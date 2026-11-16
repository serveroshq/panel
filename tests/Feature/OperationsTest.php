<?php

use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\McpToken;
use App\Models\Operation;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Operations\Operations;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202), '*' => Http::response('ok')]);
});

/** @return array{0: Workspace, 1: string} */
function operationsWorkspace(int $prod = 3, array $scopes = Scopes::ALL): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    foreach (range(1, $prod) as $n) {
        operationsMachine($workspace, "web-{$n}", ['env' => 'prod']);
    }
    operationsMachine($workspace, 'staging-1', ['env' => 'staging']);
    [, $key] = McpToken::issueKey($workspace, $workspace->owner, 'CI', $scopes, McpToken::API);

    return [$workspace, $key];
}

function operationsMachine(Workspace $workspace, string $name, array $labels, string $status = 'running'): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name]);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'labels' => $labels,
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            ['key' => 'docker:aaa', 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => $status, 'ports' => [], 'details' => ['managed' => 'true']],
        ]],
    ])->save();

    return $machine;
}

function operationsApi(string $key, string $method, string $path, array $body = [], array $headers = []): TestResponse
{
    return test()->flushHeaders()->withHeaders(['Authorization' => "Bearer {$key}", ...$headers])->json($method, "/api/v1/{$path}", $body);
}

function restartProd(string $key, array $strategy = [], array $extra = []): TestResponse
{
    return operationsApi($key, 'POST', 'operations', [
        'kind' => 'service_action',
        'selector' => ['labels' => ['env' => 'prod']],
        'service_action' => ['service' => 'shop', 'action' => 'restart'],
        'strategy' => $strategy,
        ...$extra,
    ]);
}

/** Every job the operation started on these machines finishes with this state. */
function finishJobs(Operation $operation, string $state = 'succeeded', ?string $machine = null): void
{
    $ids = $operation->targets()->where('state', 'running')
        ->when($machine, fn ($q) => $q->where('machine_name', $machine))->pluck('job_id');
    DaemonJob::query()->whereIn('id', $ids)->update([
        'state' => $state,
        'finished_at' => now(),
        'error' => $state === 'failed' ? json_encode(['message' => 'The container exited with code 1']) : null,
    ]);
}

function tickOperations(): void
{
    app(Operations::class)->tick();
}

function targetStates(Operation $operation): array
{
    return $operation->targets()->pluck('state', 'machine_name')->all();
}

test('an operation restarts a service in batches, and the next batch waits for the one before', function () {
    [$workspace, $key] = operationsWorkspace();

    $answer = restartProd($key, ['batch_size' => 2])->assertAccepted()
        ->assertJsonPath('data.operation.kind', 'service_action')
        ->assertJsonPath('data.operation.state', 'running')
        ->assertJsonPath('data.operation.strategy.machinesPerBatch', 2)
        ->assertJsonPath('data.operation.progress.total', 3)
        ->assertJsonPath('data.operation.progress.running', 2);
    $operation = Operation::query()->findOrFail($answer->json('data.operation.id'));

    expect(targetStates($operation))->toBe(['web-1' => 'running', 'web-2' => 'running', 'web-3' => 'pending'])
        ->and(DaemonJob::query()->where('kind', 'service_action')->count())->toBe(2);

    tickOperations();
    expect(targetStates($operation)['web-3'])->toBe('pending');

    finishJobs($operation, 'succeeded', 'web-1');
    tickOperations();
    expect(targetStates($operation)['web-3'])->toBe('pending');

    finishJobs($operation);
    tickOperations();
    expect(targetStates($operation))->toBe(['web-1' => 'succeeded', 'web-2' => 'succeeded', 'web-3' => 'running']);

    finishJobs($operation);
    tickOperations();
    operationsApi($key, 'GET', "operations/{$operation->id}")->assertOk()
        ->assertJsonPath('data.operation.state', 'finished')
        ->assertJsonPath('data.operation.progress.succeeded', 3)
        ->assertJsonPath('data.operation.machines.2.batch', 2)
        ->assertJsonPath('data.operation.machines.2.outcome', 'Done.');

    // One line for the operation, and each machine's own line linked to it.
    $lines = Activity::query()->where('workspace_id', $workspace->id)->get();
    expect($lines->where('action', 'operation.start')->sole()->meta)->toMatchArray(['operation' => $operation->id, 'kind' => 'service_action', 'action' => 'restart', 'machines' => 3, 'batch' => 2, 'via' => 'api'])
        ->and($lines->where('action', 'service.restart')->pluck('meta.operation')->unique()->values()->all())->toBe([$operation->id])
        ->and($lines->where('action', 'service.restart')->count())->toBe(3)
        ->and(ActivityLine::describe($lines->where('action', 'service.restart')->first(), false))->toMatchArray(['detail' => "GET /api/v1/operations/{$operation->id}", 'mono' => true])
        ->and(ActivityLine::describe($lines->where('action', 'operation.start')->sole(), false)['detail'])->toBe("GET /api/v1/operations/{$operation->id}");

    operationsApi($key, 'GET', 'operations?state=finished')->assertOk()->assertJsonPath('data.total', 1)
        ->assertJsonMissingPath('data.operations.0.machines');
});

