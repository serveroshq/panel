<?php

namespace App\Support\Integrations\Linear;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\Response;

/**
 * Linear's GraphQL API (developers.linear.app). A personal API key goes in
 * the Authorization header as it is. GraphQL errors can come with a 200.
 */
final class LinearClient extends ProviderClient
{
    public const string URL = 'https://api.linear.app';

    public function __construct(private readonly string $key) {}

    protected function baseUrl(): string
    {
        return self::URL;
    }

    protected function providerName(): string
    {
        return 'Linear';
    }

    protected function headers(): array
    {
        return ['Authorization' => $this->key];
    }

    protected function providerMessage(Response $response): ?string
    {
        $errors = $response->json('errors');

        return is_array($errors) ? self::said($errors) : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        $errors = $response->json('errors');

        return is_array($errors) && $errors !== [] ? $this->fromErrors($errors, $response->status()) : null;
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws IntegrationFailed
     */
    public function query(string $query, array $variables = []): array
    {
        $body = $this->post('graphql', ['query' => $query, 'variables' => (object) $variables]);
        if (is_array($body['errors'] ?? null) && $body['errors'] !== []) {
            throw $this->fromErrors($body['errors'], 200);
        }

        return is_array($body['data'] ?? null) ? $body['data'] : [];
    }

    /** @param  array<mixed>  $errors */
    private function fromErrors(array $errors, int $status): IntegrationFailed
    {
        $first = is_array($errors[0] ?? null) ? $errors[0] : [];
        $code = strtoupper((string) ($first['extensions']['code'] ?? ''));
        $type = strtolower((string) ($first['extensions']['type'] ?? ''));
        $said = self::said($errors);

        return match (true) {
            $code === 'AUTHENTICATION_ERROR' || $type === 'authentication error' || $status === 401 => new IntegrationFailed($this->t('Linear turned the API key down. Check it’s copied in full and hasn’t been revoked.'), IntegrationFailed::CREDENTIALS, $status),
            $code === 'FORBIDDEN' || $type === 'forbidden' || $status === 403 => new IntegrationFailed($this->t('Linear says this key isn’t allowed to do that: :reason', ['reason' => rtrim((string) $said, '.').'.']), IntegrationFailed::PERMISSION, $status),
            $code === 'RATELIMITED' || $status === 429 => new IntegrationFailed($this->t(':provider is limiting requests from this account right now. Try again in a few minutes.'), IntegrationFailed::RATE_LIMITED, $status),
            $status >= 500 => new IntegrationFailed($this->t(':provider isn’t answering properly right now. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE, $status),
            str_contains(strtolower((string) $said), 'not found') => new IntegrationFailed($this->t(':provider can’t find it. It may have been deleted there.'), IntegrationFailed::NOT_FOUND, $status),
            default => new IntegrationFailed($said !== null ? $this->t('Linear said: :reason', ['reason' => rtrim($said, '.').'.']) : $this->t(':provider turned the request down.'), IntegrationFailed::INVALID, $status),
        };
    }

    /** @param  array<mixed>  $errors */
    private static function said(array $errors): ?string
    {
        $first = is_array($errors[0] ?? null) ? $errors[0] : [];
        $message = $first['extensions']['userPresentableMessage'] ?? $first['message'] ?? null;

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }
}
