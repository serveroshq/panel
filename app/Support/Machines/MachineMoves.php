<?php

namespace App\Support\Machines;

use App\Http\Controllers\MachineFirewallController;
use App\Mcp\Tools\Deploy\Deploys;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineMove;
use App\Models\MachineMoveApp;
use App\Models\MachineMoveCopy;
use App\Models\User;
use App\Support\Backups\BackupReceivers;
use App\Support\Backups\ReceiverKey;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppReleases;
use App\Support\Firewall;
use App\Support\Projects\Placement;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Moves a machine's apps onto another machine, as a record that survives
 * restarts and moves on as the machines report jobs finished (and once a
 * minute from moves:tick):
 *
 *   copying    each app is deployed on the destination from its last deploy,
 *              without its domains, and its data is sent over (a snapshot
 *              the source sends to the destination's backup receiver, which
 *              the destination restores and checks against its checksums)
 *   ready      every app runs on the destination with its data
 *   switching  the source's apps are stopped (databases keep running to be
 *              dumped), the data is copied again, and the apps are deployed
 *              on the destination with their domains
 *   switched   the domains are served by the destination once DNS points there
 *   completed, rolled_back, cancelled or failed
 *
 * The source is only ever stopped, never removed, and a rollback starts it
 * again. The key the source sends data with is allowed in on the destination
 * only while the move needs it.
 */
final class MachineMoves
{
    private const int DATA_TIMEOUT = 6 * 3600;

    public function __construct(
        private readonly DaemonJobQueue $queue,
        private readonly MachineMoveCheck $check,
        private readonly ReceiverKey $keys,
    ) {}

    /**
     * The token the machine's Cloudflare Tunnel was started with, from the
     * job that started it (kept encrypted with the job), or null when it
     * wasn't started from ServerOS.
     */
    public static function tunnelToken(Machine $machine): ?string
    {
        $job = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', CloudflareTunnel::KIND)
            ->where('state', 'succeeded')->latest()->limit(20)->get()
            ->first(fn (DaemonJob $j) => in_array($j->command['job']['action'] ?? null, ['up', 'down'], true));
        $token = $job?->command['job']['action'] === 'up' ? ($job->command['job']['token'] ?? null) : null;

        return is_string($token) && CloudflareTunnel::tunnelId($token) !== null ? $token : null;
    }

    public static function active(Machine $from): ?MachineMove
    {
        return MachineMove::query()->where('from_machine_id', $from->id)
            ->whereIn('state', MachineMove::ACTIVE)->latest('id')->first();
    }

    /** @throws RuntimeException */
    public function start(Machine $from, Machine $to, User $user): MachineMove
    {
        if (self::active($from) !== null) {
            throw new RuntimeException(self::say('A move from :from is already under way.', ['from' => $from->name]));
        }
        $check = $this->check->run($from, $to);
        if (! $check['ready']) {
            throw new RuntimeException($check['blocking'][0] ?? self::say('This move can’t start yet.'));
        }

        $move = DB::transaction(function () use ($from, $to, $user) {
            $move = MachineMove::query()->create([
                'workspace_id' => $from->workspace_id,
                'from_machine_id' => $from->id,
                'to_machine_id' => $to->id,
                'user_id' => $user->id,
                'state' => 'copying',
            ]);
            if (Firewall::report($to) !== null) {
                $move->forceFill(['firewall' => array_map(fn (string $rule) => ['rule' => $rule, 'state' => 'waiting', 'job' => null, 'error' => null], MachineFile::openedPorts($from))])->save();
            }
            foreach (MachineFile::apps($from) as $app) {
                $row = $move->apps()->create(['name' => $app['name'], 'source' => $app['source'], 'state' => 'deploying']);
                foreach (self::dataOf($from, $app['name']) as $service) {
                    $row->copies()->create($service + ['state' => 'waiting']);
                }
            }

            return $move;
        });

        if ($move->copies()->exists()) {
            $this->setUpReceiver($move, $user);
        }
        foreach ($move->apps as $app) {
            $this->deploy($move, $app, $user, false);
        }
        Activity::record($move->workspace_id, 'machine.move_start', $from->name, $from, $user, ['to' => $to->name, 'apps' => $move->apps->count()]);
        $this->advance($move);

        return $move->refresh();
    }

