<?php

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Integration;
use App\Models\IntegrationDnsRecord;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\DaemonJobQueue;
use App\Support\Domains\DomainResolver;
use App\Support\Integrations\Cloudflare\CloudflareDns;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeCloudflare;

/** @return array{0: Workspace, 1: Machine, 2: FakeCloudflare} */
function cflareSetup(string $ip = '203.0.113.7'): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $cloudflare = FakeCloudflare::install();
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    app()->instance(DomainResolver::class, new class extends DomainResolver
    {
        public function addresses(string $domain): array
        {
            return [];
        }
    });

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => $ip]);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => '0.1.1-pre.40'])->save();

    return [$workspace, $machine, $cloudflare];
}

function cflareAccount(Workspace $workspace, string $label = 'Acme'): Integration
{
    return $workspace->integrations()->create([
        'provider' => 'cloudflare',
        'label' => $label,
        'credentials' => ['token' => FakeCloudflare::TOKEN],
        'fingerprint' => Integration::fingerprintOf('cloudflare', ['token' => FakeCloudflare::TOKEN.$label]),
        'meta' => ['account' => 'Acme', 'zones' => 1],
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
    ]);
}

function cflareConnect(Workspace $workspace, string $token = FakeCloudflare::TOKEN, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $workspace->owner)
        ->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/cloudflare'), ['credentials' => ['token' => $token]]);
}

/** A deploy of the app that worked, as the machine last ran it. */
function cflareLive(Machine $machine, array $domains, string $service = 'shop'): DaemonJob
{
    return DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $machine->workspace->owner->id, 'kind' => 'deploy', 'service' => $service,
        'state' => 'succeeded', 'log' => [], 'finished_at' => now(),
        'command' => ['job' => [
            'type' => 'deploy', 'service' => $service, 'repo' => 'https://github.com/acme/shop.git',
            'commit' => str_repeat('a', 40), 'env' => [], 'domains' => $domains, 'port' => 3000,
        ]],
    ]);
}

/** Queues a deploy the way every path does, and returns it. */
function cflareDeploy(Machine $machine, array $domains, string $service = 'shop'): DaemonJob
{
    return app(DaemonJobQueue::class)->deploy($machine, $machine->workspace->owner, [
        'type' => 'deploy', 'service' => $service, 'repo' => 'https://github.com/acme/shop.git',
        'commit' => str_repeat('b', 40), 'env' => (object) [], 'domains' => $domains, 'port' => 3000,
        'health' => ['path' => '/', 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => 5],
    ]);
}

function cflareFinish(Machine $machine, DaemonJob $job, string $state = 'succeeded'): void
{
    test()->withHeaders(['X-Gateway-Secret' => 'test-gateway-secret'])
        ->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [[
            'v' => 1, 'kind' => 'job_update',
            'payload' => ['job_id' => $job->id, 'state' => $state],
        ]]])
        ->assertOk();
}

/** The machine runs a container for each app, as its inventory says. */
function cflareContainers(Machine $machine, array $apps): void
{
    $machine->forceFill(['inventory' => ['services' => array_map(fn (string $app) => [
        'key' => "docker:serveros-{$app}-abcdefabcdef", 'name' => "serveros-{$app}-abcdefabcdef", 'kind' => 'container', 'status' => 'running',
    ], $apps)]])->save();
}

function cflareRemove(Machine $machine, string $app): void
{
    $key = "docker:serveros-{$app}-abcdefabcdef";
    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'service_remove', 'service' => $key, 'state' => 'running',
        'command' => ['job' => ['type' => 'service_remove', 'service' => $key]], 'log' => [],
    ]);
    cflareFinish($machine, $job);
}

function cflareMember(Workspace $workspace, string $role): User
{
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => $role]);

    return $user;
}

beforeEach(function () {
    Sleep::fake();
});

