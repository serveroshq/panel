<?php

namespace App\Support\Integrations\GitLab;

use App\Http\Controllers\AppVersionController;
use App\Models\Activity;
use App\Models\GitLabProjectHook;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\DeployOnPush;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * The webhooks ServerOS keeps on GitLab projects: one per project while any
 * of its apps deploys each push, none once no app does.
 */
final class GitLabHooks
{
    public function __construct(private readonly GitLabRepositories $repositories) {}

    public static function url(GitLabProjectHook|string $hook): string
    {
        return rtrim((string) config('app.url'), '/').'/api/gitlab/webhook/'.($hook instanceof GitLabProjectHook ? $hook->uuid : $hook);
    }

    /**
     * The project's webhook, added at GitLab when it isn't there yet.
     *
     * @throws IntegrationFailed
     */
    public function ensure(Integration $integration, string $path): GitLabProjectHook
    {
        return Cache::lock("gitlab-hook:{$integration->id}:".strtolower($path), 30)->block(10, function () use ($integration, $path): GitLabProjectHook {
            $client = GitLabRepositories::client($integration);
            $project = GitLabClient::project($path);
            $row = $this->row($integration, $path);

            if ($row !== null) {
                try {
                    $client->get(GitLabClient::project($row->project).'/hooks/'.$row->hook_id);

                    return $row;
                } catch (IntegrationFailed $e) {
                    if ($e->reason !== IntegrationFailed::NOT_FOUND) {
                        throw $e;
                    }
                    $row->delete();
                }
            }

            $uuid = (string) Str::uuid();
            $secret = Str::random(48);
            try {
                $found = $client->get($project);
                $made = $client->post($project.'/hooks', [
                    'url' => self::url($uuid),
                    'token' => $secret,
                    'name' => 'ServerOS',
                    'description' => 'Deploys pushes to the apps ServerOS made from this project.',
                    'push_events' => true,
                    'tag_push_events' => false,
                    'merge_requests_events' => false,
                    'enable_ssl_verification' => true,
                ]);
            } catch (IntegrationFailed $e) {
                throw match ($e->reason) {
                    IntegrationFailed::PERMISSION => new IntegrationFailed(self::t('GitLab didn’t let this token add a webhook to :project. It needs the api scope, from an account that’s a Maintainer of the project.', ['project' => $path]), IntegrationFailed::PERMISSION, $e->status),
                    IntegrationFailed::NOT_FOUND => new IntegrationFailed(self::t('GitLab can’t find :project with this token. It may have been moved or deleted.', ['project' => $path]), IntegrationFailed::NOT_FOUND, $e->status),
                    IntegrationFailed::INVALID => new IntegrationFailed(self::t('GitLab couldn’t add the webhook: :reason', ['reason' => $e->getMessage()]), IntegrationFailed::INVALID, $e->status),
                    default => $e,
                };
            }
            if (! is_int($made['id'] ?? null) || ! is_int($found['id'] ?? null)) {
                throw new IntegrationFailed(self::t('GitLab added the webhook but didn’t say which one it is.'));
            }

            return GitLabProjectHook::query()->create([
                'uuid' => $uuid,
                'integration_id' => $integration->id,
                'project_id' => $found['id'],
                'project' => is_string($found['path_with_namespace'] ?? null) ? $found['path_with_namespace'] : $path,
                'hook_id' => $made['id'],
                'secret' => $secret,
            ]);
        });
    }

    /** Takes off the webhooks no app needs any more. */
    public function sync(Workspace $workspace): void
    {
        $needed = [];
        foreach ($this->apps($workspace) as $app) {
            if ($app['push']['on']) {
                $needed[$app['integration']->id.':'.strtolower($app['path'])] = true;
            }
        }
        $hooks = GitLabProjectHook::query()
            ->whereIn('integration_id', $workspace->integrations()->where('provider', GitLabRepositories::PROVIDER)->select('id'))
            ->with('integration')
            ->get();
        foreach ($hooks as $hook) {
            if (! isset($needed[$hook->integration_id.':'.strtolower($hook->project)])) {
                $this->remove($hook);
            }
        }
    }

    /** @return int how many webhooks were taken off */
    public function removeAll(Integration $integration): int
    {
        $removed = 0;
        foreach (GitLabProjectHook::query()->where('integration_id', $integration->id)->get() as $hook) {
            $hook->setRelation('integration', $integration);
            $removed += $this->remove($hook) ? 1 : 0;
        }

        return $removed;
    }

    /** Takes the webhook off GitLab. One GitLab won't take off is kept, so it's tried again later. */
    public function remove(GitLabProjectHook $hook): bool
    {
        try {
            GitLabRepositories::client($hook->integration)->delete(GitLabClient::project($hook->project).'/hooks/'.$hook->hook_id);
        } catch (IntegrationFailed $e) {
            if ($e->reason !== IntegrationFailed::NOT_FOUND) {
                return false;
            }
        }
        $hook->delete();

        return true;
    }