    /** @throws RuntimeException */
    public function switchOver(MachineMove $move, User $user): void
    {
        DB::transaction(function () use ($move, $user) {
            $locked = MachineMove::query()->lockForUpdate()->findOrFail($move->id);
            if ($locked->state !== 'ready') {
                throw new RuntimeException(self::say('Only a move that’s ready can switch over.'));
            }
            foreach ([$locked->from, $locked->to] as $machine) {
                if (($reason = $this->queue->unavailable($machine, 'deploy')) !== null) {
                    throw new RuntimeException($reason);
                }
            }

            // Writers stop first, so the final copy has everything they wrote;
            // databases keep running, since they're dumped while they run.
            $stopped = [];
            foreach ($locked->apps as $app) {
                foreach (self::containers($locked->from, $app->name) as $service) {
                    if (in_array($service['details']['backup'] ?? null, MachineMoveCopy::DUMPS, true) || ($service['status'] ?? null) !== 'running') {
                        continue;
                    }
                    $job = $this->queue->queue($locked->from, $user, 'service_action', ['type' => 'service_action', 'service' => $service['key'], 'action' => 'stop'], $service['key']);
                    $stopped[] = ['key' => (string) $service['key'], 'job' => $job->id];
                }
            }

            $locked->copies()->update(['state' => 'waiting', 'round' => 2, 'backup_job_id' => null, 'restore_job_id' => null, 'snapshot' => null, 'received' => null, 'error' => null]);
            $tunnel = CloudflareTunnel::active($locked->from) && CloudflareTunnel::supported($locked->to) && self::tunnelToken($locked->from) !== null
                ? ['state' => 'pending', 'job' => null] : null;
            $locked->forceFill(['state' => 'switching', 'round' => 2, 'stopped' => $stopped, 'tunnel' => $tunnel, 'error' => null])->save();
            Activity::record($locked->workspace_id, 'machine.move_switch', $locked->from->name, $locked->from, $user, ['to' => $locked->to->name]);
        });

        $this->advance($move);
    }

    /** @throws RuntimeException */
    public function rollBack(MachineMove $move, User $user): void
    {
        if (! in_array($move->state, ['ready', 'switching', 'switched', 'failed'], true)) {
            throw new RuntimeException(self::say('This move can’t be rolled back.'));
        }

        foreach ($move->stopped ?? [] as $stopped) {
            try {
                $this->queue->queue($move->from, $user, 'service_action', ['type' => 'service_action', 'service' => $stopped['key'], 'action' => 'start'], $stopped['key']);
            } catch (RuntimeException $e) {
                report($e);
            }
        }
        // The tunnel goes back to the source, started there before it stops here.
        if (in_array($move->tunnel['state'] ?? null, ['starting', 'stopping_source', 'moved'], true) && ($token = self::tunnelToken($move->from) ?? self::tunnelToken($move->to)) !== null) {
            try {
                $this->queue->queue($move->from, $user, CloudflareTunnel::KIND, ['type' => 'tunnel', 'action' => 'up', 'token' => $token], null, 600);
                $this->queue->queue($move->to, $user, CloudflareTunnel::KIND, ['type' => 'tunnel', 'action' => 'down'], null, 600);
            } catch (RuntimeException $e) {
                report($e);
            }
        }
        // Apps sent their domains go back to serving none on the destination.
        foreach ($move->apps as $app) {
            if (in_array($app->state, ['deploying_domains', 'switched'], true) || ($app->state === 'failed' && $move->round === 2 && $app->deploy_job_id !== null)) {
                $this->deploy($move, $app, $user, false, rollingBack: true);
            }
        }

        $move->forceFill(['state' => 'rolled_back', 'finished_at' => now()])->save();
        $this->revoke($move, $user);
        Activity::record($move->workspace_id, 'machine.move_rollback', $move->from->name, $move->from, $user, ['to' => $move->to->name]);
    }

