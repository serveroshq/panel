<?php

use App\Models\Activity;
use App\Models\AppDeployHook;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

/** @return array{0: Workspace, 1: Machine, 2: string} the app and a fresh hook address */
function hookedApp(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('services.github.token', null);
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'gitlab.com/*' => Http::response(null, 404),
        'api.github.com/repos/acme/shop/commits/main' => Http::response(['sha' => str_repeat('7', 40)]),
        '*' => Http::response(null, 404),
    ]);
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => '0.1.1-pre.51'])->save();
    $live = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('1', 40), 'port' => 3000, 'env' => ['A' => '1'], 'domains' => ['shop.example.com']]],
    ]);
    $live->forceFill(['created_at' => now()->subDays(2)])->save();

    $url = test()->actingAs($workspace->owner)->postJson($workspace->url()."/machines/{$machine->id}/apps/shop/hook")
        ->assertCreated()->json('url');

    return [$workspace, $machine, (string) $url];
}

function hookDeploys(Machine $machine): int
{
    return DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->where('state', '!=', 'succeeded')->count();
}

/** @param  array<string, string>  $headers */
function postHook(string $url, array $body = [], array $headers = []): TestResponse
{
    return test()->postJson(str_replace(config('app.url'), '', $url), $body, $headers);
}

test('a deploy hook is shown once, kept only as a hash, and deploys the app’s latest commit', function () {
    [$workspace, $machine, $url] = hookedApp();

    expect($url)->toMatch('#/api/hooks/deploy/dh_[A-Za-z0-9]{40}$#');
    $hook = AppDeployHook::query()->sole();
    expect($hook->token_hash)->toBe(hash('sha256', Str::afterLast($url, '/')))
        ->and(json_encode($hook->toArray()))->not->toContain(Str::afterLast($url, '/'));

    postHook($url)->assertAccepted()->assertJson(['deployed' => true, 'commit' => str_repeat('7', 40)]);
    $job = DaemonJob::query()->where('state', 'queued')->sole();
    expect($job)->trigger->toBe('hook')->user_id->toBe($workspace->owner_id)
        ->and($job->command['job']['env']['A'])->toBe('1');
    expect(ActivityLine::describe(Activity::query()->where('action', 'service.deploy')->sole(), false)['after'])->toBe('from its deploy hook');
    expect($hook->fresh()->last_trigger)->toBe('hook');

    // The page shows that there is one, never the address.
    $this->actingAs($workspace->owner)->putJson($workspace->url()."/machines/{$machine->id}/apps/shop/env", ['set' => ['B' => '2']]);
    expect(json_encode(Activity::query()->get()))->not->toContain(Str::afterLast($url, '/'));
});

test('pushes from GitHub, GitLab, Gitea and Bitbucket deploy only the app’s branch', function (array $headers, array $push, array $other) {
    [, $machine, $url] = hookedApp();

    postHook($url, $other, $headers)->assertOk()->assertJson(['ignored' => true]);
    expect(hookDeploys($machine))->toBe(0);

    postHook($url, $push, $headers)->assertAccepted()->assertJson(['deployed' => true, 'commit' => str_repeat('b', 40)]);
    expect(hookDeploys($machine))->toBe(1);

    // The same commit again isn't deployed twice.
    postHook($url, $push, $headers)->assertOk()->assertJson(['ignored' => true]);
})->with([
    'GitHub' => [['X-GitHub-Event' => 'push'], ['ref' => 'refs/heads/main', 'after' => str_repeat('b', 40)], ['ref' => 'refs/heads/dev', 'after' => str_repeat('c', 40)]],
    'GitLab' => [['X-Gitlab-Event' => 'Push Hook'], ['object_kind' => 'push', 'ref' => 'refs/heads/main', 'after' => str_repeat('b', 40)], ['object_kind' => 'push', 'ref' => 'refs/tags/v1', 'after' => str_repeat('c', 40)]],
    'Forgejo' => [['X-Forgejo-Event' => 'push', 'X-GitHub-Event' => 'push'], ['ref' => 'refs/heads/main', 'after' => str_repeat('b', 40)], ['ref' => 'refs/heads/main', 'after' => str_repeat('0', 40), 'deleted' => true]],
    'Bitbucket' => [['X-Event-Key' => 'repo:push'], ['push' => ['changes' => [['new' => ['type' => 'branch', 'name' => 'main', 'target' => ['hash' => str_repeat('b', 40)]]]]]], ['push' => ['changes' => [['new' => ['type' => 'branch', 'name' => 'dev', 'target' => ['hash' => str_repeat('c', 40)]]]]]]],
]);

test('a GitHub ping and a plain post naming another branch do nothing', function () {
    [, $machine, $url] = hookedApp();

    postHook($url, ['zen' => 'Hi'], ['X-GitHub-Event' => 'ping'])->assertOk()->assertJson(['ignored' => true, 'reason' => 'ping']);
    postHook($url, ['branch' => 'dev'])->assertOk()->assertJson(['ignored' => true]);
    postHook($url, ['commit' => str_repeat('d', 40)])->assertAccepted()->assertJson(['commit' => str_repeat('d', 40)]);
    expect(hookDeploys($machine))->toBe(1);
});

test('a push that comes in while the app deploys waits, as GitHub pushes do', function () {
    [, $machine, $url] = hookedApp();

    postHook($url, ['ref' => 'refs/heads/main', 'after' => str_repeat('b', 40)], ['X-Gitlab-Event' => 'Push Hook', 'Content-Type' => 'application/json'])->assertStatus(200);
    postHook($url, ['object_kind' => 'push', 'ref' => 'refs/heads/main', 'after' => str_repeat('b', 40)], ['X-Gitlab-Event' => 'Push Hook'])->assertAccepted();
    postHook($url, ['object_kind' => 'push', 'ref' => 'refs/heads/main', 'after' => str_repeat('c', 40)], ['X-Gitlab-Event' => 'Push Hook'])->assertAccepted()->assertJson(['waiting' => true]);

    expect(DaemonJob::query()->where('trigger', 'hook:gitlab')->where('held', true)->count())->toBe(1);
});

test('a new address replaces the old one, a revoked one stops working, and a busy hook is slowed down', function () {
    [$workspace, $machine, $url] = hookedApp();
    $base = $workspace->url()."/machines/{$machine->id}/apps/shop/hook";

    $newer = $this->actingAs($workspace->owner)->postJson($base)->assertCreated()->json('url');
    postHook($url)->assertNotFound();
    expect(Activity::query()->where('action', 'app.hook')->latest('id')->first()->meta)->toMatchArray(['regenerated' => true]);

    for ($i = 0; $i < 10; $i++) {
        postHook($newer, ['branch' => 'dev']);
    }
    postHook($newer, ['branch' => 'dev'])->assertStatus(429);

    $this->actingAs($workspace->owner)->deleteJson($base)->assertOk()->assertJsonPath('hook.exists', false);
    postHook($newer)->assertNotFound();
    expect(AppDeployHook::query()->count())->toBe(0);
});
