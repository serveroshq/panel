<?php

namespace App\Mcp\Tools\Theme;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\NotAudited;
use App\Support\Theme\DashboardTheme;
use App\Support\Theme\ThemeBuilder;
use App\Support\Theme\ThemeCss;
use App\Support\Theme\ThemeTemplates;
use Illuminate\Validation\Rule;
use InvalidArgumentException;

final class UpdateDashboardTheme extends Tool
{
    public function __construct(
        private readonly DashboardTheme $theme,
        private readonly ThemeTemplates $templates,
    ) {}

    public function name(): string
    {
        return 'update_dashboard_theme';
    }

    public function title(): string
    {
        return 'Try a dashboard theme';
    }

    public function description(): string
    {
        return 'Save custom CSS for the dashboard as the person’s draft. Only they see it, on every dashboard page, straight away; nobody else does until it’s published (publish_dashboard_theme, or Publish in Settings › Theme). '
            .'Send one of: palette (a few colours per mode; ServerOS builds the whole theme from them, the best way to make a new look), template (a template key to start from), changes (find and replace edits to what the person is editing now, best for tweaks), or css (a whole stylesheet you wrote). With palette or template, extra_css is added after, for backgrounds and anything else. Read get_dashboard_theme first.';
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function permission(): WorkspacePermission
    {
        return WorkspacePermission::ManageSettings;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'css' => Schema::string('The whole stylesheet. Empty means the standard look.', maxLength: ThemeCss::MAX_BYTES),
            'template' => Schema::string('Start from this template instead of sending CSS.', $this->templates->keys()),
            'palette' => Schema::object([
                'light' => $this->modeSchema('Colours for light mode. Leave out for a theme that is always dark.'),
                'dark' => $this->modeSchema('Colours for dark mode. Leave out for a theme that is always light.'),
                'font' => Schema::string('A font family for the whole dashboard, e.g. JetBrains Mono. Loaded ones: Mona Sans, Inter, Instrument Sans, Schibsted Grotesk, Cabin, Geist Mono; others need font_import.', maxLength: 80),
                'font_import' => Schema::string('A Google Fonts or Bunny Fonts CSS address that loads the font.', maxLength: 500),
            ]),
            'extra_css' => Schema::string('CSS added after a palette or template: backgrounds, borders, anything else.', maxLength: ThemeCss::MAX_BYTES),
            'changes' => Schema::array(Schema::object([
                'find' => Schema::string('Text that appears exactly once in the current CSS.', maxLength: 4000),
                'replace' => Schema::string('What it becomes.', maxLength: 8000),
            ], ['find', 'replace']), 'Edits to the CSS the person is editing now, made in order.', 30),
        ]);
    }

    #[NotAudited('A draft only the person editing sees; publishing it is audited')]
    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'css' => ['nullable', 'string', 'max:'.ThemeCss::MAX_BYTES, 'required_without_all:template,changes,palette'],
            'palette' => ['nullable', 'array'],
            'palette.light' => ['nullable', 'array', Rule::requiredIf(fn () => is_array($arguments['palette'] ?? null) && ! isset($arguments['palette']['dark']))],
            'palette.dark' => ['nullable', 'array'],
            ...self::colourRules('light'),
            ...self::colourRules('dark'),
            'palette.font' => ['nullable', 'string', 'max:80'],
            'palette.font_import' => ['nullable', 'string', 'max:500'],
            'extra_css' => ['nullable', 'string', 'max:'.ThemeCss::MAX_BYTES],
            'template' => ['nullable', 'string', 'in:'.implode(',', $this->templates->keys())],
            'changes' => ['nullable', 'array', 'min:1', 'max:30'],
            'changes.*.find' => ['required', 'string', 'min:1', 'max:4000'],
            'changes.*.replace' => ['present', 'nullable', 'string', 'max:8000'],
        ], [
            'css.required_without_all' => 'Send a palette, a template key, changes, or css.',
            'palette.*.*.regex' => 'Palette colours are hex, like #1e1e2e.',
            'palette.*.*.required_with' => 'Each mode in a palette needs background, text and accent.',
            'palette.light.required_without' => 'A palette needs light, dark, or both.',
        ]);

        $extra = isset($data['extra_css']) ? "\n".trim((string) $data['extra_css'])."\n" : '';
        $css = match (true) {
            isset($data['palette']) => ThemeBuilder::build(
                self::colours($data['palette']['light'] ?? null),
                self::colours($data['palette']['dark'] ?? null),
                $data['palette']['font'] ?? null,
                $data['palette']['font_import'] ?? null,
                (string) ($data['extra_css'] ?? ''),
            ),
            isset($data['template']) => (string) $this->templates->find($data['template'])['css'].$extra,
            isset($data['changes']) => $this->edited($this->theme->editing($context->workspace, $context->user), $data['changes']),
            default => (string) ($data['css'] ?? ''),
        };

        try {
            $this->theme->saveDraft($context->workspace, $context->user, $css);
        } catch (InvalidArgumentException $e) {
            throw new ToolError('Nothing was saved. '.$e->getMessage().' Send it again straight away without that part (leave pictures out if they aren’t uploaded yet), then tell the person what you left out and why.');
        }

        return [
            'summary' => 'Draft saved, showing to you now',
            'saved' => 'draft',
            'css' => isset($data['changes']) ? $css : null,
            'bytes' => strlen($css),
            'template' => $data['template'] ?? null,
            'note' => 'The person sees this draft now on every dashboard page. Ask what they think; publish only when they say it’s right.',
            'themeSettings' => $context->url('/settings/theme'),
        ];
    }

    /** @param  list<array{find: string, replace: string|null}>  $changes */
    private function edited(string $css, array $changes): string
    {
        foreach ($changes as $i => $change) {
            $found = substr_count($css, $change['find']);
            if ($found !== 1) {
                throw new ToolError('Change '.($i + 1).': its find text is in the CSS '.($found === 0 ? 'nowhere' : "{$found} times").'. Read the current CSS with get_dashboard_theme and use text that appears exactly once, or send the whole css.');
            }
            $css = str_replace($change['find'], (string) $change['replace'], $css);
        }

        return $css;
    }

    /** @return array<string, mixed> */
    private function modeSchema(string $description): array
    {
        $colour = fn (string $what) => Schema::string("Hex colour: {$what}", maxLength: 7);

        return Schema::object([
            'background' => $colour('the page'),
            'text' => $colour('text'),
            'accent' => $colour('buttons, links, focus and highlights'),
            'surface' => $colour('cards, tables and menus (optional)'),
            'panel' => $colour('behind the page and the sidebar (optional)'),
            'muted' => $colour('quiet text (optional)'),
            'border' => $colour('lines (optional)'),
            'accent_text' => $colour('text on accent buttons (optional)'),
            'green' => $colour('healthy and running (optional)'),
            'amber' => $colour('warnings (optional)'),
            'red' => $colour('errors and down (optional)'),
        ], ['background', 'text', 'accent']) + ['description' => $description];
    }

    /**
     * @param  array<string, mixed>|null  $colours
     * @return array<string, string>|null
     */
    private static function colours(?array $colours): ?array
    {
        if ($colours === null) {
            return null;
        }

        return array_filter(
            array_intersect_key($colours, array_flip(ThemeBuilder::COLOURS)),
            fn ($value) => is_string($value) && $value !== '',
        );
    }

    /** @return array<string, list<string>> */
    private static function colourRules(string $mode): array
    {
        $rules = [];
        foreach (ThemeBuilder::COLOURS as $colour) {
            $needed = in_array($colour, ['background', 'text', 'accent'], true);
            $rules["palette.{$mode}.{$colour}"] = [$needed ? "required_with:palette.{$mode}" : 'nullable', 'nullable', 'regex:'.ThemeBuilder::HEX];
        }

        return $rules;
    }
}
