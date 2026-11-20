<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{0: Workspace, 1: Machine, 2: Machine, 3: string} */
function moveSetup(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $from = Machine::factory()->for($workspace)->create(['name' => 'old-box']);
    $from->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => 'v0.1.1-pre.45',
        'facts' => ['arch' => 'x86_64', 'docker_version' => '27.0.1', 'memory_bytes' => 8 << 30],
        'memory_used_mb' => 2048,
        'disks' => [['mount' => '/', 'used' => 30 << 30, 'total' => 80 << 30]],
        'inventory' => ['services' => [
            ['key' => 'docker:web', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'kind' => 'app', 'managed' => true, 'origin' => 'created', 'details' => ['managed' => 'true', 'backup' => 'tar']],
            ['key' => 'docker:pg', 'name' => 'serveros-shopdb-postgres-1', 'kind' => 'database', 'managed' => true, 'origin' => 'created', 'details' => ['managed' => 'true', 'compose_project' => 'serveros-shopdb', 'backup' => 'pg_dumpall']],
            ['key' => 'docker:legacy', 'name' => 'legacy-api', 'kind' => 'app', 'managed' => true, 'origin' => 'adopted', 'details' => ['managed' => 'true']],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web_server', 'details' => []],
        ], 'scheduled' => [
            ['command' => 'curl -H "Authorization: Bearer s3cret-token" https://example.com/ping', 'schedule' => '0 3 * * *', 'source' => '/etc/crontab', 'user' => 'root'],
            ['command' => '/usr/bin/serverosd backfill', 'schedule' => '*/5 * * * *', 'source' => '/etc/cron.d/serveros', 'user' => 'root'],
        ]],
    ])->save();

    $to = Machine::factory()->for($workspace)->create(['name' => 'new-box']);
    $to->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => 'v0.1.1-pre.45',
        'facts' => ['arch' => 'x86_64', 'docker_version' => '27.0.1', 'memory_bytes' => 16 << 30],
        'disks' => [['mount' => '/', 'used' => 5 << 30, 'total' => 160 << 30]],
        'inventory' => ['services' => [], 'listeners' => [['port' => 22, 'address' => '0.0.0.0', 'proto' => 'tcp']]],
    ])->save();

    moveDeploy($from, 'shop', [
        'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40), 'dockerfile' => 'Dockerfile',
        'env' => ['DB_PASSWORD' => 'hunter2-secret', 'APP_URL' => 'https://shop.example.com'],
        'files' => ['.env.production' => 'API_KEY=file-secret'],
        'domains' => ['shop.example.com'], 'port' => 3000,
        'health' => ['path' => '/up', 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => 5],
    ]);
    moveDeploy($from, 'shopdb', [
        'repo' => '', 'commit' => 'stack', 'compose_file' => 'compose.yaml',
        'env' => ['POSTGRES_PASSWORD' => 'pg-secret'], 'port' => 5433,
    ]);

    return [$workspace, $from, $to, 'https://'.$workspace->host().'/machines/'.$from->id];
}

/** @param  array<string, mixed>  $spec */
function moveDeploy(Machine $machine, string $app, array $spec): DaemonJob
{
    return DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'user_id' => $machine->workspace->owner_id,
        'kind' => 'deploy',
        'service' => $app,
        'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => $app, ...$spec]],
    ]);
}

test('the machine file describes what ServerOS deployed without any secret values', function () {
    [$workspace, $from, , $url] = moveSetup();

    $response = $this->actingAs($workspace->owner)->get($url.'/machine-file')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="old-box.serveros.json"');

    $file = $response->json();
    expect($file)->toMatchArray(['kind' => 'serveros-machine', 'version' => 1])
        ->and($file['machine'])->toMatchArray(['name' => 'old-box', 'arch' => 'x86_64', 'daemon_version' => 'v0.1.1-pre.45'])
        ->and(array_column($file['apps'], 'name'))->toBe(['shop', 'shopdb'])
        ->and($file['apps'][0])->toMatchArray([
            'source' => 'git', 'repo' => 'https://github.com/acme/shop', 'env' => ['APP_URL', 'DB_PASSWORD'],
            'files' => ['.env.production'], 'domains' => ['shop.example.com'], 'data' => ['tar'],
            'health' => ['path' => '/up', 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => 5],
        ])
        ->and($file['apps'][0])->not->toHaveKey('services')
        ->and($file['apps'][1])->toMatchArray(['source' => 'stack', 'repo' => null, 'commit' => null, 'port' => 5433, 'data' => ['pg_dumpall']])
        ->and(array_column($file['not_moved'], 'key'))->toBe(['docker:legacy', 'systemd:nginx.service'])
        ->and($file['not_moved'][0]['reason'])->toContain('adopted')
        ->and($file['scheduled'])->toBe([['kind' => 'cron', 'schedule' => '0 3 * * *', 'user' => 'root', 'service' => null]]);

    $raw = $response->getContent();
    foreach (['hunter2-secret', 'file-secret', 'pg-secret', 's3cret-token'] as $secret) {
        expect($raw)->not->toContain($secret);
    }

    $activity = Activity::query()->where('action', 'machine.file')->sole();
    expect($activity->machine_id)->toBe($from->id)
        ->and(ActivityLine::describe($activity, false)['verb'])->toBe('downloaded the machine file of');
});

