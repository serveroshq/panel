<?php

namespace App\Http\Controllers;

use App\Http\Resources\MachineResource;
use App\Models\Activity;
use App\Models\Backup;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineGroup;
use App\Models\MachinePackageUpdate;
use App\Models\MachinePolicy;
use App\Models\MachineSample;
use App\Models\MaintenanceWindow;
use App\Models\ProjectNode;
use App\Models\ServiceGroup;
use App\Models\Workspace;
use App\Support\Admin\Impersonation;
use App\Support\Audit\Audited;
use App\Support\ConsoleLock;
use App\Support\Deploys\DeployFailure;
use App\Support\Files\FileJobs;
use App\Support\Firewall;
use App\Support\Health\WorkspaceHealth;
use App\Support\Integrations\Servers\CloudServers;
use App\Support\Machines\MachinePolicies;
use App\Support\Machines\Readme\MachineReadmes;
use App\Support\Machines\SshAccess;
use App\Support\ScheduledTasks;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class MachineController extends Controller
{
    private const RANGES = ['1h' => 3600, '6h' => 21600, '24h' => 86400, '7d' => 604800, '30d' => 2592000];

    public function index(Workspace $workspace): Response
    {
        return Inertia::render('workspace/Machines', [
            'workspace' => $workspace->toPageProps(),
            // ServerOS's agent machines live on the Agents side.
            'machines' => MachineResource::collection(
                $workspace->countedMachines()->orderBy('name')->get(),
            )->resolve(),
            'machineGroups' => $workspace->machineGroups()->orderBy('name')->get()
                ->map(fn (MachineGroup $group) => ['id' => $group->id, 'name' => $group->name, 'policyId' => $group->machine_policy_id])
                ->all(),
            'machinePolicies' => tap($workspace->machinePolicies(), fn () => MachinePolicies::defaultFor($workspace))->orderByDesc('is_default')->orderBy('name')->get(['id', 'name', 'is_default'])
                ->map(fn (MachinePolicy $policy) => ['id' => $policy->id, 'name' => $policy->name, 'isDefault' => $policy->is_default])
                ->all(),
            'fleetStatus' => ['live' => (bool) $workspace->fleetStatusPage?->live()],
        ]);
    }

    public function show(Request $request, Workspace $workspace, Machine $machine, ServiceTemplateCatalog $templates, ConsoleLock $console, MachineReadmes $readmes): Response
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);

        $tab = $request->route('tab');
        $user = $request->user();
        $defaultTab = $tab === null && MachineReadmes::opensFirst($machine, $user) ? 'readme' : 'overview';
        $onReadme = $tab === 'readme' || $defaultTab === 'readme';
        if ($onReadme && ! Impersonation::active($request)) {
            MachineReadmes::seen($machine, $user);
        }
        $readme = fn () => $readmes->page($machine, $user);

        $range = array_key_exists((string) $request->query('range'), self::RANGES)
            ? (string) $request->query('range')
            : '24h';
        $seesConsole = Gate::allows('manageMachines', $workspace);

        return Inertia::render('workspace/MachineShow', [
            'workspace' => $workspace->toPageProps(),
            'machine' => [
                ...(new MachineResource($machine))->resolve(),
                'firewall' => Firewall::view($machine),
                'serviceStats' => (object) $machine->serviceStatsNow(),
                'plannedMaintenance' => MaintenanceWindow::query()
                    ->where('machine_id', $machine->id)
                    ->whereIn('state', ['scheduled', 'active'])
                    ->with('creator:id,name')
                    ->orderBy('starts_at')
                    ->limit(10)
                    ->get()
                    ->map(fn (MaintenanceWindow $w) => $w->toCard())
                    ->all(),
            ],
            'recent' => fn () => MachineSample::history($machine, 3600),
            'day' => fn () => MachineSample::history($machine, self::RANGES['24h']),
            'live' => fn () => $this->live($machine),
            'traffic' => fn () => MachineSample::monthTraffic($machine),
            'overview' => fn () => $this->overview($machine),
            'range' => $range,
            'history' => Inertia::optional(fn () => MachineSample::history($machine, self::RANGES[$range])),
            'scheduled' => fn () => ScheduledTasks::for($machine),
            'issues' => fn () => app(WorkspaceHealth::class)->forMachine($machine),
            'defaultTab' => $defaultTab,
            'readme' => $onReadme ? $readme : Inertia::optional($readme),
            'access' => fn () => SshAccess::view($workspace, $machine, $request->user()),
            'cloudServer' => fn () => $seesConsole ? CloudServers::machineProps($machine) : null,
            'serviceGroups' => fn () => $machine->serviceGroups()->orderBy('name')->get()
                ->map(fn (ServiceGroup $group) => $group->toPageProps())
                ->all(),
            'templates' => fn () => $templates->toPageProps($machine),
            'deployEnabled' => (bool) config('daemon.commands_enabled') && filled(config('daemon.gateway_internal_url')),
            'deployWorkflows' => fn () => [
                'available' => $workspace->hasAutomations(),
                'count' => $workspace->workflows()->whereIn('trigger', ['webhook', 'deploy.finished'])->count(),
            ],
            'jobs' => DaemonJob::query()
                ->where('machine_id', $machine->id)
                ->whereNotIn('kind', ['discover', 'database_query', ...FileJobs::KINDS])
                ->latest()
                ->limit(20)
                ->get()
                ->map(function (DaemonJob $job) use ($seesConsole) {
                    $hidden = $job->kind === 'service_exec' && ! $seesConsole;

                    return [
                        'id' => $job->id,
                        'kind' => $job->kind,
                        'service' => $job->service,
                        'command' => $job->kind === 'service_exec' && ! $hidden ? ($job->command['job']['command'] ?? null) : null,
                        'state' => $job->state,
                        'phase' => $job->phase,
                        'progress' => $job->progress,
                        'log' => $hidden ? [] : ($job->log ?? []),
                        'result' => $hidden ? null : $job->result,
                        'error' => $hidden && is_array($job->error) ? Arr::except($job->error, ['output_tail']) : $job->error,
                        'explanation' => $hidden ? null : DeployFailure::explain($job),
                        'createdAt' => $job->created_at?->toIso8601String(),
                        'updatedAt' => $job->updated_at?->toIso8601String(),
                        'retryable' => $job->isRetryable(),
                        'stale' => $job->isStale(),
                    ];
                }),
            'files' => fn () => [
                'places' => FileJobs::places($machine),
                'maxWrite' => FileJobs::MAX_WRITE_BYTES,
                'maxRead' => FileJobs::MAX_READ_BYTES,
            ],
            'consoleLock' => fn () => [
                'twoFactorEnabled' => ! $console->requiresSetup($request->user()),
                'unlockedUntil' => $console->unlockedUntil()?->toIso8601String(),
                'minutes' => ConsoleLock::MINUTES,
            ],
        ]);
    }

    /** @return array<string, mixed> */
    private function overview(Machine $machine): array
    {
        $backups = Backup::query()
            ->where('machine_id', $machine->id)
            ->with(['machine', 'destination'])
            ->latest('id')
            ->limit(20)
            ->get();

        return [
            'projects' => ProjectNode::query()->where('machine_id', $machine->id)->distinct()->count('project_id'),
            'updates' => [
                'count' => $machine->updates_count,
                'security' => $machine->security_updates_count,
                'checkedAt' => $machine->updates_checked_at?->toIso8601String(),
                'rebootRequired' => $machine->reboot_required,
                'packages' => MachinePackageUpdate::query()
                    ->where('machine_id', $machine->id)
                    ->orderByDesc('security')
                    ->orderBy('name')
                    ->limit(200)
                    ->get()
                    ->map(fn (MachinePackageUpdate $update) => [
                        'name' => $update->name,
                        'from' => $update->installed,
                        'to' => $update->candidate,
                        'security' => $update->security,
                    ])
                    ->all(),
            ],
            'backup' => [
                'schedule' => $machine->backup_schedule,
                'destination' => $machine->backupDestination?->name,
            ],
            'backups' => $backups->map(fn (Backup $backup) => $backup->toPageProps())->all(),
        ];
    }

    /** @return array{cpu: int|null, memoryMb: int|null, load: list<float>|null, processes: int|null, networkIn: int|null, networkOut: int|null, at: string|null, last: array{at: string, cpu: int|null, memoryMb: int|null}|null} */
    private function live(Machine $machine): array
    {
        $now = $machine->isReporting();
        $readAt = $machine->readingAt();

        return [
            'cpu' => $now ? $machine->cpu_percent : null,
            'memoryMb' => $now ? $machine->memory_used_mb : null,
            'load' => ! $now || $machine->load_1 === null || $machine->load_5 === null || $machine->load_15 === null
                ? null
                : [$machine->load_1, $machine->load_5, $machine->load_15],
            'processes' => $now ? $machine->processes : null,
            'networkIn' => $now ? $machine->net_rx_rate : null,
            'networkOut' => $now ? $machine->net_tx_rate : null,
            'at' => $machine->telemetry_ts === null ? null : CarbonImmutable::createFromTimestamp($machine->telemetry_ts)->toIso8601String(),
            'last' => $now || $readAt === null ? null : [
                'at' => $readAt->toIso8601String(),
                'cpu' => $machine->cpu_percent,
                'memoryMb' => $machine->memory_used_mb,
            ],
        ];
    }

    #[Audited]
    public function destroy(Workspace $workspace, Machine $machine): RedirectResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $name = $machine->name;
        Activity::record($workspace, 'machine.remove', $name);
        $machine->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => "Removed {$name}."]);

        return to_route('workspace.machines.index', $workspace->slug);
    }
}
