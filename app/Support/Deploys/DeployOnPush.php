<?php

namespace App\Support\Deploys;

use App\Http\Controllers\AppVersionController;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Apps set to deploy each push to a branch: the switch on the app's page,
 * so the common case needs no workflow. A push to that branch of the app's
 * GitHub repository deploys the pushed commit, with everything else as the
 * app's last deploy had it.
 *
 * With "only the newest push" on (the default), a push that arrives while
 * the app is deploying waits in the panel instead of building beside it,
 * and a newer push replaces it there, so only the newest commit is built.
 * Only waiting push deploys are ever dropped: never one a person, an AI
 * app, the API or a workflow started, and never a rollback.
 */
final class DeployOnPush
{
    public const string BRANCH_PATTERN = '/^(?!\/|.*\/$|.*\.\.|.*\/\/)[A-Za-z0-9._\/-]{1,200}$/';

    public const string TRIGGER = 'push';

    public const string REPLACED = 'A newer push replaced this deploy.';

    // What a held push deploy waits for: anything else changing the app.
    private const array BLOCKING_KINDS = ['deploy', 'rollback'];

    public function __construct(private readonly DaemonJobQueue $queue) {}

    /** "owner/name" for a github.com repository address, lowercased; null for anything else. */
    public static function repoName(?string $url): ?string
    {
        if (! is_string($url) || ! preg_match('#^https://github\.com/([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?/?$#', $url, $m)) {
            return null;
        }

        return strtolower($m[1].'/'.$m[2]);
    }

    /** @return array{on: bool, branch: string, onlyNewest: bool} */
    public static function setting(Machine $machine, string $app): array
    {
        $settings = $machine->app_settings[$app] ?? [];

        return [
            'on' => (bool) ($settings['deploy_on_push'] ?? false),
            'branch' => is_string($settings['push_branch'] ?? null) && $settings['push_branch'] !== '' ? $settings['push_branch'] : 'main',
            'onlyNewest' => (bool) ($settings['push_only_newest'] ?? true),
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
                    $job = [...$last->command['job'], 'commit' => $commit];
                    try {
                        $reason = $this->locked($machine, $app, function () use ($machine, $app, $user, $job, $commit, $branch, $setting): ?string {
                            $hold = false;
                            if ($setting['onlyNewest']) {
                                $this->replaceWaiting($machine, $app, $commit, $branch);
                                $hold = $this->busy($machine, $app);
                            }
                            try {
                                $this->queue->deploy($machine, $user, $job, hold: $hold, trigger: self::TRIGGER);
                            } catch (RuntimeException $e) {
                                return $e->getMessage();
                            }

                            return null;
                        });
                    } catch (LockTimeoutException) {
                        $reason = 'another push to it was being handled at the same moment';
                    }
                    if ($reason === null) {
                        $started++;

                        continue;
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

    /**
     * Send the newest push deploy waiting for this app, once nothing else
     * is deploying it. Called when one of its deploys finishes, and every
     * minute in case that was missed.
     */
    public function release(Machine $machine, string $app): ?DaemonJob
    {
        try {
            return $this->locked($machine, $app, function () use ($machine, $app): ?DaemonJob {
                if ($this->busy($machine, $app)) {
                    return null;
                }
                $waiting = $this->waiting($machine, $app);
                $newest = $waiting->shift();
                foreach ($waiting as $older) {
                    $this->replace($older, (string) ($newest?->command['job']['commit'] ?? ''));
                }
                if ($newest !== null) {
                    $this->queue->release($newest);
                }

                return $newest;
            });
        } catch (LockTimeoutException) {
            return null;
        }
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     *
     * @throws LockTimeoutException
     */
    private function locked(Machine $machine, string $app, callable $callback): mixed
    {
        return Cache::lock('deploy-on-push:'.$machine->id.':'.$app, 30)->block(10, $callback);
    }

    // Whether the app is deploying or rolling back now, in a job its machine
    // has (a stale one, the daemon never answered, doesn't count).
    private function busy(Machine $machine, string $app): bool
    {
        return DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('service', $app)
            ->whereIn('kind', self::BLOCKING_KINDS)
            ->whereIn('state', ['queued', 'running'])
            ->where('held', false)
            ->where('created_at', '>=', now()->subSeconds(DaemonJobQueue::DEPLOY_TIMEOUT_SECS + 600))
            ->get()
            ->contains(fn (DaemonJob $job) => ! $job->isStale());
    }

    /** @return Collection<int, DaemonJob> newest first */
    private function waiting(Machine $machine, string $app): Collection
    {
        return DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('service', $app)
            ->where('kind', 'deploy')
            ->where('state', 'queued')
            ->where('held', true)
            ->where('trigger', self::TRIGGER)
            ->orderByDesc('created_at')
            ->get();
    }

    private function replaceWaiting(Machine $machine, string $app, string $commit, string $branch): void
    {
        foreach ($this->waiting($machine, $app) as $older) {
            $this->replace($older, $commit, $branch);
        }
    }

    private function replace(DaemonJob $older, string $newer, ?string $branch = null): void
    {
        $older->forceFill([
            'state' => 'cancelled',
            'held' => false,
            'phase' => null,
            'error' => ['phase' => 'panel', 'message' => self::REPLACED],
            'finished_at' => now(),
        ])->save();

        Activity::system($older->machine->workspace, 'app.push_replaced', (string) $older->service, $older->machine, array_filter([
            'commit' => substr((string) ($older->command['job']['commit'] ?? ''), 0, 7),
            'newer' => substr($newer, 0, 7),
            'branch' => $branch,
        ]));
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
