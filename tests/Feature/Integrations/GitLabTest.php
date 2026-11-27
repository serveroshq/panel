<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\GitLabProjectHook;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\CommitMessages;
use App\Support\Monitors\HostResolver;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeGitLab;

beforeEach(function () {
    Sleep::fake();
    config()->set('app.url', 'https://panel.example.com');
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $this->withoutDefer();

    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            $bare = trim($host, '[]');
            if (filter_var($bare, FILTER_VALIDATE_IP) !== false) {
                return $this->allowed($bare) ? [$bare] : [];
            }

            return str_ends_with($bare, '.internal') || $bare === 'localhost' ? [] : ['203.0.113.10'];
        }
    });
});

/** @param  array<string, string>  $extra */
function glConnect(Workspace $workspace, string $token = FakeGitLab::TOKEN, ?string $url = 'https://gitlab.com', ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $workspace->owner)
        ->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/gitlab'), ['credentials' => ['token' => $token, 'url' => $url]]);
}

function glAccount(Workspace $workspace, string $token = FakeGitLab::TOKEN, string $url = 'https://gitlab.com'): Integration
{
    return $workspace->integrations()->create([
        'provider' => 'gitlab',
        'label' => 'liam',
        'credentials' => ['token' => $token, 'url' => $url],
        'fingerprint' => Integration::fingerprintOf('gitlab', ['token' => $token, 'url' => $url]),
        'meta' => ['username' => 'liam', 'instance' => $url],
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
        'created_by' => $workspace->owner_id,
    ]);
}

function glMember(Workspace $workspace, string $role): User
{
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => $role]);

    return $user;
}

/** @return array{0: Machine, 1: DaemonJob} */
function glApp(Workspace $workspace, string $repo = 'https://gitlab.com/acme/shop.git', string $app = 'shop'): array
{
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => '0.1.1-pre.10',
        'inventory' => ['services' => [['key' => 'docker:abc', 'name' => "serveros-{$app}-111111111111", 'kind' => 'container']]],
    ])->save();
    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'user_id' => $workspace->owner_id,
        'kind' => 'deploy',
        'service' => $app,
        'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => $app, 'repo' => $repo, 'commit' => str_repeat('1', 40), 'port' => 3000, 'env' => ['APP_ENV' => 'production'], 'domains' => ['shop.example.com']]],
    ]);
    $job->forceFill(['created_at' => now()->subDays(2)])->save();

    return [$machine, $job];
}

function glPush(GitLabProjectHook $hook, ?string $token = null, string $branch = 'main', string $after = '', ?int $project = null): TestResponse
{
    $body = json_encode([
        'object_kind' => 'push',
        'ref' => 'refs/heads/'.$branch,
        'after' => $after ?: str_repeat('a', 40),
        'user_username' => 'liam',
        'project_id' => $project ?? $hook->project_id,
        'project' => ['id' => $project ?? $hook->project_id, 'path_with_namespace' => $hook->project],
        'commits' => [['id' => $after ?: str_repeat('a', 40), 'title' => 'Fix the cart']],
    ]);

    return test()->call('POST', '/api/gitlab/webhook/'.$hook->uuid, [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_GITLAB_EVENT' => 'Push Hook',
        'HTTP_X_GITLAB_TOKEN' => $token ?? $hook->secret,
    ], $body);
}

function glGatewayCommands(): array
{
    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (HttpRequest $r) => str_ends_with($r->url(), '/commands'))
        ->map(fn (HttpRequest $r) => $r['command']['job'] ?? [])
        ->values()
        ->all();
}