test('connecting Cloudflare checks the token, lists its zones and keeps the token encrypted', function () {
    [$workspace, , $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    $cloudflare->zone('acme.dev');

    cflareConnect($workspace)->assertSessionHasNoErrors()->assertRedirect();

    $integration = Integration::query()->sole();
    expect($integration->label)->toBe('Acme')
        ->and($integration->meta)->toBe(['account' => 'Acme', 'zones' => 2])
        ->and($integration->credential('token'))->toBe(FakeCloudflare::TOKEN)
        ->and(DB::table('integrations')->value('credentials'))->not->toContain(FakeCloudflare::TOKEN)
        ->and($cloudflare->called())->toBe(['GET user/tokens/verify', 'GET zones'])
        ->and($cloudflare->writes())->toBe([]);
    Http::assertSent(fn ($request) => ! str_contains($request->url(), 'cloudflare.com') || $request->hasHeader('Authorization', 'Bearer '.FakeCloudflare::TOKEN));
    expect(ActivityLine::sentence(Activity::query()->where('action', 'integration.connect')->sole()))->toBe($workspace->owner->name.' connected Acme (Cloudflare)');

    $page = $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))->assertOk();
    $page->assertInertia(fn (Assert $p) => $p
        ->where('integration.summary', '2 zones')
        ->where('integration.facts.0', ['label' => 'Account', 'value' => 'Acme'])
        ->has('provider.capabilities', 2));
    expect($page->getContent())->not->toContain(FakeCloudflare::TOKEN);
});

test('a token Cloudflare turns down, or one that has expired, is never saved', function () {
    [$workspace, , $cloudflare] = cflareSetup();

    cflareConnect($workspace, 'wrong-token-0123456789abcdef')
        ->assertSessionHasErrors(['credentials.token' => 'Cloudflare turned the token down. Check it’s copied in full and hasn’t been deleted.']);

    $cloudflare->tokenStatus = 'expired';
    cflareConnect($workspace)->assertSessionHasErrors(['credentials.token' => 'This token has expired. Make a new one in Cloudflare and connect it.']);

    expect(Integration::query()->count())->toBe(0);
});

test('more than one Cloudflare account can be connected', function () {
    [$workspace, , $cloudflare] = cflareSetup();
    cflareAccount($workspace, 'Agency');

    $cloudflare->zone('example.com');

    cflareConnect($workspace)->assertSessionHasNoErrors();

    expect(Integration::query()->where('provider', 'cloudflare')->pluck('label')->all())->toBe(['Agency', 'Acme']);
});

test('a domain matches the longest connected zone, for the apex, subdomains and wildcards', function () {
    [$workspace, , $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    $cloudflare->zone('shop.example.com');
    $cloudflare->zone('old.dev', 'pending');
    cflareAccount($workspace);
    $dns = app(CloudflareDns::class);

    expect($dns->zoneFor($workspace->id, 'example.com')['zone']['name'])->toBe('example.com')
        ->and($dns->zoneFor($workspace->id, 'blog.example.com')['zone']['name'])->toBe('example.com')
        ->and($dns->zoneFor($workspace->id, '*.example.com')['zone']['name'])->toBe('example.com')
        ->and($dns->zoneFor($workspace->id, 'eu.shop.example.com')['zone']['name'])->toBe('shop.example.com')
        ->and($dns->zoneFor($workspace->id, 'notexample.com'))->toBeNull()
        ->and($dns->zoneFor($workspace->id, 'app.old.dev'))->toBeNull()
        ->and($dns->zoneFor(Workspace::factory()->create()->id, 'example.com'))->toBeNull()
        ->and($cloudflare->called())->toBe(['GET zones']);
});

test('a deploy with a domain in a connected zone adds an A record pointing at the machine, unproxied', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    cflareAccount($workspace);

    cflareDeploy($machine, ['shop.example.com', '*.shop.example.com', 'shop.other.org']);

    $zone = $cloudflare->zoneId('example.com');
    expect($cloudflare->writes())->toBe(["POST zones/{$zone}/dns_records", "POST zones/{$zone}/dns_records"])
        ->and($cloudflare->bodyOf('POST', "zones/{$zone}/dns_records"))->toBe(['type' => 'A', 'name' => '*.shop.example.com', 'content' => '203.0.113.7', 'ttl' => 1, 'proxied' => false, 'comment' => 'Managed by ServerOS'])
        ->and($cloudflare->recordsNamed('shop.example.com')[0])->toMatchArray(['type' => 'A', 'content' => '203.0.113.7', 'proxied' => false]);

    $row = IntegrationDnsRecord::query()->where('hostname', 'shop.example.com')->sole();
    expect($row->only(['service', 'type', 'content', 'proxied', 'created_record', 'status']))
        ->toBe(['service' => 'shop', 'type' => 'A', 'content' => '203.0.113.7', 'proxied' => false, 'created_record' => true, 'status' => 'active'])
        ->and(IntegrationDnsRecord::query()->count())->toBe(2);

    expect(ActivityLine::sentence(Activity::query()->where('action', 'app.dns_point')->first()))->toBe('ServerOS pointed shop.example.com at shop through Cloudflare on web-1');

    $before = count($cloudflare->calls);
    cflareDeploy($machine, ['shop.example.com', '*.shop.example.com']);
    expect(count($cloudflare->calls))->toBe($before);
});

