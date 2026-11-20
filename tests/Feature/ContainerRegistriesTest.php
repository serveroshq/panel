<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\ContainerRegistry;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\DeployFailure;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
});

/** @return array{0: Workspace, 1: string} */
function registryWorkspace(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    return [$workspace, 'https://'.$workspace->host()];
}

function registryMachine(Workspace $workspace, string $version = '0.1.1-pre.46'): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $version])->save();

    return $machine;
}

/** @return list<array<string, mixed>> */
function registryCommandsSent(): array
{
    return collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (HttpRequest $request) => str_ends_with($request->url(), '/commands'))
        ->map(fn (HttpRequest $request) => json_decode($request->body(), true)['command']['job'])
        ->values()
        ->all();
}

test('a registry login is added in settings with its password encrypted and never shown', function () {
    $this->withoutVite();
    [$workspace, $base] = registryWorkspace();
    $owner = $workspace->owner;

    $this->actingAs($owner)->post($base.'/settings/registries', ['host' => 'https://ghcr.io/acme/shop', 'username' => 'acme-bot', 'password' => 'ghp_readpackages123'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->actingAs($owner)->post($base.'/settings/registries', ['host' => 'index.docker.io', 'username' => 'acme', 'password' => 'dckr_pat_abc'])->assertSessionHasNoErrors();

    $ghcr = ContainerRegistry::query()->where('host', 'ghcr.io')->sole();
    expect($ghcr)->username->toBe('acme-bot')->password->toBe('ghp_readpackages123')
        ->and(ContainerRegistry::query()->where('host', 'docker.io')->exists())->toBeTrue()
        ->and(DB::table('container_registries')->pluck('password')->implode(' '))->not->toContain('ghp_readpackages123')
        ->and(json_encode($ghcr->toArray()))->not->toContain('ghp_readpackages123');

    $page = $this->actingAs($owner)->get($base.'/settings/general');
    $page->assertInertia(fn (AssertableInertia $p) => $p
        ->where('registries.0.host', 'docker.io')->where('registries.0.label', 'Docker Hub')
        ->where('registries.1', fn ($row) => $row['host'] === 'ghcr.io' && $row['username'] === 'acme-bot' && ! isset($row['password'])));
    expect($page->getContent())->not->toContain('ghp_readpackages123')->not->toContain('dckr_pat_abc');

    // Adding the same registry again replaces its login.
    $this->actingAs($owner)->post($base.'/settings/registries', ['host' => 'ghcr.io', 'username' => 'acme-ci', 'password' => 'ghp_new'])->assertSessionHasNoErrors();
    expect(ContainerRegistry::query()->where('host', 'ghcr.io')->sole())->username->toBe('acme-ci')->password->toBe('ghp_new');

    $this->actingAs($owner)->delete($base.'/settings/registries/'.$ghcr->id)->assertRedirect();
    expect(ContainerRegistry::query()->pluck('host')->all())->toBe(['docker.io'])
        ->and(Activity::query()->where('workspace_id', $workspace->id)->where('action', 'like', 'registry.%')->orderBy('id')->pluck('action')->all())
        ->toBe(['registry.add', 'registry.add', 'registry.update', 'registry.remove'])
        ->and(json_encode(Activity::query()->pluck('meta')))->not->toContain('ghp_');
});

test('registry logins are checked, and only people who manage machines can change them', function () {
    [$workspace, $base] = registryWorkspace();
    $owner = $workspace->owner;

    $this->actingAs($owner)->post($base.'/settings/registries', ['host' => 'not a host!', 'username' => 'a', 'password' => 'b'])->assertSessionHasErrors('host');
    $this->actingAs($owner)->post($base.'/settings/registries', ['host' => 'ghcr.io', 'username' => 'ac:me', 'password' => 'b'])->assertSessionHasErrors('username');
    $this->actingAs($owner)->post($base.'/settings/registries', ['host' => 'ghcr.io', 'username' => 'acme', 'password' => ''])->assertSessionHasErrors('password');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->post($base.'/settings/registries', ['host' => 'ghcr.io', 'username' => 'acme', 'password' => 'x'])->assertForbidden();

    $other = Workspace::factory()->onPlan(Plan::Pro)->create();
    $theirs = $other->containerRegistries()->create(['host' => 'ghcr.io', 'username' => 'them', 'password' => 'secret']);
    $this->actingAs($owner)->delete($base.'/settings/registries/'.$theirs->id)->assertNotFound();
    expect(ContainerRegistry::query()->count())->toBe(1);
});

test('deploys carry the logins to the machine as they’re sent, and they’re never kept in the job', function () {
    [$workspace] = registryWorkspace();
    $workspace->containerRegistries()->create(['host' => 'ghcr.io', 'username' => 'acme', 'password' => 'ghp_secret']);
    $machine = registryMachine($workspace);
    $queue = app(DaemonJobQueue::class);

    $job = $queue->deploy($machine, $workspace->owner, ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://example.com/acme/shop.git', 'commit' => str_repeat('a', 40)]);
    $queue->queue($machine, $workspace->owner, 'discover', ['type' => 'discover'], null);

    [$deploy, $discover] = registryCommandsSent();
    expect($deploy['registries'])->toBe([['host' => 'ghcr.io', 'username' => 'acme', 'password' => 'ghp_secret']])
        ->and($discover)->not->toHaveKey('registries')
        ->and(json_encode($job->fresh()->command))->not->toContain('ghp_secret')
        ->and(json_encode(DaemonJob::query()->get()->map->toArray()))->not->toContain('ghp_secret');
});

test('an older daemon isn’t sent registry logins', function () {
    [$workspace] = registryWorkspace();
    $workspace->containerRegistries()->create(['host' => 'ghcr.io', 'username' => 'acme', 'password' => 'ghp_secret']);
    $machine = registryMachine($workspace, '0.1.1-pre.45');

    app(DaemonJobQueue::class)->deploy($machine, $workspace->owner, ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://example.com/acme/shop.git', 'commit' => str_repeat('a', 40)]);

    expect(registryCommandsSent()[0])->not->toHaveKey('registries');
});

test('a private image that can’t be pulled points to the registry logins', function () {
    $private = DeployFailure::fromError('build', 'docker compose up exited 1', ['Error response from daemon: pull access denied for acme/private, repository does not exist or may require \'docker login\'']);
    expect($private['fix'])->toContain('Settings → General → Container registries');

    $rejected = DeployFailure::fromError('build', 'docker build exited 1', ['ERROR: failed to solve: ghcr.io/acme/base:1: failed to authorize: failed to fetch oauth token: unexpected status 401 Unauthorized']);
    expect($rejected['cause'])->toBe('A registry turned down the login for an image the deploy needs.')
        ->and($rejected['fix'])->toContain('Container registries');
});
