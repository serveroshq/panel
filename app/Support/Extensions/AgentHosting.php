<?php

namespace App\Support\Extensions;

use App\Models\AgentTask;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use RuntimeException;

/**
 * Somewhere agents can run that isn't one of the workspace's own machines:
 * a package's machines, run for the workspace (serveros.com's agent
 * machines). The panel offers it on the Agents page and asks it before
 * agents start; everything else about a task works as on any machine.
 */
interface AgentHosting
{
    /**
     * What the Agents page offers, or null when there's nothing to offer
     * this workspace.
     *
     * @return array{label: string, detail: string, price: string|null, balance: string|null, hasTime: bool, ready: bool, refusal: string|null, buyUrl: string|null, packs: list<array{packs: int, hours: int, price: string}>, concurrent: int, minimum: int}|null
     */
    public function offer(Workspace $workspace, User $user): ?array;

    /**
     * The machine and container a new task runs in. The machine may still
     * be starting: its agents wait in line until it's ready.
     *
     * @return array{0: Machine, 1: string}
     *
     * @throws RuntimeException when the workspace can't use it now
     */
    public function place(Workspace $workspace, User $user, int $agents): array;

    /** Why these agents can't start on the package's machine now, or null. */
    public function refusal(AgentTask $task, int $count): ?string;

    /**
     * How the package's machine for the task is coming along while its
     * agents wait for it, step by step; null when there's nothing to show.
     *
     * @return array{steps: list<array{key: string, label: string, state: string}>, startedAt: string|null, region: string|null, waiting: int, error: string|null}|null
     */
    public function progress(AgentTask $task): ?array;
}
