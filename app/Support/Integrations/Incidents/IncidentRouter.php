<?php

namespace App\Support\Integrations\Incidents;

use App\Enums\MachineEnvironment;
use App\Models\Activity;
use App\Models\Integration;
use App\Models\IntegrationIncident;
use App\Models\Machine;
use App\Models\MachineVulnerability;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\Workspace;
use App\Support\Integrations\IntegrationProviders;
use App\Support\Monitors\MonitorAlerts;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Str;
use Throwable;

/**
 * Tells the workspace's incident tools (PagerDuty, Linear) when something
 * goes wrong and when it's fine again. The watchers call it as things
 * change; sweep() runs every minute to open what waits for a delay, close
 * what recovered while nobody was looking, and try failed sends again.
 */
final class IncidentRouter
{
    private const int OFFLINE_FOR_AT_MOST = 86400;

    public function __construct(private readonly IntegrationProviders $providers) {}

    public function monitorDown(Monitor $monitor, MonitorIncident $incident): void
    {
        $this->safely(function () use ($monitor, $incident): void {
            if (! self::quietMonitor($monitor)) {
                $this->raise(Problem::monitor($monitor, $incident));
            }
        });
    }

    public function monitorUp(Monitor $monitor, ?MonitorIncident $incident): void
    {
        $this->safely(fn () => $this->clear($monitor->workspace_id, 'monitor:'.$monitor->id, $incident !== null
            ? 'Back up after '.MonitorAlerts::duration((int) $incident->started_at->diffInSeconds($incident->resolved_at ?? now())).' down.'
            : 'Back up.'));
    }

    public function machineOffline(Machine $machine): void
    {
        $this->safely(function () use ($machine): void {
            if (! self::quietMachine($machine)) {
                $this->raise(Problem::machine($machine));
            }
        });
    }

    public function machineOnline(Machine $machine, int $seconds): void
    {
        $this->safely(fn () => $this->clear($machine->workspace_id, 'machine:'.$machine->id, 'Reporting again after '.MonitorAlerts::duration($seconds).'.'));
    }

    public function vulnerabilities(Workspace $workspace): void
    {
        $this->safely(function () use ($workspace): void {
            if ($this->accounts($workspace->id) !== []) {
                foreach ($this->vulnerabilityProblems($workspace) as $problem) {
                    $this->raise($problem);
                }
            }
        });
    }

    /** Every workspace with an incident tool: open what's due, close what recovered, send what's waiting. */
    public function sweep(): void
    {
        $workspaces = Integration::query()
            ->whereIn('provider', array_keys($this->routing()))
            ->where('status', Integration::CONNECTED)
            ->distinct()
            ->pluck('workspace_id');

        foreach ($workspaces as $id) {
            $workspace = Workspace::query()->find($id);
            if ($workspace instanceof Workspace) {
                $this->safely(fn () => $this->reconcile($workspace));
            }
        }

        app(IntegrationEvents::class)->retryDue();
    }

