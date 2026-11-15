<?php

use App\Models\DaemonJob;
use App\Models\GitHubInstallation;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\DaemonJobQueue;
use App\Support\GitHub\GitHubApp;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config()->set('services.github_app', [
        'id' => '12345', 'slug' => 'serveros', 'client_id' => 'Iv1.abc', 'client_secret' => 'shh',
        'private_key' => base64_encode($pem),
    ]);
});

function connectedWorkspace(string $login = 'acme'): array
{
    $workspace = Workspace::factory()->create();
    $installation = $workspace->githubInstallations()->create(['installation_id' => 777, 'account_login' => $login, 'account_type' => 'Organization']);

    return [$workspace, $installation];
}

function workspaceUrl(Workspace $workspace, string $path): string
{
    return 'https://'.$workspace->host().$path;
}

test('a token is minted for just the one repository, signed as the app, and reused', function () {
    [$workspace] = connectedWorkspace();
    Http::fake(['api.github.com/app/installations/777/access_tokens' => Http::response(['token' => 'ghs_one'], 201)]);

    $github = app(GitHubApp::class);
    expect($github->tokenFor($workspace, 'ACME', 'secret-app'))->toBe('ghs_one')
        ->and($github->tokenFor($workspace, 'acme', 'secret-app'))->toBe('ghs_one')
        ->and($github->tokenFor($workspace, 'someone-else', 'secret-app'))->toBeNull();

    Http::assertSentCount(1);
    Http::assertSent(function (HttpRequest $request) {
        [$header, $payload] = explode('.', Str::after($request->header('Authorization')[0], 'Bearer '));

        return $request['repositories'] === ['secret-app']
            && $request['permissions'] === ['contents' => 'read', 'metadata' => 'read']
            && json_decode(base64_decode(strtr($header, '-_', '+/')), true)['alg'] === 'RS256'
            && json_decode(base64_decode(strtr($payload, '-_', '+/')), true)['iss'] === '12345';
    });
});

test('an installation removed on GitHub is forgotten here', function () {
    [$workspace] = connectedWorkspace();
    Http::fake(['api.github.com/*' => Http::response([], 404)]);

    expect(app(GitHubApp::class)->tokenFor($workspace, 'acme', 'secret-app'))->toBeNull()
        ->and(GitHubInstallation::count())->toBe(0);
});

test('a private repository is detected with the workspace’s token', function () {
    [$workspace] = connectedWorkspace();
    Http::fake([
        'api.github.com/app/installations/777/access_tokens' => Http::response(['token' => 'ghs_read'], 201),
        'api.github.com/repos/acme/secret-app' => fn (HttpRequest $r) => $r->hasHeader('Authorization', 'Bearer ghs_read')
            ? Http::response(['default_branch' => 'main']) : Http::response([], 404),
        'api.github.com/repos/acme/secret-app/commits/main' => Http::response(['sha' => str_repeat('a', 40)]),
        'api.github.com/repos/acme/secret-app/git/trees/*' => Http::response(['tree' => [['path' => 'Dockerfile']]]),
    ]);

    $this->actingAs($workspace->owner)
        ->postJson(workspaceUrl($workspace, '/services/detect'), ['repository' => 'https://github.com/acme/secret-app'])
        ->assertOk()
        ->assertJsonPath('commit', str_repeat('a', 40));
});

test('a private repository says how to connect GitHub, or to share it', function () {
    $workspace = Workspace::factory()->create();
    Http::fake(['api.github.com/app/*' => Http::response([], 422), 'api.github.com/*' => Http::response([], 404)]);

    $this->actingAs($workspace->owner)
        ->postJson(workspaceUrl($workspace, '/services/detect'), ['repository' => 'acme/secret-app'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.repository.0', fn (string $m) => str_contains($m, 'connect GitHub in Settings'));

    $workspace->githubInstallations()->create(['installation_id' => 9, 'account_login' => 'acme']);
    $this->actingAs($workspace->owner)
        ->postJson(workspaceUrl($workspace, '/services/detect'), ['repository' => 'acme/secret-app'])
        ->assertJsonPath('errors.repository.0', fn (string $m) => str_contains($m, 'shared with ServerOS'));
});

test('a private deploy goes with a fresh token that is never stored', function () {
    [$workspace] = connectedWorkspace();
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake([
        'api.github.com/app/installations/777/access_tokens' => Http::response(['token' => 'ghs_deploy'], 201),
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
    ]);
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => '0.1.1-pre.10'])->save();

    $job = app(DaemonJobQueue::class)->deploy($machine, $workspace->owner, [
        'type' => 'deploy', 'service' => 'api', 'repo' => 'https://github.com/acme/secret-app.git', 'commit' => str_repeat('b', 40),
    ]);

    Http::assertSent(fn (HttpRequest $r) => str_ends_with($r->url(), '/commands') && ($r['command']['job']['repo_token'] ?? null) === 'ghs_deploy');
    expect(DaemonJob::query()->findOrFail($job->id)->command['job'])->not->toHaveKey('repo_token');
});

