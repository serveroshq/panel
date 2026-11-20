<?php

namespace App\Support\Theme;

/**
 * Custom dashboard CSS is shown to everyone in a workspace. It can restyle
 * anything, but it can't load files from other sites (a selector like
 * input[value^=a] with a background from another site would leak what's on
 * the page), except fonts from the font services below, and it can't break
 * out of its <style> tag.
 */
final class ThemeCss
{
    public const MAX_BYTES = 40_000;

    /** Font services a theme may load from: they serve fonts and font CSS only. */
    public const FONT_HOSTS = ['fonts.googleapis.com', 'fonts.gstatic.com', 'fonts.bunny.net'];

    /** @return list<string> Hosts a theme may load from: the font services and this panel. */
    public static function allowedHosts(): array
    {
        $own = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            parse_url((string) config('filesystems.disks.public.url'), PHP_URL_HOST),
        ], fn ($host) => is_string($host) && $host !== '');

        return array_values(array_unique([...self::FONT_HOSTS, ...array_map('strtolower', $own)]));
    }

    /** Why this CSS can't be used, or null when it can. */
    public static function problem(string $css): ?string
    {
        if (strlen($css) > self::MAX_BYTES) {
            return 'Themes can be up to 40 KB of CSS.';
        }
        if (! mb_check_encoding($css, 'UTF-8')) {
            return 'The CSS isn’t valid UTF-8 text.';
        }
        if (str_contains($css, '<')) {
            return 'Themes can’t contain “<”. Write it as \3c in a content string.';
        }

        $plain = self::plain($css);

        foreach (['expression(' => 'Themes can’t use expression().',
            'javascript:' => 'Themes can’t contain javascript: links.',
            'vbscript:' => 'Themes can’t contain vbscript: links.',
            '-moz-binding' => 'Themes can’t use -moz-binding.',
            'behavior:' => 'Themes can’t use behavior.'] as $needle => $why) {
            if (str_contains($plain, $needle)) {
                return $why;
            }
        }

        $outside = preg_replace([
            '/"\s*data:[^"]*"/',
            "/'\\s*data:[^']*'/",
            '/url\(\s*data:[^)]*\)/',
        ], '""', $plain) ?? $plain;

        if (preg_match_all('/url\(\s*[\'"]?\s*([a-z][a-z0-9+.-]*):/', $outside, $schemes)) {
            foreach ($schemes[1] as $scheme) {
                if (! in_array($scheme, ['http', 'https'], true)) {
                    return "Themes can’t load {$scheme}: addresses. Use images on this panel or data: URLs.";
                }
            }
        }

        if (preg_match_all('#//\s*([a-z0-9.-]*)#', $outside, $hosts)) {
            foreach ($hosts[1] as $host) {
                if (! in_array(rtrim($host, '.'), self::allowedHosts(), true)) {
                    $shown = $host === '' ? 'another site' : $host;

                    return "Themes can’t load files from {$shown}. Upload images in Settings › Theme, or use data: URLs. Fonts can come from Google Fonts or Bunny Fonts.";
                }
            }
        }

        foreach (self::addresses($plain) as [$address, $import]) {
            if (($why = self::addressProblem($address, $import)) !== null) {
                return $why;
            }
        }

        return null;
    }

    /**
     * Everything in the CSS the browser would request: url(), src(), the
     * strings in image-set() and @import targets.
     *
     * @return list<array{0: string, 1: bool}> each address, and whether it's an @import
     */
    private static function addresses(string $plain): array
    {
        $found = [];
        preg_match_all('/@import\s+(?:url\(\s*)?(?:"([^"]*)"|\'([^\']*)\'|([^\s);]+))/', $plain, $imports, PREG_SET_ORDER);
        foreach ($imports as $m) {
            $found[] = [trim(($m[1] ?? '').($m[2] ?? '').($m[3] ?? '')), true];
        }
        preg_match_all('/(?<![a-z-])(?:url|src)\(\s*([\'"]?)(.*?)\1\s*\)/s', $plain, $calls, PREG_SET_ORDER);
        foreach ($calls as $m) {
            $found[] = [trim($m[2]), false];
        }
        preg_match_all('/(?:image-set|cross-fade|image)\(([^;}]*)/', $plain, $sets, PREG_SET_ORDER);
        foreach ($sets as $m) {
            preg_match_all('/"([^"]*)"|\'([^\']*)\'/', $m[1], $strings, PREG_SET_ORDER);
            foreach ($strings as $s) {
                $found[] = [trim(($s[1] ?? '').($s[2] ?? '')), false];
            }
        }

        return $found;
    }

    /**
     * Only fonts from the font services, images uploaded for themes and the
     * panel's own images. Nothing else on this panel: a theme mustn't make
     * the browser call the panel's pages as the person viewing it.
     */
    private static function addressProblem(string $address, bool $import): ?string
    {
        if ($import) {
            return preg_match('#^https://(fonts\.googleapis\.com|fonts\.bunny\.net)/[^\s]*$#', $address) === 1
                ? null
                : 'Themes can only @import font stylesheets from Google Fonts or Bunny Fonts.';
        }
        if ($address === '' || str_starts_with($address, '#') || str_starts_with($address, 'data:')) {
            return null;
        }

        $own = preg_match('#^https?://([a-z0-9.-]+)(/[^\s]*)$#', $address, $m) === 1 ? $m : null;
        if ($own !== null && in_array($own[1], self::FONT_HOSTS, true)) {
            return null;
        }
        $path = $own !== null ? $own[2] : $address;
        if (preg_match('#^/(storage/(workspace-themes|theme-shares)|images|build/assets)/[a-z0-9._/-]+$#', $path) === 1 && ! str_contains($path, '..')) {
            return null;
        }

        return 'Themes can only use images uploaded in Settings › Theme (url(/storage/…)), the panel’s own images, data: URLs, and fonts from Google Fonts or Bunny Fonts. “'.mb_strimwidth($address, 0, 60, '…').'” isn’t one of those.';
    }

    /** The CSS as the browser reads it: comments gone, escapes decoded, lower case. */
    private static function plain(string $css): string
    {
        $css = preg_replace('#/\*.*?(\*/|$)#s', '', $css) ?? $css;
        $css = preg_replace("/\\\\\r?\n/", '', $css) ?? $css;
        $css = preg_replace_callback('/\\\\([0-9a-fA-F]{1,6})[ \t\r\n\f]?/', function (array $m): string {
            $code = (int) hexdec($m[1]);

            return $code > 0 && $code <= 0x10FFFF ? (mb_chr($code, 'UTF-8') ?: '') : '';
        }, $css) ?? $css;
        $css = preg_replace('/\\\\(.)/su', '$1', $css) ?? $css;

        return mb_strtolower($css, 'UTF-8');
    }
}
