<?php

use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Traffic\ExistingSites;
use App\Support\Traffic\SiteTraffic;
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

/**
 * A machine shaped like production: Caddy with a site per name, a
 * catch-all and a site with no log; Laravel's queue and SSR as systemd
 * services in the app's folder; php-fpm behind a socket; and containers
 * (Traefik with routers on labels, an app it sends to, nginx).
 *
 * @return array{0: Workspace, 1: Machine, 2: string}
 */
function anySiteMachine(string $daemon = '0.1.1-pre.48', bool $collecting = true): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $service = fn (string $key, string $name, string $kind, array $extra = []) => ['key' => $key, 'name' => $name, 'kind' => $kind, 'manager' => str_starts_with($key, 'docker:') ? 'docker' : 'systemd', 'status' => 'running', 'ports' => [], 'details' => [], ...$extra];
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => $daemon,
        'proxy' => 'caddy',
        'traffic' => ['enabled' => $collecting, 'previous' => $collecting, 'job' => null, 'sites' => 0],
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            $service('systemd:caddy.service', 'caddy', 'web_server', ['ports' => [80, 443]]),
            $service('systemd:serveros-queue.service', 'serveros-queue', 'app', ['working_dir' => '/var/www/serveros']),
            $service('systemd:serveros-ssr.service', 'serveros-ssr', 'app', ['working_dir' => '/var/www/serveros/', 'ports' => [13714]]),
            $service('systemd:php8.4-fpm.service', 'php8.4-fpm', 'runtime'),
            $service('systemd:other.service', 'other', 'app', ['working_dir' => '/var/www2', 'ports' => [4000]]),
            $service('systemd:postgresql.service', 'postgresql', 'database', ['ports' => [5432]]),
            $service('docker:aaaaaaaaaaaa', 'coolify-proxy', 'container', ['ports' => [80, 443], 'details' => ['image' => 'traefik:v3.1']]),
            $service('docker:bbbbbbbbbbbb', 'shop-app', 'container', ['ports' => [3000]]),
            $service('docker:cccccccccccc', 'web', 'container', ['ports' => [8080], 'details' => ['image' => 'nginx:alpine']]),
        ]],
        'traffic_sites' => ExistingSites::stored([
            ['site' => 'caddy.any', 'server' => 'caddy', 'names' => [], 'logs' => ['/var/log/caddy/access.log'], 'timed' => true, 'configs' => ['/etc/caddy/Caddyfile'], 'catch_all' => true, 'roots' => ['/var/www/serveros/public'], 'sockets' => ['/run/php/php8.4-fpm.sock'], 'editable' => true],
            ['site' => 'caddy.gateway.serveros.com', 'server' => 'caddy', 'names' => ['gateway.serveros.com'], 'logs' => ['/var/log/caddy/access.log'], 'timed' => true, 'configs' => ['/etc/caddy/Caddyfile'], 'roots' => ['/var/www/serveros/public'], 'sockets' => ['/run/php/php8.4-fpm-daemon.sock'], 'editable' => true],
            ['site' => 'caddy.releases.serveros.com', 'server' => 'caddy', 'names' => ['releases.serveros.com'], 'logs' => ['/var/log/caddy/access.log'], 'timed' => true, 'configs' => ['/etc/caddy/Caddyfile'], 'roots' => ['/var/www/serveros-releases/shared/public/releases/daemon'], 'editable' => true],
            ['site' => 'caddy.quiet.test', 'server' => 'caddy', 'names' => ['quiet.test'], 'configs' => ['/etc/caddy/Caddyfile'], 'unlogged' => 'off', 'editable' => true],
            ['site' => 'traefik-coolify-proxy.shop.test', 'server' => 'traefik', 'names' => ['shop.test'], 'logs' => ['/dev/stdout'], 'timed' => true, 'configs' => ['its command line'], 'container' => 'coolify-proxy', 'upstream_containers' => ['shop-app']],
            ['site' => 'nginx-web.static.test', 'server' => 'nginx', 'names' => ['static.test'], 'logs' => ['/var/log/nginx/access.log'], 'configs' => ['/etc/nginx/conf.d/default.conf'], 'container' => 'web', 'roots' => ['/var/www/serveros/public']],
            ['site' => 'nginx-web.quiet.test', 'server' => 'nginx', 'names' => ['quiet.test'], 'configs' => ['/etc/nginx/conf.d/default.conf'], 'container' => 'web', 'unlogged' => 'off'],
        ]),
    ])->save();

    return [$workspace, $machine, 'https://'.$workspace->host()];
}

