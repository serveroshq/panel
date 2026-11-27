<?php

namespace App\Support\Deploys;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DaemonJobQueue;
use App\Support\GitHub\GitHubApp;
use App\Support\Integrations\GitLab\GitLabRepositories;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Apps set to deploy each push to a branch: the switch on the app's page,
 * so the common case needs no workflow. A push to that branch of the app's
 * GitHub repository (or GitLab project) deploys the pushed commit, with everything else as the
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

    // Deploys of a pushed commit: from GitHub's push event, or a deploy hook.
    public const array PUSH_TRIGGERS = [self::TRIGGER, ...DeployHooks::TRIGGERS];

    public const string REPLACED = 'A newer push replaced this deploy.';

    public function __construct(private readonly DaemonJobQueue $queue) {}

    /** "owner/name" for a github.com repository address, lowercased; null for anything else. */
    public static function repoName(?string $url): ?string
    {
        if (! is_string($url) || ! preg_match('#^https://github\.com/([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?/?$#', $url, $m)) {
            return null;
        }

        return strtolower($m[1].'/'.$m[2]);
    }

    /**
     * Why pushes to this repository can't deploy an app: only a GitHub
     * repository the workspace's GitHub connection can see sends them.
     * Null when they can.
     */
    public static function cannotWatch(Workspace $workspace, mixed $repo): ?string
    {
        $name = self::repoName(is_string($repo) ? $repo : null);
        if ($name === null) {
            return 'Deploying each push works for apps deployed from GitHub, or from GitLab with its account connected in Integrations. For other hosts, use the app’s deploy hook.';
        }
        if (! GitHubApp::enabled()) {
            return 'Deploying each push needs GitHub connected to this panel. Use the app’s deploy hook instead, or ask whoever runs the panel to set up the GitHub app.';
        }
        [$owner, $repository] = explode('/', $name, 2);
        if (app(GitHubApp::class)->tokenFor($workspace, $owner, $repository) === null) {
            return GitHubApp::connected($workspace, $owner)
                ? "The ServerOS app on GitHub can’t see {$name}. Add it to the app’s repositories on GitHub, then turn this on."
                : "Connect GitHub in Settings › General and give the ServerOS app {$name}, then turn this on. Pushes reach ServerOS through it.";
        }

        return null;
    }

    /** Whether pushes can deploy an app from this address: a GitHub repository, or a project of a GitLab account the workspace connected. */
    public static function supports(Workspace $workspace, ?string $url): bool
    {
        return self::repoName($url) !== null || app(GitLabRepositories::class)->find($workspace, $url) !== null;
    }

    /** @return array{on: bool, branch: string, onlyNewest: bool} */
    public static function setting(Machine $machine, string $app): array
    {
        $settings = $machine->app_settings[$app] ?? [];

        return [
            'on' => (bool) ($settings['deploy_on_push'] ?? false),
            'branch' => AppDeploys::branch($machine, $app) ?? 'main',
            'onlyNewest' => (bool) ($settings['push_only_newest'] ?? true),
        ];
    }

    /**
     * @param  list<int>  $workspaces  the workspaces the push's GitHub installation (or GitLab account) covers
     * @param  (\Closure(string|null): bool)|null  $sameRepo  whether an app's repository address is the pushed one; a GitHub "owner/name" when null
     * @return array{started: int, skipped: int}
     */
    public function handle(array $workspaces, string $repo, string $branch, string $commit, ?\Closure $sameRepo = null): array
    {
        $started = 0;
        $skipped = 0;
        $repo = strtolower($repo);
        $sameRepo ??= fn (?string $url): bool => self::repoName($url) === $repo;

        $machines = Machine::query()->whereIn('workspace_id', $workspaces)->whereNotNull('app_settings')->with('workspace')->get();
        foreach ($machines as $machine) {
            foreach (array_keys($machine->app_settings ?? []) as $app) {
                $app = (string) $app;
                $setting = self::setting($machine, $app);
                if (! $setting['on'] || $setting['branch'] !== $branch) {
                    continue;
                }
                $spec = AppDeploys::spec($machine, $app);
                $address = $spec['repo'] ?? null;
                if ($spec === null || ! $sameRepo(is_string($address) ? $address : null)) {
                    continue;
                }

                $outcome = $this->deployCommit($machine, $app, $commit, $branch, self::TRIGGER);
                $outcome['started'] ? $started++ : $skipped++;
            }
        }

        return ['started' => $started, 'skipped' => $skipped];
    }

    /**
     * Deploys a pushed commit of an app, with everything else as its latest
     * deploy had it: from GitHub's push to its branch, or its deploy hook.
     * With "only the newest push" on, it waits while the app deploys, and
     * replaces an older push that's waiting. Why it didn't is recorded.
     *
     * @param  array<string, mixed>  $meta  more for the activity log
     * @param  bool  $again  deploy it even when that commit is live (an image, pulled again)
     * @return array{started: bool, reason: string|null, job: DaemonJob|null} reason is null for a commit already deploying
     */
    public function deployCommit(Machine $machine, string $app, string $commit, string $branch, string $trigger, ?User $user = null, array $meta = [], bool $again = false): array
    {
        $spec = AppDeploys::spec($machine, $app);
        if ($spec === null) {
            return ['started' => false, 'reason' => 'it has no deploy to copy settings from', 'job' => null];
        }
        if ($this->alreadyDeploying($machine, $app, $commit, $again)) {
            return ['started' => false, 'reason' => null, 'job' => null];
        }

        $setting = self::setting($machine, $app);
        $user ??= $this->actor($machine, $app);
        $reason = $user === null ? 'no one in the workspace can deploy it' : $this->queue->unavailable($machine, 'deploy');
        $job = null;
        if ($reason === null && $user !== null) {
            $spec = [...$spec, 'commit' => strtolower($commit)];
            try {
                [$reason, $job] = $this->locked($machine, $app, function () use ($machine, $app, $user, $spec, $commit, $branch, $setting, $trigger, $meta): array {
                    $hold = false;
                    if ($setting['onlyNewest']) {
                        $this->replaceWaiting($machine, $app, $commit, $branch);
                        $hold = $this->busy($machine, $app);
                    }
                    try {
                        return [null, $this->queue->deploy($machine, $user, $spec, hold: $hold, trigger: $trigger, meta: $meta)];
                    } catch (RuntimeException $e) {
                        return [$e->getMessage(), null];
                    }
                });
            } catch (LockTimeoutException) {
                $reason = 'another push to it was being handled at the same moment';
            }
            if ($reason === null) {
                return ['started' => true, 'reason' => null, 'job' => $job];
            }
        }

        Activity::system($machine->workspace, 'app.push_skipped', $app, $machine, [
            'reason' => $reason, 'branch' => $branch, 'commit' => substr($commit, 0, 7), 'trigger' => $trigger,
        ]);

        return ['started' => false, 'reason' => $reason, 'job' => null];
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
    // has (one the daemon never answered on, past its timeout, doesn't count).
    private function busy(Machine $machine, string $app): bool
    {
        return AppDeploys::busy($machine, $app) !== null;
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
            ->whereIn('trigger', self::PUSH_TRIGGERS)
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
    private function alreadyDeploying(Machine $machine, string $app, string $commit, bool $again = false): bool
    {
        return DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'deploy')
            ->where('service', $app)
            ->whereIn('state', $again ? ['queued', 'running'] : ['queued', 'running', 'succeeded'])
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
