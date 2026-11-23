<?php

namespace App\Support\Traffic;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use RuntimeException;

/**
 * Whether a machine's daemon counts visits to its sites from the proxy's
 * access logs. Off by default. The daemon does it (its `traffic` job) and
 * keeps the setting itself; the panel keeps what it asked for, the job that
 * asked, and what came back.
 *
 * Stored on the machine as {enabled, previous, job, sites, existing}:
 * `existing` counts the sites of the machine's own web server. `enabled` is
 * what was asked for, `previous` what it was before, so a change that
 * didn't apply goes back.
 */
final class TrafficSwitch
{
    public const string KIND = 'traffic';

    private const FAILED = ['failed', 'refused', 'timed_out', 'cancelled'];

    public function __construct(private readonly DaemonJobQueue $queue) {}

    /** Whether the machine counts traffic now, as far as the panel knows. */
    public static function on(Machine $machine): bool
    {
        $traffic = $machine->traffic ?? [];
        $job = self::job($machine);
        if ($job !== null && $job->isStale()) {
            return (bool) ($traffic['previous'] ?? false);
        }

        return (bool) ($traffic['enabled'] ?? false);
    }

    /** Why the machine can't count traffic, or null when it can. */
    public static function blocked(Machine $machine): ?string
    {
        if (! DaemonJobQueue::canCollectTraffic($machine)) {
            return self::say('Update the ServerOS daemon on :name to :version or later to count traffic.', ['name' => $machine->name, 'version' => 'v'.config('daemon.traffic_since')]);
        }
        if ($machine->proxy === 'none' && ! ExistingSites::available($machine)) {
            return self::say('No proxy serves sites on :name, so there are no visits to count.', ['name' => $machine->name]);
        }

        return null;
    }

    /**
     * What the machine's settings show.
     *
     * @return array{enabled: bool, blocked: string|null, proxy: string|null, sites: int|null, existing: int|null, changing: string|null, error: string|null}
     */
    public static function props(Machine $machine): array
    {
        $job = self::job($machine);
        $failed = $job !== null && (in_array($job->state, self::FAILED, true) || $job->isStale());

        return [
            'enabled' => self::on($machine),
            'blocked' => self::blocked($machine),
            'proxy' => Machine::PROXIES[$machine->proxy ?? ''] ?? null,
            'sites' => is_int($machine->traffic['sites'] ?? null) ? $machine->traffic['sites'] : null,
            // The sites of the machine's own web server it counts too, when its daemon does.
            'existing' => ExistingSites::available($machine) ? (is_int($machine->traffic['existing'] ?? null) ? $machine->traffic['existing'] : count(ExistingSites::all($machine))) : null,
            'changing' => $job !== null && $job->isActive() && ! $job->isStale() ? $job->id : null,
            'error' => $failed ? (is_array($job->error) && is_string($job->error['message'] ?? null) && $job->error['message'] !== ''
                ? $job->error['message']
                : self::say('The machine didn’t answer. Try again once it’s online.')) : null,
        ];
    }

    /**
     * Turns counting on or off on the machine. Returns the queued job.
     *
     * @throws RuntimeException when the machine can't take it now
     */
    public function set(Machine $machine, User $user, bool $enabled): DaemonJob
    {
        if (($reason = self::blocked($machine)) !== null) {
            throw new RuntimeException($reason);
        }
        if (($reason = $this->queue->unavailable($machine)) !== null) {
            throw new RuntimeException($reason);
        }
        $current = self::job($machine);
        if ($current !== null && $current->isActive() && ! $current->isStale()) {
            throw new RuntimeException(self::say('The last change is still being applied. Try again in a moment.'));
        }

        $previous = self::on($machine);
        $record = $this->queue->queue($machine, $user, self::KIND, ['type' => self::KIND, 'enabled' => $enabled], null, 120);
        $machine->forceFill(['traffic' => [
            'enabled' => $enabled,
            'previous' => $previous,
            'job' => $record->id,
            'sites' => $machine->traffic['sites'] ?? null,
            'existing' => $machine->traffic['existing'] ?? null,
        ]])->save();

        Activity::record($machine->workspace_id, 'machine.traffic', $machine->name, $machine, $user, ['enabled' => $enabled]);

        return $record;
    }

    /**
     * What the machine keeps once its traffic job has finished, or null when
     * the job isn't the one that last changed it.
     *
     * @param  array<string, mixed>|null  $result
     * @return array{enabled: bool, previous: bool, job: string, sites: int|null, existing: int|null}|null
     */
    public static function finished(Machine $machine, DaemonJob $job, string $state, ?array $result): ?array
    {
        $traffic = $machine->traffic ?? [];
        if (($traffic['job'] ?? null) !== $job->id) {
            return null;
        }
        $sites = is_int($traffic['sites'] ?? null) ? $traffic['sites'] : null;
        $existing = is_int($traffic['existing'] ?? null) ? $traffic['existing'] : null;

        if ($state === 'succeeded') {
            $enabled = is_bool($result['enabled'] ?? null) ? $result['enabled'] : (bool) ($traffic['enabled'] ?? false);

            return [
                'enabled' => $enabled,
                'previous' => $enabled,
                'job' => $job->id,
                'sites' => is_int($result['sites'] ?? null) ? max(0, $result['sites']) : $sites,
                // Daemons that count the machine's own sites say how many.
                'existing' => is_int($result['existing'] ?? null) ? max(0, $result['existing']) : $existing,
            ];
        }

        $previous = (bool) ($traffic['previous'] ?? false);

        return ['enabled' => $previous, 'previous' => $previous, 'job' => $job->id, 'sites' => $sites, 'existing' => $existing];
    }

    /** @param  array<string, string>  $replace */
    private static function say(string $text, array $replace = []): string
    {
        $said = __($text, $replace);

        return is_string($said) ? $said : $text;
    }

    private static function job(Machine $machine): ?DaemonJob
    {
        $id = $machine->traffic['job'] ?? null;

        return is_string($id) ? DaemonJob::query()->where('machine_id', $machine->id)->find($id) : null;
    }
}