function anySiteMinute(string $site, int $requests): array
{
    return [
        'site' => $site, 'minute' => intdiv(now()->getTimestamp(), 60) * 60 - 60,
        'requests' => $requests, 'status_2xx' => $requests, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0,
        'bytes' => 100, 'p50_ms' => 10.0, 'p95_ms' => 40.0, 'visitors' => 1, 'paths' => [['/', $requests]], 'referrers' => [],
    ];
}

function sendAnySiteTraffic(Machine $machine, array $rollups): void
{
    test()->withHeaders(['X-Gateway-Secret' => 'test-gateway-secret'])
        ->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [['v' => 1, 'kind' => 'telemetry', 'payload' => ['samples' => [], 'traffic' => $rollups]]]])
        ->assertOk();
}

test('the reported sites keep what ties them to services, and junk is dropped', function () {
    [, $machine] = anySiteMachine();
    $sites = collect(ExistingSites::all($machine))->keyBy('site');

    expect($sites)->toHaveCount(7)
        ->and($sites['caddy.any'])->toMatchArray(['catchAll' => true, 'roots' => ['/var/www/serveros/public'], 'sockets' => ['/run/php/php8.4-fpm.sock'], 'editable' => true, 'unlogged' => null])
        ->and($sites['caddy.quiet.test'])->toMatchArray(['unlogged' => 'off', 'logs' => []])
        ->and($sites['traefik-coolify-proxy.shop.test'])->toMatchArray(['container' => 'coolify-proxy', 'apps' => ['shop-app'], 'editable' => false])
        ->and(ExistingSites::label($sites['caddy.any']))->toBe('All other hosts');

    $cleaned = ExistingSites::stored([
        ['site' => 'caddy.x.test', 'server' => 'nginx'],
        ['site' => 'traefik-Bad Name.x', 'server' => 'traefik'],
        ['site' => 'log.api', 'server' => 'log', 'names' => ['api'], 'logs' => ['/srv/api/access.log'], 'manual' => true],
        ['site' => 'caddy.y.test', 'server' => 'caddy', 'container' => '../etc', 'unlogged' => 'maybe'],
    ])['sites'];
    expect(array_column($cleaned, 'site'))->toBe(['log.api', 'caddy.y.test'])
        ->and($cleaned[1])->toMatchArray(['container' => null, 'unlogged' => null]);
});

test('a Laravel app’s systemd services show the Caddy sites whose root is in their folder', function () {
    [$workspace, $machine, $base] = anySiteMachine();
    sendAnySiteTraffic($machine, [anySiteMinute('caddy.any', 500), anySiteMinute('caddy.gateway.serveros.com', 50)]);
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    foreach (['systemd:serveros-queue.service', 'systemd:serveros-ssr.service'] as $key) {
        $this->actingAs($viewer)->get($base."/machines/{$machine->id}/services/{$key}/traffic")
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('siteTraffic.role', 'app')
                ->where('siteTraffic.server', null)
                ->where('siteTraffic.update', null)
                ->has('siteTraffic.sites', 2)
                ->where('siteTraffic.sites.0.site', 'caddy.any')
                ->where('siteTraffic.sites.0.label', 'All other hosts')
                ->where('siteTraffic.sites.0.where', 'Caddy')
                ->where('siteTraffic.sites.0.domain', null)
                ->where('siteTraffic.sites.0.lastDay.requests', 500)
                ->where('siteTraffic.sites.1.site', 'caddy.gateway.serveros.com'));
    }

    // /var/www must not match /var/www2: that one has nothing in front of it.
    $this->actingAs($viewer)->get($base."/machines/{$machine->id}/services/systemd:other.service/traffic")
        ->assertInertia(fn ($page) => $page->where('siteTraffic.role', 'app')
            ->where('siteTraffic.sites', [])
            ->where('siteTraffic.ports', [4000])
            ->where('siteTraffic.canAddFile', true));

    // php-fpm serves the sockets both sites pass PHP to.
    $this->actingAs($viewer)->get($base."/machines/{$machine->id}/services/systemd:php8.4-fpm.service/traffic")
        ->assertInertia(fn ($page) => $page->has('siteTraffic.sites', 2));

    // Databases get no Traffic tab.
    $this->actingAs($viewer)->get($base."/machines/{$machine->id}/services/systemd:postgresql.service")
        ->assertInertia(fn ($page) => $page->where('siteTraffic', null));
});

