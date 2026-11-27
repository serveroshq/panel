<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for the Cloudflare API v4, so tests never call the real
 * one. It keeps zones and DNS records, records every call and purge, and
 * can be told to fail the next calls to a path.
 */
final class FakeCloudflare
{
    public const string TOKEN = 'cf-test-token-0123456789abcdefghijklmnopqrstuv';

    public string $tokenStatus = 'active';

    public string $account = 'Acme';

    /** @var array<string, array<string, mixed>> */
    public array $zones = [];

    /** @var array<string, array<string, array<string, mixed>>> by zone id, then record id */
    public array $records = [];

    /** @var list<array{zone: string, hosts: list<string>}> */
    public array $purges = [];

    /** @var list<array{method: string, path: string, body: array<string, mixed>, query: array<string, string>}> */
    public array $calls = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $failures = [];

    private int $nextRecord = 1;

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake(['api.cloudflare.com/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    public function zone(string $name, string $status = 'active'): string
    {
        $id = substr(md5($name), 0, 32);
        $this->zones[$id] = ['id' => $id, 'name' => $name, 'status' => $status, 'account' => ['id' => 'acc1', 'name' => $this->account]];
        $this->records[$id] ??= [];

        return $id;
    }

    public function record(string $zone, string $type, string $name, string $content, bool $proxied = false): string
    {
        $zoneId = $this->zoneId($zone);
        $id = 'rec'.str_pad((string) $this->nextRecord++, 6, '0', STR_PAD_LEFT);
        $this->records[$zoneId][$id] = ['id' => $id, 'type' => $type, 'name' => $name, 'content' => $content, 'proxied' => $proxied, 'ttl' => 1, 'comment' => null];

        return $id;
    }

    /** @return list<array<string, mixed>> */
    public function recordsNamed(string $name): array
    {
        $out = [];
        foreach ($this->records as $records) {
            foreach ($records as $record) {
                if ($record['name'] === $name) {
                    $out[] = $record;
                }
            }
        }

        return $out;
    }

    /** @param  array<string, mixed>  $body */
    public function failNext(string $method, string $path, int $status, array $body = []): self
    {
        $this->failures[strtoupper($method).' '.$path][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** @return list<string> */
    public function called(?string $method = null): array
    {
        return array_values(array_map(
            fn (array $call) => $call['method'].' '.$call['path'],
            array_filter($this->calls, fn (array $call) => $method === null || $call['method'] === $method),
        ));
    }

    /** @return list<string> writes: anything but GET */
    public function writes(): array
    {
        return array_values(array_filter($this->called(), fn (string $call) => ! str_starts_with($call, 'GET ')));
    }

    /** @return array<string, mixed>|null */
    public function bodyOf(string $method, string $path): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method && $call['path'] === $path) {
                return $call['body'];
            }
        }

        return null;
    }

    public function zoneId(string $name): string
    {
        foreach ($this->zones as $id => $zone) {
            if ($zone['name'] === $name || $id === $name) {
                return $id;
            }
        }

        return $this->zone($name);
    }

    public function handle(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = trim(substr((string) ($url['path'] ?? ''), strlen('/client/v4')), '/');
        $query = [];
        foreach (array_filter(explode('&', (string) ($url['query'] ?? ''))) as $pair) {
            [$key, $value] = array_pad(explode('=', $pair, 2), 2, '');
            $query[urldecode($key)] = urldecode($value);
        }
        $method = strtoupper($request->method());
        $body = $request->data();
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => is_array($body) ? $body : [], 'query' => $query];

        $key = $method.' '.$path;
        if (($this->failures[$key] ?? []) !== [] && ($failure = array_shift($this->failures[$key])) !== null) {
            return Http::response($failure['body'] === [] ? self::error(500, 'Internal error') : $failure['body'], $failure['status']);
        }
        if ($request->header('Authorization')[0] !== 'Bearer '.self::TOKEN) {
            return $path === 'user/tokens/verify'
                ? Http::response(self::error(1000, 'Invalid API Token'), 401)
                : Http::response(self::error(10000, 'Authentication error'), 403);
        }

        return $this->route($method, $path, is_array($body) ? $body : [], $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $query
     */
    private function route(string $method, string $path, array $body, array $query): PromiseInterface
    {
        $parts = explode('/', $path);
        $zoneId = $parts[1] ?? '';

        return match (true) {
            $method === 'GET' && $path === 'user/tokens/verify' => self::ok(['id' => 'tok1', 'status' => $this->tokenStatus]),
            $method === 'GET' && $path === 'accounts' => self::ok([['id' => 'acc1', 'name' => $this->account]]),
            $method === 'GET' && $path === 'zones' => $this->page(array_values($this->zones), $query),
            ($parts[0] === 'zones') && ! isset($this->zones[$zoneId]) => Http::response(self::error(7003, 'Could not route to /zones/'.$zoneId.', perhaps your object identifier is invalid?'), 404),
            $method === 'GET' && count($parts) === 3 && $parts[2] === 'dns_records' => $this->page(array_values(array_filter(
                $this->records[$zoneId],
                fn (array $r) => (! isset($query['name.exact']) || $r['name'] === $query['name.exact']) && (! isset($query['type']) || $r['type'] === $query['type']),
            )), $query),
            $method === 'POST' && count($parts) === 3 && $parts[2] === 'dns_records' => $this->create($zoneId, $body),
            $method === 'PATCH' && count($parts) === 4 && $parts[2] === 'dns_records' => $this->update($zoneId, $parts[3], $body),
            $method === 'DELETE' && count($parts) === 4 && $parts[2] === 'dns_records' => $this->delete($zoneId, $parts[3]),
            $method === 'POST' && count($parts) === 3 && $parts[2] === 'purge_cache' => $this->purge($zoneId, $body),
            default => Http::response(self::error(7000, 'No route for that URI'), 404),
        };
    }

    /** @param  array<string, mixed>  $body */
    private function create(string $zoneId, array $body): PromiseInterface
    {
        $clash = collect($this->records[$zoneId])->first(fn (array $r) => $r['name'] === $body['name']
            && (($body['type'] === 'CNAME') !== ($r['type'] === 'CNAME')));
        if ($clash !== null) {
            return Http::response(self::error(81053, 'An A, AAAA, or CNAME record with that host already exists.'), 400);
        }
        $id = 'rec'.str_pad((string) $this->nextRecord++, 6, '0', STR_PAD_LEFT);
        $this->records[$zoneId][$id] = ['id' => $id, 'type' => $body['type'], 'name' => $body['name'], 'content' => $body['content'], 'proxied' => (bool) ($body['proxied'] ?? false), 'ttl' => $body['ttl'] ?? 1, 'comment' => $body['comment'] ?? null];

        return self::ok($this->records[$zoneId][$id]);
    }

    /** @param  array<string, mixed>  $body */
    private function update(string $zoneId, string $id, array $body): PromiseInterface
    {
        if (! isset($this->records[$zoneId][$id])) {
            return Http::response(self::error(81044, 'Record does not exist.'), 404);
        }
        $this->records[$zoneId][$id] = [...$this->records[$zoneId][$id], ...array_intersect_key($body, array_flip(['type', 'content', 'proxied', 'comment']))];

        return self::ok($this->records[$zoneId][$id]);
    }

    private function delete(string $zoneId, string $id): PromiseInterface
    {
        if (! isset($this->records[$zoneId][$id])) {
            return Http::response(self::error(81044, 'Record does not exist.'), 404);
        }
        unset($this->records[$zoneId][$id]);

        return self::ok(['id' => $id]);
    }

    /** @param  array<string, mixed>  $body */
    private function purge(string $zoneId, array $body): PromiseInterface
    {
        $this->purges[] = ['zone' => $this->zones[$zoneId]['name'], 'hosts' => array_values((array) ($body['hosts'] ?? []))];

        return self::ok(['id' => $zoneId]);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, string>  $query
     */
    private function page(array $items, array $query): PromiseInterface
    {
        $per = (int) ($query['per_page'] ?? 20);
        $page = max(1, (int) ($query['page'] ?? 1));
        $pages = max(1, (int) ceil(count($items) / max(1, $per)));

        return Http::response([
            'success' => true,
            'errors' => [],
            'messages' => [],
            'result' => array_slice($items, ($page - 1) * $per, $per),
            'result_info' => ['page' => $page, 'per_page' => $per, 'count' => count($items), 'total_count' => count($items), 'total_pages' => $pages],
        ]);
    }

    private static function ok(mixed $result): PromiseInterface
    {
        return Http::response(['success' => true, 'errors' => [], 'messages' => [], 'result' => $result]);
    }

    /** @return array<string, mixed> */
    public static function error(int $code, string $message): array
    {
        return ['success' => false, 'errors' => [['code' => $code, 'message' => $message]], 'messages' => [], 'result' => null];
    }
}
