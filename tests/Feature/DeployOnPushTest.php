<?php

use App\Enums\Plan;
use App\Mcp\Tools\DeployTools;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\DeployOnPush;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('services.github_app.webhook_secret', 'hook-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202), '*' => Http::response('ok')]);
    $this->withoutDefer();
});

/** @return array{0: Workspace, 1: Machine, 2: string} */
function pushDeployApp(string $repo = 'https://github.com/acme/shop'): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $workspace->githubInstallations()->create(['installation_id' => 42, 'account_login' => 'acme', 'account_type' => 'Organization']);
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'user_id' => $workspace->owner_id,
        'kind' => 'deploy',
        'service' => 'shop',
        'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => $repo, 'commit' => str_repeat('1', 40), 'port' => 3000, 'env' => ['APP_ENV' => 'production'], 'domains' => ['shop.example.com']]],
    ]);
    $job->forceFill(['created_at' => now()->subDays(2)])->save();

    return [$workspace, $machine, 'https://'.$workspace->host()];
}

function pushTo(string $branch = 'main', string $commit = '', string $repo = 'acme/shop'): TestResponse
{
    $body = json_encode([
        'ref' => 'refs/heads/'.$branch,
        'after' => $commit ?: str_repeat('a', 40),
        'deleted' => false,
        'repository' => ['full_name' => $repo],
        'installation' => ['id' => 42],
        'head_commit' => ['message' => 'Fix the cart'],
        'pusher' => ['name' => 'liam'],
    ]);

    return test()->call('POST', '/api/github/webhook', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITHUB_EVENT' => 'push',
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'hook-secret'),
    ], $body);
}

function pushDeploys(Machine $machine): Collection
{
    return DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->where('state', '!=', 'succeeded')->get();
}

test('turning on deploy each push deploys the pushed commit with the app’s settings', function () {
    [$workspace, $machine, $base] = pushDeployApp();

    pushTo()->assertStatus(202)->assertJson(['deploys' => 0]);
    expect(pushDeploys($machine))->toHaveCount(0);

    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/settings", ['deploy_on_push' => true, 'push_branch' => 'main'])
        ->assertOk()->assertJsonPath('deployOnPush', ['on' => true, 'branch' => 'main', 'onlyNewest' => true]);
    expect(ActivityLine::describe(Activity::query()->where('action', 'app.settings')->sole(), false)['verb'])->toBe('turned on deploying each push for');

    pushTo()->assertStatus(202)->assertJson(['deploys' => 1]);
    $job = pushDeploys($machine)->sole();
    expect($job->user_id)->toBe($workspace->owner_id)
        ->and($job->command['job'])->commit->toBe(str_repeat('a', 40))->port->toBe(3000)
        ->and($job->command['job']['env'])->toBe(['APP_ENV' => 'production'])
        ->and($job->command['job']['domains'])->toBe(['shop.example.com']);

    // GitHub retrying the same delivery doesn't deploy it twice.
    pushTo()->assertJson(['deploys' => 0, 'deploysSkipped' => 1]);
    expect(pushDeploys($machine))->toHaveCount(1);
});

test('only the chosen branch of the app’s own repository deploys', function () {
    [$workspace, $machine, $base] = pushDeployApp();
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/settings", ['deploy_on_push' => true, 'push_branch' => 'release/v2'])->assertOk();

    pushTo('main')->assertJson(['deploys' => 0]);
    pushTo('release/v2', repo: 'acme/other')->assertJson(['deploys' => 0]);
    pushTo('release/v2')->assertJson(['deploys' => 1]);

    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/settings", ['deploy_on_push' => false])->assertOk();
    pushTo('release/v2', str_repeat('c', 40))->assertJson(['deploys' => 0]);
});

test('deploy each push needs a GitHub repository and a real branch name', function () {
    [$workspace, $machine, $base] = pushDeployApp('https://gitlab.com/acme/shop');

    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/settings", ['deploy_on_push' => true])
        ->assertStatus(422)->assertJsonValidationErrors('deploy_on_push');

    [$workspace, $machine, $base] = pushDeployApp();
    foreach (['', '../main', 'a..b', '/main', 'main/'] as $bad) {
        $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/settings", ['push_branch' => $bad])
            ->assertStatus(422);
    }
});

