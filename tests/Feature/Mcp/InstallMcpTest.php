<?php

use App\Mcp\Install\InstallChecks;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    Http::fake([
        'api.github.com/repos/serveroshq/panel/releases/latest' => Http::response([
            'tag_name' => 'v1.9.0',
            'published_at' => '2026-11-18T09:58:29Z',
            'html_url' => 'https://github.com/serveroshq/panel/releases/tag/v1.9.0',
            'body' => 'Faster deploys.',
        ]),
    ]);
});

/** @param  array<string, mixed>  $params */
function installMcp(string $method, array $params = [], string $ip = '198.51.100.7'): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])
        ->postJson('https://serveros.test/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array<string, mixed>
 */
function installTool(string $name, array $arguments = []): array
{
    $response = installMcp('tools/call', ['name' => $name, 'arguments' => $arguments])->assertOk();

    return json_decode($response->json('result.content.0.text'), true);
}

/**
 * @param  array<string, list<string>>  $dns
 * @param  array<string, string>  $ports
 * @param  array{status: int|null, error: string|null}  $up
 */
function fakeInstallNetwork(array $dns = [], array $ports = [], array $up = ['status' => 200, 'error' => null]): void
{
    app()->instance(InstallChecks::class, new InstallChecks(
        resolver: fn (string $host) => $dns[$host] ?? (str_starts_with($host, 'serveros-check-') ? ($dns['*.'.substr($host, strpos($host, '.') + 1)] ?? []) : []),
        connector: fn (string $address, int $port) => $ports[$port] ?? 'open',
        fetcher: fn (string $domain, string $address) => $up,
    ));
}

$goodReport = <<<'TXT'
    os=Linux
    arch=x86_64
    distro=ubuntu 24.04
    cpus=2
    memory_mb=3911
    disk_free_mb=71000
    docker=none
    root=yes
    sudo=yes
    systemd=yes
    dir=/opt/serveros
    installed=no
    port_80=free
    port_443=free
    port_8443=free
    public_ipv4=203.0.113.10
    public_ipv6=
    TXT;

test('it introduces itself with instructions, six tools and an install prompt', function () {
    installMcp('initialize', ['protocolVersion' => '2025-06-18'])
        ->assertOk()
        ->assertJsonPath('result.serverInfo.name', 'serveros')
        ->assertJsonPath('result.protocolVersion', '2025-06-18')
        ->assertJsonPath('result.capabilities.prompts.listChanged', false);

    $tools = collect(installMcp('tools/list')->json('result.tools'))->pluck('name')->all();
    expect($tools)->toBe(['plan_install', 'check_server', 'check_dns', 'check_ports', 'check_panel', 'get_release']);

    installMcp('prompts/get', ['name' => 'install', 'arguments' => ['where' => 'root@203.0.113.10']])
        ->assertJsonPath('result.messages.0.role', 'user')
        ->assertJsonPath('result.messages.0.content.text', fn (string $text) => str_contains($text, 'root@203.0.113.10') && str_contains($text, 'plan_install'));
});

test('the plan asks where first, then what a server install needs', function () {
    $plan = installTool('plan_install');
    expect($plan['ready'])->toBeFalse()
        ->and(array_column($plan['questions'], 'id'))->toBe(['target'])
        ->and(array_column($plan['questions'][0]['options'], 'value'))->toBe(['server', 'this_computer', 'local'])
        ->and($plan['release']['version'])->toBe('1.9.0');

    $plan = installTool('plan_install', ['target' => 'server']);
    expect(array_column($plan['questions'], 'id'))->toBe(['ssh', 'domain', 'email', 'tunnel']);

    $plan = installTool('plan_install', ['target' => 'server', 'ssh' => 'root@203.0.113.10', 'domain' => 'https://Panel.Example.com/', 'email' => 'me@example.com']);
    expect($plan['ready'])->toBeTrue()
        ->and($plan['answers']['domain'])->toBe('panel.example.com')
        ->and($plan['steps'][0]['run'])->toBe("ssh root@203.0.113.10 'curl -fsSL https://raw.githubusercontent.com/serveroshq/panel/main/docker/self-hosted/install.sh | bash -s -- --check'")
        ->and($plan['steps'][3]['run'])->toBe("ssh -t root@203.0.113.10 'curl -fsSL https://raw.githubusercontent.com/serveroshq/panel/main/docker/self-hosted/install.sh | sudo bash -s -- --domain=panel.example.com --email=me@example.com --version=1.9.0'")
        ->and($plan['steps'][3]['confirm_first'])->toContain('only after they say yes')
        ->and(array_column($plan['steps'], 'tool'))->toBe(['check_dns', 'check_ports', 'check_panel'])
        ->and($plan['afterwards']['upgrade'])->toBe("ssh root@203.0.113.10 'sudo serveros-panel upgrade'");
});

test('a local install needs no questions past where', function () {
    $plan = installTool('plan_install', ['target' => 'local']);

    expect($plan['ready'])->toBeTrue()
        ->and($plan['answers']['domain'])->toBe('serveros.localhost')
        ->and($plan['steps'][1]['run'])->toBe('curl -fsSL https://raw.githubusercontent.com/serveroshq/panel/main/docker/self-hosted/install.sh | bash -s -- --local --version=1.9.0')
        ->and($plan['steps'][2]['tell_the_person'])->toContain('https://serveros.localhost');
});

test('behind a tunnel it asks for the gateway name and keeps the token out of the chat', function () {
    $plan = installTool('plan_install', ['target' => 'this_computer', 'domain' => 'panel.example.com', 'tunnel' => true]);
    expect(array_column($plan['questions'], 'id'))->toBe(['gateway_host']);

    $plan = installTool('plan_install', ['target' => 'this_computer', 'domain' => 'panel.example.com', 'tunnel' => true, 'gateway_host' => 'gateway.example.com']);
    expect($plan['ready'])->toBeTrue()
        ->and($plan['steps'][3]['run'])->toContain('--tunnel-token="$CLOUDFLARE_TUNNEL_TOKEN"')
        ->and($plan['notes'][0])->toContain('never paste it into the chat');
});

test('answers that could smuggle in commands are turned away', function () {
    $plan = installTool('plan_install', ['target' => 'server', 'ssh' => 'root@host; rm -rf /', 'domain' => 'panel.example.com$(id)', 'email' => 'a@b.co`id`']);

    expect($plan['ready'])->toBeFalse()
        ->and(array_column($plan['questions'], 'id'))->toBe(['ssh', 'domain', 'email', 'tunnel'])
        ->and($plan['problems'])->toHaveCount(3);
});

test('it reads the server check and says what to fix', function () use ($goodReport) {
    $ok = installTool('check_server', ['report' => $goodReport]);
    expect($ok['status'])->toBe('ok')
        ->and($ok['can_install'])->toBeTrue()
        ->and($ok['server_ip'])->toBe('203.0.113.10');

    $busy = installTool('check_server', ['report' => str_replace(['port_443=free', 'sudo=yes', 'root=yes'], ['port_443=busy:nginx', 'sudo=no', 'root=no'], $goodReport)]);
    expect($busy['status'])->toBe('fail')
        ->and($busy['needs_person'])->toBeTrue()
        ->and(collect($busy['lines'])->pluck('text')->implode(' '))->toContain('Port 443 is in use (nginx)');

    $mac = "os=Darwin\narch=arm64\ncpus=8\nmemory_mb=16384\ndisk_free_mb=30000\ndocker=29.3.1\ncompose=5.1.1\nroot=no\nsudo=no\ninstalled=no\nport_80=free\nport_443=free\nport_8443=free";
    expect(installTool('check_server', ['report' => $mac])['status'])->toBe('fail')
        ->and(installTool('check_server', ['report' => $mac, 'local' => true])['status'])->toBe('ok');

    expect(installTool('check_server', ['report' => 'hello'])['can_install'])->toBeFalse();
});

test('it checks DNS, the wildcard and Cloudflare’s proxy', function () {
    fakeInstallNetwork(dns: ['panel.example.com' => ['203.0.113.10'], '*.panel.example.com' => ['203.0.113.10']]);
    expect(installTool('check_dns', ['domain' => 'panel.example.com', 'server_ip' => '203.0.113.10'])['status'])->toBe('ok');

    fakeInstallNetwork(dns: ['panel.example.com' => ['203.0.113.10']]);
    $missing = installTool('check_dns', ['domain' => 'panel.example.com', 'server_ip' => '203.0.113.10']);
    expect($missing['status'])->toBe('fail')
        ->and($missing['records'][1])->toBe(['type' => 'A', 'name' => '*.panel.example.com', 'value' => '203.0.113.10', 'proxy' => 'DNS only (grey cloud on Cloudflare)']);

    fakeInstallNetwork(dns: ['panel.example.com' => ['104.16.1.1'], '*.panel.example.com' => ['104.16.1.1']]);
    $proxied = installTool('check_dns', ['domain' => 'panel.example.com', 'server_ip' => '203.0.113.10']);
    expect($proxied['status'])->toBe('fail')
        ->and($proxied['lines'][0]['text'])->toContain('Cloudflare’s proxy');
});

test('it checks ports from the internet, only on public addresses', function () {
    fakeInstallNetwork(ports: [80 => 'closed', 443 => 'closed', 8443 => 'blocked']);
    $ports = installTool('check_ports', ['host' => '203.0.113.10']);
    expect($ports['status'])->toBe('fail')
        ->and($ports['ports'])->toBe(['80' => 'closed', '443' => 'closed', '8443' => 'blocked']);

    fakeInstallNetwork(dns: ['intranet.example.com' => ['10.0.0.5']]);
    expect(installTool('check_ports', ['host' => '127.0.0.1'])['address'])->toBeNull()
        ->and(installTool('check_ports', ['host' => '169.254.169.254'])['address'])->toBeNull()
        ->and(installTool('check_ports', ['host' => 'intranet.example.com'])['address'])->toBeNull();
});

test('it checks the finished panel', function () {
    fakeInstallNetwork(dns: ['panel.example.com' => ['203.0.113.10']]);
    expect(installTool('check_panel', ['domain' => 'panel.example.com'])['status'])->toBe('ok');

    fakeInstallNetwork(dns: ['panel.example.com' => ['203.0.113.10']], ports: [8443 => 'blocked'], up: ['status' => null, 'error' => 'cURL error 60: SSL certificate problem']);
    $panel = installTool('check_panel', ['domain' => 'panel.example.com']);
    expect($panel['status'])->toBe('fail')
        ->and($panel['lines'][0]['status'])->toBe('warn')
        ->and($panel['lines'][1]['status'])->toBe('fail');
});

test('network checks are limited per address', function () {
    fakeInstallNetwork(dns: ['panel.example.com' => ['203.0.113.10']]);
    foreach (range(1, 20) as $i) {
        installMcp('tools/call', ['name' => 'check_panel', 'arguments' => ['domain' => 'panel.example.com']], '198.51.100.9');
    }

    installMcp('tools/call', ['name' => 'check_panel', 'arguments' => ['domain' => 'panel.example.com']], '198.51.100.9')
        ->assertJsonPath('result.isError', true);
    installMcp('tools/call', ['name' => 'plan_install', 'arguments' => []], '198.51.100.9')
        ->assertJsonPath('result.isError', false);
});

test('it isn’t there on self-hosted panels, and answers POST only', function () {
    config()->set('serveros.edition', 'self-hosted');
    expect(installMcp('initialize')->status())->toBeIn([404, 503]);

    config()->set('serveros.edition', 'cloud');
    test()->getJson('https://serveros.test/mcp')->assertStatus(405);
});
