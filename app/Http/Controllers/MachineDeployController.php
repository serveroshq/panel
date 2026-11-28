<?php

namespace App\Http\Controllers;

use App\Mcp\ToolError;
use App\Mcp\Tools\Deploy\RefResolver;
use App\Models\AppInstall;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppDeploys;
use App\Support\Deploys\BuildSettings;
use App\Support\Deploys\DeployRequest;
use App\Support\Deploys\GitRemote;
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

        $data = $request->validate([
            ...DeployRequest::gitRules(),
            'commit' => self::REF_RULES,
            'branch' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:'.GitRemote::REF_PATTERN],
            'build' => ['sometimes', 'nullable', 'array'],
            ...BuildSettings::rules('build.'),
            ...BuildSettings::flagRules(),
        ], BuildSettings::messages('build.'));

        DeployRequest::assertRepo($data['repo'], $data['commit'] ?? null);
        DeployRequest::assertWildcard($machine, $data['domain'] ?? null, (bool) ($data['wildcard'] ?? false));
        DeployRequest::assertBaseDir($machine, $data['base_dir'] ?? null);
        DeployRequest::assertDocker($machine, $request->user());
        DeployRequest::assertDomainFree($machine, $data['domain'] ?? null, $data['service']);
        $proxy = DeployRequest::proxy($machine, $data['domain'] ?? null, $data['proxy'] ?? null);
        $https = DeployRequest::https($machine, $data['domain'] ?? null, $data['https'] ?? null);
        $resolved = $this->resolveCommit($data['repo'], $data['commit'] ?? $data['branch'] ?? null, $workspace, $refs);
        $data['commit'] = $resolved['commit'];

        $job = DeployRequest::withBaseDir(DeployRequest::withHttps(DeployRequest::withProxy(DeployRequest::gitJob($data), $proxy), $https), $data['base_dir'] ?? null);
        $job = DeployRequest::gitBuild($machine, $job, $data);
        $response = $this->dispatch($request, $machine, $queue, $job);
        if ($response->getStatusCode() === 202) {
            AppDeploys::rememberBranch($machine, $data['service'], self::branchOf($data, $resolved));
        }

        return $response->getStatusCode() === 202
            ? response()->json([...$response->getData(true), 'commit' => $data['commit'], 'appUrl' => self::appUrl($workspace, $machine, $data['service'])], 202)
            : $response;
    }

    public function taken(Workspace $workspace, Machine $machine): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $build = DaemonJobQueue::canDeployBuild($machine);

        return response()->json([
            ...Placement::taken($machine),
            'proxy' => $machine->proxy,
            'canChooseProxy' => DaemonJobQueue::canChooseProxy($machine),
            'canUseExternalProxy' => DaemonJobQueue::canUseExternalProxy($machine),
            'canChooseHttps' => DaemonJobQueue::canChooseHttps($machine),
            'canDeployBuild' => $build,
            'canInspect' => $build && DaemonJobQueue::canResolveRef($machine),
            'docker' => [
                'installed' => $machine->hasDocker(),
                'canInstall' => $build && $machine->hasDocker() === false,
            ],
            'domains' => self::domains($machine),
            'freeAddress' => DeployRequest::freeAddressBase($machine),
            'machines' => $workspace->machines()->orderBy('name')->get()
                ->map(fn (Machine $other) => [
                    'id' => $other->id,
                    'name' => $other->name,
                    'provider' => $other->provider,
                    'online' => $other->isOnline(),
                    'canDeployBuild' => DaemonJobQueue::canDeployBuild($other),
                ])->values()->all(),
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
        $data = $request->validate($template->isApp() ? [...$rules, 'commit' => self::REF_RULES] : $rules, DeployRequest::templateMessages());

        DeployRequest::assertTemplateSupported($machine, $template);
        if (($free = DeployRequest::freeDomain($machine, $template, $data)) !== null) {
            $data['domain'] = $free;
        }
        DeployRequest::assertWildcard($machine, $template->web ? ($data['domain'] ?? null) : null, (bool) ($data['wildcard'] ?? false));
        DeployRequest::assertComposeFromRepo($machine, $template);
        DeployRequest::assertBaseDir($machine, $data['base_dir'] ?? null);
        DeployRequest::assertDocker($machine, $request->user());
        DeployRequest::assertDomainFree($machine, $template->web ? ($data['domain'] ?? null) : null, $data['service']);
        $proxy = DeployRequest::proxy($machine, $template->web ? ($data['domain'] ?? null) : null, $data['proxy'] ?? null);
        $https = DeployRequest::https($machine, $template->web ? ($data['domain'] ?? null) : null, $data['https'] ?? null);
        $resolved = null;
        if ($template->isApp()) {
            DeployRequest::assertRepo($data['repo'], $data['commit'] ?? null);
            $resolved = $this->resolveCommit($data['repo'], $data['commit'] ?? $data['branch'] ?? null, $workspace, $refs);
            $data['commit'] = $resolved['commit'];
        }

        $deployment = new TemplateDeployment($template, $machine, $data);
        $deployment->check();
        if ($template->composeFromRepo() && $deployment->port === null && filled($data['domain'] ?? null)) {
            throw ValidationException::withMessages(['port' => 'Your Compose file doesn’t publish a port, so there’s nothing for the domain to send visitors to. Publish the web one (like "127.0.0.1:8080:80"), or give the port here.']);
        }
        $job = DeployRequest::withBaseDir(DeployRequest::withHttps(DeployRequest::withProxy($deployment->job(), $proxy), $https), $data['base_dir'] ?? null);
        $response = $this->dispatch($request, $machine, $queue, $job);

        if ($response->getStatusCode() !== 202) {
            return $response;
        }

        AppInstall::record($machine, $request->user(), $template->slug, $response->getData(true)['jobId'] ?? null, 'panel');
        if ($resolved !== null) {
            AppDeploys::rememberBranch($machine, $data['service'], self::branchOf($data, $resolved));
        }

        return response()->json([
            ...$response->getData(true),
            ...DeployRequest::templateResult($deployment, $data),
            'commit' => $template->isApp() ? $deployment->commit() : null,
            'appUrl' => $template->isApp() || $template->isImage() ? self::appUrl($workspace, $machine, $data['service']) : null,
        ], 202);
    }

    /**
     * What to deploy can be a branch, a tag or a commit, and is the default
     * branch's latest commit when left out. Either way the job gets the
     * exact commit it stands for now, as the MCP deploy tools do. It runs
     * after the cheap checks, so a refused deploy doesn't call GitHub.
     *
     * @return array{commit: string, ref: string|null}
     */
    private function resolveCommit(string $repo, ?string $ref, Workspace $workspace, RefResolver $refs): array
    {
        if (GitRemote::isSsh($repo) && is_string($ref) && preg_match(DeployRequest::COMMIT_PATTERN, $ref) === 1) {
            return ['commit' => strtolower($ref), 'ref' => null];
        }
        try {
            return $refs->resolve($repo, $ref, $workspace);
        } catch (ToolError $e) {
            throw ValidationException::withMessages(['commit' => $e->getMessage()]);
        }
    }

    /**
     * The branch an app follows from now on (its push deploys and "deploy
     * the latest commit"): the one the dialog says, or the one it resolved.
     *
     * @param  array<string, mixed>  $data
     * @param  array{commit: string, ref: string|null}  $resolved
     */
    private static function branchOf(array $data, array $resolved): ?string
    {
        if (is_string($data['branch'] ?? null) && $data['branch'] !== '') {
            return $data['branch'];
        }

        return $resolved['ref'];
    }

    private static function appUrl(Workspace $workspace, Machine $machine, string $service): string
    {
        return $workspace->url("/machines/{$machine->id}/services/app:{$service}");
    }

    /**
     * The domains other apps on the machine serve, so the dialog can warn
     * before a deploy is turned down for taking one.
     *
     * @return list<array{domain: string, app: string}>
     */
    private static function domains(Machine $machine): array
    {
        $taken = [];
        foreach (Placement::taken($machine)['services'] as $app) {
            $deploy = Placement::latestDeploy($machine, $app);
            foreach ((array) ($deploy?->command['job']['domains'] ?? []) as $domain) {
                if (is_string($domain) && ! str_starts_with($domain, '*.')) {
                    $taken[] = ['domain' => strtolower($domain), 'app' => $app];
                }
            }
        }

        return $taken;
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