test('an uploaded app is described without the upload’s secret address', function () {
    [$workspace, $from, , $url] = moveSetup();
    moveDeploy($from, 'shop', ['repo' => 'https://panel.test/api/deploy-sources/'.str_repeat('t', 40).'.git', 'commit' => str_repeat('b', 40)])
        ->forceFill(['created_at' => now()->addMinute()])->save();

    $raw = $this->actingAs($workspace->owner)->get($url.'/machine-file')->assertOk()->getContent();

    expect(json_decode($raw, true)['apps'][0])->toMatchArray(['source' => 'upload', 'repo' => null])
        ->and($raw)->not->toContain(str_repeat('t', 40));
});

test('only people who manage machines can download the file or check a move', function () {
    [$workspace, , $to, $url] = moveSetup();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->get($url.'/machine-file')->assertForbidden();
    $this->actingAs($viewer)->postJson($url.'/move-check', ['to' => $to->id])->assertForbidden();
    expect(Activity::query()->where('action', 'machine.file')->exists())->toBeFalse();
});

test('a move is checked against a machine in the same workspace only', function () {
    [$workspace, $from, , $url] = moveSetup();
    $elsewhere = Machine::factory()->create();

    $this->actingAs($workspace->owner)->postJson($url.'/move-check', ['to' => $from->id])->assertJsonValidationErrors('to');
    $this->actingAs($workspace->owner)->postJson($url.'/move-check', ['to' => $elsewhere->id])->assertJsonValidationErrors('to');
});

test('a move onto a ready machine has no blockers, and says what won’t move', function () {
    [$workspace, , $to, $url] = moveSetup();

    $check = $this->actingAs($workspace->owner)->postJson($url.'/move-check', ['to' => $to->id])->assertOk()->json();

    expect($check['ready'])->toBeTrue()
        ->and($check['blocking'])->toBe([])
        ->and(array_column($check['apps'], 'name'))->toBe(['shop', 'shopdb'])
        ->and(implode("\n", $check['warnings']))
        ->toContain('shop keeps files outside its container')
        ->toContain('Point shop.example.com at new-box only when you switch over')
        ->toContain('2 services on old-box weren’t deployed by ServerOS')
        ->toContain('1 scheduled task on old-box won’t move');

    expect(DaemonJob::query()->where('kind', '!=', 'deploy')->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('name and port clashes, a missing Docker and an offline machine block a move', function () {
    [$workspace, , $to, $url] = moveSetup();
    $to->forceFill([
        'last_seen_at' => now()->subMinutes(10),
        'facts' => ['arch' => 'aarch64'],
        'inventory' => ['services' => [
            ['key' => 'systemd:shop.service', 'name' => 'serveros-shop-cccccccccccc', 'kind' => 'app', 'details' => []],
        ], 'listeners' => [['port' => 5433, 'address' => '127.0.0.1', 'proto' => 'tcp']]],
    ])->save();

    $check = $this->actingAs($workspace->owner)->postJson($url.'/move-check', ['to' => $to->id])->assertOk()->json();

    expect($check['ready'])->toBeFalse()
        ->and(implode("\n", $check['blocking']))
        ->toContain('new-box is offline')
        ->toContain('Docker isn’t installed on new-box')
        ->toContain('Something called shop is already on new-box')
        ->toContain('Port 5433, which shopdb uses, is already taken on new-box')
        ->and(implode("\n", $check['warnings']))->toContain('old-box is x86_64 and new-box is aarch64');
});

test('a move warns about a smaller or older destination and blocks cleared uploads', function () {
    [$workspace, $from, $to, $url] = moveSetup();
    moveDeploy($from, 'shop', ['repo' => 'https://panel.test/api/deploy-sources/'.str_repeat('c', 40).'.git', 'commit' => str_repeat('b', 40)])
        ->forceFill(['created_at' => now()->addMinute()])->save();
    $to->forceFill([
        'daemon_version' => 'v0.1.1-pre.40',
        'facts' => ['arch' => 'x86_64', 'docker_version' => '27.0.1', 'memory_bytes' => 1 << 30],
        'disks' => [['mount' => '/', 'used' => 15 << 30, 'total' => 20 << 30]],
    ])->save();

    $check = $this->actingAs($workspace->owner)->postJson($url.'/move-check', ['to' => $to->id])->assertOk()->json();

    expect(implode("\n", $check['blocking']))->toContain('The code uploaded for shop has been cleared')
        ->and(implode("\n", $check['warnings']))
        ->toContain('new-box runs an older daemon (v0.1.1-pre.40)')
        ->toContain('new-box has less memory than old-box uses now')
        ->toContain('new-box has less free disk than old-box uses now');
});

test('the settings page offers the workspace’s other machines to move to', function () {
    [$workspace, , $to, $url] = moveSetup();
    Machine::factory()->create(['name' => 'not-ours']);

    $this->actingAs($workspace->owner)->get($url.'/settings')
        ->assertInertia(fn (Assert $page) => $page->where('moveTargets', [['id' => $to->id, 'name' => 'new-box']]));
});

test('nothing to move is a blocker', function () {
    [$workspace, $from, $to] = moveSetup();
    $to->forceFill(['inventory' => ['services' => []]])->save();

    $check = $this->actingAs($workspace->owner)
        ->postJson('https://'.$workspace->host().'/machines/'.$to->id.'/move-check', ['to' => $from->id])
        ->assertOk()->json();

    expect($check['ready'])->toBeFalse()
        ->and($check['blocking'])->toContain('Nothing on new-box was deployed by ServerOS, so there’s nothing to move.');
});