    /**
     * An app's container was removed: once none of its containers is left, a
     * GitLab app stops deploying each push, and its webhook goes when no
     * other app needs it.
     *
     * @param  array<string, mixed>  $before  the machine's inventory with it
     * @param  array<string, mixed>  $inventory  and without it
     */
    public function removed(Machine $machine, string $key, array $before, array $inventory): bool
    {
        $gone = collect((array) ($before['services'] ?? []))->first(fn ($service) => is_array($service) && ($service['key'] ?? null) === $key);
        $release = is_array($gone) ? AppReleases::parse($gone['name'] ?? null) : null;
        if ($release === null) {
            return false;
        }
        $app = $release['app'];
        foreach ((array) ($inventory['services'] ?? []) as $service) {
            if (is_array($service) && (AppReleases::parse($service['name'] ?? null)['app'] ?? null) === $app) {
                return false;
            }
        }
        if (! DeployOnPush::setting($machine, $app)['on']
            || ! $this->repositories->onConnectedInstance($machine->workspace, AppVersionController::lastDeployOf($machine, $app)?->command['job']['repo'] ?? null)) {
            return false;
        }

        $settings = $machine->app_settings ?? [];
        $settings[$app]['deploy_on_push'] = false;
        $machine->forceFill(['app_settings' => $settings])->save();
        Activity::system($machine->workspace, 'app.settings', $app, $machine, ['deploy_on_push' => false]);

        return true;
    }

    /**
     * The workspace's apps deployed from a connected GitLab account.
     *
     * @return list<array{machine: Machine, app: string, integration: Integration, path: string, push: array{on: bool, branch: string, onlyNewest: bool}}>
     */
    public function apps(Workspace $workspace, ?Integration $only = null): array
    {
        if (! $workspace->integrations()->where('provider', GitLabRepositories::PROVIDER)->exists()) {
            return [];
        }
        $apps = [];
        foreach ($workspace->machines()->orderBy('name')->get() as $machine) {
            $machine->setRelation('workspace', $workspace);
            foreach (array_keys(AppReleases::live($machine)) as $app) {
                $repo = AppVersionController::lastDeployOf($machine, (string) $app)?->command['job']['repo'] ?? null;
                $found = $this->repositories->find($workspace, is_string($repo) ? $repo : null);
                if ($found === null || ($only !== null && $found[0]->id !== $only->id)) {
                    continue;
                }
                $apps[] = [
                    'machine' => $machine,
                    'app' => (string) $app,
                    'integration' => $found[0],
                    'path' => $found[1],
                    'push' => DeployOnPush::setting($machine, (string) $app),
                ];
            }
        }

        return $apps;
    }

    /**
     * The account's projects that apps are deployed from, with the apps and
     * the state of the project's webhook at GitLab.
     *
     * @return list<array{path: string, url: string, apps: list<array{machine: string, app: string, href: string, push: bool, branch: string}>, hook: array{state: string, lastPushAt: string|null}}>
     */
    public function projects(Integration $integration): array
    {
        $instance = GitLabRepositories::instanceOf($integration);
        $hooks = GitLabProjectHook::query()->where('integration_id', $integration->id)->get()->keyBy(fn (GitLabProjectHook $hook) => strtolower($hook->project));
        $client = GitLabRepositories::client($integration);
        $projects = [];

        foreach ($this->apps($integration->workspace, $integration) as $app) {
            if (AppReleases::find($app['machine'], $app['app']) === null) {
                continue;
            }
            $key = strtolower($app['path']);
            $projects[$key] ??= ['path' => $app['path'], 'url' => $instance.'/'.$app['path'], 'apps' => [], 'hook' => null];
            $projects[$key]['apps'][] = [
                'machine' => $app['machine']->name,
                'app' => $app['app'],
                'href' => "/machines/{$app['machine']->id}/services/app:{$app['app']}",
                'push' => $app['push']['on'],
                'branch' => $app['push']['branch'],
            ];
        }

        foreach ($projects as $key => $project) {
            $hook = $hooks->get($key);
            $state = 'none';
            if ($hook instanceof GitLabProjectHook) {
                try {
                    $live = $client->get(GitLabClient::project($hook->project).'/hooks/'.$hook->hook_id);
                    $state = in_array($live['alert_status'] ?? 'executable', ['disabled', 'temporarily_disabled'], true) ? 'disabled' : 'active';
                } catch (IntegrationFailed $e) {
                    $state = $e->reason === IntegrationFailed::NOT_FOUND ? 'missing' : 'unknown';
                }
            }
            $projects[$key]['hook'] = ['state' => $state, 'lastPushAt' => $hook?->last_push_at?->toIso8601String()];
        }
        ksort($projects);

        return array_values($projects);
    }

    private function row(Integration $integration, string $path): ?GitLabProjectHook
    {
        return GitLabProjectHook::query()->where('integration_id', $integration->id)->get()
            ->first(fn (GitLabProjectHook $hook) => strcasecmp($hook->project, $path) === 0);
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