test('a web server’s page lists its own sites, the ones with no log last, and containers count as theirs', function () {
    [$workspace, $machine, $base] = anySiteMachine();
    sendAnySiteTraffic($machine, [anySiteMinute('caddy.releases.serveros.com', 5), anySiteMinute('caddy.any', 500)]);

    $this->actingAs($workspace->owner)->get($base."/machines/{$machine->id}/services/systemd:caddy.service/traffic")
        ->assertInertia(fn ($page) => $page->where('siteTraffic.role', 'server')
            ->where('siteTraffic.server', 'Caddy')
            ->where('siteTraffic.canAddFile', false)
            ->has('siteTraffic.sites', 4)
            ->where('siteTraffic.sites.0.site', 'caddy.any')
            ->where('siteTraffic.sites.1.site', 'caddy.releases.serveros.com')
            ->where('siteTraffic.sites.3.site', 'caddy.quiet.test')
            ->where('siteTraffic.sites.3.existing.unlogged', 'off')
            ->where('siteTraffic.sites.3.existing.canChange', true));

    // Traefik in a container, found by its image, with its own sites.
    $this->actingAs($workspace->owner)->get($base."/machines/{$machine->id}/services/docker:aaaaaaaaaaaa/traffic")
        ->assertInertia(fn ($page) => $page->where('siteTraffic.role', 'server')
            ->where('siteTraffic.server', 'Traefik')
            ->has('siteTraffic.sites', 1)
            ->where('siteTraffic.sites.0.existing.container', 'coolify-proxy')
            ->where('siteTraffic.sites.0.existing.canChange', false));

    // The app container Traefik's router sends to has that one site.
    $this->actingAs($workspace->owner)->get($base."/machines/{$machine->id}/services/docker:bbbbbbbbbbbb/traffic")
        ->assertInertia(fn ($page) => $page->where('siteTraffic.role', 'app')
            ->has('siteTraffic.sites', 1)
            ->where('siteTraffic.sites.0.site', 'traefik-coolify-proxy.shop.test')
            ->where('siteTraffic.sites.0.where', 'Traefik in coolify-proxy'));

    // nginx in a container: only its sites, not the host's, though a root matches.
    $this->actingAs($workspace->owner)->get($base."/machines/{$machine->id}/services/docker:cccccccccccc/traffic")
        ->assertInertia(fn ($page) => $page->where('siteTraffic.server', 'nginx')
            ->has('siteTraffic.sites', 2)
            ->where('siteTraffic.sites.1.existing.unlogged', 'off'));
});

test('with traffic off or an older daemon the tab still shows, saying what to do', function () {
    [$workspace, $machine, $base] = anySiteMachine(collecting: false);
    $this->actingAs($workspace->owner)->get($base."/machines/{$machine->id}/services/systemd:serveros-queue.service/traffic")
        ->assertInertia(fn ($page) => $page->where('siteTraffic.collecting', false)->has('siteTraffic.sites', 2));

    // A daemon that counts host nginx and Apache only: Caddy needs the newer one.
    $machine->forceFill(['daemon_version' => '0.1.1-pre.47', 'traffic_sites' => null])->save();
    $this->actingAs($workspace->owner)->get($base."/machines/{$machine->id}/services/systemd:caddy.service/traffic")
        ->assertInertia(fn ($page) => $page->where('siteTraffic.role', 'server')
            ->where('siteTraffic.sites', [])
            ->where('siteTraffic.update', 'Update the ServerOS daemon on web-1 to v0.1.1-pre.48 or later to count this service’s traffic.'));
    $this->actingAs($workspace->owner)->get($base."/machines/{$machine->id}/services/systemd:other.service/traffic")
        ->assertInertia(fn ($page) => $page->where('siteTraffic.canAddFile', false)
            ->where('siteTraffic.update', 'Update the ServerOS daemon on web-1 to v0.1.1-pre.48 or later to count this service’s traffic.'));
});

test('the machine’s table links each site to its web server’s page and lists unlogged ones last', function () {
    [$workspace, $machine, $base] = anySiteMachine();
    sendAnySiteTraffic($machine, [anySiteMinute('caddy.any', 500), anySiteMinute('traefik-coolify-proxy.shop.test', 20)]);

    $sites = collect($this->actingAs($workspace->owner)->getJson($base."/machines/{$machine->id}/traffic/sites")->assertOk()->json('sites'));
    expect($sites->pluck('site')->take(2)->all())->toBe(['caddy.any', 'traefik-coolify-proxy.shop.test'])
        ->and($sites->first())->toMatchArray(['name' => 'All other hosts', 'server' => 'Caddy', 'catchAll' => true, 'url' => "/machines/{$machine->id}/services/systemd:caddy.service/traffic?site=caddy.any"])
        ->and($sites->firstWhere('site', 'traefik-coolify-proxy.shop.test'))->toMatchArray(['server' => 'Traefik', 'container' => 'coolify-proxy', 'url' => "/machines/{$machine->id}/services/docker:aaaaaaaaaaaa/traffic?site=traefik-coolify-proxy.shop.test"])
        ->and($sites->slice(-2)->pluck('unlogged')->all())->toBe(['off', 'off']);
    expect(SiteTraffic::sites($machine))->toHaveCount(7);
});

