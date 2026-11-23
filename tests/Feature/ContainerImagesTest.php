<?php

use App\Models\Activity;
use App\Models\ContainerImage;
use App\Models\DaemonJob;
use App\Models\ImageVulnerability;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Images\ImageReference;
use App\Support\Images\ImageScanner;
use App\Support\Images\ImageUpdates;
use App\Support\Monitors\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

const NEW_PG = 'sha256:'.'1111111111111111111111111111111111111111111111111111111111111111';
const OLD_PG = 'sha256:'.'2222222222222222222222222222222222222222222222222222222222222222';
const NGINX = 'sha256:'.'3333333333333333333333333333333333333333333333333333333333333333';

beforeEach(function () {
    $this->withoutVite();
    Cache::flush();
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        protected function addresses(string $host): array
        {
            return $host === 'registry.internal' ? ['10.0.0.5'] : ['93.184.215.14'];
        }
    });
});

/** A registry that wants a token, like Docker Hub: postgres:16 has moved on, nginx hasn't. */
function fakeRegistries(): void
{
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'auth.docker.io/token*' => Http::response(['token' => 'pull-token']),
        'registry-1.docker.io/v2/library/postgres/manifests/16' => fn (Request $r) => $r->hasHeader('Authorization', 'Bearer pull-token')
            ? Http::response('', 200, ['Docker-Content-Digest' => NEW_PG])
            : Http::response('', 401, ['WWW-Authenticate' => 'Bearer realm="https://auth.docker.io/token",service="registry.docker.io",scope="repository:library/postgres:pull"']),
        'registry-1.docker.io/v2/library/nginx/manifests/alpine' => Http::response('', 200, ['Docker-Content-Digest' => NGINX]),
        'ghcr.io/v2/acme/private/manifests/1' => fn (Request $r) => $r->hasHeader('Authorization', 'Basic '.base64_encode('bot:secret'))
            ? Http::response('', 200, ['Docker-Content-Digest' => NGINX])
            : Http::response('', 401, ['WWW-Authenticate' => 'Basic realm="ghcr"']),
    ]);
}

/**
 * @param  list<array{0: string, 1: string, 2: string, 3: string}>  $containers  name, image, image id, digests
 * @return array{0: Workspace, 1: Machine}
 */
function imageMachine(array $containers = [], string $version = 'v0.1.1-pre.49'): array
{
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $containers = $containers ?: [
        ['shop-db-1', 'postgres:16', OLD_PG, 'postgres@'.OLD_PG],
        ['shop-web-1', 'nginx:alpine', NGINX, 'nginx@'.NGINX],
        ['shop-app-1', 'shop-app:latest', 'sha256:'.str_repeat('4', 64), ''],
    ];
    $services = [];
    foreach ($containers as $i => [$name, $image, $id, $digests]) {
        $services[] = ['key' => 'docker:abc00000000'.$i, 'name' => $name, 'manager' => 'compose', 'kind' => 'container', 'status' => 'running',
            'details' => array_filter(['image' => $image, 'image_id' => $id, 'image_digests' => $digests])];
    }
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $version, 'inventory' => ['services' => $services]])->save();

    return [$workspace, $machine];
}

test('image references read the way Docker reads them', function () {
    $pg = ImageReference::parse('postgres:16');
    expect([$pg->registry, $pg->repository, $pg->tag, $pg->display()])->toBe(['docker.io', 'library/postgres', '16', 'postgres:16']);

    $ghcr = ImageReference::parse('ghcr.io/Acme/App');
    expect([$ghcr->registry, $ghcr->repository, $ghcr->tag, $ghcr->apiHost()])->toBe(['ghcr.io', 'acme/app', 'latest', 'ghcr.io']);

    $local = ImageReference::parse('localhost:5000/team/app:2');
    expect([$local->registry, $local->repository, $local->tag])->toBe(['localhost:5000', 'team/app', '2']);

    expect(ImageReference::parse('redis@sha256:abc'))->toBeNull()
        ->and(ImageReference::parse(OLD_PG))->toBeNull();

    expect($pg->digestsIn(['postgres@'.OLD_PG, 'docker.io/library/postgres@'.NEW_PG, 'mirror.example.com/postgres@'.NGINX]))->toBe([OLD_PG, NEW_PG]);
});

