<?php

namespace App\Support\Webhooks;

use App\Jobs\DeliverWebhook;
use App\Models\Activity;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Notifications\MonitorAlert;
use App\Support\Monitors\HostResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * Signed events for a workspace's webhook endpoints. Each event is stored as
 * one delivery per endpoint that wants it, sent from the queue, and retried
 * with backoff for up to 24 hours. An endpoint whose deliveries keep failing
 * for that long is turned off, and whoever added it gets an email.
 *
 * Deliveries carry ids and states, never logs or customer data: receivers
 * fetch the details with their API key.
 */
final class Webhooks
{
    public const array EVENTS = [
        'operation.started',
        'operation.paused',
        'operation.finished',
        'job.finished',
        'deploy.finished',
        'machine.online',
        'machine.offline',
    ];

    public const string TEST_EVENT = 'webhook.test';

    public const int MAX_ENDPOINTS = 10;

    public const int GIVE_UP_HOURS = 24;

    public const string USER_AGENT = 'ServerOS-Webhooks/1.0';

    /** Seconds before each retry; the last one is repeated until 24 hours have passed. */
    private const array BACKOFF = [60, 300, 900, 1800, 3600, 7200, 14400];

    public function __construct(private readonly HostResolver $resolver) {}

    /**
     * Sends an event to every endpoint in the workspace that wants it. Never
     * throws: a webhook problem mustn't break what raised the event.
     *
     * @param  array<string, mixed>  $data  ids and states only
     */
    public static function dispatch(int $workspaceId, string $event, array $data): void
    {
        try {
            $endpoints = WebhookEndpoint::query()->where('workspace_id', $workspaceId)->where('enabled', true)->get()
                ->filter(fn (WebhookEndpoint $e) => $e->wants($event));
            if ($endpoints->isEmpty()) {
                return;
            }
            $id = (string) Str::uuid();
            $payload = ['id' => $id, 'type' => $event, 'createdAt' => now()->toIso8601String(), 'data' => (object) $data];
            foreach ($endpoints as $endpoint) {
                $delivery = WebhookDelivery::query()->create([
                    'webhook_endpoint_id' => $endpoint->id,
                    'event_id' => $id,
                    'event' => $event,
                    'payload' => $payload,
                    'state' => 'pending',
                    'next_attempt_at' => now(),
                ]);
                DeliverWebhook::dispatch($delivery->id)->afterCommit();
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** A test event, sent once now, so a person can check their receiver. */
    public function test(WebhookEndpoint $endpoint): WebhookDelivery
    {
        $id = (string) Str::uuid();
        $delivery = WebhookDelivery::query()->create([
            'webhook_endpoint_id' => $endpoint->id,
            'event_id' => $id,
            'event' => self::TEST_EVENT,
            'payload' => ['id' => $id, 'type' => self::TEST_EVENT, 'createdAt' => now()->toIso8601String(), 'data' => ['endpoint_id' => $endpoint->id]],
            'state' => 'pending',
            'next_attempt_at' => now(),
        ]);
        $this->attempt($delivery, force: true);

        return $delivery->refresh();
    }

    /** Sends one delivery now, and plans the next try if it didn't work. */
    public function attempt(WebhookDelivery $delivery, bool $force = false): void
    {
        $endpoint = $delivery->endpoint;
        if ($delivery->state !== 'pending' || ($delivery->next_attempt_at?->isFuture() && ! $force)) {
            return;
        }
        if (! $endpoint->enabled && ! $force) {
            $delivery->forceFill(['state' => 'failed', 'next_attempt_at' => null, 'error' => 'The endpoint was turned off.'])->save();

            return;
        }

        $body = (string) json_encode($delivery->payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        [$status, $error] = $this->post($endpoint->url, $body, [
            'Content-Type' => 'application/json',
            'User-Agent' => self::USER_AGENT,
            'ServerOS-Event' => $delivery->event,
            'ServerOS-Delivery' => (string) $delivery->id,
            'ServerOS-Signature' => self::signature($body, $endpoint->secret, now()->getTimestamp()),
        ]);
        $attempts = $delivery->attempts + 1;

        if ($error === null) {
            $delivery->forceFill(['state' => 'delivered', 'attempts' => $attempts, 'response_status' => $status, 'error' => null, 'delivered_at' => now(), 'next_attempt_at' => null])->save();

            return;
        }

        $wait = self::BACKOFF[$attempts - 1] ?? self::BACKOFF[array_key_last(self::BACKOFF)];
        $giveUp = $delivery->event === self::TEST_EVENT
            || $delivery->created_at === null
            || $delivery->created_at->addHours(self::GIVE_UP_HOURS)->lt(now()->addSeconds($wait));
        $delivery->forceFill([
            'state' => $giveUp ? 'failed' : 'pending',
            'attempts' => $attempts,
            'response_status' => $status,
            'error' => mb_substr($error, 0, 300),
            'next_attempt_at' => $giveUp ? null : now()->addSeconds($wait),
        ])->save();

        if ($giveUp && $delivery->event !== self::TEST_EVENT && $endpoint->enabled) {
            $this->turnOff($endpoint, $error);
        }
    }

    /** Hands due retries to the queue. */
    public function retryDue(): int
    {
        $count = 0;
        WebhookDelivery::query()->where('state', 'pending')->where('next_attempt_at', '<=', now())
            ->orderBy('id')->limit(500)->pluck('id')
            ->each(function (int $id) use (&$count) {
                DeliverWebhook::dispatch($id);
                $count++;
            });

        return $count;
    }

    /** `t=<unix time>,v1=<hex HMAC-SHA256 of "t.body">`, as the SDK's verifyWebhook() checks it. */
    public static function signature(string $body, string $secret, int $time): string
    {
        return "t={$time},v1=".hash_hmac('sha256', "{$time}.{$body}", $secret);
    }

    public static function secret(): string
    {
        return 'whsec_'.Str::random(40);
    }

    /** The URL without its query string or credentials, which can hold tokens. */
    public static function shown(string $url): string
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['host'])) {
            return $url;
        }

        return ($parts['scheme'] ?? 'https').'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '').($parts['path'] ?? '').(isset($parts['query']) ? '?…' : '');
    }

    /** Why an address can't take webhooks, or null if it can. */
    public function refusal(string $url): ?string
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! str_starts_with(strtolower($url), 'https://') || ! is_string($host) || $host === '') {
            return 'A webhook needs an https:// address.';
        }
        if (parse_url($url, PHP_URL_USER) !== null) {
            return 'Leave the user name and password out of the address; use the signature to trust deliveries.';
        }