test('a dry run shows the machines the selector resolves to and the batches, and starts nothing', function () {
    [, $key] = operationsWorkspace();

    $resolved = collect(operationsApi($key, 'POST', 'selectors/resolve', ['selector' => ['labels' => ['env' => 'prod']]])->json('data.machines'))->pluck('name');
    $dry = restartProd($key, ['batch_size' => '50%'], ['dry_run' => true])->assertOk()
        ->assertJsonPath('data.dryRun', true)
        ->assertJsonPath('data.machinesPerBatch', 2);

    expect(collect($dry->json('data.machines'))->pluck('name')->all())->toBe($resolved->all())
        ->and(collect($dry->json('data.batches'))->map(fn ($b) => collect($b)->pluck('name')->all())->all())->toBe([['web-1', 'web-2'], ['web-3']])
        ->and(Operation::query()->count())->toBe(0)
        ->and(DaemonJob::query()->count())->toBe(0);
});

test('a failure pauses it; resume carries on, and cancel skips the rest while running jobs finish', function () {
    [, $key] = operationsWorkspace(4);
    $operation = Operation::query()->findOrFail(restartProd($key, ['batch_size' => 1])->json('data.operation.id'));

    finishJobs($operation, 'failed');
    tickOperations();
    $operation->refresh();
    expect($operation->state)->toBe('paused')
        ->and($operation->paused_reason)->toContain('web-1')
        ->and(targetStates($operation))->toBe(['web-1' => 'failed', 'web-2' => 'pending', 'web-3' => 'pending', 'web-4' => 'pending'])
        ->and($operation->targets()->first()->outcome)->toContain('exited with code 1');

    tickOperations();
    expect(targetStates($operation)['web-2'])->toBe('pending');

    operationsApi($key, 'POST', "operations/{$operation->id}/resume")->assertOk()->assertJsonPath('data.operation.state', 'running');
    expect(targetStates($operation)['web-2'])->toBe('running');

    operationsApi($key, 'POST', "operations/{$operation->id}/cancel")->assertOk()->assertJsonPath('data.operation.state', 'cancelled');
    expect(targetStates($operation))->toBe(['web-1' => 'failed', 'web-2' => 'running', 'web-3' => 'skipped', 'web-4' => 'skipped']);

    // The job it already started still gets recorded.
    finishJobs($operation);
    tickOperations();
    expect(targetStates($operation)['web-2'])->toBe('succeeded');

    operationsApi($key, 'POST', "operations/{$operation->id}/resume")->assertStatus(422);
    operationsApi($key, 'POST', "operations/{$operation->id}/pause")->assertStatus(422);

    $steered = Activity::query()->whereIn('action', ['operation.resume', 'operation.cancel'])->get();
    expect($steered->pluck('subject')->filter()->all())->toBe([])
        ->and($steered->pluck('meta.operation')->unique()->values()->all())->toBe([$operation->id]);
});

