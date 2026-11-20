<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Agent\AddAgents;
use App\Mcp\Tools\Agent\GetAgentTask;
use App\Mcp\Tools\Agent\ListAgentMachines;
use App\Mcp\Tools\Agent\ListAgentTasks;
use App\Mcp\Tools\Agent\MergeAgentPullRequest;
use App\Mcp\Tools\Agent\StartAgents;
use App\Mcp\Tools\Agent\StopAgentTask;

/** Coding agents on the workspace's machines, like the Agents page. Each says it's off while Agents is (Agents::enabled). */
final class AgentTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            ListAgentMachines::class,
            ListAgentTasks::class,
            GetAgentTask::class,
            StartAgents::class,
            AddAgents::class,
            StopAgentTask::class,
            MergeAgentPullRequest::class,
        ];
    }
}
