<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stand-in for PagerDuty's Events API v2: it keeps which dedup keys are
 * triggered, records every event and can fail the next ones.
 */
final class FakePagerDuty
{
    public const string KEY = 'R0UT1NGKEY0123456789ABCDEFGHIJKL';

    /** @var list<array<string, mixed>> */
    public array $events = [];

    /** @var array<string, array<string, mixed>> triggered alerts by dedup key */
    public array $open = [];

    /** @var list<array{status: int, body: array<string, mixed>}> */
    private array $failures = [];

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake(['events.pagerduty.com/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    /** @param  array<string, mixed>  $body */
    public function failNext(int $status, array $body = [], int $times = 1): self
    {
        for ($i = 0; $i < $times; $i++) {
            $this->failures[] = ['status' => $status, 'body' => $body];
        }

        return $this;
    }

    /** @return list<string> like "trigger serveros:1:monitor:2" */
    public function sent(): array
    {
        return array_map(fn (array $e) => $e['event_action'].' '.$e['dedup_key'], $this->events);
    }

    public function handle(Request $request): PromiseInterface
    {
        $body = (array) $request->data();
        $this->events[] = $body;

        if (($failure = array_shift($this->failures)) !== null) {
            return Http::response($failure['body'] === [] ? ['status' => 'error', 'message' => 'Internal error'] : $failure['body'], $failure['status']);
        }
        if (($body['routing_key'] ?? null) !== self::KEY) {
            return Http::response(['status' => 'invalid event', 'message' => 'Event object is invalid', 'errors' => ['Invalid routing key']], 400);
        }
        $dedup = (string) ($body['dedup_key'] ?? 'generated');
        match ($body['event_action'] ?? null) {
            'trigger' => $this->open[$dedup] = $body,
            'resolve' => $this->resolve($dedup),
            default => null,
        };

        return Http::response(['status' => 'success', 'message' => 'Event processed', 'dedup_key' => $dedup], 202);
    }

    private function resolve(string $dedup): void
    {
        unset($this->open[$dedup]);
    }
}
