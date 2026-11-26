<?php

namespace App\Support\Agents;

use App\Enums\WorkspacePermission;
use App\Models\AgentQueued;
use App\Models\AgentTask;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineSample;
use App\Models\Project;
use App\Models\ProjectEnvironment;
use App\Models\User;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\DaemonJobQueue;
use App\Support\Extensions\AgentHosting;
use App\Support\GitHub\GitHubApp;
use App\Support\Projects\Previews;
use App\Support\Workflows\AgentSpec;
use App\Support\Workflows\ReleaseSpec;
use App\Support\Workflows\WorkflowEngine;
use App\Support\Workflows\WorkflowSteps;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Agent tasks ride on the workflow engine: a task owns a one-step workflow
 * (manual trigger, then an agent step), and every agent working on it is a
 * run of that workflow. The engine already clones the repository, runs the
 * agent in the machine's container and opens the pull request.
 *
 * Around that: agents queue when their machine is full or were asked to run
 * tonight, a task can start from a failed deploy or a machine's logs, and a
 * pull request can be merged and deployed from the task.
 */
final class AgentTasks
{
    public const NODE = 'agent';

    public const TRIGGER = 'agent_task';

    /** What one agent needs, roughly: the agent itself plus the installs and tests it runs. */
    public const GB_PER_AGENT = 2;

    public const MAX_SLOTS = 6;

    /** Busier than this (CPU %), a machine isn't quiet enough for tonight's agents. */
    public const QUIET_CPU = 40;

    /** How long after 1am tonight's agents may still start, before waiting for the next night. */
    public const QUIET_WINDOW_HOURS = 5;

    public const MAX_WAITING_PER_TASK = 20;

    public const MAX_WAITING_PER_WORKSPACE = 60;

    public function __construct(
        private readonly WorkflowEngine $engine,
        private readonly GitHubApp $github,
        private readonly DaemonJobQueue $queue,
        private readonly Previews $previews,
        private readonly AgentHosting $hosting,
    ) {}

    /**
     * @param  array{prompt: string, title?: string|null, machine_id: int, service: string, agent: string, model?: string|null, repo?: string|null, branch?: string|null, pull_request: bool, agents: int, setup?: string|null, check?: string|null, minutes?: int|null}  $input
     * @param  array{ref: string, kind: string, label: string, text: string}|null  $context
     *
     * @throws RuntimeException
     */
    public function create(Workspace $workspace, User $user, array $input, ?array $context = null): AgentTask
    {
        /** @var Machine|null $machine */
        $machine = $workspace->machines()->find($input['machine_id']);
        if ($machine === null) {
            throw new RuntimeException('Choose one of this workspace’s machines.');
        }

        $prompt = trim($input['prompt']);
        $title = trim((string) ($input['title'] ?? '')) ?: self::titleFrom($prompt);
        $repo = trim((string) ($input['repo'] ?? '')) ?: null;
        $branch = trim((string) ($input['branch'] ?? '')) ?: 'main';
        $rules = array_filter([
            'machine_id' => $machine->id,
            'service' => $input['service'],
            'agent' => $input['agent'],
            'model' => trim((string) ($input['model'] ?? '')) ?: null,
            'prompt' => $prompt,
            'repo' => $repo,
            // Each run says which branch it starts from, so a follow-up can
            // build on an earlier agent's branch.
            'branch' => $repo === null ? null : '{{data.base}}',
            'pull_request' => $repo !== null && $input['pull_request'],
            'pr_title' => $repo !== null ? $title : null,
            'setup' => $repo !== null ? (trim((string) ($input['setup'] ?? '')) ?: null) : null,
            'check' => $repo !== null ? (trim((string) ($input['check'] ?? '')) ?: null) : null,
            'minutes' => AgentSpec::minutes($input['minutes'] ?? null),
        ], fn ($v) => $v !== null);

        $graph = WorkflowSteps::clean(['nodes' => [
            ['id' => 'trigger', 'kind' => 'trigger', 'x' => -200, 'y' => 0, 'title' => 'From the Agents page', 'description' => '', 'rules' => ['event' => 'manual']],
            ['id' => self::NODE, 'kind' => 'agent', 'x' => -200, 'y' => 200, 'title' => $title, 'description' => '', 'rules' => $rules],
        ], 'edges' => [
            ['id' => 'e1', 'source' => 'trigger', 'port' => 'out', 'target' => self::NODE],
        ]]);
        $problems = WorkflowSteps::problems($graph, $workspace);
        if ($problems !== []) {
            throw new RuntimeException((string) array_values($problems)[0]);
        }
        if ($repo !== null) {
            $this->checkRepository($workspace, $machine, $repo, $branch, (bool) $rules['pull_request']);
        }

        return DB::transaction(function () use ($workspace, $user, $machine, $input, $title, $prompt, $repo, $branch, $rules, $graph, $context) {
            $workflow = $workspace->workflows()->create([
                'name' => mb_substr("Agent task: {$title}", 0, 120),
                'graph' => $graph,
                'trigger' => 'manual',
                'run_as' => $user->id,
                'enabled' => false,
                'internal' => true,
            ]);

            $task = new AgentTask([
                'user_id' => $user->id,
                'workflow_id' => $workflow->id,
                'machine_id' => $machine->id,
                'service' => $input['service'],
                'title' => $title,
                'prompt' => $prompt,
                'context' => $context === null ? null : array_intersect_key($context, array_flip(['ref', 'kind', 'label', 'text'])),
                'agent' => $input['agent'],
                'model' => $rules['model'] ?? null,
                'repo' => $repo,
                'branch' => $branch,
                'pull_request' => $rules['pull_request'],
                'agents' => max(1, min(AgentTask::MAX_AGENTS, $input['agents'])),
            ]);
            $task->workspace_id = $workspace->id;
            $task->save();

            return $task;
        });
    }

