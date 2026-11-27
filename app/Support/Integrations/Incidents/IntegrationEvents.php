<?php

namespace App\Support\Integrations\Incidents;

use App\Jobs\DeliverIntegrationEvent;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\IntegrationIncident;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Servers\CloudServers;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends IntegrationEvents one at a time per incident, in order, and tries the
 * ones that failed for a passing reason again later.
 */
final class IntegrationEvents
{
    public const int TRIES = 5;

    /** Seconds before each next try. */
    public const array BACKOFF = [60, 300, 900, 3600];

    private const array PASSING = [IntegrationFailed::UNAVAILABLE, IntegrationFailed::RATE_LIMITED, IntegrationFailed::CONFLICT];

    private const int STUCK_MINUTES = 10;

    /** @param  array<string, mixed>  $payload */
    public static function queue(Integration $integration, string $action, string $subject, array $payload = [], ?IntegrationIncident $incident = null, bool $dispatch = true): IntegrationEvent
    {
        $event = $integration->events()->create([
            'integration_incident_id' => $incident?->id,
            'action' => $action,
            'subject' => mb_substr($subject, 0, 200),
            'payload' => $payload,
            'state' => 'queued',
        ]);
        if ($dispatch) {
            DeliverIntegrationEvent::dispatch($event->id);
        }

        return $event;
    }

    public function deliver(IntegrationEvent $event): void
    {
        if ($event->integration_incident_id !== null && IntegrationEvent::query()
            ->where('integration_incident_id', $event->integration_incident_id)
            ->where('id', '<', $event->id)
            ->whereIn('state', IntegrationEvent::PENDING)
            ->exists()) {
            return;
        }
        $claimed = IntegrationEvent::query()->whereKey($event->id)->whereIn('state', ['queued', 'retrying'])
            ->update(['state' => 'sending', 'updated_at' => now()]);
        if ($claimed === 0) {
            return;
        }
        $event->refresh();
        $integration = $event->integration;
        $provider = $integration->provider();
        $attempts = $event->attempts + 1;

        try {
            if (! $provider instanceof DeliversEvents) {
                throw new IntegrationFailed(__('This account can’t send anything.'));
            }
            $result = $provider->deliver($integration, $event);
            $event->forceFill([
                'state' => ($result['skipped'] ?? false) === true ? 'skipped' : 'sent',
                'attempts' => $attempts,
                'result' => $result === [] ? null : $result,
                'error' => null,
                'next_attempt_at' => null,
                'sent_at' => now(),
            ])->save();
        } catch (IntegrationFailed $e) {
            $again = in_array($e->reason, self::PASSING, true) && $attempts < self::TRIES;
            $event->forceFill([
                'state' => $again ? 'retrying' : 'failed',
                'attempts' => $attempts,
                'error' => $e->getMessage(),
                'next_attempt_at' => $again ? now()->addSeconds(self::BACKOFF[$attempts - 1] ?? 3600) : null,
            ])->save();
            if ($e->refusedCredentials() || $e->reason === IntegrationFailed::PERMISSION) {
                CloudServers::failed($integration, $e);
            }
            if ($again) {
                return;
            }
        } catch (Throwable $e) {
            report($e);
            Log::warning('Integration event failed', ['event' => $event->id, 'error' => $e->getMessage()]);
            $again = $attempts < self::TRIES;
            $event->forceFill([
                'state' => $again ? 'retrying' : 'failed',
                'attempts' => $attempts,
                'error' => __('Something went wrong sending it.'),
                'next_attempt_at' => $again ? now()->addSeconds(self::BACKOFF[$attempts - 1] ?? 3600) : null,
            ])->save();
            if ($again) {
                return;
            }
        }

        $this->next($event);
    }

    /** Puts a failed event back in line, as if new. */
    public function retry(IntegrationEvent $event): void
    {
        if ($event->state !== 'failed') {
            return;
        }
        $event->forceFill(['state' => 'queued', 'attempts' => 0, 'error' => null, 'next_attempt_at' => null])->save();
        DeliverIntegrationEvent::dispatch($event->id);
    }

    /** Sends whatever is due to be tried again. Returns how many. */
    public function retryDue(): int
    {
        IntegrationEvent::query()->where('state', 'sending')->where('updated_at', '<', now()->subMinutes(self::STUCK_MINUTES))
            ->update(['state' => 'retrying', 'next_attempt_at' => now()]);

        $due = IntegrationEvent::query()
            ->where(fn ($q) => $q->where('state', 'retrying')->where('next_attempt_at', '<=', now())
                ->orWhere(fn ($q) => $q->where('state', 'queued')->where('created_at', '<', now()->subMinutes(self::STUCK_MINUTES))))
            ->orderBy('id')
            ->limit(200)
            ->pluck('id');
        foreach ($due as $id) {
            DeliverIntegrationEvent::dispatch((int) $id);
        }

        return $due->count();
    }

    private function next(IntegrationEvent $event): void
    {
        if ($event->integration_incident_id === null) {
            return;
        }
        $next = IntegrationEvent::query()
            ->where('integration_incident_id', $event->integration_incident_id)
            ->where('id', '>', $event->id)
            ->where('state', 'queued')
            ->orderBy('id')
            ->value('id');
        if ($next !== null) {
            DeliverIntegrationEvent::dispatch((int) $next);
        }
    }
}
