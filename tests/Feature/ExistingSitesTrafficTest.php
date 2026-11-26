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
use App\Support\Traffic\ExistingSites;
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

/** @return array<string, mixed> */
function reportedSite(string $key, array $overrides = []): array
{
    return [
        'site' => $key,
        'server' => str_starts_with($key, 'apache.') ? 'apache' : 'nginx',
        'names' => [substr($key, strpos($key, '.') + 1)],
        'logs' => ['/var/log/nginx/access.log'],
        'timed' => false,
        'shared' => false,
        'copy' => false,
        'configs' => ['/etc/nginx/sites-enabled/default'],
        'upstream_ports' => [],
        ...$overrides,
    ];
}

/** @return array{0: Workspace, 1: Machine, 2: string} */
function webServerMachine(string $daemon = '0.1.1-pre.47', string $proxy = 'none'): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => $daemon,
        'proxy' => $proxy,
        'traffic' => ['enabled' => true, 'previous' => true, 'job' => null, 'sites' => 0],
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web_server', 'manager' => 'systemd', 'status' => 'running', 'ports' => [80, 443], 'details' => []],
            ['key' => 'systemd:apache2.service', 'name' => 'apache2', 'kind' => 'web_server', 'manager' => 'systemd', 'status' => 'running', 'ports' => [8080], 'details' => []],
            ['key' => 'systemd:node-api.service', 'name' => 'node-api', 'kind' => 'app', 'manager' => 'systemd', 'status' => 'running', 'ports' => [3000], 'details' => []],
        ]],
    ])->save();

    return [$workspace, $machine, 'https://'.$workspace->host()];
}

function sendSiteEnvelopes(Machine $machine, array $payload): void
{
    test()->withHeaders(['X-Gateway-Secret' => 'test-gateway-secret'])
        ->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [['v' => 1, 'kind' => 'telemetry', 'payload' => ['samples' => [], ...$payload]]]])
        ->assertOk();
}

function siteMinute(string $site, int $minutesAgo, array $overrides = []): array
{
    return [
        'site' => $site, 'minute' => intdiv(now()->getTimestamp(), 60) * 60 - $minutesAgo * 60,
        'requests' => 200, 'status_2xx' => 180, 'status_3xx' => 0, 'status_4xx' => 10, 'status_5xx' => 10,
        'bytes' => 5000, 'p50_ms' => null, 'p95_ms' => null, 'visitors' => 40,
        'paths' => [['/', 120]], 'referrers' => [],
        ...$overrides,
    ];
}

function withSites(Machine $machine): void
{
    sendSiteEnvelopes($machine, ['traffic_sites' => [
        reportedSite('nginx.shop.example.com', ['names' => ['shop.example.com', 'www.shop.example.com']]),
        reportedSite('nginx.log.access.log-1a2b3c', ['names' => ['blog.example.com', 'docs.example.com'], 'shared' => true]),
        reportedSite('nginx.api.example.com', ['names' => ['api.example.com'], 'logs' => ['/var/log/nginx/api.log'], 'timed' => true, 'upstream_ports' => [3000]]),
        reportedSite('apache.legacy.example.com', ['logs' => ['/var/log/apache2/access.log']]),
    ]]);
}