    /**
     * What GitHub says about the repository before any agent starts: an
     * empty one, or a branch that isn't there, would only fail on the
     * machine. On a package's machine there's no token of the container's
     * own, so pull requests need ServerOS's GitHub App on the repository.
     *
     * @throws RuntimeException
     */
    private function checkRepository(Workspace $workspace, Machine $machine, string $repo, string $branch, bool $pullRequests): void
    {
        [$owner, $name] = explode('/', $repo, 2);
        $branches = $this->github->branchesFor($workspace, $owner, $name);
        if ($branches === []) {
            throw new RuntimeException("{$repo} is empty, so agents have nothing to start from. Add a first commit (a README is enough), then send it again.");
        }
        if (is_array($branches) && ! in_array($branch, $branches, true)) {
            throw new RuntimeException("{$repo} has no branch called {$branch}.");
        }
        if ($pullRequests && $machine->hosted_by !== null && $this->github->tokenFor($workspace, $owner, $name) === null) {
            throw new PullRequestsUnavailable("ServerOS’s GitHub App isn’t on {$repo}, so agents can’t open pull requests there. Install it from Settings › Integrations, or send without them: the agents still hand back what they did and their screenshots.");
        }
    }

    /**
     * Asks for $count agents on the task. They start now if their machine
     * has room, and wait in line otherwise; "tonight" waits for the small
     * hours on the machine's clock and a quiet machine. A follow-up passes
     * what the person wants changed and the run it follows on from. Agents
     * the hourly limit won't let start yet wait in line rather than being lost.
     *
     * @return array{started: list<WorkflowRun>, queued: int}
     *
     * @throws RuntimeException
     */
    public function request(AgentTask $task, User $user, int $count, ?string $notes = null, ?WorkflowRun $after = null, string $when = 'now'): array
    {
        $count = max(1, min(AgentTask::MAX_AGENTS, $count));
        if ($task->machine->hosted_by !== null && ($refusal = $this->hosting->refusal($task, $count)) !== null) {
            throw new RuntimeException($refusal);
        }
        $waitingForTask = $task->queued()->count();
        $waitingInWorkspace = AgentQueued::query()->whereIn('agent_task_id', AgentTask::query()->where('workspace_id', $task->workspace_id)->select('id'))->count();
        if ($waitingForTask + $count > self::MAX_WAITING_PER_TASK || $waitingInWorkspace + $count > self::MAX_WAITING_PER_WORKSPACE) {
            throw new RuntimeException('Too many agents are already waiting. Stop some, or wait for them to start.');
        }

        $turn = Str::lower(Str::random(10));
        $notes = filled($notes) ? mb_substr(trim((string) $notes), 0, 4000) : null;
        $tonight = $when === 'tonight';
        // A package's machine may still be starting: its agents wait in line.
        $room = $tonight || ! $this->ready($task) ? 0 : max(0, self::slots($task->machine) - self::running($task->machine) - $this->waiting($task->machine));

        $started = [];
        for ($i = 0; $i < min($count, $room); $i++) {
            try {
                $started[] = $this->startOne($task, $user, $turn, $notes, $after);
            } catch (RuntimeException) {
                break; // The hourly limit: the rest wait in line.
            }
        }
        $queued = $count - count($started);
        for ($i = 0; $i < $queued; $i++) {
            $task->queued()->create([
                'user_id' => $user->id,
                'turn' => $turn,
                'notes' => $notes,
                'after_run_id' => $after?->id,
                'not_before' => $tonight ? self::tonight($task->machine) : null,
                'quiet' => $tonight,
            ]);
        }
        $task->touch();

        return ['started' => $started, 'queued' => $queued];
    }

    /**
     * Starts what's waiting, oldest first, wherever there's room and the
     * hour has come. Runs every minute, on one server.
     */
    public function dispatch(): int
    {
        $started = 0;
        $room = [];
        $due = AgentQueued::query()
            ->where(fn ($q) => $q->whereNull('not_before')->orWhere('not_before', '<=', now()))
            ->with(['task.machine', 'task.workflow', 'task.workspace', 'user'])
            ->orderBy('id')
            ->limit(500)
            ->get();
        foreach ($due as $item) {
            $task = $item->task;
            $machine = $task->machine;
            $workspace = $task->workspace;
            $user = $item->user ?? $task->user;

            // Whoever asked has to still be allowed to, and the plan still cover it.
            if ($user === null || ! $workspace->allows($user, WorkspacePermission::ManageMachines) || ! self::mayRun($task, $user)) {
                $item->delete();

                continue;
            }
            if ($item->quiet && ! self::quiet($machine)) {
                // Still busy at the end of the night: try again the next night.
                if ($item->not_before !== null && $item->not_before->addHours(self::QUIET_WINDOW_HOURS)->isPast()) {
                    $item->update(['not_before' => self::tonight($machine)]);
                }

                continue;
            }
            if ($this->queue->unavailable($machine) !== null || ! $this->ready($task)) {
                continue;
            }
            if ($machine->hosted_by !== null && $this->hosting->refusal($task, 1) !== null) {
                continue;
            }
            $room[$machine->id] ??= self::slots($machine) - self::running($machine);
            if ($room[$machine->id] <= 0) {
                continue;
            }
            $after = $item->after_run_id !== null ? $task->runs()->find($item->after_run_id) : null;
            try {
                $claimed = DB::transaction(function () use ($item, $task, $user, $after) {
                    // Whoever deletes the row starts the agent: a Stop, or a
                    // second dispatcher, that got there first wins.
                    if (AgentQueued::query()->whereKey($item->id)->delete() !== 1) {
                        return false;
                    }
                    $this->startOne($task, $user, $item->turn, $item->notes, $after);

                    return true;
                });
                if ($claimed) {
                    $room[$machine->id]--;
                    $started++;
                }
            } catch (RuntimeException) {
                // The hourly limit: it stays in line for a later minute.
            } catch (Throwable $e) {
                report($e);
            }
        }

        return $started;
    }

