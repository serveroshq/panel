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
    private const REF_RULES = ['nullable', 'string', 'max:255'];

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

        $data = $request->validate([...DeployRequest::gitRules(), 'commit' => self::REF_RULES]);

        DeployRequest::assertRepo($data['repo'], $data['commit'] ?? null);
        DeployRequest::assertWildcard($machine, $data['domain'] ?? null, (bool) ($data['wildcard'] ?? false));
        DeployRequest::assertBaseDir($machine, $data['base_dir'] ?? null);
        DeployRequest::assertDocker($machine, $request->user());
        $proxy = DeployRequest::proxy($machine, $data['domain'] ?? null, $data['proxy'] ?? null);
        $https = DeployRequest::https($machine, $data['domain'] ?? null, $data['https'] ?? null);
        $data['commit'] = $this->resolveCommit($data['repo'], $data['commit'] ?? null, $workspace, $refs);

        $job = DeployRequest::withBaseDir(DeployRequest::withHttps(DeployRequest::withProxy(DeployRequest::gitJob($data), $proxy), $https), $data['base_dir'] ?? null);
        $response = $this->dispatch($request, $machine, $queue, $job);

        return $response->getStatusCode() === 202
            ? response()->json([...$response->getData(true), 'commit' => $data['commit']], 202)
            : $response;
    }

    public function taken(Workspace $workspace, Machine $machine): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        return response()->json([
            ...Placement::taken($machine),
            'proxy' => $machine->proxy,
            'canChooseProxy' => DaemonJobQueue::canChooseProxy($machine),
            'canChooseHttps' => DaemonJobQueue::canChooseHttps($machine),
            'freeAddress' => DeployRequest::freeAddressBase($machine),
        ]);
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

        $rules = DeployRequest::templateRules($template);
        $data = $request->validate($template->isApp() ? [...$rules, 'commit' => self::REF_RULES] : $rules);

        DeployRequest::assertWildcard($machine, $template->web ? ($data['domain'] ?? null) : null, (bool) ($data['wildcard'] ?? false));
        DeployRequest::assertComposeFromRepo($machine, $template);
        DeployRequest::assertBaseDir($machine, $data['base_dir'] ?? null);
        DeployRequest::assertDocker($machine, $request->user());
        $proxy = DeployRequest::proxy($machine, $template->web ? ($data['domain'] ?? null) : null, $data['proxy'] ?? null);
        $https = DeployRequest::https($machine, $template->web ? ($data['domain'] ?? null) : null, $data['https'] ?? null);
        if ($template->isApp()) {
            DeployRequest::assertRepo($data['repo'], $data['commit'] ?? null);
            $data['commit'] = $this->resolveCommit($data['repo'], $data['commit'] ?? null, $workspace, $refs);
        }

        $deployment = new TemplateDeployment($template, $machine, $data);
        $job = DeployRequest::withBaseDir(DeployRequest::withHttps(DeployRequest::withProxy($deployment->job(), $proxy), $https), $data['base_dir'] ?? null);
        $response = $this->dispatch($request, $machine, $queue, $job);

        if ($response->getStatusCode() !== 202) {
            return $response;
        }

        AppInstall::record($machine, $request->user(), $template->slug, $response->getData(true)['jobId'] ?? null, 'panel');

        return response()->json([
            ...$response->getData(true),
            ...DeployRequest::templateResult($deployment, $data),
            'commit' => $template->isApp() ? $deployment->commit() : null,
        ], 202);
    }

    /**
     * What to deploy can be a branch, a tag or a commit, and is the default
     * branch's latest commit when left out. Either way the job gets the
     * exact commit it stands for now, as the MCP deploy tools do. It runs
     * after the cheap checks, so a refused deploy doesn't call GitHub.
     */
    private function resolveCommit(string $repo, ?string $ref, Workspace $workspace, RefResolver $refs): string
    {
        try {
            return $refs->resolve($repo, $ref, $workspace)['commit'];
        } catch (ToolError $e) {
            throw ValidationException::withMessages(['commit' => $e->getMessage()]);
        }
    }

    /** @param  array<string, mixed>  $job */
    private function dispatch(Request $request, Machine $machine, DaemonJobQueue $queue, array $job): JsonResponse
    {
        try {
            $record = $queue->deploy($machine, $request->user(), $job);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(['jobId' => $record->id, 'state' => 'queued'], 202);
    }
}
