<?php

namespace App\Mcp\Tools\Agent;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\AgentTask;
use App\Support\Agents\AgentTasks;
use RuntimeException;

final class AddAgents extends Tool
{
    public function __construct(private readonly AgentTasks $tasks) {}

    public function enabled(): bool
    {
        return Agents::enabled();
    }

    public function name(): string
    {
        return 'add_agents';
    }

    public function title(): string
    {
        return 'Add agents or a follow-up to a task';
    }

    public function description(): string
    {
        return 'Asks for more agents on an agent task, like the follow-up box on its page. With just agents, more of them try the task from the start. '
            .'With notes and after (one agent’s run id), they follow on from that agent: they’re told what it did and what to change, and while its pull request is open they build on its branch. '
            .'Like start_agents, more than the machine fits wait in line; it needs a connection allowed to run commands, two-factor authentication and Pro. Check with the person first.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'task' => Agents::taskSchema(),
            'agents' => Schema::integer('How many (1–'.AgentTask::MAX_AGENTS.', default 1).', 1, AgentTask::MAX_AGENTS),
            'notes' => Schema::string('What to change or try, for a follow-up. Needed with after.', maxLength: 4000),
            'after' => Agents::agentSchema(),
            'when' => Schema::string('now (default), or tonight.', ['now', 'tonight']),
        ], ['task']);
    }

    public function scope(): string
    {
        return Scopes::COMMANDS;
    }

    public function idempotent(): bool
    {
        return false;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'task' => ['required', 'integer', 'min:1'],
            'agents' => ['sometimes', 'integer', 'min:1', 'max:'.AgentTask::MAX_AGENTS],
            'notes' => ['sometimes', 'nullable', 'string', 'max:4000'],
            'after' => ['sometimes', 'integer', 'min:1'],
            'when' => ['sometimes', 'string', 'in:now,tonight'],
        ]);
        $task = Agents::task($context, $data['task']);
        Agents::requireCanRun($context, hosted: $task->machine->hosted_by !== null);
        $after = isset($data['after']) ? Agents::agent($task, $data['after']) : null;
        if ($after !== null && blank($data['notes'] ?? null)) {
            throw new ToolError('Say what to change, in notes.');
        }

        try {
            $asked = $context->within(fn () => $this->tasks->request($task, $context->user, (int) ($data['agents'] ?? 1), $data['notes'] ?? null, $after, $data['when'] ?? 'now'));
        } catch (RuntimeException $e) {
            throw new ToolError($e->getMessage());
        }
        $context->record($after !== null ? 'agent.follow_up' : 'agent.more', $task->title, $task->machine, [
            'started' => count($asked['started']), 'waiting' => $asked['queued'],
        ]);
        $started = count($asked['started']);

        return [
            'task' => ['id' => $task->id, 'title' => $task->title, 'url' => Agents::url($context, $task)],
            'started' => array_map(fn ($run) => $run->id, $asked['started']),
            'waiting' => $asked['queued'],
            'note' => "{$started} started".($asked['queued'] > 0 ? ", {$asked['queued']} waiting" : '').'. Follow them with get_agent_task.',
        ];
    }
}
