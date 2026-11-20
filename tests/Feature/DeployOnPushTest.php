<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
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
        ->assertOk()->assertJsonPath('deployOnPush', ['on' => true, 'branch' => 'main']);
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
