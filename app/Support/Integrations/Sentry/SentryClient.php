<?php

namespace App\Support\Integrations\Sentry;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\Response;

/**
 * Sentry's web API (docs.sentry.io/api). Organization calls go to the
 * organization's region (us.sentry.io, de.sentry.io) once it's known.
 */
final class SentryClient extends ProviderClient
{
    public const string URL = 'https://sentry.io';

    public function __construct(private readonly string $token, private readonly string $base = self::URL) {}

    protected function baseUrl(): string
    {
        return rtrim($this->base, '/').'/api/0';
    }

    protected function providerName(): string
    {
        return 'Sentry';
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer '.$this->token];
    }

    protected function providerMessage(Response $response): ?string
    {
        $detail = $response->json('detail');
        if (is_string($detail)) {
            return $detail;
        }
        foreach ((array) $response->json() as $field => $errors) {
            if (is_array($errors) && is_string($errors[0] ?? null)) {
                return "{$field}: {$errors[0]}";
            }
        }

        return null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        return $response->status() === 403
            ? new IntegrationFailed($this->t('Sentry says this token can’t do that. Make one with the project:read, project:releases and org:read scopes.'), IntegrationFailed::PERMISSION, 403)
            : null;
    }
}