test('connecting gitlab.com checks the token with GitLab and keeps it encrypted', function () {
    $gitlab = FakeGitLab::install();
    $workspace = Workspace::factory()->create();

    glConnect($workspace)->assertSessionHasNoErrors()->assertRedirect();

    $integration = Integration::query()->sole();
    expect($integration->label)->toBe('liam')
        ->and($integration->credential('token'))->toBe(FakeGitLab::TOKEN)
        ->and(DB::table('integrations')->value('credentials'))->not->toContain(FakeGitLab::TOKEN)
        ->and($integration->meta)->toBe(['username' => 'liam', 'instance' => 'https://gitlab.com', 'scopes' => 'read_api, read_repository', 'hooks' => false])
        ->and($gitlab->called('GET'))->toBe(['GET user', 'GET personal_access_tokens/self'])
        ->and(collect($gitlab->calls)->pluck('host')->unique()->all())->toBe(['gitlab.com']);
    Http::assertSent(fn (HttpRequest $r) => $r->hasHeader('PRIVATE-TOKEN', FakeGitLab::TOKEN) && str_starts_with($r->url(), 'https://gitlab.com/api/v4/'));

    $activity = Activity::query()->where('action', 'integration.connect')->sole();
    expect(ActivityLine::sentence($activity))->toBe($workspace->owner->name.' connected liam (GitLab)')
        ->and(json_encode($activity->meta))->not->toContain(FakeGitLab::TOKEN);

    $page = $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))->assertOk();
    $deferred = null;
    $page->assertInertia(function (Assert $p) use (&$deferred) {
        $p->where('integration.summary', '@liam on gitlab.com')
            ->where('provider.capabilities.0.key', 'repositories')
            ->where('provider.fields.1.value', 'https://gitlab.com')
            ->where('provider.fields.0.value', null)
            ->loadDeferredProps(function (Assert $reload) use (&$deferred) {
                $deferred = $reload->toArray()['props'];
            });
    });
    expect($deferred['projects'])->toBe([])
        ->and(json_encode($page->viewData('page')['props']).json_encode($deferred))->not->toContain(FakeGitLab::TOKEN);
});

test('a self-managed GitLab is connected at its own address', function () {
    $gitlab = FakeGitLab::install();
    $workspace = Workspace::factory()->create();
    $gitlab->scopes = ['api'];

    glConnect($workspace, url: 'gitlab.example.com/')->assertSessionHasNoErrors();

    $integration = Integration::query()->sole();
    expect($integration->label)->toBe('liam on gitlab.example.com')
        ->and($integration->meta['instance'])->toBe('https://gitlab.example.com')
        ->and($integration->meta['hooks'])->toBeTrue()
        ->and(collect($gitlab->calls)->pluck('host')->unique()->all())->toBe(['gitlab.example.com']);
});

