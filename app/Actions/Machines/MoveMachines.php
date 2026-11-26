<?php

namespace App\Actions\Machines;

use App\Models\Activity;
use App\Models\AgentFix;
use App\Models\BackupDestination;
use App\Models\Machine;
use App\Models\MaintenanceWindow;
use App\Models\Monitor;
use App\Models\PackageRolloutMachine;
use App\Models\Project;
use App\Models\ProjectLink;
use App\Models\ProjectMachine;
use App\Models\ProjectNode;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceStatusPage;
use App\Support\Backups\BackupReceivers;
use App\Support\Machines\MachinePolicies;
use App\Support\Projects\ProjectNetwork;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

class MoveMachines
{
    public function __construct(
        private readonly ProjectNetwork $network,
        private readonly BackupReceivers $receivers,
    ) {}

    /**
     * @param  Collection<int, Machine>  $machines
     * @return list<string>
     */
    public function move(Collection $machines, Workspace $to, ?User $by = null): array
    {
        $notes = [];
        /** @var list<array{0: Machine, 1: string}> $networks */
        $networks = [];
        /** @var list<int> $projects */
        $projects = [];
        /** @var array<int, true> $rotate destinations whose key a machine that's leaving them had */
        $rotate = [];

        DB::transaction(function () use ($machines, $to, &$notes, &$networks, &$projects, &$rotate) {
            $movingIds = array_values($machines->map(fn (Machine $machine) => $machine->id)->all());
            /** @var array<int, int> $copies */
            $copies = [];
            $notes = $this->moveReceivers($to, $movingIds, $rotate);

            foreach ($machines as $machine) {
                $from = $machine->workspace;

                if ($machine->backup_destination_id !== null) {
                    $notes = [...$notes, ...$this->bringDestination($machine, $to, $movingIds, $copies, $rotate)];
                }

                Monitor::query()->where('machine_id', $machine->id)->update(['workspace_id' => $to->id]);

                $left = $this->leaveProjects($machine);
                if ($left !== []) {
                    $notes[] = "{$machine->name} left its projects in {$from->name}, as projects stay with their workspace.";
                    $projects = [...$projects, ...array_keys($left)];
                    foreach ($left as $uid) {
                        $networks[] = [$machine, $uid];
                    }
                }
                $this->settleWork($machine);

                $machine->forceFill(['workspace_id' => $to->id, 'machine_group_id' => null, 'machine_policy_id' => null])->save();

                $this->dropFromFleetPage($from, $machine->id);

                Activity::record($from, 'machine.move_out', $machine->name, null, null, ['to' => $to->name]);
                Activity::record($to, 'machine.move_in', $machine->name, $machine, null, ['from' => $from->name]);
            }
        });
        MachinePolicies::push($machines);

        if ($by !== null && $projects !== []) {
            DB::afterCommit(fn () => $this->resyncNetworks($networks, array_values(array_unique($projects)), $by));
        }
        $keyedBy = $by ?? $to->owner;
        if ($rotate !== [] && $keyedBy !== null) {
            DB::afterCommit(fn () => $this->rotateKeys(array_keys($rotate), $keyedBy));
        }

        return $notes;
    }

    /** @return array<int, string> */
    private function leaveProjects(Machine $machine): array
    {
        $nodeIds = ProjectNode::query()->where('machine_id', $machine->id)->pluck('id')->all();
        $projectIds = ProjectNode::query()->whereIn('id', $nodeIds)->pluck('project_id')
            ->merge(ProjectMachine::query()->where('machine_id', $machine->id)->pluck('project_id'))
            ->unique()->all();
        if ($projectIds === []) {
            return [];
        }

        ProjectLink::query()->whereIn('app_node_id', $nodeIds)->orWhereIn('resource_node_id', $nodeIds)->delete();
        ProjectNode::query()->whereIn('origin_id', $nodeIds)->update(['origin_id' => null]);
        ProjectNode::query()->whereIn('id', $nodeIds)->delete();
        ProjectMachine::query()->where('machine_id', $machine->id)->delete();

        return Project::query()->whereIn('id', $projectIds)->pluck('uid', 'id')->all();
    }

