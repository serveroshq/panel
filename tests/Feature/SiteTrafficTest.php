<?php

use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\McpToken;
use App\Models\TrafficRollup;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Traffic\SiteTraffic;
use App\Support\Traffic\TrafficSwitch;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $this->withoutDefer();
    $this->travelTo(Carbon::parse('2026-11-20 12:30:20', 'UTC'));
});

/** @return array{0: Workspace, 1: Machine, 2: string} */
function trafficApp(string $app = 'shop', array $domains = ['shop.example.com'], string $daemon = '0.1.1-pre.46'): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $daemon, 'proxy' => 'caddy'])->save();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'user_id' => $workspace->owner_id,
        'kind' => 'deploy',
        'service' => $app,
        'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => $app, 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('1', 40), 'port' => 3000, 'domains' => $domains]],
    ]);

    return [$workspace, $machine, 'https://'.$workspace->host()];
}

function sendEnvelopes(Machine $machine, array $envelopes): void
{
    test()->withHeaders(['X-Gateway-Secret' => 'test-gateway-secret'])
        ->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => $envelopes])
        ->assertOk();
}

function sendTraffic(Machine $machine, array $rollups): void
{
    sendEnvelopes($machine, [['v' => 1, 'kind' => 'telemetry', 'payload' => ['samples' => [], 'traffic' => $rollups]]]);
}

function minuteAgo(int $minutes): int
{
    return intdiv(now()->getTimestamp(), 60) * 60 - $minutes * 60;
}

/** @return array<string, mixed> */
function rollupOf(string $site, int $minute, array $overrides = []): array
{
    return [
        'site' => $site, 'minute' => $minute, 'requests' => 120,
        'status_2xx' => 100, 'status_3xx' => 10, 'status_4xx' => 8, 'status_5xx' => 2,
        'bytes' => 123456, 'p50_ms' => 12.5, 'p95_ms' => 80.0, 'visitors' => 30,
        'paths' => [['/', 50], ['/pricing', 20]],
        'referrers' => [['google.com', 5], ['news.ycombinator.com', 2]],
        ...$overrides,
    ];
}

function trafficJobs(Machine $machine)
{
    return DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'traffic')->orderBy('created_at')->get();
}

function finishTrafficJob(Machine $machine, string $state, ?array $result = null, ?array $error = null): void
{
    sendEnvelopes($machine, [['v' => 1, 'kind' => 'job_update', 'payload' => array_filter([
        'job_id' => trafficJobs($machine)->last()->id, 'state' => $state, 'result' => $result, 'error' => $error,
    ])]]);
}

test('telemetry stores each site’s minutes in one go, and a minute sent again replaces it', function () {
    [, $machine] = trafficApp();

    sendTraffic($machine, [rollupOf('shop', minuteAgo(2)), rollupOf('shop', minuteAgo(1)), rollupOf('blog', minuteAgo(1))]);
    sendTraffic($machine, [rollupOf('shop', minuteAgo(1), ['requests' => 7, 'status_2xx' => 7, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0])]);

    $rows = TrafficRollup::query()->where('site', 'shop')->orderBy('bucket')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('requests')->all())->toBe([120, 7])
        ->and($rows[0]->seconds)->toBe(60)
        ->and(TrafficRollup::decode($rows[0]->paths))->toBe([['/', 50], ['/pricing', 20]])
        ->and(TrafficRollup::decode($rows[0]->referrers))->toBe([['google.com', 5], ['news.ycombinator.com', 2]])
        ->and(TrafficRollup::query()->where('site', 'blog')->count())->toBe(1);
});

