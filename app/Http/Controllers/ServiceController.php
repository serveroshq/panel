<?php

namespace App\Http\Controllers;

use App\Http\Resources\MachineResource;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\ServiceGroup;
use App\Models\Workspace;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppReleases;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Illuminate\Support\Collection;
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
            'deploys' => self::deploys($machines->modelKeys())->map(fn (DaemonJob $job) => [
                'id' => $job->id,
                'machineId' => $job->machine_id,
                'service' => $job->service,
                'state' => $job->state,
                'phase' => $job->phase,
                'progress' => $job->progress,
                'createdAt' => $job->created_at?->toIso8601String(),
            ])->values(),
            'templates' => fn () => $templates->toPageProps(),
            'deployEnabled' => (bool) config('daemon.commands_enabled') && filled(config('daemon.gateway_internal_url')),
        ]);
    }

    /**
     * The same deploys a machine's page lists while a service builds: ones
     * still moving, and ones started in the last ten minutes. Nothing
     * older than the deploy timeout can still be moving, which keeps the read
     * on the (machine_id, created_at) index, and a moving one with no update
     * in ten minutes is stuck (its machine went away), so it's left out.
     *
     * @param  array<int, int|string>  $machineIds
     * @return Collection<int, DaemonJob>
     */
    private static function deploys(array $machineIds): Collection
    {
        $jobs = fn () => DaemonJob::query()
            ->whereIn('machine_id', $machineIds)
            ->where('kind', 'deploy')
            ->where('created_at', '>=', now()->subSeconds(DaemonJobQueue::DEPLOY_TIMEOUT_SECS));
        $moving = ['queued', 'accepted', 'running'];

        // Moving ones are fetched apart, so a busy workspace's finished deploys can't push them out.
        return $jobs()->whereIn('state', $moving)->where('updated_at', '>=', now()->subMinutes(DaemonJob::STALE_AFTER_MINUTES))->get()
            ->concat($jobs()->whereNotIn('state', $moving)->where('created_at', '>=', now()->subMinutes(10))->latest()->limit(50)->get())
            ->sortByDesc('created_at');
    }
}