test('a push that can’t deploy says why in the activity', function () {
    [$workspace, $machine, $base] = pushDeployApp();
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/settings", ['deploy_on_push' => true])->assertOk();
    config()->set('daemon.commands_enabled', false);

    pushTo()->assertJson(['deploys' => 0, 'deploysSkipped' => 1]);

    $skipped = Activity::query()->where('action', 'app.push_skipped')->sole();
    expect($skipped->subject)->toBe('shop')->and($skipped->meta['commit'])->toBe('aaaaaaa')
        ->and(ActivityLine::describe($skipped, false)['verb'])->toBe('didn’t deploy a push to');
});

function sentToMachine(DaemonJob $job): bool
{
    return Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/commands') && $r['id'] === $job->id)->isNotEmpty();
}

function pushJob(Machine $machine, string $commit): DaemonJob
{
    return DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->get()
        ->sole(fn (DaemonJob $job) => ($job->command['job']['commit'] ?? null) === $commit);
}

function finishDeploy(Machine $machine, DaemonJob $job): void
{
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, 'state' => 'succeeded']]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
}

test('pushes that come in while the app deploys wait, and only the newest of them is built', function () {
    [$workspace, $machine, $base] = pushDeployApp();
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/settings", ['deploy_on_push' => true])->assertOk();
    [$a, $b, $c] = [str_repeat('a', 40), str_repeat('b', 40), str_repeat('c', 40)];

    pushTo(commit: $a)->assertJson(['deploys' => 1]);
    $first = pushJob($machine, $a);
    expect(sentToMachine($first))->toBeTrue()->and($first->trigger)->toBe('push')->and($first->held)->toBeFalse();

    pushTo(commit: $b)->assertJson(['deploys' => 1]);
    $second = pushJob($machine, $b);
    expect($second)->state->toBe('queued')->held->toBeTrue()->phase->toBe('waiting')
        ->and($second->isStale())->toBeFalse()
        ->and(sentToMachine($second))->toBeFalse();

    pushTo(commit: $c)->assertJson(['deploys' => 1]);
    $second->refresh();
    $third = pushJob($machine, $c);
    expect($second)->state->toBe('cancelled')->held->toBeFalse()
        ->and($second->error['message'])->toBe('A newer push replaced this deploy.')
        ->and($third)->held->toBeTrue()
        ->and(sentToMachine($third))->toBeFalse();
    $replaced = Activity::query()->where('action', 'app.push_replaced')->sole();
    expect($replaced->meta)->toMatchArray(['commit' => 'bbbbbbb', 'newer' => 'ccccccc'])
        ->and(ActivityLine::describe($replaced, false)['verb'])->toBe('skipped an older push to');

    // The deploy that was building finishes: the newest push goes to the machine.
    finishDeploy($machine, $first);
    $third->refresh();
    expect($third)->held->toBeFalse()->state->toBe('queued')->phase->toBeNull()
        ->and(sentToMachine($third))->toBeTrue();
});

test('people, AI apps and rollbacks are never replaced, and a push waits for them too', function () {
    [$workspace, $machine, $base] = pushDeployApp();
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/settings", ['deploy_on_push' => true])->assertOk();
    $manual = app(DaemonJobQueue::class)->deploy($machine, $workspace->owner, ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('d', 40)]);
    $rollback = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'rollback', 'service' => 'shop', 'state' => 'running', 'command' => ['job' => ['type' => 'rollback']]]);

    pushTo(commit: str_repeat('a', 40));
    pushTo(commit: str_repeat('b', 40));

    expect($manual->fresh()->state)->toBe('queued')
        ->and($rollback->fresh()->state)->toBe('running')
        ->and(pushJob($machine, str_repeat('a', 40))->state)->toBe('cancelled')
        ->and(pushJob($machine, str_repeat('b', 40))->held)->toBeTrue();

    // Still deploying: the rollback hasn't finished.
    finishDeploy($machine, $manual);
    expect(pushJob($machine, str_repeat('b', 40))->held)->toBeTrue();

    finishDeploy($machine, $rollback);
    expect(pushJob($machine, str_repeat('b', 40))->held)->toBeFalse();
});

