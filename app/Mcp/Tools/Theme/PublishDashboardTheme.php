<?php

namespace App\Mcp\Tools\Theme;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\AuditContext;
use App\Support\Theme\DashboardTheme;
use InvalidArgumentException;

final class PublishDashboardTheme extends Tool
{
    public function __construct(private readonly DashboardTheme $theme) {}

    public function name(): string
    {
        return 'publish_dashboard_theme';
    }

    public function title(): string
    {
        return 'Publish the dashboard theme';
    }

    public function description(): string
    {
        return 'Show the person’s draft theme (from update_dashboard_theme) to everyone in the workspace. Only when the person asks to publish it. An empty draft goes back to the standard look.';
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function permission(): WorkspacePermission
    {
        return WorkspacePermission::ManageSettings;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        try {
            $published = $this->theme->publish($context->workspace, $context->user, $context->via);
        } catch (InvalidArgumentException $e) {
            throw new ToolError($e->getMessage());
        }
        if (! $published) {
            AuditContext::nothingChanged();
            throw new ToolError('There’s no draft to publish. Save one with update_dashboard_theme first.');
        }

        return [
            'summary' => 'Published for everyone',
            'published' => true,
            'standardLook' => $context->workspace->theme_css === null,
            'themeSettings' => $context->url('/settings/theme'),
        ];
    }
}
