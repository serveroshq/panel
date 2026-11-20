<?php

namespace App\Support\Extensions;

use App\Models\AgentTask;
use App\Models\User;
use App\Models\Workspace;
use RuntimeException;

final class NoAgentHosting implements AgentHosting
{
    public function offer(Workspace $workspace, User $user): ?array
    {
        return null;
    }

    public function place(Workspace $workspace, User $user, int $agents): array
    {
        throw new RuntimeException('Agents can only run on your own machines here.');
    }

    public function refusal(AgentTask $task, int $count): ?string
    {
        return null;
    }

    public function progress(AgentTask $task): ?array
    {
        return null;
    }
}
