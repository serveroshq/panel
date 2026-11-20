<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Deploys\SiteSwitches;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * An app's site switches (maintenance page, password, redirects), changed
 * at once on the machine without a redeploy.
 */
class AppSiteController extends Controller
{
    #[Audited]
    public function update(Request $request, Workspace $workspace, Machine $machine, string $service, SiteSwitches $switches): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless($machine->workspace_id === $workspace->id, 404);
        abort_if(AppVersionController::lastDeployOf($machine, $service) === null, 404, 'This service wasn’t deployed from code.');

        try {
            $record = $switches->update($machine, $service, $request->user(), $request->only(SiteSwitches::fields()));
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        $domains = (array) (AppVersionController::lastDeployOf($machine, $service)?->command['job']['domains'] ?? []);

        return response()->json(['jobId' => $record->id, 'site' => SiteSwitches::props($machine->fresh() ?? $machine, $service, $domains)], 202);
    }
}
