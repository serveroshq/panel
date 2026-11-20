<?php

namespace App\Support\Theme;

/**
 * Builds a whole theme from a few colours per mode, the same way the
 * templates are made, so every part of the dashboard follows them.
 */
final class ThemeBuilder
{
    public const HEX = '/^#(?:[0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/';

    /** @var list<string> The colours a palette can name; background, text and accent are needed. */
    public const COLOURS = ['background', 'panel', 'surface', 'text', 'muted', 'border', 'accent', 'accent_text', 'green', 'amber', 'red'];

    private const STATUS = [
        'light' => ['green' => '#15803d', 'amber' => '#b45309', 'red' => '#b42318'],
        'dark' => ['green' => '#4ade80', 'amber' => '#fbbf24', 'red' => '#f87171'],
    ];

    /**
     * @param  array<string, string>|null  $light
     * @param  array<string, string>|null  $dark
     */
    public static function build(?array $light, ?array $dark, ?string $font = null, ?string $fontImport = null, string $extra = ''): string
    {
        $css = [];
        if ($fontImport !== null && $fontImport !== '') {
            $css[] = "@import url('".str_replace("'", '%27', $fontImport)."');";
        }

        $only = match (true) {
            $light === null && $dark !== null => 'dark',
            $dark === null && $light !== null => 'light',
            default => null,
        };
        if ($only !== null || $font) {
            $root = array_filter([
                $only !== null ? "    --theme-appearance: {$only};" : null,
                $font ? '    --font-ui: '.self::fontFamily($font).';' : null,
            ]);
            $css[] = "html:root {\n".implode("\n", $root)."\n}";
        }

        if ($light !== null) {
            $css[] = "/* Light mode */\n\n".self::block('html:root', self::roles($light, false), false);
        }
        if ($dark !== null) {
            $css[] = "/* Dark mode */\n\n".self::block('html.dark', self::roles($dark, true), true);
        }
        if (trim($extra) !== '') {
            $css[] = trim($extra);
        }

        return implode("\n\n", $css)."\n";
    }

    /**
     * @param  array<string, string>  $p
     * @return array<string, string>
     */
    private static function roles(array $p, bool $dark): array
    {
        $bg = $p['background'];
        $text = $p['text'];
        $surface = $p['surface'] ?? ($dark ? self::mix($text, 5, $bg) : self::mix('#ffffff', 70, $bg));
        $accent = $p['accent'];

        return [
            'bg0' => $p['panel'] ?? ($dark ? self::mix('#000000', 30, $bg) : self::mix($text, 6, $bg)),
            'bg1' => $bg,
            'bg2' => $surface,
            'bg3' => self::mix($text, 8, $surface),
            'bg4' => self::mix($text, 14, $surface),
            'line1' => $p['border'] ?? self::mix($text, 14, $surface),
            'line2' => self::mix($text, 22, $surface),
            'line3' => self::mix($text, 34, $surface),
            'fg' => $text,
            'fg2' => self::mix($text, 85, $surface),
            'fg3' => $p['muted'] ?? self::mix($text, 65, $surface),
            'fg4' => self::mix($text, 48, $surface),
            'accent' => $accent,
            'on' => $p['accent_text'] ?? (self::luminance($accent) > 0.45 ? '#0a0a0a' : '#ffffff'),
            'green' => $p['green'] ?? self::STATUS[$dark ? 'dark' : 'light']['green'],
            'amber' => $p['amber'] ?? self::STATUS[$dark ? 'dark' : 'light']['amber'],
            'red' => $p['red'] ?? self::STATUS[$dark ? 'dark' : 'light']['red'],
        ];
    }

