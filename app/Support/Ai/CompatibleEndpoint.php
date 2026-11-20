<?php

namespace App\Support\Ai;

use App\Support\Edition;
use App\Support\Monitors\HostResolver;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\ResponseInterface;
use RuntimeException;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * An OpenAI-compatible API at an address the workspace chose, like OpenRouter,
 * DeepSeek, Groq or Ollama. The address is resolved once per request and the
 * connection pinned to that answer, so it can't be pointed into the panel's
 * own network. Self-hosted panels may use private addresses with
 * MONITORS_ALLOW_PRIVATE=true, as monitors and webhooks do; link-local
 * addresses, like the cloud metadata service, are always refused.
 */
class CompatibleEndpoint
{
    public const MAX_BYTES = 5 * 1024 * 1024;

    private const LINK_LOCAL = ['169.254.0.0/16', 'fe80::/10'];

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
        $addresses = $this->resolver->resolve($bare);
        if ($addresses === [] || array_filter($addresses, fn (string $ip) => IpUtils::checkIp($ip, self::LINK_LOCAL)) !== []) {
            throw new RuntimeException("Couldn’t find {$bare}, or it points into a private network."
                .(Edition::selfHosted() ? ' To use one, set MONITORS_ALLOW_PRIVATE=true.' : ''));
        }

        $options = [
            'allow_redirects' => false,
            'progress' => function (int $total, int $downloaded) use ($bare): void {
                if ($total > self::MAX_BYTES || $downloaded > self::MAX_BYTES) {
                    throw self::tooLarge($bare);
                }
            },
        ];
        if (filter_var($bare, FILTER_VALIDATE_IP) === false) {
            $port = $parts['port'] ?? ($scheme === 'https' ? 443 : 80);
            $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]];
        }

        return Http::withToken($key)->connectTimeout(10)->timeout($timeout)->withOptions($options)
            ->withResponseMiddleware(function (ResponseInterface $response) use ($bare): ResponseInterface {
                if ($response->getBody()->getSize() > self::MAX_BYTES) {
                    throw self::tooLarge($bare);
                }

                return $response;
            });
    }

    private static function tooLarge(string $host): RuntimeException
    {
        return new RuntimeException("{$host} sent back more than ".(self::MAX_BYTES / 1024 / 1024).' MB. Check the address.');
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