test('rollups that don’t make sense are dropped or clamped, and older daemons send none', function () {
    [, $machine] = trafficApp();

    sendTraffic($machine, [
        rollupOf('shop', minuteAgo(1) + 30),
        rollupOf('shop', minuteAgo(-10)),
        rollupOf('shop', minuteAgo(48 * 60)),
        rollupOf('', minuteAgo(1)),
        'nonsense',
        ['site' => 'shop', 'minute' => (string) minuteAgo(1)],
        rollupOf('my app!', minuteAgo(3), ['requests' => -5, 'p50_ms' => null, 'p95_ms' => 'slow', 'paths' => [['/'.str_repeat('a', 900), 3], ['', 9], ['/x', -1], 'bad'], 'referrers' => 'nope']),
    ]);
    sendEnvelopes($machine, [['v' => 1, 'kind' => 'telemetry', 'payload' => ['samples' => [['cpu_percent' => 5]]]]]);

    $row = TrafficRollup::query()->sole();
    expect($row->site)->toBe('my-app-')
        ->and($row->requests)->toBe(0)
        ->and($row->p50_ms)->toBeNull()
        ->and($row->p95_ms)->toBeNull()
        ->and($row->referrers)->toBeNull()
        ->and(TrafficRollup::decode($row->paths))->toHaveCount(1)
        ->and(strlen(TrafficRollup::decode($row->paths)[0][0]))->toBe(500)
        ->and($machine->fresh()->cpu_percent)->toBe(5);
});

test('minutes roll into hours: counters add up, response times are weighted by requests and the top lists merge', function () {
    [, $machine] = trafficApp();
    $hour = intdiv(now()->getTimestamp(), 3600) * 3600 - 3600;

    sendTraffic($machine, [
        rollupOf('shop', $hour, ['requests' => 100, 'p95_ms' => 100.0, 'paths' => [['/', 60], ['/a', 40]]]),
        rollupOf('shop', $hour + 60, ['requests' => 300, 'p95_ms' => 20.0, 'paths' => [['/a', 200], ['/b', 100]]]),
    ]);
    $this->artisan('traffic:roll-up')->assertSuccessful();

    $hourly = TrafficRollup::query()->where('seconds', 3600)->sole();
    expect($hourly->bucket)->toBe($hour)
        ->and($hourly->requests)->toBe(400)
        ->and($hourly->status_5xx)->toBe(4)
        ->and($hourly->visitors)->toBe(60)
        ->and($hourly->p95_ms)->toBe(40.0)
        ->and(TrafficRollup::decode($hourly->paths))->toBe([['/a', 240], ['/b', 100], ['/', 60]])
        ->and(TrafficRollup::query()->where('rolled', false)->count())->toBe(0);

    // A late minute: the hour is worked out again, not added to twice.
    sendTraffic($machine, [rollupOf('shop', $hour + 120, ['requests' => 100])]);
    TrafficRollup::rollUp();
    TrafficRollup::rollUp();
    expect(TrafficRollup::query()->where('seconds', 3600)->sole()->requests)->toBe(500);
});

test('minutes go after two days, hours after 90 days, and everything goes with the machine', function () {
    [, $machine] = trafficApp();
    $now = now()->getTimestamp();
    $row = fn (int $bucket, int $seconds) => ['machine_id' => $machine->id, 'site' => 'shop', 'bucket' => $bucket, 'seconds' => $seconds, 'requests' => 1, 'rolled' => true];
    TrafficRollup::query()->insert([
        $row(intdiv($now, 60) * 60 - 3 * 86400, 60),
        $row(intdiv($now, 60) * 60 - 3600, 60),
        $row(intdiv($now, 3600) * 3600 - 91 * 86400, 3600),
        $row(intdiv($now, 3600) * 3600 - 60 * 86400, 3600),
    ]);

    $this->artisan('logs:prune')->assertSuccessful();
    expect(TrafficRollup::query()->count())->toBe(2);

    $machine->delete();
    expect(TrafficRollup::query()->count())->toBe(0);
});

