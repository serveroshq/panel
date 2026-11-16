<?php

use App\Models\Machine;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('daemon.https_choice_since', '0.1.1-pre.41');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
});

/** @return array{0: Workspace, 1: string} */
function httpsMachine(string $version = '0.1.1-pre.41'): array
{
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '203.0.113.7']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => $version,
        'facts' => ['docker_version' => '27.0.1'],
    ])->save();

    return [$workspace, 'https://'.$workspace->host().'/machines/'.$machine->id];
}

/** @return array<string, mixed>|null */
function lastDeployJob(): ?array
{
    return collect(Http::recorded())
        ->filter(fn (array $pair) => str_ends_with($pair[0]->url(), '/commands'))
        ->map(fn (array $pair) => json_decode($pair[0]->body(), true)['command']['job'])
        ->last();
}

test('a deploy can serve its domain over plain HTTP, and says the address is http', function () {
    [$workspace, $url] = httpsMachine();

    $this->actingAs($workspace->owner)
        ->postJson($url.'/deploy', ['template' => 'uptime-kuma', 'service' => 'status', 'domain' => 'status.acme.io', 'https' => false])
        ->assertAccepted()
        ->assertJsonPath('url', 'http://status.acme.io');

    expect(lastDeployJob())->toMatchArray(['domains' => ['status.acme.io'], 'https' => false]);
});

test('HTTPS is sent when it is chosen, and left to the daemon when it is not said', function () {
    [$workspace, $url] = httpsMachine();

    $this->actingAs($workspace->owner)
        ->postJson($url.'/deploy', ['template' => 'uptime-kuma', 'service' => 'status', 'domain' => 'status.acme.io', 'https' => true])
        ->assertAccepted()
        ->assertJsonPath('url', 'https://status.acme.io');
    expect(lastDeployJob())->toMatchArray(['https' => true]);

    $this->actingAs($workspace->owner)
        ->postJson($url.'/deploy', ['template' => 'uptime-kuma', 'service' => 'status2', 'domain' => 'status2.acme.io'])
        ->assertAccepted();
    expect(lastDeployJob())->not->toHaveKey('https');
});

test('without a domain there is nothing to serve over HTTPS', function () {
    [$workspace, $url] = httpsMachine();

    $this->actingAs($workspace->owner)
        ->postJson($url.'/deploy', ['template' => 'uptime-kuma', 'service' => 'status', 'https' => false])
        ->assertAccepted();

    expect(lastDeployJob())->not->toHaveKey('https');
});

test('an older daemon can’t turn HTTPS off, but asking for HTTPS is fine', function () {
    [$workspace, $url] = httpsMachine('0.1.1-pre.40');

    $this->actingAs($workspace->owner)
        ->postJson($url.'/deploy', ['template' => 'uptime-kuma', 'service' => 'status', 'domain' => 'status.acme.io', 'https' => false])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['https' => 'too old to serve a domain without HTTPS']);
    expect(lastDeployJob())->toBeNull();

    $this->actingAs($workspace->owner)
        ->postJson($url.'/deploy', ['template' => 'uptime-kuma', 'service' => 'status', 'domain' => 'status.acme.io', 'https' => true])
        ->assertAccepted();
    expect(lastDeployJob())->not->toHaveKey('https');
});

test('the deploy dialog is told whether the machine can turn HTTPS off', function () {
    [$workspace, $url] = httpsMachine();
    $this->actingAs($workspace->owner)->getJson($url.'/deploy/taken')->assertOk()->assertJson(['canChooseHttps' => true]);

    [$workspace, $url] = httpsMachine('0.1.1-pre.40');
    $this->actingAs($workspace->owner)->getJson($url.'/deploy/taken')->assertOk()->assertJson(['canChooseHttps' => false]);
});