test('a deploy with no connected Cloudflare account never calls Cloudflare', function () {
    [, $machine, $cloudflare] = cflareSetup();

    cflareDeploy($machine, ['shop.example.com']);

    expect($cloudflare->calls)->toBe([])
        ->and(IntegrationDnsRecord::query()->count())->toBe(0);
});

test('a record pointing somewhere else is taken over but never deleted, and one already pointing here is left as it is', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    $old = $cloudflare->record('example.com', 'A', 'shop.example.com', '198.51.100.9', true);
    $same = $cloudflare->record('example.com', 'A', 'api.example.com', '203.0.113.7', true);
    cflareAccount($workspace);
    $zone = $cloudflare->zoneId('example.com');

    $deploy = cflareDeploy($machine, ['shop.example.com', 'api.example.com']);
    cflareFinish($machine, $deploy);

    expect($cloudflare->writes())->toBe(["PATCH zones/{$zone}/dns_records/{$old}"])
        ->and($cloudflare->bodyOf('PATCH', "zones/{$zone}/dns_records/{$old}"))->toBe(['content' => '203.0.113.7', 'proxied' => false])
        ->and(IntegrationDnsRecord::query()->where('hostname', 'shop.example.com')->value('created_record'))->toBeFalse()
        ->and(IntegrationDnsRecord::query()->where('hostname', 'api.example.com')->sole()->only(['record_id', 'created_record', 'proxied']))->toBe(['record_id' => $same, 'created_record' => false, 'proxied' => true]);

    cflareContainers($machine, ['shop']);
    cflareRemove($machine, 'shop');

    expect($cloudflare->called('DELETE'))->toBe([])
        ->and($cloudflare->recordsNamed('shop.example.com'))->toHaveCount(1)
        ->and(IntegrationDnsRecord::query()->count())->toBe(0)
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'app.dns_remove')->first()))->toBe('ServerOS stopped managing the Cloudflare record for api.example.com of shop on web-1');
});

test('a CNAME in the way, or several A records, is reported next to the domain instead of being changed', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    $cloudflare->record('example.com', 'CNAME', 'shop.example.com', 'shops.myshopify.com');
    $cloudflare->record('example.com', 'A', 'www.example.com', '198.51.100.1');
    $cloudflare->record('example.com', 'A', 'www.example.com', '198.51.100.2');
    cflareAccount($workspace);

    cflareDeploy($machine, ['shop.example.com', 'www.example.com']);

    expect($cloudflare->writes())->toBe([])
        ->and(IntegrationDnsRecord::query()->where('hostname', 'shop.example.com')->sole()->only(['status', 'error']))
        ->toBe(['status' => 'failed', 'error' => 'There’s already a CNAME record for shop.example.com at Cloudflare. Delete it there, then try again.'])
        ->and(IntegrationDnsRecord::query()->where('hostname', 'www.example.com')->value('error'))->toBe('There are 2 A records for www.example.com at Cloudflare. Keep one there, then try again.');
});