    private function settleWork(Machine $machine): void
    {
        $moved = 'The machine moved to another workspace.';

        AgentFix::query()->where('machine_id', $machine->id)->whereNotIn('state', AgentFix::FINISHED)
            ->update(['state' => 'error', 'error' => $moved, 'finished_at' => now()]);
        PackageRolloutMachine::query()->where('machine_id', $machine->id)->whereNotIn('state', PackageRolloutMachine::FINISHED)
            ->update(['state' => 'skipped', 'outcome' => $moved, 'finished_at' => now()]);
        MaintenanceWindow::query()->where('machine_id', $machine->id)->whereIn('state', ['scheduled', 'active'])
            ->update(['state' => 'cancelled', 'phase' => null, 'outcome' => $moved, 'finished_at' => now()]);
    }

    /**
     * @param  list<array{0: Machine, 1: string}>  $networks
     * @param  list<int>  $projectIds
     */
    private function resyncNetworks(array $networks, array $projectIds, User $by): void
    {
        try {
            foreach ($networks as [$machine, $uid]) {
                $this->network->release($machine->fresh() ?? $machine, $uid, $by);
            }
            foreach (Project::query()->whereIn('id', $projectIds)->get() as $project) {
                $this->network->sync($project, $by);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * A machine that takes other machines' backups brings its destinations
     * with it, so only machines in its own workspace ever send to it. Ones
     * left behind stop backing up there, and the key they had is replaced.
     *
     * @param  list<int>  $movingIds
     * @param  array<int, true>  $rotate
     * @return list<string>
     */
    private function moveReceivers(Workspace $to, array $movingIds, array &$rotate): array
    {
        $notes = [];
        $destinations = BackupDestination::query()->with('machine')
            ->where('type', 'machine')
            ->whereIn('machine_id', $movingIds)
            ->where('workspace_id', '!=', $to->id)
            ->get();

        foreach ($destinations as $destination) {
            $stayers = Machine::query()->where('backup_destination_id', $destination->id)->whereNotIn('id', $movingIds)->get();
            foreach ($stayers as $stayer) {
                $stayer->forceFill(['backup_destination_id' => null])->save();
                $notes[] = "{$stayer->name}: its backups went to {$destination->machine?->name}, which moved, so they're off until you pick a destination.";
            }
            if ($stayers->isNotEmpty()) {
                $rotate[$destination->id] = true;
            }
            $destination->forceFill(['workspace_id' => $to->id])->save();
        }

        return $notes;
    }

    /** @param  list<int>  $destinationIds */
    private function rotateKeys(array $destinationIds, User $by): void
    {
        foreach (BackupDestination::query()->whereKey($destinationIds)->get() as $destination) {
            try {
                $this->receivers->setUp($destination, $by);
            } catch (Throwable $e) {
                report($e);
            }
        }
    }

    /**
     * @param  list<int>  $movingIds
     * @param  array<int, int>  $copies
     * @param  array<int, true>  $rotate
     * @return list<string>
     */
    private function bringDestination(Machine $machine, Workspace $to, array $movingIds, array &$copies, array &$rotate): array
    {
        $destination = BackupDestination::query()->find($machine->backup_destination_id);
        if ($destination === null || $destination->workspace_id === $to->id) {
            return [];
        }

        if ($destination->machine_id !== null && ! in_array($destination->machine_id, $movingIds, true)) {
            $machine->forceFill(['backup_destination_id' => null])->save();
            if ($destination->type === 'machine') {
                $rotate[$destination->id] = true;
            }

            return ["{$machine->name}: its backups went to a machine that stayed behind, so they're off until you pick a destination."];
        }

        $sharedWithStayers = Machine::query()
            ->where('backup_destination_id', $destination->id)
            ->whereNotIn('id', $movingIds)
            ->exists();

        if (! $sharedWithStayers) {
            $destination->forceFill(['workspace_id' => $to->id])->save();

            return [];
        }

        if (! isset($copies[$destination->id])) {
            $copy = $destination->replicate();
            $copy->forceFill(['workspace_id' => $to->id])->save();
            $copies[$destination->id] = $copy->id;
        }
        $copyId = $copies[$destination->id];

        $machine->forceFill(['backup_destination_id' => $copyId])->save();
        $machine->backups()->where('backup_destination_id', $destination->id)->update(['backup_destination_id' => $copyId]);

        return [];
    }

    private function dropFromFleetPage(Workspace $from, int $machineId): void
    {
        $page = WorkspaceStatusPage::query()->where('workspace_id', $from->id)->first();
        if ($page === null || ! is_array($page->machines)) {
            return;
        }

        $page->forceFill(['machines' => array_values(array_diff($page->machines, [$machineId]))])->save();
    }
}
