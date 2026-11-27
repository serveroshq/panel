<?php

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Models\Workspace;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{0: Workspace, 1: Machine, 2: string, 3: DaemonJob} an app whose only deploy failed */
function failedApp(string $version = '0.1.1-pre.51', array $spec = []): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('services.github.token', null);
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'api.github.com/repos/acme/shop/commits/release' => Http::response(['sha' => str_repeat('f', 40)]),
        'api.github.com/repos/acme/shop/commits/v2.0' => Http::response(['sha' => str_repeat('9', 40)]),
        '*' => Http::response(null, 404),
    ]);
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $version, 'app_settings' => ['shop' => ['branch' => 'release']]])->save();

    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop',
        'state' => 'failed', 'phase' => 'health', 'finished_at' => now(),
        'error' => ['phase' => 'health', 'message' => 'health check failed: returned HTTP 404'],
        'command' => ['timeout_secs' => 1800, 'job' => [
            'type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40),
            'dockerfile' => 'Dockerfile', 'files' => [], 'domains' => ['shop.example.com'], 'port' => 8080,
            'env' => ['PORT' => '8080', 'SECRET' => 's3cret', 'NEXT_PUBLIC_API' => 'https://api'],
            'health' => ['path' => '/', 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => 5],
            ...$spec,
        ]],
    ]);

    return [$workspace, $machine, $workspace->url()."/machines/{$machine->id}/apps/shop", $job];
}

/** @return array<string, mixed> */
function lastAppDeploy(): array
{
    $request = collect(Http::recorded())->map(fn (array $pair) => $pair[0])
        ->filter(fn (GatewayRequest $r) => str_ends_with($r->url(), '/commands'))->last();

    return json_decode($request->body(), true)['command']['job'];
}

test('an app whose first deploy failed still has a page, with its settings and why it failed', function () {
    [$workspace, $machine] = failedApp();
    $this->withoutVite();

    $this->actingAs($workspace->owner)->get($workspace->url()."/machines/{$machine->id}/services/app:shop")
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/ServiceShow')
            ->where('service.missing', true)
            ->where('service.key', 'app:shop')
            ->where('deploy.source', 'git')
            ->where('deploy.branch', 'release')
            ->where('deploy.build.method', 'dockerfile')
            ->where('deploy.build.port', 8080)
            ->where('deploy.build.health.expectedStatus', 200)
            ->where('deploy.lastFailure.phase', 'health')
            ->where('deploy.lastFailure.explanation.settings', 'health')
            ->where('deploy.build.variables.0', ['name' => 'NEXT_PUBLIC_API', 'build' => true, 'runtime' => true, 'from' => null, 'auto' => false])
            ->where('deploy.can.deployLatest', true)
            ->where('deploy.can.rebuild', true)
            ->missing('deploy.build.variables.0.value'));

    $page = $this->actingAs($workspace->owner)->get($workspace->url()."/machines/{$machine->id}/services/app:shop");
    expect($page->getContent())->not->toContain('s3cret');

    // Once a deploy works, the port the daemon read from the image shows too.
    DaemonJob::query()->update(['state' => 'succeeded', 'result' => json_encode(['container_port' => 8080])]);
    $this->actingAs($workspace->owner)->get($workspace->url()."/machines/{$machine->id}/services/app:shop")
        ->assertInertia(fn (Assert $page) => $page->where('deploy.detectedPort', 8080)->where('deploy.lastFailure', null));
});

