<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\AgentTask;
use App\Models\Machine;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Support\Agents\AgentContext;
use App\Support\Agents\AgentTasks;
use App\Support\Agents\PullRequestsUnavailable;
use App\Support\Audit\Audited;
use App\Support\Extensions\AgentHosting;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use App\Support\Workflows\AgentShots;
use App\Support\Workflows\AgentSpec;
use App\Support\Workflows\ReleaseSpec;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

class AgentTaskController extends Controller
{
    public function index(Request $request, Workspace $workspace, AgentTasks $tasks, AgentHosting $hosting): Response
    {
        $this->enabled();
        $list = AgentTask::query()->where('workspace_id', $workspace->id)->with(['machine:id,name', 'user:id,name'])->latest('updated_at')->limit(200)->get();
        $from = $request->string('from')->toString();
        $context = $from !== '' && Gate::allows('manageMachines', $workspace) ? AgentContext::resolve($workspace, $from) : null;

        return Inertia::render('workspace/Agents', [
            'workspace' => $workspace->toPageProps(),
            'tasks' => $tasks->rows($list),
            'boxes' => fn () => AgentTasks::machines($workspace),
            // The sidebar's AI keys dialog, as on Settings › AI and MCP.
            'aiKeys' => fn () => WorkspaceAiController::props($workspace, Gate::allows('manageSettings', $workspace)),
            // Somewhere to run agents without a machine of their own, when a package offers it.
            'hosting' => fn () => Gate::allows('manageMachines', $workspace) ? $hosting->offer($workspace, $request->user()) : null,
            'context' => $context === null ? null : [
                'ref' => $context['ref'],
                'kind' => $context['kind'],
                'label' => $context['label'],
                'preview' => mb_substr($context['text'], -1200),
                'lines' => substr_count($context['text'], "\n") + 1,
                'prompt' => $context['prompt'],
                'repo' => $context['repo'],
                'branch' => $context['branch'],
                'machineId' => $context['machine_id'],
            ],
            'planAllows' => $workspace->hasAutomations(),
            'twoFactor' => $request->user()->hasEnabledTwoFactorAuthentication(),
        ]);
    }

