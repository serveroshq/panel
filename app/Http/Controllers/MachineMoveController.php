<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Machine;
use App\Models\MachineMove;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Machines\MachineFile;
use App\Support\Machines\MachineMoveCheck;
use App\Support\Machines\MachineMoves;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use RuntimeException;

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

        return response()->json($check->run($machine, $this->target($request, $workspace, $machine)));
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace, Machine $machine, MachineMoves $moves): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $to = $this->target($request, $workspace, $machine);

        try {
            $move = $moves->start($machine, $to, $request->user());
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(MachineMoves::present($move), 202);
    }

    public function show(Workspace $workspace, Machine $machine, string $move): JsonResponse
    {
        return response()->json(MachineMoves::present($this->move($workspace, $machine, $move)));
    }

    #[Audited]
    public function switchOver(Request $request, Workspace $workspace, Machine $machine, string $move, MachineMoves $moves): JsonResponse
    {
        return $this->act($this->move($workspace, $machine, $move), fn (MachineMove $m) => $moves->switchOver($m, $request->user()));
    }

    #[Audited]
    public function rollBack(Request $request, Workspace $workspace, Machine $machine, string $move, MachineMoves $moves): JsonResponse
    {
        return $this->act($this->move($workspace, $machine, $move), fn (MachineMove $m) => $moves->rollBack($m, $request->user()));
    }

    #[Audited]
    public function finish(Request $request, Workspace $workspace, Machine $machine, string $move, MachineMoves $moves): JsonResponse
    {
        return $this->act($this->move($workspace, $machine, $move), fn (MachineMove $m) => $moves->finish($m, $request->user()));
    }

    #[Audited]
    public function cancel(Request $request, Workspace $workspace, Machine $machine, string $move, MachineMoves $moves): JsonResponse
    {
        return $this->act($this->move($workspace, $machine, $move), fn (MachineMove $m) => $moves->cancel($m, $request->user()));
    }

    #[Audited]
    public function retry(Request $request, Workspace $workspace, Machine $machine, string $move, MachineMoves $moves): JsonResponse
    {
        return $this->act($this->move($workspace, $machine, $move), fn (MachineMove $m) => $moves->retry($m, $request->user()));
    }

    /** @param  \Closure(MachineMove): void  $action */
    private function act(MachineMove $move, \Closure $action): JsonResponse
    {
        try {
            $action($move);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(MachineMoves::present($move->refresh()));
    }

    private function move(Workspace $workspace, Machine $machine, string $id): MachineMove
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        return MachineMove::query()->where('workspace_id', $workspace->id)->where('from_machine_id', $machine->id)->findOrFail((int) $id);
    }

    private function target(Request $request, Workspace $workspace, Machine $machine): Machine
    {
        $data = $request->validate([
            'to' => ['required', 'integer', Rule::notIn([$machine->id]), Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
        ], [
            'to.not_in' => __('Pick a different machine to move to.'),
            'to.exists' => __('Pick a machine in this workspace.'),
        ]);

        return Machine::query()->findOrFail((int) $data['to']);
    }
}
