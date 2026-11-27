<?php

namespace App\Http\Controllers;

use App\Http\Resources\MachineResource;
use App\Models\Activity;
use App\Models\AgentFix;
use App\Models\Backup;
use App\Models\BackupDestination;
use App\Models\IntegrationIncident;
use App\Models\Machine;
use App\Models\MachinePackageUpdate;
use App\Models\MachineVulnerability;
use App\Models\Monitor;
use App\Models\Vulnerability;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Deploys\ServiceLabels;
use App\Support\Edition;
use App\Support\Extensions\Extensions;
use App\Support\Health\WorkspaceHealth;
use App\Support\Licensing\SupportAsk;
use App\Support\Updates\PanelUpdates;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as BaseCollection;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

/**
 * @phpstan-type HomeIssue array{id: string, severity: string, title: string, detail: string, brief: string, href: string, action: string, since: string|null, machineId: int|null, ask: string|null, dismissible: bool, linked?: array{key: string, url: string}|null}
 * @phpstan-type Security array{package: string|null, severity: string|null, cves: int, exploited: int, machines: list<array{id: int, name: string}>, affected: int, critical: int, high: int, updates: int, checkedAt: string|null}
 */
class WorkspaceHomeController extends Controller
{
    private const int BUSIEST = 5;

    private const int GRAPH_MACHINES = 3;

    private const int GRAPH_SOURCES = 2;

    private const int GRAPH_DESTINATIONS = 3;

    private const int BACKUP_LOOKBACK_DAYS = 14;

