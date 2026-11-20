<?php

use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Mcp\Tools\AgentTools;
use App\Models\Activity;
use App\Models\AgentQueued;
use App\Models\AgentTask;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Workflows\AgentSpec;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

beforeEach(function () {
    config()->set('serveros.agents', true);
    config()->set('mcp.groups', [AgentTools::class]);
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($key, $pem);
    config()->set('services.github_app', [
        'id' => '12345', 'slug' => 'serveros', 'client_id' => 'Iv1.abc', 'client_secret' => 'shh',
        'private_key' => base64_encode($pem),
    ]);
    Http::fake([
        '127.0.0.1:8090/*' => Http::response(['queued' => true], 202),
        'api.github.com/repos/acme/shop/pulls/12' => Http::response(['state' => 'open', 'merged' => false, 'head' => ['sha' => str_repeat('c', 40), 'ref' => 'feature/coupons', 'repo' => ['full_name' => 'acme/shop']]]),
        'api.github.com/repos/acme/shop/pulls/13' => Http::response(['state' => 'open', 'merged' => false, 'head' => ['sha' => str_repeat('d', 40), 'ref' => 'patch-1', 'repo' => ['full_name' => 'someone/shop']]]),
        'api.github.com/repos/acme/shop/pulls/14' => Http::response(['state' => 'closed', 'merged' => false, 'head' => ['sha' => str_repeat('e', 40), 'ref' => 'old', 'repo' => ['full_name' => 'acme/shop']]]),
        '*' => Http::response('ok'),
    ]);
    Notification::fake();
});

/** @return array{0: Workspace, 1: Machine, 2: string} */
function agentToolsSetup(int $memoryGb = 8): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create(['name' => 'Acme']);
    $workspace->owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $machine = agentToolsMachine($workspace, 'web-1', $memoryGb);

    return [$workspace, $machine, mcpKey($workspace)];
}

function agentToolsMachine(Workspace $workspace, string $name, int $memoryGb = 8, bool $agentContainer = true): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name]);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'facts' => ['memory_bytes' => $memoryGb * 1024 ** 3],
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => array_values(array_filter([
            $agentContainer ? ['key' => "docker:{$name}-agent", 'name' => 'coding-agent-1', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']] : null,
            ['key' => "docker:{$name}-shop", 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web', 'manager' => 'systemd', 'status' => 'running', 'ports' => [80], 'details' => []],
        ]))],
    ])->save();

    return $machine;
}

const AGENT_TOOLS_READ = ['list_agent_machines', 'list_agent_tasks', 'get_agent_task'];

test('agent tools are listed only while Agents is on, and by what the connection allows', function () {
    [$workspace, , $key] = agentToolsSetup();

    $all = collect(mcpRequest($workspace, $key, 'tools/list')->json('result.tools'))->keyBy('name');
    expect($all->keys()->all())->toEqualCanonicalizing([...AGENT_TOOLS_READ, 'start_agents', 'add_agents', 'stop_agent_task', 'merge_agent_pull_request'])
        ->and($all['stop_agent_task']['annotations']['destructiveHint'])->toBeTrue()
        ->and($all['start_agents']['inputSchema']['properties']['agents']['maximum'])->toBe(20);

    $write = collect(mcpRequest($workspace, mcpKey($workspace, scopes: [Scopes::READ, Scopes::WRITE]), 'tools/list')->json('result.tools'))->pluck('name');
    expect($write->all())->toEqualCanonicalizing([...AGENT_TOOLS_READ, 'stop_agent_task', 'merge_agent_pull_request']);

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $viewed = collect(mcpRequest($workspace, mcpKey($workspace, $viewer), 'tools/list')->json('result.tools'))->pluck('name');
    expect($viewed->all())->toEqualCanonicalizing(AGENT_TOOLS_READ);

    config()->set('serveros.agents', false);
    expect(mcpRequest($workspace, $key, 'tools/list')->json('result.tools'))->toBe([]);
});

test('list_agent_machines shows where agents can run and how many fit', function () {
    [$workspace, $machine, $key] = agentToolsSetup(memoryGb: 8);

    $result = mcpCall($workspace, $key, 'list_agent_machines');

    expect($result['error'])->toBeFalse()
        ->and($result['data']['machines'][0])->toMatchArray(['id' => $machine->id, 'name' => 'web-1', 'online' => true, 'slots' => 4, 'running' => 0, 'free' => 4])
        ->and($result['data']['machines'][0]['containers'][0])->toBe(['key' => 'docker:web-1-agent', 'name' => 'coding-agent-1', 'agent' => true])
        ->and($result['data']['note'])->toBeNull();
});

