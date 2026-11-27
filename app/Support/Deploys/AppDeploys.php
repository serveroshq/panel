<?php

namespace App\Support\Deploys;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\DaemonJobQueue;
use Illuminate\Support\Collection;

/**
 * An app's deploys: what it's set up as (its latest deploy's spec, whether
 * that deploy worked or not, so a failed first deploy can be fixed), whether
 * it's deploying now, its history and why the last one failed.
 */
final class AppDeploys
{
    public const array FINISHED = ['succeeded', 'failed', 'timed_out', 'cancelled', 'refused'];

    public const array FAILED = ['failed', 'timed_out', 'refused'];

    /**
     * An app ServerOS builds or runs itself, as one release at a time:
     * from code (a repository, or an uploaded .zip) or a prebuilt image.
     * Template stacks (a ready-made Compose file) aren't.
     */
    public static function isApp(mixed $spec): bool
    {
        if (! is_array($spec)) {
            return false;
        }

        return filled($spec['repo'] ?? null)
            || (is_array($spec['build'] ?? null) && ($spec['build']['method'] ?? null) === BuildSettings::IMAGE && filled($spec['build']['image'] ?? null));
    }

    public static function isImage(mixed $spec): bool
    {
        return is_array($spec) && blank($spec['repo'] ?? null) && self::isApp($spec);
    }

