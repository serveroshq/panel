<?php

use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\McpToken;
use App\Models\McpToolCall;
use App\Models\Operation;
use App\Models\Workspace;
use App\Support\Operations\Operations;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

const KINDS_OLD = 'aaaaaaaaaaaa1111111111111111111111111111';
const KINDS_NEW = 'bbbbbbbbbbbb2222222222222222222222222222';

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202), '*' => Http::response('', 404)]);
});

/** @return array{0: Workspace, 1: string} */
function kindsWorkspace(array $scopes = Scopes::ALL, bool $twoFactor = true): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    if ($twoFactor) {
        $workspace->owner->forceFill(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()])->save();
    }
    foreach (['web-1', 'web-2'] as $name) {
        Machine::factory()->for($workspace)->create(['name' => $name])->forceFill([
            'uid' => (string) Str::uuid(),
            'last_seen_at' => now(),
            'daemon_version' => 'v0.2.0',
            'labels' => ['env' => 'prod'],
            'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
                ['key' => 'docker:aaa', 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
            ]],
        ])->save();
    }
    [, $key] = McpToken::issueKey($workspace, $workspace->owner, 'CI', $scopes, McpToken::API);

    return [$workspace, $key];
}

function kindsOperation(string $key, array $body): TestResponse
{
    return test()->flushHeaders()->withHeader('Authorization', "Bearer {$key}")
        ->postJson('/api/v1/operations', ['selector' => ['labels' => ['env' => 'prod']], ...$body]);
}

function kindsStates(int $id): array
{
    return Operation::query()->findOrFail($id)->targets()->pluck('state', 'machine_name')->all();
}

test('deploy runs deploy_template on every machine under one name, or deploy_from_git without a template', function () {
    [, $key] = kindsWorkspace();

    $id = kindsOperation($key, ['kind' => 'deploy', 'deploy' => ['template' => 'postgres', 'name' => 'db'], 'strategy' => ['batch_size' => 1]])
        ->assertOk()->json('data.operation.id');
    expect(kindsStates($id))->toBe(['web-1' => 'running', 'web-2' => 'pending'])
        ->and(DaemonJob::query()->where('kind', 'deploy')->sole()->service)->toBe('db');

    // From a repository: deploy_from_git is the tool each machine runs (and here it can't reach the repository).
    $git = kindsOperation($key, ['kind' => 'deploy', 'machines' => ['web-1'], 'selector' => null, 'deploy' => ['repo' => 'https://github.com/acme/private-shop', 'name' => 'shop2', 'port' => 3000]])
        ->assertOk()->json('data.operation');
    expect($git['machines'][0]['state'])->toBe('failed')
        ->and(McpToolCall::query()->where('tool', 'deploy_from_git')->count())->toBe(1);

    foreach ([
        ['template' => 'postgres'],
        ['name' => 'db'],
        ['repo' => 'https://github.com/acme/shop', 'name' => 'shop'],
        ['template' => 'postgres', 'name' => 'Not A Name'],
    ] as $params) {
        kindsOperation($key, ['kind' => 'deploy', 'deploy' => $params])->assertStatus(422);
    }
});

test('rollback takes each machine back to the version before its live one, after confirming the name', function () {
    [$workspace, $key] = kindsWorkspace();
    $at = now()->subDay();
    foreach ($workspace->machines as $machine) {
        $inventory = $machine->inventory;
        $inventory['services'] = [
            ['key' => 'docker:old', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'kind' => 'app', 'status' => 'stopped', 'details' => ['managed' => 'true']],
            ['key' => 'docker:new', 'name' => 'serveros-shop-bbbbbbbbbbbb', 'kind' => 'app', 'status' => 'running', 'details' => ['managed' => 'true']],
        ];
        $machine->forceFill(['inventory' => $inventory])->save();
        foreach ([KINDS_OLD => $at, KINDS_NEW => $at->addHours(20)] as $commit => $when) {
            DaemonJob::query()->create([
                'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded',
                'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => $commit]],
                'result' => ['commit' => $commit],
            ])->forceFill(['created_at' => $when])->save();
        }
        if ($machine->name === 'web-2') {
            // web-2 only kept its live version.
            DaemonJob::query()->where('machine_id', $machine->id)->get()->filter(fn (DaemonJob $j) => ($j->command['job']['commit'] ?? null) === KINDS_OLD)->each->delete();
        }
    }

    kindsOperation($key, ['kind' => 'rollback', 'rollback' => ['app' => 'shop']])->assertStatus(422)
        ->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'confirm'));

    $operation = kindsOperation($key, ['kind' => 'rollback', 'rollback' => ['app' => 'shop', 'confirm' => 'shop'], 'strategy' => ['stop_on_failure' => false]])
        ->assertOk()->json('data.operation');

    expect(collect($operation['machines'])->pluck('state', 'machine.name')->all())->toBe(['web-1' => 'running', 'web-2' => 'failed'])
        ->and($operation['machines'][1]['outcome'])->toContain('no earlier version');
    $rollback = DaemonJob::query()->findOrFail($operation['machines'][0]['jobId']);
    expect(json_encode($rollback->command))->toContain(KINDS_OLD)->not->toContain(KINDS_NEW);
});

