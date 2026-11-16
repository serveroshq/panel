<?php

use App\Enums\Plan;
use App\Mcp\Tools\ServiceTools;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\ServiceGroup;
use App\Models\ServiceSample;
use App\Models\Workspace;
use App\Support\Monitors\HostResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(fn () => config()->set('mcp.groups', [ServiceTools::class]));

function servicesSchemaSetup(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Storage::fake('public');
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        public function resolve(string $host): array
        {
            return ['93.184.215.14'];
        }
    });
    $sha = str_repeat('a', 40);
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'https://cdn.example.com/logo.png' => Http::response(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='), 200, ['Content-Type' => 'image/png']),
        'api.github.com/repos/acme/app' => Http::response(['default_branch' => 'main']),
        'api.github.com/repos/acme/app/commits/main' => Http::response(['sha' => $sha]),
        "api.github.com/repos/acme/app/git/trees/{$sha}" => Http::response(['tree' => [['path' => 'composer.json'], ['path' => 'artisan']]]),
        "raw.githubusercontent.com/acme/app/{$sha}/composer.json" => Http::response(json_encode(['require' => ['laravel/framework' => '^12.0']])),
        "raw.githubusercontent.com/acme/app/{$sha}/artisan" => Http::response(''),
        '*' => Http::response(null, 404),
    ]);

    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create(['name' => 'Acme']);
    $workspace->owner->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => 'v0.2.0',
        'service_stats' => ['docker:aaa' => ['cpu' => 1.5, 'memUsed' => 1000, 'memLimit' => null, 'pids' => 3, 'netRx' => 10, 'netTx' => 20, 'startedAt' => 1, 'restarts' => 0, 'at' => 2]],
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:aaa', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [8080], 'confidence' => 90, 'capabilities' => ['logs'], 'details' => ['managed' => 'true', 'compose_project' => 'shop', 'image' => 'shop:1']],
            ['key' => 'docker:bbb', 'name' => 'shop-worker', 'kind' => 'app', 'manager' => 'docker', 'status' => 'exited', 'ports' => [], 'details' => ['managed' => 'true', 'compose_project' => 'shop']],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx.service', 'kind' => 'web_server', 'manager' => 'systemd', 'status' => 'running', 'ports' => [80], 'details' => []],
        ]],
    ])->save();
    Machine::factory()->for($workspace)->create(['name' => 'old-1', 'uid' => (string) Str::uuid(), 'last_seen_at' => now()->subHour()]);

    return [$workspace, $machine, mcpKey($workspace)];
}

/** @param  array<string, mixed>  $changes */
function finishJobsWith(array $changes): void
{
    DaemonJob::created(fn (DaemonJob $job) => DaemonJob::query()->whereKey($job->id)->update([...$changes, 'state' => 'succeeded', 'finished_at' => now()]));
}

test('service reads answer as documented', function () {
    [$workspace, $machine, $key] = servicesSchemaSetup();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded', 'log' => [],
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40), 'domains' => ['shop.example.com']]],
    ]);
    foreach ([60, 30, 0] as $ago) {
        ServiceSample::query()->create(['machine_id' => $machine->id, 'service' => 'docker:aaa', 'ts' => now()->getTimestamp() - $ago, 'cpu' => 2.5, 'mem_used' => 1000, 'net_rx' => 100 + $ago, 'net_tx' => 200]);
    }
    ServiceGroup::put($machine, new ServiceGroup, 'Web', ['systemd:nginx.service', 'docker:gone']);

    expect(mcpCall($workspace, $key, 'list_services', [])['error'])->toBeFalse()
        ->and(mcpCall($workspace, $key, 'get_service', ['machine' => 'web-1', 'service' => 'docker:aaa'])['data']['usage']['samples'])->toBe(3)
        ->and(mcpCall($workspace, $key, 'get_service', ['machine' => 'web-1', 'service' => 'nginx'])['error'])->toBeFalse();

    $groups = mcpCall($workspace, $key, 'list_service_groups', ['machine' => 'web-1']);
    expect($groups['data']['groups'][0]['missing'])->toBe(['docker:gone'])
        ->and($groups['data']['stacks'][0]['composeProject'])->toBe('shop');

    expect(mcpCall($workspace, $key, 'detect_services', ['repository' => 'acme/app'])['data']['type'])->toBe('Web app');
});

test('icons and service actions answer as documented', function () {
    [$workspace, , $key] = servicesSchemaSetup();
    $machine = Machine::query()->where('name', 'web-1')->sole();
    ServiceGroup::put($machine, new ServiceGroup, 'Web', ['systemd:nginx.service']);
    $service = ['machine' => 'web-1', 'service' => 'docker:aaa'];

    foreach ([
        ['set_service_group_icon', ['machine' => 'web-1', 'group' => 'Web', 'url' => 'https://cdn.example.com/logo.png']],
        ['remove_service_group_icon', ['machine' => 'web-1', 'group' => 'Web']],
        ['set_service_icon', [...$service, 'url' => 'https://cdn.example.com/logo.png']],
        ['remove_service_icon', $service],
        ['start_service', $service],
        ['stop_service', [...$service, 'confirm' => 'serveros-shop-aaaaaaaaaaaa']],
        ['reload_service', $service],
        ['get_service_logs', [...$service, 'wait' => 0]],
        ['run_command', [...$service, 'command' => 'ls', 'wait' => 0]],
    ] as [$tool, $arguments]) {
        $result = mcpCall($workspace, $key, $tool, $arguments);
        expect($result['error'])->toBeFalse("{$tool}: {$result['text']}");
    }
});

test('logs and commands that finish answer as documented', function () {
    [$workspace, , $key] = servicesSchemaSetup();
    finishJobsWith(['result' => json_encode(['lines' => ['one', 'two'], 'exit_code' => 0, 'stdout' => "one\ntwo"])]);

    expect(mcpCall($workspace, $key, 'get_service_logs', ['machine' => 'web-1', 'service' => 'docker:aaa', 'wait' => 1])['data']['lines'])->toBe(['one', 'two'])
        ->and(mcpCall($workspace, $key, 'run_command', ['machine' => 'web-1', 'service' => 'docker:aaa', 'command' => 'ls', 'wait' => 1])['data']['exitCode'])->toBe(0);
});

test('retrying a deploy answers as documented', function () {
    [$workspace, $machine, $key] = servicesSchemaSetup();
    $failed = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'failed', 'log' => [],
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40)]],
    ]);

    $result = mcpCall($workspace, $key, 'retry_job', ['job' => $failed->id]);
    expect($result['error'])->toBeFalse($result['text']);
});