test('a new domain moves the record over once the deploy works, deleting only the record ServerOS made', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    cflareAccount($workspace);
    $first = cflareDeploy($machine, ['shop.example.com']);
    cflareFinish($machine, $first);
    $oldId = IntegrationDnsRecord::query()->sole()->record_id;

    $move = cflareDeploy($machine, ['store.example.com']);
    expect(IntegrationDnsRecord::query()->pluck('hostname')->sort()->values()->all())->toBe(['shop.example.com', 'store.example.com']);

    cflareFinish($machine, $move);

    $zone = $cloudflare->zoneId('example.com');
    expect($cloudflare->called('DELETE'))->toBe(["DELETE zones/{$zone}/dns_records/{$oldId}"])
        ->and($cloudflare->recordsNamed('shop.example.com'))->toBe([])
        ->and(IntegrationDnsRecord::query()->pluck('hostname')->all())->toBe(['store.example.com'])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'app.dns_remove')->sole()))->toBe('ServerOS removed the Cloudflare record for shop.example.com from shop on web-1');
});

test('a deploy that fails gives back the domain it was adding, and keeps the live one', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    cflareAccount($workspace);
    cflareLive($machine, ['shop.example.com']);
    app(CloudflareDns::class)->point($machine, 'shop', ['shop.example.com']);

    $move = cflareDeploy($machine, ['store.example.com']);
    cflareFinish($machine, $move, 'failed');

    expect(IntegrationDnsRecord::query()->pluck('hostname')->all())->toBe(['shop.example.com'])
        ->and($cloudflare->recordsNamed('store.example.com'))->toBe([])
        ->and($cloudflare->recordsNamed('shop.example.com'))->toHaveCount(1);
});

test('removing the app deletes the records ServerOS made for it, and nothing else in the zone', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    $mail = $cloudflare->record('example.com', 'A', 'mail.example.com', '198.51.100.30');
    cflareAccount($workspace);
    cflareLive($machine, ['shop.example.com']);
    app(CloudflareDns::class)->point($machine, 'shop', ['shop.example.com', '*.shop.example.com']);
    app(CloudflareDns::class)->point($machine, 'blog', ['blog.example.com']);

    app(CloudflareDns::class)->point($machine, 'preview', ['preview.example.com']);
    cflareContainers($machine, ['shop', 'blog']);
    cflareDeploy($machine, ['preview.example.com'], 'preview');

    cflareRemove($machine, 'shop');

    expect($cloudflare->called('DELETE'))->toHaveCount(2)
        ->and($cloudflare->recordsNamed('mail.example.com')[0]['id'])->toBe($mail)
        ->and($cloudflare->recordsNamed('blog.example.com'))->toHaveCount(1)
        ->and(IntegrationDnsRecord::query()->orderBy('hostname')->pluck('hostname')->all())->toBe(['blog.example.com', 'preview.example.com']);
});