test('command runs in each container, needs the commands scope and two-factor, and a non-zero exit fails that machine', function () {
    [$workspace, $key] = kindsWorkspace();
    $id = kindsOperation($key, ['kind' => 'command', 'command' => ['service' => 'shop', 'command' => 'php artisan cache:clear'], 'strategy' => ['stop_on_failure' => false]])
        ->assertOk()->json('data.operation.id');

    $jobs = DaemonJob::query()->where('kind', 'service_exec')->get();
    expect($jobs)->toHaveCount(2)
        ->and($jobs->pluck('command.job.command')->unique()->all())->toBe(['php artisan cache:clear']);
    $jobs[0]->forceFill(['state' => 'succeeded', 'finished_at' => now(), 'result' => ['exit_code' => 0]])->save();
    $jobs[1]->forceFill(['state' => 'succeeded', 'finished_at' => now(), 'result' => ['exit_code' => 3]])->save();
    app(Operations::class)->tick();

    $states = kindsStates($id);
    sort($states);
    expect($states)->toBe(['failed', 'succeeded'])
        ->and(Operation::query()->findOrFail($id)->targets()->where('state', 'failed')->value('outcome'))->toBe('The command exited with code 3.');

    [, $writeKey] = McpToken::issueKey($workspace, $workspace->owner, 'write', [Scopes::READ, Scopes::WRITE], McpToken::API);
    kindsOperation($writeKey, ['kind' => 'command', 'command' => ['service' => 'shop', 'command' => 'ls']])->assertStatus(422)
        ->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'run commands'));

    [, $noTwoFactor] = kindsWorkspace(twoFactor: false);
    kindsOperation($noTwoFactor, ['kind' => 'command', 'command' => ['service' => 'shop', 'command' => 'ls']])->assertStatus(422)
        ->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'two-factor'));
    expect(DaemonJob::query()->where('kind', 'service_exec')->count())->toBe(2);
});

test('update_machines sets labels, tier and environment, and moves machines into a group', function () {
    [$workspace, $key] = kindsWorkspace();
    $group = $workspace->machineGroups()->create(['name' => 'eu-web']);

    kindsOperation($key, ['kind' => 'update_machines', 'update_machines' => ['labels' => ['app' => 'shop', 'env' => null], 'tier' => 'web', 'group' => 'eu-web']])
        ->assertOk()->assertJsonPath('data.operation.state', 'finished')->assertJsonPath('data.operation.progress.succeeded', 2);

    foreach ($workspace->machines()->get() as $machine) {
        expect($machine->labels)->toBe(['app' => 'shop'])
            ->and($machine->tier)->toBe('web')
            ->and($machine->machine_group_id)->toBe($group->id);
    }

    $other = $workspace->machineGroups()->create(['name' => 'us-web']);
    kindsOperation($key, ['kind' => 'update_machines', 'selector' => ['labels' => ['app' => 'shop']], 'update_machines' => ['group' => 'us-web']])
        ->assertOk()->assertJsonPath('data.operation.progress.succeeded', 2);
    expect($workspace->machines()->pluck('machine_group_id')->unique()->all())->toBe([$other->id]);

    kindsOperation($key, ['kind' => 'update_machines', 'selector' => ['labels' => ['app' => 'shop']], 'update_machines' => []])->assertStatus(422);
    kindsOperation($key, ['kind' => 'update_machines', 'selector' => ['labels' => ['app' => 'shop']], 'update_machines' => ['environment' => 'moon']])->assertStatus(422);
});

test('a deployed stack counts as healthy only while every container in it is running', function () {
    [$workspace, $key] = kindsWorkspace();
    $id = kindsOperation($key, ['kind' => 'deploy', 'deploy' => ['template' => 'postgres', 'name' => 'tools'], 'strategy' => ['wait_healthy_seconds' => 60, 'stop_on_failure' => false]])
        ->assertOk()->json('data.operation.id');
    DaemonJob::query()->where('kind', 'deploy')->update(['state' => 'succeeded', 'finished_at' => now()]);

    foreach ($workspace->machines as $machine) {
        $inventory = $machine->inventory;
        $inventory['services'] = [
            ['key' => 'docker:t1', 'name' => 'serveros-tools-web-1', 'kind' => 'app', 'status' => 'running', 'details' => ['managed' => 'true', 'compose_project' => 'serveros-tools']],
            ['key' => 'docker:t2', 'name' => 'serveros-tools-worker-1', 'kind' => 'app', 'status' => $machine->name === 'web-2' ? 'exited' : 'running', 'details' => ['managed' => 'true', 'compose_project' => 'serveros-tools']],
        ];
        $machine->forceFill(['inventory' => $inventory])->save();
    }
    app(Operations::class)->tick();
    expect(kindsStates($id))->toBe(['web-1' => 'checking', 'web-2' => 'checking']);

    app(Operations::class)->tick();
    expect(kindsStates($id))->toBe(['web-1' => 'checking', 'web-2' => 'failed'])
        ->and(Operation::query()->findOrFail($id)->targets()->where('machine_name', 'web-2')->value('outcome'))->toContain('tools stopped running')->toContain('exited');

    $this->travel(61)->seconds();
    app(Operations::class)->tick();
    expect(kindsStates($id)['web-1'])->toBe('succeeded');
});
