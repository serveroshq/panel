<?php

namespace App\Mcp\Tools\Agent;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\AgentTask;
use App\Support\Agents\AgentTasks;

final class ListAgentTasks extends Tool
{
    public function __construct(private readonly AgentTasks $tasks) {}

    public function enabled(): bool
    {
        return Agents::enabled();
    }

    public function name(): string
    {
        return 'list_agent_tasks';
    }

    public function title(): string
    {
        return 'List agent tasks';
    }

    public function description(): string
    {
        return 'Lists agent tasks, newest activity first, like the Agents page’s task list. Each has its status (running, queued, review: a pull request is open, merged, done, failed or stopped), its repository and branch, the machine, how many agents are waiting for room, and each agent’s state, what it’s doing now and its pull request. get_agent_task has one task in full.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'status' => Schema::string('Only tasks in this state.', ['running', 'queued', 'review', 'merged', 'done', 'failed', 'stopped']),
            'limit' => Schema::integer('How many (default 20, up to 100).', 1, 100),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'status' => ['sometimes', 'string', 'in:running,queued,review,merged,done,failed,stopped'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $list = AgentTask::query()->where('workspace_id', $context->workspace->id)
            ->with(['machine:id,name', 'user:id,name'])
            ->latest('updated_at')
            ->limit(isset($data['status']) ? 200 : (int) ($data['limit'] ?? 20))
            ->get();
        $rows = $this->tasks->rows($list);
        if (isset($data['status'])) {
            $rows = array_values(array_filter($rows, fn (array $r) => $r['status'] === $data['status']));
        }
        $rows = array_slice($rows, 0, (int) ($data['limit'] ?? 20));

        return [
            'tasks' => array_map(fn (array $row) => [
                'id' => $row['id'],
                'title' => $row['title'],
                'status' => $row['status'],
                'agent' => $row['agentName'],
                'model' => $row['model'],
                'repo' => $row['repo'],
                'branch' => $row['branch'],
                'machine' => $row['machine'],
                'startedBy' => $row['user'],
                'waiting' => $row['waiting'],
                'agents' => array_map(fn (array $a) => [
                    'run' => $a['run'],
                    'state' => $a['state'],
                    'doing' => $a['phase'],
                    'message' => $a['message'],
                    'pr' => $a['pr'],
                    'prState' => $a['prState'],
                    'seconds' => $a['seconds'],
                ], $row['agents']),
                'updatedAt' => $row['updatedAt'],
                'url' => $context->url("/agents/{$row['id']}"),
            ], $rows),
        ];
    }
}