test('a check says which containers have a newer image, signing in where the registry asks', function () {
    fakeRegistries();
    [, $machine] = imageMachine();

    expect(app(ImageUpdates::class)->check($machine))->toBe(1);

    $rows = $machine->containerImages()->get()->keyBy('container');
    expect($rows['shop-db-1']->state)->toBe('available')
        ->and($rows['shop-db-1']->remote_digest)->toBe(NEW_PG)
        ->and($rows['shop-db-1']->available_since)->not->toBeNull()
        ->and($rows['shop-web-1']->state)->toBe('current')
        ->and($rows['shop-app-1']->state)->toBe('local');

    // A tag on many machines is asked about once an hour, and a container that's gone goes too.
    $machine->forceFill(['inventory' => ['services' => array_slice($machine->inventory['services'], 0, 1)]])->save();
    $since = $rows['shop-db-1']->available_since;
    $this->travel(5)->minutes();
    app(ImageUpdates::class)->check($machine);
    Http::assertSentCount(4);
    expect($machine->containerImages()->pluck('container')->all())->toBe(['shop-db-1'])
        ->and($machine->containerImages()->first()->available_since->equalTo($since))->toBeTrue();
});

test('a machine removed while its check asks the registries is skipped, not written to', function () {
    [, $machine] = imageMachine();
    // Asked first, so it wins over the usual registries below.
    Http::fake(['registry-1.docker.io/v2/library/nginx/manifests/alpine' => function () use ($machine) {
        Machine::query()->whereKey($machine->id)->delete();

        return Http::response('', 200, ['Docker-Content-Digest' => NGINX]);
    }]);
    fakeRegistries();

    expect(app(ImageUpdates::class)->check($machine))->toBe(0)
        ->and(ContainerImage::query()->count())->toBe(0);
});

test('private images are checked with the workspace’s registry login, and never on a private network', function () {
    fakeRegistries();
    [$workspace, $machine] = imageMachine([
        ['private-1', 'ghcr.io/acme/private:1', NGINX, 'ghcr.io/acme/private@'.NGINX],
        ['internal-1', 'registry.internal/team/app:1', NGINX, 'registry.internal/team/app@'.NGINX],
    ]);

    app(ImageUpdates::class)->check($machine);
    $rows = $machine->containerImages()->get()->keyBy('container');
    expect($rows['private-1']->state)->toBe('unknown')
        ->and($rows['private-1']->error)->toContain('Settings › Registries')
        ->and($rows['internal-1']->state)->toBe('unknown')
        ->and($rows['internal-1']->error)->toContain('private network');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'registry.internal'));

    $workspace->containerRegistries()->create(['host' => 'ghcr.io', 'username' => 'bot', 'password' => 'secret', 'user_id' => $workspace->owner_id]);
    app(ImageUpdates::class)->check($machine);
    expect($machine->containerImages()->where('container', 'private-1')->value('state'))->toBe('current');
});

test('machines on an older daemon aren’t checked, and nothing is recorded for them', function () {
    fakeRegistries();
    [, $machine] = imageMachine([], 'v0.1.1-pre.48');

    expect(ImageUpdates::due()->pluck('id')->all())->not->toContain($machine->id);
    $this->artisan('images:check')->assertSuccessful();
    expect(ContainerImage::query()->count())->toBe(0);
});

test('updating a container queues the update with the registry logins, and its result marks it current', function () {
    fakeRegistries();
    [$workspace, $machine] = imageMachine();
    $workspace->containerRegistries()->create(['host' => 'docker.io', 'username' => 'me', 'password' => 'hub-token', 'user_id' => $workspace->owner_id]);
    app(ImageUpdates::class)->check($machine);
    $db = $machine->containerImages()->where('container', 'shop-db-1')->firstOrFail();

    $queued = $this->actingAs($workspace->owner)->postJson('https://'.$workspace->host().'/container-images/update', ['ids' => [$db->id]])
        ->assertAccepted()
        ->json('queued');
    $job = DaemonJob::query()->findOrFail($queued[$db->id]);
    expect($job->kind)->toBe('image_update')
        ->and($job->command['job'])->toBe(['type' => 'image_update', 'service' => 'docker:abc000000000']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), '127.0.0.1:8090') && ($r['command']['job']['registries'][0]['password'] ?? null) === 'hub-token');
    expect(Activity::query()->where('action', 'service.image_update')->value('meta'))->toBe(['image' => 'postgres:16']);

    // Asking again while it runs doesn't queue a second one.
    $this->actingAs($workspace->owner)->postJson('https://'.$workspace->host().'/container-images/update', ['ids' => [$db->id]])->assertAccepted();
    expect(DaemonJob::query()->where('kind', 'image_update')->count())->toBe(1);

    $job->forceFill(['state' => 'succeeded', 'result' => ['service' => 'docker:fff000000000', 'updated' => true, 'image_id' => NEW_PG]])->save();
    ImageUpdates::finished($job);
    expect($db->fresh())->state->toBe('current')->service_key->toBe('docker:fff000000000')->image_id->toBe(NEW_PG)->scanned_at->toBeNull();
});