test('telemetry keeps the reported list of web server sites, cleaned, and their minutes under their own keys', function () {
    [, $machine] = webServerMachine();

    sendSiteEnvelopes($machine, [
        'traffic' => [siteMinute('nginx.shop.example.com', 1), siteMinute('shop', 1)],
        'traffic_sites' => [
            reportedSite('nginx.shop.example.com', ['names' => ['shop.example.com', 42], 'upstream_ports' => [3000, 99999, 'x']]),
            reportedSite('shop'),
            reportedSite('caddy.shop.example.com', ['server' => 'caddy']),
            'nonsense',
        ],
    ]);

    $machine->refresh();
    $empty = ['container' => null, 'apps' => [], 'catchAll' => false, 'roots' => [], 'sockets' => [], 'unlogged' => null];
    expect(ExistingSites::all($machine))->toBe([[
        'site' => 'nginx.shop.example.com', 'server' => 'nginx', 'names' => ['shop.example.com'],
        'logs' => ['/var/log/nginx/access.log'], 'timed' => false, 'shared' => false, 'copy' => false,
        'configs' => ['/etc/nginx/sites-enabled/default'], 'ports' => [3000],
        // Daemons before Caddy and containers only ever changed host nginx and Apache.
        ...$empty, 'editable' => true, 'manual' => false,
    ], [
        'site' => 'caddy.shop.example.com', 'server' => 'caddy', 'names' => ['shop.example.com'],
        'logs' => ['/var/log/nginx/access.log'], 'timed' => false, 'shared' => false, 'copy' => false,
        'configs' => ['/etc/nginx/sites-enabled/default'], 'ports' => [],
        ...$empty, 'editable' => false, 'manual' => false,
    ]])
        ->and(TrafficRollup::query()->orderBy('site')->pluck('site')->all())->toBe(['nginx.shop.example.com', 'shop']);

    // A batch without the list leaves it be; an empty list empties it.
    sendSiteEnvelopes($machine, ['traffic' => [siteMinute('shop', 2)]]);
    expect(ExistingSites::all($machine->fresh()))->toHaveCount(2);
    sendSiteEnvelopes($machine, ['traffic_sites' => []]);
    expect(ExistingSites::all($machine->fresh()))->toBe([]);
});

test('a web server’s page lists its sites with their last day, and each one opens its own report', function () {
    [$workspace, $machine, $base] = webServerMachine();
    withSites($machine);
    sendSiteEnvelopes($machine, ['traffic' => [siteMinute('nginx.shop.example.com', 1), siteMinute('nginx.shop.example.com', 2)]]);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->get($base."/machines/{$machine->id}/services/systemd:nginx.service/traffic")
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('siteTraffic.server', 'nginx')
            ->where('siteTraffic.collecting', true)
            ->has('siteTraffic.sites', 3)
            ->where('siteTraffic.sites.0.site', 'nginx.shop.example.com')
            ->where('siteTraffic.sites.0.label', 'shop.example.com')
            ->where('siteTraffic.sites.0.lastDay', ['requests' => 400, 'errors' => 20, 'clientErrors' => 20, 'visitors' => 80])
            ->where('siteTraffic.sites.1.label', 'access.log (2 sites)')
            ->where('siteTraffic.sites.1.existing.shared', true)
            ->where('siteTraffic.sites.2.label', 'api.example.com'));

    $this->actingAs($viewer)->get($base."/machines/{$machine->id}/services/systemd:apache2.service")
        ->assertInertia(fn ($page) => $page->where('siteTraffic.server', 'Apache')->has('siteTraffic.sites', 1));
    // An app the user's nginx passes requests to shows that site.
    $this->actingAs($viewer)->get($base."/machines/{$machine->id}/services/systemd:node-api.service")
        ->assertInertia(fn ($page) => $page->where('siteTraffic.server', null)->where('siteTraffic.sites.0.site', 'nginx.api.example.com'));

    $this->actingAs($viewer)->getJson($base."/machines/{$machine->id}/sites/nginx.shop.example.com/traffic?range=24h")
        ->assertOk()
        ->assertJsonPath('existing.server', 'nginx')
        ->assertJsonPath('existing.timed', false)
        ->assertJsonPath('domain', 'shop.example.com')
        ->assertJsonPath('summary.requests', 400)
        ->assertJsonPath('summary.p95', null);
    $this->actingAs($viewer)->getJson($base."/machines/{$machine->id}/sites/nginx.nope.example.com/traffic")->assertNotFound();

    $other = Workspace::factory()->create();
    $this->actingAs($other->owner)->getJson('https://'.$other->host()."/machines/{$machine->id}/sites/nginx.shop.example.com/traffic")->assertNotFound();
});

test('machines whose daemon doesn’t count web server sites say so on the tab and show no numbers', function () {
    [$workspace, $machine, $base] = webServerMachine();
    withSites($machine);
    $machine->forceFill(['daemon_version' => '0.1.1-pre.46', 'proxy' => 'caddy'])->save();

    $this->actingAs($workspace->owner)->get($base."/machines/{$machine->id}/services/systemd:nginx.service")
        ->assertInertia(fn ($page) => $page->where('siteTraffic.role', 'server')
            ->where('siteTraffic.server', 'nginx')
            ->where('siteTraffic.blocked', null)
            ->where('siteTraffic.update', 'Update the ServerOS daemon on web-1 to v0.1.1-pre.47 or later to count this service’s traffic.')
            ->where('siteTraffic.sites', []));
    $this->actingAs($workspace->owner)->getJson($base."/machines/{$machine->id}/sites/nginx.shop.example.com/traffic")->assertNotFound();
    expect(TrafficSwitch::props($machine)['existing'])->toBeNull();
});

