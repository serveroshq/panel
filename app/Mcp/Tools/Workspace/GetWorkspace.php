<?php

namespace App\Mcp\Tools\Workspace;

use App\Api\ResponseSchemas;
use App\Enums\Plan;
use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Mcp\McpContext;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Models\StatusPage;
use App\Support\Health\WorkspaceHealth;
use App\Support\Mcp\McpUsage;

final class GetWorkspace extends Tool
{
    public function name(): string
    {
        return 'get_workspace';
    }

    public function title(): string
    {
        return 'Get the workspace';
    }

    public function description(): string
    {
        return 'Start here. Returns the workspace (name, plan, trial, and `aiToolCalls`: tool calls used this month, the month’s limit (null for unlimited) and when it resets), who you are acting as and their role, what this connection may do (read, make changes, run commands), and counts of machines, services, monitors, workflows and status pages, with the panel URL. '
            .'`counts.needsAttention` is how many critical and warning items the Home’s “Needs attention” list has (list_issues lists them). To find something by name use search; for what changed recently, get_activity.';
    }

    public function __construct(private readonly WorkspaceHealth $health) {}

    public function responseSchema(): array
    {
        $count = ['type' => 'integer'];

        return ResponseSchemas::object([
            'workspace' => ResponseSchemas::object([
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'slug' => ['type' => 'string'],
                'url' => ['type' => 'string'],
                'plan' => ['type' => ['string', 'null'], 'enum' => [...array_map(fn (Plan $p) => $p->label(), Plan::cases()), null]],
                'onTrial' => ['type' => 'boolean'],
                'trialEndsAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'machineLimit' => ['type' => ['integer', 'null'], 'description' => 'Null for unlimited.'],
                'aiToolCalls' => ResponseSchemas::object([
                    'used' => ['type' => 'integer', 'description' => 'Tool calls used this month.'],
                    'limit' => ['type' => ['integer', 'null'], 'description' => 'Null for unlimited.'],
                    'resetsAt' => ['type' => 'string', 'format' => 'date-time'],
                ], ['used', 'limit', 'resetsAt']),
            ], ['id', 'name', 'slug', 'url', 'plan', 'onTrial', 'trialEndsAt', 'machineLimit', 'aiToolCalls']),
            'you' => ResponseSchemas::object([
                'name' => ['type' => 'string'],
                'email' => ['type' => 'string'],
                'role' => ['type' => ['string', 'null'], 'enum' => [...array_map(fn (WorkspaceRole $r) => $r->label(), WorkspaceRole::cases()), null]],
                'can' => ['type' => 'array', 'description' => 'The permissions you have.', 'items' => ['type' => 'string', 'enum' => array_map(fn (WorkspacePermission $p) => $p->value, WorkspacePermission::cases())]],
            ], ['name', 'email', 'role', 'can'], 'Who this connection acts as.'),
            'connection' => ResponseSchemas::object([
                'name' => ['type' => 'string', 'description' => 'The AI app or API key.'],
                'scopes' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => Scopes::ALL]],
                'canMakeChanges' => ['type' => 'boolean'],
                'canRunCommands' => ['type' => 'boolean'],
                'experimental' => ['type' => 'string'],
            ], ['name', 'scopes', 'canMakeChanges', 'canRunCommands', 'experimental']),
            'counts' => ResponseSchemas::object([
                'machines' => $count,
                'machinesOnline' => $count,
                'services' => $count,
                'monitors' => $count,
                'workflows' => $count,
                'statusPages' => $count,
                'needsAttention' => ['type' => 'integer', 'description' => 'Critical and warning items, as list_issues lists them.'],
            ], ['machines', 'machinesOnline', 'services', 'monitors', 'workflows', 'statusPages', 'needsAttention']),
        ], ['workspace', 'you', 'connection', 'counts']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $workspace = $context->workspace;
        $machines = $workspace->machines()->get();
        $plan = $workspace->effectivePlan();

        return [
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'slug' => $workspace->slug,
                'url' => $context->url('/'),
                'plan' => $plan?->label(),
                'onTrial' => $workspace->onTrial(),
                'trialEndsAt' => $workspace->onTrial() ? $workspace->trial_ends_at?->toIso8601String() : null,
                'machineLimit' => $workspace->machineLimit(),
                'aiToolCalls' => [
                    'used' => McpUsage::used($workspace),
                    'limit' => McpUsage::limit($workspace),
                    'resetsAt' => McpUsage::resetsAt()->toIso8601String(),
                ],
            ],
            'you' => [
                'name' => $context->user->name,
                'email' => $context->user->email,
                'role' => $workspace->roleOf($context->user)?->label(),
                'can' => array_values(array_map(
                    fn (WorkspacePermission $p) => $p->value,
                    array_filter(WorkspacePermission::cases(), fn (WorkspacePermission $p) => $context->can($p)),
                )),
            ],
            'connection' => [
                'name' => $context->client(),
                'scopes' => $context->scopes(),
                'canMakeChanges' => $context->allows(Scopes::WRITE),
                'canRunCommands' => $context->allows(Scopes::COMMANDS),
                'experimental' => 'MCP in ServerOS is experimental. Confirm destructive actions with the person before taking them.',
            ],
            'counts' => [
                'machines' => $machines->count(),
                'machinesOnline' => $machines->filter->isOnline()->count(),
                'services' => $machines->sum(fn ($m) => $context->services($m)->count()),
                'monitors' => $workspace->monitors()->count(),
                'workflows' => $workspace->workflows()->count(),
                'statusPages' => StatusPage::query()->whereIn('machine_id', $machines->modelKeys())->count() + ($workspace->fleetStatusPage ? 1 : 0),
                'needsAttention' => count(array_filter($this->health->issues($workspace, $machines), fn (array $issue) => $issue['severity'] !== WorkspaceHealth::NOTICE)),
            ],
        ];
    }
}
