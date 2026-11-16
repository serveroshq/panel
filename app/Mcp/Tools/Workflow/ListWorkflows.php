<?php

namespace App\Mcp\Tools\Workflow;

use App\Api\ResponseSchemas;
use App\Api\Schemas\Workflows as WorkflowSchemas;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\WorkflowRun;

final class ListWorkflows extends Tool
{
    public function name(): string
    {
        return 'list_workflows';
    }

    public function title(): string
    {
        return 'List workflows';
    }

    public function description(): string
    {
        return 'Lists the workspace’s workflows (automations: a trigger and steps), by name, like the Workflows page: id, name, description, whether it’s on (enabled), its trigger (the event, and in words like “Every day at 03:00 UTC” or “A monitor goes down: Shop”), how many steps, its last run (id, state, time), runs and failed runs in the last 7 days, and when it runs next (scheduled ones that are on). '
            .'`planAllows` says whether the plan includes workflows (Pro or Business). Use get_workflow for one workflow’s steps and problems, list_workflow_runs for its runs, and list_workflow_templates to start a new one.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'enabled' => Schema::boolean('Only workflows that are on (true) or off (false). Leave out for all.'),
            'trigger' => Schema::string('Only workflows started by this event (like “schedule”, “webhook”, “monitor.down”).', maxLength: 40),
        ]);
    }

    public function responseSchema(): array
    {
        return ResponseSchemas::object([
            'workflows' => ['type' => 'array', 'items' => ResponseSchemas::object([
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'description' => ['type' => ['string', 'null']],
                'enabled' => ['type' => 'boolean'],
                'trigger' => ['type' => 'string', 'description' => 'What starts it, like manual, schedule or service.down.'],
                'triggerWords' => ['type' => 'string', 'description' => 'The trigger in words.'],
                'steps' => ['type' => 'integer', 'description' => 'How many steps follow the trigger.'],
                'lastRun' => [...ResponseSchemas::object([
                    'id' => ['type' => 'integer'],
                    'state' => ['type' => 'string', 'enum' => WorkflowSchemas::RUN_STATES],
                    'at' => ['type' => 'string', 'format' => 'date-time'],
                ], ['id', 'state', 'at']), 'type' => ['object', 'null'], 'description' => 'Its latest run in the last 7 days.'],
                'lastRunAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'runs7d' => ['type' => 'integer', 'description' => 'Runs in the last 7 days.'],
                'failed7d' => ['type' => 'integer', 'description' => 'Failed runs in the last 7 days.'],
                'nextRunAt' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'When a schedule runs it next, while it is on.'],
                'url' => ['type' => 'string'],
            ], ['id', 'name', 'description', 'enabled', 'trigger', 'triggerWords', 'steps', 'lastRun', 'lastRunAt', 'runs7d', 'failed7d', 'nextRunAt', 'url'])],
            'count' => ['type' => 'integer', 'description' => 'How many are listed.'],
            'total' => ['type' => 'integer', 'description' => 'How many the workspace has.'],
            'limit' => ['type' => 'integer', 'description' => 'How many a workspace can have.'],
            'planAllows' => ['type' => 'boolean', 'description' => 'The workspace’s plan includes workflows.'],
            'url' => ['type' => 'string', 'description' => 'The workflows page in the dashboard.'],
        ], ['workflows', 'count', 'total', 'limit', 'planAllows', 'url']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'enabled' => ['sometimes', 'boolean'],
            'trigger' => ['sometimes', 'string', 'max:40'],
        ]);

        $workspace = $context->workspace;
        $workflows = $workspace->workflows()->orderBy('name')->get();
        $runs = WorkflowRun::query()
            ->whereIn('workflow_id', $workflows->modelKeys())
            ->where('started_at', '>=', now()->subDays(7))
            ->get(['id', 'workflow_id', 'state', 'started_at'])
            ->groupBy('workflow_id');
        $words = WorkflowWords::for($workspace);

        $shown = $workflows
            ->when(isset($data['enabled']), fn ($c) => $c->where('enabled', (bool) $data['enabled']))
            ->when(isset($data['trigger']), fn ($c) => $c->where('trigger', $data['trigger']));

        return [
            'workflows' => $shown->map(fn ($w) => WorkflowView::row($w, $runs[$w->id] ?? collect(), $words, $context))->values()->all(),
            'count' => $shown->count(),
            'total' => $workflows->count(),
            'limit' => Workflows::LIMIT,
            'planAllows' => $workspace->hasAutomations(),
            'url' => $context->url('/workflows'),
        ];
    }
}
