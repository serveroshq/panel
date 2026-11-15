<?php

namespace App\Support\Ai;

use App\Support\Edition;
use App\Support\Monitors\HostResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * An OpenAI-compatible API at an address the workspace chose, like OpenRouter,
 * DeepSeek, Groq or Ollama. The address is resolved once per request and the
 * connection pinned to that answer, so it can't be pointed into the panel's
 * own network; self-hosted panels may use private addresses.
 */
class CompatibleEndpoint
{
    public function __construct(private readonly HostResolver $resolver) {}

    /** The address as it's kept: http or https, a host, an optional path, nothing else. */
    public static function normalise(string $url): ?string
    {
        $url = rtrim(trim($url), '/');
        $parts = parse_url($url);
        if (! is_array($parts)
            || ! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
            || ($parts['host'] ?? '') === ''
            || isset($parts['user'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return null;
        }

        return $url;
    }

    /** @throws RuntimeException */
    public function request(string $base, string $key, int $timeout): PendingRequest
    {
        $parts = parse_url($base);
        $scheme = strtolower(is_array($parts) ? (string) ($parts['scheme'] ?? '') : '');
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';
        if ($host === '' || ! in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('That isn’t a web address.');
        }
        if ($scheme !== 'https' && ! Edition::selfHosted()) {
            throw new RuntimeException('The address has to start with https://.');
        }

        $bare = trim($host, '[]');
        $addresses = $this->resolver->resolveOnLan($bare);
        if ($addresses === []) {
            throw new RuntimeException("Couldn’t find {$bare}, or it points into a private network.");
        }

        $options = ['allow_redirects' => false];
        if (filter_var($bare, FILTER_VALIDATE_IP) === false) {
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]];
        }

        return Http::withToken($key)->connectTimeout(10)->timeout($timeout)->withOptions($options);
    }

    public static function url(string $base, string $path): string
    {
        return rtrim($base, '/').'/'.ltrim($path, '/');
    }

    public static function host(string $base): string
    {
        return (string) parse_url($base, PHP_URL_HOST);
    }
}
