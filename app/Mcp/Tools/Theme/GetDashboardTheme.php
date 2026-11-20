<?php

namespace App\Mcp\Tools\Theme;

use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Support\Theme\DashboardTheme;
use App\Support\Theme\ThemeTemplates;

final class GetDashboardTheme extends Tool
{
    public function __construct(
        private readonly DashboardTheme $theme,
        private readonly ThemeTemplates $templates,
    ) {}

    public function name(): string
    {
        return 'get_dashboard_theme';
    }

    public function title(): string
    {
        return 'Read the dashboard theme';
    }

    public function description(): string
    {
        return 'Read the workspace’s custom dashboard CSS: what everyone sees (published), the person’s own unpublished draft, the ready-made templates, the images uploaded for backgrounds, and how to write a theme. Call it before update_dashboard_theme.';
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $workspace = $context->workspace;
        $draft = $this->theme->draftOf($workspace, $context->user);

        $images = $this->theme->images($workspace);

        return [
            'summary' => match (true) {
                $draft !== null => 'Your draft',
                $workspace->theme_css !== null => 'Published theme',
                default => 'Standard look',
            }.(count($images) > 0 ? ', '.count($images).' '.(count($images) === 1 ? 'image' : 'images') : ''),
            'published' => $workspace->theme_css,
            'draft' => $draft,
            'editing' => $draft !== null ? 'draft' : ($workspace->theme_css !== null ? 'published' : 'standard look'),
            'templates' => array_map(fn (array $t) => ['key' => $t['key'], 'name' => $t['name'], 'description' => $t['description']], $this->templates->all()),
            'images' => array_map(fn (array $image) => ['name' => $image['name'], 'css' => "url({$image['url']})"], $images),
            'guide' => ThemeGuide::text(),
            'themeSettings' => $context->url('/settings/theme'),
        ];
    }
}
