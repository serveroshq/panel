<?php

namespace App\Support\Theme;

use Illuminate\Support\Facades\File;

/** Ready-made themes in resources/themes, each a CSS file with a name, description and credit in its first comment. */
final class ThemeTemplates
{
    private const ORDER = ['starter', 'catppuccin', 'nord', 'rose-pine', 'gruvbox', 'solarized', 'dracula', 'high-contrast'];

    /** @var list<array{key: string, name: string, description: string, credit: string, preview: array{light: array<string, string>, dark: array<string, string>}, css: string}>|null */
    private ?array $all = null;

    /** @return list<array{key: string, name: string, description: string, credit: string, preview: array{light: array<string, string>, dark: array<string, string>}, css: string}> */
    public function all(): array
    {
        if ($this->all !== null) {
            return $this->all;
        }

        $templates = [];
        foreach (File::glob(resource_path('themes/*.css')) as $path) {
            $css = (string) file_get_contents($path);
            $key = basename($path, '.css');
            $templates[] = [
                'key' => $key,
                'name' => self::field($css, 'name') ?? $key,
                'description' => self::field($css, 'description') ?? '',
                'credit' => self::field($css, 'credit') ?? '',
                'preview' => ['light' => self::variables($css, 'html:root'), 'dark' => self::variables($css, 'html.dark')],
                'css' => $css,
            ];
        }

        usort($templates, fn (array $a, array $b) => self::rank($a['key']) <=> self::rank($b['key']));

        return $this->all = $templates;
    }

    /** @return array{key: string, name: string, description: string, credit: string, preview: array{light: array<string, string>, dark: array<string, string>}, css: string}|null */
    public function find(string $key): ?array
    {
        foreach ($this->all() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_column($this->all(), 'key');
    }

    private static function field(string $css, string $name): ?string
    {
        return preg_match('/^\s*\*\s*'.preg_quote($name, '/').':\s*(.+)$/m', $css, $m) ? trim($m[1]) : null;
    }

    /** @return array<string, string> The --ds-* variables a template sets for one mode, without the prefix. */
    public static function variables(string $css, string $selector): array
    {
        if (! preg_match('/^'.preg_quote($selector, '/').'\s*\{([^}]*)\}/m', $css, $block)) {
            return [];
        }
        preg_match_all('/--ds-([a-z-]+)\s*:\s*([^;]+);/', $block[1], $pairs, PREG_SET_ORDER);

        $variables = [];
        foreach ($pairs as [, $name, $value]) {
            $variables[$name] = trim($value);
        }

        return $variables;
    }

    private static function rank(string $key): int
    {
        $at = array_search($key, self::ORDER, true);

        return $at === false ? PHP_INT_MAX : $at;
    }
}