test('only people who manage machines can update, on machines whose daemon knows how', function () {
    fakeRegistries();
    [$workspace, $machine] = imageMachine([], 'v0.1.1-pre.49');
    app(ImageUpdates::class)->check($machine);
    $db = $machine->containerImages()->where('container', 'shop-db-1')->firstOrFail();

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson('https://'.$workspace->host().'/container-images/update', ['ids' => [$db->id]])->assertForbidden();

    $machine->forceFill(['daemon_version' => 'v0.1.1-pre.48'])->save();
    $this->actingAs($workspace->owner)->postJson('https://'.$workspace->host().'/container-images/update', ['ids' => [$db->id]])
        ->assertConflict()
        ->assertJsonFragment(['error' => 'Its ServerOS daemon is too old to update container images. Update it from the machine’s page first.']);

    // Another workspace's container isn't found.
    $other = Workspace::factory()->create();
    $this->actingAs($other->owner)->postJson('https://'.$other->host().'/container-images/update', ['ids' => [$db->id]])->assertNotFound();
});

test('the Updates page lists containers, newer images first', function () {
    fakeRegistries();
    [$workspace, $machine] = imageMachine();
    app(ImageUpdates::class)->check($machine);

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/updates?view=containers')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->component('workspace/Updates')
            ->where('view', 'containers')
            ->where('containers.0.container', 'shop-db-1')
            ->where('containers.0.state', 'available')
            ->where('containers.0.machine', 'web-1')
            ->has('containers', 3));
});

test('the machine lists Docker’s images and volumes and removes only what was picked', function () {
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    [$workspace, $machine] = imageMachine();
    $url = 'https://'.$workspace->host().'/machines/'.$machine->id;

    $jobId = $this->actingAs($workspace->owner)->postJson("{$url}/docker")->assertAccepted()->json('jobId');
    expect(DaemonJob::query()->findOrFail($jobId)->command['job'])->toBe(['type' => 'docker_resources']);
    DaemonJob::query()->whereKey($jobId)->update(['state' => 'succeeded', 'finished_at' => now(), 'result' => json_encode([
        'images' => [['id' => OLD_PG, 'tags' => [], 'size' => 400, 'created' => 1700000000, 'containers' => []]],
        'volumes' => [['name' => 'pgdata', 'size' => 900, 'project' => 'shop', 'anonymous' => false, 'containers' => ['shop-db-1']]],
        'build_cache' => ['size' => 130, 'reclaimable' => 100],
    ])]);
    $this->actingAs($workspace->owner)->getJson("{$url}/docker/{$jobId}")
        ->assertOk()
        ->assertJsonPath('images.0.id', OLD_PG)
        ->assertJsonPath('volumes.0.containers', ['shop-db-1'])
        ->assertJsonPath('buildCache.reclaimable', 100);

    $this->actingAs($workspace->owner)->postJson("{$url}/docker/prune", ['images' => ['postgres']])->assertUnprocessable();
    $this->actingAs($workspace->owner)->postJson("{$url}/docker/prune", [])->assertUnprocessable();

    $pruneId = $this->actingAs($workspace->owner)->postJson("{$url}/docker/prune", ['images' => [OLD_PG], 'volumes' => ['old_data'], 'build_cache' => true])
        ->assertAccepted()->json('jobId');
    expect(DaemonJob::query()->findOrFail($pruneId)->command['job'])->toBe(['type' => 'docker_prune', 'images' => [OLD_PG], 'volumes' => ['old_data'], 'build_cache' => true]);
    expect(Activity::query()->where('action', 'machine.docker_prune')->value('meta'))->toBe(['images' => 1, 'volumes' => 1, 'build_cache' => true]);

    DaemonJob::query()->whereKey($pruneId)->update(['state' => 'succeeded', 'finished_at' => now(), 'result' => json_encode([
        'images' => [['id' => OLD_PG, 'size' => 400]], 'volumes' => [], 'build_cache' => 100,
        'skipped' => [['volume' => 'old_data', 'reason' => 'used by shop-db-1']],
    ])]);
    $this->actingAs($workspace->owner)->getJson("{$url}/docker/{$pruneId}")
        ->assertJson(['removedImages' => 1, 'removedVolumes' => 0, 'reclaimed' => 500, 'skipped' => [['name' => 'old_data', 'reason' => 'used by shop-db-1']]]);

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson("{$url}/docker")->assertForbidden();
    $this->actingAs($viewer)->postJson("{$url}/docker/prune", ['build_cache' => true])->assertForbidden();

    [$old, $oldMachine] = imageMachine([], 'v0.1.1-pre.48');
    $this->actingAs($old->owner)->postJson('https://'.$old->host().'/machines/'.$oldMachine->id.'/docker')->assertConflict();
});