    public function reconcile(Workspace $workspace): void
    {
        $ongoing = [];

        $monitors = $workspace->monitors()->where('status', 'down')->with(['openIncident', 'machine', 'workspace'])->get();
        foreach ($monitors as $monitor) {
            if ($monitor->openIncident instanceof MonitorIncident) {
                $ongoing['monitor:'.$monitor->id] = true;
                if (! self::quietMonitor($monitor)) {
                    $this->raise(Problem::monitor($monitor, $monitor->openIncident));
                }
            }
        }

        $machines = $workspace->machines()->whereNotNull('alert_state')->with('workspace')->get();
        foreach ($machines as $machine) {
            if (isset($machine->alert_state['offline']) && ! $machine->isOnline()) {
                $ongoing['machine:'.$machine->id] = true;
                if (! self::quietMachine($machine) && now()->getTimestamp() - (int) $machine->alert_state['offline'] < self::OFFLINE_FOR_AT_MOST) {
                    $this->raise(Problem::machine($machine));
                }
            }
        }

        foreach ($this->vulnerabilityProblems($workspace) as $problem) {
            $ongoing[$problem->key] = true;
            $this->raise($problem);
        }

        $open = IntegrationIncident::query()->whereNotNull('open_key')
            ->whereHas('integration', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->get();
        foreach ($open as $incident) {
            $key = (string) $incident->open_key;
            if (isset($ongoing[$key]) || ($incident->kind === Problem::CVE && $this->stillVulnerable($workspace, $key))) {
                continue;
            }
            $this->clear($workspace->id, $key, match ($incident->kind) {
                Problem::CVE => 'Fixed: no machine has it any more.',
                Problem::MACHINE => 'Reporting again.',
                default => 'Back up.',
            });
        }
    }

    /** Opens the problem with every account that wants it and hasn't got it open. Returns how many. */
    public function raise(Problem $problem): int
    {
        $opened = 0;
        foreach ($this->accounts($problem->workspace->id) as [$integration, $provider]) {
            if (! $provider->wants($integration, $problem)) {
                continue;
            }
            $open = $integration->incidents()->where('open_key', $problem->key)->first();
            if ($open instanceof IntegrationIncident) {
                if ($problem->kind === Problem::CVE && $open->title !== $problem->title) {
                    $open->update(['title' => mb_substr($problem->title, 0, 200)]);
                    IntegrationEvents::queue($integration, 'update', $problem->title, self::payload($problem), $open);
                }

                continue;
            }

            try {
                $incident = $integration->incidents()->create([
                    'key' => $problem->key,
                    'open_key' => $problem->key,
                    'kind' => $problem->kind,
                    'monitor_incident_id' => $problem->monitorIncidentId,
                    'machine_id' => $problem->machineId,
                    'title' => mb_substr($problem->title, 0, 200),
                    'opened_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                continue;
            }
            IntegrationEvents::queue($integration, 'open', $problem->title, self::payload($problem), $incident);
            Activity::system($integration->workspace_id, 'integration.incident_open', $problem->title, null, [
                'provider' => $integration->provider()->name(),
                'account' => $integration->label,
            ]);
            $opened++;
        }

        return $opened;
    }

    /** Closes the problem everywhere it's open. */
    public function clear(int $workspaceId, string $key, string $note): int
    {
        $open = IntegrationIncident::query()->where('open_key', $key)
            ->whereHas('integration', fn ($q) => $q->where('workspace_id', $workspaceId))
            ->with('integration')
            ->get();

        foreach ($open as $incident) {
            $incident->update(['open_key' => null, 'resolved_at' => now()]);
            IntegrationEvents::queue($incident->integration, 'resolve', $incident->title, ['note' => $note], $incident);
            Activity::system($workspaceId, 'integration.incident_resolve', $incident->title, null, [
                'provider' => $incident->integration->provider()->name(),
                'account' => $incident->integration->label,
            ]);
        }

        return $open->count();
    }

    /** @return array<string, mixed> */
    public static function payload(Problem $problem): array
    {
        return [
            'id' => (string) Str::uuid(),
            'dedup' => 'serveros:'.$problem->workspace->id.':'.$problem->key,
            'kind' => $problem->kind,
            'title' => $problem->title,
            'lines' => $problem->lines,
            'url' => $problem->url,
            'source' => $problem->source,
            'workspace' => $problem->workspace->name,
            'since' => $problem->since->toIso8601String(),
            'critical' => $problem->critical,
            'exploited' => $problem->exploited,
        ];
    }

    private static function quietMonitor(Monitor $monitor): bool
    {
        return ! $monitor->alerts || $monitor->paused || $monitor->muted() || ($monitor->machine?->inMaintenance() ?? false);
    }

    private static function quietMachine(Machine $machine): bool
    {
        return $machine->environment === MachineEnvironment::Development || $machine->inMaintenance();
    }

    /** @return list<Problem> the critical and exploited vulnerabilities still open on the workspace's machines */
    private function vulnerabilityProblems(Workspace $workspace): array
    {
        $findings = MachineVulnerability::query()->open()
            ->whereHas('machine', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->whereHas('vulnerability', fn ($q) => $q->where(fn ($q) => $q->where('severity', 'critical')->orWhere('exploited', true)))
            ->with(['vulnerability', 'machine:id,name,workspace_id'])
            ->get()
            ->groupBy('vulnerability_id');

        $problems = [];
        foreach ($findings as $group) {
            $problems[] = Problem::vulnerability($workspace, $group->first()->vulnerability, $group);
        }

        return $problems;
    }

    private function stillVulnerable(Workspace $workspace, string $key): bool
    {
        return MachineVulnerability::query()->open()
            ->whereHas('machine', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->whereHas('vulnerability', fn ($q) => $q->where('cve', Str::after($key, 'cve:')))
            ->exists();
    }

    /** @return array<string, RoutesIncidents> */
    private function routing(): array
    {
        return array_filter($this->providers->all(), fn ($provider) => $provider instanceof RoutesIncidents);
    }

    /** @return list<array{0: Integration, 1: RoutesIncidents}> */
    private function accounts(int $workspaceId): array
    {
        $routing = $this->routing();
        if ($routing === []) {
            return [];
        }

        return array_values(Integration::query()
            ->where('workspace_id', $workspaceId)
            ->whereIn('provider', array_keys($routing))
            ->where('status', Integration::CONNECTED)
            ->orderBy('id')
            ->get()
            ->map(fn (Integration $integration) => [$integration, $routing[$integration->provider]])
            ->all());
    }

    /** @param  Closure(): mixed  $work */
    private function safely(Closure $work): void
    {
        try {
            $work();
        } catch (Throwable $e) {
            report($e);
        }
    }
}