test('the domain check knows a zone is in a connected account, and Point it here adds the record now', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    cflareAccount($workspace);
    $url = $workspace->url().'/machines/'.$machine->id;

    $this->actingAs($workspace->owner)->postJson($url.'/domain-check', ['domain' => 'shop.example.com', 'wildcard' => true])
        ->assertOk()
        ->assertJson([
            'status' => 'missing',
            'cloudflare' => ['account' => 'Acme', 'zone' => 'example.com', 'canPoint' => true, 'forced' => false, 'record' => null],
            'wildcard' => ['cloudflare' => ['zone' => 'example.com', 'record' => null]],
        ]);
    $this->actingAs($workspace->owner)->postJson($url.'/domain-check', ['domain' => 'shop.other.org'])
        ->assertOk()->assertJson(['cloudflare' => null]);

    $this->actingAs($workspace->owner)->postJson($url.'/domain-dns', ['domain' => 'https://Shop.example.com/', 'wildcard' => true, 'service' => 'shop', 'proxied' => true])
        ->assertOk()
        ->assertJson([
            'domain' => 'shop.example.com',
            'cloudflare' => ['record' => ['status' => 'active', 'type' => 'A', 'content' => '203.0.113.7', 'proxied' => true, 'created' => true, 'here' => true]],
            'wildcard' => ['cloudflare' => ['record' => ['hostname' => '*.shop.example.com', 'proxied' => true]]],
        ]);
    expect($cloudflare->recordsNamed('shop.example.com')[0]['proxied'])->toBeTrue()
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'app.dns_point')->first()))->toBe($workspace->owner->name.' pointed shop.example.com at shop through Cloudflare on web-1');

    $this->actingAs($workspace->owner)->postJson($url.'/domain-dns', ['domain' => 'shop.other.org', 'service' => 'shop'])
        ->assertUnprocessable()->assertJsonValidationErrors(['domain' => 'shop.other.org isn’t in a connected Cloudflare account.']);
});

test('the proxied choice can be changed per domain, but a tunnel’s record stays proxied', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    cflareAccount($workspace);
    cflareLive($machine, ['shop.example.com']);
    app(CloudflareDns::class)->point($machine, 'shop', ['shop.example.com']);
    $row = IntegrationDnsRecord::query()->sole();
    $url = $workspace->url().'/machines/'.$machine->id.'/apps/shop/dns';

    $this->actingAs($workspace->owner)->patchJson($url, ['hostname' => 'shop.example.com', 'proxied' => true])
        ->assertOk()->assertJson(['record' => ['proxied' => true]]);

    $zone = $cloudflare->zoneId('example.com');
    expect($cloudflare->bodyOf('PATCH', "zones/{$zone}/dns_records/{$row->record_id}"))->toBe(['proxied' => true])
        ->and($row->fresh()->proxied)->toBeTrue()
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'app.dns_proxied')->sole()))->toBe($workspace->owner->name.' turned on Cloudflare’s proxy for shop.example.com of shop on web-1');

    $this->actingAs($workspace->owner)->patchJson($url, ['hostname' => 'nope.example.com', 'proxied' => true])->assertNotFound();

    $machine->forceFill(['tunnel' => ['provider' => 'cloudflare', 'tunnel_id' => 'c0ffee00-1111-2222-3333-444455556666', 'state' => 'connected', 'plain_http' => true, 'since' => now()->toIso8601String()]])->save();
    app(CloudflareDns::class)->point($machine->fresh(), 'tunnelled', ['tunnel.example.com'], false);

    $tunnel = IntegrationDnsRecord::query()->where('hostname', 'tunnel.example.com')->sole();
    expect($tunnel->only(['type', 'content', 'proxied']))->toBe(['type' => 'CNAME', 'content' => 'c0ffee00-1111-2222-3333-444455556666.cfargotunnel.com', 'proxied' => true]);
    cflareLive($machine, ['tunnel.example.com'], 'tunnelled');
    $this->actingAs($workspace->owner)->patchJson($workspace->url().'/machines/'.$machine->id.'/apps/tunnelled/dns', ['hostname' => 'tunnel.example.com', 'proxied' => false])
        ->assertUnprocessable()->assertJson(['error' => 'Records for a Cloudflare Tunnel have to be proxied.']);
});