test('a GitLab address in a private network, or one that isn’t https, is refused before anything is sent', function (string $url) {
    FakeGitLab::install();
    $workspace = Workspace::factory()->create();

    glConnect($workspace, url: $url)->assertSessionHasErrors('credentials.token');

    expect(Integration::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'plain http' => ['http://gitlab.example.com'],
    'loopback' => ['https://127.0.0.1'],
    'a private address' => ['https://10.1.2.3'],
    'link local' => ['https://169.254.169.254'],
    'a name that points inside' => ['https://gitlab.internal'],
    'localhost' => ['https://localhost'],
    'a password in it' => ['https://user:secret@gitlab.com'],
    'not an address' => ['https://exa mple.com'],
]);

test('a token GitLab turns down, or one that can’t read code, is never saved', function () {
    $gitlab = FakeGitLab::install();
    $workspace = Workspace::factory()->create();

    glConnect($workspace, 'glpat-wrong-token-0123456789abcdef')->assertSessionHasErrors(['credentials.token' => 'GitLab turned the token down. Check it’s copied in full and hasn’t been deleted.']);

    $gitlab->scopes = ['read_api'];
    glConnect($workspace)->assertSessionHasErrors(['credentials.token' => 'This token can’t read code. Make one with the read_api and read_repository scopes.']);

    expect(Integration::query()->count())->toBe(0);
});

test('only people who manage machines connect GitLab or list its projects', function () {
    FakeGitLab::install()->project(11, 'acme/shop');
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    glAccount($workspace);
    $viewer = glMember($workspace, 'viewer');

    glConnect($workspace, as: $viewer)->assertForbidden();
    $this->actingAs($viewer)->getJson($workspace->url('/gitlab/repositories'))->assertForbidden();
    $this->actingAs($viewer)->getJson($workspace->url('/gitlab/branches?repo=https://gitlab.com/acme/shop'))->assertForbidden();
});

test('the deploy dialog lists the projects of every GitLab account, newest first', function () {
    FakeGitLab::install()
        ->project(11, 'acme/shop', overrides: ['last_activity_at' => '2026-11-20T10:00:00Z', 'visibility' => 'private'])
        ->project(12, 'acme/tools/docs', branches: ['trunk' => str_repeat('2', 40)], overrides: ['last_activity_at' => '2026-11-25T10:00:00Z', 'visibility' => 'internal'])
        ->project(13, 'acme/empty', overrides: ['empty_repo' => true]);
    $workspace = Workspace::factory()->create();
    $account = glAccount($workspace);

    $body = $this->actingAs($workspace->owner)->getJson($workspace->url('/gitlab/repositories'))->assertOk()->json();

    expect($body['connected'])->toBeTrue()
        ->and($body['accounts'])->toBe([['id' => $account->id, 'login' => 'liam', 'label' => 'liam', 'host' => 'gitlab.com', 'connectedBy' => $workspace->owner->name, 'error' => null]])
        ->and(array_column($body['repositories'], 'name'))->toBe(['acme/tools/docs', 'acme/shop'])
        ->and($body['repositories'][0])->toMatchArray(['url' => 'https://gitlab.com/acme/tools/docs', 'private' => true, 'visibility' => 'internal', 'branch' => 'trunk', 'provider' => 'gitlab', 'account' => $account->id]);
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), '/api/v4/projects?') && str_contains($r->url(), 'membership=true') && str_contains($r->url(), 'min_access_level=20'));

    $this->actingAs($workspace->owner)->getJson($workspace->url('/gitlab/branches?repo='.urlencode('https://gitlab.com/acme/tools/docs.git')))
        ->assertOk()->assertJson(['branches' => ['trunk']]);
    $this->actingAs($workspace->owner)->getJson($workspace->url('/gitlab/branches?repo='.urlencode('https://gitlab.other.com/acme/shop')))->assertNotFound();
});

test('a GitLab account that stopped working shows why instead of its projects', function () {
    FakeGitLab::install();
    $workspace = Workspace::factory()->create();
    $account = glAccount($workspace, 'glpat-revoked-token-0123456789abcdef');

    $body = $this->actingAs($workspace->owner)->getJson($workspace->url('/gitlab/repositories'))->assertOk()->json();

    expect($body['repositories'])->toBe([])
        ->and($body['accounts'][0]['error'])->toContain('turned the token down')
        ->and($account->fresh()->status)->toBe(Integration::FAILING);
});

test('a private GitLab project is detected through the account, on a branch and in a folder', function () {
    FakeGitLab::install()->project(11, 'acme/shop', [
        'composer.json' => json_encode(['require' => ['php' => '^8.3', 'laravel/framework' => '^12.0']]),
        'artisan' => '',
        'web/package.json' => json_encode(['dependencies' => ['next' => '15.0.0']]),
    ], ['main' => str_repeat('1', 40), 'release/v2' => str_repeat('2', 40)]);
    $workspace = Workspace::factory()->create();
    glAccount($workspace);

    $found = $this->actingAs($workspace->owner)->postJson($workspace->url('/services/detect'), ['repository' => 'https://gitlab.com/acme/shop'])->assertOk()->json();
    expect($found)->toMatchArray(['detected' => true, 'template' => 'laravel', 'repo' => 'https://gitlab.com/acme/shop.git', 'branch' => 'main', 'commit' => str_repeat('1', 40), 'baseDir' => null]);

    $folder = $this->actingAs($workspace->owner)->postJson($workspace->url('/services/detect'), ['repository' => 'https://gitlab.com/acme/shop/-/tree/release/v2/web'])->assertOk()->json();
    expect($folder)->toMatchArray(['template' => 'nextjs', 'branch' => 'release/v2', 'commit' => str_repeat('2', 40), 'baseDir' => 'web']);

    // The dialog's branch picker builds GitHub-style addresses; they work too.
    $this->actingAs($workspace->owner)->postJson($workspace->url('/services/detect'), ['repository' => 'https://gitlab.com/acme/shop/tree/release/v2'])
        ->assertOk()->assertJsonPath('branch', 'release/v2');
});

