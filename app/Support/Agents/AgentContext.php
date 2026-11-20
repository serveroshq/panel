<?php

namespace App\Support\Agents;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Monitor;
use App\Models\Workspace;
use App\Support\Fixes\FixEvidence;
use App\Support\Workflows\ReleaseSpec;

/**
 * What an agent task starts from, when it's started from something that went
 * wrong: a failed deploy, a machine's logs, a monitor that's down. ServerOS
 * already has the evidence, so the agent gets it without anyone pasting logs.
 *
 * A ref is job:<uuid>, logs:<machine>[:<service key>] or monitor:<id>.
 */
final class AgentContext
{
    public const MAX_TEXT = 8000;

    /**
     * @return array{ref: string, kind: string, label: string, text: string, prompt: string, repo: string|null, branch: string|null, machine_id: int|null}|null
     */
    public static function resolve(Workspace $workspace, string $ref): ?array
    {
        $parts = explode(':', $ref, 3);

        return match ($parts[0]) {
            'job' => self::job($workspace, $parts[1] ?? ''),
            'logs' => self::logs($workspace, (int) ($parts[1] ?? 0), $parts[2] ?? null),
            'monitor' => self::monitor($workspace, (int) ($parts[1] ?? 0)),
            default => null,
        };
    }

    /** @return array{ref: string, kind: string, label: string, text: string, prompt: string, repo: string|null, branch: string|null, machine_id: int|null}|null */
    private static function job(Workspace $workspace, string $id): ?array
    {
        $job = DaemonJob::query()->whereKey($id)->whereHas('machine', fn ($q) => $q->where('workspace_id', $workspace->id))->with('machine')->first();
        if ($job === null) {
            return null;
        }
        $spec = is_array($job->command['job'] ?? null) ? $job->command['job'] : [];
        $repo = is_string($spec['repo'] ?? null) ? ReleaseSpec::github($spec['repo']) : null;
        $error = is_array($job->error) ? trim((string) ($job->error['message'] ?? '')) : '';
        $tail = array_slice(array_values(array_filter($job->log ?? [], 'is_string')), -120);
        $what = $job->kind === 'deploy' || $job->kind === 'release' ? 'deploy' : str_replace('_', ' ', $job->kind);
        $service = $job->service ?? 'a service';

        $text = implode("\n", array_filter([
            "The {$what} of {$service} on {$job->machine->name} ended {$job->state}".($error !== '' ? ": {$error}" : '.'),
            isset($spec['commit']) && is_string($spec['commit']) ? 'Commit: '.$spec['commit'] : null,
            $tail !== [] ? "The end of its log:\n".implode("\n", $tail) : null,
        ]));

        return [
            'ref' => "job:{$job->id}",
            'kind' => 'job',
            'label' => ucfirst($what)." of {$service} on {$job->machine->name}, {$job->state}",
            'text' => mb_substr($text, -self::MAX_TEXT),
            'prompt' => $job->state === 'failed' || $job->state === 'timed_out'
                ? "The {$what} of {$service} failed. Work out why from the log. If the cause is in this repository, fix it with the smallest change that makes it pass. If it isn't (a server problem, a missing setting), change nothing and explain what a person should do."
                : "Look into this {$what} of {$service} and explain what happened.",
            'repo' => $repo === null ? null : "{$repo[0]}/{$repo[1]}",
            'branch' => is_string($spec['branch'] ?? null) ? $spec['branch'] : null,
            'machine_id' => $job->machine_id,
        ];
    }