test('the report adds up a range, with points, top pages and referrers', function () {
    [, $machine] = trafficApp('my.shop');
    $minute = minuteAgo(5);
    sendTraffic($machine, [
        rollupOf('my-shop', $minute, ['requests' => 100, 'status_2xx' => 90, 'status_3xx' => 0, 'status_4xx' => 5, 'status_5xx' => 5, 'p95_ms' => 50.0, 'visitors' => 10]),
        rollupOf('my-shop', $minute - 3600, ['requests' => 300, 'status_2xx' => 300, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0, 'p95_ms' => 10.0, 'visitors' => 20, 'paths' => [['/', 150]]]),
        rollupOf('other', $minute, ['requests' => 999]),
    ]);

    $report = SiteTraffic::report($machine, 'my.shop', '24h');
    expect($report['hasData'])->toBeTrue()
        ->and($report['step'])->toBe(900)
        ->and($report['series'])->toHaveCount(96)
        ->and($report['series'][0]['requests'])->toBeNull()
        ->and($report['series'][94]['requests'])->toBe(100)
        ->and($report['series'][95]['requests'])->toBe(0)
        ->and($report['summary'])->toMatchArray(['requests' => 400, 'bytes' => 246912, 'visitors' => 30, 'p95' => 20.0, 'errorRate' => 0.0125])
        ->and($report['summary']['status'])->toBe(['2xx' => 390, '3xx' => 0, '4xx' => 5, '5xx' => 5])
        ->and($report['paths'][0])->toBe(['name' => '/', 'requests' => 200, 'share' => 0.5])
        ->and($report['referrers'][0]['name'])->toBe('google.com');

    // Longer ranges read the hours.
    expect(SiteTraffic::report($machine, 'my.shop', '7d')['hasData'])->toBeFalse();
    TrafficRollup::rollUp();
    $week = SiteTraffic::report($machine, 'my.shop', '7d');
    expect($week['step'])->toBe(3600)
        ->and($week['summary']['requests'])->toBe(400)
        ->and(SiteTraffic::report($machine, 'my.shop', '30d')['step'])->toBe(21600);
});

test('the Traffic tab reads its numbers from its own endpoint, for anyone in the workspace', function () {
    [$workspace, $machine, $base] = trafficApp();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    sendTraffic($machine, [rollupOf('shop', minuteAgo(1))]);

    $this->actingAs($viewer)->getJson($base."/machines/{$machine->id}/apps/shop/traffic?range=24h")
        ->assertOk()
        ->assertJsonPath('collecting', false)
        ->assertJsonPath('domain', 'shop.example.com')
        ->assertJsonPath('summary.requests', 120);
    $this->actingAs($viewer)->getJson($base."/machines/{$machine->id}/apps/nope/traffic")->assertNotFound();

    $other = Workspace::factory()->create();
    $this->actingAs($other->owner)->getJson('https://'.$other->host()."/machines/{$machine->id}/apps/shop/traffic")->assertNotFound();
});

test('the app page and machine settings say whether traffic is counted', function () {
    [$workspace, $machine, $base] = trafficApp('shop', []);
    $machine->forceFill(['inventory' => ['services' => [
        ['key' => 'docker:new', 'name' => 'serveros-shop-bbbbbbbbbbbb', 'kind' => 'app', 'status' => 'running', 'details' => ['managed' => 'true']],
    ]]])->save();

    $this->actingAs($workspace->owner)->get($base."/machines/{$machine->id}/services/app:shop/traffic")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('app.traffic', ['site' => 'shop', 'domain' => null, 'collecting' => false, 'blocked' => null]));

    $machine->forceFill(['daemon_version' => '0.1.1-pre.40'])->save();
    $this->actingAs($workspace->owner)->get($base."/machines/{$machine->id}/settings")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('traffic.enabled', false)
            ->where('traffic.blocked', 'Update the ServerOS daemon on web-1 to v0.1.1-pre.46 or later to count traffic.'));
});

