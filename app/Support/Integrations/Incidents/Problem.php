<?php

namespace App\Support\Integrations\Incidents;

use App\Models\Machine;
use App\Models\MachineVulnerability;
use App\Models\Monitor;
use App\Models\MonitorIncident;
use App\Models\Vulnerability;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Something wrong that an incident tool can be told about. The key stays the
 * same for as long as the same thing is wrong.
 */
final readonly class Problem
{
    public const string MONITOR = 'monitor';

    public const string MACHINE = 'machine';

    public const string CVE = 'cve';

    /** @param  list<string>  $lines */
    public function __construct(
        public Workspace $workspace,
        public string $key,
        public string $kind,
        public string $title,
        public array $lines,
        public string $url,
        public CarbonImmutable $since,
        public string $source,
        public bool $critical = true,
        public bool $exploited = false,
        public ?int $monitorId = null,
        public ?int $monitorIncidentId = null,
        public ?int $machineId = null,
    ) {}

    public static function monitor(Monitor $monitor, MonitorIncident $incident): self
    {
        return new self(
            workspace: $monitor->workspace,
            key: 'monitor:'.$monitor->id,
            kind: self::MONITOR,
            title: "{$monitor->name} is down",
            lines: array_values(array_filter([$monitor->target(), $incident->cause])),
            url: $monitor->workspace->url('/monitors/'.$monitor->id),
            since: $incident->started_at,
            source: $monitor->target(),
            monitorId: $monitor->id,
            monitorIncidentId: $incident->id,
            machineId: $monitor->machine_id,
        );
    }

    public static function machine(Machine $machine): self
    {
        $since = CarbonImmutable::createFromTimestamp((int) ($machine->alert_state['offline'] ?? $machine->last_seen_at?->getTimestamp() ?? now()->getTimestamp()));

        return new self(
            workspace: $machine->workspace,
            key: 'machine:'.$machine->id,
            kind: self::MACHINE,
            title: "{$machine->name} stopped reporting",
            lines: [
                'Nothing from it since '.$since->toDayDateTimeString().' UTC.',
                'It may be down or unreachable, or the ServerOS daemon stopped.',
            ],
            url: $machine->workspace->url('/machines/'.$machine->id),
            since: $since,
            source: $machine->name,
            machineId: $machine->id,
        );
    }

    /** @param  Collection<int, MachineVulnerability>  $findings  open findings of one vulnerability in one workspace */
    public static function vulnerability(Workspace $workspace, Vulnerability $vulnerability, Collection $findings): self
    {
        $machines = $findings->map(fn (MachineVulnerability $f) => $f->machine->name)->unique()->sort()->values();
        $on = match (true) {
            $machines->count() === 1 => (string) $machines->first(),
            $machines->count() <= 3 => $machines->slice(0, -1)->implode(', ').' and '.$machines->last(),
            default => $machines->first().' and '.($machines->count() - 1).' more machines',
        };
        $fix = $findings->map(fn (MachineVulnerability $f) => $f->fixed_by === null ? null : "{$f->source} {$f->fixed_by}")->filter()->unique()->values();

        return new self(
            workspace: $workspace,
            key: 'cve:'.$vulnerability->cve,
            kind: self::CVE,
            title: "{$vulnerability->cve} on {$on}",
            lines: array_values(array_filter([
                $vulnerability->title,
                $vulnerability->severityLabel().($vulnerability->exploited ? ', and being exploited' : '').'.',
                'Affects '.$findings->map(fn (MachineVulnerability $f) => $f->source)->unique()->implode(', ').' on '.$machines->implode(', ').'.',
                $fix->isEmpty() ? 'No fixed version is out yet.' : 'Fixed by '.$fix->implode(', ').'.',
                $vulnerability->url(),
            ])),
            url: $workspace->url('/updates'),
            since: $findings->min('detected_at') ?? CarbonImmutable::now(),
            source: $machines->implode(', '),
            critical: $vulnerability->severity === 'critical',
            exploited: $vulnerability->exploited,
        );
    }
}