test('max_failures lets some machines fail, stop_on_failure false never pauses, and pause stops new batches', function () {
    [, $key] = operationsWorkspace(3);
    $operation = Operation::query()->findOrFail(restartProd($key, ['batch_size' => 1, 'max_failures' => 1])->json('data.operation.id'));
    finishJobs($operation, 'failed');
    tickOperations();
    expect($operation->refresh()->state)->toBe('running')->and(targetStates($operation)['web-2'])->toBe('running');
    finishJobs($operation, 'failed');
    tickOperations();
    expect($operation->refresh()->state)->toBe('paused');

    Operation::query()->update(['state' => 'cancelled']);
    $loose = Operation::query()->findOrFail(restartProd($key, ['batch_size' => 1, 'stop_on_failure' => false])->json('data.operation.id'));
    foreach (range(1, 3) as $ignored) {
        finishJobs($loose, 'failed');
        tickOperations();
    }
    expect($loose->refresh()->state)->toBe('finished')->and(array_values(targetStates($loose)))->toBe(['failed', 'failed', 'failed']);

    $held = Operation::query()->findOrFail(restartProd($key, ['batch_size' => 1])->json('data.operation.id'));
    operationsApi($key, 'POST', "operations/{$held->id}/pause")->assertOk()->assertJsonPath('data.operation.pausedReason', fn (string $r) => str_contains($r, 'Paused by'));
    finishJobs($held);
    tickOperations();
    expect(targetStates($held))->toBe(['web-1' => 'succeeded', 'web-2' => 'pending', 'web-3' => 'pending']);
});

test('a machine that’s offline at its turn is skipped', function () {
    [$workspace, $key] = operationsWorkspace(2);
    $workspace->machines()->where('name', 'web-2')->update(['last_seen_at' => now()->subHour()]);

    $operation = Operation::query()->findOrFail(restartProd($key, ['batch_size' => 1])->json('data.operation.id'));
    finishJobs($operation);
    tickOperations();

    expect(targetStates($operation))->toBe(['web-1' => 'succeeded', 'web-2' => 'skipped'])
        ->and($operation->refresh()->state)->toBe('finished')
        ->and($operation->targets()->where('machine_name', 'web-2')->value('outcome'))->toContain('offline');
});

test('maintenance starts and ends on every machine, with no job to wait for', function () {
    [$workspace, $key] = operationsWorkspace(2);
    $maintenance = fn (array $params) => operationsApi($key, 'POST', 'operations', ['kind' => 'maintenance', 'selector' => ['labels' => ['env' => 'prod']], 'maintenance' => $params]);

    $maintenance(['action' => 'start', 'minutes' => 30, 'reason' => 'Upgrading Postgres'])->assertAccepted()
        ->assertJsonPath('data.operation.state', 'finished')
        ->assertJsonPath('data.operation.progress.succeeded', 2);
    expect($workspace->machines()->get()->filter->inMaintenance()->pluck('name')->sort()->values()->all())->toBe(['web-1', 'web-2']);

    $maintenance(['action' => 'end'])->assertAccepted()->assertJsonPath('data.operation.progress.succeeded', 2);
    expect($workspace->machines()->get()->filter->inMaintenance()->all())->toBe([]);

    $maintenance(['action' => 'start', 'minutes' => 7])->assertStatus(422)->assertJsonPath('error.type', 'invalid_arguments');
});

