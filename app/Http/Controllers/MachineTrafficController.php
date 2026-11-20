<?php

namespace App\Http\Controllers;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Traffic\SiteTraffic;
use App\Support\Traffic\TrafficSwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Traffic counted from the proxy's access logs: the machine's switch, and
 * each app's numbers for its Traffic tab.
 */
class MachineTrafficController extends Controller
{
    #[Audited]
    public function update(Request $request, Workspace $workspace, Machine $machine, TrafficSwitch $switch): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        try {
            $record = $switch->set($machine, $request->user(), $enabled);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['jobId' => $record->id, 'traffic' => TrafficSwitch::props($machine->fresh() ?? $machine)], 202);
    }

    public function show(Workspace $workspace, Machine $machine, DaemonJob $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id && $job->kind === TrafficSwitch::KIND, 404);

        return response()->json([
            'state' => $job->isStale() ? 'stale' : $job->state,
            'error' => $job->error,
            'traffic' => TrafficSwitch::props($machine->fresh() ?? $machine),
        ]);
    }

    public function report(Request $request, Workspace $workspace, Machine $machine, string $service): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        abort_if(AppVersionController::lastDeployOf($machine, $service) === null, 404);

        return response()->json([
            ...SiteTraffic::state($machine, $service, SiteTraffic::domains($machine, $service)),
            ...SiteTraffic::report($machine, $service, (string) $request->query('range', '24h')),
        ]);
    }
}