test('an app can purge Cloudflare’s cache after each deploy, and by hand from its page', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    cflareAccount($workspace);
    cflareLive($machine, ['shop.example.com', '*.shop.example.com']);
    $url = $workspace->url().'/machines/'.$machine->id.'/apps/shop';

    $quiet = cflareDeploy($machine, ['shop.example.com', '*.shop.example.com']);
    cflareFinish($machine, $quiet);
    expect($cloudflare->purges)->toBe([]);

    $this->actingAs($workspace->owner)->putJson($url.'/purge', ['on' => true])->assertOk()->assertJson(['purgeAfterDeploy' => true]);
    expect(CloudflareDns::purgesAfterDeploy($machine->fresh(), 'shop'))->toBeTrue()
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'app.cache_purge_setting')->sole()))->toBe($workspace->owner->name.' turned on purging Cloudflare’s cache after each deploy of shop on web-1');

    $deploy = cflareDeploy($machine->fresh(), ['shop.example.com', '*.shop.example.com']);
    cflareFinish($machine, $deploy);
    expect($cloudflare->purges)->toBe([['zone' => 'example.com', 'hosts' => ['shop.example.com']]])
        ->and(CloudflareDns::lastPurge($machine, 'shop'))->toMatchArray(['hosts' => ['shop.example.com'], 'error' => null, 'auto' => true]);

    $failed = cflareDeploy($machine->fresh(), ['shop.example.com']);
    cflareFinish($machine, $failed, 'failed');
    expect($cloudflare->purges)->toHaveCount(1);

    $this->actingAs($workspace->owner)->postJson($url.'/purge')->assertOk()->assertJson(['hosts' => ['shop.example.com'], 'error' => null]);
    expect($cloudflare->purges)->toHaveCount(2)
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'app.cache_purge')->sole()))->toBe($workspace->owner->name.' purged Cloudflare’s cache for shop on web-1');

    cflareLive($machine, ['blog.other.org'], 'blog');
    $this->actingAs($workspace->owner)->postJson($workspace->url().'/machines/'.$machine->id.'/apps/blog/purge')
        ->assertUnprocessable()->assertJson(['error' => 'None of blog’s domains are in a connected Cloudflare account.']);
});

test('a purge Cloudflare refuses says why', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    cflareAccount($workspace);
    cflareLive($machine, ['shop.example.com']);
    $cloudflare->failNext('POST', 'zones/'.$cloudflare->zoneId('example.com').'/purge_cache', 403, FakeCloudflare::error(10000, 'Authentication error'));

    $this->actingAs($workspace->owner)->postJson($workspace->url().'/machines/'.$machine->id.'/apps/shop/purge')
        ->assertUnprocessable()
        ->assertJson(['error' => 'Cloudflare says this token isn’t allowed to do that. Give it Zone Read, DNS Edit and Cache Purge for this zone.']);
    expect(Integration::query()->sole()->status)->toBe('connected');
});

test('a failing call leaves the record failed with why, and a refused token marks the account', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $zone = $cloudflare->zone('example.com');
    $integration = cflareAccount($workspace);
    $cloudflare->failNext('POST', "zones/{$zone}/dns_records", 403, FakeCloudflare::error(10000, 'Authentication error'));

    cflareDeploy($machine, ['shop.example.com']);

    expect(IntegrationDnsRecord::query()->sole()->only(['status', 'record_id']))->toBe(['status' => 'failed', 'record_id' => null])
        ->and($integration->fresh()->status)->toBe('connected');

    app(CloudflareDns::class)->point($machine, 'shop', ['shop.example.com']);
    expect(IntegrationDnsRecord::query()->sole()->status)->toBe('active');

    $cloudflare->failNext('PATCH', "zones/{$zone}/dns_records/".IntegrationDnsRecord::query()->sole()->record_id, 401, FakeCloudflare::error(1000, 'Invalid API Token'));
    app(CloudflareDns::class)->point($machine, 'shop', ['shop.example.com'], true);
    expect($integration->fresh()->status)->toBe('failing');
});

test('a machine with no public address can’t be pointed at, and says so', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup('10.0.0.5');
    $cloudflare->zone('example.com');
    cflareAccount($workspace);

    cflareDeploy($machine, ['shop.example.com']);

    expect($cloudflare->writes())->toBe([])
        ->and(IntegrationDnsRecord::query()->sole()->error)->toBe('ServerOS doesn’t know a public address for web-1 to point shop.example.com at.');
});