    /** @throws RuntimeException */
    public function finish(MachineMove $move, User $user): void
    {
        if ($move->state !== 'switched') {
            throw new RuntimeException(self::say('Only a move that has switched over can be finished.'));
        }
        $move->forceFill(['state' => 'completed', 'finished_at' => now()])->save();
        $this->revoke($move, $user);
        Activity::record($move->workspace_id, 'machine.move_finish', $move->from->name, $move->from, $user, ['to' => $move->to->name]);
    }

    /** @throws RuntimeException */
    public function cancel(MachineMove $move, User $user): void
    {
        if (! in_array($move->state, ['copying', 'ready', 'failed'], true) || $move->round !== 1) {
            throw new RuntimeException(self::say('A move that has started switching over is rolled back instead.'));
        }
        $move->forceFill(['state' => 'cancelled', 'finished_at' => now()])->save();
        $this->revoke($move, $user);
        Activity::record($move->workspace_id, 'machine.move_cancel', $move->from->name, $move->from, $user, ['to' => $move->to->name]);
    }

    /** @throws RuntimeException */
    public function retry(MachineMove $move, User $user): void
    {
        if ($move->state !== 'failed') {
            throw new RuntimeException(self::say('Only a move that failed can be tried again.'));
        }
        $move->forceFill(['state' => $move->round === 1 ? 'copying' : 'switching', 'error' => null])->save();

        if ($move->receiver_state === 'failed') {
            $this->setUpReceiver($move, $user);
        }
        foreach ($move->apps as $app) {
            foreach ($app->copies as $copy) {
                if ($copy->state === 'failed') {
                    $copy->forceFill(['state' => $copy->received !== null ? 'sent' : 'waiting', 'error' => null])->save();
                }
            }
            if ($app->state !== 'failed') {
                continue;
            }
            $deployed = $app->deploy_job_id !== null && DaemonJob::query()->whereKey($app->deploy_job_id)->value('state') === 'succeeded';
            if ($deployed) {
                $app->forceFill(['state' => $move->round === 1 ? 'deployed' : 'ready', 'error' => null])->save();
            } else {
                $this->deploy($move, $app, $user, $move->round === 2 && $app->copies->every(fn (MachineMoveCopy $c) => $c->state === 'copied'));
            }
        }
        Activity::record($move->workspace_id, 'machine.move_retry', $move->from->name, $move->from, $user, ['to' => $move->to->name]);
        $this->advance($move);
    }

    public function jobFinished(DaemonJob $job): void
    {
        if (in_array($job->state, ['queued', 'running'], true)) {
            return;
        }
        $ok = $job->state === 'succeeded';
        $error = mb_substr((string) ($job->error['message'] ?? self::say('The job didn’t finish.')), 0, 1000);
        $moves = [];

        foreach (MachineMove::query()->where('receiver_job_id', $job->id)->where('receiver_state', 'setting_up')->get() as $move) {
            $move->forceFill($ok
                ? ['receiver_state' => 'ready', 'host_key' => is_string($job->result['host_key'] ?? null) ? mb_substr($job->result['host_key'], 0, 1000) : null]
                : ['receiver_state' => 'failed', 'error' => self::say(':to couldn’t be set up to receive data: :error', ['to' => $move->to->name, 'error' => $error])])->save();
            $moves[$move->id] = $move;
        }
        foreach (MachineMoveApp::query()->where('deploy_job_id', $job->id)->whereIn('state', ['deploying', 'deploying_domains'])->get() as $app) {
            $app->forceFill($ok
                ? ['state' => $app->state === 'deploying' ? 'deployed' : 'switched', 'error' => null]
                : ['state' => 'failed', 'error' => $error])->save();
            $moves[$app->machine_move_id] = $app->move;
        }
        foreach (MachineMoveCopy::query()->where('backup_job_id', $job->id)->where('state', 'sending')->get() as $copy) {
            $received = $ok ? self::receivedPath($job) : null;
            $copy->forceFill($ok && $received !== null
                ? ['state' => 'sent', 'snapshot' => mb_substr((string) $job->result['snapshot'], 0, 80), 'received' => $received, 'bytes' => is_numeric($job->result['bytes'] ?? null) ? (int) $job->result['bytes'] : null]
                : ['state' => 'failed', 'error' => $ok ? self::say('The machine didn’t say where it sent the data.') : $error])->save();
            $moves[$copy->app->machine_move_id] = $copy->app->move;
        }
        foreach (MachineMoveCopy::query()->where('restore_job_id', $job->id)->where('state', 'restoring')->get() as $copy) {
            $copy->forceFill($ok ? ['state' => 'copied', 'error' => null] : ['state' => 'failed', 'error' => $error])->save();
            $moves[$copy->app->machine_move_id] = $copy->app->move;
        }
        if (in_array($job->kind, ['service_action', 'firewall_rule', CloudflareTunnel::KIND], true)) {
            // The source's apps stopping, ports opening, or the tunnel moving.
            $waiting = MachineMove::query()->whereIn('state', ['copying', 'switching'])
                ->where(fn ($q) => $q->where('from_machine_id', $job->machine_id)->orWhere('to_machine_id', $job->machine_id))->get();
            foreach ($waiting as $move) {
                $moves[$move->id] = $move;
            }
        }

        foreach ($moves as $move) {
            $this->advance($move);
        }
    }

