<?php

namespace App\Support\Deploys;

use App\Http\Controllers\AppVersionController;
use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * CPU and memory limits for an app's container, set on the app's page and
 * applied with each deploy (docker run --cpus and --memory). Only a daemon
 * that reports back the limits it ran the container with counts as having
 * applied them: an older one ignores them, and the page says so.
 */
final class AppLimits
{
    public const float MIN_CPUS = 0.01;

    public const int MIN_MEMORY_MB = 32;

    /** @return array{cpus: float|null, memoryMb: int|null}|null */
    public static function setting(Machine $machine, string $app): ?array
    {
        $limits = $machine->app_settings[$app]['limits'] ?? null;
        if (! is_array($limits)) {
            return null;
        }
        $cpus = is_numeric($limits['cpus'] ?? null) ? (float) $limits['cpus'] : null;
        $memory = is_numeric($limits['memory_mb'] ?? null) ? (int) $limits['memory_mb'] : null;

        return $cpus === null && $memory === null ? null : ['cpus' => $cpus, 'memoryMb' => $memory];
    }

    /** What the live container runs with, as its daemon reported it. */
    /** @return array{cpus: float|null, memoryMb: int|null}|null */
    public static function applied(?DaemonJob $deploy): ?array
    {
        $limits = $deploy?->result['limits'] ?? null;
        if (! is_array($limits)) {
            return null;
        }
        $cpus = is_numeric($limits['cpu_millis'] ?? null) ? round(((int) $limits['cpu_millis']) / 1000, 3) : null;
        $memory = is_numeric($limits['memory_mb'] ?? null) ? (int) $limits['memory_mb'] : null;

        return $cpus === null && $memory === null ? null : ['cpus' => $cpus, 'memoryMb' => $memory];
    }

    /** The most a machine has, when its daemon has said. */
    /** @return array{cpus: int|null, memoryMb: int|null} */
    public static function machineMax(Machine $machine): array
    {
        $cores = (int) ($machine->facts['cpu_cores'] ?? 0);
        $bytes = (int) ($machine->facts['memory_bytes'] ?? 0);

        return ['cpus' => $cores > 0 ? $cores : null, 'memoryMb' => $bytes > 0 ? intdiv($bytes, 1024 * 1024) : null];
    }

    // Why limits can't be set for this app, or null when they can.
    public static function unavailable(Machine $machine, ?DaemonJob $live): ?string
    {
        if (filled($live?->command['job']['compose_file'] ?? null)) {
            return 'Limits work for apps that run as one container. This app runs from a Compose file, so set them there (cpus and mem_limit).';
        }
        if (! DaemonJobQueue::canLimitResources($machine)) {
            return $machine->name.'’s daemon is too old to limit an app’s CPU and memory. It updates itself, or run “sudo serverosd update” on the server.';
        }

        return null;
    }

    /**
     * The job with the app's limits on it, when its daemon can apply them,
     * and without any otherwise: a redeploy copies the job before it, so
     * limits taken off since are taken off here too.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    public static function forJob(Machine $machine, array $job): array
    {
        unset($job['limits']);
        $setting = self::setting($machine, (string) ($job['service'] ?? ''));
        if ($setting === null || filled($job['compose_file'] ?? null) || ! DaemonJobQueue::canLimitResources($machine)) {
            return $job;
        }

        return [...$job, 'limits' => array_filter([
            'cpu_millis' => $setting['cpus'] === null ? null : (int) round($setting['cpus'] * 1000),
            'memory_mb' => $setting['memoryMb'],
        ], fn ($value) => $value !== null)];
    }

    /**
     * Check limits against the machine: at least a hundredth of a CPU and
     * 32 MB, at most what the machine has when its daemon has said.
     *
     * @param  array<string, mixed>  $input  cpus and memory_mb, either null for no limit
     * @return array{cpus: float|null, memory_mb: int|null}
     *
     * @throws ValidationException
     */
    public static function validate(Machine $machine, array $input): array
    {
        $max = self::machineMax($machine);
        $data = Validator::make($input, [
            'cpus' => ['present', 'nullable', 'numeric', 'min:'.self::MIN_CPUS, 'max:'.($max['cpus'] ?? 1024)],
            'memory_mb' => ['present', 'nullable', 'integer', 'min:'.self::MIN_MEMORY_MB, 'max:'.($max['memoryMb'] ?? 4 * 1024 * 1024)],
        ], [
            'cpus.min' => 'Give it at least '.self::MIN_CPUS.' of a CPU.',
            'cpus.max' => $max['cpus'] === null ? 'That’s more CPUs than a machine has.' : $machine->name.' has '.$max['cpus'].' '.($max['cpus'] === 1 ? 'CPU' : 'CPUs').', so the limit can’t be more.',
            'memory_mb.min' => 'Give it at least '.self::MIN_MEMORY_MB.' MB of memory.',
            'memory_mb.max' => $max['memoryMb'] === null ? 'That’s more memory than a machine has.' : $machine->name.' has '.$max['memoryMb'].' MB of memory, so the limit can’t be more.',
        ])->validate();

        return [
            'cpus' => $data['cpus'] === null ? null : round((float) $data['cpus'], 3),
            'memory_mb' => $data['memory_mb'] === null ? null : (int) $data['memory_mb'],
        ];
    }

    /** @param  array{cpus: float|null, memory_mb: int|null}  $limits */
    public static function store(Machine $machine, string $app, array $limits): void
    {
        $settings = $machine->app_settings ?? [];
        $settings[$app] = $settings[$app] ?? [];
        if ($limits['cpus'] === null && $limits['memory_mb'] === null) {
            unset($settings[$app]['limits']);
        } else {
            $settings[$app]['limits'] = $limits;
        }
        $machine->forceFill(['app_settings' => $settings])->save();
    }

    /**
     * Save the app's limits and deploy its live version again with them,
     * like changing its settings does. Nothing is saved if it can't deploy.
     *
     * @param  array<string, mixed>  $input
     * @return array{limits: array{cpus: float|null, memory_mb: int|null}, job: DaemonJob|null}
     *
     * @throws ValidationException
     */
    public function change(Machine $machine, string $app, array $input, User $user, DaemonJobQueue $queue): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['limits' => $message]);
        $live = AppVersionController::lastDeployOf($machine, $app);
        if ($live === null || ! $live->isRetryable()) {
            $fail("{$app} has no deploy to change yet.");
        }
        if (($reason = self::unavailable($machine, $live)) !== null) {
            $fail($reason);
        }
        $limits = self::validate($machine, $input);
        $before = self::setting($machine, $app);
        if (($before['cpus'] ?? null) === $limits['cpus'] && ($before['memoryMb'] ?? null) === $limits['memory_mb']) {
            return ['limits' => $limits, 'job' => null];
        }

        $repo = (string) ($live->command['job']['repo'] ?? '');
        if (str_contains($repo, '/api/deploy-sources/') && ! (DeploySource::fromRepoUrl($repo)?->usable() ?? false)) {
            $fail("The code uploaded for {$app}’s live version has been cleared. Upload it again first.");
        }
        if (($reason = $queue->unavailable($machine, 'deploy')) !== null) {
            $fail($reason);
        }

        $old = $machine->app_settings;
        self::store($machine, $app, $limits);
        try {
            $job = $queue->deploy($machine, $user, $live->command['job']);
        } catch (RuntimeException $e) {
            $machine->forceFill(['app_settings' => $old])->save();
            $fail($e->getMessage());
        }

        return ['limits' => $limits, 'job' => $job];
    }
}
