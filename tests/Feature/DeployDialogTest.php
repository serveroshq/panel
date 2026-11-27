<?php

use App\Models\DeploySource;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

function githubAppConfigured(): void
{
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config()->set('services.github_app', [
        'id' => '12345', 'slug' => 'serveros', 'client_id' => 'Iv1.abc', 'client_secret' => 'shh',
        'private_key' => base64_encode($pem),
    ]);
}

test('the deploy dialog can offer every machine in the workspace to run on', function () {
    $workspace = Workspace::factory()->create();
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'provider' => 'Hetzner']);
    $web->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1', 'provider' => 'DigitalOcean']);
    Machine::factory()->create(['name' => 'someone-elses']);

    $this->actingAs($workspace->owner)
        ->getJson($workspace->url().'/machines/'.$web->id.'/deploy/taken')
        ->assertOk()
        ->assertJsonPath('machines', [
            ['id' => $db->id, 'name' => 'db-1', 'provider' => 'DigitalOcean', 'online' => false, 'canDeployBuild' => false],
            ['id' => $web->id, 'name' => 'web-1', 'provider' => 'Hetzner', 'online' => true, 'canDeployBuild' => false],
        ]);
});

test('the repository picker names the connected GitHub accounts, who connected them, and each repository’s language', function () {
    githubAppConfigured();
    $workspace = Workspace::factory()->create();
    $sam = User::factory()->create(['name' => 'Sam Carter']);
    $workspace->githubInstallations()->create([
        'installation_id' => 777, 'account_login' => 'acme', 'account_type' => 'Organization', 'connected_by' => $sam->id,
    ]);
    Http::fake([
        'api.github.com/app/installations/777/access_tokens' => Http::response(['token' => 'ghs_list'], 201),
        'api.github.com/installation/repositories*' => Http::response(['repositories' => [
            ['id' => 1, 'full_name' => 'acme/shop', 'private' => true, 'default_branch' => 'main', 'pushed_at' => '2026-11-26T10:00:00Z', 'language' => 'PHP'],
            ['id' => 2, 'full_name' => 'acme/notes', 'private' => false, 'default_branch' => 'main', 'pushed_at' => '2026-11-20T10:00:00Z', 'language' => null],
        ]]),
    ]);

    $this->actingAs($workspace->owner)
        ->getJson($workspace->url().'/github/repositories')
        ->assertOk()
        ->assertJsonPath('connected', true)
        ->assertJsonPath('accounts', [['login' => 'acme', 'type' => 'Organization', 'connectedBy' => 'Sam Carter']])
        ->assertJsonPath('repositories.0.name', 'acme/shop')
        ->assertJsonPath('repositories.0.language', 'PHP')
        ->assertJsonPath('repositories.1.language', null);
});

test('without a connected account the repository picker lists nothing', function () {
    githubAppConfigured();
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)
        ->getJson($workspace->url().'/github/repositories')
        ->assertOk()
        ->assertJson(['connected' => false, 'accounts' => [], 'repositories' => []]);
});

test('an uploaded Laravel app says which PHP it asks for, for the dialog’s detected chips', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $path = tempnam(sys_get_temp_dir(), 'zip').'.zip';
    $zip = new ZipArchive;
    $zip->open($path, ZipArchive::CREATE);
    $zip->addFromString('shop/composer.json', json_encode(['require' => ['php' => '^8.4', 'laravel/framework' => '^10.0']]));
    $zip->addFromString('shop/artisan', '<?php');
    $zip->close();
    $bytes = (string) file_get_contents($path);
    $base = $workspace->url()."/machines/{$machine->id}/sources";

    $id = $this->actingAs($workspace->owner)
        ->postJson($base, ['filename' => 'shop.zip', 'size' => strlen($bytes)])
        ->assertCreated()
        ->json('id');
    $this->call('POST', "{$base}/{$id}/chunk?offset=0", [], [], [], ['CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json'], $bytes)->assertOk();

    $this->postJson("{$base}/{$id}/finish")
        ->assertOk()
        ->assertJsonPath('template', 'laravel')
        ->assertJsonPath('php', '8.3')
        ->assertJsonPath('healthPath', '/')
        ->assertJsonPath('upload.filename', 'shop.zip');

    File::deleteDirectory(DeploySource::query()->sole()->directory());
});

test('what the dialog sends for a scanned Laravel 10 app keeps its PHP, checks / for health and carries its build settings', function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('services.github.token', null);
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'api.github.com/repos/acme/shop' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/shop/commits/*' => Http::response(['sha' => str_repeat('c', 40)]),
        '*' => Http::response(null, 404),
    ]);
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '203.0.113.7']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => '0.1.1-pre.51', 'facts' => ['docker_version' => '27.1']])->save();

    // As DeployServiceDialog posts it after a scan: the detection's php and
    // health path, the branch, the template's settings, a .env as the dialog
    // writes one, a setting kept to the build, and only what Advanced changed.
    $this->actingAs($workspace->owner)->postJson($workspace->url()."/machines/{$machine->id}/deploy", [
        'template' => 'laravel', 'service' => 'shop', 'port' => null,
        'repo' => 'https://github.com/acme/shop.git', 'commit' => str_repeat('c', 40), 'branch' => 'main',
        'health_path' => '/', 'compose_file' => null, 'base_dir' => null, 'php' => '8.1',
        'domain' => null, 'wildcard' => false, 'proxy' => null, 'https' => null,
        'env' => ['APP_KEY' => '', 'APP_ENV' => 'production', 'APP_DEBUG' => 'false', 'APP_URL' => '', 'DB_CONNECTION' => 'sqlite', 'LOG_CHANNEL' => 'stderr'],
        'extra_env' => "APP_NAME=Shop\nMAIL_FROM_NAME=\"\\\${APP_NAME}\"\nCERT=\"one\\ntwo\"\nNPM_TOKEN=abc",
        'env_flags' => ['NPM_TOKEN' => 'build'],
        'free_address' => true,
        'build' => [
            'volumes' => [['name' => 'storage', 'path' => '/var/www/html/storage']],
            'release_command' => 'php artisan migrate --force',
            'health' => ['enabled' => true, 'path' => '/', 'expected_status' => 0, 'start_period_secs' => 120],
        ],
    ])->assertAccepted()
        ->assertJsonPath('domain', 'shop.203-0-113-7.sslip.io')
        ->assertJsonPath('appUrl', $workspace->url("/machines/{$machine->id}/services/app:shop"));

    $request = collect(Http::recorded())->map(fn (array $pair) => $pair[0])
        ->filter(fn ($r) => str_ends_with($r->url(), '/commands'))->last();
    $job = json_decode($request->body(), true)['command']['job'];

    expect($job['health'])->toMatchArray(['path' => '/', 'expected_status' => 0, 'start_period_secs' => 120])
        ->and($job['files']['.serveros/Dockerfile'])->toContain('serversideup/php:8.1-')
        ->and($job['volumes'])->toBe([['name' => 'storage', 'path' => '/var/www/html/storage']])
        ->and($job['release_command'])->toBe('php artisan migrate --force')
        ->and($job['domains'])->toBe(['shop.203-0-113-7.sslip.io'])
        ->and($job['env'])->toMatchArray(['APP_NAME' => 'Shop', 'MAIL_FROM_NAME' => '${APP_NAME}', 'CERT' => "one\ntwo", 'APP_ENV' => 'production'])
        ->and($job['env'])->not->toHaveKey('NPM_TOKEN')
        ->and($job['env']['APP_KEY'])->toStartWith('base64:')
        ->and($job['build']['args'])->toHaveKey('NPM_TOKEN');
});