    public function show(Request $request, Workspace $workspace, AgentTask $task, AgentTasks $tasks, AgentHosting $hosting): Response
    {
        $this->enabled();
        $this->own($workspace, $task);
        $task->load(['machine:id,workspace_id,name,facts,uid,last_seen_at,disconnected_at,hosted_by,inventory', 'user:id,name']);

        return Inertia::render('workspace/AgentTaskShow', [
            'workspace' => $workspace->toPageProps(),
            'task' => $tasks->detail($task, full: Gate::allows('manageMachines', $workspace)),
            // The rail of other tasks, like a chat app's history.
            'tasks' => fn () => $tasks->rows(AgentTask::query()->where('workspace_id', $workspace->id)->with(['machine:id,name', 'user:id,name'])->latest('updated_at')->limit(60)->get()),
            'canManage' => Gate::allows('manageMachines', $workspace),
            'twoFactor' => $request->user()->hasEnabledTwoFactorAuthentication(),
            'maxAgents' => AgentTask::MAX_AGENTS,
            'aiKeys' => fn () => WorkspaceAiController::props($workspace, Gate::allows('manageSettings', $workspace)),
            // A package's machine still being made for it: the setup screen.
            'setup' => fn () => $hosting->progress($task),
        ]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace, AgentTasks $tasks, AgentHosting $hosting): RedirectResponse
    {
        $this->enabled();
        Gate::authorize('manageMachines', $workspace);
        // Agents on ServerOS's machines need no plan: they're paid in agent time.
        if (! $request->boolean('hosted')) {
            $this->canRun($request, $workspace);
        }

        $input = $request->validate([
            'prompt' => ['required', 'string', 'max:'.AgentSpec::MAX_PROMPT],
            'title' => ['nullable', 'string', 'max:160'],
            'hosted' => ['boolean'],
            'machine_id' => ['required_unless:hosted,true', 'nullable', 'integer'],
            'service' => ['required_unless:hosted,true', 'nullable', 'string', 'max:255', 'starts_with:docker:'],
            'agent' => ['required', Rule::in(array_keys(AgentSpec::AGENTS))],
            'model' => ['nullable', 'string', 'max:80', 'regex:#^[A-Za-z0-9._:/\[\]-]{1,80}$#'],
            'repo' => ['nullable', 'string', 'max:200'],
            'branch' => ['nullable', 'string', 'max:200'],
            'pull_request' => ['boolean'],
            'agents' => ['required', 'integer', 'min:1', 'max:'.AgentTask::MAX_AGENTS],
            'setup' => ['nullable', 'string', 'max:500'],
            'check' => ['nullable', 'string', 'max:500'],
            'minutes' => ['nullable', 'integer', 'min:'.AgentSpec::MIN_MINUTES, 'max:'.AgentSpec::MAX_MINUTES],
            'context' => ['nullable', 'string', 'max:200'],
            'when' => ['nullable', Rule::in(['now', 'tonight'])],
        ], ['model.regex' => 'That isn’t a model name, like opus or gpt-5-codex.', 'service.starts_with' => 'Agents run inside containers: choose one.']);

        $repo = trim((string) ($input['repo'] ?? ''));
        if ($repo !== '') {
            $parsed = ReleaseSpec::github($repo);
            if ($parsed === null) {
                throw ValidationException::withMessages(['repo' => 'Enter the repository as owner/name, or its GitHub URL.']);
            }
            $input['repo'] = "{$parsed[0]}/{$parsed[1]}";
        }
        if (! ReleaseSpec::validBranch(trim((string) ($input['branch'] ?? '')) ?: 'main')) {
            throw ValidationException::withMessages(['branch' => 'That isn’t a branch name.']);
        }
        if ($input['agent'] === 'opencode' && blank($input['model'] ?? null)) {
            throw ValidationException::withMessages(['model' => 'Choose OpenCode’s model, like ollama/qwen3-coder:30b.']);
        }
        $input['pull_request'] = (bool) ($input['pull_request'] ?? false);
        $context = filled($input['context'] ?? null) ? AgentContext::resolve($workspace, (string) $input['context']) : null;
        if (filled($input['context'] ?? null) && $context === null) {
            throw ValidationException::withMessages(['prompt' => 'What this task started from is gone.']);
        }

        if ($request->boolean('hosted')) {
            try {
                [$machine, $service] = $hosting->place($workspace, $request->user(), (int) $input['agents']);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['prompt' => $e->getMessage()]);
            }
            $input['machine_id'] = $machine->id;
            $input['service'] = $service;
        }

        try {
            // All or nothing: a task the line has no room for isn't left behind.
            $task = DB::transaction(function () use ($tasks, $workspace, $request, $input, $context) {
                /** @var array{prompt: string, title?: string|null, machine_id: int, service: string, agent: string, model?: string|null, repo?: string|null, branch?: string|null, pull_request: bool, agents: int, setup?: string|null, check?: string|null, minutes?: int|null} $input */
                $task = $tasks->create($workspace, $request->user(), $input, $context);
                $tasks->request($task, $request->user(), $task->agents, when: (string) ($request->input('when') ?: 'now'));
                Activity::record($workspace, 'agent.task', $task->title, null, $request->user(), ['task' => $task->id, 'agents' => $task->agents]);

                return $task;
            });
        } catch (PullRequestsUnavailable $e) {
            throw ValidationException::withMessages(['pull_request' => $e->getMessage()]);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['prompt' => $e->getMessage()]);
        }

