<?php

use App\Enums\Plan;
use App\Mcp\Tools\DeployTools;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppLimits;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/** @return array{0: Workspace, 1: Machine, 2: string, 3: DaemonJob} */
function limitedApp(string $version = '0.1.1-pre.46', array $job = []): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $version,
        'facts' => ['cpu_cores' => 4, 'memory_bytes' => 8 * 1024 ** 3],
    ])->save();

    $live = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop',
        'state' => 'succeeded', 'log' => [],
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40), 'port' => 3000, 'env' => [], 'domains' => [], ...$job]],
    ]);
    $live->forceFill(['created_at' => now()->subHour()])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id.'/apps/shop/limits', $live];
}

/** @return list<array<string, mixed>> */
function sentDeploys(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (HttpRequest $request) => str_ends_with($request->url(), '/commands'))
        ->map(fn (HttpRequest $request) => json_decode($request->body(), true)['command']['job'])
        ->values()
        ->all();
}

test('limits are saved from the app page and the app deploys again with them', function () {
    [$workspace, $machine, $url] = limitedApp();

    $jobId = $this->actingAs($workspace->owner)->putJson($url, ['cpus' => 0.5, 'memory_mb' => 512])
        ->assertOk()->assertJsonPath('limits', ['cpus' => 0.5, 'memoryMb' => 512])->json('jobId');

    expect(DaemonJob::query()->find($jobId)->command['job']['limits'])->toBe(['cpu_millis' => 500, 'memory_mb' => 512])
        ->and(sentDeploys()[0]['limits'])->toBe(['cpu_millis' => 500, 'memory_mb' => 512])
        ->and(sentDeploys()[0]['commit'])->toBe(str_repeat('a', 40));
    expect(ActivityLine::describe(Activity::query()->where('action', 'app.limits')->sole(), false))
        ->verb->toBe('limited')->after->toBe('to 0.5 CPUs, 512 MB');

    // Saving the same again doesn't deploy.
    $this->actingAs($workspace->owner)->putJson($url, ['cpus' => 0.5, 'memory_mb' => 512])->assertOk()->assertJsonPath('jobId', null);
    expect(sentDeploys())->toHaveCount(1);

    // Every later deploy carries them, and taking them off takes them off the next one.
    app(DaemonJobQueue::class)->deploy($machine->fresh(), $workspace->owner, ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('b', 40)]);
    expect(sentDeploys()[1]['limits'])->toBe(['cpu_millis' => 500, 'memory_mb' => 512]);

    $this->actingAs($workspace->owner)->putJson($url, ['cpus' => null, 'memory_mb' => null])->assertOk()->assertJsonPath('limits', null);
    expect(sentDeploys()[2])->not->toHaveKey('limits')
        ->and($machine->fresh()->app_settings['shop'] ?? [])->not->toHaveKey('limits');
});

test('limits are checked against what the machine has', function () {
    [$workspace, , $url] = limitedApp();

    $this->actingAs($workspace->owner)->putJson($url, ['cpus' => 6, 'memory_mb' => null])->assertUnprocessable()
        ->assertJsonValidationErrors(['cpus' => 'web-1 has 4 CPUs, so the limit can’t be more.']);
    $this->actingAs($workspace->owner)->putJson($url, ['cpus' => null, 'memory_mb' => 9000])->assertUnprocessable()
        ->assertJsonValidationErrors(['memory_mb' => 'web-1 has 8192 MB of memory, so the limit can’t be more.']);
    $this->actingAs($workspace->owner)->putJson($url, ['cpus' => 0, 'memory_mb' => 16])->assertUnprocessable()->assertJsonValidationErrors(['cpus', 'memory_mb']);
    $this->actingAs($workspace->owner)->putJson($url, ['cpus' => 'lots', 'memory_mb' => null])->assertUnprocessable()->assertJsonValidationErrors('cpus');

    expect(sentDeploys())->toBe([]);
});

