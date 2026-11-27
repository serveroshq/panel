<?php

namespace App\Support\Integrations\Vultr;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\Response;

/**
 * The Vultr API v2 (vultr.com/api). Keys belong to one account.
 */
final class VultrClient extends ProviderClient
{
    public const string URL = 'https://api.vultr.com/v2';

    private const int PAGE = 500;

    private const int MAX_PAGES = 20;

    public function __construct(private readonly string $key) {}

    protected function baseUrl(): string
    {
        return self::URL;
    }

    protected function providerName(): string
    {
        return 'Vultr';
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->key];
    }

    protected function providerMessage(Response $response): ?string
    {
        $message = $response->json('error');

        return is_string($message) ? $message : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        $said = strtolower((string) $this->providerMessage($response));

        return match (true) {
            $response->status() === 401 && preg_match('/\bip\b/', $said) === 1 => new IntegrationFailed($this->t('Vultr won’t take this key from this panel’s address. Allow it under Access control in Vultr’s API settings.'), IntegrationFailed::PERMISSION, 401),
            $response->status() === 401 => new IntegrationFailed($this->t('Vultr turned the API key down. Check it’s copied in full and hasn’t been deleted.'), IntegrationFailed::CREDENTIALS, 401),
            $response->status() < 500 && str_contains($said, 'locked') => new IntegrationFailed($this->t('Vultr is busy with something else on this server. Try again in a minute.'), IntegrationFailed::CONFLICT, $response->status()),
            default => null,
        };
    }

    /**
     * Every page of a list, up to ten thousand items.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function all(string $path, string $key, array $query = []): array
    {
        $items = [];
        $cursor = null;

        for ($i = 0; $i < self::MAX_PAGES; $i++) {
            $body = $this->get($path, [...$query, 'per_page' => self::PAGE, ...($cursor === null ? [] : ['cursor' => $cursor])]);
            foreach ((array) ($body[$key] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $next = $body['meta']['links']['next'] ?? null;
            if (! is_string($next) || $next === '' || $next === $cursor) {
                break;
            }
            $cursor = $next;
        }

        return $items;
    }
}