test('wait_healthy_seconds also fails a machine whose monitor goes down while it waits, but not one that was already down', function () {
    [$workspace, $key] = operationsWorkspace(3);
    $machines = $workspace->machines()->orderBy('name')->get()->keyBy('name');
    $monitor = fn (string $machine, array $attributes) => $workspace->monitors()->create(['machine_id' => $machines[$machine]->id, 'name' => "{$machine} site", 'type' => 'http', 'url' => 'https://shop.example.com', 'interval_seconds' => 60, 'timeout_seconds' => 10, ...$attributes]);
    $monitor('web-1', ['status' => 'down', 'status_changed_at' => now()->subHour()]);
    $operation = Operation::query()->findOrFail(restartProd($key, ['wait_healthy_seconds' => 60])->json('data.operation.id'));

    finishJobs($operation);
    tickOperations();
    $this->travel(10)->seconds();
    $monitor('web-2', ['status' => 'down', 'status_changed_at' => now()]);
    $monitor('web-3', ['status' => 'down', 'status_changed_at' => now(), 'paused' => true]);

    tickOperations();
    expect(targetStates($operation))->toBe(['web-1' => 'checking', 'web-2' => 'failed', 'web-3' => 'checking'])
        ->and($operation->targets()->where('machine_name', 'web-2')->value('outcome'))->toContain('monitor web-2 site');

    $this->travel(61)->seconds();
    tickOperations();
    expect(targetStates($operation))->toMatchArray(['web-1' => 'succeeded', 'web-3' => 'succeeded']);
});

test('wait_healthy_seconds needs the service to stay running before a machine counts as done', function () {
    [$workspace, $key] = operationsWorkspace(2);
    $operation = Operation::query()->findOrFail(restartProd($key, ['wait_healthy_seconds' => 60])->json('data.operation.id'));

    finishJobs($operation);
    tickOperations();
    expect(array_values(targetStates($operation)))->toBe(['checking', 'checking']);

    $broken = $workspace->machines()->where('name', 'web-2')->sole();
    $inventory = $broken->inventory;
    $inventory['services'][0]['status'] = 'exited';
    $broken->forceFill(['inventory' => $inventory])->save();

    tickOperations();
    expect(targetStates($operation))->toBe(['web-1' => 'checking', 'web-2' => 'failed']);

    $this->travel(61)->seconds();
    tickOperations();
    expect(targetStates($operation)['web-1'])->toBe('succeeded')
        ->and($operation->targets()->where('machine_name', 'web-2')->value('outcome'))->toContain('exited')
        ->and($operation->refresh()->state)->toBe('paused');
});

test('deploy_version runs deploy_new_version on each machine, and its refusal fails that machine', function () {
    [, $key] = operationsWorkspace(2);

    $operation = Operation::query()->findOrFail(operationsApi($key, 'POST', 'operations', [
        'kind' => 'deploy_version',
        'machines' => ['web-1', 'web-2'],
        'deploy_version' => ['app' => 'blog', 'ref' => 'v2'],
        'strategy' => ['batch_size' => 1],
    ])->assertAccepted()->json('data.operation.id'));

    expect($operation->state)->toBe('paused')
        ->and(targetStates($operation))->toBe(['web-1' => 'failed', 'web-2' => 'pending'])
        ->and($operation->targets()->first()->outcome)->toContain('blog');
});

test('only keys and people allowed to make changes can start or steer one', function () {
    [$workspace, $readKey] = operationsWorkspace(2, [Scopes::READ]);
    restartProd($readKey)->assertForbidden()->assertJsonPath('error.type', 'forbidden');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    [, $viewerKey] = McpToken::issueKey($workspace, $viewer, 'viewer', Scopes::ALL, McpToken::API);
    restartProd($viewerKey)->assertForbidden();

    [, $key] = McpToken::issueKey($workspace, $workspace->owner, 'CI', Scopes::ALL, McpToken::API);
    $id = restartProd($key, ['batch_size' => 1])->assertAccepted()->json('data.operation.id');
    operationsApi($readKey, 'GET', "operations/{$id}")->assertOk();
    operationsApi($readKey, 'POST', "operations/{$id}/cancel")->assertForbidden();

    $other = Workspace::factory()->onPlan(Plan::Pro)->create();
    [, $otherKey] = McpToken::issueKey($other, $other->owner, 'CI', Scopes::ALL, McpToken::API);
    operationsApi($otherKey, 'GET', "operations/{$id}")->assertStatus(422);

    expect(DaemonJob::query()->count())->toBe(1);
});

