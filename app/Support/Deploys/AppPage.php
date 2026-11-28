<?php

namespace App\Support\Deploys;

use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\Projects\AppSettings;

/**
 * What the app's page shows about how it's deployed, from its latest
 * deploy (failed or not), so every setting can be fixed after a first
 * deploy that didn't work.
 */
final class AppPage
{
    /** @return array<string, mixed>|null null when it was never deployed as an app */
    public static function props(Machine $machine, string $app): ?array
    {
        $current = AppDeploys::current($machine, $app);
        $spec = AppDeploys::spec($machine, $app);
        if ($current === null || $spec === null) {
            return null;
        }
        $repo = (string) ($spec['repo'] ?? '');
        $source = match (true) {
            AppDeploys::isImage($spec) => 'image',
            str_contains($repo, '/api/deploy-sources/') => 'upload',
            default => 'git',
        };
        $upload = $source === 'upload' ? DeploySource::fromRepoUrl($repo) : null;
        $settings = BuildSettings::fromSpec($spec);
        $new = DaemonJobQueue::canDeployBuild($machine);
        $busy = AppDeploys::busy($machine, $app);
        $builds = in_array($settings->method, [BuildSettings::RAILPACK, BuildSettings::DOCKERFILE, BuildSettings::TEMPLATE], true);
        $push = DeployOnPush::repoName($repo);

        return [
            'service' => $app,
            'source' => $source,
            'repo' => $source === 'git' ? $repo : null,
            'upload' => $upload === null ? null : ['filename' => $upload->filename, 'usable' => $upload->usable()],
            'branch' => $source === 'git' ? DeployOnPush::setting($machine, $app)['branch'] : null,
            'commit' => $source === 'image' ? null : ($spec['commit'] ?? null),
            'liveCommit' => AppReleases::live($machine)[$app] ?? null,
            'domains' => array_values(array_filter((array) ($spec['domains'] ?? []), 'is_string')),
            // The port the daemon used when none was set (an image's or Dockerfile's EXPOSE).
            'detectedPort' => self::detectedPort($machine, $app),
            'canDeployBuild' => $new,
            'update' => $new ? null : 'Update the ServerOS daemon on '.$machine->name.' to v'.config('daemon.deploy_build_since').' or later for Railpack, images, volumes, release commands and the rest of the build settings.',
            'build' => $settings->toArray(AppSettings::managedNames($machine, $app)),
            'deploying' => $busy === null ? null : AppDeploys::row($busy),
            'lastFailure' => AppDeploys::lastFailure($machine, $app),
            'proxyNote' => self::proxyNote($machine, $app),
            'hook' => $source === 'upload' ? null : DeployHooks::props($machine, $app),
            'schedules' => AppSchedules::props($machine, $app),
            'schedulesUnavailable' => AppSchedules::unavailable($spec),
            'pushToDeploy' => $push === null ? null : DeployOnPush::setting($machine, $app),
            'can' => [
                'deployLatest' => $source === 'git',
                'deployCommit' => $source === 'git',
                'rebuild' => $new && $builds,
                'redeploy' => $source !== 'upload' || ($upload?->usable() ?? false),
                'hook' => $source !== 'upload',
            ],
        ];
    }

    private static function detectedPort(Machine $machine, string $app): ?int
    {
        $result = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->where('service', $app)
            ->where('state', 'succeeded')->latest()->value('result');
        $port = is_array($result) ? ($result['container_port'] ?? null) : (is_string($result) ? (json_decode($result, true)['container_port'] ?? null) : null);

        return is_int($port) && $port > 0 && $port <= 65535 ? $port : null;
    }

    /** The latest deploy left the app up and did not change the reverse proxy. */
    private static function proxyNote(Machine $machine, string $app): ?string
    {
        $result = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->where('service', $app)
            ->where('state', 'succeeded')->latest()->value('result');
        $note = is_array($result) ? ($result['proxy_note'] ?? null) : (is_string($result) ? (json_decode($result, true)['proxy_note'] ?? null) : null);

        return is_string($note) && $note !== '' ? $note : null;
    }
}
