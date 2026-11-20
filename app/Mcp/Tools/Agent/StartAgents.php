<?php

namespace App\Mcp\Tools\Agent;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\AgentTask;
use App\Models\Machine;
use App\Support\Agents\AgentTasks;
use App\Support\Extensions\AgentHosting;
use App\Support\Workflows\AgentSpec;
use App\Support\Workflows\ReleaseSpec;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StartAgents extends Tool
{
    public function __construct(private readonly AgentTasks $tasks, private readonly AgentHosting $hosting) {}

    public function enabled(): bool
    {
        return Agents::enabled();
    }

    public function name(): string
    {
        return 'start_agents';
    }

    public function title(): string
    {
        return 'Start coding agents on a machine';
    }

    public function description(): string
    {
        return 'Starts a task for coding agents (Claude Code, Codex or OpenCode) on one of the workspace’s own machines, like sending from the Agents page. Up to '.AgentTask::MAX_AGENTS.' agents each work on it on their own, in the machine’s Coding agent container, with the workspace’s own AI key. '
            .'With a repository, each agent clones it, works on a branch of its own and (unless pull_requests is false) opens its own pull request. Pass pull_request (a GitHub PR link, owner/name#12, or a number with repo) to work on an open PR: agents start from its branch and open their pull requests into it. '
            .'A machine fits about one agent per '.AgentTasks::GB_PER_AGENT.' GB of memory (at most '.AgentTasks::MAX_SLOTS.' at once): the rest wait in line and start as agents finish. when “tonight” waits for 1am on the machine’s clock and a quiet machine. '
            .'Agents run code on the machine, so this needs a connection allowed to run commands, two-factor authentication and Pro. Say what will run where and how many, and check with the person first. '
            .'Returns the task: follow it with get_agent_task, add agents or a follow-up with add_agents, stop it with stop_agent_task. list_agent_machines shows where agents can run.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'prompt' => Schema::string('What the agents should do, in plain words, as the person would write it.', maxLength: AgentSpec::MAX_PROMPT),
            'agents' => Schema::integer('How many agents work on it, each on its own (1–'.AgentTask::MAX_AGENTS.', default 1).', 1, AgentTask::MAX_AGENTS),
            'machine' => Schema::machine('Where they run: a machine’s id or name. Leave it out to use the online machine with a Coding agent container and the most room.'),
            'on_serveros' => Schema::boolean('Run them on ServerOS’s own machines instead of the workspace’s, when list_agent_machines offers that: paid for in agent time, and a machine takes a few minutes to start.'),
            'container' => Schema::string('Optional: the container they run in, by key or name (list_agent_machines). Defaults to the machine’s Coding agent container.', maxLength: 255),
            'pull_request' => Schema::string('Optional: an open pull request to work on: its GitHub link, owner/name#12, or its number with repo.', maxLength: 300),
            'repo' => Schema::string('Optional: the GitHub repository, as owner/name or its URL. Without one, agents work in the container itself and open no pull request.', maxLength: 200),
            'branch' => Schema::string('Optional: the branch to start from (default main, or the pull request’s branch).', maxLength: 200),
            'pull_requests' => Schema::boolean('Whether each agent opens a pull request with its change (default true when there’s a repository).'),
            'agent' => Schema::string('Which agent (default claude).', array_keys(AgentSpec::AGENTS)),
            'model' => Schema::string('Optional: the model, like opus, sonnet or gpt-5-codex. OpenCode needs one, like ollama/qwen3-coder:30b.', maxLength: 80),
            'title' => Schema::string('Optional: a short name for the task (default: the prompt’s first line).', maxLength: 160),
            'setup' => Schema::string('Optional: a command to run in the repository first, like npm ci.', maxLength: 500),
            'check' => Schema::string('Optional: a command that must pass before the agent hands its change back, like npm test.', maxLength: 500),
            'minutes' => Schema::integer('Optional: each agent’s time limit ('.AgentSpec::MIN_MINUTES.'–'.AgentSpec::MAX_MINUTES.' minutes).', AgentSpec::MIN_MINUTES, AgentSpec::MAX_MINUTES),
            'when' => Schema::string('now (default), or tonight: 1am on the machine’s clock, once it’s quiet.', ['now', 'tonight']),
        ], ['prompt']);
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
            'prompt' => ['required', 'string', 'max:'.AgentSpec::MAX_PROMPT],
            'agents' => ['sometimes', 'integer', 'min:1', 'max:'.AgentTask::MAX_AGENTS],
            'machine' => ['sometimes', 'nullable', 'string', 'max:120'],
            'on_serveros' => ['sometimes', 'boolean'],
            'container' => ['sometimes', 'nullable', 'string', 'max:255'],
            'pull_request' => ['sometimes', 'nullable', 'string', 'max:300'],
            'repo' => ['sometimes', 'nullable', 'string', 'max:200'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:200'],
            'pull_requests' => ['sometimes', 'boolean'],
            'agent' => ['sometimes', 'string', 'in:'.implode(',', array_keys(AgentSpec::AGENTS))],
            'model' => ['sometimes', 'nullable', 'string', 'max:80', 'regex:#^[A-Za-z0-9._:/\[\]-]{1,80}$#'],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'setup' => ['sometimes', 'nullable', 'string', 'max:500'],
            'check' => ['sometimes', 'nullable', 'string', 'max:500'],
            'minutes' => ['sometimes', 'nullable', 'integer', 'min:'.AgentSpec::MIN_MINUTES, 'max:'.AgentSpec::MAX_MINUTES],
            'when' => ['sometimes', 'string', 'in:now,tonight'],
        ], ['model.regex' => 'That isn’t a model name, like opus or gpt-5-codex.']);
        if (trim($data['prompt']) === '') {
            throw new ToolError('Write what the agents should do.');
        }
        Agents::requireCanRun($context, hosted: (bool) ($data['on_serveros'] ?? false));

        $agent = $data['agent'] ?? 'claude';
        if ($agent === 'opencode' && blank($data['model'] ?? null)) {
            throw new ToolError('Choose OpenCode’s model, like ollama/qwen3-coder:30b.');
        }
        if ((bool) ($data['on_serveros'] ?? false)) {
            try {
                [$machine, $container] = $context->within(fn () => $this->hosting->place($context->workspace, $context->user, (int) ($data['agents'] ?? 1)));
            } catch (RuntimeException $e) {
                throw new ToolError($e->getMessage());
            }
        } else {
            [$machine, $container] = $this->place($context, $data['machine'] ?? null, $data['container'] ?? null);
        }
        [$repo, $branch, $pr] = $this->source($context, $data);

        $input = [
            'prompt' => trim($data['prompt']),
            'title' => $data['title'] ?? null,
            'machine_id' => $machine->id,
            'service' => $container,
            'agent' => $agent,
            'model' => $data['model'] ?? null,
            'repo' => $repo,
            'branch' => $branch,
            'pull_request' => $repo !== null && (bool) ($data['pull_requests'] ?? true),
            'agents' => (int) ($data['agents'] ?? 1),
            'setup' => $data['setup'] ?? null,
            'check' => $data['check'] ?? null,
            'minutes' => $data['minutes'] ?? null,
        ];
        if ($pr !== null) {
            $input['prompt'] = "You’re working on the open pull request {$pr} (branch {$branch}).\n\n".$input['prompt'];
        }

        try {
            [$task, $asked] = $context->within(fn () => DB::transaction(function () use ($context, $input, $data) {
                $task = $this->tasks->create($context->workspace, $context->user, $input);
                $asked = $this->tasks->request($task, $context->user, $task->agents, when: $data['when'] ?? 'now');

                return [$task, $asked];
            }));
        } catch (RuntimeException $e) {
            throw new ToolError($e->getMessage());
        }
        $context->record('agent.task', $task->title, $machine, ['task' => $task->id, 'agents' => $task->agents]);

        $started = count($asked['started']);
        $waiting = $asked['queued'];

        return [
            'task' => [
                'id' => $task->id,
                'title' => $task->title,
                'agent' => AgentSpec::AGENTS[$task->agent],
                'model' => $task->model,
                'repo' => $task->repo,
                'branch' => $task->branch,
                'pullRequest' => $pr,
                'machine' => ['id' => $machine->id, 'name' => $machine->name],
                'container' => $container,
                'url' => Agents::url($context, $task),
            ],
            'started' => array_map(fn ($run) => $run->id, $asked['started']),
            'waiting' => $waiting,
            'note' => match (true) {
                ($data['when'] ?? 'now') === 'tonight' => "{$waiting} agent".($waiting === 1 ? '' : 's').' will start tonight, around 1am on '.$machine->name.'’s clock, once it’s quiet.',
                $waiting > 0 => "{$started} started on {$machine->name}; {$waiting} wait in line and start as the others finish. Follow them with get_agent_task.",
                default => "{$started} started on {$machine->name}. Follow them with get_agent_task.",
            },
        ];
    }

    /**
     * The machine and container: as named, or the online machine with an
     * agent container and the most room.
     *
     * @return array{0: Machine, 1: string}
     */
    private function place(McpContext $context, ?string $machineRef, ?string $containerRef): array
    {
        $boxes = collect(AgentTasks::machines($context->workspace))->keyBy('id');
        if (filled($machineRef)) {
            $machine = $context->machine($machineRef);
            $box = $boxes[$machine->id];
        } else {
            $box = $boxes->filter(fn (array $b) => $b['online'] && collect($b['containers'])->contains('agent', true))
                ->sortByDesc(fn (array $b) => $b['slots'] - $b['running'])
                ->first();
            if ($box === null) {
                throw new ToolError('No online machine has a Coding agent container. The person can add one from the Agents page ('.$context->url('/agents').'), or name a machine and container.');
            }
            $machine = $context->workspace->machines()->findOrFail($box['id']);
        }

        $containers = collect($box['containers']);
        if (filled($containerRef)) {
            $found = $containers->first(fn (array $c) => $c['key'] === $containerRef || strcasecmp(ltrim($c['name'], '/'), ltrim((string) $containerRef, '/')) === 0);
            if ($found === null) {
                throw new ToolError("No managed container “{$containerRef}” on {$machine->name}. Agents run in containers ServerOS manages: list_agent_machines shows them.");
            }

            return [$machine, $found['key']];
        }
        $found = $containers->firstWhere('agent', true);
        if ($found === null) {
            throw new ToolError("{$machine->name} has no Coding agent container. The person can add one from the Agents page, or name another managed container.");
        }

        return [$machine, $found['key']];
    }

    /**
     * The repository and branch, from a pull request when one's given.
     *
     * @param  array<string, mixed>  $data
     * @return array{0: string|null, 1: string, 2: string|null}
     */
    private function source(McpContext $context, array $data): array
    {
        $repo = trim((string) ($data['repo'] ?? ''));
        if ($repo !== '') {
            $parsed = ReleaseSpec::github($repo) ?? throw new ToolError('Give the repository as owner/name, or its GitHub URL.');
            $repo = "{$parsed[0]}/{$parsed[1]}";
        }
        $branch = trim((string) ($data['branch'] ?? ''));
        $pr = trim((string) ($data['pull_request'] ?? ''));
        if ($pr === '') {
            if (! ReleaseSpec::validBranch($branch ?: 'main')) {
                throw new ToolError('That isn’t a branch name.');
            }

            return [$repo ?: null, $branch ?: 'main', null];
        }

        $link = $this->pullRequestLink($pr, $repo ?: null);
        [$owner, $name] = AgentTasks::parsePr($link);
        if ($repo !== '' && strcasecmp($repo, "{$owner}/{$name}") !== 0) {
            throw new ToolError("That pull request is in {$owner}/{$name}, not {$repo}.");
        }
        $github = $this->tasks->pullRequest($context->workspace, $link, fresh: true);
        if ($github === null || $github['headRef'] === null) {
            throw new ToolError("GitHub didn’t show ServerOS {$link}. The person may need to install the ServerOS GitHub app on {$owner}/{$name} (Settings → Integrations).");
        }
        if ($github['state'] !== 'open') {
            throw new ToolError("{$link} is {$github['state']}. Agents work on open pull requests; pass repo and branch to start from a branch instead.");
        }
        if ($github['headRepo'] !== null && strcasecmp($github['headRepo'], "{$owner}/{$name}") !== 0) {
            throw new ToolError("{$link} comes from a fork ({$github['headRepo']}), so agents can’t push to its branch. Start them on {$owner}/{$name} with a branch instead.");
        }
        if ($branch !== '' && $branch !== $github['headRef']) {
            throw new ToolError("{$link} is on the branch {$github['headRef']}, not {$branch}. Leave branch out to use it.");
        }

        return ["{$owner}/{$name}", $github['headRef'], $link];
    }

    private function pullRequestLink(string $given, ?string $repo): string
    {
        if (preg_match('#^(?:https://)?github\.com/([A-Za-z0-9_.-]{1,100})/([A-Za-z0-9_.-]{1,100})/pull/(\d{1,9})(?:[/?\#].*)?$#', $given, $m) === 1
            || preg_match('#^([A-Za-z0-9_.-]{1,100})/([A-Za-z0-9_.-]{1,100})\#(\d{1,9})$#', $given, $m) === 1) {
            $link = "https://github.com/{$m[1]}/{$m[2]}/pull/{$m[3]}";
        } elseif (preg_match('#^\#?(\d{1,9})$#', $given, $m) === 1 && $repo !== null) {
            $link = "https://github.com/{$repo}/pull/{$m[1]}";
        } else {
            throw new ToolError(preg_match('#^\#?\d+$#', $given) === 1
                ? 'Give repo too, so the pull request’s number means something: owner/name.'
                : 'Give the pull request as its GitHub link, owner/name#12, or its number with repo.');
        }
        if (AgentTasks::parsePr($link) === null) {
            throw new ToolError('That isn’t a GitHub pull request.');
        }

        return $link;
    }
}