        return $this->resolver->resolve($host) === [] ? 'That address can’t be reached from the panel.' : null;
    }

    private function turnOff(WebhookEndpoint $endpoint, string $error): void
    {
        $endpoint->forceFill([
            'enabled' => false,
            'disabled_reason' => mb_substr('Turned off '.now()->format('j M, H:i').' UTC: deliveries kept failing for '.self::GIVE_UP_HOURS.' hours. The last answer: '.$error, 0, 300),
        ])->save();
        $host = (string) parse_url($endpoint->url, PHP_URL_HOST);
        Activity::system($endpoint->workspace_id, 'api.webhook_disabled', $host, null, ['endpoint' => $endpoint->id, 'error' => mb_substr($error, 0, 200)]);

        $creator = $endpoint->creator;
        if ($creator !== null && $endpoint->workspace->roleOf($creator) !== null) {
            rescue(fn () => $creator->notify(new MonitorAlert(
                "Webhook to {$host} turned off",
                [
                    'Deliveries to '.self::shown($endpoint->url).' kept failing for '.self::GIVE_UP_HOURS.' hours, so '.$endpoint->workspace->name.' stopped sending to it.',
                    "The last answer: {$error}",
                    'Fix the receiver, then turn the endpoint back on in Settings › API.',
                ],
                $endpoint->workspace->url().'/settings/api#webhooks',
                true,
                'Open API settings',
            )));
        }
    }

    /**
     * @param  array<string, string>  $headers
     * @return array{0: int|null, 1: string|null} the status, and the error if it didn't work
     */
    private function post(string $url, string $body, array $headers): array
    {
        if (($refusal = $this->refusal($url)) !== null) {
            return [null, $refusal];
        }
        $host = (string) parse_url($url, PHP_URL_HOST);
        $addresses = $this->resolver->resolve($host);
        $ip = str_contains($addresses[0], ':') ? "[{$addresses[0]}]" : $addresses[0];
        $port = parse_url($url, PHP_URL_PORT) ?? 443;

        try {
            // Pinned to the address just checked, and no redirects, so a receiver can't point the panel elsewhere.
            $response = Http::timeout(10)->connectTimeout(5)
                ->withOptions(['allow_redirects' => false, 'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]])
                ->withHeaders($headers)
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (Throwable) {
            return [null, 'Couldn’t connect.'];
        }

        return $response->successful() ? [$response->status(), null] : [$response->status(), "It answered {$response->status()}."];
    }
}