test('a public gitlab.com project is detected without an account, and a private one says to connect GitLab', function () {
    $gitlab = FakeGitLab::install()
        ->project(21, 'oss/site', ['index.html' => '<h1>hi</h1>'], overrides: ['visibility' => 'public'])
        ->project(22, 'acme/secret', ['index.html' => '<h1>hi</h1>']);
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->postJson($workspace->url('/services/detect'), ['repository' => 'https://gitlab.com/oss/site'])
        ->assertOk()->assertJsonPath('template', 'static-site')->assertJsonPath('repo', 'https://gitlab.com/oss/site.git');
    $this->actingAs($workspace->owner)->postJson($workspace->url('/services/detect'), ['repository' => 'https://gitlab.com/acme/secret'])
        ->assertUnprocessable()->assertJsonPath('errors.repository.0', 'That project doesn’t exist, or it’s private. To deploy private GitLab projects, connect GitLab in Integrations.');

    expect(collect($gitlab->calls)->pluck('token')->filter()->all())->toBe([]);
});

test('a GitLab deploy clones with the account’s token, sent with the job and never stored', function () {
    FakeGitLab::install();
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $workspace = Workspace::factory()->create();
    glAccount($workspace, url: 'https://gitlab.example.com');
    [$machine] = glApp($workspace);

    $job = app(DaemonJobQueue::class)->deploy($machine, $workspace->owner, ['type' => 'deploy', 'service' => 'api', 'repo' => 'https://gitlab.example.com/acme/api.git', 'commit' => str_repeat('b', 40)]);
    app(DaemonJobQueue::class)->deploy($machine, $workspace->owner, ['type' => 'deploy', 'service' => 'web', 'repo' => 'https://gitlab.com/acme/web.git', 'commit' => str_repeat('c', 40)]);
    app(DaemonJobQueue::class)->deploy($machine, $workspace->owner, ['type' => 'deploy', 'service' => 'evil', 'repo' => 'https://gitlab.example.com.evil.test/acme/api.git', 'commit' => str_repeat('d', 40)]);

    $sent = collect(glGatewayCommands())->keyBy('service');
    expect($sent['api']['repo_token'] ?? null)->toBe(FakeGitLab::TOKEN)
        ->and($sent['web'])->not->toHaveKey('repo_token')
        ->and($sent['evil'])->not->toHaveKey('repo_token')
        ->and(DaemonJob::query()->findOrFail($job->id)->command['job'])->not->toHaveKey('repo_token')
        ->and(json_encode(DaemonJob::query()->pluck('command')))->not->toContain(FakeGitLab::TOKEN);
});