test('the machine’s traffic table lists the apps and the web server’s sites with their last day', function () {
    [$workspace, $machine, $base] = webServerMachine(proxy: 'caddy');
    withSites($machine);
    $inventory = $machine->inventory;
    $inventory['services'][] = ['key' => 'docker:abc', 'name' => 'serveros-shop-bbbbbbbbbbbb', 'kind' => 'app', 'status' => 'running', 'details' => ['managed' => 'true']];
    $machine->forceFill(['inventory' => $inventory])->save();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('1', 40), 'port' => 3000, 'domains' => ['shop.acme.io']]],
    ]);
    sendSiteEnvelopes($machine, ['traffic' => [siteMinute('shop', 1, ['requests' => 900]), siteMinute('nginx.api.example.com', 1)]]);

    $sites = $this->actingAs($workspace->owner)->getJson($base."/machines/{$machine->id}/traffic/sites")
        ->assertOk()
        ->assertJsonPath('collecting', true)
        ->assertJsonPath('existing', true)
        ->json('sites');

    expect(array_column($sites, 'site'))->toBe(['shop', 'nginx.api.example.com', 'nginx.log.access.log-1a2b3c', 'apache.legacy.example.com', 'nginx.shop.example.com'])
        ->and($sites[0])->toMatchArray(['kind' => 'app', 'name' => 'shop', 'server' => 'ServerOS', 'names' => ['shop.acme.io'], 'requests' => 900, 'url' => "/machines/{$machine->id}/services/app:shop/traffic"])
        ->and($sites[1])->toMatchArray(['kind' => 'existing', 'server' => 'nginx', 'requests' => 200, 'errors' => 10, 'timed' => true, 'url' => "/machines/{$machine->id}/services/systemd:nginx.service/traffic?site=nginx.api.example.com"])
        ->and($sites[3]['url'])->toBe("/machines/{$machine->id}/services/systemd:apache2.service/traffic?site=apache.legacy.example.com");
});

test('response times for a site are asked for first, then added, by someone who manages machines', function () {
    [$workspace, $machine, $base] = webServerMachine();
    withSites($machine);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $url = $base."/machines/{$machine->id}/sites/nginx.shop.example.com/timing";

    $this->actingAs($viewer)->postJson($url, ['apply' => false])->assertForbidden();

    $jobId = $this->actingAs($workspace->owner)->postJson($url, ['apply' => false])->assertStatus(202)->json('jobId');
    $job = DaemonJob::query()->findOrFail($jobId);
    expect($job->kind)->toBe('traffic_timing')
        ->and($job->command['job'])->toBe(['type' => 'traffic_timing', 'site' => 'nginx.shop.example.com', 'apply' => false])
        ->and(Activity::query()->where('action', 'machine.traffic_timing')->count())->toBe(0);

    $lines = ['# Added by ServerOS: a copy of this site’s access log with response times.', 'access_log /var/log/serveros-traffic/existing/nginx.shop.example.com.log serveros_timed;'];
    sendEnvelopesForJob($machine, $jobId, 'succeeded', ['site' => 'nginx.shop.example.com', 'applied' => false, 'changes' => [['file' => '/etc/nginx/sites-enabled/shop', 'new' => false, 'at' => 'server block 1 (shop.example.com), before its closing line', 'lines' => $lines]], 'check' => 'nginx -t', 'reload' => 'nginx -s reload']);
    $this->actingAs($viewer)->getJson("{$url}/{$jobId}")
        ->assertOk()
        ->assertJsonPath('state', 'succeeded')
        ->assertJsonPath('result.changes.0.lines', $lines);
    $this->actingAs($viewer)->getJson($base."/machines/{$machine->id}/sites/nginx.api.example.com/timing/{$jobId}")->assertNotFound();

    $applied = $this->actingAs($workspace->owner)->postJson($url, ['apply' => true])->assertStatus(202)->json('jobId');
    expect(DaemonJob::query()->findOrFail($applied)->command['job']['apply'])->toBeTrue()
        ->and(ActivityLine::describe(Activity::query()->where('action', 'machine.traffic_timing')->sole(), false)['verb'])
        ->toBe('added response times to shop.example.com’s access log on');

    // A log that has times already needs none.
    $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/sites/nginx.api.example.com/timing", ['apply' => false])
        ->assertStatus(409);
    $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/sites/nginx.nope.example.com/timing", ['apply' => false])
        ->assertNotFound();
});