    /**
     * The workspace's own machines with a container that can run an agent:
     * the Coding agent template's, or any managed container whose name says
     * agent. With how many agents fit on each at once, and how many are
     * working there. A package's machines are offered on their own.
     *
     * @return list<array{id: int, name: string, online: bool, slots: int, running: int, containers: list<array{key: string, name: string, agent: bool}>}>
     */
    public static function machines(Workspace $workspace): array
    {
        return array_values($workspace->machines()->whereNull('hosted_by')->orderBy('name')->get()->map(function (Machine $machine) {
            $containers = [];
            foreach ((array) ($machine->inventory['services'] ?? []) as $s) {
                if (! is_array($s) || ! is_string($s['key'] ?? null) || ! str_starts_with($s['key'], 'docker:') || ($s['details']['managed'] ?? null) !== 'true') {
                    continue;
                }
                $name = (string) ($s['name'] ?? $s['key']);
                $containers[] = ['key' => $s['key'], 'name' => $name, 'agent' => preg_match('/agent/i', "{$name} {$s['key']}") === 1];
            }
            usort($containers, fn ($a, $b) => $b['agent'] <=> $a['agent']);

            return [
                'id' => $machine->id,
                'name' => $machine->name,
                'online' => $machine->disconnected_at === null && $machine->last_seen_at !== null && $machine->last_seen_at->gt(now()->subSeconds(60)),
                'slots' => self::slots($machine),
                'running' => self::running($machine),
                'containers' => $containers,
            ];
        })->all());
    }

    /**
     * Whether the task's agents can start on its machine. A package's machine
     * isn't until it's connected and its container is there; any other
     * machine always is, and an agent there fails with the reason if not.
     */
    public function ready(AgentTask $task): bool
    {
        $machine = $task->machine;
        if ($machine->hosted_by === null) {
            return true;
        }
        if ($this->queue->unavailable($machine) !== null) {
            return false;
        }

        return collect((array) ($machine->inventory['services'] ?? []))->contains(fn ($s) => is_array($s) && ($s['key'] ?? null) === $task->service);
    }

    /**
     * Moves the task to another machine or container: its agents from now on
     * run there. A package's machine gets a fresh container each time it
     * starts, so its tasks follow it.
     */
    public function moveTo(AgentTask $task, Machine $machine, string $service): void
    {
        if ($machine->workspace_id !== $task->workspace_id) {
            throw new RuntimeException('That machine belongs to another workspace.');
        }
        DB::transaction(function () use ($task, $machine, $service) {
            $workflow = $task->workflow;
            $graph = $workflow->graph;
            foreach ($graph['nodes'] as $i => $node) {
                if (($node['id'] ?? null) === self::NODE) {
                    $graph['nodes'][$i]['rules']['machine_id'] = $machine->id;
                    $graph['nodes'][$i]['rules']['service'] = $service;
                }
            }
            $workflow->forceFill(['graph' => $graph])->save();
            $task->forceFill(['machine_id' => $machine->id, 'service' => $service])->save();
        });
        $task->setRelation('machine', $machine);
    }

    /**
     * Whether this person may run agents on the task's machine besides
     * managing machines. Their own machines: Pro and two-factor, since an
     * agent runs code there. A package's machine (ServerOS's) is paid for
     * by agent time and runs nothing of theirs, so neither is needed.
     */
    public static function mayRun(AgentTask $task, User $user): bool
    {
        return $task->machine->hosted_by !== null
            || ($task->workspace->hasAutomations() && $user->hasEnabledTwoFactorAuthentication());
    }

    /** How many agents fit on the machine at once, from its total memory. */
    public static function slots(Machine $machine): int
    {
        $bytes = $machine->facts['memory_bytes'] ?? null;
        if (! is_numeric($bytes) || (int) $bytes <= 0) {
            return 2;
        }

        return max(1, min(self::MAX_SLOTS, intdiv((int) $bytes, self::GB_PER_AGENT * 1024 ** 3)));
    }

    /** Agents working on the machine right now. */
    public static function running(Machine $machine): int
    {
        return WorkflowRun::query()
            ->where('state', 'running')
            ->whereIn('workflow_id', AgentTask::query()->where('machine_id', $machine->id)->select('workflow_id'))
            ->count();
    }

    private function waiting(Machine $machine): int
    {
        return AgentQueued::query()
            ->whereNull('not_before')
            ->whereIn('agent_task_id', AgentTask::query()->where('machine_id', $machine->id)->select('id'))
            ->count();
    }

    /** Quiet: it reported in the last 10 minutes, and its CPU stayed low. */
    public static function quiet(Machine $machine): bool
    {
        $cpu = MachineSample::query()->where('machine_id', $machine->id)->where('ts', '>=', now()->subMinutes(10)->getTimestamp())->avg('cpu');

        return $cpu !== null && (float) $cpu < self::QUIET_CPU;
    }

    /** 1am on the machine's own clock, tonight or tomorrow night once it's past. */
    public static function tonight(Machine $machine): CarbonImmutable
    {
        $zone = $machine->facts['timezone'] ?? null;
        $zone = is_string($zone) && in_array($zone, timezone_identifiers_list(), true) ? $zone : 'UTC';
        $at = CarbonImmutable::now($zone)->setTime(1, 0);

        return ($at->isPast() ? $at->addDay() : $at)->utc();
    }

    /** @throws RuntimeException */
    private function startOne(AgentTask $task, User $user, string $turn, ?string $notes, ?WorkflowRun $after): WorkflowRun
    {
        $base = $task->branch;
        $context = null;
        if ($after !== null) {
            $step = $after->steps[self::NODE] ?? [];
            $pr = self::prFor($task, $after);
            // Build on the earlier agent's branch while its pull request is
            // open; once it's merged or closed, start again from the task's.
            if ($pr !== null && ! isset($step['merged']) && ! isset($step['closed'])) {
                $base = AgentSpec::pullRequestBranch($after, self::NODE);
            }
            $context = array_filter([
                'summary' => is_string($step['summary'] ?? null) ? mb_substr($step['summary'], 0, 4000) : null,
                'pr' => $pr,
                'state' => $after->state,
            ]);
        }

        $by = AuditContext::viewAsSession($user) === null ? $user->name : AuditContext::SUPPORT;
        $run = $this->engine->start($task->workflow, self::TRIGGER, $after !== null ? "Follow-up by {$by}" : "Started by {$by}", array_filter([
            'task' => $task->id,
            'turn' => $turn,
            'by' => $by,
            'after' => $after?->id,
            'base' => $base,
            'notes' => $notes,
            'previous' => $context ?: null,
            'evidence' => $task->context === null ? null : ['label' => $task->context['label'], 'text' => $task->context['text']],
        ], fn ($v) => $v !== null), $user, paid: $task->machine->hosted_by !== null);
        if ($run === null) {
            throw new RuntimeException('This task has started too many agents in the last hour; the rest wait in line.');
        }

        return $run;
    }

