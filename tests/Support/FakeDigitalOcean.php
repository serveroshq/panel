<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for the DigitalOcean API, so tests never call the real
 * one. It keeps droplets, actions and snapshots, records every call, and
 * can be told to fail the next calls to a path.
 */
final class FakeDigitalOcean
{
    public const string TOKEN = 'fake-digitalocean-token-for-tests';

    /** @var array<int, array<string, mixed>> */
    public array $droplets = [];

    /** @var array<int, array{status: string, polls: int, type: string, droplet: int, failing: bool, then?: callable|null}> */
    public array $actions = [];

    /** @var array<int, array<string, mixed>> */
    public array $snapshots = [];

    /** @var list<array{method: string, path: string, body: array<string, mixed>, query: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>, headers: array<string, string>}>> */
    private array $failures = [];

    private int $nextAction = 300;

    private int $nextDroplet = 7000;

    private int $nextSnapshot = 88000;

    /** Polls an action answers "in-progress" before it finishes. */
    public int $actionPolls = 0;

    public ?string $failActions = null;

    public int $shutdownPolls = 0;

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake(['api.digitalocean.com/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    /** @param  array<string, mixed>  $overrides */
    public function droplet(int $id, string $name, ?string $ipv4, ?string $ipv6 = null, array $overrides = []): self
    {
        $size = self::size((string) ($overrides['size_slug'] ?? 's-1vcpu-2gb'));
        $this->droplets[$id] = array_replace([
            'id' => $id,
            'name' => $name,
            'memory' => $size['memory'],
            'vcpus' => $size['vcpus'],
            'disk' => $size['disk'],
            'locked' => false,
            'status' => 'active',
            'created_at' => '2026-11-01T10:00:00Z',
            'features' => ['ipv6'],
            'image' => ['id' => 1, 'slug' => 'ubuntu-24-04-x64', 'distribution' => 'Ubuntu', 'name' => '24.04 (LTS) x64'],
            'size' => $size,
            'size_slug' => $size['slug'],
            'networks' => [
                'v4' => array_values(array_filter([
                    $ipv4 === null ? null : ['ip_address' => $ipv4, 'netmask' => '255.255.240.0', 'gateway' => '203.0.113.1', 'type' => 'public'],
                    ['ip_address' => '10.110.0.'.($id % 250), 'netmask' => '255.255.240.0', 'gateway' => '10.110.0.1', 'type' => 'private'],
                ])),
                'v6' => $ipv6 === null ? [] : [['ip_address' => $ipv6, 'netmask' => 64, 'gateway' => '2001:db8::1', 'type' => 'public']],
            ],
            'region' => self::region('ams3'),
            'tags' => [],
            'snapshot_ids' => [],
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

    /** @return list<string> the types of the droplet actions asked for, in order */
    public function actionsAsked(): array
    {
        return array_values(array_map(
            fn (array $call) => (string) ($call['body']['type'] ?? ''),
            array_filter($this->calls, fn (array $call) => $call['method'] === 'POST' && str_ends_with($call['path'], '/actions')),
        ));
    }

    /** @return array<string, mixed>|null */
    public function bodyOf(string $method, string $path, ?string $type = null): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method && $call['path'] === $path && ($type === null || ($call['body']['type'] ?? null) === $type)) {
                return $call['body'];
            }
        }

        return null;
    }

    public function handle(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $path = trim(substr((string) ($url['path'] ?? ''), strlen('/v2')), '/');
        parse_str((string) ($url['query'] ?? ''), $query);
        $method = strtoupper($request->method());
        $body = $request->data();
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => is_array($body) ? $body : [], 'query' => $query];

        $key = $method.' '.$path;
        if (($this->failures[$key] ?? []) !== [] && ($failure = array_shift($this->failures[$key])) !== null) {
            return Http::response($failure['body'] === [] ? ['id' => 'server_error', 'message' => 'Server Error'] : $failure['body'], $failure['status'], $failure['headers']);
        }
        if ($request->header('Authorization')[0] !== 'Bearer '.self::TOKEN) {
            return Http::response(['id' => 'Unauthorized', 'message' => 'Unable to authenticate you'], 401);
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
        $id = (int) ($parts[1] ?? 0);

        return match (true) {
            $method === 'GET' && $path === 'account' => Http::response(['account' => [
                'droplet_limit' => 25, 'floating_ip_limit' => 3, 'volume_limit' => 100, 'email' => 'sam@example.com', 'name' => 'Sam',
                'uuid' => 'b6fr89dbf6d9156cace5f3c78dc9851d957381ef', 'email_verified' => true, 'status' => 'active', 'status_message' => '',
                'team' => ['uuid' => '5df3e3004a17e242b7c20ca6c9fc25b701a47ece', 'name' => 'Acme'],
            ]]),
            $method === 'GET' && $path === 'droplets' => $this->page('droplets', array_values($this->droplets), $query),
            $method === 'POST' && $path === 'droplets' => $this->create($body),
            $method === 'GET' && $parts[0] === 'droplets' && count($parts) === 2 => isset($this->droplets[$id])
                ? Http::response(['droplet' => $this->pollDroplet($id)])
                : $this->notFound(),
            $method === 'DELETE' && $parts[0] === 'droplets' && count($parts) === 2 => $this->deleteDroplet($id),
            $method === 'POST' && $parts[0] === 'droplets' && ($parts[2] ?? null) === 'actions' => $this->dropletAction($id, $body),
            $method === 'GET' && $parts[0] === 'droplets' && ($parts[2] ?? null) === 'actions' => $this->page('actions', array_values(array_reverse(array_map(
                fn (int $actionId) => $this->actionBody($actionId),
                array_keys(array_filter($this->actions, fn (array $a) => $a['droplet'] === $id)),
            ))), $query),
            $method === 'GET' && $parts[0] === 'droplets' && ($parts[2] ?? null) === 'snapshots' => isset($this->droplets[$id])
                ? $this->page('snapshots', array_values(array_filter($this->snapshots, fn (array $s) => ($s['droplet'] ?? null) === $id)), $query)
                : $this->notFound(),
            $method === 'GET' && $parts[0] === 'actions' => isset($this->actions[$id]) ? $this->pollAction($id) : $this->notFound(),
            $method === 'DELETE' && $parts[0] === 'snapshots' => $this->deleteSnapshot($id),
            $method === 'GET' && $path === 'regions' => $this->page('regions', [self::region('ams3'), self::region('fra1'), self::region('nyc1', available: false)], $query),
            $method === 'GET' && $path === 'sizes' => $this->page('sizes', [
                self::size('s-1vcpu-1gb'), self::size('s-1vcpu-2gb'), self::size('s-2vcpu-4gb'), self::size('g-2vcpu-8gb'),
                self::size('s-4vcpu-8gb-retired', available: false), self::size('gpu-h100x1-80gb'),
            ], $query),
            $method === 'GET' && $path === 'images' => $this->page('images', self::distributionImages(), $query),
            $method === 'GET' && $path === 'account/keys' => $this->page('ssh_keys', [['id' => 512190, 'fingerprint' => '3b:16:bf:e4', 'public_key' => 'ssh-ed25519 AAAA', 'name' => 'sam@laptop']], $query),
            default => $this->notFound(),
        };
    }

    private function notFound(): PromiseInterface
    {
        return Http::response(['id' => 'not_found', 'message' => 'The resource you were accessing could not be found.'], 404);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $query
     */
    private function page(string $key, array $items, array $query): PromiseInterface
    {
        $per = (int) ($query['per_page'] ?? 20);
        $page = (int) ($query['page'] ?? 1);
        $chunk = array_slice($items, ($page - 1) * $per, $per);
        $last = max(1, (int) ceil(count($items) / max(1, $per)));
        $pages = $page < $last ? ['next' => "https://api.digitalocean.com/v2/{$key}?page=".($page + 1), 'last' => "https://api.digitalocean.com/v2/{$key}?page={$last}"] : [];

        return Http::response([$key => $chunk, 'links' => ['pages' => $pages], 'meta' => ['total' => count($items)]]);
    }

    /** @param  array<string, mixed>  $body */
    private function create(array $body): PromiseInterface
    {
        if (! isset($body['user_data'])) {
            return Http::response(['id' => 'unprocessable_entity', 'message' => 'user_data is missing'], 422);
        }
        $id = $this->nextDroplet++;
        $this->droplet($id, (string) $body['name'], null, null, [
            'status' => 'new',
            'size_slug' => (string) $body['size'],
            'region' => self::region((string) $body['region']),
        ]);
        $action = $this->action($id, 'create', then: function () use ($id): void {
            $this->droplets[$id]['status'] = 'active';
            $this->droplets[$id]['networks']['v4'][] = ['ip_address' => '203.0.113.'.($id % 250), 'netmask' => '255.255.240.0', 'gateway' => '203.0.113.1', 'type' => 'public'];
            $this->droplets[$id]['networks']['v6'][] = ['ip_address' => '2001:db8:'.dechex($id).'::1', 'netmask' => 64, 'gateway' => '2001:db8::1', 'type' => 'public'];
        });

        return Http::response(['droplet' => $this->droplets[$id], 'links' => ['actions' => [['id' => $action['id'], 'rel' => 'create', 'href' => "https://api.digitalocean.com/v2/actions/{$action['id']}"]]]], 202);
    }

    /** @param  array<string, mixed>  $body */
    private function dropletAction(int $id, array $body): PromiseInterface
    {
        if (! isset($this->droplets[$id])) {
            return $this->notFound();
        }
        $type = (string) ($body['type'] ?? '');

        if ($type === 'resize' && $this->droplets[$id]['status'] !== 'off') {
            return Http::response(['id' => 'unprocessable_entity', 'message' => 'Droplet is currently on. Please power it off to run this event.'], 422);
        }

        $then = match ($type) {
            'shutdown' => function () use ($id): void {
                $this->droplets[$id]['status'] = $this->shutdownPolls > 0 ? 'active' : 'off';
            },
            'power_off' => function () use ($id): void {
                $this->droplets[$id]['status'] = 'off';
                $this->shutdownPolls = 0;
            },
            'power_on', 'reboot', 'power_cycle' => function () use ($id): void {
                $this->droplets[$id]['status'] = 'active';
            },
            'resize' => function () use ($id, $body): void {
                $size = self::size((string) $body['size']);
                $this->droplets[$id] = [...$this->droplets[$id], 'size' => $size, 'size_slug' => $size['slug'], 'memory' => $size['memory'], 'vcpus' => $size['vcpus']];
                if (($body['disk'] ?? false) === true) {
                    $this->droplets[$id]['disk'] = $size['disk'];
                }
            },
            'snapshot' => function () use ($id, $body): void {
                $snapshot = $this->nextSnapshot++;
                $this->snapshots[$snapshot] = self::snapshotOf($snapshot, (string) ($body['name'] ?? ''), $id, (int) $this->droplets[$id]['disk']);
                $this->droplets[$id]['snapshot_ids'][] = $snapshot;
            },
            default => null,
        };
        if (in_array($type, ['shutdown', 'power_off'], true) && $then !== null) {
            $then();
            $then = null;
        }

        return Http::response(['action' => $this->action($id, $type, failing: $type === $this->failActions, then: $then)], 201);
    }

    /** @return array<string, mixed> */
    private function pollDroplet(int $id): array
    {
        if ($this->droplets[$id]['status'] === 'active' && $this->shutdownPolls > 0 && $this->shuttingDown($id)) {
            if (--$this->shutdownPolls <= 0) {
                $this->droplets[$id]['status'] = 'off';
            }
        }

        return $this->droplets[$id];
    }

    private function shuttingDown(int $id): bool
    {
        foreach (array_reverse($this->actions, true) as $action) {
            if ($action['droplet'] === $id) {
                return $action['type'] === 'shutdown';
            }
        }

        return false;
    }

    /** @return array<string, mixed> */
    private function action(int $droplet, string $type, bool $failing = false, ?callable $then = null): array
    {
        $id = $this->nextAction++;
        $this->actions[$id] = ['status' => 'in-progress', 'polls' => $this->actionPolls, 'type' => $type, 'droplet' => $droplet, 'failing' => $failing, 'then' => $then];

        return $this->actionBody($id);
    }

    /** @return array<string, mixed> */
    private function actionBody(int $id): array
    {
        $action = $this->actions[$id];

        return [
            'id' => $id, 'status' => $action['status'], 'type' => $action['type'], 'started_at' => '2026-11-27T10:00:00Z',
            'completed_at' => $action['status'] === 'in-progress' ? null : '2026-11-27T10:01:00Z',
            'resource_id' => $action['droplet'], 'resource_type' => 'droplet', 'region' => self::region('ams3'), 'region_slug' => 'ams3',
        ];
    }

    private function pollAction(int $id): PromiseInterface
    {
        $action = $this->actions[$id];
        if ($action['status'] === 'in-progress') {
            if ($action['polls'] > 0) {
                $this->actions[$id]['polls']--;
            } elseif ($action['failing']) {
                $this->actions[$id]['status'] = 'errored';
            } else {
                $this->actions[$id]['status'] = 'completed';
                if (isset($action['then'])) {
                    ($action['then'])();
                }
            }
        }

        return Http::response(['action' => $this->actionBody($id)]);
    }

    /** Finishes every action still in progress, like DigitalOcean would in a while. */
    public function settle(): void
    {
        foreach (array_keys($this->actions) as $id) {
            while ($this->actions[$id]['status'] === 'in-progress') {
                $this->actions[$id]['polls'] = 0;
                $this->pollAction($id);
            }
        }
    }

    private function deleteDroplet(int $id): PromiseInterface
    {
        if (! isset($this->droplets[$id])) {
            return $this->notFound();
        }
        unset($this->droplets[$id]);

        return Http::response(null, 204);
    }

    private function deleteSnapshot(int $id): PromiseInterface
    {
        if (! isset($this->snapshots[$id])) {
            return $this->notFound();
        }
        unset($this->snapshots[$id]);

        return Http::response(null, 204);
    }

    /** @return array<string, mixed> */
    public static function snapshotOf(int $id, string $name, int $droplet, int $disk = 50, string $created = '2026-11-27T10:05:00Z'): array
    {
        return ['id' => $id, 'name' => $name, 'regions' => ['ams3'], 'created_at' => $created, 'resource_id' => (string) $droplet, 'resource_type' => 'droplet',
            'min_disk_size' => $disk, 'size_gigabytes' => 2.36, 'tags' => [], 'droplet' => $droplet];
    }

    /** @return array<string, mixed> */
    public static function region(string $slug, bool $available = true): array
    {
        $names = ['ams3' => 'Amsterdam 3', 'fra1' => 'Frankfurt 1', 'nyc1' => 'New York 1', 'lon1' => 'London 1', 'sfo3' => 'San Francisco 3'];

        return ['name' => $names[$slug] ?? strtoupper($slug), 'slug' => $slug, 'features' => ['backups', 'ipv6', 'metadata'], 'available' => $available, 'sizes' => ['s-1vcpu-1gb', 's-1vcpu-2gb', 's-2vcpu-4gb']];
    }

    /** @return array<string, mixed> */
    public static function size(string $slug, bool $available = true): array
    {
        $specs = [
            's-1vcpu-1gb' => [1024, 1, 25, 6.0, 0.00893, ['ams3', 'fra1', 'nyc1']],
            's-1vcpu-2gb' => [2048, 1, 50, 12.0, 0.01786, ['ams3', 'fra1', 'nyc1']],
            's-2vcpu-4gb' => [4096, 2, 80, 24.0, 0.03571, ['ams3', 'fra1', 'nyc1']],
            'g-2vcpu-8gb' => [8192, 2, 25, 63.0, 0.09375, ['ams3', 'nyc1']],
            's-4vcpu-8gb-retired' => [8192, 4, 160, 48.0, 0.07143, ['ams3']],
            'gpu-h100x1-80gb' => [245760, 20, 720, 2512.0, 3.39, ['ams3']],
        ];
        [$memory, $vcpus, $disk, $monthly, $hourly, $regions] = $specs[$slug] ?? [2048, 1, 50, 12.0, 0.01786, ['ams3']];

        return [
            'slug' => $slug, 'memory' => $memory, 'vcpus' => $vcpus, 'disk' => $disk, 'transfer' => 2.0,
            'price_monthly' => $monthly, 'price_hourly' => $hourly, 'regions' => $regions, 'available' => $available,
            'description' => str_starts_with($slug, 's-') ? 'Basic' : (str_starts_with($slug, 'gpu-') ? 'GPU' : 'General Purpose'),
            ...(str_starts_with($slug, 'gpu-') ? ['gpu_info' => ['count' => 1, 'model' => 'nvidia_h100']] : []),
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function distributionImages(): array
    {
        $image = fn (int $id, string $slug, string $distribution, string $name) => [
            'id' => $id, 'name' => $name, 'distribution' => $distribution, 'slug' => $slug, 'public' => true, 'regions' => ['ams3', 'fra1', 'nyc1'],
            'created_at' => '2026-01-01T00:00:00Z', 'type' => 'base', 'min_disk_size' => 7, 'size_gigabytes' => 2.4, 'description' => '', 'tags' => [], 'status' => 'available',
        ];

        return [
            $image(1, 'ubuntu-22-04-x64', 'Ubuntu', '22.04 (LTS) x64'),
            $image(2, 'ubuntu-24-04-x64', 'Ubuntu', '24.04 (LTS) x64'),
            $image(3, 'ubuntu-25-04-x64', 'Ubuntu', '25.04 x64'),
            $image(4, 'debian-12-x64', 'Debian', '12 x64'),
            $image(5, 'fedora-41-x64', 'Fedora', '41 x64'),
        ];
    }
}
