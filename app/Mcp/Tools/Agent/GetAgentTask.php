<?php

namespace App\Mcp\Tools\Agent;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\Agents\AgentTasks;

final class GetAgentTask extends Tool
{
    public function __construct(private readonly AgentTasks $tasks) {}

    public function enabled(): bool
    {
        return Agents::enabled();
    }

    public function name(): string
    {
        return 'get_agent_task';
    }

    public function title(): string
    {
        return 'Get an agent task';
    }

    public function description(): string
    {
        return 'One agent task in full, like its page: what it was asked, where it runs, and each agent with its state, what it’s doing now, its plan, its last steps (files read and edited, commands run), its answer when it’s done, its pull request and what it cost. Agents still waiting for room or for tonight are listed under waiting. '
            .'Pass agent (a run id) for that one agent’s whole step list and the last lines of its output. Agents can take many minutes: check back rather than waiting here. '
            .'What agents read and printed on the machine is only shown to people who can manage machines.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'task' => Agents::taskSchema(),
            'agent' => Agents::agentSchema(),
        ], ['task']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'task' => ['required', 'integer', 'min:1'],
            'agent' => ['sometimes', 'integer', 'min:1'],
        ]);
        $task = Agents::task($context, $data['task']);
        $one = isset($data['agent']) ? Agents::agent($task, $data['agent'])->id : null;
        $task->load(['machine:id,workspace_id,name,facts,uid,last_seen_at,disconnected_at,hosted_by,inventory', 'user:id,name']);
        $detail = $this->tasks->detail($task, full: $context->can(WorkspacePermission::ManageMachines));

        $agents = array_map(function (array $a) use ($one) {
            $mine = $one === $a['run'];

            return array_filter([
                'run' => $a['run'],
                'state' => $a['state'],
                'doing' => $a['phase'],
                'message' => $a['message'],
                'model' => $a['model'],
                'startedAt' => $a['startedAt'],
                'seconds' => $a['seconds'],
                'followsOn' => $a['after'],
                'notes' => $a['notes'],
                'plan' => $a['plan'],
                'steps' => array_map(fn (array $s) => trim("{$s['k']}: {$s['t']}".($s['d'] !== null ? " ({$s['d']})" : '')), $mine ? $a['steps'] : array_slice($a['steps'], -8)),
                'summary' => $a['summary'] !== '' ? $a['summary'] : null,
                'error' => $a['error'],
                'stat' => $a['stat'],
                'cost' => $a['cost'],
                'pr' => $a['pr'],
                'prState' => $a['prState'],
                'prBranch' => $a['prBranch'],
                'merged' => $a['merged'],
                'preview' => ($a['preview']['url'] ?? null),
                'output' => $mine ? array_slice($a['log'], -150) : null,
                'machineJob' => $a['machineJob'],
            ], fn ($v) => $v !== null && $v !== []);
        }, $one === null ? $detail['agents'] : array_values(array_filter($detail['agents'], fn (array $a) => $a['run'] === $one)));

        return [
            'task' => [
                'id' => $detail['id'],
                'title' => $detail['title'],
                'status' => $detail['status'],
                'prompt' => $detail['prompt'],
                'startedFrom' => $detail['context'],
                'agent' => $detail['agentName'],
                'model' => $detail['model'],
                'repo' => $detail['repo'],
                'branch' => $detail['branch'],
                'pullRequests' => $detail['pullRequest'],
                'machine' => $detail['machine'],
                'container' => $detail['service'],
                'startedBy' => $detail['user'],
                'capacity' => $detail['capacity'],
                'url' => Agents::url($context, $task),
            ],
            'agents' => $agents,
            'waiting' => $detail['waiting'],
        ];
    }
}