    /**
     * Stops the task: what's waiting never starts, and each working agent is
     * ended on its machine, with every process it started.
     */
    public function stop(AgentTask $task, User $user): int
    {
        $stopped = $task->queued()->delete();
        foreach ($task->runs()->where('state', 'running')->get() as $run) {
            $this->engine->cancel($run, $user);
            $this->kill($task, $run, $user);
            $stopped++;
        }

        return $stopped;
    }

    /**
     * Ends a run's script and everything under it, found by the pid file the
     * script writes. The run id is a number, so it's safe in the command.
     */
    private function kill(AgentTask $task, WorkflowRun $run, User $user): void
    {
        $machine = $task->machine;
        if ($this->queue->unavailable($machine) !== null) {
            return;
        }
        $id = (int) $run->id;
        $command = 'k() { for c in $(cat /proc/$1/task/*/children 2>/dev/null); do k "$c"; done; kill -TERM "$1" 2>/dev/null; }; '
            ."p=\$(cat /tmp/serveros-agent-run-{$id}.pid 2>/dev/null) && [ -n \"\$p\" ] && k \"\$p\"; exit 0";
        try {
            $this->queue->queue($machine, $user, 'service_exec', [
                'type' => 'service_exec',
                'service' => $task->service,
                'command' => $command,
                'timeout_secs' => 30,
            ], $task->service, 60);
        } catch (RuntimeException) {
            // The machine is gone or has no plan: the agent stops at its time limit.
        }
    }

    /** Per workspace: what one workspace's GitHub access can read is never served to another. */
    public static function filesKey(int $workspaceId, string $pr): string
    {
        return "agent-pr-files:{$workspaceId}:".sha1($pr);
    }

    public static function pullKey(int $workspaceId, string $pr): string
    {
        return "agent-pr:{$workspaceId}:".sha1($pr);
    }

    /**
     * What a pull request changed, file by file with GitHub's patches, through
     * the workspace's GitHub App. Kept for a minute.
     *
     * @return list<array{path: string, status: string, additions: int, deletions: int, patch: string|null}>|null
     */
    public function pullRequestFiles(Workspace $workspace, string $pr): ?array
    {
        $parsed = self::parsePr($pr);
        if ($parsed === null) {
            return null;
        }
        [$owner, $repo, $number] = $parsed;

        $files = Cache::remember(self::filesKey($workspace->id, $pr), 60, function () use ($workspace, $owner, $repo, $number) {
            $response = $this->api($workspace, $owner, $repo)->get("https://api.github.com/repos/{$owner}/{$repo}/pulls/{$number}/files", ['per_page' => 100]);
            if (! $response->ok() || ! is_array($response->json())) {
                return false;
            }

            return array_values(array_map(fn ($f) => [
                'path' => (string) ($f['filename'] ?? ''),
                'status' => (string) ($f['status'] ?? 'modified'),
                'additions' => (int) ($f['additions'] ?? 0),
                'deletions' => (int) ($f['deletions'] ?? 0),
                'patch' => is_string($f['patch'] ?? null) ? mb_substr($f['patch'], 0, 60_000) : null,
            ], array_filter($response->json(), 'is_array')));
        });

        /** @var list<array{path: string, status: string, additions: int, deletions: int, patch: string|null}>|null */
        return is_array($files) ? $files : null;
    }

    /**
     * Where the pull request stands on GitHub. Kept for a minute.
     *
     * @return array{state: string, merged: bool, mergeSha: string|null, headSha: string|null, headRef: string|null, headRepo: string|null}|null
     */
    public function pullRequest(Workspace $workspace, string $pr, bool $fresh = false): ?array
    {
        $parsed = self::parsePr($pr);
        if ($parsed === null) {
            return null;
        }
        [$owner, $repo, $number] = $parsed;
        if ($fresh) {
            Cache::forget(self::pullKey($workspace->id, $pr));
        }

        // A miss is kept too, as an empty answer, so a repository GitHub won't
        // tell us about isn't asked again on every page load. Anything could be
        // cached under the key (an older entry, a partial one), so it's read
        // as it is and checked field by field below.
        $key = self::pullKey($workspace->id, $pr);
        $answer = Cache::get($key);
        if ($answer === null) {
            $answer = (function () use ($workspace, $owner, $repo, $number): array {
                $response = $this->api($workspace, $owner, $repo)->get("https://api.github.com/repos/{$owner}/{$repo}/pulls/{$number}");
                $json = $response->ok() ? $response->json() : null;
                if (! is_array($json)) {
                    return [];
                }

                return [
                    'state' => (bool) ($json['merged'] ?? false) ? 'merged' : (string) ($json['state'] ?? 'open'),
                    'merged' => (bool) ($json['merged'] ?? false),
                    'mergeSha' => is_string($json['merge_commit_sha'] ?? null) && ($json['merged'] ?? false) ? $json['merge_commit_sha'] : null,
                    'headSha' => is_string($json['head']['sha'] ?? null) ? $json['head']['sha'] : null,
                    'headRef' => is_string($json['head']['ref'] ?? null) ? $json['head']['ref'] : null,
                    'headRepo' => is_string($json['head']['repo']['full_name'] ?? null) ? $json['head']['repo']['full_name'] : null,
                ];
            })();
            Cache::put($key, $answer, 60);
        }

        if (! is_array($answer) || $answer === []) {
            return null;
        }

        // Whatever's cached (an older entry, a partial one) comes back whole.
        return [
            'state' => is_string($answer['state'] ?? null) ? $answer['state'] : 'open',
            'merged' => (bool) ($answer['merged'] ?? false),
            'mergeSha' => is_string($answer['mergeSha'] ?? null) ? $answer['mergeSha'] : null,
            'headSha' => is_string($answer['headSha'] ?? null) ? $answer['headSha'] : null,
            'headRef' => is_string($answer['headRef'] ?? null) ? $answer['headRef'] : null,
            'headRepo' => is_string($answer['headRepo'] ?? null) ? $answer['headRepo'] : null,
        ];
    }

