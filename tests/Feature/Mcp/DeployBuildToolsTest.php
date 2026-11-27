<?php

use App\Enums\Plan;
use App\Mcp\Tools\DeployTools;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(fn () => config()->set('mcp.groups', [DeployTools::class]));

/** @return array{0: Workspace, 1: Machine, 2: string} */
function buildToolsSetup(string $version = '0.1.1-pre.51', array $fakes = []): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('services.github.token', null);
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'api.github.com/repos/acme/shop' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/shop/commits/*' => Http::response(['sha' => str_repeat('c', 40)]),
        ...$fakes,
        '*' => Http::response(null, 404),
    ]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '10.0.0.5']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $version, 'facts' => ['docker_version' => '27.1'], 'inventory' => ['services' => []]])->save();

    return [$workspace, $machine, mcpKey($workspace)];
}

test('deploy_template builds with Railpack or runs an image, with build settings', function () {
    [$workspace, , $key] = buildToolsSetup();

    $railpack = mcpCall($workspace, $key, 'deploy_template', [
        'machine' => 'web-1', 'template' => 'auto-build', 'repo' => 'https://github.com/acme/shop',
        'build' => ['start_command' => 'python main.py', 'port' => 8000], 'env_flags' => ['TOKEN' => 'runtime'], 'extra_env' => ['TOKEN' => 'abc'],
    ]);
    expect($railpack['error'])->toBeFalse($railpack['text'])->and($railpack['data']['port'])->toBe(8000);
    $job = DaemonJob::query()->where('service', 'shop')->sole()->command['job'];
    expect($job['build'])->toMatchArray(['method' => 'railpack', 'start_command' => 'python main.py'])
        ->and($job['env'])->toMatchArray(['TOKEN' => 'abc', 'PORT' => '8000'])
        ->and($job['build']['args'] ?? [])->not->toHaveKey('TOKEN');

    $image = mcpCall($workspace, $key, 'deploy_template', ['machine' => 'web-1', 'template' => 'docker-image', 'image' => 'ghcr.io/acme/api:1.4']);
    expect($image['error'])->toBeFalse($image['text'])->and($image['data']['name'])->toBe('api');
    expect(DaemonJob::query()->where('service', 'api')->sole()->command['job']['build'])->toMatchArray(['method' => 'image', 'image' => 'ghcr.io/acme/api:1.4']);

    $missing = mcpCall($workspace, $key, 'deploy_template', ['machine' => 'web-1', 'template' => 'docker-image']);
    expect($missing['error'])->toBeTrue()->and($missing['text'])->toContain('give image');
});

test('an older daemon is told to update for Railpack over MCP', function () {
    [$workspace, , $key] = buildToolsSetup('0.1.1-pre.49');

    $old = mcpCall($workspace, $key, 'deploy_template', ['machine' => 'web-1', 'template' => 'auto-build', 'repo' => 'https://github.com/acme/shop']);
    expect($old['error'])->toBeTrue()->and($old['text'])->toContain('too old to build an app without a Dockerfile');
});

test('update_app_settings fixes how a failed app builds, and get_app shows it', function () {
    [$workspace, $machine, $key] = buildToolsSetup();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'failed',
        'error' => ['phase' => 'health', 'message' => 'health check failed: not accepting connections'],
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40), 'dockerfile' => 'Dockerfile', 'port' => 80, 'env' => ['A' => '1'], 'domains' => []]],
    ]);

    $app = mcpCall($workspace, $key, 'get_app', ['machine' => 'web-1', 'app' => 'shop']);
    expect($app['error'])->toBeFalse($app['text'])
        ->and($app['data']['build'])->toMatchArray(['method' => 'dockerfile', 'port' => 80])
        ->and($app['data']['lastFailure']['explanation']['settings'])->toBe('port')
        ->and($app['data']['branch'])->toBe('main');

    $fixed = mcpCall($workspace, $key, 'update_app_settings', ['machine' => 'web-1', 'app' => 'shop', 'build' => ['port' => 8080, 'health' => ['expected_status' => 0]]]);
    expect($fixed['error'])->toBeFalse($fixed['text'])->and($fixed['data']['buildChanged'])->toBe(['port', 'health']);
    $job = DaemonJob::query()->where('state', 'queued')->sole()->command['job'];
    expect($job)->port->toBe(8080)->commit->toBe(str_repeat('a', 40))->and($job['health']['expected_status'])->toBe(0);
});

test('redeploy_app builds from scratch on request, and deploy_new_version follows the app’s branch', function () {
    [$workspace, $machine, $key] = buildToolsSetup();
    $machine->forceFill(['app_settings' => ['shop' => ['branch' => 'release']]])->save();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40), 'dockerfile' => 'Dockerfile', 'port' => 80, 'env' => [], 'domains' => []]],
    ]);

    $fresh = mcpCall($workspace, $key, 'redeploy_app', ['machine' => 'web-1', 'app' => 'shop', 'no_cache' => true]);
    expect($fresh['error'])->toBeFalse($fresh['text'])
        ->and(DaemonJob::query()->where('state', 'queued')->sole()->command['job']['build']['no_cache'])->toBeTrue();
    DaemonJob::query()->where('state', 'queued')->update(['state' => 'succeeded']);

    $latest = mcpCall($workspace, $key, 'deploy_new_version', ['machine' => 'web-1', 'app' => 'shop']);
    expect($latest['error'])->toBeFalse($latest['text'])->and($latest['data'])->toMatchArray(['ref' => 'release', 'commit' => str_repeat('c', 40)]);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'commits/release'));
});

test('detect_repository falls back to Railpack and says what Railpack sees', function () {
    $sha = str_repeat('a', 40);
    [$workspace, , $key] = buildToolsSetup(fakes: [
        'api.github.com/repos/acme/worker' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/worker/commits/main' => Http::response(['sha' => $sha]),
        "api.github.com/repos/acme/worker/git/trees/{$sha}" => Http::response(['tree' => [['path' => 'requirements.txt'], ['path' => '.env.example']]]),
        "raw.githubusercontent.com/acme/worker/{$sha}/requirements.txt" => Http::response("celery\nredis"),
        "raw.githubusercontent.com/acme/worker/{$sha}/.env.example" => Http::response('REDIS_URL=redis://localhost'),
    ]);

    $found = mcpCall($workspace, $key, 'detect_repository', ['repository' => 'acme/worker', 'machine' => 'web-1']);
    expect($found['error'])->toBeFalse($found['text'])
        ->and($found['data'])->toMatchArray(['template' => 'auto-build', 'framework' => 'Python'])
        ->and($found['data']['railpack'])->toMatchArray(['language' => 'Python', 'packageManager' => 'pip'])
        ->and($found['data']['envExample']['variables'])->toBe([['name' => 'REDIS_URL', 'value' => 'redis://localhost']]);
});
