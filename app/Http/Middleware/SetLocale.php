<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public const string COOKIE = 'locale';

    /**
     * Map browser language tags onto a locale ServerOS ships.
     *
     * @var array<string, string>
     */
    private const array ALIASES = [
        'zh' => 'zh-CN',
        'zh-cn' => 'zh-CN',
        'zh-hans' => 'zh-CN',
        'zh-hans-cn' => 'zh-CN',
        'zh-sg' => 'zh-CN',
        'zh-hans-sg' => 'zh-CN',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = self::for($request);

        App::setLocale($locale);
        Carbon::setLocale(str_replace('-', '_', $locale));

        return $next($request);
    }

    public static function for(Request $request): string
    {
        $supported = array_map('strval', array_keys(config('app.locales')));
        try {
            $user = $request->user();
        } catch (\Throwable) {
            $user = null;
        }
        $chosen = $user->locale ?? $request->cookie(self::COOKIE);

        if (is_string($chosen) && in_array($chosen, $supported, true)) {
            return $chosen;
        }

        return self::fromBrowser($request, $supported)
            ?? (string) config('app.fallback_locale', 'en');
    }

    /**
     * @param  list<string>  $supported
     */
    private static function fromBrowser(Request $request, array $supported): ?string
    {
        $lookup = [];
        foreach ($supported as $code) {
            $lookup[strtolower(str_replace('_', '-', $code))] = $code;
        }

        foreach ($request->getLanguages() as $lang) {
            $norm = strtolower(str_replace('_', '-', (string) $lang));
            if ($norm === '') {
                continue;
            }
            if (isset($lookup[$norm])) {
                return $lookup[$norm];
            }
            if (isset(self::ALIASES[$norm]) && in_array(self::ALIASES[$norm], $supported, true)) {
                return self::ALIASES[$norm];
            }
        }

        $browser = $request->getPreferredLanguage($supported);

        return is_string($browser) && in_array($browser, $supported, true) ? $browser : null;
    }
}
