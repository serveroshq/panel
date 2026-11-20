<?php

use App\Mcp\ToolError;
use App\Mcp\Tools\Deploy\RefResolver;
use App\Models\User;
use App\Models\Workspace;
use App\Support\ServiceTemplates\RepositoryDetector;
use Illuminate\Support\Facades\Http;

/** @param  array<string, string>  $files */
function fakeGitHubRepo(array $files, string $branch = 'main'): void
{
    $sha = str_repeat('a', 40);
    $raw = [];
    foreach ($files as $path => $body) {
        $raw["raw.githubusercontent.com/acme/app/{$sha}/{$path}"] = Http::response($body);
    }

    Http::fake([
        'api.github.com/repos/acme/app' => Http::response(['default_branch' => $branch]),
        "api.github.com/repos/acme/app/commits/{$branch}" => Http::response(['sha' => $sha]),
        "api.github.com/repos/acme/app/git/trees/{$sha}" => Http::response([
            'tree' => array_map(fn ($path) => ['path' => $path], array_keys($files)),
        ]),
        ...$raw,
        '*' => Http::response(null, 404),
    ]);
}

function detectUrl(Workspace $workspace): string
{
    return 'https://'.$workspace->host().'/services/detect';
}

test('a Laravel repository is detected as a web app on its latest commit', function () {
    fakeGitHubRepo(['composer.json' => json_encode(['require' => ['laravel/framework' => '^12.0']]), 'artisan' => '']);
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->postJson(detectUrl($workspace), ['repository' => 'https://github.com/acme/app'])
        ->assertOk()
        ->assertJson([
            'detected' => true,
            'template' => 'laravel',
            'framework' => 'Laravel',
            'type' => 'Web app',
            'repo' => 'https://github.com/acme/app.git',
            'branch' => 'main',
            'commit' => str_repeat('a', 40),
        ]);
});

test('the detector recognises the common stacks', function (array $files, ?string $template, ?string $type) {
    $match = app(RepositoryDetector::class)->match(array_keys($files), $files);

    expect($match['template'] ?? null)->toBe($template)
        ->and($match['type'] ?? null)->toBe($type);
})->with([
    'own Dockerfile' => [['Dockerfile' => '', 'package.json' => '{}'], 'custom-dockerfile', 'App'],
    'Express API' => [['package.json' => '{"dependencies":{"express":"^5"}}'], 'node', 'API'],
    'Express on Bun' => [['package.json' => '{"dependencies":{"hono":"^4"}}', 'bun.lockb' => ''], 'bun', 'API'],
    'NestJS' => [['package.json' => '{"dependencies":{"@nestjs/core":"^11"}}'], 'nestjs', 'API'],
    'Remix' => [['package.json' => '{"dependencies":{"@remix-run/node":"^2"}}'], 'remix', 'Web app'],
    'Angular' => [['package.json' => '{"dependencies":{"@angular/core":"^19"}}'], 'angular', 'Static site'],
    'Hugo' => [['hugo.toml' => 'baseURL = "/"', 'package.json' => '{}'], 'hugo', 'Static site'],
    'Phoenix' => [['mix.exs' => '{:phoenix, "~> 1.7"}'], 'phoenix', 'Web app'],
    'Next.js' => [['package.json' => '{"dependencies":{"next":"15"}}'], 'nextjs', 'Web app'],
    'Vite SPA' => [['package.json' => '{"devDependencies":{"vite":"6"},"scripts":{"build":"vite build"}}'], 'vite-spa', 'Static site'],
    'FastAPI' => [['requirements.txt' => "fastapi==0.115\nuvicorn"], 'fastapi', 'API'],
    'Django' => [['pyproject.toml' => 'dependencies = ["Django>=5"]'], 'django', 'Web app'],
    'Rails' => [['Gemfile' => "gem 'rails', '~> 8.0'"], 'rails', 'Web app'],
    'Go with Gin' => [['go.mod' => "module x\nrequire github.com/gin-gonic/gin v1.10.0"], 'go', 'API'],
    'Rust with Axum' => [['Cargo.toml' => "[dependencies]\naxum = \"0.7\""], 'rust', 'API'],
    'plain HTML' => [['index.html' => '<h1>hi</h1>'], 'static-site', 'Static site'],
    'nothing known' => [['README.md' => '# hi'], null, null],
]);

