<?php

use App\Enums\Plan;
use App\Mcp\Tools\DeployTools;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Deploys\SiteSwitches;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('mcp.groups', [DeployTools::class]);
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $this->withoutDefer();
});

/** @return array{0: Workspace, 1: Machine, 2: string} */
function siteApp(array $domains = ['shop.example.com'], string $daemon = '0.1.1-pre.46'): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $daemon])->save();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'user_id' => $workspace->owner_id,
        'kind' => 'deploy',
        'service' => 'shop',
        'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('1', 40), 'port' => 3000, 'domains' => $domains]],
    ]);

    return [$workspace, $machine, 'https://'.$workspace->host()];
}

function siteJobs(Machine $machine)
{
    return DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'site_options')->orderBy('created_at')->get();
}

function finishSiteJob(Machine $machine): void
{
    siteJobs($machine)->each(fn (DaemonJob $job) => $job->forceFill(['state' => 'succeeded'])->save());
}

test('a maintenance page goes up at once, without a redeploy', function () {
    [$workspace, $machine, $base] = siteApp();

    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['maintenance' => true])
        ->assertStatus(202)
        ->assertJsonPath('site.maintenance', true)
        ->assertJsonPath('site.password', false);

    $job = siteJobs($machine)->sole();
    expect($job->service)->toBe('shop')
        ->and($job->command['job'])->toBe(['type' => 'site_options', 'service' => 'shop', 'options' => ['maintenance' => true, 'www_redirect' => false]])
        ->and(DaemonJob::query()->where('kind', 'deploy')->count())->toBe(1)
        ->and(ActivityLine::describe(Activity::query()->where('action', 'app.site')->sole(), false)['verb'])->toBe('put up the maintenance page for');

    finishSiteJob($machine);
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['maintenance' => false, 'maintenance_page' => '<h1>Back at 5</h1>'])
        ->assertStatus(202);
    expect(siteJobs($machine)->last()->command['job']['options'])->toBe(['maintenance' => false, 'www_redirect' => false, 'maintenance_html' => '<h1>Back at 5</h1>']);
});

test('the password only ever leaves as a hash and is never shown', function () {
    [$workspace, $machine, $base] = siteApp();

    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['password_protect' => true])
        ->assertStatus(422)->assertJsonValidationErrors('password');
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['password_protect' => true, 'username' => 'staff', 'password' => 'short'])
        ->assertStatus(422)->assertJsonValidationErrors('password');

    $response = $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['password_protect' => true, 'username' => 'staff', 'password' => 'correct horse'])
        ->assertStatus(202)
        ->assertJsonPath('site.password', true)
        ->assertJsonPath('site.user', 'staff');
    expect($response->getContent())->not->toContain('correct horse')->not->toContain('$2y$');

    $auth = siteJobs($machine)->sole()->command['job']['options']['basic_auth'];
    expect($auth['user'])->toBe('staff')
        ->and(password_verify('correct horse', $auth['hash']))->toBeTrue()
        ->and(json_encode($machine->fresh()->app_settings))->not->toContain('correct horse');

    // Other changes keep the password; turning it off drops the hash.
    finishSiteJob($machine);
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['www_redirect' => true])->assertStatus(202);
    expect(siteJobs($machine)->last()->command['job']['options']['basic_auth']['hash'])->toBe($auth['hash']);

    finishSiteJob($machine);
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['password_protect' => false])->assertStatus(202);
    expect(siteJobs($machine)->last()->command['job']['options'])->not->toHaveKey('basic_auth')
        ->and($machine->fresh()->app_settings['shop']['site']['hash'])->toBeNull();
});