test('deploying each push adds a webhook to the project, deploys pushes to the branch, and takes the webhook off again', function () {
    $gitlab = FakeGitLab::install()->project(11, 'acme/shop');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    glAccount($workspace, FakeGitLab::HOOKS_TOKEN);
    [$machine] = glApp($workspace);
    $settings = $workspace->url("/machines/{$machine->id}/apps/shop/settings");

    $this->actingAs($workspace->owner)->putJson($settings, ['deploy_on_push' => true, 'push_branch' => 'main'])
        ->assertOk()->assertJsonPath('deployOnPush.on', true);

    $hook = GitLabProjectHook::query()->sole();
    $made = $gitlab->bodyOf('POST', 'projects/acme%2Fshop/hooks');
    expect($made)->toMatchArray(['url' => 'https://panel.example.com/api/gitlab/webhook/'.$hook->uuid, 'token' => $hook->secret, 'push_events' => true, 'merge_requests_events' => false, 'enable_ssl_verification' => true])
        ->and($hook->project_id)->toBe(11)
        ->and(DB::table('gitlab_project_hooks')->value('secret'))->not->toBe($hook->secret);

    glPush($hook, 'wrong-secret')->assertUnauthorized();
    $this->postJson('/api/gitlab/webhook/'.Str::uuid(), [])->assertUnauthorized();
    glPush($hook, branch: 'dev')->assertStatus(202)->assertJson(['deploys' => 0]);
    glPush($hook, after: str_repeat('0', 40))->assertOk()->assertJson(['ignored' => true]);
    glPush($hook, project: 99)->assertOk()->assertJson(['ignored' => true]);

    glPush($hook)->assertStatus(202)->assertJson(['deploys' => 1]);
    $deploy = DaemonJob::query()->where('machine_id', $machine->id)->where('state', '!=', 'succeeded')->sole();
    expect($deploy->trigger)->toBe('push')
        ->and($deploy->command['job'])->commit->toBe(str_repeat('a', 40))->repo->toBe('https://gitlab.com/acme/shop.git')
        ->and($deploy->command['job']['env'])->toBe(['APP_ENV' => 'production'])
        ->and($hook->fresh()->last_push_at)->not->toBeNull();
    glPush($hook)->assertJson(['deploys' => 0, 'deploysSkipped' => 1]);

    $this->actingAs($workspace->owner)->putJson($settings, ['deploy_on_push' => false])->assertOk();
    expect(GitLabProjectHook::query()->count())->toBe(0)
        ->and($gitlab->hooks)->toBe([])
        ->and($gitlab->called('DELETE'))->toBe(['DELETE projects/acme%2Fshop/hooks/'.$hook->hook_id]);
    glPush($hook)->assertUnauthorized();
});

test('a token that can only read can’t turn on deploying each push, and nothing is saved', function () {
    FakeGitLab::install()->project(11, 'acme/shop');
    $workspace = Workspace::factory()->create();
    glAccount($workspace);
    [$machine] = glApp($workspace);

    $this->actingAs($workspace->owner)->putJson($workspace->url("/machines/{$machine->id}/apps/shop/settings"), ['deploy_on_push' => true])
        ->assertStatus(422)
        ->assertJsonPath('errors.deploy_on_push.0', 'GitLab didn’t let this token add a webhook to acme/shop. It needs the api scope, from an account that’s a Maintainer of the project.');

    expect($machine->fresh()->app_settings)->toBeNull()
        ->and(GitLabProjectHook::query()->count())->toBe(0);
});

test('a GitLab app with no connected account can’t deploy each push', function () {
    FakeGitLab::install();
    $workspace = Workspace::factory()->create();
    [$machine] = glApp($workspace);

    $this->actingAs($workspace->owner)->putJson($workspace->url("/machines/{$machine->id}/apps/shop/settings"), ['deploy_on_push' => true])
        ->assertStatus(422)->assertJsonValidationErrors('deploy_on_push');
    Http::assertNothingSent();
});