test('an old daemon is told to update rather than failing to clone', function () {
    [$workspace] = connectedWorkspace();
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['api.github.com/*' => Http::response(['token' => 'ghs_deploy'], 201), '127.0.0.1:8090/*' => Http::response([], 202)]);
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => '0.1.1-pre.7'])->save();

    expect(fn () => app(DaemonJobQueue::class)->deploy($machine, $workspace->owner, [
        'type' => 'deploy', 'service' => 'api', 'repo' => 'https://github.com/acme/secret-app', 'commit' => str_repeat('b', 40),
    ]))->toThrow(RuntimeException::class, 'too old to deploy private repositories');
});

test('connecting sends you to install the app, and back here once GitHub confirms it’s yours', function () {
    $workspace = Workspace::factory()->create();

    $location = $this->actingAs($workspace->owner)->get(workspaceUrl($workspace, '/settings/github/connect'))->headers->get('Location');
    expect($location)->toStartWith('https://github.com/apps/serveros/installations/new?state=');
    $state = urldecode(Str::after($location, 'state='));

    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_user']),
        'api.github.com/app/installations/4242' => Http::response(['account' => ['login' => 'acme', 'type' => 'Organization', 'id' => 9]]),
        'api.github.com/user/memberships/orgs/acme' => Http::response(['state' => 'active', 'role' => 'admin']),
    ]);

    $this->actingAs($workspace->owner)
        ->get('/github/setup?'.http_build_query(['installation_id' => 4242, 'setup_action' => 'install', 'code' => 'abc', 'state' => $state]))
        ->assertRedirect($workspace->url().'/settings/general');

    expect($workspace->githubInstallations()->sole()->only(['installation_id', 'account_login', 'account_type', 'repository_ids']))
        ->toBe(['installation_id' => 4242, 'account_login' => 'acme', 'account_type' => 'Organization', 'repository_ids' => null]);
});

test('someone else’s installation id, or someone else’s state, connects nothing', function () {
    $workspace = Workspace::factory()->create();
    $state = Crypt::encryptString(json_encode(['workspace' => $workspace->id, 'user' => $workspace->owner->id, 'at' => now()->getTimestamp()]));
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_user']),
        'api.github.com/user/installations*' => Http::response(['installations' => [['id' => 1]]]),
    ]);

    $this->actingAs($workspace->owner)->get('/github/setup?'.http_build_query(['installation_id' => 4242, 'code' => 'abc', 'state' => $state]));
    $this->actingAs(User::factory()->create())->get('/github/setup?'.http_build_query(['installation_id' => 1, 'code' => 'abc', 'state' => $state]))
        ->assertRedirect(route('workspaces.index'));

    expect(GitHubInstallation::count())->toBe(0);
});

function setupWith(Workspace $workspace, int $installation): void
{
    $state = Crypt::encryptString(json_encode(['workspace' => $workspace->id, 'user' => $workspace->owner->id, 'at' => now()->getTimestamp()]));

    test()->actingAs($workspace->owner)
        ->get('/github/setup?'.http_build_query(['installation_id' => $installation, 'code' => 'abc', 'state' => $state]))
        ->assertRedirect($workspace->url().'/settings/general');
}

