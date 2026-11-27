<?php

namespace App\Support\Integrations\Servers;

use App\Actions\Machines\CreateJoinCommand;
use App\Exceptions\MachineLimitReached;
use App\Jobs\AdvanceIntegrationOperation;
use App\Models\Integration;
use App\Models\IntegrationOperation;
use App\Models\IntegrationServer;
use App\Models\Machine;
use App\Models\User;
use App\Support\Integrations\IntegrationFailed;
use RuntimeException;

/**
 * Making and resizing servers: changes that take several provider calls and
 * some waiting. Each runs as an IntegrationOperation, moved on a step at a
 * time by AdvanceIntegrationOperation, so nothing blocks a page.
 */
final class ServerOperations
{
    public const string CREATE = 'create';

    public const string RESIZE = 'resize';

    private const int WAIT = 5;

    private const int INSTALL_WAIT = 20;

    private const int SLOW_WAIT = 10;

    private const int MAX_CHECKS = 600;

    private const int LOST_MINUTES = 45;

    private const int INSTALL_MINUTES = 30;

    private const int SHUTDOWN_GRACE = 60;

    private const int SHUTDOWN_LIMIT = 300;

    private const int ACTION_MINUTES = 30;

    /** @var array<string, list<array{0: string, 1: list<string>}>> */
    private const array STEPS = [
        self::CREATE => [['create', ['create']], ['boot', ['boot']], ['install', ['install']]],
        self::RESIZE => [['stop', ['stop', 'stopping']], ['change', ['change', 'changing']], ['start', ['start', 'starting']]],
    ];

    /** A resize the provider does with the server running: one step from start to end. */
    private const array IN_PLACE = [['change', ['stop', 'change', 'changing', 'start', 'starting']]];

    public function __construct(private readonly CreateJoinCommand $join) {}

    /**
     * Makes the server now, so a refusal shows in the form, and follows its
     * start and install in the background.
     *
     * @throws IntegrationFailed
     */
    public function create(Integration $integration, User $user, NewServer $spec): IntegrationOperation
    {
        $manager = CloudServers::manager($integration) ?? throw new IntegrationFailed(self::t('This account can’t make servers.'));

        try {
            $joined = $this->join->create($integration->workspace, $user, minutes: 60);
        } catch (RuntimeException $e) {
            throw new IntegrationFailed($e->getMessage(), IntegrationFailed::UNAVAILABLE);
        }
        if ($joined === null) {
            throw new IntegrationFailed(MachineLimitReached::selfHostedMessage($integration->workspace) ?? self::t('Your plan’s machines are all in use. Make room or upgrade, then make the server.'));
        }

        try {
            $started = $manager->create($integration, $spec, CloudInit::userData(CloudInit::hostname($spec->name), $joined['command']));
        } catch (IntegrationFailed $e) {
            $joined['enrolment']->update(['expires_at' => now()]);
            throw $e;
        }

        $integration->servers()->updateOrCreate(['server_id' => $started->server->id], [
            'name' => $started->server->name,
            'details' => $started->server->toArray(),
            'daemon_enrolment_id' => $joined['enrolment']->id,
            'synced_at' => now(),
        ]);
        $integration->forceFill(['meta' => [...($integration->meta ?? []), 'servers' => (int) ($integration->meta['servers'] ?? 0) + 1]])->save();

        $operation = $integration->operations()->create([
            'kind' => self::CREATE,
            'user_id' => $user->id,
            'server_id' => $started->server->id,
            'state' => 'running',
            'step' => 'boot',
            'action_id' => $started->actionId,
            'step_started_at' => now(),
            'input' => ['name' => $spec->name, 'location' => $spec->location, 'type' => $spec->type, 'image' => $spec->image],
        ]);
        AdvanceIntegrationOperation::dispatch($operation->id)->delay(now()->addSeconds(self::WAIT));

        return $operation;
    }

    public function resize(IntegrationServer $row, Machine $machine, User $user, ServerType $type, bool $growDisk): IntegrationOperation
    {
        $powerOff = CloudServers::manager($row->integration)?->rules()->resizeNeedsPowerOff ?? true;
        $operation = $row->integration->operations()->create([
            'kind' => self::RESIZE,
            'machine_id' => $machine->id,
            'user_id' => $user->id,
            'server_id' => $row->server_id,
            'state' => 'queued',
            'step' => 'stop',
            'step_started_at' => now(),
            'input' => ['type' => $type->name, 'type_label' => $type->label, 'from' => $row->details['typeLabel'] ?? $row->details['type'] ?? null, 'grow_disk' => $growDisk, 'power_off' => $powerOff],
        ]);
        AdvanceIntegrationOperation::dispatch($operation->id);

        return $operation;
    }