test('the service page shows each domain’s record and the purge setting', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    cflareAccount($workspace);
    cflareLive($machine, ['shop.example.com', 'shop.other.org']);
    app(CloudflareDns::class)->point($machine, 'shop', ['shop.example.com']);
    $machine->forceFill(['inventory' => ['services' => [['key' => 'docker:serveros-shop-abcdefabcdef', 'name' => 'serveros-shop-abcdefabcdef', 'kind' => 'container', 'app' => 'shop', 'status' => 'running', 'managed' => true]]]])->save();

    $props = null;
    $this->actingAs($workspace->owner)->get($workspace->url().'/machines/'.$machine->id.'/services/app:shop')
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $page->loadDeferredProps(function (Assert $reload) use (&$props) {
                $props = $reload->toArray()['props']['cloudflare'] ?? null;
            });
        });

    expect($props['domains'][0])->toMatchArray(['hostname' => 'shop.example.com', 'account' => 'Acme', 'zone' => 'example.com'])
        ->and($props['domains'][0]['record'])->toMatchArray(['status' => 'active', 'type' => 'A', 'proxied' => false, 'here' => true])
        ->and($props['domains'][1])->toBe(['hostname' => 'shop.other.org', 'account' => null, 'zone' => null, 'record' => null])
        ->and($props['canPurge'])->toBeTrue()
        ->and($props['purgeAfterDeploy'])->toBeFalse();
});

test('the account page lists its zones with the records ServerOS keeps in each', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    $cloudflare->zone('acme.dev');
    $integration = cflareAccount($workspace);
    app(CloudflareDns::class)->point($machine, 'shop', ['shop.example.com', 'www.example.com']);

    $props = null;
    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertOk()
        ->assertInertia(function (Assert $page) use (&$props) {
            $page->where('integration.facts.2', ['label' => 'Records ServerOS manages', 'value' => '2'])
                ->loadDeferredProps(function (Assert $reload) use (&$props) {
                    $props = $reload->toArray()['props']['cloudflare'];
                });
        });

    expect($props['zones'])->toBe([
        ['id' => $cloudflare->zoneId('acme.dev'), 'name' => 'acme.dev', 'status' => 'active', 'managed' => 0],
        ['id' => $cloudflare->zoneId('example.com'), 'name' => 'example.com', 'status' => 'active', 'managed' => 2],
    ])
        ->and($props['records'][0])->toMatchArray(['hostname' => 'shop.example.com', 'type' => 'A', 'content' => '203.0.113.7', 'proxied' => false, 'app' => 'shop', 'machine' => 'web-1'])
        ->and($props['error'])->toBeNull();
});

test('disconnecting says which records stop being managed, and leaves them at Cloudflare', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    $integration = cflareAccount($workspace);
    app(CloudflareDns::class)->point($machine, 'shop', ['shop.example.com']);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page->where('dependents', ['1 DNS record stops being kept pointed at its machine. It stays at Cloudflare.']));

    $this->actingAs($workspace->owner)->delete($workspace->url('/integrations/'.$integration->id), ['confirm' => 'Acme'])->assertRedirect();

    expect(IntegrationDnsRecord::query()->count())->toBe(0)
        ->and($cloudflare->called('DELETE'))->toBe([])
        ->and($cloudflare->recordsNamed('shop.example.com'))->toHaveCount(1);
});

