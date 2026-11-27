<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for the Hetzner Cloud API, so tests never call the real
 * one. It keeps servers, actions and snapshots, records every call, and can
 * be told to fail the next calls to a path.
 */
final class FakeHetzner
{
    public const string TOKEN = 'hz-test-token-0123456789abcdefghijklmnopqrstuvwxyz0123456789ABCD';

    /** @var array<int, array<string, mixed>> */
    public array $servers = [];

    /** @var array<int, array{status: string, polls: int, error: string|null, then?: callable}> */
    public array $actions = [];

    /** @var array<int, array<string, mixed>> */
    public array $images = [];

    /** @var list<array{method: string, path: string, body: array<string, mixed>, query: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>, headers: array<string, string>}>> */
    private array $failures = [];

    private int $nextAction = 100;

    private int $nextServer = 900;

    private int $nextImage = 5000;

    /** Polls an action answers "running" before it finishes. */
    public int $actionPolls = 0;

    public ?string $failActions = null;

    public int $shutdownPolls = 0;

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake(['api.hetzner.cloud/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    /** @param  array<string, mixed>  $overrides */
    public function server(int $id, string $name, ?string $ipv4, ?string $ipv6 = null, array $overrides = []): self
    {
        $this->servers[$id] = array_replace_recursive([
            'id' => $id,
            'name' => $name,
            'status' => 'running',
            'created' => '2026-11-01T10:00:00+00:00',
            'public_net' => [
                'ipv4' => $ipv4 === null ? null : ['ip' => $ipv4, 'blocked' => false, 'dns_ptr' => ''],
                'ipv6' => $ipv6 === null ? null : ['ip' => $ipv6, 'blocked' => false, 'dns_ptr' => []],
            ],
            'server_type' => self::type('cx22'),
            'location' => self::location('fsn1'),
            'primary_disk_size' => 40,
            'image' => ['id' => 1, 'name' => 'ubuntu-24.04'],
        ], $overrides);

        return $this;
    }

    /** @param  array<string, mixed>  $body */
    public function failNext(string $method, string $path, int $status, array $body = [], array $headers = []): self
    {
        $this->failures[strtoupper($method).' '.$path][] = ['status' => $status, 'body' => $body, 'headers' => $headers];

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

    public function handle(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = trim(substr((string) ($url['path'] ?? ''), strlen('/v1')), '/');
        parse_str((string) ($url['query'] ?? ''), $query);
        $method = strtoupper($request->method());
        $body = $request->data();
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => is_array($body) ? $body : [], 'query' => $query];

        $key = $method.' '.$path;
        if (($this->failures[$key] ?? []) !== [] && ($failure = array_shift($this->failures[$key])) !== null) {
            return Http::response($failure['body'] === [] ? ['error' => ['code' => 'server_error', 'message' => 'Boom']] : $failure['body'], $failure['status'], $failure['headers']);
        }
        if ($request->header('Authorization')[0] !== 'Bearer '.self::TOKEN) {
            return Http::response(['error' => ['code' => 'unauthorized', 'message' => 'unable to authenticate']], 401);
        }

        return $this->route($method, $path, is_array($body) ? $body : [], $query);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, mixed>  $query
     */
    private function route(string $method, string $path, array $body, array $query): PromiseInterface
    {
        $parts = explode('/', $path);

        return match (true) {
            $method === 'GET' && $path === 'servers' => $this->page('servers', array_values($this->servers), $query),
            $method === 'POST' && $path === 'servers' => $this->create($body),
            $method === 'GET' && $parts[0] === 'servers' && count($parts) === 2 => isset($this->servers[(int) $parts[1]])
                ? Http::response(['server' => $this->pollServer((int) $parts[1])])
                : Http::response(['error' => ['code' => 'not_found', 'message' => 'server not found']], 404),
            $method === 'DELETE' && $parts[0] === 'servers' && count($parts) === 2 => $this->deleteServer((int) $parts[1]),
            $method === 'POST' && $parts[0] === 'servers' && ($parts[2] ?? null) === 'actions' => $this->serverAction((int) $parts[1], (string) $parts[3], $body),
            $method === 'GET' && $parts[0] === 'actions' => $this->pollAction((int) $parts[1]),
            $method === 'GET' && $path === 'locations' => $this->page('locations', [self::location('fsn1'), self::location('hel1')], $query),
            $method === 'GET' && $path === 'server_types' => $this->page('server_types', [self::type('cx22'), self::type('cx32'), self::type('cx42'), self::type('cax11'), self::type('cx11', deprecated: true)], $query),
            $method === 'GET' && $path === 'images' => $this->page('images', ($query['type'] ?? null) === 'snapshot' ? array_values($this->images) : self::systemImages(), $query),
            $method === 'DELETE' && $parts[0] === 'images' => $this->deleteImage((int) $parts[1]),
            $method === 'GET' && $path === 'ssh_keys' => $this->page('ssh_keys', [['id' => 7, 'name' => 'sam@laptop', 'fingerprint' => 'aa:bb', 'public_key' => 'ssh-ed25519 AAAA', 'labels' => [], 'created' => '2026-01-01T00:00:00+00:00']], $query),
            $method === 'GET' && $path === 'pricing' => Http::response(['pricing' => ['currency' => 'EUR', 'vat_rate' => '19.00']]),
            default => Http::response(['error' => ['code' => 'not_found', 'message' => 'not found']], 404),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $query
     */
    private function page(string $key, array $items, array $query): PromiseInterface
    {
        $per = (int) ($query['per_page'] ?? 25);
        $page = (int) ($query['page'] ?? 1);
        $chunk = array_slice($items, ($page - 1) * $per, $per);
        $last = max(1, (int) ceil(count($items) / max(1, $per)));

        return Http::response([$key => $chunk, 'meta' => ['pagination' => [
            'page' => $page, 'per_page' => $per, 'previous_page' => $page > 1 ? $page - 1 : null,
            'next_page' => $page < $last ? $page + 1 : null, 'last_page' => $last, 'total_entries' => count($items),
        ]]]);
    }

    /** @param  array<string, mixed>  $body */
    private function create(array $body): PromiseInterface
    {
        $id = $this->nextServer++;
        $this->server($id, (string) $body['name'], '203.0.113.'.($id % 250), '2001:db8:'.dechex($id).'::/64', [
            'status' => 'initializing',
            'server_type' => self::type((string) $body['server_type']),
            'location' => self::location((string) $body['location']),
        ]);
        $action = $this->action(then: function () use ($id): void {
            $this->servers[$id]['status'] = 'running';
        });

        return Http::response(['server' => $this->servers[$id], 'action' => $action, 'next_actions' => [], 'root_password' => null], 201);
    }

    /** @param  array<string, mixed>  $body */
    private function serverAction(int $id, string $action, array $body): PromiseInterface
    {
        if (! isset($this->servers[$id])) {
            return Http::response(['error' => ['code' => 'not_found', 'message' => 'server not found']], 404);
        }

        if ($action === 'change_type' && $this->servers[$id]['status'] !== 'off') {
            return Http::response(['error' => ['code' => 'server_not_stopped', 'message' => 'server must be stopped']], 422);
        }

        if ($action === 'create_image') {
            $image = ['id' => $this->nextImage++, 'type' => 'snapshot', 'status' => 'creating', 'name' => null,
                'description' => (string) ($body['description'] ?? ''), 'image_size' => null, 'disk_size' => 40,
                'created' => '2026-11-27T10:00:00+00:00', 'created_from' => ['id' => $id, 'name' => $this->servers[$id]['name']]];
            $this->images[$image['id']] = $image;

            return Http::response(['image' => $image, 'action' => $this->action()], 201);
        }

        $then = match ($action) {
            'shutdown' => function () use ($id): void {
                $this->servers[$id]['status'] = $this->shutdownPolls > 0 ? 'stopping' : 'off';
            },
            'poweroff' => function () use ($id): void {
                $this->servers[$id]['status'] = 'off';
                $this->shutdownPolls = 0;
            },
            'poweron', 'reboot', 'reset' => function () use ($id): void {
                $this->servers[$id]['status'] = 'running';
            },
            'change_type' => function () use ($id, $body): void {
                $this->servers[$id]['server_type'] = self::type((string) $body['server_type']);
            },
            default => null,
        };
        if (in_array($action, ['shutdown', 'poweroff'], true) && $then !== null) {
            $then();
            $then = null;
        }

        return Http::response(['action' => $this->action(
            failing: $action === $this->failActions,
            then: $then,
        )], 201);
    }

    /** @return array<string, mixed> */
    private function pollServer(int $id): array
    {
        if ($this->servers[$id]['status'] === 'stopping') {
            if ($this->shutdownPolls-- <= 0) {
                $this->servers[$id]['status'] = 'off';
            }
        }

        return $this->servers[$id];
    }

    /** @return array<string, mixed> */
    private function action(bool $failing = false, ?callable $then = null): array
    {
        $id = $this->nextAction++;
        $this->actions[$id] = ['status' => 'running', 'polls' => $this->actionPolls, 'error' => $failing ? 'cannot change to this server type' : null, 'then' => $then];

        return ['id' => $id, 'command' => 'x', 'status' => 'running', 'progress' => 0, 'started' => '2026-11-27T10:00:00+00:00', 'finished' => null, 'resources' => [], 'error' => null];
    }

    private function pollAction(int $id): PromiseInterface
    {
        $action = $this->actions[$id] ?? null;
        if ($action === null) {
            return Http::response(['error' => ['code' => 'not_found', 'message' => 'action not found']], 404);
        }
        if ($action['status'] === 'running') {
            if ($action['polls'] > 0) {
                $this->actions[$id]['polls']--;
            } elseif ($action['error'] !== null) {
                $this->actions[$id]['status'] = 'error';
            } else {
                $this->actions[$id]['status'] = 'success';
                if (isset($action['then'])) {
                    ($action['then'])();
                }
            }
        }
        $action = $this->actions[$id];

        return Http::response(['action' => [
            'id' => $id, 'command' => 'x', 'status' => $action['status'], 'progress' => $action['status'] === 'running' ? 50 : 100,
            'started' => '2026-11-27T10:00:00+00:00', 'finished' => null, 'resources' => [],
            'error' => $action['status'] === 'error' ? ['code' => 'action_failed', 'message' => $action['error']] : null,
        ]]);
    }

    private function deleteServer(int $id): PromiseInterface
    {
        unset($this->servers[$id]);

        return Http::response(['action' => $this->action()]);
    }

    private function deleteImage(int $id): PromiseInterface
    {
        unset($this->images[$id]);

        return Http::response(null, 204);
    }

    /** @return array<string, mixed> */
    public static function location(string $name): array
    {
        $cities = ['fsn1' => ['Falkenstein', 'DE', 'eu-central'], 'hel1' => ['Helsinki', 'FI', 'eu-central'], 'nbg1' => ['Nuremberg', 'DE', 'eu-central']];
        [$city, $country, $zone] = $cities[$name] ?? ['Somewhere', 'DE', 'eu-central'];

        return ['id' => crc32($name) % 100, 'name' => $name, 'description' => "{$city} DC", 'country' => $country, 'city' => $city, 'latitude' => 50.0, 'longitude' => 12.0, 'network_zone' => $zone];
    }

    /** @return array<string, mixed> */
    public static function type(string $name, bool $deprecated = false): array
    {
        $specs = [
            'cx11' => [1, 2.0, 20, 3.29, 'x86'],
            'cx22' => [2, 4.0, 40, 4.59, 'x86'],
            'cx32' => [4, 8.0, 80, 7.59, 'x86'],
            'cx42' => [8, 16.0, 160, 18.59, 'x86'],
            'cax11' => [2, 4.0, 40, 4.49, 'arm'],
        ];
        [$cores, $memory, $disk, $monthly, $arch] = $specs[$name] ?? [2, 4.0, 40, 5.0, 'x86'];
        $price = fn (string $location) => ['location' => $location, 'price_hourly' => ['net' => '0.0070000000', 'gross' => '0.0083300000'], 'price_monthly' => ['net' => (string) round($monthly / 1.19, 4), 'gross' => (string) $monthly], 'included_traffic' => 21990232555520, 'price_per_tb_traffic' => ['net' => '1.0000000000', 'gross' => '1.1900000000']];

        return [
            'id' => crc32($name) % 1000, 'name' => $name, 'description' => strtoupper($name), 'cores' => $cores, 'memory' => $memory, 'disk' => $disk,
            'prices' => [$price('fsn1'), $price('hel1')], 'storage_type' => 'local', 'cpu_type' => 'shared', 'category' => 'cost_optimized', 'architecture' => $arch,
            'deprecation' => $deprecated ? ['announced' => '2026-01-01T00:00:00+00:00', 'unavailable_after' => '2026-06-01T00:00:00+00:00'] : null,
            'locations' => [
                ['id' => 1, 'name' => 'fsn1', 'deprecation' => null, 'recommended' => true, 'available' => true],
                ['id' => 3, 'name' => 'hel1', 'deprecation' => null, 'recommended' => false, 'available' => $name !== 'cx42'],
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function systemImages(): array
    {
        $image = fn (int $id, string $name, string $flavor, string $version, string $arch = 'x86') => [
            'id' => $id, 'type' => 'system', 'status' => 'available', 'name' => $name, 'description' => ucfirst($flavor).' '.$version,
            'image_size' => null, 'disk_size' => 5, 'created' => '2026-01-01T00:00:00+00:00', 'created_from' => null, 'bound_to' => null,
            'os_flavor' => $flavor, 'os_version' => $version, 'rapid_deploy' => true, 'protection' => ['delete' => false], 'deprecation' => null,
            'deleted' => null, 'labels' => [], 'architecture' => $arch,
        ];

        return [
            $image(1, 'ubuntu-22.04', 'ubuntu', '22.04'),
            $image(2, 'ubuntu-24.04', 'ubuntu', '24.04'),
            $image(3, 'ubuntu-25.04', 'ubuntu', '25.04'),
            $image(4, 'debian-12', 'debian', '12'),
            $image(5, 'ubuntu-24.04', 'ubuntu', '24.04', 'arm'),
            $image(6, 'rocky-9', 'rocky', '9'),
        ];
    }
}