    public static function busy(Machine $machine): bool
    {
        self::expireLost();

        return IntegrationOperation::query()->where('machine_id', $machine->id)->whereIn('state', IntegrationOperation::ACTIVE)->exists();
    }

    /** A change nothing moved on for a long while lost its job: it stops counting as in progress. */
    public static function expireLost(): void
    {
        IntegrationOperation::query()
            ->whereIn('state', IntegrationOperation::ACTIVE)
            ->where('updated_at', '<', now()->subMinutes(self::LOST_MINUTES))
            ->update([
                'state' => 'failed',
                'error' => self::t('ServerOS lost track of this change. Check the server in the provider’s console.'),
                'finished_at' => now(),
                'updated_at' => now(),
            ]);
    }

    /** Seconds until the next step, or null when nothing more is to do. */
    public function advance(IntegrationOperation $operation): ?int
    {
        if (! $operation->active()) {
            return null;
        }
        $integration = $operation->integration;
        $manager = CloudServers::manager($integration);
        if ($manager === null || $operation->server_id === null) {
            $this->fail($operation, self::t('The account this belonged to was disconnected.'));

            return null;
        }

        $operation->checks++;
        $operation->state = 'running';
        if ($operation->checks > self::MAX_CHECKS) {
            $this->failWith($operation, $integration, $manager, self::t('It took too long. Check the server in the provider’s console.'));

            return null;
        }

        try {
            $wait = $operation->kind === self::RESIZE
                ? $this->advanceResize($operation, $integration, $manager)
                : $this->advanceCreate($operation, $integration, $manager);
        } catch (IntegrationFailed $e) {
            if (in_array($e->reason, [IntegrationFailed::UNAVAILABLE, IntegrationFailed::RATE_LIMITED], true) && $operation->checks < self::MAX_CHECKS) {
                $operation->save();

                return 30;
            }
            $this->failWith($operation, $integration, $manager, $e->getMessage());

            return null;
        }

        if ($operation->active()) {
            $operation->save();
        }

        return $operation->active() ? $wait : null;
    }

    public function giveUp(IntegrationOperation $operation): void
    {
        $manager = CloudServers::manager($operation->integration);
        $message = self::t('Something went wrong on ServerOS’s side. Check the server in the provider’s console.');
        $manager === null ? $this->fail($operation, $message) : $this->failWith($operation, $operation->integration, $manager, $message);
    }

    private function advanceCreate(IntegrationOperation $op, Integration $integration, ManagesServers $manager): ?int
    {
        $id = (string) $op->server_id;

        if ($op->step === 'boot') {
            if ($op->action_id !== null) {
                $state = $manager->action($integration, $op->action_id);
                if ($state->state === ActionState::RUNNING) {
                    $this->stillWithin($op, self::ACTION_MINUTES);

                    return self::WAIT;
                }
                if ($state->state === ActionState::FAILED) {
                    throw new IntegrationFailed(self::t('The provider couldn’t start the server: :reason', ['reason' => self::sentence((string) $state->error)]));
                }
                $server = $manager->server($integration, $id);
            } else {
                $server = $manager->server($integration, $id) ?? throw new IntegrationFailed(self::t('The server was deleted before it joined.'));
                if ($server->status !== 'running') {
                    $this->stillWithin($op, self::ACTION_MINUTES);

                    return self::WAIT;
                }
            }
            if ($server !== null) {
                $integration->servers()->where('server_id', $id)->update(['details' => json_encode($server->toArray()), 'name' => $server->name, 'synced_at' => now()]);
            }
            $this->step($op, 'install');

            return self::INSTALL_WAIT;
        }

        $row = $integration->servers()->where('server_id', $id)->first();
        if ($row === null) {
            throw new IntegrationFailed(self::t('The server was deleted before it joined.'));
        }
        $machineId = $row->machine_id ?? $row->enrolment?->machine_id;
        if ($machineId !== null) {
            if ($row->machine_id === null && ! IntegrationServer::query()->where('machine_id', $machineId)->exists()) {
                $row->forceFill(['machine_id' => $machineId])->save();
            }
            self::finish($op, ['machine_id' => $machineId]);

            return null;
        }
        if ($op->step_started_at?->lt(now()->subMinutes(self::INSTALL_MINUTES))) {
            throw new IntegrationFailed(self::t('The server is up, but ServerOS didn’t hear from it within :minutes minutes. Open its console at the provider and look at /var/log/cloud-init-output.log.', ['minutes' => self::INSTALL_MINUTES]));
        }

        return self::INSTALL_WAIT;
    }

