<?php

namespace App\Support\Integrations\Hetzner;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\Response;

/**
 * The Hetzner Cloud API (docs.hetzner.cloud). Tokens belong to one project.
 */
final class HetznerClient extends ProviderClient
{
    public const string URL = 'https://api.hetzner.cloud/v1';

    private const int PAGE = 50;

    private const int MAX_PAGES = 20;

    public function __construct(private readonly string $token) {}

    protected function baseUrl(): string
    {
        return self::URL;
    }

    protected function providerName(): string
    {
        return 'Hetzner';
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    protected function providerMessage(Response $response): ?string
    {
        $message = $response->json('error.message');

        return is_string($message) ? $message : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        $code = $response->json('error.code');
        $said = $this->providerMessage($response);

        return match ($code) {
            'forbidden' => new IntegrationFailed($this->t('This token can only read. Make a Read & Write token in the Hetzner Console and connect it again.'), IntegrationFailed::PERMISSION, $response->status()),
            'resource_limit_exceeded' => new IntegrationFailed($this->t('Hetzner says this project has reached a limit: :reason', ['reason' => rtrim((string) $said, '.').'.']), IntegrationFailed::INVALID, $response->status()),
            'resource_unavailable', 'placement_error' => new IntegrationFailed($this->t('Hetzner can’t make that right now. Try another location or server type.'), IntegrationFailed::UNAVAILABLE, $response->status()),
            'locked' => new IntegrationFailed($this->t('Hetzner is busy with another action on this server. Try again in a minute.'), IntegrationFailed::CONFLICT, $response->status()),
            'protected' => new IntegrationFailed($this->t('This is protected at Hetzner. Turn its protection off in the Hetzner Console first.'), IntegrationFailed::CONFLICT, $response->status()),
            'uniqueness_error' => new IntegrationFailed($this->t('Something in this project already has that name. Pick another.'), IntegrationFailed::INVALID, $response->status()),
            'maintenance' => new IntegrationFailed($this->t('Hetzner is doing maintenance. Try again later.'), IntegrationFailed::UNAVAILABLE, $response->status()),
            default => null,
        };
    }

    /**
     * Every page of a list, up to a thousand items.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function all(string $path, string $key, array $query = []): array
    {
        $items = [];
        $page = 1;

        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $body = $this->get($path, [...$query, 'page' => $page, 'per_page' => self::PAGE]);
            foreach ((array) ($body[$key] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $next = $body['meta']['pagination']['next_page'] ?? null;
            if (! is_int($next) || $next <= $page) {
                break;
            }
            $page = $next;
        }

        return $items;
    }
}
