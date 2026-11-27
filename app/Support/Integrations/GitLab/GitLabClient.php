<?php

namespace App\Support\Integrations\GitLab;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use App\Support\Monitors\HostResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The GitLab REST API (docs.gitlab.com/api) of gitlab.com or a self-managed
 * instance. The instance's address is resolved for every request and the
 * connection pinned to that answer, so it can never be pointed into the
 * panel's own network, and redirects aren't followed.
 */
final class GitLabClient extends ProviderClient
{
    public const string GITLAB_COM = 'https://gitlab.com';

    private const int PAGE = 100;

    private const array LINK_LOCAL = ['169.254.0.0/16', 'fe80::/10'];

    public function __construct(private readonly string $instance, private readonly string $token) {}

    /**
     * The instance's address as it's kept: https, a host, an optional port
     * and path, no trailing slash. Null when it isn't one.
     */
    public static function normalise(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return self::GITLAB_COM;
        }
        if (! preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'https://'.$url;
        }
        $parts = parse_url($url);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || ($parts['host'] ?? '') === ''
            || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }
        $host = strtolower($parts['host']);
        if (preg_match('/^(?:\[[0-9a-f:.]+\]|[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)*)$/', $host) !== 1) {
            return null;
        }
        $path = rtrim((string) preg_replace('#/api/v4/?$#', '', rtrim($parts['path'] ?? '', '/')), '/');
        if ($path !== '' && preg_match('#^(?:/[A-Za-z0-9._~-]+){1,5}$#', $path) !== 1) {
            return null;
        }
        $port = isset($parts['port']) && $parts['port'] !== 443 ? ':'.$parts['port'] : '';

        return "https://{$host}{$port}{$path}";
    }

    public static function host(string $instance): string
    {
        return (string) parse_url($instance, PHP_URL_HOST);
    }

    protected function baseUrl(): string
    {
        return $this->instance.'/api/v4';
    }

    protected function providerName(): string
    {
        return 'GitLab';
    }

    protected function headers(): array
    {
        return $this->token === '' ? ['User-Agent' => 'ServerOS'] : ['PRIVATE-TOKEN' => $this->token, 'User-Agent' => 'ServerOS'];
    }

    protected function providerMessage(Response $response): ?string
    {
        $message = $response->json('message') ?? $response->json('error_description') ?? $response->json('error');
        if (is_array($message)) {
            $message = collect($message)->flatten()->filter(fn ($line) => is_string($line))->implode(' ');
        }

        return is_string($message) && $message !== '' ? (string) preg_replace('/^\d{3} /', '', $message) : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        if ($response->status() === 403 && $response->json('error') === 'insufficient_scope') {
            $scope = is_string($response->json('scope')) ? explode(' ', (string) $response->json('scope'))[0] : 'api';

            return new IntegrationFailed($this->t('This GitLab token needs the :scope scope for that. Make a token with it, then connect it again.', ['scope' => $scope]), IntegrationFailed::PERMISSION, 403);
        }

        return null;
    }

    /**
     * Every page of a list, up to five hundred items.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function all(string $path, array $query = [], int $pages = 5): array
    {
        $items = [];
        for ($page = 1; $page <= $pages; $page++) {
            $body = $this->get($path, [...$query, 'page' => $page, 'per_page' => self::PAGE]);
            foreach ($body as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            if (count($body) < self::PAGE) {
                break;
            }
        }

        return $items;
    }

    /**
     * A file's raw contents, or null when it isn't there.
     *
     * @param  array<string, mixed>  $query
     *
     * @throws IntegrationFailed
     */
    public function raw(string $path, array $query = []): ?string
    {
        try {
            $response = $this->request()->get(ltrim($path, '/'), $query);
        } catch (ConnectionException) {
            throw new IntegrationFailed($this->t(':provider couldn’t be reached. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE);
        }

        return $response->successful() && strlen($response->body()) <= 512 * 1024 ? $response->body() : null;
    }

    public static function project(string $path): string
    {
        return 'projects/'.rawurlencode($path);
    }

    protected function request(): PendingRequest
    {
        $host = trim(self::host($this->instance), '[]');
        $addresses = app(HostResolver::class)->resolve($host);
        if ($addresses === [] || array_filter($addresses, fn (string $ip) => IpUtils::checkIp($ip, self::LINK_LOCAL)) !== []) {
            throw new IntegrationFailed($this->t('ServerOS can’t reach :host, or it points into a private network.', ['host' => $host]), IntegrationFailed::INVALID);
        }

        $options = ['allow_redirects' => false];
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            $port = parse_url($this->instance, PHP_URL_PORT) ?? 443;
            $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
            $options['curl'] = [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]];
        }

        return parent::request()->withOptions($options);
    }
}