test('redirects only take plain paths and addresses', function () {
    [$workspace, $machine, $base] = siteApp();
    $url = $base."/machines/{$machine->id}/apps/shop/site";

    foreach ([
        ['from' => 'old', 'to' => '/new'],
        ['from' => '/a b', 'to' => '/new'],
        ['from' => '/a;b', 'to' => '/new'],
        ['from' => '/.well-known/acme-challenge/x', 'to' => '/new'],
        ['from' => '/../etc', 'to' => '/new'],
        ['from' => '/old', 'to' => '//evil.example'],
        ['from' => '/old', 'to' => '/new$1'],
        ['from' => '/old', 'to' => '/new%20x'],
        ['from' => '/old', 'to' => '/new{uri}'],
        ['from' => '/old', 'to' => 'javascript:alert(1)'],
    ] as $redirect) {
        $this->actingAs($workspace->owner)->putJson($url, ['redirects' => [$redirect]])->assertStatus(422);
    }
    $this->actingAs($workspace->owner)->putJson($url, ['redirects' => [['from' => '/a', 'to' => '/b'], ['from' => '/a', 'to' => '/c']]])->assertStatus(422);
    expect(siteJobs($machine))->toHaveCount(0);

    $this->actingAs($workspace->owner)->putJson($url, ['redirects' => [
        ['from' => '/old-pricing', 'to' => '/pricing'],
        ['from' => '/docs', 'to' => 'https://docs.example.com/start?ref=site', 'permanent' => false],
    ]])->assertStatus(202)->assertJsonCount(2, 'site.redirects');
    expect(siteJobs($machine)->sole()->command['job']['options']['redirects'])->toBe([
        ['from' => '/old-pricing', 'to' => '/pricing', 'permanent' => true],
        ['from' => '/docs', 'to' => 'https://docs.example.com/start?ref=site', 'permanent' => false],
    ]);
});

test('site switches need a domain, a new enough daemon and one change at a time', function () {
    [$workspace, $machine, $base] = siteApp([]);
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['maintenance' => true])
        ->assertStatus(422)->assertJsonValidationErrors('domain');

    [$workspace, $machine, $base] = siteApp(daemon: '0.1.1-pre.45');
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['maintenance' => true])
        ->assertStatus(409)->assertJsonPath('error', fn (string $e) => str_contains($e, 'too old'));

    [$workspace, $machine, $base] = siteApp();
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['maintenance' => true])->assertStatus(202);
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['maintenance' => false])
        ->assertStatus(409)->assertJsonPath('error', fn (string $e) => str_contains($e, 'still being applied'));
    finishSiteJob($machine);
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['maintenance' => true])
        ->assertStatus(422)->assertJsonValidationErrors('site');
});

test('only people who manage machines can change a site', function () {
    [$workspace, $machine, $base] = siteApp();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['maintenance' => true])->assertForbidden();
    expect(siteJobs($machine))->toHaveCount(0);
});

test('the app page shows the switches and the www name, never the hash', function () {
    [$workspace, $machine, $base] = siteApp(['www.example.com']);
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/site", ['www_redirect' => true, 'password_protect' => true, 'password' => 'correct horse'])->assertStatus(202);

    $props = SiteSwitches::props($machine->fresh(), 'shop', ['www.example.com']);
    expect($props)->toMatchArray(['wwwRedirect' => true, 'wwwName' => 'example.com', 'domain' => 'www.example.com', 'password' => true, 'user' => 'admin', 'supported' => true])
        ->and($props['job']['state'])->toBe('queued')
        ->and(json_encode($props))->not->toContain('$2y$');
});

test('update_site_settings sets the switches through MCP', function () {
    [$workspace, $machine] = siteApp();
    $key = mcpKey($workspace);

    $result = mcpCall($workspace, $key, 'update_site_settings', ['machine' => 'web-1', 'app' => 'shop', 'maintenance' => true, 'redirects' => [['from' => '/old', 'to' => '/new']]]);
    expect($result['error'])->toBeFalse($result['text'])
        ->and($result['data']['site'])->toMatchArray(['maintenance' => true, 'redirects' => [['from' => '/old', 'to' => '/new', 'permanent' => true]]])
        ->and(siteJobs($machine)->sole()->command['job']['options']['maintenance'])->toBeTrue();

    finishSiteJob($machine);
    $refused = mcpCall($workspace, $key, 'update_site_settings', ['machine' => 'web-1', 'app' => 'shop', 'redirects' => [['from' => 'nope', 'to' => '/new']]]);
    expect($refused['error'])->toBeTrue()->and($refused['text'])->toContain('exact path');

    $nothing = mcpCall($workspace, $key, 'update_site_settings', ['machine' => 'web-1', 'app' => 'shop']);
    expect($nothing['error'])->toBeTrue()->and($nothing['text'])->toContain('Say what to change');
});
