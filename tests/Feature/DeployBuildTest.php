<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Deploys\AppDeploys;
use App\Support\Deploys\BuildSettings;
use App\Support\Deploys\DeployOnPush;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @return array{0: Workspace, 1: Machine, 2: string} */
function buildMachine(string $version = '0.1.1-pre.51'): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('services.github.token', null);
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'api.github.com/repos/acme/shop' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/shop/commits/*' => Http::response(['sha' => str_repeat('c', 40)]),
        '*' => Http::response(null, 404),
    ]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '203.0.113.7']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $version, 'facts' => ['docker_version' => '27.1']])->save();

    return [$workspace, $machine, $workspace->url().'/machines/'.$machine->id.'/deploy'];
}

/** @return array<string, mixed> the last deploy sent to the machine */
function lastSentDeploy(): array
{
    $request = collect(Http::recorded())->map(fn (array $pair) => $pair[0])
        ->filter(fn (GatewayRequest $r) => str_ends_with($r->url(), '/commands'))->last();
    expect($request)->not->toBeNull();

    return json_decode($request->body(), true)['command']['job'];
}

test('a repository with nothing ServerOS knows builds with Railpack, with a working address and a forgiving health check', function () {
    [$workspace, $machine, $url] = buildMachine();

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'auto-build', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'branch' => 'main',
        'extra_env' => "export SECRET=\"two\\nlines\"\nNEXT_PUBLIC_API='https://api.example.com' # the API\nBUILD_ONLY=1",
        'env_flags' => ['SECRET' => 'runtime', 'BUILD_ONLY' => 'build'],
    ])->assertAccepted()
        ->assertJsonPath('domain', 'shop.203-0-113-7.sslip.io')
        ->assertJsonPath('appUrl', $workspace->url("/machines/{$machine->id}/services/app:shop"));

    $job = lastSentDeploy();
    expect($job)->toMatchArray([
        'repo' => 'https://github.com/acme/shop',
        'commit' => str_repeat('c', 40),
        'port' => 3000,
        'network' => 'serveros',
        'domains' => ['shop.203-0-113-7.sslip.io'],
        'health' => ['path' => '/', 'expected_status' => 0, 'timeout_secs' => 10, 'retries' => 5, 'start_period_secs' => 60, 'interval_secs' => 3],
    ])->and($job)->not->toHaveKey('dockerfile')
        ->and($job['build'])->toMatchArray(['method' => 'railpack', 'submodules' => true, 'lfs' => true])
        ->and($job['env'])->toMatchArray(['SECRET' => "two\nlines", 'NEXT_PUBLIC_API' => 'https://api.example.com', 'PORT' => '3000', 'HOST' => '0.0.0.0'])
        ->and($job['env'])->not->toHaveKey('BUILD_ONLY')
        ->and($job['build']['args'])->toHaveKeys(['BUILD_ONLY', 'NEXT_PUBLIC_API', 'PORT'])
        ->and($job['build']['args'])->not->toHaveKey('SECRET');

    // The branch it came from is the one it follows from now on.
    expect(DeployOnPush::setting($machine->fresh(), 'shop')['branch'])->toBe('main');
});

test('a prebuilt image runs with nothing fetched, its port read from the image', function () {
    [$workspace, , $url] = buildMachine();

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'docker-image', 'service' => 'api', 'free_address' => false,
        'build' => ['image' => 'ghcr.io/acme/api:1.4', 'volumes' => [['name' => 'data', 'path' => '/data']], 'release_command' => 'bin/migrate'],
    ])->assertAccepted()->assertJsonPath('port', null);

    $job = lastSentDeploy();
    expect($job)->repo->toBe('')
        ->commit->toBe(BuildSettings::imageCommit('ghcr.io/acme/api:1.4'))
        ->port->toBeNull()
        ->domains->toBe([])
        ->volumes->toBe([['name' => 'data', 'path' => '/data']])
        ->release_command->toBe('bin/migrate')
        ->and($job['build'])->toMatchArray(['method' => 'image', 'image' => 'ghcr.io/acme/api:1.4'])
        ->and($job['env'])->not->toHaveKey('PORT');
    expect(AppDeploys::isApp(DaemonJob::query()->sole()->command['job']))->toBeTrue();
});