test('a revoked key stops the rest from starting', function () {
    [, $key] = operationsWorkspace(2);
    $operation = Operation::query()->findOrFail(restartProd($key, ['batch_size' => 1])->json('data.operation.id'));

    McpToken::query()->where('audience', McpToken::API)->sole()->revoke();
    finishJobs($operation);
    tickOperations();

    expect(targetStates($operation))->toBe(['web-1' => 'succeeded', 'web-2' => 'skipped'])
        ->and($operation->targets()->where('machine_name', 'web-2')->value('outcome'))->toContain('revoked');
});

test('workspace limits cap machines per operation and operations at once', function () {
    [, $key] = operationsWorkspace(3);

    config()->set('api.operations.machines', 2);
    restartProd($key)->assertStatus(422)->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'up to 2'));

    config()->set('api.operations.machines', 500);
    config()->set('api.operations.running', 1);
    restartProd($key, ['batch_size' => 1])->assertAccepted();
    restartProd($key)->assertStatus(422)->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'at once'));
});

test('the same Idempotency-Key starts one operation', function () {
    [, $key] = operationsWorkspace(2);
    $body = ['kind' => 'service_action', 'selector' => ['labels' => ['env' => 'prod']], 'service_action' => ['service' => 'shop', 'action' => 'restart']];

    $first = operationsApi($key, 'POST', 'operations', $body, ['Idempotency-Key' => 'restart-shop'])->assertAccepted();
    operationsApi($key, 'POST', 'operations', $body, ['Idempotency-Key' => 'restart-shop'])->assertAccepted()
        ->assertHeader('Idempotent-Replayed', 'true')
        ->assertJsonPath('data.operation.id', $first->json('data.operation.id'));

    expect(Operation::query()->count())->toBe(1)->and(DaemonJob::query()->count())->toBe(2);
});

test('a request it can’t run is refused before anything starts', function () {
    [, $key] = operationsWorkspace(2);
    $post = fn (array $body) => operationsApi($key, 'POST', 'operations', $body);

    foreach ([
        ['kind' => 'reboot', 'machines' => ['web-1']],
        ['kind' => 'service_action', 'machines' => ['web-1']],
        ['kind' => 'service_action', 'machines' => ['web-1'], 'service_action' => ['service' => 'shop', 'action' => 'explode']],
        ['kind' => 'service_action', 'machines' => ['web-1'], 'service_action' => ['service' => 'shop', 'action' => 'stop', 'extra' => 1]],
        ['kind' => 'service_action', 'service_action' => ['service' => 'shop', 'action' => 'stop']],
        ['kind' => 'service_action', 'selector' => [], 'service_action' => ['service' => 'shop', 'action' => 'stop']],
        ['kind' => 'service_action', 'machines' => ['web-1'], 'service_action' => ['service' => 'shop', 'action' => 'stop'], 'strategy' => ['batch_size' => '0%']],
        ['kind' => 'service_action', 'machines' => ['web-1'], 'service_action' => ['service' => 'shop', 'action' => 'stop'], 'strategy' => ['wait_healthy_seconds' => 9999]],
    ] as $body) {
        $post($body)->assertStatus(422);
    }
    $post(['kind' => 'service_action', 'selector' => ['labels' => ['env' => 'dev']], 'service_action' => ['service' => 'shop', 'action' => 'stop']])
        ->assertStatus(422)->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'No machines'));

    expect(Operation::query()->count())->toBe(0)->and(DaemonJob::query()->count())->toBe(0);
});

test('only one advance at a time starts machines', function () {
    [, $key] = operationsWorkspace(2);
    $operation = Operation::query()->findOrFail(restartProd($key, ['batch_size' => 1])->json('data.operation.id'));
    finishJobs($operation);

    $lock = Cache::lock("operations:{$operation->id}", 300);
    $lock->get();
    tickOperations();
    expect(targetStates($operation))->toBe(['web-1' => 'running', 'web-2' => 'pending']);

    $lock->release();
    tickOperations();
    expect(targetStates($operation))->toBe(['web-1' => 'succeeded', 'web-2' => 'running'])
        ->and(DaemonJob::query()->count())->toBe(2);
});
