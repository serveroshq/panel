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
