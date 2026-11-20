<?php

namespace App\Mcp\Tools\Agent;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Agents\AgentTasks;
use RuntimeException;

final class MergeAgentPullRequest extends Tool
{
    public function __construct(private readonly AgentTasks $tasks) {}

    public function enabled(): bool
    {
        return Agents::enabled();
    }

    public function name(): string
    {
        return 'merge_agent_pull_request';
    }

    public function title(): string
    {
        return 'Merge an agent’s pull request';
    }

    public function description(): string
    {
        return 'Squash-merges one agent’s pull request on GitHub, like Merge on the task’s page. The other agents’ pull requests from the same message are closed and their waiting agents dropped, since one answer was picked. '
            .'Only a pull request ServerOS opened for that agent, in the task’s repository, can be merged. Needs two-factor authentication. '
            .'Read its changes first (get_agent_task), ask the person, then pass confirm set to the pull request’s link exactly.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'task' => Agents::taskSchema(),
            'agent' => Agents::agentSchema(),
            'confirm' => Schema::confirm('the pull request’s link'),
        ], ['task', 'agent', 'confirm']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'task' => ['required', 'integer', 'min:1'],
            'agent' => ['required', 'integer', 'min:1'],
            'confirm' => ['nullable', 'string', 'max:200'],
        ]);
        $task = Agents::task($context, $data['task']);
        $run = Agents::agent($task, $data['agent']);
        if (! $context->user->hasEnabledTwoFactorAuthentication()) {
            throw new ToolError('Turn on two-factor authentication to merge: the person can do that in ServerOS under Settings → Security.');
        }
        $pr = AgentTasks::prFor($task, $run) ?? throw new ToolError("Agent {$run->id} has no pull request to merge.");
        $given = trim((string) ($data['confirm'] ?? ''));
        if ($given !== $pr) {
            throw new ToolError(($given === '' ? 'Check with the person first: this merges into ' : 'confirm doesn’t match. ')
                ."{$task->repo} and closes the other agents’ pull requests from the same message. Call again with confirm set to the pull request’s link exactly: {$pr}");
        }

        try {
            $sha = $context->within(fn () => $this->tasks->merge($task, $run, $context->user));
        } catch (RuntimeException $e) {
            throw new ToolError($e->getMessage());
        }
        $context->record('agent.merge', $task->title, null, ['commit' => substr($sha, 0, 7)]);

        return [
            'task' => ['id' => $task->id, 'title' => $task->title, 'url' => Agents::url($context, $task)],
            'pr' => $pr,
            'commit' => $sha,
            'note' => "Merged {$pr} as ".substr($sha, 0, 7).'.',
        ];
    }
}