    /**
     * Merges the agent's pull request on GitHub, as a squash.
     *
     * @throws RuntimeException
     */
    public function merge(AgentTask $task, WorkflowRun $run, User $user): string
    {
        [$pr] = $this->verified($task, $run);
        if (isset($run->steps[self::NODE]['merged'])) {
            throw new RuntimeException('It’s already merged.');
        }
        [$owner, $repo, $number] = self::parsePr($pr) ?? throw new RuntimeException('That isn’t a GitHub pull request.');
        $response = $this->api($task->workspace, $owner, $repo, true)->put("https://api.github.com/repos/{$owner}/{$repo}/pulls/{$number}/merge", [
            'merge_method' => 'squash',
            'commit_title' => mb_substr($task->title, 0, 200)." (#{$number})",
        ]);
        if (! $response->ok() || ! is_string($response->json('sha'))) {
            throw new RuntimeException((string) ($response->json('message') ?? 'GitHub didn’t merge it.'));
        }
        $sha = (string) $response->json('sha');
        $this->remember($run, ['merged' => ['sha' => $sha, 'at' => now()->getTimestamp(), 'by' => $user->name]]);
        Cache::forget(self::pullKey($task->workspace_id, $pr));
        // The same message's agents that haven't started yet aren't needed now.
        $turn = $run->trigger['data']['turn'] ?? null;
        if (is_string($turn)) {
            $task->queued()->where('turn', $turn)->delete();
        }
        $this->closeSiblings($task, $run);

        return $sha;
    }

    /**
     * The run's pull request, checked with GitHub to be the one ServerOS
     * opened for it: from this agent's own branch, in the task's repository,
     * not a fork's. What the machine printed is never enough on its own.
     *
     * @return array{0: string, 1: array{state: string, merged: bool, mergeSha: string|null, headSha: string|null, headRef: string|null, headRepo: string|null}}
     *
     * @throws RuntimeException
     */
    private function verified(AgentTask $task, WorkflowRun $run): array
    {
        $pr = self::prFor($task, $run) ?? throw new RuntimeException('This agent didn’t open a pull request in '.$task->repo.'.');
        $github = $this->pullRequest($task->workspace, $pr, true) ?? throw new RuntimeException('GitHub didn’t answer about the pull request. Try again.');
        if ($github['headRef'] !== AgentSpec::pullRequestBranch($run, self::NODE) || strcasecmp((string) $github['headRepo'], (string) $task->repo) !== 0) {
            throw new RuntimeException('That pull request isn’t the one ServerOS opened for this agent, so it won’t act on it.');
        }

        return [$pr, $github];
    }

    /**
     * Agents that tried the same message side by side were alternatives: once
     * one is merged, the others' pull requests are closed, with a note.
     */
    private function closeSiblings(AgentTask $task, WorkflowRun $merged): void
    {
        $turn = $merged->trigger['data']['turn'] ?? null;
        if (! is_string($turn)) {
            return;
        }
        $mergedPr = self::prFor($task, $merged);
        foreach ($task->runs()->whereKeyNot($merged->id)->get() as $run) {
            $pr = self::prFor($task, $run);
            if ($pr === null || ($run->trigger['data']['turn'] ?? null) !== $turn || isset($run->steps[self::NODE]['merged'])) {
                continue;
            }
            [$owner, $repo, $number] = self::parsePr($pr) ?? [null, null, null];
            if ($owner === null) {
                continue;
            }
            try {
                $api = $this->api($task->workspace, $owner, $repo, true);
                $api->post("https://api.github.com/repos/{$owner}/{$repo}/issues/{$number}/comments", [
                    'body' => 'Closed by ServerOS: another agent’s pull request for the same task was merged'.($mergedPr !== null ? " ({$mergedPr})" : '').'.',
                ]);
                $closed = $api->patch("https://api.github.com/repos/{$owner}/{$repo}/pulls/{$number}", ['state' => 'closed']);
                if ($closed->ok()) {
                    $this->remember($run, ['closed' => ['at' => now()->getTimestamp(), 'because' => $merged->id]]);
                    Cache::forget(self::pullKey($task->workspace_id, $pr));
                }
            } catch (RuntimeException) {
                // A pull request left open is the person's to close.
            }
        }
    }

    /**
     * Deploys the merged change wherever this repository was last deployed
     * from, at the merge commit.
     *
     * @throws RuntimeException
     */
    public function deploy(AgentTask $task, WorkflowRun $run, User $user): DaemonJob
    {
        [, $github] = $this->verified($task, $run);
        // Only a change merged into the task's own branch is what runs there.
        if (($run->trigger['data']['base'] ?? $task->branch) !== $task->branch) {
            throw new RuntimeException("This change was merged into another agent’s branch, not {$task->branch}. Merge that one to deploy it.");
        }
        $merged = $run->steps[self::NODE]['merged']['sha'] ?? $github['mergeSha'];
        if (! is_string($merged)) {
            throw new RuntimeException('Merge the pull request first.');
        }
        if (isset($run->steps[self::NODE]['deployJob'])) {
            throw new RuntimeException('It’s already been deployed from here.');
        }
        $last = $this->lastDeployOf($task);
        if ($last === null) {
            throw new RuntimeException("Nothing on your machines is deployed from {$task->repo} yet.");
        }
        $machine = $last->machine;
        if (($reason = $this->queue->unavailable($machine, 'deploy')) !== null) {
            throw new RuntimeException($reason);
        }
        $job = $this->queue->deploy($machine, $user, [...$last->command['job'], 'commit' => $merged]);
        $this->remember($run, ['deployJob' => $job->id]);

        return $job;
    }

    /**
     * Opens the pull request's preview in the project that has previews on
     * for this repository, if GitHub's webhook hasn't already.
     *
     * @throws RuntimeException
     */
    public function startPreview(AgentTask $task, WorkflowRun $run): ProjectEnvironment
    {
        [$pr, $github] = $this->verified($task, $run);
        $project = $this->previewProject($task) ?? throw new RuntimeException("Turn on previews for {$task->repo} in a project first.");
        $head = $github['headSha'];
        if (! is_string($head)) {
            throw new RuntimeException('GitHub didn’t say what the pull request’s latest commit is.');
        }
        $number = (int) (self::parsePr($pr)[2] ?? 0);
        if (($app = Previews::composeApp($project)) !== null) {
            throw new RuntimeException(Previews::composeReason($app));
        }

        return $this->previews->opened($project, $number, $head) ?? throw new RuntimeException('The project couldn’t open another preview. Close one, or raise its limit.');
    }