test('a site’s log is started and stopped by someone who manages machines, dry run first', function () {
    [$workspace, $machine, $base] = anySiteMachine();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $url = $base."/machines/{$machine->id}/sites/caddy.quiet.test/logging";

    $this->actingAs($viewer)->postJson($url, ['action' => 'add', 'apply' => false])->assertForbidden();

    $jobId = $this->actingAs($workspace->owner)->postJson($url, ['action' => 'add', 'apply' => false])->assertStatus(202)->json('jobId');
    expect(DaemonJob::query()->findOrFail($jobId)->command['job'])->toBe(['type' => 'traffic_logging', 'site' => 'caddy.quiet.test', 'action' => 'add', 'apply' => false])
        ->and(Activity::query()->where('action', 'machine.traffic_logging')->count())->toBe(0);
    $this->actingAs($viewer)->getJson("{$url}/{$jobId}")->assertOk()->assertJsonPath('state', 'queued');
    $this->actingAs($viewer)->getJson($base."/machines/{$machine->id}/sites/caddy.any/logging/{$jobId}")->assertNotFound();

    $this->actingAs($workspace->owner)->postJson($url, ['action' => 'add', 'apply' => true])->assertStatus(202);
    expect(ActivityLine::describe(Activity::query()->where('action', 'machine.traffic_logging')->sole(), false)['verb'])->toBe('started logging quiet.test on');

    // Nothing ServerOS added to take out; containers aren't changed; old daemons can't.
    $this->actingAs($workspace->owner)->postJson($url, ['action' => 'remove', 'apply' => false])->assertStatus(409);
    $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/sites/nginx-web.quiet.test/logging", ['action' => 'add', 'apply' => false])
        ->assertStatus(409)
        ->assertJsonPath('error', 'This site’s server runs in the container web, so ServerOS won’t change its config. Turn on its access log in the container’s config instead.');
    $this->actingAs($workspace->owner)->postJson($url, ['action' => 'sideways', 'apply' => false])->assertStatus(422);

    $machine->forceFill(['daemon_version' => '0.1.1-pre.47'])->save();
    $this->actingAs($workspace->owner)->postJson($url, ['action' => 'add', 'apply' => false])->assertStatus(409);
});

test('a log ServerOS added can be taken out again', function () {
    [$workspace, $machine, $base] = anySiteMachine();
    $sites = $machine->traffic_sites;
    $sites['sites'][3] = [...$sites['sites'][3], 'copy' => true, 'unlogged' => null, 'logs' => ['/var/log/serveros-traffic/existing/caddy.quiet.test.log']];
    $machine->forceFill(['traffic_sites' => $sites])->save();
    $url = $base."/machines/{$machine->id}/sites/caddy.quiet.test/logging";

    $this->actingAs($workspace->owner)->postJson($url, ['action' => 'add', 'apply' => false])->assertStatus(409);
    $jobId = $this->actingAs($workspace->owner)->postJson($url, ['action' => 'remove', 'apply' => true])->assertStatus(202)->json('jobId');
    expect(DaemonJob::query()->findOrFail($jobId)->command['job']['action'])->toBe('remove')
        ->and(ActivityLine::describe(Activity::query()->where('action', 'machine.traffic_logging')->sole(), false)['verb'])->toBe('took ServerOS’s log out of quiet.test’s config on');
});