    public function tick(CarbonImmutable $now): void
    {
        foreach (MachineMove::query()->whereIn('state', ['copying', 'switching'])->get() as $move) {
            foreach ($this->inFlight($move) as [$id, $timeout]) {
                $job = DaemonJob::query()->find($id);
                if ($job === null) {
                    $this->jobGone($id, self::say('The job is gone. Try the move again.'));
                } elseif (! in_array($job->state, ['queued', 'running'], true)) {
                    $this->jobFinished($job);
                } elseif ($job->created_at?->lt($now->subSeconds($timeout + 15 * 60))) {
                    $this->jobGone($id, self::say(':machine didn’t report the job finishing.', ['machine' => $job->machine->name]));
                }
            }
            $this->advance($move);
        }

        // Keys a finished move left allowed in (the destination was offline, say).
        foreach (MachineMove::query()->whereNotIn('state', MachineMove::ACTIVE)->whereIn('receiver_state', ['setting_up', 'ready'])->get() as $move) {
            $owner = $move->user ?? $move->workspace->owner;
            if ($owner !== null) {
                $this->revoke($move, $owner);
            }
        }
    }

    /** @return array<string, mixed> */
    public static function present(MachineMove $move): array
    {
        $move->loadMissing(['from', 'to', 'apps.copies']);
        $domains = [];
        foreach ($move->apps as $app) {
            $spec = Deploys::lastDeploy($move->from, $app->name)?->command['job'] ?? [];
            $domains = [...$domains, ...array_map('strval', (array) ($spec['domains'] ?? []))];
        }

        return [
            'id' => $move->id,
            'state' => $move->state,
            'round' => $move->round,
            'error' => $move->error,
            'from' => ['id' => $move->from->id, 'name' => $move->from->name],
            'to' => ['id' => $move->to->id, 'name' => $move->to->name, 'address' => $move->to->ip_address],
            'domains' => array_values(array_unique($domains)),
            'stopped' => count($move->stopped ?? []),
            'firewall' => array_map(fn (array $e) => ['rule' => $e['rule'], 'state' => $e['state'], 'error' => $e['error']], $move->firewall ?? []),
            'tunnel' => $move->tunnel['state'] ?? null,
            'startedAt' => $move->created_at?->toIso8601String(),
            'switchedAt' => $move->switched_at?->toIso8601String(),
            'finishedAt' => $move->finished_at?->toIso8601String(),
            'apps' => $move->apps->map(fn (MachineMoveApp $app) => [
                'name' => $app->name,
                'state' => $app->state,
                'error' => $app->error,
                'copies' => $app->copies->map(fn (MachineMoveCopy $copy) => [
                    'container' => $copy->container,
                    'method' => $copy->method,
                    'state' => $copy->state,
                    'bytes' => $copy->bytes,
                    'error' => $copy->error,
                ])->values()->all(),
            ])->values()->all(),
            'can' => [
                'switch' => $move->state === 'ready',
                'rollBack' => in_array($move->state, ['ready', 'switching', 'switched', 'failed'], true) && $move->round === 2,
                'finish' => $move->state === 'switched',
                'cancel' => in_array($move->state, ['copying', 'ready', 'failed'], true) && $move->round === 1,
                'retry' => $move->state === 'failed',
            ],
        ];
    }