        return to_route('workspace.agents.show', ['workspace' => $workspace->slug, 'task' => $task->id]);
    }

    #[Audited]
    public function more(Request $request, Workspace $workspace, AgentTask $task, AgentTasks $tasks): RedirectResponse
    {
        $this->enabled();
        $this->own($workspace, $task);
        Gate::authorize('manageMachines', $workspace);
        if ($task->machine->hosted_by === null) {
            $this->canRun($request, $workspace);
        }

        $input = $request->validate([
            'agents' => ['required', 'integer', 'min:1', 'max:'.AgentTask::MAX_AGENTS],
            'notes' => ['nullable', 'string', 'max:4000'],
            'after' => ['nullable', 'integer'],
            'when' => ['nullable', Rule::in(['now', 'tonight'])],
        ]);
        $after = isset($input['after']) ? $task->runs()->find($input['after']) : null;
        if (isset($input['after']) && ! $after instanceof WorkflowRun) {
            throw ValidationException::withMessages(['notes' => 'That agent isn’t part of this task.']);
        }
        if ($after !== null && blank($input['notes'] ?? null)) {
            throw ValidationException::withMessages(['notes' => 'Say what to change.']);
        }

        try {
            $asked = $tasks->request($task, $request->user(), (int) $input['agents'], $input['notes'] ?? null, $after, (string) ($input['when'] ?? 'now'));
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['notes' => $e->getMessage()]);
        }
        Activity::record($workspace, $after !== null ? 'agent.follow_up' : 'agent.more', $task->title, null, $request->user(), [
            'started' => count($asked['started']), 'waiting' => $asked['queued'],
        ]);

        return back();
    }

    public function changes(Workspace $workspace, AgentTask $task, int $run, AgentTasks $tasks): JsonResponse
    {
        $this->enabled();
        $this->own($workspace, $task);
        Gate::authorize('manageMachines', $workspace);
        $found = $task->runs()->findOrFail($run);
        $pr = AgentTasks::prFor($task, $found);
        if ($pr === null) {
            return response()->json(['pr' => null, 'files' => []]);
        }
        $files = $tasks->pullRequestFiles($workspace, $pr);

        return response()->json(['pr' => $pr, 'files' => $files ?? [], 'unavailable' => $files === null]);
    }

    #[Audited]
    public function merge(Request $request, Workspace $workspace, AgentTask $task, int $run, AgentTasks $tasks): RedirectResponse
    {
        return $this->act($request, $workspace, $task, $run, function (WorkflowRun $found) use ($task, $tasks, $request, $workspace) {
            $sha = $tasks->merge($task, $found, $request->user());
            Activity::record($workspace, 'agent.merge', $task->title, null, $request->user(), ['commit' => substr($sha, 0, 7)]);
        });
    }

    #[Audited]
    public function deploy(Request $request, Workspace $workspace, AgentTask $task, int $run, AgentTasks $tasks): RedirectResponse
    {
        return $this->act($request, $workspace, $task, $run, fn (WorkflowRun $found) => $tasks->deploy($task, $found, $request->user()));
    }

    #[Audited]
    public function preview(Request $request, Workspace $workspace, AgentTask $task, int $run, AgentTasks $tasks): RedirectResponse
    {
        return $this->act($request, $workspace, $task, $run, fn (WorkflowRun $found) => $tasks->startPreview($task, $found));
    }

    /** The deploy dialog's templates for one machine, to set up a Coding agent from the Agents page. */
    public function templates(Workspace $workspace, Machine $machine, ServiceTemplateCatalog $templates): JsonResponse
    {
        $this->enabled();
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        return response()->json(['templates' => $templates->toPageProps($machine)]);
    }

    #[Audited]
    public function stop(Request $request, Workspace $workspace, AgentTask $task, AgentTasks $tasks): RedirectResponse
    {
        $this->enabled();
        $this->own($workspace, $task);
        Gate::authorize('manageMachines', $workspace);

        $tasks->stop($task, $request->user());

        return back();
    }

    #[Audited]
    public function destroy(Workspace $workspace, AgentTask $task): RedirectResponse
    {
        $this->enabled();
        $this->own($workspace, $task);
        Gate::authorize('manageMachines', $workspace);
        if ($task->runs()->where('state', 'running')->exists()) {
            throw ValidationException::withMessages(['task' => 'Stop its agents before you delete it.']);
        }

        // Its workflow goes too, with every run of it and their screenshots.
        $workflow = $task->workflow;
        $task->runs()->get()->each(fn (WorkflowRun $run) => AgentShots::forget($run));
        $task->delete();
        $workflow->delete();
        Activity::record($workspace, 'agent.task.delete', $task->title);

        return to_route('workspace.agents.index', ['workspace' => $workspace->slug]);
    }

    /** @param  callable(WorkflowRun): mixed  $action */
    private function act(Request $request, Workspace $workspace, AgentTask $task, int $run, callable $action): RedirectResponse
    {
        $this->enabled();
        $this->own($workspace, $task);
        Gate::authorize('manageMachines', $workspace);
        if (! $request->user()->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages(['task' => 'Turn on two-factor authentication to merge and deploy.']);
        }
        $found = $task->runs()->findOrFail($run);

        try {
            $action($found);
        } catch (RuntimeException $e) {
            throw ValidationException::withMessages(['task' => $e->getMessage()]);
        }

        return back();
    }

    private function canRun(Request $request, Workspace $workspace): void
    {
        if (! $workspace->hasAutomations()) {
            throw ValidationException::withMessages(['prompt' => 'Agents come with Pro. Choose Pro or Scale to use them.']);
        }
        if (! $request->user()->hasEnabledTwoFactorAuthentication()) {
            throw ValidationException::withMessages(['prompt' => 'Turn on two-factor authentication to run agents.']);
        }
    }

    private function enabled(): void
    {
        abort_unless((bool) config('serveros.agents'), 404);
    }

    private function own(Workspace $workspace, AgentTask $task): void
    {
        abort_unless($task->workspace_id === $workspace->id, 404);
    }
}