test('only public GitHub links are accepted', function () {
    fakeGitHubRepo([]);
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->postJson(detectUrl($workspace), ['repository' => 'https://gitlab.com/acme/app'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('repository');

    $this->actingAs($workspace->owner)
        ->postJson(detectUrl($workspace), ['repository' => 'https://github.com/acme/missing'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('repository');
});

test('the panel’s own GitHub token only ever reads public repositories', function () {
    config()->set('services.github.token', 'ghp_operator');
    $sha = str_repeat('b', 40);
    Http::fake([
        'api.github.com/repos/operator/secret' => Http::response(['default_branch' => 'main', 'private' => true, 'visibility' => 'private']),
        'api.github.com/repos/acme/app' => Http::response(['default_branch' => 'main', 'private' => false, 'visibility' => 'public']),
        'api.github.com/repos/*/commits/*' => Http::response(['sha' => $sha]),
        'api.github.com/repos/*/git/trees/*' => Http::response(['tree' => []]),
        '*' => Http::response(null, 404),
    ]);
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->postJson(detectUrl($workspace), ['repository' => 'https://github.com/operator/secret'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('repository');
    expect(fn () => app(RefResolver::class)->resolve('https://github.com/operator/secret', 'main', $workspace))->toThrow(ToolError::class)
        ->and(fn () => app(RefResolver::class)->resolve('https://github.com/operator/secret', null, $workspace))->toThrow(ToolError::class);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'operator/secret/'));

    $this->actingAs($workspace->owner)
        ->postJson(detectUrl($workspace), ['repository' => 'https://github.com/acme/app'])
        ->assertOk()
        ->assertJson(['commit' => $sha]);
    expect(app(RefResolver::class)->resolve('https://github.com/acme/app', 'main', $workspace))->toBe(['commit' => $sha, 'ref' => 'main']);
    Http::assertSent(fn ($request) => str_contains($request->url(), 'acme/app/commits/main') && $request->hasHeader('Authorization', 'Bearer ghp_operator'));
});

test('viewers cannot detect repositories', function () {
    $workspace = Workspace::factory()->create();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)
        ->postJson(detectUrl($workspace), ['repository' => 'acme/app'])
        ->assertForbidden();
});

test('a /tree/ link to a folder detects the app in that folder', function () {
    $sha = str_repeat('a', 40);
    Http::fake([
        'api.github.com/repos/acme/app' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/app/commits/release%2Fv2' => Http::response(['sha' => $sha]),
        "api.github.com/repos/acme/app/contents/apps/web?ref={$sha}" => Http::response([
            ['name' => 'package.json', 'type' => 'file'], ['name' => 'src', 'type' => 'dir'],
        ]),
        "raw.githubusercontent.com/acme/app/{$sha}/apps/web/package.json" => Http::response('{"dependencies":{"next":"15"}}'),
        '*' => Http::response(null, 404),
    ]);
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->postJson(detectUrl($workspace), ['repository' => 'https://github.com/acme/app/tree/release/v2/apps/web'])
        ->assertOk()
        ->assertJson(['detected' => true, 'template' => 'nextjs', 'branch' => 'release/v2', 'baseDir' => 'apps/web', 'commit' => $sha]);

    $this->actingAs($workspace->owner)
        ->postJson(detectUrl($workspace), ['repository' => 'https://github.com/acme/app/tree/release/v2/apps/missing'])
        ->assertStatus(422)->assertJsonValidationErrors(['repository' => 'Couldn’t find the folder apps/missing']);
});

test('a Compose file in a folder is named by its path from the root', function () {
    $sha = str_repeat('a', 40);
    Http::fake([
        'api.github.com/repos/acme/app' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/app/commits/main' => Http::response(['sha' => $sha]),
        "api.github.com/repos/acme/app/contents/deploy?ref={$sha}" => Http::response([['name' => 'compose.yaml', 'type' => 'file']]),
        "raw.githubusercontent.com/acme/app/{$sha}/deploy/compose.yaml" => Http::response("services:\n  web:\n    build: .\n    ports:\n      - \"8080:80\"\n"),
        '*' => Http::response(null, 404),
    ]);

    $found = app(RepositoryDetector::class)->detect('https://github.com/acme/app/tree/main/deploy');
    expect($found)->template->toBe('compose-file')->composeFile->toBe('deploy/compose.yaml')->baseDir->toBe('deploy');
});