    private function advance(MachineMove $move): void
    {
        DB::transaction(function () use ($move) {
            $move = MachineMove::query()->lockForUpdate()->find($move->id);
            if ($move === null || ! in_array($move->state, ['copying', 'switching'], true)) {
                return;
            }
            $user = $move->user ?? $move->workspace->owner;
            if ($user === null) {
                return;
            }
            if ($move->receiver_state === 'failed') {
                $move->forceFill(['state' => 'failed'])->save();

                return;
            }
            if ($move->round === 2 && ($stops = $this->stops($move)) !== 'done') {
                if ($stops === 'failed') {
                    $move->forceFill(['state' => 'failed', 'error' => self::say('Some of :from’s apps couldn’t be stopped for the final copy. Roll back to start them again.', ['from' => $move->from->name])])->save();
                }

                return;
            }

            if ($move->round === 1) {
                $this->advanceFirewall($move, $user);
            }
            foreach ($move->apps as $app) {
                $this->advanceApp($move, $app, $user);
            }
            $this->settle($move, $user);
        });
    }

    /** Opens the source's ports on the destination one at a time; a port that won't open is reported, not fatal. */
    private function advanceFirewall(MachineMove $move, User $user): void
    {
        $entries = $move->firewall ?? [];
        foreach ($entries as $i => $entry) {
            if ($entry['state'] === 'opening') {
                $job = $entry['job'] === null ? null : DaemonJob::query()->find($entry['job']);
                if ($job !== null && in_array($job->state, ['queued', 'running'], true)) {
                    return;
                }
                $entries[$i] = $job?->state === 'succeeded'
                    ? ['state' => 'opened', 'error' => null] + $entry
                    : ['state' => 'failed', 'error' => mb_substr((string) ($job?->error['message'] ?? self::say('The port couldn’t be opened.')), 0, 500)] + $entry;
            }
        }
        foreach ($entries as $i => $entry) {
            if ($entry['state'] === 'waiting') {
                try {
                    [$port, $proto] = explode('/', $entry['rule']);
                    $job = $this->queue->queue($move->to, $user, 'firewall_rule', MachineFirewallController::job('open', (int) $port, $proto), null, 120);
                    $entries[$i] = ['state' => 'opening', 'job' => $job->id] + $entry;
                    Activity::record($move->workspace_id, 'machine.firewall', $move->to->name, $move->to, $user, ['action' => 'open', 'port' => $entry['rule']]);
                } catch (RuntimeException $e) {
                    $entries[$i] = ['state' => 'failed', 'error' => $e->getMessage()] + $entry;
                }
                break;
            }
        }
        $move->forceFill(['firewall' => $entries])->save();
    }

