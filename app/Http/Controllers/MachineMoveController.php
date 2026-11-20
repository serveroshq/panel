<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Machines\MachineFile;
use App\Support\Machines\MachineMoveCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MachineMoveController extends Controller
{
    public function file(Workspace $workspace, Machine $machine): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        $file = MachineFile::for($machine);
        Activity::record($workspace, 'machine.file', $machine->name, $machine);
        $name = (Str::slug($machine->name) ?: 'machine').'.serveros.json';

        return response()->json($file, 200, [
            'Content-Disposition' => 'attachment; filename="'.$name.'"',
            'Cache-Control' => 'no-store',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    #[Audited]
    public function check(Request $request, Workspace $workspace, Machine $machine, MachineMoveCheck $check): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        // A read-only check: nothing is sent to either machine.
        AuditContext::nothingChanged();

        $data = $request->validate([
            'to' => ['required', 'integer', Rule::notIn([$machine->id]), Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
        ], [
            'to.not_in' => __('Pick a different machine to move to.'),
            'to.exists' => __('Pick a machine in this workspace.'),
        ]);

        return response()->json($check->run($machine, Machine::query()->findOrFail((int) $data['to'])));
    }
}