test('start_agents runs 20 agents on a machine: as many as fit start, the rest wait in line', function () {
    [$workspace, $machine, $key] = agentToolsSetup(memoryGb: 8);

    $result = mcpCall($workspace, $key, 'start_agents', [
        'prompt' => 'Make checkout faster.', 'agents' => 20, 'machine' => 'web-1', 'repo' => 'acme/shop',
    ]);

    expect($result['error'])->toBeFalse()
        ->and($result['data']['started'])->toHaveCount(4)
        ->and($result['data']['waiting'])->toBe(16)
        ->and($result['data']['task']['container'])->toBe('docker:web-1-agent')
        ->and($result['data']['note'])->toContain('4 started on web-1; 16 wait in line');

    $task = AgentTask::query()->sole();
    expect($task->only(['repo', 'branch', 'agents', 'pull_request', 'service']))->toBe([
        'repo' => 'acme/shop', 'branch' => 'main', 'agents' => 20, 'pull_request' => true, 'service' => 'docker:web-1-agent',
    ])
        ->and($task->runs()->count())->toBe(4)
        ->and(AgentQueued::query()->count())->toBe(16)
        ->and(DaemonJob::query()->pluck('service')->unique()->all())->toBe(['docker:web-1-agent'])
        ->and(Activity::query()->where('action', 'agent.task')->sole()->meta)->toMatchArray(['via' => 'mcp', 'agents' => 20]);
});

test('start_agents works on an open pull request: agents start from its branch', function () {
    [$workspace, , $key] = agentToolsSetup();

    $result = mcpCall($workspace, $key, 'start_agents', [
        'prompt' => 'Review this and fix what you find.', 'agents' => 2, 'pull_request' => 'https://github.com/acme/shop/pull/12',
    ]);

    expect($result['error'])->toBeFalse()
        ->and($result['data']['task'])->toMatchArray(['repo' => 'acme/shop', 'branch' => 'feature/coupons', 'pullRequest' => 'https://github.com/acme/shop/pull/12']);
    $env = DaemonJob::query()->first()->command['job']['env'];
    expect($env['SERVEROS_AGENT_BRANCH'])->toBe('feature/coupons')
        ->and($env['SERVEROS_AGENT_PROMPT'])->toContain('https://github.com/acme/shop/pull/12')->toContain('Review this and fix what you find.');

    expect(mcpCall($workspace, $key, 'start_agents', ['prompt' => 'x', 'pull_request' => 'acme/shop#13'])['text'])->toContain('comes from a fork')
        ->and(mcpCall($workspace, $key, 'start_agents', ['prompt' => 'x', 'pull_request' => '14', 'repo' => 'acme/shop'])['text'])->toContain('is closed')
        ->and(mcpCall($workspace, $key, 'start_agents', ['prompt' => 'x', 'pull_request' => '12'])['text'])->toContain('Give repo too')
        ->and(AgentTask::query()->count())->toBe(1);
});

test('start_agents picks the online machine with an agent container and the most room', function () {
    [$workspace, , $key] = agentToolsSetup(memoryGb: 4);
    agentToolsMachine($workspace, 'big', memoryGb: 32);
    agentToolsMachine($workspace, 'bigger-no-agent', memoryGb: 64, agentContainer: false);
    agentToolsMachine($workspace, 'offline', memoryGb: 64)->forceFill(['last_seen_at' => now()->subHour()])->save();

    $result = mcpCall($workspace, $key, 'start_agents', ['prompt' => 'Tidy the README.']);

    expect($result['error'])->toBeFalse()
        ->and($result['data']['task']['machine']['name'])->toBe('big')
        ->and($result['data']['task']['container'])->toBe('docker:big-agent')
        ->and($result['data']['task']['repo'])->toBeNull();
});

