<?php

namespace App\Http\Controllers;

use App\Http\Resources\MachineResource;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\ServiceGroup;
use App\Models\Workspace;
use App\Support\Deploys\AppReleases;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Inertia\Inertia;
use Inertia\Response;

class ServiceController extends Controller
{
    public function index(Workspace $workspace, ServiceTemplateCatalog $templates): Response
    {
        $machines = $workspace->machines()->orderBy('name')->get();

        return Inertia::render('workspace/Services', [
            'workspace' => $workspace->toPageProps(),
            'services' => $machines->flatMap(fn (Machine $machine) => collect(AppReleases::visible($machine))
                ->filter(fn (array $service) => isset($service['key']))
                ->map(fn (array $service) => [
                    ...$service,
                    'machine' => ['id' => $machine->id, 'name' => $machine->name],
                    'stat' => $machine->service_stats[$service['key']] ?? null,
                ]))->values(),
            'groups' => ServiceGroup::query()->whereIn('machine_id', $machines->modelKeys())->orderBy('name')->get()
                ->map(fn (ServiceGroup $group) => [...$group->toPageProps(), 'machineId' => $group->machine_id]),
            'machines' => $machines->map(fn (Machine $machine) => [
                'id' => $machine->id,
                'name' => $machine->name,
                'connection' => MachineResource::connection($machine),
                'scanned' => $machine->inventory !== null,
                'scannedAt' => $machine->inventory['scanned_at'] ?? null,
            ]),
            // The same deploys a machine's page lists while a service builds.
            'deploys' => DaemonJob::query()
                ->whereIn('machine_id', $machines->modelKeys())
                ->where('kind', 'deploy')
                ->where(fn ($query) => $query->whereIn('state', ['queued', 'accepted', 'running'])
                    ->orWhere('created_at', '>=', now()->subMinutes(10)))
                ->latest()
                ->limit(50)
                ->get()
                ->map(fn (DaemonJob $job) => [
                    'id' => $job->id,
                    'machineId' => $job->machine_id,
                    'service' => $job->service,
                    'state' => $job->state,
                    'phase' => $job->phase,
                    'progress' => $job->progress,
                    'createdAt' => $job->created_at?->toIso8601String(),
                ]),
            'templates' => fn () => $templates->toPageProps(),
            'deployEnabled' => (bool) config('daemon.commands_enabled') && filled(config('daemon.gateway_internal_url')),
        ]);
    }
}