test('an org member who can only read one of its repositories can’t connect its installation', function () {
    $workspace = Workspace::factory()->create();
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_member']),
        'api.github.com/app/installations/42' => Http::response(['account' => ['login' => 'victim-org', 'type' => 'Organization', 'id' => 9]]),
        'api.github.com/user/memberships/orgs/victim-org' => Http::response(['state' => 'active', 'role' => 'member']),
        'api.github.com/user/installations/42/repositories*' => Http::response(['repositories' => [
            ['id' => 1, 'permissions' => ['admin' => false, 'push' => false, 'pull' => true]],
        ]]),
        'api.github.com/user/installations*' => Http::response(['installations' => [['id' => 42]]]),
        'api.github.com/app/installations/42/access_tokens' => Http::response(['token' => 'ghs_list'], 201),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => [['id' => 1], ['id' => 2]]]),
    ]);

    setupWith($workspace, 42);

    expect(GitHubInstallation::count())->toBe(0);
});

test('a GitHub user installation only connects for that user', function () {
    $workspace = Workspace::factory()->create();
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_other']),
        'api.github.com/app/installations/55' => Http::response(['account' => ['login' => 'liam', 'type' => 'User', 'id' => 31]]),
        'api.github.com/user' => Http::response(['id' => 99, 'login' => 'collaborator']),
        'api.github.com/user/installations*' => Http::response(['installations' => [['id' => 55]]]),
    ]);

    setupWith($workspace, 55);

    expect(GitHubInstallation::count())->toBe(0);
});

test('an admin of every repository it shares connects it, and gets tokens for just those', function () {
    $workspace = Workspace::factory()->create();
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_admin']),
        'api.github.com/app/installations/42' => Http::response(['account' => ['login' => 'acme', 'type' => 'Organization', 'id' => 9]]),
        'api.github.com/user/memberships/orgs/acme' => Http::response(['message' => 'Resource not accessible by integration'], 403),
        'api.github.com/user/installations/42/repositories*' => Http::response(['repositories' => [
            ['id' => 2, 'permissions' => ['admin' => true]],
            ['id' => 1, 'permissions' => ['admin' => true]],
        ]]),
        'api.github.com/app/installations/42/access_tokens' => fn (HttpRequest $r) => match ($r['repositories'][0] ?? null) {
            'shop' => Http::response(['token' => 'ghs_shop', 'repositories' => [['id' => 1]]], 201),
            'added-later' => Http::response(['token' => 'ghs_later', 'repositories' => [['id' => 3]]], 201),
            default => Http::response(['token' => 'ghs_list'], 201),
        },
        'api.github.com/installation/repositories*' => Http::response(['repositories' => [['id' => 1], ['id' => 2]]]),
    ]);

    setupWith($workspace, 42);

    expect($workspace->githubInstallations()->sole()->repository_ids)->toBe([1, 2])
        ->and(app(GitHubApp::class)->tokenFor($workspace, 'acme', 'shop'))->toBe('ghs_shop')
        ->and(app(GitHubApp::class)->writeTokenFor($workspace, 'acme', 'added-later'))->toBeNull()
        ->and(app(GitHubApp::class)->tokenFor($workspace, 'acme', 'added-later'))->toBeNull();
});

test('a cached token from one workspace’s link isn’t handed to another', function () {
    [$owner] = connectedWorkspace();
    $limited = Workspace::factory()->create();
    $limited->githubInstallations()->create(['installation_id' => 777, 'account_login' => 'acme', 'repository_ids' => [1]]);
    Http::fake(['api.github.com/app/installations/777/access_tokens' => Http::response(['token' => 'ghs_secret', 'repositories' => [['id' => 2]]], 201)]);

    expect(app(GitHubApp::class)->tokenFor($owner, 'acme', 'secret-app'))->toBe('ghs_secret')
        ->and(app(GitHubApp::class)->tokenFor($limited, 'acme', 'secret-app'))->toBeNull();
});