test('an app’s own log file can be counted and stopped', function () {
    [$workspace, $machine, $base] = anySiteMachine();

    $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/traffic/files", ['name' => 'other', 'path' => '/proc/self/environ'])->assertStatus(422);
    $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/traffic/files", ['name' => 'other', 'path' => 'relative.log'])->assertStatus(422);
    $jobId = $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/traffic/files", ['name' => 'other', 'path' => '/var/www2/storage/access.log'])->assertStatus(202)->json('jobId');
    expect(DaemonJob::query()->findOrFail($jobId)->command['job'])->toBe(['type' => 'traffic_source', 'action' => 'add', 'name' => 'other', 'path' => '/var/www2/storage/access.log']);
    $this->actingAs($workspace->owner)->getJson($base."/machines/{$machine->id}/traffic/files/{$jobId}")->assertOk()->assertJsonPath('state', 'queued');

    // Once the daemon reports it, the app's page shows it, and it can be stopped.
    $sites = $machine->traffic_sites['sites'];
    $machine->forceFill(['traffic_sites' => ExistingSites::stored([...array_map(fn (array $s) => [...$s, 'upstream_ports' => $s['ports'], 'upstream_containers' => $s['apps'], 'catch_all' => $s['catchAll']], $sites), ['site' => 'log.other', 'server' => 'log', 'names' => ['other'], 'logs' => ['/var/www2/storage/access.log'], 'manual' => true]])])->save();
    $this->actingAs($workspace->owner)->get($base."/machines/{$machine->id}/services/systemd:other.service/traffic")
        ->assertInertia(fn ($page) => $page->has('siteTraffic.sites', 1)->where('siteTraffic.sites.0.where', 'Log file'));
    $removed = $this->actingAs($workspace->owner)->deleteJson($base."/machines/{$machine->id}/traffic/files/log.other")->assertStatus(202)->json('jobId');
    expect(DaemonJob::query()->findOrFail($removed)->command['job'])->toBe(['type' => 'traffic_source', 'action' => 'remove', 'name' => 'log.other']);
    $this->actingAs($workspace->owner)->deleteJson($base."/machines/{$machine->id}/traffic/files/caddy.any")->assertNotFound();
});

test('list_site_traffic, get_site_traffic and change_site_logging cover every kind of site', function () {
    [$workspace, $machine] = anySiteMachine();
    $read = mcpKey($workspace, scopes: [Scopes::READ]);
    $write = mcpKey($workspace, scopes: [Scopes::READ, Scopes::WRITE]);

    $list = mcpCall($workspace, $read, 'list_site_traffic', ['machine' => 'web-1']);
    expect($list['error'])->toBeFalse($list['text'])
        ->and(collect($list['data']['sites'])->firstWhere('site', 'caddy.quiet.test'))->toMatchArray(['server' => 'Caddy', 'unlogged' => 'off']);

    $one = mcpCall($workspace, $read, 'get_site_traffic', ['machine' => 'web-1', 'site' => 'caddy.quiet.test']);
    expect($one['data']['note'])->toBe('Caddy writes no access log for this site, so there’s nothing to count. change_site_logging can add a log of ServerOS’s own to its config.');
    $container = mcpCall($workspace, $read, 'get_site_traffic', ['machine' => 'web-1', 'site' => 'nginx-web.quiet.test']);
    expect($container['data']['note'])->toBe('nginx in web writes no access log for this site, so there’s nothing to count. Turn on its access log in the container’s config.');

    $dry = mcpCall($workspace, $write, 'change_site_logging', ['machine' => 'web-1', 'site' => 'caddy.quiet.test', 'action' => 'start']);
    expect($dry['error'])->toBeFalse($dry['text'])
        ->and($dry['data']['applied'])->toBeFalse()
        ->and(DaemonJob::query()->where('kind', 'traffic_logging')->sole()->command['job']['apply'])->toBeFalse();

    $wrong = mcpCall($workspace, $write, 'change_site_logging', ['machine' => 'web-1', 'site' => 'caddy.quiet.test', 'action' => 'start', 'confirm' => 'caddy.any']);
    expect($wrong['error'])->toBeTrue();
    $done = mcpCall($workspace, $write, 'change_site_logging', ['machine' => 'web-1', 'site' => 'caddy.quiet.test', 'action' => 'start', 'confirm' => 'caddy.quiet.test']);
    expect($done['error'])->toBeFalse($done['text'])
        ->and($done['data']['applied'])->toBeTrue()
        ->and(DaemonJob::query()->where('kind', 'traffic_logging')->latest('created_at')->get()->contains(fn (DaemonJob $j) => $j->command['job']['apply'] === true))->toBeTrue();

    $refused = mcpCall($workspace, $write, 'change_site_logging', ['machine' => 'web-1', 'site' => 'nginx-web.quiet.test', 'action' => 'start']);
    expect($refused['error'])->toBeTrue()->and($refused['text'])->toContain('container web');
    $readOnly = mcpCall($workspace, $read, 'change_site_logging', ['machine' => 'web-1', 'site' => 'caddy.quiet.test', 'action' => 'start']);
    expect($readOnly['error'])->toBeTrue();
});
