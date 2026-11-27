<?php

namespace App\Support\Deploys;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use Carbon\CarbonImmutable;
use Cron\CronExpression;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Commands an app runs on a schedule, in its live container, like cron:
 * `php artisan schedule:run` every minute, a nightly cleanup. They're kept
 * with the app's other switches (machines.app_settings[<app>]['schedules'])
 * and run from the panel's scheduler as service_exec jobs, as the person
 * who saved them. A run that's still going isn't started again.
 */
final class AppSchedules
{
    public const int MAX = 20;

    public const int DEFAULT_TIMEOUT = 300;

    public const string TRIGGER = 'schedule:';

    public function __construct(private readonly DaemonJobQueue $queue) {}

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'schedules' => ['present', 'array', 'max:'.self::MAX],
            'schedules.*' => ['array'],
            'schedules.*.id' => ['nullable', 'string', 'regex:/^[a-z0-9]{6}$/'],
            'schedules.*.name' => ['nullable', 'string', 'max:80'],
            'schedules.*.command' => ['required', 'string', 'max:2000'],
            'schedules.*.cron' => ['required', 'string', 'max:120', function (string $attribute, mixed $value, \Closure $fail): void {
                if (! is_string($value) || ! CronExpression::isValidExpression(self::expression($value))) {
                    $fail('Use five cron fields, like */5 * * * * (every five minutes), or @hourly, @daily, @weekly.');
                }
            }],
            'schedules.*.enabled' => ['sometimes', 'boolean'],
            'schedules.*.timeout' => ['sometimes', 'nullable', 'integer', 'between:10,3600'],
        ];
    }

    /** @return list<array{id: string, name: string|null, command: string, cron: string, enabled: bool, timeout: int, user_id: int|null}> */
    public static function of(Machine $machine, string $app): array
    {
        $schedules = $machine->app_settings[$app]['schedules'] ?? [];

        return array_values(array_filter(array_map(fn ($s) => is_array($s) && is_string($s['id'] ?? null) && is_string($s['command'] ?? null) && is_string($s['cron'] ?? null) ? [
            'id' => $s['id'],
            'name' => is_string($s['name'] ?? null) ? $s['name'] : null,
            'command' => $s['command'],
            'cron' => $s['cron'],
            'enabled' => (bool) ($s['enabled'] ?? true),
            'timeout' => is_int($s['timeout'] ?? null) ? $s['timeout'] : self::DEFAULT_TIMEOUT,
            'user_id' => is_int($s['user_id'] ?? null) ? $s['user_id'] : null,
        ] : null, is_array($schedules) ? $schedules : [])));
    }

    /**
     * Replaces the app's schedules with the list given (an id keeps a
     * schedule's runs together), as the person saving them.
     *
     * @param  list<array<string, mixed>>  $given  validated against rules()
     * @return array{added: int, changed: int, removed: int}
     */
    public static function save(Machine $machine, string $app, array $given, User $user): array
    {
        $before = collect(self::of($machine, $app))->keyBy('id');
        $next = [];
        foreach ($given as $schedule) {
            $id = is_string($schedule['id'] ?? null) && $before->has($schedule['id']) ? $schedule['id'] : strtolower(Str::random(6));
            $next[] = [
                'id' => $id,
                'name' => filled($schedule['name'] ?? null) ? trim((string) $schedule['name']) : null,
                'command' => trim((string) $schedule['command']),
                'cron' => trim((string) $schedule['cron']),
                'enabled' => (bool) ($schedule['enabled'] ?? true),
                'timeout' => isset($schedule['timeout']) ? (int) $schedule['timeout'] : self::DEFAULT_TIMEOUT,
                'user_id' => $user->id,
            ];
        }

        $kept = collect($next)->keyBy('id');
        $counts = [
            'added' => $kept->keys()->diff($before->keys())->count(),
            'changed' => $kept->filter(fn (array $s, string $id) => $before->has($id) && array_diff_key($before[$id], ['user_id' => true]) != array_diff_key($s, ['user_id' => true]))->count(),
            'removed' => $before->keys()->diff($kept->keys())->count(),
        ];

        $settings = $machine->app_settings ?? [];
        $settings[$app] = [...($settings[$app] ?? []), 'schedules' => $next];
        if ($next === []) {
            unset($settings[$app]['schedules']);
        }
        $machine->forceFill(['app_settings' => $settings])->save();

        return $counts;
    }

    /**
     * Whether the app can run scheduled commands: it runs as one container.
     *
     * @param  array<string, mixed>|null  $spec
     */
    public static function unavailable(?array $spec): ?string
    {
        if ($spec === null) {
            return 'Deploy the app first.';
        }
        if (filled($spec['compose_file'] ?? null)) {
            return 'Scheduled commands run in apps that run as one container. In a Compose app, add a service to its Compose file that runs them.';
        }

        return null;
    }

    /** @return list<array<string, mixed>> for the app's page */
    public static function props(Machine $machine, string $app): array
    {
        $schedules = self::of($machine, $app);
        if ($schedules === []) {
            return [];
        }
        $runs = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'service_exec')
            ->whereIn('trigger', array_map(fn (array $s) => self::TRIGGER.$s['id'], $schedules))
            ->latest()->limit(100)->get()->groupBy('trigger');
        $names = User::query()->whereIn('id', array_filter(array_column($schedules, 'user_id')))->pluck('name', 'id');

        return array_map(function (array $s) use ($runs, $names) {
            $last = $runs->get(self::TRIGGER.$s['id'])?->first();

            return [
                'id' => $s['id'],
                'name' => $s['name'],
                'command' => $s['command'],
                'cron' => $s['cron'],
                'enabled' => $s['enabled'],
                'timeout' => $s['timeout'],
                'by' => $s['user_id'] === null ? null : ($names[$s['user_id']] ?? null),
                'nextRunAt' => $s['enabled'] ? self::next($s['cron'])?->toIso8601String() : null,
                'lastRun' => $last === null ? null : [
                    'jobId' => $last->id,
                    'state' => $last->isStale() ? 'stale' : $last->state,
                    'exitCode' => is_int($last->result['exit_code'] ?? null) ? $last->result['exit_code'] : null,
                    'at' => $last->created_at?->toIso8601String(),
                    'finishedAt' => $last->finished_at?->toIso8601String(),
                ],
            ];
        }, $schedules);
    }

    public static function next(string $cron): ?CarbonImmutable
    {
        try {
            return CarbonImmutable::instance((new CronExpression(self::expression($cron)))->getNextRunDate(now()));
        } catch (\Throwable) {
            return null;
        }
    }

    // Laravel's own @-names, as cron has them.
    private static function expression(string $cron): string
    {
        return match (strtolower(trim($cron))) {
            '@yearly', '@annually' => '0 0 1 1 *',
            '@monthly' => '0 0 1 * *',
            '@weekly' => '0 0 * * 0',
            '@daily', '@midnight' => '0 0 * * *',
            '@hourly' => '0 * * * *',
            default => trim($cron),
        };
    }

    /**
     * Starts every schedule that's due this minute. Called every minute by
     * the scheduler; a schedule already started this minute isn't again.
     *
     * @return array{started: int, skipped: int}
     */
    public function runDue(): array
    {
        $started = 0;
        $skipped = 0;
        $now = now();

        Machine::query()->whereNotNull('app_settings')->with('workspace')->each(function (Machine $machine) use ($now, &$started, &$skipped) {
            foreach (array_keys($machine->app_settings ?? []) as $app) {
                foreach (self::of($machine, (string) $app) as $schedule) {
                    if (! $schedule['enabled'] || ! (new CronExpression(self::expression($schedule['cron'])))->isDue($now)) {
                        continue;
                    }
                    $this->run($machine, (string) $app, $schedule, $now) ? $started++ : $skipped++;
                }
            }
        });

        return ['started' => $started, 'skipped' => $skipped];
    }

    /** @param  array{id: string, name: string|null, command: string, cron: string, enabled: bool, timeout: int, user_id: int|null}  $schedule */
    private function run(Machine $machine, string $app, array $schedule, \DateTimeInterface $now): bool
    {
        $trigger = self::TRIGGER.$schedule['id'];
        $recent = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'service_exec')->where('trigger', $trigger)
            ->where('created_at', '>=', now()->subSeconds($schedule['timeout'] + 120))->latest()->get();
        if ($recent->contains(fn (DaemonJob $job) => $job->created_at !== null && $job->created_at->gte(now()->startOfMinute()))) {
            return false;
        }
        if ($recent->contains(fn (DaemonJob $job) => $job->isActive() && ! $job->isStale())) {
            return $this->skip($machine, $app, $schedule, 'the run before it is still going');
        }

        $user = $schedule['user_id'] === null ? null : User::query()->find($schedule['user_id']);
        $workspace = $machine->workspace;
        if ($user === null || ! $workspace->members()->whereKey($user->id)->exists() || ! $user->can('manageMachines', $workspace)) {
            return $this->skip($machine, $app, $schedule, 'whoever saved it can no longer manage machines. Save it again as someone who can');
        }
        if (! $user->hasEnabledTwoFactorAuthentication()) {
            return $this->skip($machine, $app, $schedule, "{$user->name} needs two-factor authentication on for it to run commands as them");
        }
        if (self::unavailable(AppDeploys::spec($machine, $app)) !== null) {
            return $this->skip($machine, $app, $schedule, 'the app runs as a Compose stack');
        }
        $container = AppReleases::find($machine, $app);
        if ($container === null || ! str_starts_with((string) ($container['key'] ?? ''), 'docker:') || ($container['status'] ?? null) !== 'running') {
            return $this->skip($machine, $app, $schedule, 'the app isn’t running');
        }
        if ($this->queue->unavailable($machine, 'service_exec') !== null) {
            return false;
        }

        try {
            $this->queue->queue($machine, $user, 'service_exec', [
                'type' => 'service_exec',
                'service' => (string) $container['key'],
                'command' => $schedule['command'],
                'timeout_secs' => $schedule['timeout'],
            ], (string) $container['key'], $schedule['timeout'] + 60, trigger: $trigger, audit: false);
        } catch (RuntimeException $e) {
            return $this->skip($machine, $app, $schedule, $e->getMessage());
        }

        return true;
    }

    /** @param  array{id: string, name: string|null, command: string, cron: string, enabled: bool, timeout: int, user_id: int|null}  $schedule */
    private function skip(Machine $machine, string $app, array $schedule, string $reason): bool
    {
        // Said at most once an hour, so a schedule every minute doesn't fill the activity log.
        if (Cache::add("app-schedule-skipped:{$machine->id}:{$app}:{$schedule['id']}", true, 3600)) {
            Activity::system($machine->workspace, 'app.schedule_skipped', $app, $machine, [
                'schedule' => $schedule['name'] ?? mb_substr($schedule['command'], 0, 80),
                'reason' => rtrim($reason, '.'),
            ]);
        }

        return false;
    }
}
