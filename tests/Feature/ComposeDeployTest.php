<?php

use App\Mcp\Tools\Deploy\DetectRepository;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\ServiceTemplates\RepositoryDetector;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use App\Support\ServiceTemplates\TemplateGuide;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @return array{0: Workspace, 1: Machine, 2: string} */
function composeMachine(string $daemon = '0.1.1-pre.38'): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['ip_address' => '203.0.113.7']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $daemon])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id.'/deploy'];
}

/** @return array<string, mixed>|null */
function sentComposeJob(): ?array
{
    $command = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->first(fn (GatewayRequest $request) => str_ends_with($request->url(), '/commands'));

    return $command === null ? null : json_decode($command->body(), true)['command']['job'];
}

const COMPOSE_STACK = <<<'YAML'
services:
  web:
    build: .
    ports:
      - "127.0.0.1:${APP_PORT:-8080}:3000"
    environment:
      DATABASE_URL: ${DATABASE_URL}
  db:
    image: postgres:17
    ports:
      - 5432:5432
YAML;

test('a repository whose Compose file builds it runs as a stack, on the port it publishes', function () {
    $match = app(RepositoryDetector::class)->match(['compose.yaml', 'Dockerfile', 'package.json'], [
        'compose.yaml' => COMPOSE_STACK,
        'package.json' => '{"dependencies":{"next":"15"}}',
    ]);

    expect($match)->toMatchArray(['template' => 'compose-file', 'framework' => 'Docker Compose', 'composeFile' => 'compose.yaml', 'composePort' => 8080]);
});

test('a Compose file that only starts a database leaves the framework in charge, and says it’s there', function () {
    $dev = "services:\n  db:\n    image: postgres:17\n    ports:\n      - 5432:5432\n";
    $match = app(RepositoryDetector::class)->match(['docker-compose.yml', 'package.json'], [
        'docker-compose.yml' => $dev,
        'package.json' => '{"dependencies":{"next":"15"}}',
    ]);

    expect($match['template'])->toBe('nextjs')
        ->and($match['composeFile'])->toBe('docker-compose.yml')
        ->and($match)->not->toHaveKey('composePort')
        ->and(end($match['reasons']))->toContain('docker-compose.yml too');

    $alone = app(RepositoryDetector::class)->match(['docker-compose.yml', 'README.md'], ['docker-compose.yml' => "services:\n  app:\n    image: ghost:5\n    ports:\n      - 2368:2368\n"]);
    expect($alone)->toMatchArray(['template' => 'compose-file', 'composeFile' => 'docker-compose.yml', 'composePort' => 2368]);
});

test('Laravel Sail’s Compose file is never the way to deploy', function () {
    $sail = "services:\n  laravel.test:\n    build:\n      context: ./vendor/laravel/sail/runtimes/8.4\n    ports:\n      - '\${APP_PORT:-80}:80'\n";
    $match = app(RepositoryDetector::class)->match(['compose.yaml', 'composer.json', 'artisan'], [
        'compose.yaml' => $sail,
        'composer.json' => json_encode(['require' => ['laravel/framework' => '^12.0']]),
    ]);

    expect($match['template'])->toBe('laravel')->and($match)->not->toHaveKey('composeFile');
});

test('the web port is the first one published that isn’t a database’s', function (string $compose, ?int $port) {
    expect(RepositoryDetector::publishedPort($compose))->toBe($port);
})->with([
    'short' => ["ports:\n  - \"8080:80\"\n", 8080],
    'on 127.0.0.1' => ["ports:\n  - 127.0.0.1:3000:3000\n", 3000],
    'from a variable' => ["ports:\n  - '\${APP_PORT:-8000}:80'\n", 8000],
    'long syntax' => ["ports:\n  - target: 80\n    published: 9000\n", 9000],
    'container port only' => ["ports:\n  - \"3000\"\n", null],
    'database first' => ["db:\n  ports:\n    - 5432:5432/tcp # db\nweb:\n  ports:\n    - 80:80\n", 80],
]);