test('an image’s packages are matched against OSV, keeping only what its distribution has fixed', function () {
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'api.osv.dev/v1/querybatch' => fn (Request $r) => Http::response(['results' => array_map(
            fn (array $q) => ['vulns' => $q['package']['name'] === 'openssl' ? [['id' => 'DEBIAN-CVE-2026-1111'], ['id' => 'DEBIAN-CVE-2026-2222']] : []],
            $r['queries'],
        )]),
        'api.osv.dev/v1/vulns/DEBIAN-CVE-2026-1111' => Http::response(['id' => 'DEBIAN-CVE-2026-1111', 'upstream' => ['CVE-2026-1111'], 'summary' => 'OpenSSL overflow',
            'severity' => [['type' => 'CVSS_V3', 'score' => 'CVSS:3.1/AV:N/AC:L/PR:N/UI:N/S:U/C:H/I:H/A:H']],
            'affected' => [['package' => ['name' => 'openssl', 'ecosystem' => 'Debian:12'], 'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0'], ['fixed' => '3.0.16-1~deb12u1']]]]]]]),
        'api.osv.dev/v1/vulns/DEBIAN-CVE-2026-2222' => Http::response(['id' => 'DEBIAN-CVE-2026-2222', 'upstream' => ['CVE-2026-2222'],
            'affected' => [['package' => ['name' => 'openssl', 'ecosystem' => 'Debian:12'], 'ranges' => [['type' => 'ECOSYSTEM', 'events' => [['introduced' => '0']]]]]]]),
    ]);
    [$workspace, $machine] = imageMachine();
    $machine->containerImages()->create(['container' => 'shop-db-1', 'service_key' => 'docker:abc000000000', 'image' => 'postgres:16', 'image_id' => OLD_PG, 'state' => 'available']);

    $scanner = app(ImageScanner::class);
    expect($scanner->queue())->toBe(1);
    $row = $machine->containerImages()->firstOrFail();
    $job = DaemonJob::query()->findOrFail($row->scan_job_id);
    expect($job->command['job'])->toBe(['type' => 'image_packages', 'image' => OLD_PG]);
    // Waiting on the machine: nothing to look up, and it isn't asked twice.
    expect($scanner->process())->toBe(0)->and($scanner->queue())->toBe(0);

    $job->forceFill(['state' => 'succeeded', 'result' => [
        'os' => ['id' => 'debian', 'version_id' => '12'],
        'manager' => 'dpkg',
        'packages' => [
            ['name' => 'libssl3', 'version' => '3.0.15-1~deb12u1', 'source' => 'openssl', 'source_version' => '3.0.15-1~deb12u1'],
            ['name' => 'bash', 'version' => '5.2.15-2+b7', 'source' => 'bash', 'source_version' => '5.2.15-2+b7'],
        ],
    ]])->save();
    expect($scanner->process())->toBe(1);
    expect($row->fresh())->scan_job_id->toBeNull()->scanned_at->not->toBeNull();

    $finding = ImageVulnerability::query()->with('vulnerability')->sole();
    expect($finding->vulnerability->cve)->toBe('CVE-2026-1111')
        ->and($finding->vulnerability->severity)->toBe('critical')
        ->and([$finding->source, $finding->installed, $finding->fixed_in])->toBe(['openssl', '3.0.15-1~deb12u1', '3.0.16-1~deb12u1']);

    $this->actingAs($workspace->owner)->getJson('https://'.$workspace->host().'/container-images/'.$row->id.'/vulnerabilities')
        ->assertOk()
        ->assertJsonPath('findings.0.cve', 'CVE-2026-1111')
        ->assertJsonPath('findings.0.fixedIn', '3.0.16-1~deb12u1');

    // Once no container runs that image, its findings are resolved.
    fakeRegistries();
    $machine->forceFill(['inventory' => ['services' => []]])->save();
    app(ImageUpdates::class)->check($machine);
    expect(ImageVulnerability::query()->whereNull('resolved_at')->count())->toBe(0);
});