    /**
     * Moves the source's Cloudflare Tunnel once the apps have their domains:
     * started on the destination with the same token first, so traffic always
     * has somewhere to go, then stopped on the source. True once it's moved.
     */
    private function advanceTunnel(MachineMove $move, User $user): bool
    {
        $tunnel = $move->tunnel;
        if ($tunnel === null || $tunnel['state'] === 'moved') {
            return true;
        }
        $job = $tunnel['job'] === null ? null : DaemonJob::query()->find($tunnel['job']);
        if ($job !== null && in_array($job->state, ['queued', 'running'], true)) {
            return false;
        }
        if ($tunnel['state'] !== 'pending' && $job?->state !== 'succeeded') {
            $move->forceFill(['state' => 'failed', 'error' => self::say('The Cloudflare Tunnel couldn’t be moved: :error', ['error' => (string) ($job?->error['message'] ?? self::say('the job is gone'))])])->save();

            return false;
        }

        try {
            [$machine, $step, $next] = match ($tunnel['state']) {
                'pending' => [$move->to, ['type' => 'tunnel', 'action' => 'up', 'token' => (string) self::tunnelToken($move->from)], 'starting'],
                'starting' => [$move->from, ['type' => 'tunnel', 'action' => 'down'], 'stopping_source'],
                default => [null, null, 'moved'],
            };
            $id = $machine === null || $step === null ? null : $this->queue->queue($machine, $user, CloudflareTunnel::KIND, $step, null, 600)->id;
            $move->forceFill(['tunnel' => ['state' => $next, 'job' => $id]])->save();
        } catch (RuntimeException $e) {
            $move->forceFill(['state' => 'failed', 'error' => $e->getMessage()])->save();

            return false;
        }

        return $next === 'moved';
    }

    private function advanceApp(MachineMove $move, MachineMoveApp $app, User $user): void
    {
        $copying = $move->round === 1 ? $app->state === 'deployed' : $app->state === 'ready';
        if (! $copying) {
            return;
        }
        foreach ($app->copies as $copy) {
            $this->advanceCopy($move, $copy, $user);
        }
        if ($app->copies->every(fn (MachineMoveCopy $c) => $c->state === 'copied')) {
            if ($move->round === 1) {
                $app->forceFill(['state' => 'ready'])->save();
            } else {
                $this->deploy($move, $app, $user, true);
            }
        }
    }

    private function advanceCopy(MachineMove $move, MachineMoveCopy $copy, User $user): void
    {
        if ($copy->state === 'waiting' && $move->receiver_state === 'ready') {
            try {
                $job = $this->queue->queue($move->from, $user, 'backup_to', [
                    'type' => 'backup_to',
                    'service' => self::keyOf($move->from, $copy->container) ?? $copy->service,
                    'reason' => 'move',
                    'prefix' => 'moves/'.$move->id,
                    'destination' => self::destination($move),
                ], $copy->service, self::DATA_TIMEOUT);
                $copy->forceFill(['state' => 'sending', 'backup_job_id' => $job->id, 'error' => null])->save();
            } catch (RuntimeException $e) {
                $copy->forceFill(['state' => 'failed', 'error' => $e->getMessage()])->save();
            }
        }

        if ($copy->state === 'sent') {
            $key = self::keyOf($move->to, $copy->container);
            if ($key === null) {
                // The destination hasn't reported its copy of the container yet.
                $this->queue->rescan($move->to, $user);

                return;
            }
            try {
                $job = $this->queue->queue($move->to, $user, 'restore', [
                    'type' => 'restore',
                    'service' => $key,
                    'snapshot' => $copy->snapshot,
                    'received' => $copy->received,
                ], $key, self::DATA_TIMEOUT);
                $copy->forceFill(['state' => 'restoring', 'restore_job_id' => $job->id])->save();
            } catch (RuntimeException $e) {
                $copy->forceFill(['state' => 'failed', 'error' => $e->getMessage()])->save();
            }
        }
    }