test('the settings page lists connections, and one can be disconnected', function () {
    [$workspace, $installation] = connectedWorkspace();

    $this->actingAs($workspace->owner)
        ->get(workspaceUrl($workspace, '/settings/general'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('github.0.account', 'acme')
            ->where('github.0.manageUrl', 'https://github.com/organizations/acme/settings/installations/777'));

    $this->actingAs($workspace->owner)->delete(workspaceUrl($workspace, "/settings/github/{$installation->id}"))->assertRedirect();
    expect(GitHubInstallation::count())->toBe(0);
});

test('without the app in the env there’s nothing to connect', function () {
    config()->set('services.github_app.private_key', null);
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->get(workspaceUrl($workspace, '/settings/general'))
        ->assertInertia(fn (Assert $page) => $page->where('github', null)->where('githubApp', false));
    $this->actingAs($workspace->owner)->get(workspaceUrl($workspace, '/settings/github/connect'))->assertNotFound();
});

test('if GitHub comes back without the state, the session still knows the workspace', function () {
    $workspace = Workspace::factory()->create();
    $this->actingAs($workspace->owner)->get(workspaceUrl($workspace, '/settings/github/connect'));
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'ghu_user']),
        'api.github.com/app/installations/55' => Http::response(['account' => ['login' => 'liam', 'type' => 'User', 'id' => 31]]),
        'api.github.com/user' => Http::response(['id' => 31, 'login' => 'liam']),
    ]);

    $this->actingAs($workspace->owner)
        ->get('/github/setup?'.http_build_query(['installation_id' => 55, 'setup_action' => 'install', 'code' => 'abc']))
        ->assertRedirect($workspace->url().'/settings/general');

    expect($workspace->githubInstallations()->value('account_login'))->toBe('liam');
});

test('the slug works even when the whole public link was pasted', function () {
    config()->set('services.github_app.slug', 'https://github.com/apps/serveroshq/');

    expect(app(GitHubApp::class)->installUrl('s'))->toBe('https://github.com/apps/serveroshq/installations/new?state=s');
});

/** @return list<array<string, mixed>> */
function githubRepositories(string $owner, int ...$ids): array
{
    return array_map(fn (int $id) => [
        'id' => $id, 'full_name' => "{$owner}/repo-{$id}", 'private' => $id === 1, 'default_branch' => 'main', 'pushed_at' => "2026-01-0{$id}T00:00:00Z",
    ], $ids);
}

test('the deploy dialog lists only this workspace’s repositories, within a limited connection', function () {
    [$workspace, $installation] = connectedWorkspace();
    $installation->update(['repository_ids' => [1, 2]]);
    $other = Workspace::factory()->create();
    $other->githubInstallations()->create(['installation_id' => 888, 'account_login' => 'theirs']);
    Http::fake([
        'api.github.com/app/installations/777/access_tokens' => Http::response(['token' => 'ghs_acme'], 201),
        'api.github.com/app/installations/888/access_tokens' => Http::response(['token' => 'ghs_theirs'], 201),
        'api.github.com/installation/repositories*' => fn (HttpRequest $r) => Http::response(['repositories' => $r->hasHeader('Authorization', 'Bearer ghs_acme')
            ? githubRepositories('acme', 1, 2, 3)
            : githubRepositories('theirs', 4)]),
    ]);

    $this->actingAs($workspace->owner)->getJson(workspaceUrl($workspace, '/github/repositories'))
        ->assertOk()
        ->assertJson(['enabled' => true, 'connected' => true])
        ->assertJsonPath('repositories.*.name', ['acme/repo-2', 'acme/repo-1'])
        ->assertJsonPath('repositories.1.private', true);

    $this->actingAs($other->owner)->getJson(workspaceUrl($other, '/github/repositories'))
        ->assertOk()
        ->assertJsonPath('repositories.*.name', ['theirs/repo-4']);

    $this->actingAs($other->owner)->getJson(workspaceUrl($workspace, '/github/repositories'))->assertForbidden();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->getJson(workspaceUrl($workspace, '/github/repositories'))->assertForbidden();
    $this->actingAs($viewer)->getJson(workspaceUrl($workspace, '/github/repositories/acme/repo-1/branches'))->assertForbidden();
});