    private function advanceResize(IntegrationOperation $op, Integration $integration, ManagesServers $manager): ?int
    {
        $id = (string) $op->server_id;

        for ($i = 0; $i < 8; $i++) {
            switch ($op->step) {
                case 'stop':
                    $server = $manager->server($integration, $id) ?? throw new IntegrationFailed(self::t('The server isn’t in the account any more.'), IntegrationFailed::NOT_FOUND);
                    $running = $server->status !== 'off';
                    $stop = $running && $manager->rules()->resizeNeedsPowerOff;
                    $op->result = [...($op->result ?? []), 'was_running' => $running, 'stopped' => $stop];
                    if (! $stop) {
                        $this->step($op, 'change');
                        break;
                    }
                    $manager->shutdown($integration, $id);
                    $this->step($op, 'stopping');

                    return self::WAIT;

                case 'stopping':
                    if ($manager->server($integration, $id)?->status === 'off') {
                        $this->step($op, 'change');
                        break;
                    }
                    $since = (int) $op->step_started_at?->diffInSeconds(now());
                    if ($since > self::SHUTDOWN_LIMIT) {
                        throw new IntegrationFailed(self::t('It didn’t turn off, so its type wasn’t changed.'));
                    }
                    if ($since >= self::SHUTDOWN_GRACE && ! ($op->result['forced'] ?? false)) {
                        $manager->powerOff($integration, $id);
                        $op->result = [...($op->result ?? []), 'forced' => true];
                    }

                    return self::WAIT;

                case 'change':
                    $actionId = $manager->resize($integration, $id, (string) ($op->input['type'] ?? ''), (bool) ($op->input['grow_disk'] ?? false));
                    $this->step($op, 'changing');
                    $op->action_id = $actionId;

                    return self::WAIT;

                case 'changing':
                    if ($op->action_id === null) {
                        $server = $manager->server($integration, $id) ?? throw new IntegrationFailed(self::t('The server isn’t in the account any more.'), IntegrationFailed::NOT_FOUND);
                        $settled = ($op->result['was_running'] ?? false) ? ['running'] : ['running', 'off'];
                        if ($server->type !== (string) ($op->input['type'] ?? '') || ! in_array($server->status, $settled, true)) {
                            $this->stillWithin($op, self::ACTION_MINUTES);

                            return self::SLOW_WAIT;
                        }
                        $this->step($op, 'start');
                        break;
                    }
                    $state = $manager->action($integration, (string) $op->action_id);
                    if ($state->state === ActionState::RUNNING) {
                        $this->stillWithin($op, self::ACTION_MINUTES);

                        return self::SLOW_WAIT;
                    }
                    if ($state->state === ActionState::FAILED) {
                        throw new IntegrationFailed(self::t('The type wasn’t changed: :reason', ['reason' => self::sentence((string) $state->error)]));
                    }
                    $this->step($op, 'start');
                    break;

                case 'start':
                    if (! ($op->result['stopped'] ?? false)) {
                        $this->finishResize($op, $integration, $manager);

                        return null;
                    }
                    $actionId = $manager->powerOn($integration, $id);
                    $this->step($op, 'starting');
                    $op->action_id = $actionId;

                    return self::WAIT;

                case 'starting':
                    if ($op->action_id === null) {
                        if ($manager->server($integration, $id)?->status !== 'running') {
                            $this->stillWithin($op, self::ACTION_MINUTES);

                            return self::WAIT;
                        }
                        $this->finishResize($op, $integration, $manager);

                        return null;
                    }
                    $state = $manager->action($integration, (string) $op->action_id);
                    if ($state->state === ActionState::RUNNING) {
                        $this->stillWithin($op, self::ACTION_MINUTES);

                        return self::WAIT;
                    }
                    if ($state->state === ActionState::FAILED) {
                        throw new IntegrationFailed(self::t('It was resized but didn’t start again: :reason Start it from the provider’s console.', ['reason' => self::sentence((string) $state->error)]));
                    }
                    $this->finishResize($op, $integration, $manager);

                    return null;

                default:
                    throw new IntegrationFailed(self::t('Something went wrong on ServerOS’s side. Check the server in the provider’s console.'));
            }
        }

        return self::WAIT;
    }

