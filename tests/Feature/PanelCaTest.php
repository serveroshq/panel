<?php

use App\Mcp\Tools\Machine\AddMachine;
use App\Models\DaemonEnrolment;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;

beforeEach(function () {
    config()->set('daemon.install_ready', true);
    $this->dir = sys_get_temp_dir().'/serveros-panel-ca-'.bin2hex(random_bytes(5));
    mkdir($this->dir, 0755);

    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    $cert = openssl_csr_sign(openssl_csr_new(['commonName' => 'Caddy Local Authority'], $key), null, $key, 3650);
    openssl_x509_export($cert, $this->cert);
    openssl_pkey_export($key, $this->key);
});

afterEach(fn () => File::deleteDirectory($this->dir));

function panelCaFile(string $contents): string
{
    $path = test()->dir.'/panel-ca.crt';
    file_put_contents($path, $contents);
    config()->set('daemon.panel_ca', $path);

    return $path;
}

test('a panel on a public certificate gives the plain install command', function () {
    $workspace = Workspace::factory()->create();

    $command = $this->actingAs($workspace->owner)->postJson('https://'.$workspace->host().'/machines/connect')->assertOk()->json('command');

    expect($command)->toStartWith('curl -fsSL ')
        ->not->toContain('panel-ca');
    $this->get('/panel-ca.crt')->assertNotFound();
});

test('a panel on its own certificate pins its CA in the install command, and serves the same CA', function () {
    panelCaFile($this->cert);
    $workspace = Workspace::factory()->create();

    $command = $this->actingAs($workspace->owner)->postJson('https://'.$workspace->host().'/machines/connect')->assertOk()->json('command');
    $served = $this->get('/panel-ca.crt')->assertOk()->assertHeader('Content-Type', 'application/x-x509-ca-cert');
    $hash = hash('sha256', $served->getContent());
    $panel = rtrim((string) config('daemon.panel_url'), '/');

    expect($command)->toStartWith("curl -fsSLk -o serveros-panel-ca.crt '{$panel}/panel-ca.crt' && echo '{$hash}  serveros-panel-ca.crt' | sha256sum -c && curl -fsSL --cacert serveros-panel-ca.crt ")
        ->toContain("--panel-ca-sha256={$hash}")
        ->toEndWith(' && rm -f serveros-panel-ca.crt');
});

test('the install command waits while the panel’s CA isn’t written yet', function () {
    config()->set('daemon.panel_ca', $this->dir.'/not-yet.crt');
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->postJson('https://'.$workspace->host().'/machines/connect')
        ->assertStatus(503)
        ->assertJson(['error' => 'The panel’s own certificate isn’t ready yet. Try again in a minute.']);
    expect(DaemonEnrolment::query()->count())->toBe(0);
});

test('only the certificates in the CA file are served, never a key next to them', function () {
    panelCaFile($this->key.$this->cert);

    $body = $this->get('/panel-ca.crt')->assertOk()->getContent();

    expect($body)->toBe($this->cert)
        ->not->toContain('PRIVATE KEY');

    panelCaFile($this->key);
    $this->get('/panel-ca.crt')->assertNotFound();
});

test('the MCP tool tells provisioning scripts the pinned command too', function () {
    panelCaFile($this->cert);

    expect(app(AddMachine::class)->description())->toContain('--panel-ca-sha256='.hash('sha256', $this->cert))
        ->toContain('--cacert serveros-panel-ca.crt');
});