test('fixing the build settings of a failed app deploys it again with them, on the same commit', function () {
    [$workspace, , $url] = failedApp();

    $this->actingAs($workspace->owner)->putJson($url.'/build', [
        'health' => ['expected_status' => 0, 'start_period_secs' => 30], 'port' => 3000,
        'volumes' => [['name' => 'uploads', 'path' => '/app/storage']], 'release_command' => 'php artisan migrate --force',
    ])->assertAccepted()->assertJsonPath('changed', ['port', 'health', 'volumes', 'releaseCommand'])->assertJsonPath('deploy.deploying.state', 'queued');

    $job = lastAppDeploy();
    expect($job)->commit->toBe(str_repeat('a', 40))->port->toBe(3000)
        ->volumes->toBe([['name' => 'uploads', 'path' => '/app/storage']])
        ->release_command->toBe('php artisan migrate --force')
        ->and($job['health'])->toMatchArray(['expected_status' => 0, 'start_period_secs' => 30])
        ->and($job['env'])->toMatchArray(['PORT' => '3000', 'SECRET' => 's3cret'])
        ->and($job['files']['.env.production.local'])->toBe("NEXT_PUBLIC_API=\"https://api\"\n");
    expect(Activity::query()->where('action', 'app.build')->sole()->meta)->toMatchArray(['changed' => ['port', 'health', 'volumes', 'releaseCommand']]);

    // While that deploys, another change waits.
    $this->actingAs($workspace->owner)->putJson($url.'/build', ['port' => 4000])->assertStatus(409)->assertJsonPath('error', 'shop is deploying now. Wait for it to finish, or stop waiting on it first.');
});

test('switching to Railpack drops the Dockerfile, and nothing changing deploys nothing', function () {
    [$workspace, , $url] = failedApp();

    $this->actingAs($workspace->owner)->putJson($url.'/build', ['port' => 8080])->assertOk()->assertJsonPath('jobId', null);

    $this->actingAs($workspace->owner)->putJson($url.'/build', ['method' => 'railpack', 'start_command' => 'node server.js'])->assertAccepted();
    $job = lastAppDeploy();
    expect($job)->not->toHaveKey('dockerfile')
        ->and($job['build'])->toMatchArray(['method' => 'railpack', 'start_command' => 'node server.js'])
        ->and($job['env']['HOST'])->toBe('0.0.0.0')
        ->and($job['files'])->not->toHaveKey('.env.production.local');

    $this->actingAs($workspace->owner)->putJson($url.'/build', ['method' => 'image'])->assertUnprocessable()->assertJsonValidationErrors(['method' => 'can’t switch to a prebuilt image']);
});

test('settings are changed one by one or pasted as a .env, and each says where it goes', function () {
    [$workspace, $machine, $url] = failedApp();

    $this->actingAs($workspace->owner)->putJson($url.'/env', [
        'dotenv' => "export DATABASE_URL=\"postgres://db/shop\"\nSECRET=new",
        'env_flags' => ['SECRET' => 'runtime', 'NEXT_PUBLIC_API' => 'build'],
    ])->assertAccepted()->assertJsonPath('changed', ['DATABASE_URL', 'SECRET', 'NEXT_PUBLIC_API']);
    $job = lastAppDeploy();
    expect($job['env'])->toBe(['DATABASE_URL' => 'postgres://db/shop', 'PORT' => '8080', 'SECRET' => 'new'])
        ->and($job['build']['args'])->toBe(['DATABASE_URL' => 'postgres://db/shop', 'NEXT_PUBLIC_API' => 'https://api'])
        ->and(Activity::query()->where('action', 'app.variables')->sole()->meta)->toMatchArray(['names' => ['DATABASE_URL', 'SECRET', 'NEXT_PUBLIC_API']]);
    DaemonJob::query()->where('state', 'queued')->update(['state' => 'succeeded']);

    // Replacing with a pasted file removes what it leaves out.
    $this->actingAs($workspace->owner)->putJson($url.'/env', ['dotenv' => 'SECRET=newer', 'replace' => true])->assertAccepted();
    expect(lastAppDeploy()['env'])->toBe(['PORT' => '8080', 'SECRET' => 'newer']);

    $this->actingAs($workspace->owner)->putJson($url.'/env', ['set' => ['1BAD' => 'x']])->assertUnprocessable()->assertJsonValidationErrors('set');
    $this->actingAs($workspace->owner)->putJson($url.'/env', ['dotenv' => 'A="open'])->assertUnprocessable()->assertJsonValidationErrors('dotenv');
});

