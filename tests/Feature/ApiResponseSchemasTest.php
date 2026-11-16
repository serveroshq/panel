<?php

use App\Api\ApiRoutes;
use App\Api\OpenApi;
use App\Api\Schemas;
use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Mcp\ToolRegistry;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\McpToken;
use App\Models\Workspace;
use App\Support\Monitors\HostResolver;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202), 'hooks.example.com/*' => Http::response('ok')]);
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        protected function addresses(string $host): array
        {
            return $host === 'hooks.example.com' ? ['93.184.216.34'] : [];
        }
    });
});

/** @return array{0: Workspace, 1: string} */
function schemaWorkspace(int $machines = 3): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $group = $workspace->machineGroups()->create(['name' => 'eu-web']);
    foreach (range(1, $machines) as $n) {
        Machine::factory()->for($workspace)->create(['name' => "web-{$n}"])->forceFill([
            'uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'labels' => ['env' => 'prod'], 'tier' => 'web',
            'machine_group_id' => $n === 1 ? $group->id : null,
            'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
                ['key' => 'docker:aaa', 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
            ]],
        ])->save();
    }
    [, $key] = McpToken::issueKey($workspace, $workspace->owner, 'CI', Scopes::ALL, McpToken::API);

    return [$workspace, $key];
}

function schemaApi(string $key, string $method, string $path, array $body = []): TestResponse
{
    return test()->flushHeaders()->withHeader('Authorization', "Bearer {$key}")->json($method, "/api/v1/{$path}", $body);
}

/** Checks an answer against the schema its route documents, and returns the data. */
function matchesDocumented(TestResponse $response, string $tool, array &$checked, int $status = 200): mixed
{
    $response->assertStatus($status);
    $document = OpenApi::document(app(ToolRegistry::class));
    $schema = app(ToolRegistry::class)->find($tool)->responseSchema();
    $data = json_decode($response->getContent())->data;
    answersAsDocumented($tool, $data);
    $checked[$tool] = true;

    return $data;
}

test('every tool that documents its answer answers just that', function () {
    [$workspace, $key] = schemaWorkspace();
    $checked = [];
    $machine = $workspace->machines()->orderBy('id')->first();

    matchesDocumented(schemaApi($key, 'GET', 'machines?limit=2'), 'list_machines', $checked);
    matchesDocumented(schemaApi($key, 'POST', 'selectors/resolve', ['selector' => ['labels' => ['env' => 'prod']]]), 'resolve_selector', $checked);

    $dry = matchesDocumented(schemaApi($key, 'POST', 'operations', ['kind' => 'service_action', 'selector' => ['tier' => 'web'], 'service_action' => ['service' => 'shop', 'action' => 'restart'], 'dry_run' => true]), 'create_operation', $checked);
    expect($dry->dryRun)->toBeTrue();
    $operation = matchesDocumented(schemaApi($key, 'POST', 'operations', ['kind' => 'service_action', 'selector' => ['tier' => 'web'], 'service_action' => ['service' => 'shop', 'action' => 'restart'], 'strategy' => ['batch_size' => 1]]), 'create_operation', $checked, 202)->operation;
    matchesDocumented(schemaApi($key, 'GET', "operations/{$operation->id}"), 'get_operation', $checked);
    matchesDocumented(schemaApi($key, 'GET', 'operations'), 'list_operations', $checked);
    matchesDocumented(schemaApi($key, 'POST', "operations/{$operation->id}/pause"), 'pause_operation', $checked);
    matchesDocumented(schemaApi($key, 'POST', "operations/{$operation->id}/resume"), 'resume_operation', $checked);
    matchesDocumented(schemaApi($key, 'POST', "operations/{$operation->id}/cancel"), 'cancel_operation', $checked);

    $job = DaemonJob::query()->firstOrFail();
    $job->forceFill(['state' => 'failed', 'finished_at' => now(), 'error' => ['message' => 'Exited 1'], 'log' => ['one', 'two']])->save();
    matchesDocumented(schemaApi($key, 'GET', "jobs/{$job->id}"), 'get_job', $checked);
    matchesDocumented(schemaApi($key, 'GET', "jobs?machine={$machine->id}"), 'list_jobs', $checked);

    $webhook = matchesDocumented(schemaApi($key, 'POST', 'webhooks', ['url' => 'https://hooks.example.com/x', 'events' => ['job.finished']]), 'create_webhook', $checked)->webhook;
    matchesDocumented(schemaApi($key, 'POST', "webhooks/{$webhook->id}/test"), 'test_webhook', $checked);
    matchesDocumented(schemaApi($key, 'GET', 'webhooks'), 'list_webhooks', $checked);
    matchesDocumented(schemaApi($key, 'PATCH', "webhooks/{$webhook->id}", ['enabled' => false]), 'update_webhook', $checked);
    matchesDocumented(schemaApi($key, 'GET', "webhooks/{$webhook->id}/deliveries"), 'list_webhook_deliveries', $checked);
    matchesDocumented(schemaApi($key, 'DELETE', "webhooks/{$webhook->id}"), 'delete_webhook', $checked);

    expect($checked)->toHaveCount(16);
});

