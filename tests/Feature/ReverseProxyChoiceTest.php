<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Deploys\DeployFailure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('daemon.proxy_choice_since', '0.1.1-pre.40');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
});

/** @return array{0: Workspace, 1: Machine, 2: string} */
function proxyMachine(string $version = '0.1.1-pre.40', ?string $proxy = 'caddy'): array
{
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '203.0.113.7']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'certificate_serial' => bin2hex(random_bytes(6)),
        'last_seen_at' => now(),
        'daemon_version' => $version,
        'proxy' => $proxy,
        'facts' => ['docker_version' => '27.0.1'],
    ])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id];
}

/** @return array<string, mixed>|null */
function queuedDeploy(): ?array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/commands'))
        ->map(fn (array $pair) => json_decode($pair[0]->body(), true)['command']['job'])
        ->last();
}

test('the daemon says which reverse proxy the machine uses when it connects', function () {
    [, $machine] = proxyMachine(proxy: null);
    $hello = fn (array $extra) => $this->postJson('/api/gateway/hello', [
        'serial' => $machine->certificate_serial,
        'hello' => ['machine_id' => $machine->uid, 'daemon_version' => '0.1.1-pre.40', 'supported_majors' => [1], 'facts' => ['hostname' => 'web-1'], ...$extra],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $hello(['proxy' => 'nginx']);
    expect($machine->fresh()->proxy)->toBe('nginx');

    $hello([]);
    expect($machine->fresh()->proxy)->toBe('nginx');

    $this->postJson('/api/gateway/hello', [
        'serial' => $machine->certificate_serial,
        'hello' => ['machine_id' => $machine->uid, 'daemon_version' => '0.1.1-pre.40', 'supported_majors' => [1], 'facts' => [], 'proxy' => 'lighttpd'],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertUnprocessable();
});

test('a deploy can move the machine to another reverse proxy', function (string $proxy) {
    [$workspace, , $url] = proxyMachine();

    $this->actingAs($workspace->owner)
        ->postJson($url.'/deploy', ['template' => 'uptime-kuma', 'service' => 'status', 'domain' => 'status.acme.io', 'proxy' => $proxy])
        ->assertAccepted();

    expect(queuedDeploy())->toMatchArray(['domains' => ['status.acme.io'], 'proxy' => $proxy]);
})->with(['nginx', 'apache', 'traefik']);

test('picking the proxy the machine already uses, or deploying without a domain, leaves the proxy alone', function () {
    [$workspace, , $url] = proxyMachine(proxy: 'nginx');

    $this->actingAs($workspace->owner)
        ->postJson($url.'/deploy', ['template' => 'uptime-kuma', 'service' => 'status', 'domain' => 'status.acme.io', 'proxy' => 'nginx'])
        ->assertAccepted();
    expect(queuedDeploy())->not->toHaveKey('proxy');

    $this->actingAs($workspace->owner)
        ->postJson($url.'/deploy', ['template' => 'uptime-kuma', 'service' => 'status2', 'proxy' => 'traefik'])
        ->assertAccepted();
    expect(queuedDeploy())->not->toHaveKey('proxy');
});

test('a machine that never said which proxy it uses is on Caddy', function () {
    [$workspace, , $url] = proxyMachine(proxy: null);

    $this->actingAs($workspace->owner)
        ->postJson($url.'/deploy', ['template' => 'uptime-kuma', 'service' => 'status', 'domain' => 'status.acme.io', 'proxy' => 'caddy'])
        ->assertAccepted();

    expect(queuedDeploy())->not->toHaveKey('proxy');
});

test('an older daemon can’t switch proxy, and only the four proxies can be picked', function () {
    [$workspace, , $url] = proxyMachine('0.1.1-pre.39');

    $this->actingAs($workspace->owner)
        ->postJson($url.'/deploy', ['template' => 'uptime-kuma', 'service' => 'status', 'domain' => 'status.acme.io', 'proxy' => 'nginx'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['proxy' => 'too old to switch reverse proxy']);

    [$workspace, , $url] = proxyMachine();
    $this->actingAs($workspace->owner)
        ->postJson($url.'/deploy', ['template' => 'uptime-kuma', 'service' => 'status', 'domain' => 'status.acme.io', 'proxy' => 'lighttpd'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['proxy']);

    expect(queuedDeploy())->toBeNull();
});

test('the deploy dialog is told the machine’s proxy and whether it can switch', function () {
    [$workspace, , $url] = proxyMachine(proxy: 'apache');

    $this->actingAs($workspace->owner)->getJson($url.'/deploy/taken')
        ->assertOk()
        ->assertJson(['proxy' => 'apache', 'canChooseProxy' => true]);

    [$workspace, , $url] = proxyMachine('0.1.1-pre.39', null);
    $this->actingAs($workspace->owner)->getJson($url.'/deploy/taken')
        ->assertOk()
        ->assertJson(['proxy' => null, 'canChooseProxy' => false]);
});

test('a finished deploy records the proxy the machine ended up on', function () {
    [, $machine] = proxyMachine();
    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'status',
        'state' => 'running', 'log' => [], 'command' => ['job' => ['type' => 'deploy', 'service' => 'status', 'proxy' => 'traefik']],
    ]);

    $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => [
            'job_id' => $job->id, 'state' => 'succeeded', 'result' => ['service' => 'status', 'proxy' => 'traefik'],
        ]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    expect($machine->fresh()->proxy)->toBe('traefik');
});

test('a proxy that won’t start is explained by name', function () {
    $nginx = DeployFailure::fromError('proxy', 'nginx is not running and would not start', []);
    expect($nginx['cause'])->toContain('nginx (the web server that gives it HTTPS)')
        ->and($nginx['command'])->toBe('systemctl enable --now nginx');

    $apache = DeployFailure::fromError('proxy', 'apache2 is not running and would not start', []);
    expect($apache['cause'])->toContain('Apache')
        ->and($apache['command'])->toBe('systemctl enable --now apache2');

    $caddy = DeployFailure::fromError('proxy', 'caddy is not running and would not start', []);
    expect($caddy['command'])->toBe('systemctl enable --now caddy');
});
