<?php

namespace App\Mcp\Tools\Operation;

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
        return 'Runs one piece of work on many machines, in batches: deploy_version (ship a new version of an app already on them), service_action (start, stop, restart or reload a service) or maintenance (start or end it). '
            .'Pick the machines with a selector (groups, labels, provider, region, tier, online) or machines (ids or names); they are fixed when it starts, so a machine that joins later isn’t touched. Each machine runs the same tool you would call for one machine, with the same checks. '
            .'strategy: batch_size (a number, or a percentage like "25%"; leave out to run all at once) runs that many at a time, and the next batch starts once every machine in it has finished; wait_healthy_seconds makes the service stay running that long first; pause_between_seconds waits between batches. When more than max_failures (default 0) machines fail, it pauses for a person to look, unless stop_on_failure is false. A machine that’s offline at its turn is skipped. '
            .'dry_run: true shows the machines and batches without starting anything. Follow it with get_operation; pause_operation, resume_operation and cancel_operation steer it.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'kind' => Schema::string('What to do on each machine.', Operation::KINDS),
            ...MachineSelector::arguments(),
            'deploy_version' => Schema::object([
                'app' => Schema::string('The app, by the name it was deployed as (like shop). It must already run on every machine.', maxLength: 120),
                'ref' => Schema::string('The branch, tag or full commit to deploy. Defaults to the default branch’s latest commit.', maxLength: 255),
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
                'wait_healthy_seconds' => Schema::integer('After each machine’s job, how long its service must stay running before it counts as done (0–3600, default 0).', 0, Operations::MAX_SECONDS),
                'pause_between_seconds' => Schema::integer('How long to wait between batches (0–3600, default 0).', 0, Operations::MAX_SECONDS),
            ]) + ['description' => 'How to roll it out.'],
            'dry_run' => Schema::boolean('Only show the machines and batches it would use; start nothing.'),
        ], ['kind']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
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
