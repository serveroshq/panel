<?php

namespace App\Support\Integrations\Cloudflare;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\Response;

/**
 * The Cloudflare API v4 (developers.cloudflare.com/api), with a scoped API
 * token. Every answer is wrapped in {success, errors, result, result_info}.
 */
final class CloudflareClient extends ProviderClient
{
    public const string URL = 'https://api.cloudflare.com/client/v4';

    private const int PAGE = 50;

    private const int MAX_PAGES = 20;

    public function __construct(private readonly string $token) {}

    protected function baseUrl(): string
    {
        return self::URL;
    }

    protected function providerName(): string
    {
        return 'Cloudflare';
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    protected function providerMessage(Response $response): ?string
    {
        $messages = array_filter(array_map(
            fn ($error) => is_array($error) && is_string($error['message'] ?? null) ? $error['message'] : null,
            (array) $response->json('errors', []),
        ));

        return $messages === [] ? null : implode(' ', $messages);
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        $codes = array_map(fn ($error) => is_array($error) ? (int) ($error['code'] ?? 0) : 0, (array) $response->json('errors', []));
        $said = $this->providerMessage($response);

        return match (true) {
            array_intersect($codes, [1000, 6003, 6111, 9106]) !== [] => new IntegrationFailed($this->t(':provider turned the token down. Check it’s copied in full and hasn’t been deleted.'), IntegrationFailed::CREDENTIALS, $response->status()),
            array_intersect($codes, [9109, 10000]) !== [] => new IntegrationFailed($this->t('Cloudflare says this token isn’t allowed to do that. Give it Zone Read, DNS Edit and Cache Purge for this zone.'), IntegrationFailed::PERMISSION, $response->status()),
            array_intersect($codes, [81053, 81057, 81058]) !== [] => new IntegrationFailed($this->t('Cloudflare already has a record with that name: :reason', ['reason' => rtrim((string) $said, '.').'.']), IntegrationFailed::CONFLICT, $response->status()),
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    protected function send(string $method, string $path, array $options): array
    {
        $body = parent::send($method, $path, $options);
        if (($body['success'] ?? true) === false) {
            $said = implode(' ', array_filter(array_map(fn ($e) => is_array($e) ? (string) ($e['message'] ?? '') : '', (array) ($body['errors'] ?? []))));

            throw new IntegrationFailed($said !== '' ? $said : $this->t(':provider turned the request down.'));
        }

        return $body;
    }

    /**
     * Every page of a list, up to a thousand items.
     *
     * @param  array<string, mixed>  $query
     * @return list<array<string, mixed>>
     */
    public function all(string $path, array $query = []): array
    {
        $items = [];

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $body = $this->get($path, [...$query, 'page' => $page, 'per_page' => self::PAGE]);
            foreach ((array) ($body['result'] ?? []) as $item) {
                if (is_array($item)) {
                    $items[] = $item;
                }
            }
            $pages = $body['result_info']['total_pages'] ?? null;
            if (! is_int($pages) || $page >= $pages) {
                break;
            }
        }

        return $items;
    }
}
