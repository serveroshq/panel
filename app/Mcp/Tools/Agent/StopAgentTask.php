<?php

namespace App\Mcp\Tools\Agent;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Agents\AgentTasks;
use App\Support\Audit\AuditContext;

final class StopAgentTask extends Tool
{
    public function __construct(private readonly AgentTasks $tasks) {}

    public function enabled(): bool
    {
        return Agents::enabled();
    }

    public function name(): string
    {
        return 'stop_agent_task';
    }

    public function title(): string
    {
        return 'Stop an agent task';
    }

    public function description(): string
    {
        return 'Stops every agent on a task, like Stop on its page: agents waiting in line never start, and working ones are ended on the machine with everything they started. Their unfinished work is lost; pull requests already open stay open. add_agents can start more later. '
            .'Ask the person first unless they asked you to stop it, then pass confirm set to the task’s title exactly.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'task' => Agents::taskSchema(),
            'confirm' => Schema::confirm('the task’s title'),
        ], ['task', 'confirm']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'task' => ['required', 'integer', 'min:1'],
            'confirm' => ['nullable', 'string', 'max:200'],
        ]);
        $task = Agents::task($context, $data['task']);
        if (! $task->queued()->exists() && ! $task->runs()->where('state', 'running')->exists()) {
            AuditContext::nothingChanged();

            return ['task' => ['id' => $task->id, 'title' => $task->title], 'stopped' => 0, 'note' => 'Nothing on it is working or waiting.'];
        }
        $given = trim((string) ($data['confirm'] ?? ''));
        if ($given === '') {
            throw new ToolError("Check with the person first: this ends its agents and their unfinished work. Then call again with confirm set to the task’s title exactly: “{$task->title}”.");
        }
        if ($given !== $task->title) {
            throw new ToolError("confirm doesn’t match. It must be the task’s title exactly: “{$task->title}”. Nothing was stopped.");
        }

        $stopped = $context->within(fn () => $this->tasks->stop($task, $context->user));
        $context->record('agent.stop', $task->title, $task->machine, ['stopped' => $stopped]);

        return [
            'task' => ['id' => $task->id, 'title' => $task->title, 'url' => Agents::url($context, $task)],
            'stopped' => $stopped,
            'note' => "Stopped {$stopped} agent".($stopped === 1 ? '' : 's').'.',
        ];
    }
}
