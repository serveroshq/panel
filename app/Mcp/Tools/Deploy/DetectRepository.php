<?php

namespace App\Mcp\Tools\Deploy;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\DeployRequest;
use App\Support\Deploys\GitRemote;
use App\Support\ServiceTemplates\RepositoryDetector;
use App\Support\ServiceTemplates\ServiceTemplate;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Illuminate\Validation\ValidationException;

final class DetectRepository extends Tool
{
    public function __construct(
        private readonly RepositoryDetector $detector,
        private readonly ServiceTemplateCatalog $catalog,
    ) {}

    public function name(): string
    {
        return 'detect_repository';
    }

    public function title(): string
    {
        return 'Detect what a repository is';
    }

    public function description(): string
    {
        return 'Looks at a repository, as the dashboard’s deploy dialog does when a link is pasted: reads the files at the root of the latest commit on its default branch (or the branch given) and the manifests that name a framework, and says what it is (a Laravel web app, an Express API, a Vite static site…) and which app template builds it. A GitHub repository (public, or private and shared with the workspace’s GitHub connection) is read through GitHub; one on any other host (an HTTPS address, or an SSH one with the app’s deploy key) is read by the machine given, on an up-to-date daemon. '
            .'Returns detected, template (its key), framework, type, the reasons, the repository’s clone address, the branch and the exact commit it looked at, port (the port its Dockerfile exposes or its Compose file publishes), railpack (the language, framework, package manager and version Railpack would build it with), envExample (the settings a .env.example in it lists, to fill in), a suggested name to deploy it as, and the template’s summary. Code no template knows by name gets auto-build (Railpack); on an older daemon it gets none, and needsUpdate says why. '
            .'Then deploy it with deploy_template (template, repo, ref = the commit returned, so what was checked is what’s deployed, plus php and health_path when it returns them: an older PHP app needs its PHP version; for compose-file, compose_file = composeFile and port = composePort, the port the Compose file publishes; for an app in a folder of a monorepo, base_dir = baseDir; port when it returns one). A /tree/<branch>/<folder> link detects the app in that folder.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'repository' => Schema::string('A GitHub repository: a link like https://github.com/you/app (a /tree/<branch> link works too) or owner/repo.', maxLength: 300),
            'branch' => Schema::string('A branch or tag to look at instead of the default branch.', maxLength: 255),
            'machine' => Schema::string('The machine to deploy to (its id or name): reads a repository that isn’t on GitHub, and says whether its daemon can build with Railpack.', maxLength: 255),
            'name' => Schema::string('The app’s name, for an SSH repository read with that app’s deploy key.', maxLength: 63),
        ], ['repository']);
    }

    public function permission(): WorkspacePermission
    {
        return WorkspacePermission::ManageMachines;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'repository' => ['required', 'string', 'max:500'],
            'branch' => ['sometimes', 'nullable', 'string', 'max:255'],
            'machine' => ['sometimes', 'nullable', Deploys::reference()],
            'name' => ['sometimes', 'nullable', 'string', 'max:63', 'regex:'.DeployRequest::SERVICE_PATTERN],
        ]);
        $machine = filled($data['machine'] ?? null) ? $context->machine($data['machine']) : null;

        $repository = trim($data['repository']);
        if (! RepositoryDetector::onGitHub($repository)) {
            $found = $this->onMachine($context, $machine, $repository, $data['branch'] ?? null, $data['name'] ?? null);

            return $this->answer($found);
        }
        if (filled($data['branch'] ?? null)) {
            $branch = trim((string) $data['branch']);
            if (! RefResolver::validRef($branch)) {
                throw new ToolError('branch must be a branch or tag name, like main or v1.2.0.');
            }
            if (str_contains($repository, '/tree/')) {
                throw new ToolError('The link already names a branch (/tree/…): leave out branch, or give the plain repository link.');
            }
            $repository = preg_replace('#(\.git)?/?$#', '', $repository);
            $repository = (str_contains($repository, 'github.com') ? $repository : "https://github.com/{$repository}")."/tree/{$branch}";
        }

        try {
            $found = $this->detector->detect($repository, $context->workspace, $machine === null || DaemonJobQueue::canDeployBuild($machine));
        } catch (ValidationException $e) {
            throw new ToolError(Deploys::explain($e));
        }

        return $this->answer($found);
    }

    /**
     * A repository on another host, read by the machine (repo_inspect).
     *
     * @return array<string, mixed>
     */
    private function onMachine(McpContext $context, ?Machine $machine, string $repository, ?string $branch, ?string $name): array
    {
        if ($machine === null) {
            throw new ToolError('This repository isn’t on GitHub, so the machine you’ll deploy to reads it: give machine.');
        }
        if (! DaemonJobQueue::canDeployBuild($machine)) {
            throw new ToolError("{$machine->name}’s daemon is too old to read repositories on other hosts. It updates itself, or run “sudo serverosd update” on the server. Until then, pick a template yourself (list_templates kind=app) and deploy with deploy_template.");
        }
        [$repo, $ref, $baseDir] = GitRemote::link($repository);
        if (GitRemote::isSsh($repo) && blank($name)) {
            throw new ToolError('An SSH repository is read with the app’s deploy key: give name, the app it’s for.');
        }
        if (! GitRemote::isSsh($repo)) {
            Deploys::check(fn () => DeployRequest::assertPublicRepo($repo));
        }
        if (($reason = app(DaemonJobQueue::class)->unavailable($machine, 'deploy')) !== null) {
            throw new ToolError($reason);
        }
        $job = $context->within(fn () => app(DaemonJobQueue::class)->queue($machine, $context->user, 'repo_inspect', array_filter([
            'type' => 'repo_inspect', 'service' => $name, 'repo' => $repo, 'ref' => $branch ?? $ref, 'base_dir' => $baseDir,
        ], fn ($v) => $v !== null && $v !== ''), $name, 180));
        $job = $context->waitForJob($job, McpContext::MAX_WAIT_SECONDS);
        if ($job->state !== 'succeeded') {
            throw new ToolError(in_array($job->state, ['queued', 'running'], true)
                ? 'The machine is still reading it. Ask again in a minute.'
                : 'The machine couldn’t read it: '.(is_array($job->error) && is_string($job->error['message'] ?? null) ? $job->error['message'] : 'it failed.'));
        }

        return $this->detector->fromInspect(is_array($job->result) ? $job->result : [], $repo, $baseDir);
    }

    /**
     * @param  array<string, mixed>  $found
     * @return array<string, mixed>
     */
    private function answer(array $found): array
    {
        $template = $found['template'] !== null ? $this->catalog->find((string) $found['template']) : null;

        return [
            ...$found,
            'suggestedName' => self::serviceName((string) $found['repo']) ?: 'app',
            'templateInfo' => $template === null ? null : [
                ...ListTemplates::row($template),
                'healthPath' => $template->health,
            ],
            'appTemplates' => $template === null
                ? array_values(array_map(fn (ServiceTemplate $t) => $t->slug, array_filter($this->catalog->all(), fn (ServiceTemplate $t) => $t->isApp())))
                : null,
            'next' => $template !== null
                ? "Deploy it with deploy_template: template={$template->slug}, repo={$found['repo']}, ref={$found['commit']}".($found['composeFile'] !== null ? ", compose_file={$found['composeFile']}" : '').($found['baseDir'] !== null && $found['template'] !== 'compose-file' ? ", base_dir={$found['baseDir']}" : '').($found['port'] !== null ? ", port={$found['port']}" : '').". Ask the person which machine, the name and the domain if they haven’t said; list_templates with template={$template->slug} shows its settings."
                : 'No template matched'.($found['needsUpdate'] !== null ? ' ('.$found['needsUpdate'].')' : '').'. If it has its own Dockerfile somewhere, use template=custom-dockerfile, or template=compose-file with compose_file for its own Compose file; otherwise choose one of appTemplates with the person.',
        ];
    }

    public static function serviceName(string $repo): string
    {
        $parts = explode('/', preg_replace('/\.git$/', '', $repo));
        $name = strtolower((string) end($parts));
        $name = preg_replace('/[^a-z0-9-]+/', '-', $name);
        $name = preg_replace('/^[^a-z]+|-+$/', '', (string) $name);

        return substr((string) $name, 0, 63);
    }
}