test('the repository list says when GitHub isn’t set up or nothing is connected', function () {
    $workspace = Workspace::factory()->create();
    Http::fake();

    $this->actingAs($workspace->owner)->getJson(workspaceUrl($workspace, '/github/repositories'))
        ->assertOk()->assertExactJson(['enabled' => true, 'connected' => false, 'repositories' => []]);

    $workspace->githubInstallations()->create(['installation_id' => 777, 'account_login' => 'acme']);
    config()->set('services.github_app', []);
    $this->actingAs($workspace->owner)->getJson(workspaceUrl($workspace, '/github/repositories'))
        ->assertOk()->assertExactJson(['enabled' => false, 'connected' => false, 'repositories' => []]);
    Http::assertNothingSent();
});

test('a connection removed on GitHub drops out of the repository list', function () {
    [$workspace] = connectedWorkspace();
    Http::fake(['api.github.com/app/installations/777/access_tokens' => Http::response([], 404)]);

    $this->actingAs($workspace->owner)->getJson(workspaceUrl($workspace, '/github/repositories'))
        ->assertOk()->assertJsonPath('repositories', []);
    expect(GitHubInstallation::count())->toBe(0);
});

test('a failed repository list isn’t kept, and changing a connection forgets the list', function () {
    [$workspace, $installation] = connectedWorkspace();
    $up = false;
    Http::fake([
        'api.github.com/app/installations/777/access_tokens' => function () use (&$up) {
            return $up ? Http::response(['token' => 'ghs_acme'], 201) : Http::response([], 500);
        },
        'api.github.com/installation/repositories*' => Http::response(['repositories' => githubRepositories('acme', 1, 2)]),
    ]);
    $list = fn () => $this->actingAs($workspace->owner)->getJson(workspaceUrl($workspace, '/github/repositories'))->json('repositories.*.name');

    expect($list())->toBe([]);
    $up = true;
    expect($list())->toBe(['acme/repo-2', 'acme/repo-1']);
    $sent = count(Http::recorded());
    expect($list())->toBe(['acme/repo-2', 'acme/repo-1'])
        ->and(Http::recorded())->toHaveCount($sent);

    $installation->update(['repository_ids' => [1]]);
    expect($list())->toBe(['acme/repo-1']);
    $installation->delete();
    expect($list())->toBe([]);
});

test('branches come with the workspace’s token, or the operator’s for a public repository only', function () {
    [$workspace] = connectedWorkspace();
    config()->set('services.github.token', 'ghp_operator');
    Http::fake([
        'api.github.com/app/installations/777/access_tokens' => fn (HttpRequest $r) => $r['repositories'] === ['secret-app']
            ? Http::response(['token' => 'ghs_read'], 201) : Http::response([], 422),
        'api.github.com/repos/acme/secret-app/branches*' => fn (HttpRequest $r) => ! $r->hasHeader('Authorization', 'Bearer ghs_read')
            ? Http::response([], 404)
            : Http::response((int) $r['page'] === 1 ? array_map(fn ($i) => ['name' => "b{$i}"], range(1, 100)) : [['name' => 'zzz']]),
        'api.github.com/repos/someone/public-app' => Http::response(['private' => false, 'visibility' => 'public']),
        'api.github.com/repos/someone/public-app/branches*' => Http::response([['name' => 'main'], ['name' => 'dev']]),
        'api.github.com/repos/operator/private-app' => Http::response(['private' => true, 'visibility' => 'private']),
        'api.github.com/repos/operator/private-app/branches*' => Http::response([['name' => 'leak']]),
        'api.github.com/repos/operator/odd-app' => Http::response(''),
        'api.github.com/repos/operator/odd-app/branches*' => Http::response([['name' => 'leak']]),
    ]);
    $branches = fn (string $repo) => $this->actingAs($workspace->owner)->getJson(workspaceUrl($workspace, "/github/repositories/{$repo}/branches"))->assertOk()->json('branches');

    expect($branches('acme/secret-app'))->toHaveCount(101)->toContain('b1', 'zzz')
        ->and($branches('someone/public-app'))->toBe(['main', 'dev'])
        ->and($branches('operator/private-app'))->toBe([])
        ->and($branches('operator/odd-app'))->toBe([]);

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'someone/public-app/branches') && $r->hasHeader('Authorization', 'Bearer ghp_operator'));
    Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'private-app/branches') || str_contains($r->url(), 'odd-app/branches'));
    $this->actingAs($workspace->owner)->getJson(workspaceUrl($workspace, '/github/repositories/acme/../branches'))->assertNotFound();
});
