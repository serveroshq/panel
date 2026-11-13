<?php

namespace App\Http\Controllers;

use App\Mcp\ToolError;
use App\Mcp\Tools\Deploy\RefResolver;
use App\Models\AppInstall;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\DeployRequest;
use App\Support\Projects\Placement;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use App\Support\ServiceTemplates\TemplateDeployment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class MachineDeployController extends Controller
{
    #[Audited]
    public function __invoke(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue, ServiceTemplateCatalog $catalog, RefResolver $refs): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        if (($reason = $queue->unavailable($machine, 'deploy')) !== null) {
            return response()->json(['error' => $reason], config('daemon.commands_enabled') ? 409 : 503);
        }

        if ($request->filled('template')) {
            return $this->deployTemplate($request, $workspace, $machine, $queue, $catalog, $refs);
        }

        if (is_string($domain = $request->input('domain'))) {
            $request->merge(['domain' => DeployRequest::bareDomain($domain)]);
        }

        $this->resolveCommit($request, $workspace, $refs, DeployRequest::gitRules());
        $data = $request->validate(DeployRequest::gitRules());

        DeployRequest::assertWildcard($machine, $data['domain'] ?? null, (bool) ($data['wildcard'] ?? false));

        return $this->dispatch($request, $machine, $queue, DeployRequest::gitJob($data));
    }

    public function taken(Workspace $workspace, Machine $machine): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        return response()->json(Placement::taken($machine));
    }

    private function deployTemplate(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue, ServiceTemplateCatalog $catalog, RefResolver $refs): JsonResponse
    {
        $template = $catalog->find((string) $request->input('template'));
        if ($template === null) {
            throw ValidationException::withMessages(['template' => 'That template doesn’t exist.']);
        }

        if (is_string($domain = $request->input('domain'))) {
            $request->merge(['domain' => DeployRequest::bareDomain($domain)]);
        }

        if ($template->isApp()) {
            $this->resolveCommit($request, $workspace, $refs, DeployRequest::templateRules($template));
        }
        $data = $request->validate(DeployRequest::templateRules($template));

        DeployRequest::assertWildcard($machine, $template->web ? ($data['domain'] ?? null) : null, (bool) ($data['wildcard'] ?? false));

        $deployment = new TemplateDeployment($template, $machine, $data);
        $response = $this->dispatch($request, $machine, $queue, $deployment->job());

        if ($response->getStatusCode() !== 202) {
            return $response;
        }

        AppInstall::record($machine, $request->user(), $template->slug, $response->getData(true)['jobId'] ?? null, 'panel');

        return response()->json([
            ...$response->getData(true),
            ...DeployRequest::templateResult($deployment, $data),
        ], 202);
    }

    /**
     * What to deploy can be a branch, a tag or a commit, and is the default
     * branch's latest commit when left out. Either way the job gets the
     * exact commit it stands for now, as the MCP deploy tools do.
     *
     * @param  array<string, list<string>>  $rules
     */
    private function resolveCommit(Request $request, Workspace $workspace, RefResolver $refs, array $rules): void
    {
        $request->validate([...$rules, 'commit' => ['nullable', 'string', 'max:255']]);
        DeployRequest::assertPublicRepo((string) $request->input('repo'));

        try {
            $resolved = $refs->resolve((string) $request->input('repo'), $request->input('commit'), $workspace);
        } catch (ToolError $e) {
            throw ValidationException::withMessages(['commit' => $e->getMessage()]);
        }

        $request->merge(['commit' => $resolved['commit']]);
    }

    /** @param  array<string, mixed>  $job */
    private function dispatch(Request $request, Machine $machine, DaemonJobQueue $queue, array $job): JsonResponse
    {
        DeployRequest::assertDocker($machine, $request->user());

        try {
            $record = $queue->deploy($machine, $request->user(), $job);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['jobId' => $record->id, 'state' => 'queued'], 202);
    }
}