test('only people who manage machines can point, proxy and purge, and only in their own workspace', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    cflareAccount($workspace);
    cflareLive($machine, ['shop.example.com']);
    app(CloudflareDns::class)->point($machine, 'shop', ['shop.example.com']);
    $viewer = cflareMember($workspace, 'viewer');
    $url = $workspace->url().'/machines/'.$machine->id;

    $this->actingAs($viewer)->postJson($url.'/domain-dns', ['domain' => 'shop.example.com', 'service' => 'shop'])->assertForbidden();
    $this->actingAs($viewer)->patchJson($url.'/apps/shop/dns', ['hostname' => 'shop.example.com', 'proxied' => true])->assertForbidden();
    $this->actingAs($viewer)->postJson($url.'/apps/shop/purge')->assertForbidden();
    $this->actingAs($viewer)->putJson($url.'/apps/shop/purge', ['on' => true])->assertForbidden();

    $other = Workspace::factory()->create();
    $this->actingAs($other->owner)->postJson($other->url().'/machines/'.$machine->id.'/domain-dns', ['domain' => 'shop.example.com', 'service' => 'shop'])->assertNotFound();

    expect($cloudflare->writes())->toHaveCount(1)
        ->and($cloudflare->purges)->toBe([]);
});

test('the Integrations page offers Cloudflare to connect, and keeps tunnels in the Connected list', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    $machine->forceFill(['tunnel' => ['provider' => 'cloudflare', 'tunnel_id' => 'x', 'state' => 'connected', 'plain_http' => true, 'since' => now()->toIso8601String()]])->save();

    $props = $this->actingAs($workspace->owner)->get($workspace->url('/integrations'))->assertOk()->viewData('page')['props'];
    $row = collect($props['integrations'])->firstWhere('key', 'cloudflare');
    expect($row['action'])->toBe(['label' => 'Connect', 'connect' => 'cloudflare'])
        ->and($row['connected'])->toBeTrue()
        ->and($row['description'])->toBe('Points your apps’ domains at their machines and purges the cache after each deploy.')
        ->and(collect($props['connections'])->firstWhere('key', 'cloudflare'))->toMatchArray(['name' => 'Cloudflare', 'detail' => 'Tunnel on web-1'])
        ->and($props['providers']['cloudflare']['fields'][0]['link'])->toBe('https://dash.cloudflare.com/profile/api-tokens');

    $integration = cflareAccount($workspace);
    $props = $this->actingAs($workspace->owner)->get($workspace->url('/integrations'))->viewData('page')['props'];
    expect(collect($props['integrations'])->firstWhere('key', 'cloudflare')['action'])->toBe(['label' => 'Manage', 'href' => '/integrations/'.$integration->id])
        ->and(collect($props['connections'])->firstWhere('key', 'integration-'.$integration->id))->toMatchArray(['name' => 'Acme', 'detail' => '1 zone', 'logo' => 'cloudflare'])
        ->and(collect($props['connections'])->firstWhere('key', 'cloudflare'))->toMatchArray(['detail' => 'Tunnel on web-1']);
});

test('over MCP, check_domain says Cloudflare handles it and set_app_domain points the new domain', function () {
    [$workspace, $machine, $cloudflare] = cflareSetup();
    $cloudflare->zone('example.com');
    cflareAccount($workspace);
    cflareLive($machine, ['old.example.com']);
    $key = mcpKey($workspace);

    $check = mcpCall($workspace, $key, 'check_domain', ['machine' => 'web-1', 'domain' => 'shop.example.com']);
    expect($check['error'])->toBeFalse($check['text'])
        ->and($check['data']['cloudflare'])->toMatchArray(['account' => 'Acme', 'zone' => 'example.com', 'record' => null])
        ->and($check['data']['advice'])->toBe('shop.example.com is in the Cloudflare account Acme connected to this workspace: ServerOS points it at web-1 itself as the app deploys.');

    $moved = mcpCall($workspace, $key, 'set_app_domain', ['machine' => 'web-1', 'app' => 'shop', 'domain' => 'shop.example.com']);

    expect($moved['error'])->toBeFalse($moved['text'])
        ->and($cloudflare->recordsNamed('shop.example.com')[0])->toMatchArray(['type' => 'A', 'content' => '203.0.113.7'])
        ->and(IntegrationDnsRecord::query()->sole()->service)->toBe('shop');
});