    /** @param  array<string, mixed>  $values */
    private function remember(WorkflowRun $run, array $values): void
    {
        DB::transaction(function () use ($run, $values) {
            $locked = WorkflowRun::query()->lockForUpdate()->findOrFail($run->id);
            $steps = $locked->steps;
            $steps[self::NODE] = [...($steps[self::NODE] ?? []), ...$values];
            $locked->steps = $steps;
            $locked->save();
            $run->setRawAttributes($locked->getAttributes(), true);
        });
    }

    private function lastDeployOf(AgentTask $task): ?DaemonJob
    {
        if ($task->repo === null) {
            return null;
        }
        $jobs = DaemonJob::query()
            ->whereIn('machine_id', $task->workspace->machines()->select('id'))
            // A deploy only: a workflow's release job carries a token of its own.
            ->where('kind', 'deploy')
            ->where('state', 'succeeded')
            ->with('machine')
            ->latest()
            ->limit(100)
            ->get();

        return $jobs->first(function (DaemonJob $job) use ($task) {
            $repo = is_string($job->command['job']['repo'] ?? null) ? ReleaseSpec::github($job->command['job']['repo']) : null;

            return $repo !== null && strtolower("{$repo[0]}/{$repo[1]}") === strtolower((string) $task->repo);
        });
    }

    private function previewProject(AgentTask $task): ?Project
    {
        if ($task->repo === null) {
            return null;
        }

        return $task->workspace->projects()->whereNotNull('previews')->get()
            ->first(fn (Project $p) => Previews::settings($p)['enabled'] && strtolower(Previews::settings($p)['repo']) === strtolower((string) $task->repo));
    }

    private function api(Workspace $workspace, string $owner, string $repo, bool $write = false): PendingRequest
    {
        $token = $write ? $this->github->writeTokenFor($workspace, $owner, $repo) : $this->github->tokenFor($workspace, $owner, $repo);
        if ($write && $token === null) {
            throw new RuntimeException("ServerOS’s GitHub App can’t reach {$owner}/{$repo}. Connect it in Settings › GitHub.");
        }

        return Http::timeout(5)->connectTimeout(3)->acceptJson()
            ->withHeaders(['User-Agent' => 'ServerOS', 'X-GitHub-Api-Version' => '2022-11-28'])
            ->when($token !== null, fn ($http) => $http->withToken((string) $token));
    }

    /** @return array{0: string, 1: string, 2: string}|null */
    public static function parsePr(string $pr): ?array
    {
        if (preg_match('#^https://github\.com/([A-Za-z0-9_.-]{1,100})/([A-Za-z0-9_.-]{1,100})/pull/(\d{1,9})$#', $pr, $m) !== 1
            || in_array($m[1], ['.', '..'], true) || in_array($m[2], ['.', '..'], true)) {
            return null;
        }

        return [$m[1], $m[2], $m[3]];
    }

    /**
     * The run's pull request, when it's in the task's own repository. A run's
     * PR comes from the machine's output, so one that points anywhere else is
     * ignored: nothing an agent prints can get another repository's pull
     * request merged, deployed or read with the workspace's GitHub access.
     */
    public static function prFor(AgentTask $task, WorkflowRun $run): ?string
    {
        $pr = $run->steps[self::NODE]['pr'] ?? null;
        $parsed = is_string($pr) ? self::parsePr($pr) : null;
        if ($parsed === null || $task->repo === null || strcasecmp("{$parsed[0]}/{$parsed[1]}", $task->repo) !== 0) {
            return null;
        }

        return $pr;
    }

    /**
     * What the Agents table shows for each task.
     *
     * @param  Collection<int, AgentTask>  $tasks
     * @return list<array<string, mixed>>
     */
    public function rows(Collection $tasks): array
    {
        $runs = WorkflowRun::query()
            ->whereIn('workflow_id', $tasks->pluck('workflow_id'))
            ->orderBy('id')
            ->get(['id', 'workflow_id', 'state', 'steps', 'started_at', 'finished_at'])
            ->groupBy('workflow_id');
        $queued = AgentQueued::query()->whereIn('agent_task_id', $tasks->pluck('id'))->get()->groupBy('agent_task_id');

        return array_values($tasks->map(function (AgentTask $task) use ($runs, $queued) {
            $mine = $runs[$task->workflow_id] ?? collect();
            $agents = array_values($mine->map(fn (WorkflowRun $run) => self::brief($run, self::prFor($task, $run)))->all());
            $waiting = count($queued[$task->id] ?? []);

            return [
                ...self::header($task),
                'status' => self::status($agents, $waiting),
                'agents' => $agents,
                'waiting' => $waiting,
            ];
        })->all());
    }