test('removing the app stops it deploying each push and takes the webhook off', function () {
    $gitlab = FakeGitLab::install()->project(11, 'acme/shop');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $workspace = Workspace::factory()->create();
    glAccount($workspace, FakeGitLab::HOOKS_TOKEN);
    [$machine] = glApp($workspace);
    $this->actingAs($workspace->owner)->putJson($workspace->url("/machines/{$machine->id}/apps/shop/settings"), ['deploy_on_push' => true])->assertOk();
    expect($gitlab->hooks)->toHaveCount(1);

    $remove = app(DaemonJobQueue::class)->queue($machine->fresh(), $workspace->owner, 'service_remove', ['type' => 'service_remove', 'service' => 'docker:abc', 'delete_data' => false, 'delete_adopted_volumes' => false], 'docker:abc');
    $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $remove->id, 'state' => 'succeeded', 'log' => ['ok'], 'result' => []]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    expect($machine->fresh()->app_settings['shop']['deploy_on_push'])->toBeFalse()
        ->and($gitlab->hooks)->toBe([])
        ->and(GitLabProjectHook::query()->count())->toBe(0)
        ->and(ActivityLine::describe(Activity::query()->where('action', 'app.settings')->latest('id')->first(), false)['verb'])->toBe('turned off deploying each push for');
});

test('the service page shows commit messages and the deploy each push switch for a GitLab app', function () {
    $gitlab = FakeGitLab::install()->project(11, 'acme/shop')->title('acme/shop', str_repeat('1', 40), 'Ship the new cart');
    $workspace = Workspace::factory()->create();
    glAccount($workspace);

    expect(CommitMessages::for($workspace, 'https://gitlab.com/acme/shop.git', [str_repeat('1', 40), 'not-a-sha']))->toBe([str_repeat('1', 40) => 'Ship the new cart']);
    $calls = count($gitlab->calls);
    expect(CommitMessages::for($workspace, 'https://gitlab.com/acme/shop.git', [str_repeat('1', 40)]))->toBe([str_repeat('1', 40) => 'Ship the new cart'])
        ->and(count($gitlab->calls))->toBe($calls)
        ->and(CommitMessages::for($workspace, 'https://gitlab.other.com/acme/shop.git', [str_repeat('1', 40)]))->toBe([]);
});

test('the account page lists the projects apps are deployed from, with their webhook, and disconnecting takes the webhooks off', function () {
    $gitlab = FakeGitLab::install()->project(11, 'acme/shop');
    $workspace = Workspace::factory()->create();
    $account = glAccount($workspace, FakeGitLab::HOOKS_TOKEN);
    [$machine] = glApp($workspace);
    $this->actingAs($workspace->owner)->putJson($workspace->url("/machines/{$machine->id}/apps/shop/settings"), ['deploy_on_push' => true, 'push_branch' => 'main'])->assertOk();

    $projects = function () use ($workspace, $account): array {
        $deferred = null;
        $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$account->id))
            ->assertInertia(function (Assert $page) use (&$deferred) {
                $page->where('usage.0', '1 app is deployed from a project here.')
                    ->where('dependents.1', '1 app stops deploying each push, and ServerOS takes its webhook off GitLab.')
                    ->loadDeferredProps(function (Assert $reload) use (&$deferred) {
                        $deferred = $reload->toArray()['props'];
                    });
            });

        return $deferred['projects'];
    };

    $listed = $projects();
    expect($listed[0])->toMatchArray(['path' => 'acme/shop', 'url' => 'https://gitlab.com/acme/shop'])
        ->and($listed[0]['apps'][0])->toBe(['machine' => 'web-1', 'app' => 'shop', 'href' => "/machines/{$machine->id}/services/app:shop", 'push' => true, 'branch' => 'main'])
        ->and($listed[0]['hook']['state'])->toBe('active');

    $gitlab->hooks[array_key_first($gitlab->hooks)]['alert_status'] = 'disabled';
    expect($projects()[0]['hook']['state'])->toBe('disabled');

    $this->actingAs($workspace->owner)->delete($workspace->url('/integrations/'.$account->id), ['confirm' => 'liam'])
        ->assertRedirect()->assertInertiaFlash('toast.message', 'Disconnected liam and took ServerOS’s webhook off 1 project.');
    expect($gitlab->hooks)->toBe([])
        ->and(Integration::query()->count())->toBe(0)
        ->and(GitLabProjectHook::query()->count())->toBe(0);
});
