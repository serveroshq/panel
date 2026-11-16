<?php

use App\Enums\Plan;
use App\Models\BackupDestination;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Domains\DomainResolver;
use App\Support\Logs\LogIngest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @return array{0: Workspace, 1: Machine, 2: string} */
function deploySchemaSetup(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::preventStrayRequests();
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'api.github.com/repos/acme/shop' => Http::response(['default_branch' => 'main', 'private' => false, 'visibility' => 'public']),
        'api.github.com/repos/acme/shop/commits/*' => Http::response(['sha' => str_repeat('c', 40)]),
        'api.github.com/repos/acme/shop/git/trees/*' => Http::response(['tree' => [['path' => 'package.json'], ['path' => 'Dockerfile']]]),
        'raw.githubusercontent.com/*' => Http::response('{"name":"shop","dependencies":{"express":"^4.0.0"}}'),
    ]);

    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create(['name' => 'Acme']);
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '203.0.113.10']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => 'v0.2.0',
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:mc', 'name' => 'serveros-mc-mc-1', 'kind' => 'game_server', 'manager' => 'docker', 'status' => 'running', 'ports' => [25565], 'details' => ['managed' => 'true']],
        ]],
    ])->save();

    return [$workspace, $machine, mcpKey($workspace)];
}

test('the deploy tools answer as documented', function () {
    [$workspace, $machine, $key] = deploySchemaSetup();
    app()->instance(DomainResolver::class, new class extends DomainResolver
    {
        public function addresses(string $domain): array
        {
            return $domain === 'shop.example.com' ? ['203.0.113.10'] : [];
        }
    });

    expect(mcpCall($workspace, $key, 'detect_repository', ['repository' => 'acme/shop'])['data']['templateInfo'])->not->toBeNull()
        ->and(mcpCall($workspace, $key, 'check_domain', ['machine' => 'web-1', 'domain' => 'shop.example.com', 'wildcard' => true])['error'])->toBeFalse()
        ->and(mcpCall($workspace, $key, 'list_templates', ['template' => 'postgres'])['error'])->toBeFalse();

    $git = mcpCall($workspace, $key, 'deploy_from_git', ['machine' => 'web-1', 'repo' => 'https://github.com/acme/shop.git', 'ref' => str_repeat('a', 40), 'port' => 3000, 'domain' => 'shop.example.com']);
    expect($git['error'])->toBeFalse($git['text']);

    DaemonJob::query()->whereKey($git['data']['jobId'])->update(['state' => 'succeeded', 'finished_at' => now()]);
    $next = mcpCall($workspace, $key, 'deploy_new_version', ['machine' => 'web-1', 'app' => 'shop', 'ref' => str_repeat('b', 40)]);
    expect($next['error'])->toBeFalse($next['text']);
});

test('run_backup for every machine and search_logs around a line answer as documented', function () {
    [$workspace, $machine, $key] = deploySchemaSetup();
    $destination = new BackupDestination(['name' => 'R2', 'type' => 's3', 'settings' => [
        'endpoint' => 'acc.r2.cloudflarestorage.com', 'region' => 'auto', 'bucket' => 'worlds',
        'access_key' => 'key', 'secret_key' => 'secret', 'path_style' => true, 'prefix' => '',
    ]]);
    $destination->workspace_id = $workspace->id;
    $destination->status = 'ready';
    $destination->save();
    $machine->forceFill(['backup_destination_id' => $destination->id])->save();

    expect(mcpCall($workspace, $key, 'run_backup', ['machine' => '@all'])['error'])->toBeFalse();

    LogIngest::store($machine, ['lines' => [
        ['service' => 'docker:mc', 'name' => 'serveros-mc-mc-1', 'line' => 'starting'],
        ['service' => 'docker:mc', 'name' => 'serveros-mc-mc-1', 'line' => '{"level":"error","msg":"world failed to load","world":"main"}', 'stream' => 'stderr'],
    ]]);
    $around = mcpCall($workspace, $key, 'search_logs', ['around' => (int) mcpCall($workspace, $key, 'search_logs')['data']['entries'][0]['id']]);
    expect($around['error'])->toBeFalse()->and($around['data']['entries'])->toHaveCount(2);
});
