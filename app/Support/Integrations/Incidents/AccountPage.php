<?php

namespace App\Support\Integrations\Incidents;

use App\Models\Integration;
use App\Models\IntegrationApp;
use App\Models\IntegrationEvent;
use App\Models\IntegrationIncident;
use App\Support\Integrations\Provider;
use App\Support\Integrations\Sentry\SentryProvider;

/** The account page's parts for providers ServerOS sends things to. */
final class AccountPage
{
    public const int EVENTS = 20;

    /** @return array{routing: array<string, mixed>|null, events: list<array<string, mixed>>|null, incidents: list<array<string, mixed>>|null, linkedApps: list<array<string, mixed>>|null, canTest: bool} */
    public static function props(Integration $integration, Provider $provider): array
    {
        return [
            'routing' => $provider instanceof RoutesIncidents ? $provider->settingsProps($integration) : null,
            'events' => $provider instanceof DeliversEvents ? self::events($integration, $provider) : null,
            'incidents' => $provider instanceof RoutesIncidents ? self::incidents($integration) : null,
            'linkedApps' => $provider instanceof SentryProvider ? self::apps($integration) : null,
            'canTest' => $provider->key() === 'pagerduty',
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function events(Integration $integration, DeliversEvents $provider): array
    {
        return array_values($integration->events()->with('incident')->latest('id')->limit(self::EVENTS)->get()
            ->map(fn (IntegrationEvent $event) => [
                'id' => $event->id,
                'action' => $event->action,
                'label' => $provider->eventLabel($event),
                'subject' => $event->subject,
                'state' => $event->state,
                'error' => $event->error,
                'attempts' => $event->attempts,
                'nextAttemptAt' => $event->next_attempt_at?->toIso8601String(),
                'createdAt' => $event->created_at?->toIso8601String(),
                'link' => $event->incident?->link() ?? (is_string($event->result['url'] ?? null) ? ['key' => null, 'url' => $event->result['url']] : null),
            ])
            ->all());
    }

    /** @return list<array<string, mixed>> */
    public static function incidents(Integration $integration): array
    {
        return array_values($integration->incidents()->whereNotNull('open_key')->latest('opened_at')->limit(20)->get()
            ->map(fn (IntegrationIncident $incident) => [
                'id' => $incident->id,
                'kind' => $incident->kind,
                'title' => $incident->title,
                'openedAt' => $incident->opened_at->toIso8601String(),
                'link' => $incident->link(),
            ])
            ->all());
    }

    /** @return list<array<string, mixed>> */
    public static function apps(Integration $integration): array
    {
        return array_values($integration->apps()->with('machine:id,name')->orderBy('service')->get()
            ->map(fn (IntegrationApp $app) => [
                'id' => $app->id,
                'service' => $app->service,
                'machine' => $app->machine->name,
                'href' => '/machines/'.$app->machine_id.'/services/app:'.rawurlencode($app->service),
                'project' => $app->setting('name') ?? $app->setting('project'),
                'environment' => $app->setting('environment'),
            ])
            ->all());
    }
}
