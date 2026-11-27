<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for the Vultr API v2, so tests never call the real one.
 * It keeps instances and snapshots, records every call, and can be told to
 * fail the next calls to a path. Like Vultr, it has no actions: changes
 * show on the instance after a few reads.
 */
final class FakeVultr
{
    public const string KEY = 'VULTRTESTKEY0123456789ABCDEFGHIJKLMN';

    /** @var array<string, array<string, mixed>> */
    public array $instances = [];

    /** @var array<string, array<string, mixed>> */
    public array $snapshots = [];

    /** @var list<array{method: string, path: string, body: array<string, mixed>, query: array<string, mixed>}> */
    public array $calls = [];

    /** Reads of an instance before a change shows on it. */
    public int $settlePolls = 1;

    /** @var array<string, array{polls: int, apply: callable}> */
    private array $pending = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>, headers: array<string, string>}>> */
    private array $failures = [];

    private int $next = 1;

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake(['api.vultr.com/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    public static function id(int $n): string
    {
        return sprintf('cb676a46-66fd-4dfb-b839-%012d', $n);
    }

    /** @param  array<string, mixed>  $overrides */
    public function instance(string $id, string $label, ?string $ipv4, ?string $v6Network = null, array $overrides = []): self
    {
        $plan = self::plan((string) ($overrides['plan'] ?? 'vc2-1c-2gb'));
        $this->instances[$id] = array_replace([
            'id' => $id, 'os' => 'Ubuntu 24.04 LTS x64', 'ram' => $plan['ram'], 'disk' => $plan['disk'], 'plan' => $plan['id'],
            'main_ip' => $ipv4 ?? '0.0.0.0', 'vcpu_count' => $plan['vcpu_count'], 'region' => 'ams', 'date_created' => '2026-11-01T10:00:00+00:00',
            'status' => 'active', 'allowed_bandwidth' => 2000, 'netmask_v4' => '255.255.254.0', 'gateway_v4' => '203.0.113.1',
            'power_status' => 'running', 'server_status' => 'ok',
            'v6_network' => $v6Network ?? '', 'v6_main_ip' => $v6Network === null ? '' : $v6Network.'1', 'v6_network_size' => $v6Network === null ? 0 : 64,
            'label' => $label, 'internal_ip' => '', 'kvm' => '', 'hostname' => $label, 'os_id' => 2284, 'app_id' => 0, 'image_id' => '',
            'firewall_group_id' => '', 'features' => ['ipv6'], 'tags' => [], 'user_scheme' => 'root',
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
        $path = trim(substr((string) ($url['path'] ?? ''), strlen('/v2')), '/');
        parse_str((string) ($url['query'] ?? ''), $query);
        $method = strtoupper($request->method());
        $body = $request->data();
        $this->calls[] = ['method' => $method, 'path' => $path, 'body' => is_array($body) ? $body : [], 'query' => $query];

        $key = $method.' '.$path;
        if (($this->failures[$key] ?? []) !== [] && ($failure = array_shift($this->failures[$key])) !== null) {
            return Http::response($failure['body'] === [] ? ['error' => 'Internal error', 'status' => $failure['status']] : $failure['body'], $failure['status'], $failure['headers']);
        }
        if ($request->header('Authorization')[0] !== 'Bearer '.self::KEY) {
            return Http::response(['error' => 'Invalid API token.', 'status' => 401], 401);
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
        $id = (string) ($parts[1] ?? '');
        $known = $parts[0] === 'instances' && isset($this->instances[$id]);

        return match (true) {
            $method === 'GET' && $path === 'account' => Http::response(['account' => [
                'name' => 'Sam Rivera', 'email' => 'sam@example.com', 'acls' => ['manage_users', 'subscriptions', 'provisioning', 'billing'],
                'balance' => -12.5, 'pending_charges' => 3.1, 'last_payment_date' => '2026-11-01T00:00:00+00:00', 'last_payment_amount' => -20,
            ]]),
            $method === 'GET' && $path === 'instances' => $this->page('instances', array_values(array_map(fn (string $i) => $this->read($i), array_keys($this->instances))), $query),
            $method === 'POST' && $path === 'instances' => $this->create($body),
            $parts[0] === 'instances' && count($parts) >= 2 && ! $known => $this->notFound(),
            $method === 'GET' && $parts[0] === 'instances' && count($parts) === 2 => Http::response(['instance' => $this->read($id)]),
            $method === 'PATCH' && $parts[0] === 'instances' && count($parts) === 2 => $this->upgrade($id, $body),
            $method === 'DELETE' && $parts[0] === 'instances' && count($parts) === 2 => $this->deleteInstance($id),
            $method === 'GET' && $parts[0] === 'instances' && ($parts[2] ?? null) === 'upgrades' => Http::response(['upgrades' => ['plans' => $this->upgradesFor($id)]]),
            $method === 'POST' && $parts[0] === 'instances' && in_array($parts[2] ?? null, ['reboot', 'start', 'halt'], true) => $this->power($id, (string) $parts[2]),
            $method === 'GET' && $path === 'snapshots' => $this->page('snapshots', array_values($this->snapshots), $query),
            $method === 'POST' && $path === 'snapshots' => $this->takeSnapshot($body),
            $method === 'DELETE' && $parts[0] === 'snapshots' => $this->deleteSnapshot($id),
            $method === 'GET' && $path === 'regions' => $this->page('regions', [self::region('ams'), self::region('fra'), self::region('ewr')], $query),
            $method === 'GET' && $path === 'plans' => $this->page('plans', array_map(fn (string $p) => self::plan($p), ['vc2-1c-1gb', 'vc2-1c-2gb', 'vc2-2c-4gb', 'vc2-4c-8gb', 'voc-g-2c-8gb-50s', 'vcg-a100-1c-6g-4vram']), $query),
            $method === 'GET' && $path === 'os' => $this->page('os', [
                ['id' => 1743, 'name' => 'Ubuntu 22.04 LTS x64', 'arch' => 'x64', 'family' => 'ubuntu'],
                ['id' => 2284, 'name' => 'Ubuntu 24.04 LTS x64', 'arch' => 'x64', 'family' => 'ubuntu'],
                ['id' => 2465, 'name' => 'Ubuntu 25.04 x64', 'arch' => 'x64', 'family' => 'ubuntu'],
                ['id' => 2136, 'name' => 'Debian 12 x64 (bookworm)', 'arch' => 'x64', 'family' => 'debian'],
                ['id' => 2076, 'name' => 'Alpine Linux x64', 'arch' => 'x64', 'family' => 'alpinelinux'],
                ['id' => 159, 'name' => 'Custom', 'arch' => 'x64', 'family' => 'iso'],
            ], $query),
            $method === 'GET' && $path === 'ssh-keys' => $this->page('ssh_keys', [['id' => '3b8066a7-b438-455a-9688-44afc9a3597f', 'date_created' => '2026-01-01T00:00:00+00:00', 'name' => 'sam@laptop', 'ssh_key' => 'ssh-ed25519 AAAA']], $query),
            default => $this->notFound(),
        };
    }

    private function notFound(): PromiseInterface
    {
        return Http::response(['error' => 'Not found', 'status' => 404], 404);
    }

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  array<string, mixed>  $query
     */
    private function page(string $key, array $items, array $query): PromiseInterface
    {
        $per = (int) ($query['per_page'] ?? 100);
        $offset = isset($query['cursor']) ? (int) base64_decode((string) $query['cursor']) : 0;
        $chunk = array_slice($items, $offset, $per);
        $next = $offset + $per < count($items) ? base64_encode((string) ($offset + $per)) : '';

        return Http::response([$key => $chunk, 'meta' => ['total' => count($items), 'links' => ['next' => $next, 'prev' => '']]]);
    }

    /**
     * Reads an instance, letting a change that's under way land after a few reads.
     *
     * @return array<string, mixed>
     */
    private function read(string $id): array
    {
        if (isset($this->pending[$id]) && $this->pending[$id]['polls']-- <= 0) {
            $apply = $this->pending[$id]['apply'];
            unset($this->pending[$id]);
            $apply();
        }

        return $this->instances[$id];
    }

    private function later(string $id, callable $apply): void
    {
        $this->pending[$id] = ['polls' => $this->settlePolls, 'apply' => $apply];
    }

    /** @param  array<string, mixed>  $body */
    private function create(array $body): PromiseInterface
    {
        if (! is_string($body['user_data'] ?? null) || base64_decode($body['user_data'], true) === false) {
            return Http::response(['error' => 'Invalid user_data. It must be base64 encoded.', 'status' => 400], 400);
        }
        $id = self::id(900 + $this->next++);
        $this->instance($id, (string) $body['label'], null, null, [
            'plan' => (string) $body['plan'], 'region' => (string) $body['region'], 'status' => 'pending', 'power_status' => 'stopped',
            'server_status' => 'none', 'hostname' => (string) ($body['hostname'] ?? $body['label']), 'tags' => (array) ($body['tags'] ?? []),
            'default_password' => 'v3ry-s3cret',
        ]);
        $this->later($id, function () use ($id): void {
            $n = hexdec(substr($id, -3)) % 250;
            $this->instances[$id] = [...$this->instances[$id], 'status' => 'active', 'power_status' => 'running', 'server_status' => 'ok',
                'main_ip' => '203.0.113.'.$n, 'v6_network' => '2001:db8:'.$n.'::', 'v6_main_ip' => '2001:db8:'.$n.'::1', 'v6_network_size' => 64];
        });

        return Http::response(['instance' => $this->instances[$id]], 202);
    }

    /** @param  array<string, mixed>  $body */
    private function upgrade(string $id, array $body): PromiseInterface
    {
        $plan = (string) ($body['plan'] ?? '');
        if (! in_array($plan, $this->upgradesFor($id), true)) {
            return Http::response(['error' => 'Invalid plan. Downgrades are not supported.', 'status' => 400], 400);
        }
        $this->instances[$id]['server_status'] = 'locked';
        $this->later($id, function () use ($id, $plan): void {
            $spec = self::plan($plan);
            $this->instances[$id] = [...$this->instances[$id], 'plan' => $plan, 'ram' => $spec['ram'], 'disk' => $spec['disk'], 'vcpu_count' => $spec['vcpu_count'], 'server_status' => 'ok'];
        });

        return Http::response(['instance' => $this->instances[$id]], 202);
    }

    /** @return list<string> */
    private function upgradesFor(string $id): array
    {
        $now = self::plan((string) $this->instances[$id]['plan']);
        $plans = ['vc2-1c-1gb', 'vc2-1c-2gb', 'vc2-2c-4gb', 'vc2-4c-8gb', 'voc-g-2c-8gb-50s'];

        return array_values(array_filter($plans, fn (string $p) => self::plan($p)['disk'] >= $now['disk'] && self::plan($p)['monthly_cost'] > $now['monthly_cost']));
    }

    private function power(string $id, string $what): PromiseInterface
    {
        $this->instances[$id]['power_status'] = $what === 'halt' ? 'stopped' : 'running';

        return Http::response(null, 204);
    }

    private function deleteInstance(string $id): PromiseInterface
    {
        unset($this->instances[$id]);

        return Http::response(null, 204);
    }

    /** @param  array<string, mixed>  $body */
    private function takeSnapshot(array $body): PromiseInterface
    {
        if (! isset($this->instances[(string) ($body['instance_id'] ?? '')])) {
            return Http::response(['error' => 'Invalid instance_id', 'status' => 400], 400);
        }
        $id = sprintf('5359435d-28b6-4ec5-ad8f-%012d', 500 + $this->next++);
        $this->snapshots[$id] = self::snapshotOf($id, (string) ($body['description'] ?? ''), 'pending', '2026-11-27T10:00:00+00:00');

        return Http::response(['snapshot' => $this->snapshots[$id]], 201);
    }

    private function deleteSnapshot(string $id): PromiseInterface
    {
        if (! isset($this->snapshots[$id])) {
            return $this->notFound();
        }
        unset($this->snapshots[$id]);

        return Http::response(null, 204);
    }

    /** @return array<string, mixed> */
    public static function snapshotOf(string $id, string $description, string $status = 'complete', string $created = '2026-11-20T09:00:00+00:00'): array
    {
        return ['id' => $id, 'date_created' => $created, 'description' => $description, 'size' => $status === 'pending' ? 0 : 53687091200,
            'compressed_size' => $status === 'pending' ? 0 : 2147483648, 'status' => $status, 'os_id' => 2284, 'app_id' => 0];
    }

    /** @return array<string, mixed> */
    public static function region(string $id): array
    {
        $cities = ['ams' => ['Amsterdam', 'NL', 'Europe'], 'fra' => ['Frankfurt', 'DE', 'Europe'], 'ewr' => ['New Jersey', 'US', 'North America'], 'lhr' => ['London', 'GB', 'Europe']];
        [$city, $country, $continent] = $cities[$id] ?? ['Somewhere', 'US', 'North America'];

        return ['id' => $id, 'city' => $city, 'country' => $country, 'continent' => $continent, 'options' => ['ddos_protection']];
    }

    /** @return array<string, mixed> */
    public static function plan(string $id): array
    {
        $specs = [
            'vc2-1c-1gb' => [1, 1024, 25, 5.0, 'vc2', ['ams', 'fra', 'ewr']],
            'vc2-1c-2gb' => [1, 2048, 55, 10.0, 'vc2', ['ams', 'fra', 'ewr']],
            'vc2-2c-4gb' => [2, 4096, 80, 20.0, 'vc2', ['ams', 'fra', 'ewr']],
            'vc2-4c-8gb' => [4, 8192, 160, 40.0, 'vc2', ['fra', 'ewr']],
            'voc-g-2c-8gb-50s' => [2, 8192, 50, 60.0, 'voc', ['ams', 'ewr']],
            'vcg-a100-1c-6g-4vram' => [1, 6144, 70, 90.0, 'vcg', ['ewr']],
        ];
        [$cpus, $ram, $disk, $monthly, $type, $locations] = $specs[$id] ?? [1, 2048, 55, 10.0, 'vc2', ['ams']];

        return [
            'id' => $id, 'vcpu_count' => $cpus, 'ram' => $ram, 'disk' => $disk, 'disk_count' => 1, 'bandwidth' => 2048,
            'monthly_cost' => $monthly, 'hourly_cost' => round($monthly / 730, 3), 'type' => $type, 'locations' => $locations,
            ...($type === 'vcg' ? ['gpu_vram_gb' => 4, 'gpu_type' => 'NVIDIA_A100'] : []),
        ];
    }
}