function sendEnvelopesForJob(Machine $machine, string $jobId, string $state, ?array $result = null): void
{
    test()->withHeaders(['X-Gateway-Secret' => 'test-gateway-secret'])
        ->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => array_filter(['job_id' => $jobId, 'state' => $state, 'result' => $result])]]])
        ->assertOk();
}

test('with a daemon that counts web server sites, traffic can be turned on with no proxy, and the count comes back', function () {
    [$workspace, $machine, $base] = webServerMachine(proxy: 'none');
    $machine->forceFill(['traffic' => null])->save();

    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/traffic", ['enabled' => true])->assertStatus(202);
    $job = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'traffic')->sole();
    sendEnvelopesForJob($machine, $job->id, 'succeeded', ['enabled' => true, 'sites' => 0, 'existing' => 4]);

    expect(TrafficSwitch::props($machine->fresh()))->toMatchArray(['enabled' => true, 'blocked' => null, 'sites' => 0, 'existing' => 4]);

    $machine->forceFill(['daemon_version' => '0.1.1-pre.46'])->save();
    expect(TrafficSwitch::blocked($machine))->toBe('No proxy serves sites on web-1, so there are no visits to count.');
});

test('list_site_traffic and get_site_traffic read the web server’s sites, and so does the API', function () {
    [$workspace, $machine] = webServerMachine();
    withSites($machine);
    sendSiteEnvelopes($machine, ['traffic' => [siteMinute('nginx.shop.example.com', 1)]]);
    $key = mcpKey($workspace, scopes: [Scopes::READ]);

    $list = mcpCall($workspace, $key, 'list_site_traffic', ['machine' => 'web-1']);
    expect($list['error'])->toBeFalse($list['text'])
        ->and($list['data']['countsWebServerSites'])->toBeTrue()
        ->and(collect($list['data']['sites'])->firstWhere('site', 'nginx.shop.example.com'))->toMatchArray(['kind' => 'existing', 'requests' => 200, 'timed' => false]);

    $one = mcpCall($workspace, $key, 'get_site_traffic', ['machine' => 'web-1', 'site' => 'nginx.shop.example.com']);
    expect($one['error'])->toBeFalse($one['text'])
        ->and($one['data']['existing']['timed'])->toBeFalse()
        ->and($one['data']['summary']['requests'])->toBe(200)
        ->and($one['data']['pageUrl'])->toContain('/services/systemd:nginx.service/traffic?site=nginx.shop.example.com');

    $missing = mcpCall($workspace, $key, 'get_site_traffic', ['machine' => 'web-1', 'site' => 'nginx.nope.test']);
    expect($missing['error'])->toBeTrue()->and($missing['text'])->toContain('list_site_traffic');
    expect(mcpCall($workspace, $key, 'get_site_traffic', ['machine' => 'web-1'])['error'])->toBeTrue();

    [, $secret] = McpToken::issueKey($workspace, $workspace->owner, 'CI', [Scopes::READ], McpToken::API);
    $this->withHeader('Authorization', "Bearer {$secret}")->getJson("/api/v1/machines/{$machine->id}/sites/nginx.shop.example.com/traffic?range=7d")
        ->assertOk()
        ->assertJsonPath('data.range', '7d')
        ->assertJsonPath('data.existing.server', 'nginx');
    $this->withHeader('Authorization', "Bearer {$secret}")->getJson("/api/v1/machines/{$machine->id}/traffic")
        ->assertOk()
        ->assertJsonPath('data.collecting', true);
});
