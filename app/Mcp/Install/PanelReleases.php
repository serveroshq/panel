<?php

namespace App\Mcp\Install;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The panel's releases on GitHub, for the install MCP: which version to
 * install, and where its install script is.
 */
class PanelReleases
{
    public const REPO = 'serveroshq/panel';

    /**
     * The script from main, like the self-hosting docs: it stays able to
     * install every published image, and --version pins the one to run.
     */
    public const SCRIPT = 'https://raw.githubusercontent.com/serveroshq/panel/main/docker/self-hosted/install.sh';

    public const IMAGE = 'ghcr.io/serveroshq/panel';

    /** @return array{version: string, tag: string, published_at: string|null, url: string, notes: string}|null */
    public function latest(): ?array
    {
        /** @var array{version: string, tag: string, published_at: string|null, url: string, notes: string}|false $release */
        $release = Cache::remember('install-mcp:latest-release', now()->addMinutes(10), function (): array|false {
            try {
                $response = Http::timeout(5)
                    ->acceptJson()
                    ->withHeaders(['User-Agent' => 'ServerOS install MCP'])
                    ->get('https://api.github.com/repos/'.self::REPO.'/releases/latest');
            } catch (Throwable) {
                return false;
            }

            $tag = $response->json('tag_name');
            if (! $response->successful() || ! is_string($tag) || ! preg_match('/^v?(\d+\.\d+\.\d+)$/', $tag, $m)) {
                return false;
            }

            $published = $response->json('published_at');
            $url = $response->json('html_url');
            $notes = $response->json('body');

            return [
                'version' => $m[1],
                'tag' => $tag,
                'published_at' => is_string($published) ? $published : null,
                'url' => is_string($url) ? $url : 'https://github.com/'.self::REPO.'/releases/tag/'.$tag,
                'notes' => is_string($notes) ? Str::limit(trim($notes), 1500) : '',
            ];
        });

        return $release === false ? null : $release;
    }
}
