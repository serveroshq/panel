<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Deploys\AppCommits;
use App\Support\Deploys\AppDeploys;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\DeployRequest;
use App\Support\Deploys\GitRemote;
use RuntimeException;

final class DeployNewVersion extends Tool
{
    public function __construct(
        private readonly Deploys $deploys,
        private readonly AppCommits $commits,
    ) {}

    public function name(): string
    {
        return 'deploy_new_version';
    }

    public function title(): string
    {
        return 'Deploy a new version of an app';
    }

    public function description(): string
    {
        return 'Ships new code for an app ServerOS already built from code on a machine: its latest deploy (even one that failed) is sent again with only the code changed, so its domain, settings, port and health check stay as they are. The new version starts beside the live one and only takes over once it passes its health check; if it fails, the old one keeps serving. '
            .'Check with the person first: say the app, the machine and the commit. '
            .'app is the name it was deployed as (list_deploys or get_app; "app:<name>" and its service key work too). ref is a branch, tag or full commit of its repository (default: the latest commit of the branch it follows, get_app’s branch), resolved to an exact commit; an SSH repository is looked up by the machine with the app’s deploy key. '
            .'Apps whose code was uploaded as a .zip keep their upload address private: give repo (an HTTPS Git repository (public, or a private GitHub one the workspace has connected)) to build from that instead, or upload the new .zip on the app’s page in the dashboard. repo also moves a Git app to another repository. '
            .'Deploying the commit that’s already live is refused: to run the same version again, use redeploy_app. Returns the queued job; follow it with get_job or list_deploys.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'app' => Schema::string('The app: the name it was deployed as (like shop), or app:<name>.', maxLength: 300),
            'ref' => Schema::string('The branch, tag or full 40-character commit to deploy. Defaults to the default branch’s latest commit.', maxLength: 255),
            'repo' => Schema::string('Build from this HTTPS Git repository (public, or a private GitHub one the workspace has connected) instead of the app’s current one (needed for apps uploaded as a .zip).', maxLength: 500),
            'wait' => Deploys::waitSchema(),
        ], ['machine', 'app']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $args = $this->validate($arguments, [
            'machine' => ['required', Deploys::reference()],
            'app' => ['required', Deploys::reference()],
            'ref' => ['sometimes', 'nullable', 'string', 'max:255'],
            'repo' => ['sometimes', 'nullable', 'string', 'url', 'max:500'],
            'wait' => Deploys::waitRules(),
        ], ['repo.url' => 'repo must be a URL, like https://github.com/you/app.git.']);

        $machine = $context->machine($args['machine']);
        $app = Deploys::appName($context, $machine, $args['app']);

        $last = AppDeploys::spec($machine, $app);
        if ($last === null || AppDeploys::isImage($last)) {
            throw new ToolError("{$app} on {$machine->name} wasn’t deployed from code, so it has no code to replace. Ready-made stacks and images are redeployed with redeploy_app, or deployed again with deploy_template.");
        }

        $this->deploys->requireAvailable($machine);
        if (($busy = AppDeploys::busy($machine, $app)) !== null) {
            throw new ToolError("{$app} is deploying now (job {$busy->id}). Wait for it to finish first.");
        }

        $current = (string) ($last['repo'] ?? '');
        $repo = filled($args['repo'] ?? null) ? trim((string) $args['repo']) : null;
        if ($repo === null) {
            if (Deploys::fromUpload($current)) {
                throw new ToolError("{$app}’s code was uploaded as a .zip, so there’s no repository to fetch a new version from. Upload the new version on its page in the dashboard (".$context->url("/machines/{$machine->id}/services/app:{$app}").'), or give repo: an HTTPS Git repository (public, or a private GitHub one the workspace has connected) to build it from instead.');
            }
            $repo = $current;
        }
        if (! GitRemote::isSsh($repo) || $repo !== $current) {
            Deploys::check(fn () => DeployRequest::assertPublicRepo($repo));
        }

        $ref = $args['ref'] ?? ($repo === $current ? AppDeploys::branch($machine, $app) : null);
        try {
            $resolved = $this->commits->resolve($machine, $context->user, $app, [...$last, 'repo' => $repo], $ref);
        } catch (RuntimeException $e) {
            throw new ToolError($e->getMessage());
        }
        $live = strtolower((string) (AppReleases::live($machine)[$app] ?? ''));
        if ($live !== '' && str_starts_with($resolved['commit'], $live) && $repo === $current) {
            throw new ToolError("{$app} is already running ".substr($live, 0, 7).($resolved['ref'] !== null ? " (the latest on {$resolved['ref']})" : '').'. To deploy the same version again, use redeploy_app.');
        }

        $spec = [...$last, 'repo' => $repo, 'commit' => $resolved['commit']];
        $job = $this->deploys->deploy($context, $machine, $spec);
        $job = $context->waitForJob($job, (int) ($args['wait'] ?? 0));

        return [
            'jobId' => $job->id,
            'state' => $job->state,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'app' => $app,
            'repo' => $repo,
            'ref' => $resolved['ref'],
            'commit' => $resolved['commit'],
            'previousCommit' => $live === '' ? null : $live,
            'domains' => array_values($spec['domains'] ?? []),
            'job' => Present::job($job, 20),
            'deploymentsUrl' => $context->url("/machines/{$machine->id}?job={$job->id}"),
            'next' => 'The new version builds beside the live one and takes over once it passes its health check. Follow it with get_job (id '.$job->id.') or list_deploys.',
        ];
    }
}
