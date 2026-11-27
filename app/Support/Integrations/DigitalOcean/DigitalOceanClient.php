<?php

namespace App\Support\Integrations\DigitalOcean;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\Response;

/**
 * The DigitalOcean API (docs.digitalocean.com/reference/api). Tokens belong to one team.
 */
final class DigitalOceanClient extends ProviderClient
{
    public const string URL = 'https://api.digitalocean.com/v2';

    private const int PAGE = 200;

    private const int MAX_PAGES = 20;

    public function __construct(private readonly string $token) {}

    protected function baseUrl(): string
    {
        return self::URL;
    }

    protected function providerName(): string
    {
        return 'DigitalOcean';
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    protected function providerMessage(Response $response): ?string
    {
        $message = $response->json('message');

        return is_string($message) ? $message : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        $said = strtolower((string) $this->providerMessage($response));

        return match (true) {
            $response->status() === 403 => new IntegrationFailed($this->t('DigitalOcean says this token can’t do that. Make a token with Full Access, or with write scopes for droplets, and connect it again.'), IntegrationFailed::PERMISSION, 403),
            $response->status() === 422 && str_contains($said, 'pending event') => new IntegrationFailed($this->t('DigitalOcean is busy with another action on this droplet. Try again in a minute.'), IntegrationFailed::CONFLICT, 422),
            $response->status() === 422 && str_contains($said, 'droplet limit') => new IntegrationFailed($this->t('This team has reached its droplet limit at DigitalOcean. Raise it there, or delete a droplet first.'), IntegrationFailed::INVALID, 422),
            default => null,
        };
    }

    /**
     * Every page of a list, up to four thousand items.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function all(string $path, string $key, array $query = []): array
    {
        $items = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = $this->get($path, [...$query, 'page' => $page, 'per_page' => self::PAGE]);
            foreach ((array) ($body[$key] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            if (! is_string($body['links']['pages']['next'] ?? null)) {
                break;
            }
        }

        return $items;
    }
}