    /** @param  array<string, string>  $p */
    private static function block(string $selector, array $p, bool $dark): string
    {
        $t = [
            'panel' => $p['bg0'], 'sidebar' => $p['bg0'], 'frame' => $p['bg1'], 'card' => $p['bg2'],
            'field' => $dark ? $p['bg1'] : $p['bg2'], 'subtle' => $p['bg1'],
            'hover' => $p['bg3'], 'row-hover' => self::mix($p['bg3'], 50, $p['bg2']), 'active' => $p['bg4'],
            'avatar' => $p['bg4'], 'segment-track' => $p['bg0'], 'segment-thumb' => $dark ? $p['bg4'] : $p['bg2'],
            'divider' => $p['line1'], 'border' => $p['line1'], 'border-strong' => $p['line2'],
            'border-stronger' => $p['line3'], 'border-hover' => $p['line3'], 'border-focus' => $p['line3'],
            'panel-ring' => $p['line1'],
            'text' => $p['fg'], 'text-strong' => $p['fg'], 'text-secondary' => $p['fg2'], 'text-muted' => $p['fg3'],
            'text-faint' => $p['fg4'], 'text-subtle' => $p['fg4'],
            'inverse' => $p['fg'], 'inverse-text' => $p['bg2'], 'code' => $p['bg1'],
            'ring' => $p['accent'], 'accent' => $p['accent'], 'accent-strong' => self::mix($p['accent'], 82, $p['fg']),
            'accent-soft' => self::mix($p['accent'], 60, $p['bg2']), 'accent-bg' => self::mix($p['accent'], 14, $p['bg2']),
            'accent-border' => self::mix($p['accent'], 40, $p['bg2']), 'accent-glow' => self::mix($p['accent'], 45, 'transparent'),
            'blue' => $p['accent'], 'blue-strong' => self::mix($p['accent'], 82, $p['fg']), 'blue-bg' => self::mix($p['accent'], 14, $p['bg2']),
            'blue-border' => self::mix($p['accent'], 40, $p['bg2']),
        ];
        foreach (['green', 'amber', 'red'] as $c) {
            $t[$c] = $p[$c];
            $t["{$c}-strong"] = self::mix($p[$c], 82, $p['fg']);
            $t["{$c}-bg"] = self::mix($p[$c], 14, $p['bg2']);
            $t["{$c}-border"] = self::mix($p[$c], 38, $p['bg2']);
        }
        $t += [
            'scrollbar' => $p['line3'], 'shimmer-base' => $p['bg3'], 'shimmer-sheen' => self::mix($p['fg'], 8, 'transparent'),
            'menu' => $p['bg2'], 'menu-icon' => $p['fg3'], 'menu-rule' => $p['line1'], 'menu-hover' => $p['bg3'],
            'choice-bg' => $p['bg2'], 'choice-text' => $p['fg2'], 'choice-text-hover' => $p['fg'], 'choice-selected-bg' => $p['bg3'],
            'button-bg' => $p['bg2'], 'button-text' => $p['fg2'], 'button-text-hover' => $p['fg'],
            'modal-bg' => $p['bg2'], 'tray-bg' => $p['bg1'], 'tray-tile-bg' => $p['bg2'],
            'nav-active-bg' => $dark ? $p['bg4'] : $p['bg2'], 'bar-bg' => $p['line1'],
            'primary-bg' => $p['accent'], 'primary-bg-hover' => self::mix($p['accent'], 85, $p['fg']),
            'primary-border' => self::mix($p['accent'], 80, $p['fg']), 'primary-border-hover' => self::mix($p['accent'], 70, $p['fg']),
            'primary-text' => $p['on'],
            'chart' => $p['accent'], 'chart-warn' => $p['amber'], 'chart-hot' => $p['red'], 'chart-empty' => $p['line1'],
        ];

        $lines = ["{$selector} {"];
        foreach ($t as $name => $value) {
            $lines[] = "    --ds-{$name}: {$value};";
        }
        array_push($lines, '}', '',
            "{$selector} .ds-tile,", "{$selector} .ds-tray > .ds-tile {",
            "    --tile-bg: {$p['bg2']};", "    --tile-head: {$p['bg2']};", "    --tile-inner: {$p['bg1']};",
            "    --tile-edge: {$p['line1']};", "    --tile-rule: {$p['line1']};", '    --tile-inset: transparent;', '}', '',
            "{$selector} .workspace-panel {", "    --primary: {$p['accent']};", "    --primary-foreground: {$p['on']};", '}');

        return implode("\n", $lines);
    }

    private static function mix(string $a, int $percent, string $b): string
    {
        return "color-mix(in srgb, {$a} {$percent}%, {$b})";
    }

    private static function fontFamily(string $font): string
    {
        $name = trim(str_replace(['"', "'", ';', '{', '}'], '', $font));

        return "'{$name}', ui-sans-serif, system-ui, sans-serif";
    }

    private static function luminance(string $hex): float
    {
        $hex = ltrim($hex, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        $channels = array_map(function (string $pair): float {
            $c = hexdec($pair) / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, str_split($hex, 2));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
