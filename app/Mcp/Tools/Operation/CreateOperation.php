<?php

namespace App\Mcp\Tools\Operation;

use App\Api\ResponseSchemas;
use App\Http\Controllers\MaintenanceController;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\Tools\Machine\MachineView;
use App\Models\Machine;
use App\Models\Operation;
use App\Support\Audit\AuditContext;
use App\Support\Machines\MachineSelector;
use App\Support\Operations\Operations;

final class CreateOperation extends Tool
{
    public function __construct(private readonly Operations $operations) {}

    public function name(): string
    {
        return 'create_operation';
    }

    public function title(): string
    {
        return 'Run work across many machines';
    }

    public function description(): string
    {
        return 'Runs one piece of work on many machines, in batches: deploy (a template or a Git repository, under one name), deploy_version (ship a new version of an app already on them, from its Git repository; for a stack deployed from a Compose file in a repository, a new ref ships the image tags that file pins), rollback (back to each machine’s previous version, or a commit), service_action (start, stop, restart or reload a service), command (run a shell command in a container; needs the commands scope and two-factor authentication, as the console does), maintenance (start or end it) or update_machines (labels, tier, environment, or move them into a group). '
            .'Pick the machines with a selector (groups, labels, provider, region, tier, online) or machines (ids or names); they are fixed when it starts, so a machine that joins later isn’t touched. Each machine runs the same tool you would call for one machine, with the same checks. '
            .'strategy: batch_size (a number, or a percentage like "25%"; leave out to run all at once) runs that many at a time, and the next batch starts once every machine in it has finished; wait_healthy_seconds makes the service stay running, and the machine’s monitors stay up, that long first; pause_between_seconds waits between batches. When more than max_failures (default 0) machines fail, it pauses for a person to look, unless stop_on_failure is false. A machine that’s offline at its turn is skipped. '
            .'dry_run: true shows the machines and batches without starting anything. Follow it with get_operation; pause_operation, resume_operation and cancel_operation steer it.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'kind' => Schema::string('What to do on each machine.', Operation::KINDS),
            ...MachineSelector::arguments(),
            'deploy' => Schema::object([
                'template' => Schema::string('A template’s key, as list_templates shows it. Leave out to build from repo instead.', maxLength: 120),
                'repo' => Schema::string('The HTTPS Git repository: what an app template builds, or, without template, the app to build.', maxLength: 500),
                'name' => Schema::string('The name to deploy it as on every machine: lowercase letters, digits and dashes, starting with a letter.', maxLength: 63),
                'ref' => Schema::string('The branch, tag or full commit to deploy.', maxLength: 255),
                'port' => Schema::integer('The port it listens on: needed without template, and for templates whose port can change.', 1, 65535),
                'health_path' => Schema::string('A path that answers 200 once it’s up, like /health.', maxLength: 255),
                'env' => ['type' => 'object', 'description' => 'The template’s settings by key, as list_templates with template shows them.', 'additionalProperties' => ['type' => 'string']],
                'replace' => Schema::boolean('Replace whatever is already deployed under this name.'),
            ], ['name']) + ['description' => 'For kind deploy: template, or repo (with port), and name.'],
            'rollback' => Schema::object([
                'app' => Schema::string('The app, by the name it was deployed as.', maxLength: 120),
                'commit' => Schema::string('The version to go back to on every machine. Leave out to go back to each machine’s previous version.', maxLength: 64),
                'with_data' => Schema::boolean('Also put its data back as it was just before the next deploy.'),
                'confirm' => Schema::string('Type the app’s name exactly, to confirm. Ask the person first.', maxLength: 120),
            ], ['app', 'confirm']) + ['description' => 'For kind rollback.'],
            'command' => Schema::object([
                'service' => Schema::string('The container on each machine: its key, name or label.', maxLength: 255),
                'command' => Schema::string('The shell command to run. A non-zero exit code counts as a failure.', maxLength: 2000),
            ], ['service', 'command']) + ['description' => 'For kind command.'],
            'update_machines' => Schema::object([
                'labels' => ['type' => 'object', 'description' => 'Labels to set, like {"env": "prod"}; a null or empty value takes that label off. Others stay.', 'additionalProperties' => ['type' => ['string', 'null']]],
                'tier' => Schema::string('The tier to set. Empty: none.', maxLength: 60),
                'environment' => Schema::string('The environment to set.', ['production', 'staging', 'development']),
                'group' => Schema::string('Move them into this group (its id or name).', maxLength: 120),
            ]) + ['description' => 'For kind update_machines.'],
            'deploy_version' => Schema::object([
                'app' => Schema::string('The app, by the name it was deployed as (like shop). It must already run on every machine.', maxLength: 120),
                'ref' => Schema::string('The branch, tag or full commit to deploy. Defaults to the default branch’s latest commit. For a Compose file stack, the commit whose file pins the image tags you want.', maxLength: 255),
                'repo' => Schema::string('Build from this HTTPS Git repository instead of the app’s current one.', maxLength: 500),
            ], ['app']) + ['description' => 'For kind deploy_version.'],
            'service_action' => Schema::object([
                'service' => Schema::string('The service on each machine: its key, name or label.', maxLength: 255),
                'action' => Schema::string('What to do to it.', Operations::SERVICE_ACTIONS),
            ], ['service', 'action']) + ['description' => 'For kind service_action.'],
            'maintenance' => Schema::object([
                'action' => Schema::string('Start maintenance now, or end it.', Operations::MAINTENANCE_ACTIONS),
                'minutes' => Schema::integer('How long, when starting: 30, 60 (default), 120, 240, 480 or 1440.') + ['enum' => MaintenanceController::DURATIONS],
                'reason' => Schema::string('What’s happening, when starting.', maxLength: 200),
                'public' => Schema::boolean('Show a maintenance notice on each status page, when starting (default true).'),
            ], ['action']) + ['description' => 'For kind maintenance.'],
            'strategy' => Schema::object([
                'batch_size' => ['type' => ['integer', 'string'], 'maxLength' => 4, 'minimum' => 1, 'maximum' => 1000, 'description' => 'How many machines at a time: a number, or a percentage like "25%". Leave out to run them all at once.'],
                'stop_on_failure' => Schema::boolean('Pause when more than max_failures machines fail (default true).'),
                'max_failures' => Schema::integer('How many machines may fail before it pauses (default 0).', 0, 1000),
                'wait_healthy_seconds' => Schema::integer('After each machine’s job, how long its service must stay running, and that machine’s monitors stay up, before it counts as done (0–3600, default 0).', 0, Operations::MAX_SECONDS),
                'pause_between_seconds' => Schema::integer('How long to wait between batches (0–3600, default 0).', 0, Operations::MAX_SECONDS),
            ]) + ['description' => 'How to roll it out.'],
            'dry_run' => Schema::boolean('Only show the machines and batches it would use; start nothing.'),
        ], ['kind']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function responseSchema(): array
    {
        return ResponseSchemas::object([
            'operation' => ResponseSchemas::ref('Operation'),
            'dryRun' => ['type' => 'boolean', 'description' => 'With dry_run: nothing was started.'],
            'count' => ['type' => 'integer'],
            'machinesPerBatch' => ['type' => 'integer'],
            'batches' => ['type' => 'array', 'items' => ['type' => 'array', 'items' => ResponseSchemas::object(['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], ['id', 'name'])]],
            'machines' => ['type' => 'array', 'items' => ResponseSchemas::ref('Machine')],
        ], description: 'The operation it started, or with dry_run the machines and batches it would use.');
    }

    public function successStatus(mixed $data): int
    {
        return is_array($data) && ($data['dryRun'] ?? false) ? 200 : 202;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $plan = $this->operations->plan($context, $arguments);

        if ($arguments['dry_run'] ?? false) {
            AuditContext::nothingChanged();
            $groups = $context->workspace->machineGroups()->get()->keyBy('id');

            return [
                'dryRun' => true,
                'kind' => $plan['kind'],
                'count' => $plan['machines']->count(),
                'machinesPerBatch' => $plan['batch_size'],
                'batches' => $plan['machines']->chunk($plan['batch_size'])->map(fn ($batch) => $batch->map(fn (Machine $m) => ['id' => $m->id, 'name' => $m->name])->values()->all())->values()->all(),
                'machines' => $plan['machines']->map(fn (Machine $m) => MachineView::row($m, $context, $m->machine_group_id === null ? null : $groups->get($m->machine_group_id)))->all(),
            ];
        }

        $selector = is_array($arguments['selector'] ?? null) ? $arguments['selector'] : null;
        $operation = $this->operations->create($context, $plan, $selector);

        return ['operation' => Operations::view($operation)];
    }
}
