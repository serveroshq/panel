<?php

namespace App\Mcp\Tools\Team;

use App\Api\ResponseSchemas;
use App\Enums\WorkspacePermission;
use App\Enums\WorkspaceRole;
use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Models\WorkspaceCustomRole;
use App\Support\Edition;

final class ListRoles extends Tool
{
    public function name(): string
    {
        return 'list_roles';
    }

    public function title(): string
    {
        return 'List roles';
    }

    public function description(): string
    {
        return 'Lists the roles in the workspace and what each may do. Built-in: Owner (everything, including billing; one per workspace, can’t be given), Admin, Member and Viewer, each with its description, permissions, whether the plan offers it (`available`: Member and Viewer are on Pro and Business, so on Essential people join as Admins) and how many people have it. '
            .'Then the workspace’s own roles (`customRoles`, a Business feature) with their permissions and how many people have each; off Business they’re kept but not in use, and the people given them have Viewer access. Everyone in a workspace can view it; permissions are what a role may change. Pass a role’s `value` as the role to invite_member or change_member_role.';
    }

    public function responseSchema(): array
    {
        $permission = ResponseSchemas::object([
            'value' => ['type' => 'string', 'enum' => array_map(fn (WorkspacePermission $p) => $p->value, WorkspacePermission::cases())],
            'label' => ['type' => 'string'],
        ], ['value', 'label']);
        $permissions = ['type' => 'array', 'items' => $permission];
        $roles = array_map(fn (WorkspaceRole $r) => $r->value, WorkspaceRole::cases());

        return ResponseSchemas::object([
            'roles' => ['type' => 'array', 'description' => 'The built-in roles.', 'items' => ResponseSchemas::object([
                'value' => ['type' => 'string', 'enum' => $roles],
                'label' => ['type' => 'string'],
                'description' => ['type' => 'string'],
                'permissions' => $permissions,
                'assignable' => ['type' => 'boolean', 'description' => 'False for Owner, which can’t be given.'],
                'available' => ['type' => 'boolean', 'description' => 'Whether the plan offers it.'],
                'members' => ['type' => 'integer', 'description' => 'How many people have it.'],
            ], ['value', 'label', 'description', 'permissions', 'assignable', 'available', 'members'])],
            'customRoles' => ['type' => 'array', 'description' => 'The workspace’s own roles.', 'items' => ResponseSchemas::object([
                'value' => ['type' => 'string', 'description' => 'custom:<id>, to pass as a role.'],
                'id' => ['type' => 'integer'],
                'name' => ['type' => 'string'],
                'description' => ['type' => ['string', 'null']],
                'permissions' => $permissions,
                'inUse' => ['type' => 'boolean', 'description' => 'False off Business: people given it have Viewer access.'],
                'members' => ['type' => 'integer', 'description' => 'How many people have it.'],
            ], ['value', 'id', 'name', 'description', 'permissions', 'inUse', 'members'])],
            'hasStaffRoles' => ['type' => 'boolean', 'description' => 'Whether the plan offers Member and Viewer.'],
            'hasCustomRoles' => ['type' => 'boolean', 'description' => 'Whether the plan offers the workspace’s own roles.'],
            'permissions' => ['type' => 'array', 'description' => 'Every permission a role can have.', 'items' => $permission],
            'you' => ResponseSchemas::object([
                'role' => ['type' => 'string', 'enum' => $roles],
                'roleLabel' => ['type' => 'string'],
                'canManageMembers' => ['type' => 'boolean'],
            ], ['role', 'roleLabel', 'canManageMembers']),
            'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
            'url' => ['type' => 'string', 'description' => 'The Members page in the dashboard.'],
        ], ['roles', 'customRoles', 'hasStaffRoles', 'hasCustomRoles', 'permissions', 'you', 'notes', 'url']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $workspace = $context->workspace;
        $hasStaff = $workspace->hasStaffRoles();
        $hasCustom = $workspace->hasCustomRoles();
        $permission = fn (WorkspacePermission $p) => ['value' => $p->value, 'label' => $p->label()];

        $memberships = $workspace->members()->get(['users.id'])->map(fn ($m) => [
            'role' => $m->pivot->role,
            'custom' => $m->pivot->custom_role_id,
        ]);
        $customCounts = $memberships->whereNotNull('custom')->countBy('custom');
        $builtInCounts = $memberships
            ->filter(fn (array $m) => ! $hasCustom || $m['custom'] === null)
            ->countBy('role');

        $roles = array_map(fn (WorkspaceRole $role) => [
            'value' => $role->value,
            'label' => $role->label(),
            'description' => $role->description(),
            'permissions' => array_values(array_map($permission, array_filter($role->permissions(), fn (WorkspacePermission $p) => in_array($p, WorkspacePermission::offered(), true)))),
            'assignable' => $role !== WorkspaceRole::Owner,
            'available' => $role === WorkspaceRole::Owner || ! $role->isStaffRole() || $hasStaff,
            'members' => (int) ($builtInCounts[$role->value] ?? 0),
        ], WorkspaceRole::cases());

        $customRoles = $workspace->customRoles()->get()->map(fn (WorkspaceCustomRole $role) => [
            'value' => "custom:{$role->id}",
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => array_values(array_map($permission, array_filter(
                WorkspacePermission::cases(),
                fn (WorkspacePermission $p) => $role->grants($p),
            ))),
            'inUse' => $hasCustom,
            'members' => (int) ($customCounts[$role->id] ?? 0),
        ])->all();

        $notes = ['Everyone in a workspace can view everything in it; a role’s permissions are what it may change.'];
        if (! $hasStaff) {
            $notes[] = 'Member and Viewer roles are on Pro and Business. On this plan, people join as Admins.';
        }
        $notes[] = match (true) {
            $hasCustom && Edition::selfHosted() => 'Custom roles replace the base role’s permissions. If the workspace’s license is removed, people with them become Viewers.',
            $hasCustom => 'Custom roles replace the base role’s permissions. If the workspace leaves Business, people with them become Viewers.',
            default => (Edition::selfHosted() ? 'Custom roles need a ServerOS license (Settings › License).' : 'Custom roles are on Business.').($customRoles === [] ? '' : ' The ones listed are kept, but not in use: people given them have Viewer access.'),
        };

        $yours = $workspace->roleOf($context->user) ?? WorkspaceRole::Viewer;

        return [
            'roles' => $roles,
            'customRoles' => $customRoles,
            'hasStaffRoles' => $hasStaff,
            'hasCustomRoles' => $hasCustom,
            'permissions' => array_map($permission, WorkspacePermission::offered()),
            'you' => [
                'role' => $yours->value,
                'roleLabel' => Team::label($yours, $workspace->customRoleOf($context->user)),
                'canManageMembers' => $context->can(WorkspacePermission::ManageMembers),
            ],
            'notes' => $notes,
            'url' => $context->url(Team::PAGE),
        ];
    }
}