    private function settle(MachineMove $move, User $user): void
    {
        $move->load('apps.copies');
        $failed = $move->apps->first(fn (MachineMoveApp $a) => $a->state === 'failed')
            ?? $move->apps->flatMap->copies->first(fn (MachineMoveCopy $c) => $c->state === 'failed');
        if ($failed !== null) {
            $name = $failed instanceof MachineMoveApp ? $failed->name : $failed->container;
            $move->forceFill(['state' => 'failed', 'error' => self::say(':name: :error', ['name' => $name, 'error' => (string) $failed->error])])->save();

            return;
        }

        $opening = collect($move->firewall ?? [])->contains(fn (array $e) => in_array($e['state'], ['waiting', 'opening'], true));
        if ($move->round === 1 && ! $opening && $move->apps->every(fn (MachineMoveApp $a) => $a->state === 'ready')) {
            // Ready once the destination says every app's containers are running.
            foreach ($move->apps as $app) {
                $running = self::containers($move->to, $app->name);
                if ($running === [] || collect($running)->contains(fn (array $s) => ($s['status'] ?? null) !== 'running')) {
                    $this->queue->rescan($move->to, $user);

                    return;
                }
            }
            $move->forceFill(['state' => 'ready'])->save();
        }

        if ($move->round === 2 && $move->apps->every(fn (MachineMoveApp $a) => $a->state === 'switched')) {
            if (! $this->advanceTunnel($move, $user)) {
                return;
            }
            $move->forceFill(['state' => 'switched', 'switched_at' => now()])->save();
            $this->revoke($move, $user);
        }
    }

    private function deploy(MachineMove $move, MachineMoveApp $app, User $user, bool $withDomains, bool $rollingBack = false): void
    {
        $spec = Deploys::lastDeploy($move->from, $app->name)?->command['job'] ?? null;
        if (! is_array($spec)) {
            $app->forceFill(['state' => 'failed', 'error' => self::say('ServerOS no longer has a deploy of it on :from.', ['from' => $move->from->name])])->save();

            return;
        }
        if (! $withDomains) {
            $spec['domains'] = [];
        }
        unset($spec['snapshot_before'], $spec['snapshot_also']);

        try {
            $job = $this->queue->deploy($move->to, $user, $spec);
            if (! $rollingBack) {
                $app->forceFill(['state' => $withDomains ? 'deploying_domains' : 'deploying', 'deploy_job_id' => $job->id, 'error' => null])->save();
            }
        } catch (RuntimeException $e) {
            if (! $rollingBack) {
                $app->forceFill(['state' => 'failed', 'error' => $e->getMessage()])->save();
            }
        }
    }

    private function setUpReceiver(MachineMove $move, User $user): void
    {
        $pair = $this->keys->generate();
        $move->forceFill(['private_key' => $pair['private'], 'public_key' => $pair['public'], 'host_key' => null, 'receiver_state' => 'setting_up'])->save();

        try {
            $job = $this->queue->queue($move->to, $user, 'backup_receiver', [
                'type' => 'backup_receiver',
                'public_keys' => [...self::otherKeys($move), $pair['public']],
            ], null, 120);
            $move->forceFill(['receiver_job_id' => $job->id])->save();
        } catch (RuntimeException $e) {
            $move->forceFill(['receiver_state' => 'failed', 'error' => $e->getMessage()])->save();
        }
    }

    /** Takes the move's key off the destination's receiver, keeping every other key it has. */
    private function revoke(MachineMove $move, User $user): void
    {
        if (! in_array($move->receiver_state, ['setting_up', 'ready'], true)) {
            return;
        }
        try {
            $this->queue->queue($move->to, $user, 'backup_receiver', [
                'type' => 'backup_receiver',
                'public_keys' => self::otherKeys($move),
            ], null, 120);
            $move->forceFill(['receiver_state' => 'revoked'])->save();
        } catch (RuntimeException) {
            // moves:tick tries again once the destination can take it.
        }
    }

    /** @return list<string> keys the destination's receiver keeps for its backups and other moves */
    private static function otherKeys(MachineMove $move): array
    {
        return BackupReceivers::keysFor($move->to, $move);
    }

    /** @return array<string, mixed> */
    private static function destination(MachineMove $move): array
    {
        return [
            'type' => 'sftp',
            'host' => $move->to->ip_address,
            'port' => 22,
            'user' => 'serveros-backups',
            'private_key' => $move->private_key,
            'host_key' => $move->host_key,
        ];
    }