    /** @return array<string, mixed> */
    public function detail(AgentTask $task, bool $remote = true, bool $full = true): array
    {
        $runs = $task->runs()->orderBy('id')->get();
        $jobs = DaemonJob::query()
            ->whereIn('workflow_run_id', $runs->modelKeys())
            ->where('workflow_node', self::NODE)
            ->get()
            ->keyBy('workflow_run_id');
        $deployIds = $runs->map(fn (WorkflowRun $r) => $r->steps[self::NODE]['deployJob'] ?? null)->filter()->values();
        $deploys = DaemonJob::query()->whereIn('id', $deployIds)->with('machine:id,name')->get()->keyBy('id');
        $project = $this->previewProject($task);
        // Only worth the lookup once there's a pull request to deploy.
        // Kept a minute: it reads (and decrypts) recent deploys, and the page polls.
        $anyPr = $runs->contains(fn (WorkflowRun $r) => self::prFor($task, $r) !== null);
        $canDeploy = ! $anyPr ? null : Cache::remember("agent-can-deploy:{$task->id}", 60, function () use ($task) {
            $last = $this->lastDeployOf($task);

            return $last === null ? [] : ['service' => $last->service, 'machine' => $last->machine->name];
        });
        $canDeploy = $canDeploy === [] ? null : $canDeploy;

        $agents = $runs->map(function (WorkflowRun $run) use ($jobs, $deploys, $task, $project, $canDeploy, $remote, $full) {
            $job = $jobs[$run->id] ?? null;
            $outcome = $job !== null ? AgentSpec::outcome($job) : null;
            $pr = self::prFor($task, $run);
            $merged = $run->steps[self::NODE]['merged'] ?? null;
            $deployJob = isset($run->steps[self::NODE]['deployJob']) ? ($deploys[$run->steps[self::NODE]['deployJob']] ?? null) : null;
            $github = $pr !== null && $remote ? $this->pullRequest($task->workspace, $pr) : null;
            // GitHub says the PR comes from some other branch: not one ServerOS opened.
            if ($github !== null && $github['headRef'] !== null && $github['headRef'] !== AgentSpec::pullRequestBranch($run, self::NODE)) {
                $pr = $github = null;
            }
            $feed = $job === null || ! $full ? ['steps' => [], 'plan' => null] : $this->feed($run, $job);

            return [
                ...self::brief($run, $pr),
                // Failures can quote the machine's errors.
                'message' => $full || $run->state !== 'failed' ? self::brief($run, $pr)['message'] : null,
                // The agent's answer can quote what it read on the machine.
                'summary' => $full ? (string) ($run->steps[self::NODE]['summary'] ?? $outcome['summary'] ?? '') : '',
                'model' => $run->steps[self::NODE]['model'] ?? $outcome['model'] ?? null,
                'stat' => $outcome['stat'] ?? null,
                'error' => $full ? ($outcome['error'] ?? null) : null,
                'notes' => $run->trigger['data']['notes'] ?? null,
                'followsOn' => isset($run->trigger['data']['previous']),
                'after' => is_int($run->trigger['data']['after'] ?? null) ? $run->trigger['data']['after'] : null,
                'turn' => (string) ($run->trigger['data']['turn'] ?? "run-{$run->id}"),
                'by' => is_string($run->trigger['data']['by'] ?? null) ? $run->trigger['data']['by'] : null,
                'base' => (string) ($run->trigger['data']['base'] ?? ''),
                'prBranch' => $pr !== null ? AgentSpec::pullRequestBranch($run, self::NODE) : null,
                'prState' => $pr === null ? null : (self::prState($run) === 'open' ? ($github['state'] ?? 'open') : self::prState($run)),
                'merged' => is_array($merged) ? ['sha' => substr((string) $merged['sha'], 0, 7), 'by' => $merged['by'] ?? null, 'at' => isset($merged['at']) ? date(DATE_ATOM, (int) $merged['at']) : null] : null,
                'deploy' => $deployJob === null ? null : [
                    'id' => $deployJob->id,
                    'state' => $deployJob->state,
                    'service' => $deployJob->service,
                    'machine' => ['id' => $deployJob->machine_id, 'name' => $deployJob->machine->name],
                ],
                'canDeploy' => $canDeploy,
                'preview' => $pr !== null ? self::preview($project, (int) (self::parsePr($pr)[2] ?? 0)) : null,
                // What it looks like, when the agent took screenshots.
                'shots' => $full ? array_values(array_filter((array) ($run->steps[self::NODE]['shots'] ?? []), 'is_array')) : [],
                'machineJob' => $job?->id,
                'cost' => $job === null ? null : self::cost($job),
                // What ran on the machine can hold secrets: like a workflow's
                // command output, it's for people who manage machines.
                'log' => $job === null || ! $full ? [] : self::log($job),
                ...$feed,
            ];
        })->all();
        $agents = array_values($agents);

        $waiting = $task->queued()->get()->map(fn (AgentQueued $q) => [
            'turn' => $q->turn,
            'notes' => $q->notes,
            'after' => $q->after_run_id,
            'tonight' => $q->quiet,
            'at' => $q->not_before?->toIso8601String(),
        ])->values()->all();

        return [
            ...self::header($task),
            'prompt' => $task->prompt,
            'context' => $task->context === null ? null : ['kind' => $task->context['kind'], 'label' => $task->context['label'], 'text' => $full ? $task->context['text'] : ''],
            'service' => $task->service,
            'status' => self::status($agents, count($waiting)),
            'agents' => $agents,
            'waiting' => $waiting,
            'capacity' => ['slots' => self::slots($task->machine), 'running' => self::running($task->machine)],
        ];
    }

    /** @return array{state: string, url: string|null, project: array{id: int, name: string}|null} */
    private static function preview(?Project $project, int $number): array
    {
        if ($project === null || $number < 1) {
            return ['state' => 'off', 'url' => null, 'project' => null];
        }
        $settings = Previews::settings($project);
        $environment = $project->environments()->where('pull_request', $number)->first();
        $app = $project->production()->nodes()->where('kind', 'app')->value('name');
        $url = $settings['domain'] !== null && is_string($app) ? 'https://'.Previews::address($app, $number, $settings['domain']) : null;

        return [
            'state' => $environment === null ? 'none' : ($environment->removing_at !== null ? 'closing' : 'live'),
            'url' => $environment === null ? null : $url,
            'project' => ['id' => $project->id, 'name' => $project->name],
        ];
    }