test('turning traffic on sends the machine a job, and its answer is kept', function () {
    [$workspace, $machine, $base] = trafficApp();

    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/traffic", ['enabled' => true])
        ->assertStatus(202)
        ->assertJsonPath('traffic.enabled', true)
        ->assertJsonPath('traffic.changing', trafficJobs($machine)->sole()->id);

    expect(trafficJobs($machine)->sole()->command['job'])->toBe(['type' => 'traffic', 'enabled' => true])
        ->and(ActivityLine::describe(Activity::query()->where('action', 'machine.traffic')->sole(), false)['verb'])->toBe('started counting site traffic on');

    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/traffic", ['enabled' => false])
        ->assertStatus(409)->assertJsonPath('error', 'The last change is still being applied. Try again in a moment.');

    finishTrafficJob($machine, 'succeeded', ['enabled' => true, 'sites' => 3]);
    expect(TrafficSwitch::props($machine->fresh()))->toMatchArray(['enabled' => true, 'sites' => 3, 'changing' => null, 'error' => null])
        ->and(SiteTraffic::state($machine->fresh(), 'shop', ['shop.example.com'])['collecting'])->toBeTrue();
});

test('a change the machine refuses goes back, and says why', function () {
    [$workspace, $machine, $base] = trafficApp();

    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/traffic", ['enabled' => true])->assertStatus(202);
    finishTrafficJob($machine, 'failed', null, ['message' => 'Couldn’t read the Caddy access log.']);

    expect(TrafficSwitch::props($machine->fresh()))->toMatchArray(['enabled' => false, 'error' => 'Couldn’t read the Caddy access log.']);
});

test('traffic can’t be turned on with an old daemon, without a proxy, or by a viewer', function () {
    [$workspace, $machine, $base] = trafficApp(daemon: '0.1.1-pre.45');
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/traffic", ['enabled' => true])
        ->assertStatus(409)->assertJsonPath('error', 'Update the ServerOS daemon on web-1 to v0.1.1-pre.46 or later to count traffic.');

    $machine->forceFill(['daemon_version' => '0.1.1-pre.46', 'proxy' => 'none'])->save();
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/traffic", ['enabled' => true])
        ->assertStatus(409)->assertJsonPath('error', 'No proxy serves sites on web-1, so there are no visits to count.');

    $machine->forceFill(['proxy' => 'traefik'])->save();
    $this->actingAs($viewer)->putJson($base."/machines/{$machine->id}/traffic", ['enabled' => true])->assertForbidden();
    expect(trafficJobs($machine))->toHaveCount(0);
});

test('get_site_traffic and the API show the same numbers as the tab', function () {
    [$workspace, $machine] = trafficApp();
    $machine->forceFill(['traffic' => ['enabled' => true, 'previous' => true, 'job' => null, 'sites' => 1]])->save();
    sendTraffic($machine, [rollupOf('shop', minuteAgo(1))]);

    $result = mcpCall($workspace, mcpKey($workspace, scopes: [Scopes::READ]), 'get_site_traffic', ['machine' => 'web-1', 'app' => 'shop', 'range' => '24h']);
    expect($result['error'])->toBeFalse($result['text'])
        ->and($result['data'])->toMatchArray(['app' => 'shop', 'collecting' => true, 'range' => '24h', 'note' => null])
        ->and($result['data']['summary']['requests'])->toBe(120)
        ->and($result['data']['paths'][0]['name'])->toBe('/');

    $bad = mcpCall($workspace, mcpKey($workspace), 'get_site_traffic', ['machine' => 'web-1', 'app' => 'shop', 'range' => '1y']);
    expect($bad['error'])->toBeTrue();

    [, $secret] = McpToken::issueKey($workspace, $workspace->owner, 'CI', [Scopes::READ], McpToken::API);
    $this->withHeader('Authorization', "Bearer {$secret}")->getJson("/api/v1/machines/{$machine->id}/apps/shop/traffic?range=7d")
        ->assertOk()
        ->assertJsonPath('data.range', '7d')
        ->assertJsonPath('data.step', 3600);
});