    /**
     * The deploy whose spec is the app's setup: its newest one sent with
     * settings, in any state. Null when it was never deployed as an app.
     */
    public static function current(Machine $machine, string $app): ?DaemonJob
    {
        return DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'deploy')
            ->where('service', $app)
            ->latest()
            ->limit(20)
            ->get()
            ->first(fn (DaemonJob $job) => $job->command !== null && self::isApp($job->command['job'] ?? null));
    }

    /** @return array<string, mixed>|null the app's spec, without what's only for one deploy */
    public static function spec(Machine $machine, string $app): ?array
    {
        $job = self::current($machine, $app);
        if ($job === null) {
            return null;
        }
        $spec = (array) $job->command['job'];
        unset($spec['snapshot_before'], $spec['snapshot_also'], $spec['repo_token'], $spec['registries']);
        if (is_array($spec['build'] ?? null)) {
            unset($spec['build']['no_cache']);
        }
        foreach (['env', 'files'] as $map) {
            if (is_object($spec[$map] ?? null)) {
                $spec[$map] = (array) $spec[$map];
            }
        }

        return $spec;
    }

    /**
     * A deploy or rollback of the app the machine is working on now. One
     * that's gone quiet still counts until its own timeout is up; one held
     * in the panel doesn't.
     */
    public static function busy(Machine $machine, string $app, ?string $except = null): ?DaemonJob
    {
        return DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('service', $app)
            ->whereIn('kind', DaemonJob::LONG_KINDS)
            ->whereIn('state', ['queued', 'running'])
            ->where('held', false)
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except))
            ->where('created_at', '>=', now()->subSeconds(DaemonJobQueue::DEPLOY_TIMEOUT_SECS * 3))
            ->latest()
            ->get()
            ->first(fn (DaemonJob $job) => ! $job->isStale());
    }

    /** The branch the app was deployed from, which its push deploys follow. */
    public static function branch(Machine $machine, string $app): ?string
    {
        $settings = $machine->app_settings[$app] ?? [];
        foreach (['push_branch', 'branch'] as $key) {
            if (is_string($settings[$key] ?? null) && preg_match(DeployOnPush::BRANCH_PATTERN, $settings[$key]) === 1) {
                return $settings[$key];
            }
        }

        return null;
    }

    public static function rememberBranch(Machine $machine, string $app, ?string $branch): void
    {
        if ($branch === null || preg_match(DeployOnPush::BRANCH_PATTERN, $branch) !== 1 || preg_match(DeployRequest::COMMIT_PATTERN, $branch) === 1) {
            return;
        }
        $settings = $machine->app_settings ?? [];
        if (($settings[$app]['branch'] ?? null) === $branch) {
            return;
        }
        $settings[$app] = [...($settings[$app] ?? []), 'branch' => $branch];
        $machine->forceFill(['app_settings' => $settings])->save();
    }

    /**
     * The commit a settings change deploys again: the one that's live, so a
     * rolled-back app stays on the version it was rolled back to.
     *
     * @param  array<string, mixed>  $spec
     */
    public static function liveCommit(Machine $machine, string $app, array $spec): string
    {
        $commit = strtolower((string) ($spec['commit'] ?? ''));
        $live = AppReleases::live($machine)[$app] ?? null;
        if ($live === null || str_starts_with($commit, $live) || ! filled($spec['repo'] ?? null)) {
            return $commit;
        }
        $full = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->where('service', $app)
            ->where('state', 'succeeded')->latest()->limit(50)->get()
            ->map(fn (DaemonJob $job) => strtolower((string) ($job->command['job']['commit'] ?? '')))
            ->first(fn (string $candidate) => str_starts_with($candidate, $live));

        return $full ?? $commit;
    }

    /** @return Collection<int, DaemonJob> newest first */
    public static function history(Machine $machine, string $app, int $limit = 30, ?string $before = null): Collection
    {
        return DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('service', $app)
            ->whereIn('kind', DaemonJob::LONG_KINDS)
            ->when($before !== null, fn ($query) => $query->where('created_at', '<', $before))
            ->with('user:id,name')
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Where a deploy came from: a person (on the dashboard, or through the
     * API, MCP or a workflow), a push, a deploy hook, a retry.
     */
    public static function trigger(DaemonJob $job): string
    {
        if (is_string($job->trigger) && $job->trigger !== '') {
            return $job->trigger;
        }
        $via = $job->command['actor']['via'] ?? null;

        return is_string($via) && in_array($via, ['api', 'mcp', 'workflow', 'assistant'], true) ? $via : 'dashboard';
    }

    /** @return array<string, mixed> */
    public static function row(DaemonJob $job): array
    {
        $spec = (array) ($job->command['job'] ?? []);
        $started = $job->created_at;
        $finished = $job->finished_at;

        return [
            'id' => $job->id,
            'kind' => $job->kind,
            'state' => $job->isStale() ? 'stale' : $job->state,
            'phase' => $job->phase,
            'progress' => $job->progress,
            'trigger' => self::trigger($job),
            'by' => $job->startedByName(),
            'commit' => $job->kind === 'rollback'
                ? (is_string($job->result['commit'] ?? null) ? $job->result['commit'] : ($spec['release'] ?? null))
                : (filled($spec['repo'] ?? null) ? ($spec['commit'] ?? null) : null),
            'image' => is_array($spec['build'] ?? null) ? ($spec['build']['image'] ?? null) : null,
            'noCache' => (bool) ($spec['build']['no_cache'] ?? false),
            'message' => is_array($job->error) && is_string($job->error['message'] ?? null) ? $job->error['message'] : null,
            'explanation' => in_array($job->state, self::FAILED, true) ? DeployFailure::explain($job) : null,
            'retryable' => $job->isRetryable(),
            'createdAt' => $started?->toIso8601String(),
            'finishedAt' => $finished?->toIso8601String(),
            'durationSecs' => $started !== null && $finished !== null ? max(0, (int) $started->diffInSeconds($finished)) : null,
        ];
    }

    /**
     * Why the app's latest finished deploy failed, while that's still the
     * last word on it.
     *
     * @return array<string, mixed>|null
     */
    public static function lastFailure(Machine $machine, string $app): ?array
    {
        $last = DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('service', $app)
            ->whereIn('kind', DaemonJob::LONG_KINDS)
            ->whereIn('state', ['succeeded', ...self::FAILED])
            ->latest()
            ->first();
        if ($last === null || ! in_array($last->state, self::FAILED, true)) {
            return null;
        }
        $error = is_array($last->error) ? $last->error : [];

        return [
            'jobId' => $last->id,
            'kind' => $last->kind,
            'state' => $last->state,
            'phase' => is_string($error['phase'] ?? null) ? $error['phase'] : $last->phase,
            'message' => is_string($error['message'] ?? null) ? $error['message'] : null,
            'nextStep' => is_string($error['next_step'] ?? null) ? $error['next_step'] : null,
            'keyLine' => DeployFailure::keyLine([...($last->log ?? []), ...(is_array($error['output_tail'] ?? null) ? $error['output_tail'] : [])]),
            'explanation' => DeployFailure::explain($last),
            'at' => ($last->finished_at ?? $last->updated_at)?->toIso8601String(),
        ];
    }
}