test('your own Dockerfile leaves the port to its EXPOSE, can turn the health check off, and builds a stage', function () {
    [$workspace, , $url] = buildMachine();

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'custom-dockerfile', 'service' => 'web', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('d', 40),
        'build' => ['port' => null, 'dockerfile' => 'docker/web.Dockerfile', 'target' => 'production', 'health' => ['enabled' => false]],
    ])->assertAccepted();

    $job = lastSentDeploy();
    expect($job)->port->toBeNull()->health->toBeNull()->dockerfile->toBe('docker/web.Dockerfile')
        ->and($job['build'])->toMatchArray(['method' => 'dockerfile', 'target' => 'production']);
});

test('a framework’s known health route still has to answer 200, with time to boot', function () {
    [$workspace, , $url] = buildMachine();

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'laravel', 'service' => 'blog', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('d', 40), 'free_address' => false,
    ])->assertAccepted();

    expect(lastSentDeploy()['health'])->toMatchArray(['path' => '/up', 'expected_status' => 200, 'start_period_secs' => 60])
        ->and(lastSentDeploy()['dockerfile'])->toBe('.serveros/Dockerfile');
});

test('an older daemon gets the deploy it always did, and is told to update for what’s new', function () {
    [$workspace, , $url] = buildMachine('0.1.1-pre.49');

    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'custom-dockerfile', 'service' => 'web', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('d', 40)])
        ->assertAccepted()->assertJsonPath('domain', null);
    $job = lastSentDeploy();
    expect($job)->not->toHaveKeys(['build', 'network', 'volumes'])
        ->and($job['port'])->toBe(80)
        ->and($job['health'])->toBe(['path' => '/', 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => 5]);

    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'auto-build', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('d', 40)])
        ->assertUnprocessable()->assertJsonValidationErrors(['template' => 'too old to build an app without a Dockerfile']);
    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'custom-dockerfile', 'service' => 'web2', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('d', 40), 'build' => ['volumes' => [['name' => 'data', 'path' => '/data']]]])
        ->assertUnprocessable()->assertJsonValidationErrors(['build.volumes' => 'too old']);
});

test('two apps on one machine can’t share a domain, and a Compose file with no port can’t have one', function () {
    [$workspace, $machine, $url] = buildMachine();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40), 'domains' => ['shop.example.com']]],
    ]);
    $machine->forceFill(['inventory' => ['services' => [['key' => 'docker:abc', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'status' => 'running', 'details' => []]]]])->save();

    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'custom-dockerfile', 'service' => 'other', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('d', 40), 'domain' => 'shop.example.com'])
        ->assertUnprocessable()->assertJsonValidationErrors(['domain' => 'already shop’s domain']);
    $this->actingAs($workspace->owner)->getJson($url.'/taken')->assertJsonPath('domains', [['domain' => 'shop.example.com', 'app' => 'shop']])
        ->assertJsonPath('canDeployBuild', true)->assertJsonPath('docker', ['installed' => true, 'canInstall' => false]);

    // The same app keeps its own domain.
    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'custom-dockerfile', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('d', 40), 'domain' => 'shop.example.com'])
        ->assertAccepted();

    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'compose-file', 'service' => 'stack', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('d', 40), 'domain' => 'stack.example.com'])
        ->assertUnprocessable()->assertJsonValidationErrors(['port' => 'doesn’t publish a port']);
});

test('a pasted .env that doesn’t parse says which line', function () {
    [$workspace, , $url] = buildMachine();

    $this->actingAs($workspace->owner)->postJson($url, ['template' => 'auto-build', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('d', 40), 'extra_env' => "OK=1\nnot a setting"])
        ->assertUnprocessable()->assertJsonValidationErrors(['extra_env' => 'Line 2 should look like NAME=value.']);
});
