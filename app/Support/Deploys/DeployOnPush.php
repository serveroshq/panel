<?php

namespace App\Support\Deploys;

use App\Http\Controllers\AppVersionController;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use RuntimeException;

/**
 * Apps set to deploy each push to a branch: the switch on the app's page,
 * so the common case needs no workflow. A push to that branch of the app's
 * GitHub repository deploys the pushed commit, with everything else as the
 * app's last deploy had it.
 */
final class DeployOnPush
{
    public const string BRANCH_PATTERN = '/^(?!\/|.*\/$|.*\.\.|.*\/\/)[A-Za-z0-9._\/-]{1,200}$/';

    public function __construct(private readonly DaemonJobQueue $queue) {}

    /** "owner/name" for a github.com repository address, lowercased; null for anything else. */
    public static function repoName(?string $url): ?string
    {
        if (! is_string($url) || ! preg_match('#^https://github\.com/([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?/?$#', $url, $m)) {
            return null;
        }

        return strtolower($m[1].'/'.$m[2]);
    }

    /** @return array{on: bool, branch: string} */
    public static function setting(Machine $machine, string $app): array
    {
        $settings = $machine->app_settings[$app] ?? [];

        return [
            'on' => (bool) ($settings['deploy_on_push'] ?? false),
            'branch' => is_string($settings['push_branch'] ?? null) && $settings['push_branch'] !== '' ? $settings['push_branch'] : 'main',
        ];
    }

    /**
     * @param  list<int>  $workspaces  the workspaces the push's GitHub installation covers
     * @return array{started: int, skipped: int}
     */
    public function handle(array $workspaces, string $repo, string $branch, string $commit): array
    {
        $started = 0;
        $skipped = 0;
        $repo = strtolower($repo);

        $machines = Machine::query()->whereIn('workspace_id', $workspaces)->whereNotNull('app_settings')->with('workspace')->get();
        foreach ($machines as $machine) {
            foreach (array_keys($machine->app_settings ?? []) as $app) {
                $app = (string) $app;
                $setting = self::setting($machine, $app);
                if (! $setting['on'] || $setting['branch'] !== $branch) {
                    continue;
                }
                $last = AppVersionController::lastDeployOf($machine, $app);
                if ($last === null || self::repoName($last->command['job']['repo'] ?? null) !== $repo) {
                    continue;
                }
                if ($this->alreadyDeploying($machine, $app, $commit)) {
                    $skipped++;

                    continue;
                }

                $user = $this->actor($machine, $app);
                $reason = $user === null ? 'no one in the workspace can deploy it' : $this->queue->unavailable($machine, 'deploy');
                if ($reason === null && $user !== null) {
                    try {
                        $this->queue->deploy($machine, $user, [...$last->command['job'], 'commit' => $commit]);
                        $started++;

                        continue;
                    } catch (RuntimeException $e) {
                        $reason = $e->getMessage();
                    }
                }

                Activity::system($machine->workspace, 'app.push_skipped', $app, $machine, [
                    'reason' => $reason, 'branch' => $branch, 'commit' => substr($commit, 0, 7),
                ]);
                $skipped++;
            }
        }

        return ['started' => $started, 'skipped' => $skipped];
    }

    // GitHub sends a push again when a delivery is retried; the commit
    // queued, running or live already is never deployed twice.
    private function alreadyDeploying(Machine $machine, string $app, string $commit): bool
    {
        return DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'deploy')
            ->where('service', $app)
            ->whereIn('state', ['queued', 'running', 'succeeded'])
            ->where('created_at', '>=', now()->subDay())
            ->get()
            ->contains(fn (DaemonJob $job) => strcasecmp((string) ($job->command['job']['commit'] ?? ''), $commit) === 0);
    }

    // The person who turned the switch on, while they can still manage the
    // workspace's machines; the workspace's owner otherwise.
    private function actor(Machine $machine, string $app): ?User
    {
        $workspace = $machine->workspace;
        $id = $machine->app_settings[$app]['push_user_id'] ?? null;
        $user = is_int($id) ? User::query()->find($id) : null;
        if ($user !== null && $workspace->members()->whereKey($user->id)->exists() && $user->can('manageMachines', $workspace)) {
            return $user;
        }

        return $workspace->owner;
    }
}