test('an older daemon, or a Compose app, can’t take limits, and none are sent to it', function () {
    [$workspace, $machine, $url] = limitedApp('0.1.1-pre.45');
    $this->actingAs($workspace->owner)->putJson($url, ['cpus' => 1, 'memory_mb' => null])->assertUnprocessable()
        ->assertJsonValidationErrors(['limits' => 'web-1’s daemon is too old to limit an app’s CPU and memory. It updates itself, or run “sudo serverosd update” on the server.']);

    // Saved while it was up to date, then deployed by an older daemon: left off.
    AppLimits::store($machine, 'shop', ['cpus' => 1.0, 'memory_mb' => null]);
    app(DaemonJobQueue::class)->deploy($machine->fresh(), $workspace->owner, ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('b', 40), 'limits' => ['cpu_millis' => 1000]]);
    expect(sentDeploys()[0])->not->toHaveKey('limits');

    [$workspace, , $url] = limitedApp(job: ['compose_file' => 'compose.yaml']);
    $this->actingAs($workspace->owner)->putJson($url, ['cpus' => 1, 'memory_mb' => null])->assertUnprocessable()->assertJsonValidationErrors('limits');
});

test('people who can’t manage machines can’t change limits', function () {
    [$workspace, , $url] = limitedApp();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->putJson($url, ['cpus' => 1, 'memory_mb' => null])->assertForbidden();
});

test('MCP sets limits, and get_app shows them as set and as applied', function () {
    config()->set('mcp.groups', [DeployTools::class]);
    [$workspace, $machine, , $live] = limitedApp();
    $key = mcpKey($workspace);

    $result = mcpCall($workspace, $key, 'update_app_settings', ['machine' => 'web-1', 'app' => 'shop', 'limits' => ['cpus' => 2, 'memory_mb' => 1024]]);
    expect($result['error'])->toBeFalse($result['text'])
        ->and($result['data']['limits'])->toEqual(['cpus' => 2, 'memoryMb' => 1024])
        ->and(sentDeploys()[0]['limits'])->toBe(['cpu_millis' => 2000, 'memory_mb' => 1024]);

    // One left out stays; a machine's daemon reports what it applied.
    mcpCall($workspace, $key, 'update_app_settings', ['machine' => 'web-1', 'app' => 'shop', 'limits' => ['memory_mb' => 2048]]);
    expect(AppLimits::setting($machine->fresh(), 'shop'))->toBe(['cpus' => 2.0, 'memoryMb' => 2048]);
    expect(mcpCall($workspace, $key, 'update_app_settings', ['machine' => 'web-1', 'app' => 'shop', 'limits' => ['cpus' => 64]])['text'])->toContain('web-1 has 4 CPUs');

    $live->forceFill(['result' => ['limits' => ['cpu_millis' => 2000, 'memory_mb' => 2048]]])->save();
    $app = mcpCall($workspace, $key, 'get_app', ['machine' => 'web-1', 'app' => 'shop']);
    expect($app['data']['limits'])->toEqual([
        'setting' => ['cpus' => 2, 'memoryMb' => 2048],
        'applied' => ['cpus' => 2, 'memoryMb' => 2048],
        'unavailable' => null,
        'max' => ['cpus' => 4, 'memoryMb' => 8192],
    ]);
});

test('the app page shows the limits the container runs with beside its usage', function () {
    $this->withoutVite();
    [$workspace, $machine, , $live] = limitedApp();
    $machine->forceFill(['inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
        ['key' => 'docker:abc123def456', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'origin' => 'created', 'details' => ['managed' => 'true']],
    ]]])->save();
    AppLimits::store($machine, 'shop', ['cpus' => 1.0, 'memory_mb' => 256]);
    $live->forceFill(['result' => ['limits' => ['cpu_millis' => 500]]])->save();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/machines/'.$machine->id.'/services/app:shop')
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->where('app.limits.setting', ['cpus' => 1, 'memoryMb' => 256])
            ->where('app.limits.applied', ['cpus' => 0.5, 'memoryMb' => null])
            ->where('app.limits.unavailable', null)
            ->where('app.limits.max', ['cpus' => 4, 'memoryMb' => 8192]));
});
