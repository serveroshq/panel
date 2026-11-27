<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\ServiceTemplates\RepositoryDetector;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @return array{0: Workspace, 1: Machine} */
function inspectMachine(string $version = '0.1.1-pre.51'): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('daemon.short_job_wait_secs', 0);
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $version])->save();

    return [$workspace, $machine];
}

test('detection reads the port a Dockerfile exposes and the settings a .env.example lists', function () {
    $found = app(RepositoryDetector::class)->describe(['Dockerfile', '.env.example'], [
        'Dockerfile' => "FROM node:22\nEXPOSE 8080/tcp\nCMD [\"node\", \"server.js\"]",
        '.env.example' => "# Fill these in\nDATABASE_URL=postgres://localhost/app\nexport API_KEY=\"\"",
    ]);

    expect($found)->template->toBe('custom-dockerfile')->port->toBe(8080)
        ->and($found['reasons'])->toContain('Its Dockerfile exposes port 8080, so that’s the one served.')
        ->and($found['envExample'])->toBe(['file' => '.env.example', 'variables' => [
            ['name' => 'DATABASE_URL', 'value' => 'postgres://localhost/app'],
            ['name' => 'API_KEY', 'value' => ''],
        ]]);
});

test('code no template knows builds with Railpack, saying what it found, unless the daemon is too old', function (array $files, string $framework, array $hint) {
    $detector = app(RepositoryDetector::class);

    $found = $detector->describe(array_keys($files), $files);
    expect($found)->detected->toBeTrue()->template->toBe('auto-build')->framework->toBe($framework)
        ->and($found['railpack'])->toMatchArray($hint);

    $old = $detector->describe(array_keys($files), $files, autoBuild: false);
    expect($old)->detected->toBeFalse()->template->toBeNull()
        ->and($old['needsUpdate'])->toContain('Update the ServerOS daemon')
        ->and($old['railpack'])->toMatchArray($hint);
})->with([
    'a Python worker' => [['requirements.txt' => "requests\nrq", '.python-version' => '3.12', 'uv.lock' => ''], 'Python', ['language' => 'Python', 'packageManager' => 'uv', 'version' => '3.12']],
    'Sinatra' => [['Gemfile' => "gem 'sinatra'"], 'Ruby', ['language' => 'Ruby', 'framework' => 'Sinatra', 'packageManager' => 'bundler']],
    'plain Elixir' => [['mix.exs' => 'defp deps, do: []'], 'Elixir', ['language' => 'Elixir', 'packageManager' => 'mix']],
    'a Procfile' => [['Procfile' => 'web: ./start.sh --port $PORT'], 'Procfile', ['startCommand' => './start.sh --port $PORT']],
    'nothing at all' => [['README.md' => '# hi'], 'Your own code', []],
]);

test('a framework keeps its own template, and Railpack’s view comes along for the dialog', function () {
    $found = app(RepositoryDetector::class)->describe(['package.json', 'pnpm-lock.yaml', '.nvmrc'], [
        'package.json' => '{"dependencies":{"next":"15"}}', '.nvmrc' => "22\n",
    ]);

    expect($found)->template->toBe('nextjs')
        ->and($found['railpack'])->toMatchArray(['language' => 'Node.js', 'framework' => 'Next.js', 'packageManager' => 'pnpm', 'version' => '22']);
});

test('a repository that isn’t on GitHub is read by the machine, and the dialog polls for what it is', function () {
    [$workspace, $machine] = inspectMachine();
    $base = $workspace->url()."/machines/{$machine->id}/deploy";

    // GitLab projects are read through GitLab's API (GitLabTest); other hosts by the machine.
    $this->actingAs($workspace->owner)->postJson($workspace->url().'/services/detect', ['repository' => 'https://codeberg.org/acme/shop'])
        ->assertUnprocessable()->assertJsonPath('inspect', true)->assertJsonValidationErrors('repository');

    $answer = $this->actingAs($workspace->owner)->postJson($base.'/inspect', ['repository' => 'https://gitlab.com/acme/shop/-/tree/develop/apps/web'])
        ->assertOk()->assertJsonPath('state', 'queued');
    $job = DaemonJob::query()->findOrFail($answer->json('jobId'));
    expect($job->kind)->toBe('repo_inspect')
        ->and($job->command['job'])->toBe(['type' => 'repo_inspect', 'repo' => 'https://gitlab.com/acme/shop', 'ref' => 'develop', 'base_dir' => 'apps/web']);
    Http::assertSent(fn (GatewayRequest $r) => str_ends_with($r->url(), '/commands') && $r['command']['job']['type'] === 'repo_inspect');

    $job->forceFill(['state' => 'succeeded', 'result' => [
        'commit' => str_repeat('e', 40), 'ref' => 'develop', 'default_branch' => 'main', 'truncated' => false,
        'files' => ['go.mod', 'main.go', 'cmd/server/main.go'],
        'contents' => ['go.mod' => "module shop\n\ngo 1.23\nrequire github.com/go-chi/chi v5"],
    ]])->save();

    $this->actingAs($workspace->owner)->getJson($base.'/jobs/'.$job->id)
        ->assertOk()
        ->assertJsonPath('state', 'succeeded')
        ->assertJsonPath('detection.template', 'go')
        ->assertJsonPath('detection.type', 'API')
        ->assertJsonPath('detection.repo', 'https://gitlab.com/acme/shop')
        ->assertJsonPath('detection.branch', 'develop')
        ->assertJsonPath('detection.commit', str_repeat('e', 40))
        ->assertJsonPath('detection.baseDir', 'apps/web')
        ->assertJsonPath('detection.railpack.version', '1.23');

    // The repository's files aren't kept, only what they said.
    expect($job->fresh()->result)->not->toHaveKey('contents')->toHaveKey('detection');
});

test('an SSH repository is read with the app’s deploy key, and an older daemon is told to update', function () {
    [$workspace, $machine] = inspectMachine();
    $base = $workspace->url()."/machines/{$machine->id}/deploy";

    $this->actingAs($workspace->owner)->postJson($base.'/inspect', ['repository' => 'git@gitlab.com:acme/shop.git'])
        ->assertUnprocessable()->assertJsonValidationErrors('service');
    $this->actingAs($workspace->owner)->postJson($base.'/inspect', ['repository' => 'git@gitlab.com:acme/shop.git', 'service' => 'shop'])
        ->assertOk();
    expect(DaemonJob::query()->sole()->command['job'])->toMatchArray(['service' => 'shop', 'repo' => 'git@gitlab.com:acme/shop.git']);

    [$workspace, $old] = inspectMachine('0.1.1-pre.49');
    $this->actingAs($workspace->owner)->postJson($workspace->url()."/machines/{$old->id}/deploy/inspect", ['repository' => 'https://gitlab.com/acme/shop'])
        ->assertUnprocessable()->assertJsonValidationErrors(['repository' => 'too old to read repositories on other hosts']);
});