test('deploying a repository’s Compose file sends it as the stack, with no Dockerfile and the settings for it', function () {
    [$workspace, , $url] = composeMachine();

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'compose-file',
        'service' => 'shop',
        'repo' => 'https://github.com/example/shop.git',
        'commit' => str_repeat('c', 40),
        'compose_file' => 'deploy/compose.prod.yml',
        'port' => 8080,
        'domain' => 'shop.example.com',
        'extra_env' => 'DATABASE_URL=postgres://db/shop',
    ])->assertAccepted();

    $job = sentComposeJob();
    expect($job['repo'])->toBe('https://github.com/example/shop.git')
        ->and($job['commit'])->toBe(str_repeat('c', 40))
        ->and($job['compose_file'])->toBe('deploy/compose.prod.yml')
        ->and($job['dockerfile'])->toBeNull()
        ->and($job['files'])->toBe([])
        ->and($job['port'])->toBe(8080)
        ->and($job['domains'])->toBe(['shop.example.com'])
        ->and($job['health']['path'])->toBe('/')
        ->and($job['env']['DATABASE_URL'])->toBe('postgres://db/shop');

    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'compose-file', 'service' => 'blog', 'repo' => 'https://github.com/example/blog.git', 'commit' => str_repeat('d', 40),
    ])->assertAccepted();
    expect(sentComposeJob()['compose_file'])->toBe('compose.yaml');
});

test('the Compose file stays inside the repository, and only the Compose setup takes one', function (string $template, string $path) {
    [$workspace, , $url] = composeMachine();

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => $template, 'service' => 'shop', 'repo' => 'https://github.com/example/shop.git', 'commit' => str_repeat('c', 40),
        'compose_file' => $path,
    ])->assertUnprocessable()->assertJsonValidationErrors('compose_file');

    expect(sentComposeJob())->toBeNull();
})->with([
    'outside the repository' => ['compose-file', '../../etc/compose.yaml'],
    'an absolute path' => ['compose-file', '/etc/compose.yaml'],
    'not YAML' => ['compose-file', 'compose.json'],
    'a hidden folder' => ['compose-file', '.git/compose.yaml'],
    'a Dockerfile app' => ['custom-dockerfile', 'compose.yaml'],
]);

test('a daemon from before stacks kept their folder is told to update first', function () {
    [$workspace, , $url] = composeMachine('0.1.1-pre.37');

    $this->actingAs($workspace->owner)->postJson($url, [
        'template' => 'compose-file', 'service' => 'shop', 'repo' => 'https://github.com/example/shop.git', 'commit' => str_repeat('c', 40),
    ])->assertUnprocessable()->assertJsonValidationErrors('form');

    expect(sentComposeJob())->toBeNull();
});

test('detection hands the Compose file and its port to the dialog and to MCP', function () {
    $sha = str_repeat('a', 40);
    Http::fake([
        'api.github.com/repos/acme/app' => Http::response(['default_branch' => 'main', 'private' => false, 'visibility' => 'public']),
        'api.github.com/repos/acme/app/commits/main' => Http::response(['sha' => $sha]),
        "api.github.com/repos/acme/app/git/trees/{$sha}" => Http::response(['tree' => [['path' => 'compose.yaml'], ['path' => 'Dockerfile']]]),
        "raw.githubusercontent.com/acme/app/{$sha}/compose.yaml" => Http::response(COMPOSE_STACK),
        '*' => Http::response(null, 404),
    ]);
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->postJson('https://'.$workspace->host().'/services/detect', ['repository' => 'https://github.com/acme/app'])
        ->assertOk()
        ->assertJson(['template' => 'compose-file', 'composeFile' => 'compose.yaml', 'composePort' => 8080]);

    $template = app(ServiceTemplateCatalog::class)->find('compose-file');
    expect($template->composeFromRepo())->toBeTrue()
        ->and($template->controlsHost())->toBeTrue()
        ->and($template->toPageProps()['compose'])->toBe('compose.yaml')
        ->and(app(ServiceTemplateCatalog::class)->find('custom-dockerfile')->controlsHost())->toBeFalse()
        ->and(TemplateGuide::for($template)['title'])->toBe('How to deploy a Docker Compose file on your own server')
        ->and(TemplateGuide::for($template)['byHand'])->not->toContain('Dockerfile')
        ->and(app(DetectRepository::class)->description())->toContain('compose-file');
});