    private function finishResize(IntegrationOperation $op, Integration $integration, ManagesServers $manager): void
    {
        try {
            $server = $manager->server($integration, (string) $op->server_id);
            if ($server !== null) {
                $integration->servers()->where('server_id', $op->server_id)->update(['details' => json_encode($server->toArray()), 'synced_at' => now()]);
            }
        } catch (IntegrationFailed) {
        }
        self::finish($op);
    }

    // A resize that stopped the server starts it again before giving up, so a failure never leaves it off.
    private function failWith(IntegrationOperation $op, Integration $integration, ManagesServers $manager, string $message): void
    {
        if ($op->kind === self::RESIZE && ($op->result['stopped'] ?? false) && in_array($op->step, ['stopping', 'change', 'changing'], true)) {
            try {
                $manager->powerOn($integration, (string) $op->server_id);
                $message .= ' '.self::t('ServerOS turned it back on.');
            } catch (IntegrationFailed) {
                $message .= ' '.self::t('ServerOS couldn’t start it again, so start it from the provider’s console.');
            }
        }
        if ($op->kind === self::CREATE) {
            $integration->servers()->where('server_id', $op->server_id)->first()?->enrolment?->update(['expires_at' => now()]);
        }
        $this->fail($op, $message);
    }

    private function fail(IntegrationOperation $op, string $message): void
    {
        $op->forceFill(['state' => 'failed', 'error' => $message, 'finished_at' => now()])->save();
    }

    /** @param  array<string, mixed>  $result */
    public static function finish(IntegrationOperation $op, array $result = []): void
    {
        $op->forceFill([
            'state' => 'done',
            'step' => 'done',
            'action_id' => null,
            'result' => [...($op->result ?? []), ...$result],
            'machine_id' => $result['machine_id'] ?? $op->machine_id,
            'finished_at' => now(),
        ])->save();
    }

    private function step(IntegrationOperation $op, string $step): void
    {
        $op->forceFill(['step' => $step, 'step_started_at' => now(), 'action_id' => null]);
    }

    private function stillWithin(IntegrationOperation $op, int $minutes): void
    {
        if ($op->step_started_at?->lt(now()->subMinutes($minutes))) {
            throw new IntegrationFailed(self::t('The provider is taking too long. Check the server in its console.'));
        }
    }

    /** @return array<string, mixed> */
    public static function props(IntegrationOperation $op): array
    {
        $labels = [
            'create' => self::t('Make the server'),
            'boot' => self::t('Start it'),
            'install' => self::t('Install ServerOS and join'),
            'stop' => self::t('Shut it down'),
            'change' => self::t('Change the type'),
            'start' => self::t('Start it again'),
        ];
        $steps = [];
        $current = null;
        $plan = $op->kind === self::RESIZE && ($op->input['power_off'] ?? true) === false ? self::IN_PLACE : (self::STEPS[$op->kind] ?? []);
        foreach ($plan as $index => [$key, $internal]) {
            $steps[] = $key;
            if (in_array($op->step, $internal, true)) {
                $current = $index;
            }
        }
        $current = $op->state === 'done' ? count($steps) : ($current ?? 0);

        return [
            'id' => $op->id,
            'integrationId' => $op->integration_id,
            'kind' => $op->kind,
            'state' => $op->state,
            'name' => (string) ($op->input['name'] ?? ''),
            'type' => $op->input['type_label'] ?? $op->input['type'] ?? null,
            'from' => $op->input['from'] ?? null,
            'serverId' => $op->server_id,
            'machineId' => $op->machine_id,
            'machineName' => $op->machine?->name,
            'error' => $op->error,
            'steps' => array_map(fn (int $index, string $key) => [
                'key' => $key,
                'label' => $labels[$key],
                'state' => match (true) {
                    $index < $current => 'done',
                    $index === $current && $op->state === 'failed' => 'failed',
                    $index === $current => 'current',
                    default => 'todo',
                },
            ], array_keys($steps), $steps),
            'createdAt' => $op->created_at?->toIso8601String(),
            'finishedAt' => $op->finished_at?->toIso8601String(),
        ];
    }

    private static function sentence(string $text): string
    {
        $text = trim($text);

        return $text === '' ? '' : (preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.');
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