test('with only the newest push off, every push deploys straight away', function () {
    [$workspace, $machine, $base] = pushDeployApp();
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/settings", ['deploy_on_push' => true, 'push_only_newest' => false])
        ->assertOk()->assertJsonPath('deployOnPush.onlyNewest', false);

    pushTo(commit: str_repeat('a', 40));
    pushTo(commit: str_repeat('b', 40));

    expect(pushDeploys($machine))->toHaveCount(2)
        ->and(pushDeploys($machine)->every(fn (DaemonJob $job) => ! $job->held && sentToMachine($job)))->toBeTrue();
});

test('a waiting push is sent once the deploy before it goes quiet, or when someone stops waiting on it', function () {
    [$workspace, $machine, $base] = pushDeployApp();
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/settings", ['deploy_on_push' => true])->assertOk();

    pushTo(commit: str_repeat('a', 40));
    pushTo(commit: str_repeat('b', 40));
    $first = pushJob($machine, str_repeat('a', 40));

    $this->artisan('deploys:release-waiting')->assertSuccessful();
    expect(pushJob($machine, str_repeat('b', 40))->held)->toBeTrue();

    $first->forceFill(['updated_at' => now()->subMinutes(DaemonJob::STALE_AFTER_MINUTES + 1)])->saveQuietly();
    $this->artisan('deploys:release-waiting')->expectsOutput('Sent 1 waiting deploy(s).')->assertSuccessful();
    expect(pushJob($machine, str_repeat('b', 40))->held)->toBeFalse();

    // Stopping waiting on the one building sends the next.
    pushTo(commit: str_repeat('c', 40));
    expect(pushJob($machine, str_repeat('c', 40))->held)->toBeTrue();
    $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/jobs/".pushJob($machine, str_repeat('b', 40))->id.'/abandon')->assertOk();
    expect(pushJob($machine, str_repeat('c', 40))->held)->toBeFalse();
});

test('a waiting push can be cancelled before it’s sent', function () {
    [$workspace, $machine, $base] = pushDeployApp();
    $this->actingAs($workspace->owner)->putJson($base."/machines/{$machine->id}/apps/shop/settings", ['deploy_on_push' => true])->assertOk();
    pushTo(commit: str_repeat('a', 40));
    pushTo(commit: str_repeat('b', 40));
    $waiting = pushJob($machine, str_repeat('b', 40));

    $this->actingAs($workspace->owner)->postJson($base."/machines/{$machine->id}/jobs/{$waiting->id}/abandon")->assertOk();

    expect($waiting->fresh())->state->toBe('cancelled')->held->toBeFalse()
        ->and($waiting->fresh()->error['message'])->toBe('Cancelled before it was sent to the machine.')
        ->and(sentToMachine($waiting))->toBeFalse();
    expect(app(DeployOnPush::class)->release($machine, 'shop'))->toBeNull();
});

test('MCP turns only the newest push off and on without deploying', function () {
    config()->set('mcp.groups', [DeployTools::class]);
    [$workspace, $machine] = pushDeployApp();
    $key = mcpKey($workspace);

    $off = mcpCall($workspace, $key, 'update_app_settings', ['machine' => 'web-1', 'app' => 'shop', 'only_newest_push' => false]);
    expect($off['error'])->toBeFalse($off['text'])
        ->and($off['data']['deployOnPush']['onlyNewest'])->toBeFalse()
        ->and($off['data']['note'])->toBe('Saved. Nothing was deployed.')
        ->and(DeployOnPush::setting($machine->fresh(), 'shop')['onlyNewest'])->toBeFalse()
        ->and(pushDeploys($machine))->toHaveCount(0);
    expect(ActivityLine::describe(Activity::query()->where('action', 'app.settings')->sole(), false)['verb'])->toBe('set every push to build for');

    mcpCall($workspace, $key, 'update_app_settings', ['machine' => 'web-1', 'app' => 'shop', 'only_newest_push' => true]);
    expect(DeployOnPush::setting($machine->fresh(), 'shop')['onlyNewest'])->toBeTrue()
        ->and(mcpCall($workspace, $key, 'get_app', ['machine' => 'web-1', 'app' => 'shop'])['data']['deployOnPush'])->toMatchArray(['onlyNewest' => true]);
});
