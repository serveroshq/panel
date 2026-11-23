<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('daemon.short_job_wait_secs', 0);
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
});

/** @return array{0: Workspace, 1: Machine, 2: string} */
function keyMachine(string $daemon = '0.1.1-pre.45'): array
{
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '203.0.113.7']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $daemon, 'facts' => ['docker_version' => '27.0.1']])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id.'/deploy'];
}

/** @return list<array<string, mixed>> */
function sentKeyJobs(): array
{
    return collect(Http::recorded())->map(fn (array $pair) => $pair[0])
        ->filter(fn (GatewayRequest $r) => str_ends_with($r->url(), '/commands'))
        ->map(fn (GatewayRequest $r) => json_decode($r->body(), true)['command']['job'])->values()->all();
}

test('the machine makes a deploy key for the app and the dialog reads it back', function () {
    [$workspace, $machine, $url] = keyMachine();

    $answer = $this->actingAs($workspace->owner)->postJson($url.'/key', ['service' => 'shop'])->assertOk()->json();
    expect($answer['state'])->toBe('queued')->and(sentKeyJobs())->toBe([['type' => 'deploy_key', 'service' => 'shop']]);

    DaemonJob::query()->findOrFail($answer['jobId'])->forceFill(['state' => 'succeeded', 'result' => ['public_key' => 'ssh-ed25519 AAAA serveros-shop']])->save();
    $this->actingAs($workspace->owner)->getJson($url.'/jobs/'.$answer['jobId'])
        ->assertOk()->assertJson(['state' => 'succeeded', 'publicKey' => 'ssh-ed25519 AAAA serveros-shop']);

    $this->actingAs($workspace->owner)->postJson($url.'/key', ['service' => '../etc'])->assertUnprocessable();
});

test('a branch is looked up over the deploy key, and its commit deploys from the SSH address', function () {
    [$workspace, $machine, $url] = keyMachine();

    $answer = $this->actingAs($workspace->owner)->postJson($url.'/resolve', ['service' => 'shop', 'repo' => 'git@gitlab.com:acme/shop.git', 'ref' => 'main'])->assertOk()->json();
    expect(sentKeyJobs()[0])->toBe(['type' => 'resolve_ref', 'service' => 'shop', 'repo' => 'git@gitlab.com:acme/shop.git', 'ref' => 'main']);

    $commit = str_repeat('e', 40);
    DaemonJob::query()->findOrFail($answer['jobId'])->forceFill(['state' => 'succeeded', 'result' => ['commit' => $commit, 'ref' => 'refs/heads/main']])->save();
    $this->actingAs($workspace->owner)->getJson($url.'/jobs/'.$answer['jobId'])->assertJson(['commit' => $commit, 'ref' => 'refs/heads/main']);

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'nextjs', 'service' => 'shop', 'repo' => 'git@gitlab.com:acme/shop.git', 'commit' => $commit,
    ])->assertAccepted();
    $deploy = collect(sentKeyJobs())->last();
    expect($deploy)->type->toBe('deploy')->repo->toBe('git@gitlab.com:acme/shop.git')->commit->toBe($commit)
        ->and($deploy)->not->toHaveKey('repo_token');
});

test('an SSH repository needs its commit looked up first', function () {
    [$workspace, , $url] = keyMachine();

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'nextjs', 'service' => 'shop', 'repo' => 'git@gitlab.com:acme/shop.git', 'commit' => 'main',
    ])->assertUnprocessable()->assertJsonValidationErrors(['commit' => 'look up the branch first']);
    expect(sentKeyJobs())->toBe([]);
});

test('only real SSH addresses and ref names reach the machine', function (string $repo, ?string $ref) {
    [$workspace, , $url] = keyMachine();

    $this->actingAs($workspace->owner)->postJson($url.'/resolve', ['service' => 'shop', 'repo' => $repo, 'ref' => $ref])->assertUnprocessable();
    expect(sentKeyJobs())->toBe([]);
})->with([
    'an option' => ['--upload-pack=touch /tmp/x', null],
    'a local path' => ['/srv/repo.git', null],
    'a file URL' => ['file:///srv/repo.git', null],
    'an absolute SSH path' => ['git@example.com:/srv/repo.git', null],
    'a parent folder' => ['git@example.com:acme/../../etc.git', null],
    'a ref option' => ['git@gitlab.com:acme/shop.git', '--output=/tmp/x'],
    'a ref with ..' => ['git@gitlab.com:acme/shop.git', 'main..dev'],
]);

test('an older daemon is told to update before looking up a branch', function () {
    [$workspace, , $url] = keyMachine('0.1.1-pre.44');

    $this->actingAs($workspace->owner)->postJson($url.'/resolve', ['service' => 'shop', 'repo' => 'ssh://git@git.example.com:2222/acme/shop.git'])
        ->assertUnprocessable()->assertJsonValidationErrors(['repo' => 'too old']);
});

test('a failed lookup says why', function () {
    [$workspace, , $url] = keyMachine();
    $answer = $this->actingAs($workspace->owner)->postJson($url.'/resolve', ['service' => 'shop', 'repo' => 'git@gitlab.com:acme/shop.git'])->json();
    DaemonJob::query()->findOrFail($answer['jobId'])->forceFill(['state' => 'failed', 'error' => ['message' => 'git ls-remote exited 128', 'next_step' => 'Add the machine’s deploy key to the repository (Settings → Deploy keys).']])->save();

    $this->actingAs($workspace->owner)->getJson($url.'/jobs/'.$answer['jobId'])
        ->assertJson(['state' => 'failed', 'error' => 'git ls-remote exited 128', 'nextStep' => 'Add the machine’s deploy key to the repository (Settings → Deploy keys).']);
});
