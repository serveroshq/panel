<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\IntegrationServer;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Integrations\Capability;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Servers\CloudServers;
use App\Support\Integrations\Servers\ManagesServers;
use App\Support\Integrations\Servers\Server;
use App\Support\Integrations\Servers\ServerOperations;
use App\Support\Integrations\Servers\ServerRules;
use App\Support\Integrations\Servers\ServerType;
use App\Support\Integrations\Servers\Snapshot;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * What a machine linked to a cloud server can ask its provider to do.
 */
class MachineServerController extends Controller
{
    #[Audited]
    public function reboot(Request $request, Workspace $workspace, Machine $machine): JsonResponse
    {
        [$row, $manager] = $this->linked($workspace, $machine, Capability::Power);
        $this->call(fn () => $manager->reboot($row->integration, $row->server_id));
        Activity::record($workspace, 'machine.server_reboot', $machine->name, $machine, $request->user(), ['provider' => $row->integration->provider()->name()]);

        return response()->json(['message' => __('Restarting :machine through :provider.', ['machine' => $machine->name, 'provider' => $row->integration->provider()->name()])]);
    }

    #[Audited]
    public function powerCycle(Request $request, Workspace $workspace, Machine $machine): JsonResponse
    {
        [$row, $manager] = $this->linked($workspace, $machine, Capability::Power);
        $this->call(fn () => $manager->powerCycle($row->integration, $row->server_id));
        Activity::record($workspace, 'machine.server_power_cycle', $machine->name, $machine, $request->user(), ['provider' => $row->integration->provider()->name()]);

        return response()->json(['message' => __('Power cycling :machine through :provider.', ['machine' => $machine->name, 'provider' => $row->integration->provider()->name()])]);
    }