test('an older daemon can’t split settings or take new build settings, and says to update', function () {
    [$workspace, , $url] = failedApp('0.1.1-pre.49');

    $this->actingAs($workspace->owner)->putJson($url.'/env', ['set' => ['A' => '1'], 'env_flags' => ['A' => 'build']])
        ->assertUnprocessable()->assertJsonValidationErrors(['env_flags' => 'too old']);
    $this->actingAs($workspace->owner)->putJson($url.'/build', ['release_command' => 'migrate'])
        ->assertUnprocessable()->assertJsonValidationErrors(['release_command' => 'too old']);

    // What an older daemon reads still changes, with nothing new sent.
    $this->actingAs($workspace->owner)->putJson($url.'/build', ['health' => ['path' => '/health']])->assertAccepted();
    expect(lastAppDeploy())->not->toHaveKeys(['build', 'network'])
        ->and(lastAppDeploy()['health'])->toBe(['path' => '/health', 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => 5]);
});

test('deploying again takes the latest commit of the app’s branch, a commit asked for, or builds from scratch', function () {
    [$workspace, , $url] = failedApp();
    $finish = fn () => DaemonJob::query()->where('state', 'queued')->update(['state' => 'succeeded']);

    $this->actingAs($workspace->owner)->postJson($url.'/redeploy')->assertAccepted()->assertJsonPath('commit', str_repeat('f', 40))->assertJsonPath('ref', 'release');
    expect(lastAppDeploy()['commit'])->toBe(str_repeat('f', 40));
    $finish();

    $this->actingAs($workspace->owner)->postJson($url.'/redeploy', ['commit' => 'v2.0'])->assertAccepted()->assertJsonPath('commit', str_repeat('9', 40));
    $finish();

    $this->actingAs($workspace->owner)->postJson($url.'/redeploy', ['latest' => false, 'no_cache' => true])->assertAccepted();
    expect(lastAppDeploy()['build']['no_cache'])->toBeTrue();
    $finish();

    // Building from scratch is for that one deploy only.
    $this->actingAs($workspace->owner)->putJson($url.'/env', ['set' => ['X' => '1']])->assertAccepted();
    expect(lastAppDeploy()['build'])->not->toHaveKey('no_cache');
});

test('the app’s deploys are listed with where each came from, and one opens with its log', function () {
    [$workspace, , $url, $failed] = failedApp();
    $failed->forceFill(['log' => ['Step 1/3', 'health check failed']])->save();
    $this->actingAs($workspace->owner)->postJson($url.'/redeploy', ['latest' => false])->assertAccepted();

    $list = $this->actingAs($workspace->owner)->getJson($url.'/deploys')->assertOk()->assertJsonPath('more', false);
    expect($list->json('deploys'))->toHaveCount(2)
        ->and($list->json('deploys.0'))->toMatchArray(['kind' => 'deploy', 'state' => 'queued', 'trigger' => 'dashboard'])
        ->and($list->json('deploys.1'))->toMatchArray(['id' => $failed->id, 'state' => 'failed', 'commit' => str_repeat('a', 40)])
        ->and($list->json('deploys.1.explanation.cause'))->toContain('HTTP 404');

    $this->actingAs($workspace->owner)->getJson($url.'/jobs/'.$failed->id)->assertOk()->assertJsonPath('log', ['Step 1/3', 'health check failed']);
    $this->actingAs($workspace->owner)->getJson($url.'/jobs/'.Str::uuid())->assertNotFound();
});

test('deploying each push needs the GitHub app to see the repository, and an upload turns it off', function () {
    [$workspace, $machine, $url] = failedApp();

    $this->actingAs($workspace->owner)->putJson($url.'/settings', ['deploy_on_push' => true])
        ->assertUnprocessable()->assertJsonValidationErrors(['deploy_on_push' => 'needs GitHub connected']);

    $settings = $machine->app_settings;
    $settings['shop']['deploy_on_push'] = true;
    $machine->forceFill(['app_settings' => $settings])->save();
    DaemonJob::query()->update(['state' => 'succeeded']);
    $source = new DeploySource(['token' => Str::random(48), 'filename' => 'shop.zip', 'commit' => str_repeat('b', 40), 'size' => 10, 'received' => 10, 'status' => 'ready', 'expires_at' => now()->addDay()]);
    $source->forceFill(['workspace_id' => $workspace->id, 'user_id' => $workspace->owner_id])->save();

    $this->actingAs($workspace->owner)->postJson($url.'/versions', ['source' => $source->token])
        ->assertAccepted()->assertJsonPath('pushDeploysStopped', true);
    expect($machine->fresh()->app_settings['shop']['deploy_on_push'])->toBeFalse();
});