    /** Where the source sent a snapshot, inside the destination's receiver folder. */
    private static function receivedPath(DaemonJob $job): ?string
    {
        $location = $job->result['location'] ?? null;
        $snapshot = $job->result['snapshot'] ?? null;
        if (! is_string($location) || ! is_string($snapshot) || ! str_starts_with($location, 'sftp://')) {
            return null;
        }
        $path = ltrim((string) parse_url($location, PHP_URL_PATH), '/');

        return preg_match('#^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$#', $path) === 1 && str_ends_with($path, '/'.$snapshot) && strlen($path) <= 500
            ? $path : null;
    }

    /** @return list<array<string, mixed>> the app's live containers */
    private static function containers(Machine $machine, string $app): array
    {
        return array_values(array_filter(Placement::containers($machine, $app), fn (array $s) => is_string($s['key'] ?? null) && ! AppReleases::retired($machine, $s)));
    }

    /** @return list<array{service: string, container: string, method: string}> */
    private static function dataOf(Machine $machine, string $app): array
    {
        $data = [];
        foreach (self::containers($machine, $app) as $service) {
            $method = $service['details']['backup'] ?? null;
            if (is_string($method) && in_array($method, ['pg_dumpall', 'mysqldump', 'tar'], true)) {
                $data[] = ['service' => (string) $service['key'], 'container' => ltrim((string) ($service['name'] ?? ''), '/'), 'method' => $method];
            }
        }

        return $data;
    }

    private static function keyOf(Machine $machine, string $container): ?string
    {
        foreach ($machine->inventory['services'] ?? [] as $service) {
            if (is_array($service) && is_string($service['key'] ?? null) && ltrim((string) ($service['name'] ?? ''), '/') === $container && ! AppReleases::retired($machine, $service)) {
                return $service['key'];
            }
        }

        return null;
    }

    /** 'done', 'waiting' or 'failed': the source's apps stopping for the final copy. */
    private function stops(MachineMove $move): string
    {
        $states = DaemonJob::query()->whereKey(array_column($move->stopped ?? [], 'job'))->pluck('state')->all();
        if (count($states) < count($move->stopped ?? [])) {
            return 'failed';
        }
        foreach ($states as $state) {
            if (in_array($state, ['queued', 'running'], true)) {
                return 'waiting';
            }
            if ($state !== 'succeeded') {
                return 'failed';
            }
        }

        return 'done';
    }

    /** @return list<array{0: string, 1: int}> job ids the move waits on, with their timeouts */
    private function inFlight(MachineMove $move): array
    {
        $ids = [];
        if ($move->receiver_state === 'setting_up' && $move->receiver_job_id !== null) {
            $ids[] = [$move->receiver_job_id, 120];
        }
        foreach ($move->apps as $app) {
            if (in_array($app->state, ['deploying', 'deploying_domains'], true) && $app->deploy_job_id !== null) {
                $ids[] = [$app->deploy_job_id, DaemonJobQueue::DEPLOY_TIMEOUT_SECS];
            }
            foreach ($app->copies as $copy) {
                if ($copy->state === 'sending' && $copy->backup_job_id !== null) {
                    $ids[] = [$copy->backup_job_id, self::DATA_TIMEOUT];
                }
                if ($copy->state === 'restoring' && $copy->restore_job_id !== null) {
                    $ids[] = [$copy->restore_job_id, self::DATA_TIMEOUT];
                }
            }
        }

        return $ids;
    }

    private function jobGone(string $id, string $error): void
    {
        MachineMove::query()->where('receiver_job_id', $id)->where('receiver_state', 'setting_up')->update(['receiver_state' => 'failed', 'error' => $error]);
        MachineMoveApp::query()->where('deploy_job_id', $id)->whereIn('state', ['deploying', 'deploying_domains'])->update(['state' => 'failed', 'error' => $error]);
        MachineMoveCopy::query()->where('backup_job_id', $id)->where('state', 'sending')->update(['state' => 'failed', 'error' => $error]);
        MachineMoveCopy::query()->where('restore_job_id', $id)->where('state', 'restoring')->update(['state' => 'failed', 'error' => $error]);
    }

    /** @param  array<string, string>  $replace */
    private static function say(string $text, array $replace = []): string
    {
        $line = __($text, $replace);

        return is_string($line) ? $line : $text;
    }
}