test('start_agents needs two-factor authentication, a managed container and a connection that runs commands', function () {
    [$workspace, , $key] = agentToolsSetup();

    expect(mcpCall($workspace, $key, 'start_agents', ['prompt' => 'x', 'machine' => 'web-1', 'container' => 'nginx'])['text'])->toContain('No managed container “nginx”');

    $readWrite = mcpKey($workspace, scopes: [Scopes::READ, Scopes::WRITE]);
    expect(mcpCall($workspace, $readWrite, 'start_agents', ['prompt' => 'x'])['error'])->toBeTrue();

    $workspace->owner->forceFill(['two_factor_secret' => null, 'two_factor_confirmed_at' => null])->save();
    $result = mcpCall($workspace, $key, 'start_agents', ['prompt' => 'x']);
    expect($result['error'])->toBeTrue()
        ->and($result['text'])->toContain('two-factor')
        ->and(AgentTask::query()->count())->toBe(0)
        ->and(DaemonJob::query()->count())->toBe(0);
});

test('get_agent_task, add_agents and stop_agent_task follow a task through', function () {
    [$workspace, , $key] = agentToolsSetup(memoryGb: 2);
    $id = mcpCall($workspace, $key, 'start_agents', ['prompt' => 'Fix the coupon total.', 'agents' => 2, 'repo' => 'acme/shop'])['data']['task']['id'];
    $task = AgentTask::query()->findOrFail($id);
    $first = $task->runs()->sole();
    $first->forceFill(['steps' => [...$first->steps, 'agent' => [...($first->steps['agent'] ?? []), 'summary' => 'Moved the discount into the cart.']]])->save();

    $got = mcpCall($workspace, $key, 'get_agent_task', ['task' => $id]);
    expect($got['error'])->toBeFalse()
        ->and($got['data']['task'])->toMatchArray(['id' => $id, 'status' => 'running', 'repo' => 'acme/shop'])
        ->and($got['data']['agents'])->toHaveCount(1)
        ->and($got['data']['agents'][0]['summary'])->toBe('Moved the discount into the cart.')
        ->and($got['data']['waiting'])->toHaveCount(1);

    expect(mcpCall($workspace, $key, 'add_agents', ['task' => $id, 'after' => $first->id])['text'])->toContain('Say what to change');
    $more = mcpCall($workspace, $key, 'add_agents', ['task' => $id, 'agents' => 3, 'after' => $first->id, 'notes' => 'Keep the receipt field.']);
    expect($more['error'])->toBeFalse()
        ->and($more['data']['waiting'])->toBe(3)
        ->and(AgentQueued::query()->where('notes', 'Keep the receipt field.')->count())->toBe(3);

    expect(mcpCall($workspace, $key, 'stop_agent_task', ['task' => $id, 'confirm' => 'nope'])['text'])->toContain('doesn’t match');
    $stopped = mcpCall($workspace, $key, 'stop_agent_task', ['task' => $id, 'confirm' => 'Fix the coupon total.']);
    expect($stopped['error'])->toBeFalse()
        ->and($stopped['data']['stopped'])->toBe(5)
        ->and($task->runs()->pluck('state')->unique()->all())->toBe(['cancelled'])
        ->and(AgentQueued::query()->count())->toBe(0);

    $other = Workspace::factory()->onPlan(Plan::Pro)->create();
    expect(mcpCall($other, mcpKey($other), 'get_agent_task', ['task' => $id])['text'])->toContain("No agent task #{$id}");
});

test('merge_agent_pull_request only merges with confirm set to the pull request', function () {
    [$workspace, , $key] = agentToolsSetup();
    $id = mcpCall($workspace, $key, 'start_agents', ['prompt' => 'Fix it.', 'repo' => 'acme/shop'])['data']['task']['id'];
    $run = AgentTask::query()->findOrFail($id)->runs()->sole();

    expect(mcpCall($workspace, $key, 'merge_agent_pull_request', ['task' => $id, 'agent' => $run->id, 'confirm' => 'x'])['text'])->toContain('has no pull request');

    $run->forceFill(['state' => 'succeeded', 'steps' => [...$run->steps, 'agent' => ['state' => 'succeeded', 'pr' => 'https://github.com/acme/shop/pull/7']]])->save();
    $asked = mcpCall($workspace, $key, 'merge_agent_pull_request', ['task' => $id, 'agent' => $run->id, 'confirm' => '']);
    expect($asked['error'])->toBeTrue()
        ->and($asked['text'])->toContain('https://github.com/acme/shop/pull/7')
        ->and(AgentSpec::pullRequestBranch($run, 'agent'))->not->toBe('');
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/merge'));
});
