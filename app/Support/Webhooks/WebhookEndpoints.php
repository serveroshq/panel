<?php

namespace App\Support\Webhooks;

use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/** Adding, changing and showing webhook endpoints, for the API tools and the settings page alike. */
final class WebhookEndpoints
{
    public function __construct(private readonly Webhooks $webhooks) {}

    /** @return array<string, mixed> */
    public static function rules(bool $creating): array
    {
        return [
            'url' => [$creating ? 'required' : 'sometimes', 'string', 'max:2000'],
            'events' => ['sometimes', 'nullable', 'array', 'max:'.count(Webhooks::EVENTS)],
            'events.*' => ['string', Rule::in(Webhooks::EVENTS)],
            'description' => ['sometimes', 'nullable', 'string', 'max:120'],
            'enabled' => [$creating ? 'prohibited' : 'sometimes', 'boolean'],
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: WebhookEndpoint, 1: string}
     *
     * @throws ValidationException
     */
    public function create(Workspace $workspace, User $by, array $input): array
    {
        $data = Validator::make($input, self::rules(true))->validate();
        $this->checkUrl($data['url']);
        if (WebhookEndpoint::query()->where('workspace_id', $workspace->id)->count() >= Webhooks::MAX_ENDPOINTS) {
            throw ValidationException::withMessages(['url' => 'A workspace can have '.Webhooks::MAX_ENDPOINTS.' webhook endpoints. Remove one first.']);
        }

        $secret = Webhooks::secret();
        $endpoint = WebhookEndpoint::query()->create([
            'workspace_id' => $workspace->id,
            'created_by' => $by->id,
            'url' => trim($data['url']),
            'description' => filled($data['description'] ?? null) ? trim($data['description']) : null,
            'events' => self::events($data['events'] ?? null),
            'secret' => $secret,
            'enabled' => true,
        ]);

        return [$endpoint, $secret];
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException
     */
    public function update(WebhookEndpoint $endpoint, array $input): WebhookEndpoint
    {
        $data = Validator::make($input, self::rules(false))->validate();
        if (isset($data['url'])) {
            $this->checkUrl($data['url']);
            $endpoint->url = trim($data['url']);
        }
        if (array_key_exists('events', $data)) {
            $endpoint->events = self::events($data['events']);
        }
        if (array_key_exists('description', $data)) {
            $endpoint->description = filled($data['description']) ? trim((string) $data['description']) : null;
        }
        if (isset($data['enabled'])) {
            $endpoint->enabled = (bool) $data['enabled'];
            $endpoint->disabled_reason = $endpoint->enabled ? null : 'Turned off by hand.';
        }
        $endpoint->save();

        return $endpoint;
    }

    /** @return array<string, mixed> */
    public static function view(WebhookEndpoint $endpoint): array
    {
        $last = $endpoint->deliveries()->latest('id')->first();

        return [
            'id' => $endpoint->id,
            'url' => Webhooks::shown($endpoint->url),
            'description' => $endpoint->description,
            'events' => $endpoint->events === null || $endpoint->events === [] ? 'all' : $endpoint->events,
            'enabled' => $endpoint->enabled,
            'disabledReason' => $endpoint->disabled_reason,
            'createdAt' => $endpoint->created_at?->toIso8601String(),
            'lastDelivery' => $last === null ? null : self::delivery($last),
        ];
    }

    /** @return array<string, mixed> */
    public static function delivery(WebhookDelivery $delivery): array
    {
        return [
            'id' => $delivery->id,
            'eventId' => $delivery->event_id,
            'event' => $delivery->event,
            'state' => $delivery->state,
            'attempts' => $delivery->attempts,
            'responseStatus' => $delivery->response_status,
            'error' => $delivery->error,
            'createdAt' => $delivery->created_at?->toIso8601String(),
            'deliveredAt' => $delivery->delivered_at?->toIso8601String(),
            'nextAttemptAt' => $delivery->next_attempt_at?->toIso8601String(),
        ];
    }

    /** @throws ValidationException */
    private function checkUrl(string $url): void
    {
        if (($refusal = $this->webhooks->refusal(trim($url))) !== null) {
            throw ValidationException::withMessages(['url' => $refusal]);
        }
    }

    /** @return list<string>|null all events when none are picked */
    private static function events(mixed $events): ?array
    {
        $events = is_array($events) ? array_values(array_unique(array_map('strval', $events))) : [];

        return $events === [] || count($events) === count(Webhooks::EVENTS) ? null : $events;
    }
}
