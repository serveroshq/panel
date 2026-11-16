<?php

namespace App\Mcp\Tools\Workflow;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Support\Workflows\WorkflowTemplates;

final class ListWorkflowTemplates extends Tool
{
    public function name(): string
    {
        return 'list_workflow_templates';
    }

    public function title(): string
    {
        return 'List workflow templates';
    }

    public function description(): string
    {
        return 'Lists the templates a new workflow can start from, as the dashboard’s “New workflow” picker shows them: key (for create_workflow), name, what it does, and who it’s for (new to servers, developers, agencies, teams at scale, or everyone for “blank”). '
            .'Templates are filled in with the workspace’s first machine, managed service, deployed app and monitor where they fit, and anything they can’t fill is listed as a problem to fix with update_workflow. `planAllows` says whether the plan includes workflows; `count` and `limit` how many the workspace has and may have.';
    }

    public function responseSchema(): array
    {
        return ResponseSchemas::object([
            'templates' => ['type' => 'array', 'items' => ResponseSchemas::object([
                'key' => ['type' => 'string', 'description' => 'Pass as template to create_workflow.'],
                'audience' => ['type' => 'string', 'enum' => [...array_keys(WorkflowTemplates::AUDIENCES), 'everyone']],
                'for' => ['type' => 'string', 'description' => 'The audience in words.'],
                'name' => ['type' => 'string'],
                'description' => ['type' => 'string'],
            ], ['key', 'audience', 'for', 'name', 'description'])],
            'audiences' => ['type' => 'object', 'additionalProperties' => ['type' => 'string'], 'description' => 'Each audience in words, by its key.'],
            'planAllows' => ['type' => 'boolean', 'description' => 'The workspace’s plan includes workflows.'],
            'count' => ['type' => 'integer', 'description' => 'How many workflows the workspace has.'],
            'limit' => ['type' => 'integer', 'description' => 'How many a workspace can have.'],
        ], ['templates', 'audiences', 'planAllows', 'count', 'limit']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        return [
            'templates' => WorkflowTemplates::list(),
            'audiences' => [...WorkflowTemplates::AUDIENCES, 'everyone' => 'Everyone'],
            'planAllows' => $context->workspace->hasAutomations(),
            'count' => $context->workspace->workflows()->count(),
            'limit' => Workflows::LIMIT,
        ];
    }
}
