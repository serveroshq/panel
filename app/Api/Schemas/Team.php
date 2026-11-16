<?php

namespace App\Api\Schemas;

use App\Api\ResponseSchemas;
use App\Enums\WorkspaceRole;
use App\Mcp\Tools\Team\Team as TeamTools;

final class Team
{
    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        $string = ['type' => 'string'];
        $bool = ['type' => 'boolean'];
        $role = ['type' => 'string', 'enum' => array_map(fn (WorkspaceRole $r) => $r->value, WorkspaceRole::cases()), 'description' => 'The base role.'];
        $roleLabel = ['type' => 'string', 'description' => 'What the dashboard shows: the custom role’s name when they have one.'];
        $customRole = [
            'type' => ['object', 'null'],
            'description' => 'One of the workspace’s own roles, when given one.',
            'required' => ['id', 'name'],
            'properties' => ['id' => ['type' => 'integer'], 'name' => $string],
        ];

        return [
            'Member' => ResponseSchemas::object([
                'id' => ['type' => 'integer'],
                'name' => $string,
                'email' => $string,
                'role' => $role,
                'roleLabel' => $roleLabel,
                'customRole' => $customRole,
                'twoFactor' => ['type' => 'boolean', 'description' => 'An authenticator app or a passkey is set up.'],
                'needsTwoFactor' => ['type' => 'boolean', 'description' => 'The workspace requires two-factor sign-in and they haven’t set it up.'],
                'joinedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'isYou' => $bool,
                'canChangeRole' => ['type' => 'boolean', 'description' => 'Whether you may change their role.'],
                'canRemove' => ['type' => 'boolean', 'description' => 'Whether you may remove them.'],
            ], ['id', 'name', 'email', 'role', 'roleLabel', 'customRole', 'twoFactor', 'needsTwoFactor', 'joinedAt', 'isYou', 'canChangeRole', 'canRemove'], 'Someone in the workspace.'),
            'Invitation' => ResponseSchemas::object([
                'id' => ['type' => 'integer'],
                'email' => $string,
                'role' => $role,
                'roleLabel' => $roleLabel,
                'customRole' => $customRole,
                'invitedBy' => ['type' => ['string', 'null'], 'description' => 'Who sent it; null when they’re gone.'],
                'sentAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'expiresAt' => ['type' => 'string', 'format' => 'date-time'],
                'expired' => $bool,
            ], ['id', 'email', 'role', 'roleLabel', 'customRole', 'invitedBy', 'sentAt', 'expiresAt', 'expired'], 'A pending invite to join the workspace.'),
            'NotificationPreferences' => ResponseSchemas::object([
                'alertEmails' => ['type' => 'string', 'enum' => array_keys(TeamTools::ALERT_EMAILS), 'description' => 'all, problems (only when something breaks) or none.'],
                'alertEmailsLabel' => $string,
                'weeklySummary' => ['type' => 'boolean', 'description' => 'Whether the Monday summary email is on.'],
                'getsAlerts' => ['type' => 'boolean', 'description' => 'Whether your role is sent alerts at all (owners and admins are).'],
                'workspaceSendsAlertEmails' => ['type' => 'boolean', 'description' => 'Whether alert emails are on for the workspace.'],
                'notes' => ['type' => 'array', 'items' => $string],
                'url' => ['type' => 'string', 'description' => 'Your profile settings in the dashboard.'],
                'note' => ['type' => 'string', 'description' => 'After update_my_notifications: what was done.'],
            ], ['alertEmails', 'alertEmailsLabel', 'weeklySummary', 'getsAlerts', 'workspaceSendsAlertEmails', 'notes', 'url'], 'What you are emailed from this workspace.'),
        ];
    }
}
