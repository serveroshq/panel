<?php

namespace App\Support\Machines;

use App\Mcp\Tools\Deploy\Deploys;
use App\Models\DaemonRelease;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\GitRemote;
use App\Support\Firewall;
use App\Support\Projects\Placement;

/**
 * What moving a machine's apps onto another machine would take, checked
 * before anything is touched: what stops it, and what to know first.
 * Read-only; it sends nothing to either machine.
 *
 * @phpstan-import-type App from MachineFile
 */
final class MachineMoveCheck
{
    public function __construct(private readonly DaemonJobQueue $queue) {}

    /**
     * @return array{ready: bool, apps: list<array{name: string, source: string, domains: list<string>, data: list<string>}>, blocking: list<string>, warnings: list<string>}
     */
    public function run(Machine $from, Machine $to): array
    {
        $apps = MachineFile::apps($from);
        $blocking = [];
        $warnings = [];

        if ($apps === []) {
            $blocking[] = self::say('Nothing on :from was deployed by ServerOS, so there’s nothing to move.', ['from' => $from->name]);
        }
        foreach ([$from, $to] as $machine) {
            if (($reason = $this->queue->unavailable($machine, 'deploy')) !== null) {
                $blocking[] = $reason;
            }
        }

        $docker = $to->hasDocker();
        if ($docker === false) {
            $blocking[] = self::say('Docker isn’t installed on :to. Run `curl -fsSL https://get.docker.com | sh` on it as root first.', ['to' => $to->name]);
        } elseif ($docker === null) {
            $warnings[] = self::say(':to hasn’t been scanned yet, so ServerOS can’t tell if Docker is installed.', ['to' => $to->name]);
        }

        $taken = Placement::taken($to);
        $domains = [];
        $inApps = [];
        foreach ($apps as $app) {
            $blocking = [...$blocking, ...self::appProblems($app, $from, $to, $taken)];
            if (GitRemote::isSsh($app['repo'])) {
                $warnings[] = self::say(':app is fetched with a deploy key. Add :to’s deploy key to its repository before moving it.', ['app' => $app['name'], 'to' => $to->name]);
            }
            if (in_array('tar', $app['data'], true)) {
                $warnings[] = self::say(':app keeps files outside its container. They’re copied while it runs, then again once it’s stopped for the switch-over.', ['app' => $app['name']]);
            }
            $domains = [...$domains, ...$app['domains']];
            $inApps = [...$inApps, ...$app['services']];
        }

        if (array_filter($apps, fn (array $app) => $app['data'] !== []) !== []) {
            if (! DaemonJobQueue::canReceiveMoves($to)) {
                $blocking[] = self::say(':to’s daemon is too old to receive apps’ data. It updates itself, or run “sudo serverosd update” on it.', ['to' => $to->name]);
            }
            if (blank($to->ip_address)) {
                $blocking[] = self::say('ServerOS doesn’t know :to’s address, so :from can’t send it data.', ['to' => $to->name, 'from' => $from->name]);
            }
        }
        if (MachineMoves::active($from) !== null) {
            $blocking[] = self::say('A move from :from is already under way.', ['from' => $from->name]);
        }

        $warnings = [...$warnings, ...self::capacity($from, $to)];

        $tunnel = MachineMoves::tunnelToken($from) !== null && CloudflareTunnel::active($from);
        if ($tunnel && CloudflareTunnel::supported($to)) {
            $warnings[] = self::say('At the switch-over, :from’s Cloudflare Tunnel moves to :to, so the domains need no DNS change.', ['from' => $from->name, 'to' => $to->name]);
        } elseif ($tunnel) {
            $blocking[] = self::say(':to’s daemon can’t run :from’s Cloudflare Tunnel yet. It updates itself, or run “sudo serverosd update” on it.', ['to' => $to->name, 'from' => $from->name]);
        } elseif (CloudflareTunnel::active($from)) {
            $warnings[] = self::say(':from’s Cloudflare Tunnel was set up outside ServerOS, so it doesn’t move. Point its routes at :to yourself.', ['from' => $from->name, 'to' => $to->name]);
        }

        if (($ports = MachineFile::openedPorts($from)) !== []) {
            $warnings[] = Firewall::report($to) !== null
                ? self::say('Ports ServerOS opened on :from (:ports) are opened on :to too.', ['from' => $from->name, 'to' => $to->name, 'ports' => implode(', ', $ports)])
                : self::say('ServerOS can’t see :to’s firewall, so the ports it opened on :from (:ports) aren’t opened there. Open them yourself.', ['from' => $from->name, 'to' => $to->name, 'ports' => implode(', ', $ports)]);
        }

        if ($domains !== [] && ! $tunnel) {
            $warnings[] = self::say('Point :domains at :to only when you switch over. Their certificates are issued there once they do.', [
                'domains' => implode(', ', array_slice($domains, 0, 5)).(count($domains) > 5 ? ', …' : ''),
                'to' => $to->name,
            ]);
        }
        if (($count = count(MachineFile::notMoved($from, $inApps))) > 0) {
            $warnings[] = trans_choice(':count service on :from wasn’t deployed by ServerOS and won’t move. The machine file lists it and why.|:count services on :from weren’t deployed by ServerOS and won’t move. The machine file lists them and why.', $count, ['from' => $from->name]);
        }
        if (($count = count(MachineFile::scheduled($from))) > 0) {
            $warnings[] = trans_choice(':count scheduled task on :from won’t move. Set it up on :to yourself.|:count scheduled tasks on :from won’t move. Set them up on :to yourself.', $count, ['from' => $from->name, 'to' => $to->name]);
        }

        return [
            'ready' => $blocking === [],
            'apps' => array_map(fn (array $app) => [
                'name' => $app['name'],
                'source' => $app['source'],
                'domains' => $app['domains'],
                'data' => $app['data'],
            ], $apps),
            'blocking' => array_values(array_unique($blocking)),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /**
     * @param  App  $app
     * @param  array{services: list<string>, ports: list<int>}  $taken
     * @return list<string>
     */
    private static function appProblems(array $app, Machine $from, Machine $to, array $taken): array
    {
        $problems = [];
        if (in_array($app['name'], $taken['services'], true)) {
            $problems[] = self::say('Something called :app is already on :to.', ['app' => $app['name'], 'to' => $to->name]);
        }
        // Code deploys get a free host port from the daemon; a stack binds its own.
        if ($app['source'] === 'stack' && $app['port'] !== null && in_array($app['port'], $taken['ports'], true)) {
            $problems[] = self::say('Port :port, which :app uses, is already taken on :to.', ['port' => (string) $app['port'], 'app' => $app['name'], 'to' => $to->name]);
        }
        if ($app['source'] === 'upload') {
            // The file leaves out the upload's address (it carries a token), so ask the deploy.
            $repo = Deploys::lastDeploy($from, $app['name'])?->command['job']['repo'] ?? null;
            if (! (DeploySource::fromRepoUrl(is_string($repo) ? $repo : null)?->usable() ?? false)) {
                $problems[] = self::say('The code uploaded for :app has been cleared. Deploy it again on :from first.', ['app' => $app['name'], 'from' => $from->name]);
            }
        }

        return $problems;
    }

    /** @return list<string> */
    private static function capacity(Machine $from, Machine $to): array
    {
        $warnings = [];
        [$a, $b] = [ltrim((string) $from->daemon_version, 'v'), ltrim((string) $to->daemon_version, 'v')];
        if ($a !== '' && $b !== '' && DaemonRelease::compare($b, $a) < 0) {
            $warnings[] = self::say(':to runs an older daemon (:old) than :from (:new). Update it first with “sudo serverosd update”.', ['to' => $to->name, 'old' => 'v'.$b, 'from' => $from->name, 'new' => 'v'.$a]);
        }

        [$archFrom, $archTo] = [$from->facts['arch'] ?? null, $to->facts['arch'] ?? null];
        if (is_string($archFrom) && is_string($archTo) && $archFrom !== $archTo) {
            $warnings[] = self::say(':from is :a and :to is :b. Apps built from code are rebuilt there, but a stack’s images must have a :b version.', ['from' => $from->name, 'a' => $archFrom, 'to' => $to->name, 'b' => $archTo]);
        }

        $memory = $to->facts['memory_bytes'] ?? null;
        if (is_numeric($memory) && $from->memory_used_mb !== null && (int) $memory < $from->memory_used_mb * 1024 * 1024) {
            $warnings[] = self::say(':to has less memory than :from uses now.', ['to' => $to->name, 'from' => $from->name]);
        }

        [$source, $target] = [self::root($from), self::root($to)];
        if ($source !== null && $target !== null && $source['used'] > $target['total'] - $target['used']) {
            $warnings[] = self::say(':to has less free disk than :from uses now.', ['to' => $to->name, 'from' => $from->name]);
        }

        return $warnings;
    }

    /** @return array{mount: string, used: int, total: int}|null */
    private static function root(Machine $machine): ?array
    {
        foreach ($machine->disks ?? [] as $disk) {
            if ($disk['mount'] === '/') {
                return $disk;
            }
        }

        return null;
    }

    /** @param  array<string, string>  $replace */
    private static function say(string $text, array $replace): string
    {
        $line = __($text, $replace);

        return is_string($line) ? $line : $text;
    }
}