    public function __invoke(Request $request, Workspace $workspace, WorkspaceHealth $health): Response|RedirectResponse
    {
        if ($workspace->onboarded_at === null && $workspace->owner_id === $request->user()->id) {
            return to_route('workspace.welcome.show', ['workspace' => $workspace->slug]);
        }

        // ServerOS's agent machines live on the Agents side.
        $machines = $workspace->countedMachines()->orderBy('name')->get();

        $security = null;
        $securityOf = function () use (&$security, $machines): array {
            return $security ??= $this->security($machines);
        };
        $issues = null;
        $issuesOf = function () use (&$issues, $workspace, $machines, $health, $securityOf): array {
            return $issues ??= $this->issues($workspace, $machines, $health, $securityOf());
        };

        return Inertia::render('workspace/Home', [
            'workspace' => $workspace->toPageProps(),
            'overview' => fn () => $this->overview($workspace, $machines, $issuesOf()),
            'issues' => $issuesOf,
            'busiest' => fn () => $this->busiest($machines),
            'security' => $securityOf,
            'backups' => fn () => $this->backups($machines),
            'panelUpdate' => Edition::installAdmin($request->user(), $workspace) ? PanelUpdates::notice() : null,
            'supportAsk' => SupportAsk::for($workspace, $request->user(), $machines->count()),
            'integrationsPromo' => IntegrationController::showsPromo($request->user(), $workspace),
            ...app(Extensions::class)->pagePropsFor('workspace/Home', $request, $workspace),
        ]);
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @param  list<HomeIssue>  $issues
     * @return array{machines: int, online: int, offline: list<string>, connecting: int, services: int, running: int, serviceProblems: int, monitors: int, monitorsDown: int, uptime: float|null, averageCpu: int|null, reporting: int}
     */
    private function overview(Workspace $workspace, Collection $machines, array $issues): array
    {
        $connections = $machines->mapWithKeys(fn (Machine $machine) => [$machine->id => MachineResource::connection($machine)]);
        $offline = $machines->filter(fn (Machine $machine) => $connections[$machine->id] === 'offline');
        $reporting = $machines->filter(fn (Machine $machine) => $machine->cpu_percent !== null && $machine->isReporting());
        $monitors = $workspace->monitors()->get(['id', 'status', 'paused', 'daily']);
        $watched = $monitors->where('paused', false);

        return [
            'machines' => $machines->count(),
            'online' => $connections->filter(fn (string $connection) => in_array($connection, ['online', 'degraded', 'demo'], true))->count(),
            'offline' => array_values($offline->map(fn (Machine $machine) => $machine->name)->all()),
            'connecting' => $connections->filter(fn (string $connection) => $connection === 'connecting')->count(),
            'services' => (int) $machines->sum('services_count'),
            'running' => (int) $machines->sum(fn (Machine $machine) => count(array_filter(
                is_array($machine->inventory['services'] ?? null) ? $machine->inventory['services'] : [],
                fn ($service) => is_array($service) && ($service['status'] ?? null) === 'running' && ! ServiceLabels::isHidden($machine, $service),
            ))),
            'serviceProblems' => count(array_filter($issues, fn (array $issue) => preg_match('/^service-\d+-.+-(loop|down|repair)$/', $issue['id']) === 1)),
            'monitors' => $monitors->count(),
            'monitorsDown' => $watched->where('status', 'down')->count(),
            'uptime' => $this->uptime($watched),
            'averageCpu' => $reporting->isEmpty() ? null : (int) round((float) $reporting->avg('cpu_percent')),
            'reporting' => $reporting->count(),
        ];
    }

    /**
     * @param  BaseCollection<int, Monitor>  $monitors
     */
    private function uptime(BaseCollection $monitors): ?float
    {
        $cutoff = now()->subDays(29)->toDateString();
        $checks = $ok = 0;
        foreach ($monitors as $monitor) {
            foreach ($monitor->daily ?? [] as $day => [$c, $o]) {
                if ($day >= $cutoff) {
                    $checks += $c;
                    $ok += $o;
                }
            }
        }

        return $checks === 0 ? null : round($ok / $checks * 100, 3);
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @return list<array{id: int, name: string, cpu: int}>
     */
    private function busiest(Collection $machines): array
    {
        return array_values($machines
            ->filter(fn (Machine $machine) => $machine->cpu_percent !== null && $machine->isReporting())
            ->sortBy([['cpu_percent', 'desc'], ['name', 'asc']])
            ->take(self::BUSIEST)
            ->map(fn (Machine $machine) => ['id' => $machine->id, 'name' => $machine->name, 'cpu' => (int) round((float) $machine->cpu_percent)])
            ->all());
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @return Security
     */
    private function security(Collection $machines): array
    {
        $ids = $machines->modelKeys();
        $findings = MachineVulnerability::query()->whereIn('machine_id', $ids)->whereNull('resolved_at')->with('vulnerability')->get();
        $updates = MachinePackageUpdate::query()->whereIn('machine_id', $ids)->where('security', true)->get(['machine_id', 'name']);
        $vulnerabilities = $findings->pluck('vulnerability')->unique('id');
        $order = array_flip(Vulnerability::SEVERITIES);

        $package = $severity = null;
        $cves = $exploited = 0;
        $affected = collect();
        if ($findings->isNotEmpty()) {
            $worst = $findings->groupBy('source')
                ->map(function (BaseCollection $group, string $source) use ($order) {
                    /** @var BaseCollection<int, Vulnerability> $found */
                    $found = $group->pluck('vulnerability')->unique('id');
                    $level = (int) $found->min(fn (Vulnerability $v) => $order[$v->severity] ?? 9);

                    return [
                        'package' => $source,
                        'rank' => (int) $found->min(fn (Vulnerability $v) => $v->rank()),
                        'severity' => Vulnerability::SEVERITIES[$level] ?? 'unknown',
                        'cves' => $found->filter(fn (Vulnerability $v) => ($order[$v->severity] ?? 9) === $level)->count(),
                        'exploited' => $found->where('exploited', true)->count(),
                        'machines' => $group->pluck('machine_id')->unique()->sort()->values(),
                    ];
                })
                ->sortBy(fn (array $p) => [$p['rank'], -$p['cves'], -$p['machines']->count(), $p['package']])
                ->first();
            ['package' => $package, 'severity' => $severity, 'cves' => $cves, 'exploited' => $exploited, 'machines' => $affected] = $worst;
        } elseif ($updates->isNotEmpty()) {
            $most = $updates->groupBy('name')
                ->sortBy(fn (BaseCollection $rows, string $name) => [-$rows->pluck('machine_id')->unique()->count(), $name])
                ->keys()->first();
            $package = (string) $most;
            $affected = $updates->where('name', $package)->pluck('machine_id')->unique()->sort()->values();
        }

        $shown = $package === null
            ? $machines->take(self::GRAPH_MACHINES)
            : $affected->take(self::GRAPH_MACHINES)->map(fn (int $id) => $machines->find($id))->filter();

        return [
            'package' => $package,
            'severity' => $severity,
            'cves' => $cves,
            'exploited' => $exploited,
            'machines' => array_values($shown->map(fn (Machine $machine) => ['id' => $machine->id, 'name' => $machine->name])->all()),
            'affected' => $affected->count(),
            'critical' => $vulnerabilities->where('severity', 'critical')->count(),
            'high' => $vulnerabilities->where('severity', 'high')->count(),
            'updates' => $updates->count(),
            'checkedAt' => $machines->max('updates_checked_at')?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @return array{machines: int, covered: int, failed: int, schedule: string|null, lastRunAt: string|null, sources: list<array{id: int, name: string, database: bool, failed: bool}>, destinations: list<array{id: int, name: string, logo: string|null}>}
     */
    private function backups(Collection $machines): array
    {
        $ids = $machines->modelKeys();
        $covered = $machines->filter(fn (Machine $machine) => $machine->backup_destination_id !== null && $machine->backup_schedule !== 'off');
        $failed = Backup::query()->whereIn('machine_id', $ids)
            ->whereIn('state', ['succeeded', 'failed'])
            ->where('created_at', '>=', now()->subDays(self::BACKUP_LOOKBACK_DAYS))
            ->orderByDesc('id')->get(['id', 'machine_id', 'state'])
            ->unique('machine_id')->where('state', 'failed')->pluck('machine_id');
        $runs = Backup::query()->whereIn('machine_id', $ids)->whereNotIn('trigger', ['pre-restore', 'pre-deploy']);
        $lastRun = (clone $runs)->where('trigger', 'scheduled')->latest()->value('created_at') ?? $runs->latest()->value('created_at');
        $used = $covered->countBy('backup_destination_id');

        return [
            'machines' => $machines->count(),
            'covered' => $covered->count(),
            'failed' => $failed->count(),
            'schedule' => collect(['daily', 'weekly'])
                ->filter(fn (string $schedule) => $covered->contains('backup_schedule', $schedule))
                ->sortByDesc(fn (string $schedule) => $covered->where('backup_schedule', $schedule)->count())
                ->first(),
            'lastRunAt' => $lastRun === null ? null : CarbonImmutable::parse($lastRun)->toIso8601String(),
            'sources' => array_values($covered
                ->sortBy(fn (Machine $machine) => [$failed->contains($machine->id) ? 0 : 1, $machine->name])
                ->take(self::GRAPH_SOURCES)
                ->map(fn (Machine $machine) => [
                    'id' => $machine->id,
                    'name' => $machine->name,
                    'database' => self::onlyDatabases($machine),
                    'failed' => $failed->contains($machine->id),
                ])->all()),
            'destinations' => array_values(BackupDestination::query()->whereIn('id', $used->keys())->with('machine')->get()
                ->sortBy(fn (BackupDestination $destination) => [-$used[$destination->id], $destination->name])
                ->take(self::GRAPH_DESTINATIONS)
                ->map(fn (BackupDestination $destination) => [
                    'id' => $destination->id,
                    'name' => $destination->name,
                    'logo' => self::destinationLogo($destination),
                ])->all()),
        ];
    }

    private static function onlyDatabases(Machine $machine): bool
    {
        $services = $machine->backupableServices();

        return $services !== [] && collect($services)->every(fn (array $service) => ($service['kind'] ?? null) === 'database');
    }

    private static function destinationLogo(BackupDestination $destination): ?string
    {
        if ($destination->type !== 's3') {
            return $destination->machine?->provider;
        }
        $host = (string) (parse_url((string) ($destination->settings['endpoint'] ?? ''), PHP_URL_HOST) ?: ($destination->settings['endpoint'] ?? ''));

        return $host === '' ? null : implode('.', array_slice(explode('.', $host), -2));
    }

    /**
     * @param  Collection<int, Machine>  $machines
     * @param  Security  $security
     * @return list<HomeIssue>
     */
    private function issues(Workspace $workspace, Collection $machines, WorkspaceHealth $health, array $security): array
    {
        $issues = [];
        if ($security['package'] !== null && (in_array($security['severity'], Vulnerability::ALERTING, true) || $security['exploited'] > 0)) {
            $issues[] = $this->securityIssue($workspace, $security);
        }

        $fixes = AgentFix::query()->where('workspace_id', $workspace->id)->where('state', 'needs_approval')
            ->latest()->limit(20)->get(['id', 'title']);
        if ($fixes->isNotEmpty()) {
            $title = $fixes->first()->title;
            $issues[] = [
                'id' => 'fixes-waiting',
                'severity' => WorkspaceHealth::WARNING,
                'title' => $fixes->count() === 1
                    ? $this->t('A fix needs your approval')
                    : $this->t(':count fixes need your approval', ['count' => $fixes->count()]),
                'detail' => $title ?? $this->t('An agent found a fix and is waiting for you to approve it.'),
                'brief' => $title ?? $this->t('An agent found a fix and is waiting for you to approve it.'),
                'href' => route('workspace.fixes.index', ['workspace' => $workspace->slug, 'fix' => $fixes->first()->id]),
                'action' => $this->t('Review'),
                'since' => null,
                'machineId' => null,
                'ask' => null,
                'dismissible' => false,
            ];
        }

        $monitors = $workspace->monitors()->get()->keyBy('id');
        $linked = IntegrationIncident::query()->whereNotNull('open_key')->whereNotNull('external_url')
            ->whereHas('integration', fn ($q) => $q->where('workspace_id', $workspace->id))
            ->get()->mapWithKeys(fn (IntegrationIncident $i) => [(string) $i->open_key => $i->link()]);
        foreach ($health->issues($workspace, $machines) as $issue) {
            $source = preg_match('/^(monitor|machine)-(\d+)-(down|offline)$/', $issue['id'], $m) === 1 ? "{$m[1]}:{$m[2]}" : null;
            $issues[] = [
                ...$issue,
                'brief' => $this->brief($issue, $machines, $monitors),
                'href' => $workspace->url($issue['href']),
                'dismissible' => true,
                'linked' => $source === null ? null : $linked->get($source),
            ];
        }

        $rank = [WorkspaceHealth::CRITICAL => 0, WorkspaceHealth::WARNING => 1, WorkspaceHealth::NOTICE => 2];
        usort($issues, fn (array $a, array $b) => ($rank[$a['severity']] ?? 3) <=> ($rank[$b['severity']] ?? 3));

        return $issues;
    }

    /**
     * @param  Security  $security
     * @return HomeIssue
     */
    private function securityIssue(Workspace $workspace, array $security): array
    {
        $cves = $security['cves'];
        $title = $cves === 1
            ? $this->t('1 :severity CVE in :package', ['severity' => $security['severity'], 'package' => $security['package']])
            : $this->t(':count :severity CVEs in :package', ['count' => $cves, 'severity' => $security['severity'], 'package' => $security['package']]);
        $brief = trim(implode(' ', array_filter([
            match (true) {
                $security['exploited'] === 1 => $this->t('One is being exploited.'),
                $security['exploited'] > 1 => $this->t(':count are being exploited.', ['count' => $security['exploited']]),
                default => null,
            },
            $this->updatesWaiting($security['updates']),
        ])));

        return [
            'id' => 'security-'.$security['package'],
            'severity' => WorkspaceHealth::WARNING,
            'title' => $title,
            'detail' => $brief,
            'brief' => $brief,
            'href' => route('workspace.updates.index', ['workspace' => $workspace->slug]),
            'action' => $this->t('Review'),
            'since' => null,
            'machineId' => null,
            'ask' => null,
            'dismissible' => false,
        ];
    }

    private function updatesWaiting(int $updates): ?string
    {
        return match (true) {
            $updates === 1 => $this->t('1 security update is waiting.'),
            $updates > 1 => $this->t(':count security updates are waiting.', ['count' => $updates]),
            default => null,
        };
    }

    /**
     * @param  array{id: string, detail: string, since: string|null}  $issue
     * @param  Collection<int, Machine>  $machines
     * @param  BaseCollection<int, Monitor>  $monitors
     */
    private function brief(array $issue, Collection $machines, BaseCollection $monitors): string
    {
        $since = $issue['since'] === null ? null : CarbonImmutable::parse($issue['since']);
        preg_match('/^(machine|monitor|service)-(\d+)-/', $issue['id'], $match);
        $kind = $match[1] ?? null;
        $id = isset($match[2]) ? (int) $match[2] : null;

        if ($kind === 'machine' && str_ends_with($issue['id'], '-offline') && $since !== null) {
            return $this->t('Its daemon stopped answering :time.', ['time' => $since->diffForHumans()]);
        }
        if ($kind === 'machine' && str_ends_with($issue['id'], '-memory') && ($machine = $machines->find($id)) !== null) {
            return $this->t(':used of :total in use.', [
                'used' => WorkspaceHealth::bytes((int) $machine->memory_used_mb * 1048576),
                'total' => WorkspaceHealth::bytes((int) ($machine->facts['memory_bytes'] ?? 0)),
            ]);
        }
        if ($kind === 'monitor') {
            $monitor = $monitors->get($id);
            if ($monitor instanceof Monitor && str_ends_with($issue['id'], '-down')) {
                $error = $monitor->last_error ?? $this->t('Checks are failing.');

                return $since === null ? $error : $this->t(':error Down for :time.', ['error' => $error, 'time' => $since->diffForHumans(syntax: CarbonInterface::DIFF_ABSOLUTE)]);
            }
            if ($monitor instanceof Monitor && $monitor->ssl_expires_at !== null && str_ends_with($issue['id'], '-certificate')) {
                return $monitor->ssl_expires_at->isPast()
                    ? $this->t('It expired on :date.', ['date' => $monitor->ssl_expires_at->format('j M')])
                    : $this->t('It expires on :date.', ['date' => $monitor->ssl_expires_at->format('j M')]);
            }
        }
        if ($kind === 'service' && str_ends_with($issue['id'], '-down') && $since !== null) {
            return $this->t('It stopped :time.', ['time' => $since->diffForHumans()]);
        }

        return preg_split('/(?<=[.!?])\s+/u', $issue['detail'], 2)[0] ?? $issue['detail'];
    }

    /**
     * @param  array<string, string|int>  $replace
     */
    private function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }

    #[Audited]
    public function dismiss(Request $request, Workspace $workspace, WorkspaceHealth $health): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['string', 'max:300'],
        ])['ids'];

        $marked = $health->dismiss($workspace, $ids);
        if ($marked > 0) {
            Activity::record($workspace, 'issues.dismiss', $marked === 1 ? '1 item' : "{$marked} items");
        } else {
            AuditContext::nothingChanged();
        }

        return back();
    }
}