    /** @return array<string, mixed> */
    private static function header(AgentTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'agent' => $task->agent,
            'agentName' => AgentSpec::AGENTS[$task->agent] ?? $task->agent,
            'model' => $task->model,
            'repo' => $task->repo,
            'branch' => $task->branch,
            'pullRequest' => $task->pull_request,
            'context' => $task->context === null ? null : ['kind' => $task->context['kind'], 'label' => $task->context['label']],
            'machine' => ['id' => $task->machine_id, 'name' => $task->machine->name],
            'user' => $task->user?->name,
            'createdAt' => $task->created_at?->toIso8601String(),
            'updatedAt' => $task->updated_at?->toIso8601String(),
        ];
    }

    /** open, merged or closed, from what ServerOS did with the run's pull request. */
    private static function prState(WorkflowRun $run): string
    {
        return match (true) {
            isset($run->steps[self::NODE]['merged']) => 'merged',
            isset($run->steps[self::NODE]['closed']) => 'closed',
            default => 'open',
        };
    }

    /** @return array{run: int, state: string, phase: string|null, message: string|null, pr: string|null, prState: string|null, startedAt: string, seconds: int} */
    private static function brief(WorkflowRun $run, ?string $pr): array
    {
        $step = $run->steps[self::NODE] ?? [];

        return [
            'run' => $run->id,
            'state' => $run->state,
            'phase' => is_string($step['phase'] ?? null) ? $step['phase'] : null,
            'message' => is_string($step['message'] ?? null) ? $step['message'] : null,
            'pr' => $pr,
            'prState' => $pr === null ? null : self::prState($run),
            'startedAt' => $run->started_at->toIso8601String(),
            'seconds' => (int) $run->started_at->diffInSeconds($run->finished_at ?? now()),
        ];
    }

    /**
     * running: an agent is still working. queued: they're all waiting for
     * room or for tonight. review: a pull request is open. merged: one was
     * merged and none is left open. done: they finished without one.
     * failed: every agent failed.
     *
     * @param  list<array{state: string, pr: string|null, prState?: string|null}>  $agents
     */
    private static function status(array $agents, int $waiting = 0): string
    {
        $states = array_column($agents, 'state');
        $prs = array_filter(array_column($agents, 'prState'));

        return match (true) {
            in_array('running', $states, true) => 'running',
            $waiting > 0 => 'queued',
            $agents === [] => 'new',
            in_array('open', $prs, true) => 'review',
            in_array('merged', $prs, true) => 'merged',
            array_diff($states, ['failed', 'cancelled']) === [] => in_array('failed', $states, true) ? 'failed' : 'stopped',
            default => 'done',
        };
    }

    /**
     * The machine's output, without ServerOS's own markers or the change it
     * hands back.
     *
     * @return list<string>
     */
    private static function log(DaemonJob $job): array
    {
        $lines = [];
        foreach (array_filter($job->log ?? [], 'is_string') as $line) {
            $line = rtrim($line);
            if (str_starts_with($line, AgentSpec::SUMMARY)) {
                break;
            }
            if (str_starts_with($line, '::serveros-')) {
                continue;
            }
            $lines[] = mb_substr($line, 0, 2000);
        }

        return array_slice($lines, -400);
    }

    /**
     * The step feed, kept on the run as it grows: the machine's log only
     * keeps its last 500 lines, so early steps would otherwise fall off.
     *
     * @return array{steps: list<array{k: string, t: string, d: string|null}>, plan: list<array{t: string, s: string}>|null}
     */
    private function feed(WorkflowRun $run, DaemonJob $job): array
    {
        $fresh = self::steps($job);
        $stored = $run->steps[self::NODE]['feed'] ?? null;
        $steps = is_array($stored) ? self::joinFeed(array_values($stored), $fresh['steps']) : $fresh['steps'];
        $kept = $run->steps[self::NODE]['plan'] ?? null;
        $plan = $fresh['plan'] ?? (is_array($kept) ? array_values(array_map(fn ($t) => [
            't' => (string) ($t['t'] ?? ''),
            's' => (string) ($t['s'] ?? 'pending'),
        ], array_filter($kept, 'is_array'))) : null);
        if ($steps !== $stored || $plan !== $kept) {
            // Only a copy of the machine's log, kept so early steps don't fall
            // off: a page that can't save it now still shows it, and the next
            // look saves it.
            try {
                $this->remember($run, ['feed' => $steps, 'plan' => $plan]);
            } catch (QueryException $e) {
                report($e);
            }
        }

        return ['steps' => $steps, 'plan' => $plan];
    }

    /**
     * What was kept, then what's new: the fresh read starts somewhere inside
     * what was kept, so it's joined where they overlap.
     *
     * @param  list<array{k: string, t: string, d: string|null}>  $kept
     * @param  list<array{k: string, t: string, d: string|null}>  $fresh
     * @return list<array{k: string, t: string, d: string|null}>
     */
    private static function joinFeed(array $kept, array $fresh): array
    {
        if ($kept === [] || $fresh === []) {
            return $kept === [] ? $fresh : $kept;
        }
        $n = count($kept);
        for ($at = 0; $at < $n; $at++) {
            $tail = array_slice($kept, $at);
            if ($tail === array_slice($fresh, 0, count($tail))) {
                return array_slice([...array_slice($kept, 0, $at), ...$fresh], -400);
            }
        }

        return array_slice([...$kept, ...$fresh], -400);
    }

    /** What the agent's model calls cost, in dollars, when the agent says. */
    private static function cost(DaemonJob $job): ?float
    {
        $cost = null;
        foreach (array_filter($job->log ?? [], 'is_string') as $line) {
            if (str_starts_with($line, AgentSpec::COST) && is_numeric($value = trim(substr($line, strlen(AgentSpec::COST))))) {
                $cost = max(0.0, min(10_000.0, (float) $value));
            }
        }

        return $cost;
    }

    /**
     * What the agent did, step by step, as the machine reported it: files it
     * read and edited, commands it ran, and its latest plan.
     *
     * @return array{steps: list<array{k: string, t: string, d: string|null}>, plan: list<array{t: string, s: string}>|null}
     */
    private static function steps(DaemonJob $job): array
    {
        $steps = [];
        $plan = null;
        foreach (array_filter($job->log ?? [], 'is_string') as $line) {
            if (! str_starts_with($line, AgentSpec::STEP)) {
                continue;
            }
            $step = json_decode(trim(substr($line, strlen(AgentSpec::STEP))), true);
            if (! is_array($step) || ! is_string($step['k'] ?? null)) {
                continue;
            }
            if ($step['k'] === 'plan') {
                $plan = array_values(array_map(fn ($t) => [
                    't' => mb_substr((string) ($t['t'] ?? ''), 0, 200),
                    's' => in_array($t['s'] ?? null, ['pending', 'in_progress', 'completed'], true) ? $t['s'] : 'pending',
                ], array_filter((array) ($step['todos'] ?? []), 'is_array')));

                continue;
            }
            $steps[] = [
                'k' => mb_substr($step['k'], 0, 16),
                't' => mb_substr((string) ($step['t'] ?? ''), 0, 300),
                'd' => is_string($step['d'] ?? null) ? mb_substr($step['d'], 0, 160) : null,
            ];
        }

        return ['steps' => array_slice($steps, -300), 'plan' => $plan];
    }

    private static function titleFrom(string $prompt): string
    {
        $first = trim((string) strtok($prompt, "\n"));

        return Str::limit($first !== '' ? $first : 'Agent task', 80, '…');
    }
}