    public function types(Workspace $workspace, Machine $machine): JsonResponse
    {
        [$row, $manager] = $this->linked($workspace, $machine, Capability::Resize);
        $server = Server::fromArray($row->details ?? []);

        try {
            $fresh = $manager->server($row->integration, $row->server_id);
            if ($fresh !== null) {
                $server = $fresh;
                $row->forceFill(['details' => $fresh->toArray(), 'synced_at' => now()])->save();
            }
            $types = $manager->resizeTypes($row->integration, $server);
            $currency = $manager->plans($row->integration)->currency;
            $current = $manager->plans($row->integration)->type($server->type);
        } catch (IntegrationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json([
            'server' => $server->toArray(),
            'current' => $current === null ? null : self::typeProps($current, $server->location),
            'types' => array_map(fn (ServerType $type) => self::typeProps($type, $server->location), $types),
            'currency' => $currency,
            'needsPowerOff' => $manager->rules()->resizeNeedsPowerOff,
            'rules' => $manager->rules()->toArray(),
            'busy' => ServerOperations::busy($machine),
        ])->header('Cache-Control', 'no-store');
    }

    #[Audited]
    public function resize(Request $request, Workspace $workspace, Machine $machine, ServerOperations $operations): JsonResponse
    {
        [$row, $manager] = $this->linked($workspace, $machine, Capability::Resize);
        $data = $request->validate(['type' => ['required', 'string', 'max:40'], 'grow_disk' => ['sometimes', 'boolean']]);
        if (ServerOperations::busy($machine)) {
            throw ValidationException::withMessages(['type' => __('Something else is changing this server. Wait for it to finish.')]);
        }

        $server = Server::fromArray($row->details ?? []);
        $allowed = $this->call(fn () => $manager->resizeTypes($row->integration, $server));
        $type = collect($allowed)->first(fn (ServerType $t) => $t->name === $data['type']);
        if ($type === null) {
            throw ValidationException::withMessages(['type' => __('This server can’t move to that type. Pick another from the list.')]);
        }

        $growDisk = $manager->rules()->resizeDisk === ServerRules::DISK_ALWAYS || (bool) ($data['grow_disk'] ?? false);
        $operation = $operations->resize($row, $machine, $request->user(), $type, $growDisk);
        Activity::record($workspace, 'machine.server_resize', $machine->name, $machine, $request->user(), [
            'provider' => $row->integration->provider()->name(),
            'from' => $server->typeLabel ?: $server->type,
            'to' => $type->label,
        ]);

        return response()->json(['operation' => ServerOperations::props($operation->refresh())], 201);
    }

    public function snapshots(Workspace $workspace, Machine $machine): JsonResponse
    {
        [$row, $manager] = $this->linked($workspace, $machine, Capability::Snapshots);

        try {
            $snapshots = $manager->snapshots($row->integration, $row->server_id);
        } catch (IntegrationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        return response()->json(['snapshots' => array_map(fn (Snapshot $s) => $s->toArray(), $snapshots)])->header('Cache-Control', 'no-store');
    }

    #[Audited]
    public function snapshot(Request $request, Workspace $workspace, Machine $machine): JsonResponse
    {
        [$row, $manager] = $this->linked($workspace, $machine, Capability::Snapshots);
        $data = $request->validate(['name' => ['required', 'string', 'max:100']]);
        $name = trim($data['name']);

        $snapshot = $this->call(fn () => $manager->snapshot($row->integration, $row->server_id, $name), 'name');
        Activity::record($workspace, 'machine.snapshot_create', $machine->name, $machine, $request->user(), ['provider' => $row->integration->provider()->name(), 'name' => $name]);

        return response()->json(['snapshot' => $snapshot->toArray()], 201);
    }

    #[Audited]
    public function deleteSnapshot(Request $request, Workspace $workspace, Machine $machine, string $snapshot): JsonResponse
    {
        [$row, $manager] = $this->linked($workspace, $machine, Capability::Snapshots);
        $found = collect($this->call(fn () => $manager->snapshots($row->integration, $row->server_id)))->first(fn (Snapshot $s) => $s->id === $snapshot);
        abort_if($found === null, 404);
        if ($found->status === 'creating') {
            throw ValidationException::withMessages(['form' => __('It’s still being taken. Delete it once it’s ready.')]);
        }

        $this->call(fn () => $manager->deleteSnapshot($row->integration, $found->id));
        Activity::record($workspace, 'machine.snapshot_delete', $machine->name, $machine, $request->user(), ['provider' => $row->integration->provider()->name(), 'name' => $found->name]);

        return response()->json(['deleted' => $found->id]);
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, Machine $machine): RedirectResponse
    {
        [$row, $manager] = $this->linked($workspace, $machine, Capability::DeleteServers);
        $data = $request->validate(['confirm' => ['required', 'string'], 'keep' => ['sometimes', 'boolean']]);
        if (trim($data['confirm']) !== $machine->name) {
            throw ValidationException::withMessages(['confirm' => __('Type :name to delete its server.', ['name' => $machine->name])]);
        }
        if (ServerOperations::busy($machine)) {
            throw ValidationException::withMessages(['confirm' => __('Something else is changing this server. Wait for it to finish.')]);
        }

        $provider = $row->integration->provider()->name();
        $this->call(fn () => $manager->delete($row->integration, $row->server_id), 'confirm');
        $keep = (bool) ($data['keep'] ?? false);
        Activity::record($workspace, 'machine.server_delete', $machine->name, $keep ? $machine : null, $request->user(), ['provider' => $provider, 'kept' => $keep]);
        $row->delete();

        if ($keep) {
            Inertia::flash('toast', ['type' => 'success', 'message' => __('Deleted the server at :provider. :machine stays in ServerOS.', ['provider' => $provider, 'machine' => $machine->name])]);

            return back();
        }

        $name = $machine->name;
        $machine->delete();
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Deleted :machine and its server at :provider.', ['machine' => $name, 'provider' => $provider])]);

        return to_route('workspace.machines.index', $workspace->slug);
    }

    /** @return array{0: IntegrationServer, 1: ManagesServers} */
    private function linked(Workspace $workspace, Machine $machine, Capability $capability): array
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $linked = CloudServers::of($machine);
        abort_if($linked === null || ! $linked[0]->integration->provider()->can($capability), 404);

        return $linked;
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function call(callable $work, string $field = 'form'): mixed
    {
        try {
            return $work();
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages([$field => $e->getMessage()]);
        }
    }

    /** @return array{name: string, label: string, cores: int, memoryGb: float, diskGb: int, cpu: string, architecture: string, monthly: float|null, hourly: float|null} */
    private static function typeProps(ServerType $type, string $location): array
    {
        $price = $type->priceIn($location);

        return [
            'name' => $type->name,
            'label' => $type->label,
            'cores' => $type->cores,
            'memoryGb' => $type->memoryGb,
            'diskGb' => $type->diskGb,
            'cpu' => $type->cpu,
            'architecture' => $type->architecture,
            'monthly' => $price['monthly'] ?? null,
            'hourly' => $price['hourly'] ?? null,
        ];
    }
}
