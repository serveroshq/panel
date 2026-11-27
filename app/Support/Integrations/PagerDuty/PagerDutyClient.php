<?php

namespace App\Support\Integrations\PagerDuty;

use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\ProviderClient;
use Illuminate\Http\Client\Response;

/**
 * PagerDuty's Events API v2 (developer.pagerduty.com). The routing key goes
 * in each event's body, not a header.
 */
final class PagerDutyClient extends ProviderClient
{
    public const string URL = 'https://events.pagerduty.com/v2';

    protected function baseUrl(): string
    {
        return self::URL;
    }

    protected function providerName(): string
    {
        return 'PagerDuty';
    }

    protected function headers(): array
    {
        return [];
    }

    protected function providerMessage(Response $response): ?string
    {
        $errors = $response->json('errors');
        if (is_array($errors) && $errors !== []) {
            return implode(' ', array_map('strval', array_filter($errors, 'is_scalar')));
        }
        $message = $response->json('message');

        return is_string($message) ? $message : null;
    }

    protected function failure(Response $response): ?IntegrationFailed
    {
        if ($response->status() === 400 && str_contains(strtolower((string) $this->providerMessage($response)), 'routing')) {
            return new IntegrationFailed($this->t('PagerDuty doesn’t know this routing key. Copy the Integration Key from the service’s Events API v2 integration and connect it again.'), IntegrationFailed::CREDENTIALS, 400);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    public function enqueue(array $event): array
    {
        return $this->post('enqueue', $event);
    }
}
