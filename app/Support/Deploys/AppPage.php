<?php

namespace App\Support\Deploys;

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
            'canDeployBuild' => $new,
            'update' => $new ? null : 'Update the ServerOS daemon on '.$machine->name.' to v'.config('daemon.deploy_build_since').' or later for Railpack, images, volumes, release commands and the rest of the build settings.',
            'build' => $settings->toArray(AppSettings::managedNames($machine, $app)),
            'deploying' => $busy === null ? null : AppDeploys::row($busy),
            'lastFailure' => AppDeploys::lastFailure($machine, $app),
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
}
