<?php

namespace App\Mcp\Tools\Agent;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\ToolError;
use App\Models\AgentTask;
use App\Models\WorkflowRun;

/** What the agent tools share: finding a task or one of its agents, and the checks before agents run. */
final class Agents
{
    /** The tools are there while the Agents page is. */
    public static function enabled(): bool
    {
        return (bool) config('serveros.agents');
    }

    /** @return array<string, mixed> */
    public static function taskSchema(): array
    {
        return Schema::integer('The task’s id, as start_agents or list_agent_tasks gave it.', 1);
    }

    /** @return array<string, mixed> */
    public static function agentSchema(): array
    {
        return Schema::integer('One agent of the task: its run id, from get_agent_task’s agents.', 1);
    }

    public static function task(McpContext $context, mixed $id): AgentTask
    {
        $task = AgentTask::query()->where('workspace_id', $context->workspace->id)->find((int) $id);
        if (! $task instanceof AgentTask) {
            throw new ToolError("No agent task #{$id} in {$context->workspace->name}. list_agent_tasks shows them.");
        }

        return $task;
    }

    public static function agent(AgentTask $task, mixed $run): WorkflowRun
    {
        $found = $task->runs()->find((int) $run);
        if (! $found instanceof WorkflowRun) {
            throw new ToolError("Agent {$run} isn’t part of task #{$task->id}. get_agent_task lists its agents.");
        }

        return $found;
    }

    /** The same checks as the Agents page before anything runs on a machine. */
    public static function requireCanRun(McpContext $context, bool $hosted = false): void
    {
        $context->require(WorkspacePermission::ManageMachines);
        // ServerOS's machines are paid for in agent time: no plan or two-factor needed there.
        if ($hosted) {
            return;
        }
        if (! $context->workspace->hasAutomations()) {
            throw new ToolError('Agents come with Pro. The person can choose Pro or Scale in ServerOS under Settings → Billing.');
        }
        if (! $context->user->hasEnabledTwoFactorAuthentication()) {
            throw new ToolError('Turn on two-factor authentication to run agents: the person can do that in ServerOS under Settings → Security.');
        }
    }

    public static function url(McpContext $context, AgentTask $task): string
    {
        return $context->url("/agents/{$task->id}");
    }
}