    /** @return array{ref: string, kind: string, label: string, text: string, prompt: string, repo: string|null, branch: string|null, machine_id: int|null}|null */
    private static function logs(Workspace $workspace, int $machineId, ?string $serviceKey): ?array
    {
        /** @var Machine|null $machine */
        $machine = $workspace->machines()->find($machineId);
        if ($machine === null) {
            return null;
        }
        // The Logs page filters by a service's name; links elsewhere use its key.
        $found = filled($serviceKey) ? collect((array) ($machine->inventory['services'] ?? []))
            ->first(fn ($s) => is_array($s) && in_array($serviceKey, [$s['key'] ?? null, $s['name'] ?? null], true)) : null;
        $serviceKey = is_array($found) ? (string) ($found['key'] ?? $serviceKey) : (filled($serviceKey) ? $serviceKey : null);
        $evidence = FixEvidence::gather($machine, $serviceKey);
        $service = is_array($found) ? (string) ($found['name'] ?? $serviceKey) : $serviceKey;
        $repo = self::repoFor($machine, $service);

        return [
            'ref' => 'logs:'.$machine->id.($serviceKey ? ":{$serviceKey}" : ''),
            'kind' => 'logs',
            'label' => $service !== null ? "Recent logs of {$service} on {$machine->name}" : "Recent errors on {$machine->name}",
            'text' => mb_substr(json_encode($evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '', 0, self::MAX_TEXT),
            'prompt' => $service !== null
                ? "{$service} is logging errors. Find the cause in this repository and fix it with the smallest change. If it isn't in the code, change nothing and explain what a person should do."
                : "Look into the errors on {$machine->name}. Explain the most likely cause and what a person should do.",
            'repo' => $repo,
            'branch' => null,
            'machine_id' => $machine->id,
        ];
    }

    /** @return array{ref: string, kind: string, label: string, text: string, prompt: string, repo: string|null, branch: string|null, machine_id: int|null}|null */
    private static function monitor(Workspace $workspace, int $id): ?array
    {
        /** @var Monitor|null $monitor */
        $monitor = $workspace->monitors()->find($id);
        if ($monitor === null) {
            return null;
        }
        $machine = $monitor->machine_id !== null ? $workspace->machines()->find($monitor->machine_id) : null;
        $lines = [
            "The monitor {$monitor->name} is {$monitor->status}".($monitor->last_error ? ": {$monitor->last_error}" : '.'),
            'It checks '.($monitor->url ?? trim(($monitor->host ?? '').':'.($monitor->port ?? ''), ':')).", every {$monitor->interval_seconds}s.",
            $monitor->status_changed_at ? 'Since '.$monitor->status_changed_at->toDayDateTimeString().' UTC, '.$monitor->failures.' failed checks in a row.' : null,
        ];
        if ($machine instanceof Machine) {
            $lines[] = "Recent evidence from {$machine->name}:\n".(json_encode(FixEvidence::gather($machine, null), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
        }

        return [
            'ref' => "monitor:{$monitor->id}",
            'kind' => 'monitor',
            'label' => "{$monitor->name} is {$monitor->status}",
            'text' => mb_substr(implode("\n", array_filter($lines)), 0, self::MAX_TEXT),
            'prompt' => "{$monitor->name} is {$monitor->status}. Find out why. If the cause is in this repository, fix it with the smallest change. If it isn't, change nothing and explain what a person should do.",
            'repo' => $machine instanceof Machine ? self::repoFor($machine, null) : null,
            'branch' => null,
            'machine_id' => $machine?->id,
        ];
    }

    /** The repository the machine's app was last deployed from, when there's one. */
    private static function repoFor(Machine $machine, ?string $service): ?string
    {
        $jobs = DaemonJob::query()->where('machine_id', $machine->id)->whereIn('kind', ['deploy', 'release'])
            ->when($service !== null, fn ($q) => $q->where('service', $service))
            ->where('state', 'succeeded')->latest()->limit(10)->get();
        foreach ($jobs as $job) {
            $repo = is_string($job->command['job']['repo'] ?? null) ? ReleaseSpec::github($job->command['job']['repo']) : null;
            if ($repo !== null) {
                return "{$repo[0]}/{$repo[1]}";
            }
        }

        return null;
    }
}