test('no two groups name a shared shape the same', function () {
    $names = collect([Schemas\Machines::class, Schemas\Services::class, Schemas\Deploys::class, Schemas\Monitoring::class, Schemas\Workflows::class, Schemas\Team::class])
        ->flatMap(fn (string $group) => array_keys($group::components()));

    expect($names->duplicates()->values()->all())->toBe([])
        ->and($names->intersect(['Job', 'Machine', 'Operation', 'Webhook', 'WebhookDelivery'])->values()->all())->toBe([]);
});

test('every tool documents what it answers', function () {
    $missing = collect(app(ToolRegistry::class)->all())->filter(fn ($tool) => $tool->responseSchema() === null)->keys()->sort()->values()->all();

    expect($missing)->toBe([]);
});

test('the OpenAPI document is whole: shared shapes resolve, ids are unique, every route documents its errors', function () {
    $document = OpenApi::document(app(ToolRegistry::class));
    $json = json_encode($document);

    preg_match_all('#"\$ref":"\#\\\\/components\\\\/(schemas|responses|parameters)\\\\/([A-Za-z]+)"#', $json, $refs, PREG_SET_ORDER);
    expect($refs)->not->toBeEmpty();
    foreach ($refs as [, $kind, $name]) {
        expect($document['components'][$kind])->toHaveKey($name);
    }

    $operations = collect($document['paths'])->flatMap(fn ($methods) => array_values($methods));
    expect($operations->pluck('operationId')->duplicates()->all())->toBe([])
        ->and($operations->count())->toBe(count(ApiRoutes::all()));
    foreach ($operations as $operation) {
        expect(array_map('strval', array_keys($operation['responses'])))->toContain('200', '400', '401', '402', '403', '404', '422', '429', '500');
    }
    expect($document['components']['schemas']['Error']['properties']['error']['properties']['type']['enum'])->toContain('conflict', 'error', 'plan_required');

    $offset = collect($document['paths']['/machines']['get']['parameters'])->firstWhere('name', 'offset');
    expect($offset['deprecated'])->toBeTrue()
        ->and(collect($document['paths']['/machines']['get']['parameters'])->pluck('name'))->toContain('cursor')
        ->and($document['paths']['/operations']['post']['responses'])->toHaveKey('202')
        ->and($document['paths']['/machines']['get']['responses'])->not->toHaveKey('202');
});

test('lists page with a cursor; offset still works but says it is deprecated', function () {
    [, $key] = schemaWorkspace(5);

    $names = [];
    $cursor = null;
    $pages = 0;
    do {
        $page = schemaApi($key, 'GET', 'machines?limit=2'.($cursor ? "&cursor={$cursor}" : ''))->assertOk()->assertHeaderMissing('Deprecation');
        $names = [...$names, ...collect($page->json('data.machines'))->pluck('name')->all()];
        $cursor = $page->json('data.next_cursor');
        $pages++;
    } while ($cursor !== null && $pages < 10);
    expect($names)->toBe(['web-1', 'web-2', 'web-3', 'web-4', 'web-5'])->and($pages)->toBe(3);

    $old = schemaApi($key, 'GET', 'machines?limit=2&offset=4')->assertOk()
        ->assertJsonPath('data.machines.0.name', 'web-5')
        ->assertJsonPath('data.next_cursor', null)
        ->assertHeader('Deprecation', '@1794700800');
    expect($old->headers->get('Link'))->toContain('rel="deprecation"');

    schemaApi($key, 'GET', 'machines?cursor=not-a-cursor')->assertStatus(422)->assertJsonPath('error.type', 'invalid_arguments');
    schemaApi($key, 'GET', 'jobs?limit=1')->assertOk()->assertJsonPath('data.next_cursor', null);
    schemaApi($key, 'GET', 'templates?limit=1')->assertOk()->assertJsonPath('data.next_cursor', fn ($c) => is_string($c));
});
