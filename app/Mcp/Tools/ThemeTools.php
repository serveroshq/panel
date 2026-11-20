<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Theme\GetDashboardTheme;
use App\Mcp\Tools\Theme\PublishDashboardTheme;
use App\Mcp\Tools\Theme\UpdateDashboardTheme;

final class ThemeTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            GetDashboardTheme::class,
            UpdateDashboardTheme::class,
            PublishDashboardTheme::class,
        ];
    }
}
