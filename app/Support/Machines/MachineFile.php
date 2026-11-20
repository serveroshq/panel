<?php

namespace App\Support\Machines;

use App\Mcp\Tools\Deploy\Deploys;
use App\Models\Machine;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\ServiceLabels;
use App\Support\ScheduledTasks;

/**
 * Everything ServerOS set up on a machine, as one portable file: the apps it
 * deployed (from their last deploy), what it can't set up again elsewhere and
 * why, and the machine's own settings. Setting values, file contents and
 * scheduled commands are never in it, since they can hold secrets.
 *
 * @phpstan-type App array{name: string, source: string, repo: string|null, commit: mixed, base_dir: mixed, dockerfile: mixed, compose_file: mixed, files: list<string>, env: list<string>, port: int|null, domains: list<string>, https: bool|null, health: array<mixed>|null, data: list<string>, services: list<string>}
 */
final class MachineFile
{
    public const string KIND = 'serveros-machine';

    public const int VERSION = 1;

    /** @return array<string, mixed> */
    public static function for(Machine $machine): array
    {
        $apps = self::apps($machine);
        $inApps = [];
        foreach ($apps as $app) {
            $inApps = [...$inApps, ...$app['services']];
        }

        return [
            'kind' => self::KIND,
            'version' => self::VERSION,
            'exported_at' => now()->toIso8601String(),
            'machine' => self::machine($machine),
            'apps' => array_map(fn (array $app) => array_diff_key($app, ['services' => true]), $apps),
            'not_moved' => self::notMoved($machine, $inApps),
            'scheduled' => self::scheduled($machine),
        ];
    }

    /**
     * Apps ServerOS deployed and still finds on the machine, by name, each
     * with the inventory keys of its containers.
     *
     * @return list<App>
     */
    public static function apps(Machine $machine): array
    {
        $found = [];
        foreach ($machine->inventory['services'] ?? [] as $service) {
            if (is_array($service) && is_string($service['key'] ?? null) && ($name = self::appOf($service)) !== null) {
                $found[$name][] = $service;
            }
        }
        ksort($found);

        $apps = [];
        foreach ($found as $name => $services) {
            $spec = Deploys::lastDeploy($machine, (string) $name)?->command['job'] ?? null;
            if (is_array($spec)) {
                $apps[] = self::app((string) $name, $spec, $services);
            }
        }

        return $apps;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<array<string, mixed>>  $services
     * @return App
     */
    private static function app(string $name, array $spec, array $services): array
    {
        $repo = is_string($spec['repo'] ?? null) ? $spec['repo'] : '';
        $health = is_array($spec['health'] ?? null) ? $spec['health'] : null;
        $env = array_map('strval', array_keys((array) ($spec['env'] ?? [])));
        $files = array_map('strval', array_keys((array) ($spec['files'] ?? [])));
        sort($env);
        sort($files);
        $data = [];
        foreach ($services as $service) {
            $method = $service['details']['backup'] ?? null;
            if (is_string($method) && $method !== '' && $method !== 'none' && ! in_array($method, $data, true)) {
                $data[] = $method;
            }
        }
        sort($data);

        return [
            'name' => $name,
            'source' => match (true) {
                $repo === '' => 'stack',
                Deploys::fromUpload($repo) => 'upload',
                default => 'git',
            },
            'repo' => Deploys::publicRepo($repo),
            'commit' => $repo === '' ? null : ($spec['commit'] ?? null),
            'base_dir' => $spec['base_dir'] ?? null,
            'dockerfile' => $spec['dockerfile'] ?? null,
            'compose_file' => $spec['compose_file'] ?? null,
            'files' => $files,
            'env' => $env,
            'port' => isset($spec['port']) ? (int) $spec['port'] : null,
            'domains' => array_values(array_map('strval', (array) ($spec['domains'] ?? []))),
            'https' => isset($spec['https']) ? (bool) $spec['https'] : null,
            'health' => $health === null ? null : array_intersect_key($health, array_flip(['path', 'expected_status', 'timeout_secs', 'retries'])),
            'data' => $data,
            'services' => array_map(fn (array $s) => (string) $s['key'], $services),
        ];
    }

    /** @param  array<string, mixed>  $service */
    private static function appOf(array $service): ?string
    {
        $project = (string) ($service['details']['compose_project'] ?? '');
        if (str_starts_with($project, 'serveros-')) {
            return substr($project, strlen('serveros-'));
        }

        return $project === '' ? (AppReleases::parse($service['name'] ?? null)['app'] ?? null) : null;
    }

    /**
     * @param  list<string>  $inApps
     * @return list<array{key: string, name: string, kind: string, reason: string}>
     */
    public static function notMoved(Machine $machine, array $inApps): array
    {
        $rows = [];
        foreach ($machine->inventory['services'] ?? [] as $service) {
            if (! is_array($service) || ! is_string($service['key'] ?? null) || in_array($service['key'], $inApps, true)) {
                continue;
            }
            $managed = ($service['managed'] ?? false) === true || ($service['details']['managed'] ?? null) === 'true';

            $rows[] = [
                'key' => $service['key'],
                'name' => ServiceLabels::display($machine, $service),
                'kind' => (string) ($service['kind'] ?? 'other'),
                'reason' => match (true) {
                    ! $managed => 'ServerOS only watches it, so it doesn’t know how it was set up.',
                    ($service['origin'] ?? null) !== 'created' => 'It was adopted: ServerOS didn’t set it up, so it can’t set it up again elsewhere.',
                    default => 'ServerOS has no deploy of it to repeat.',
                },
            ];
        }

        return $rows;
    }

    /** @return list<array{kind: string, schedule: string, user: string|null, service: string|null}> */
    public static function scheduled(Machine $machine): array
    {
        $rows = [];
        foreach (ScheduledTasks::for($machine) as $task) {
            if (! $task['ours']) {
                $rows[] = ['kind' => $task['kind'], 'schedule' => $task['schedule'], 'user' => $task['user'], 'service' => $task['service']['name'] ?? null];
            }
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private static function machine(Machine $machine): array
    {
        $facts = $machine->facts ?? [];

        return [
            'name' => $machine->name,
            'environment' => $machine->environment->value,
            'provider' => $machine->provider,
            'region' => $machine->region,
            'labels' => $machine->labels ?? (object) [],
            'os' => trim(($facts['os_name'] ?? $facts['os'] ?? '').' '.($facts['os_version'] ?? '')) ?: null,
            'arch' => $facts['arch'] ?? null,
            'cpu_cores' => $facts['cpu_cores'] ?? null,
            'memory_bytes' => $facts['memory_bytes'] ?? null,
            'docker_version' => $facts['docker_version'] ?? null,
            'daemon_version' => $machine->daemon_version,
            'proxy' => $machine->proxy,
            'backup_schedule' => $machine->backup_schedule,
        ];
    }
}
