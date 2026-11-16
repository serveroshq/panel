<?php

use App\Api\OpenApi;
use App\Mcp\Scopes;
use App\Mcp\ToolRegistry;
use App\Models\McpToken;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceLogStore;
use App\Support\Admin\Impersonation;
use App\Support\Logs\LogStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Inertia;
use Tests\TestCase;

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', '../vendor/serveroshq/serveros.com/tests/Feature', '../vendor/serveroshq/panel-ee/tests/Feature');

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

function something() {}

/**
 * @param  list<string>  $scopes
 */
function mcpKey(Workspace $workspace, ?User $user = null, array $scopes = Scopes::ALL): string
{
    return McpToken::issueKey($workspace, $user ?? $workspace->owner, 'Test key', $scopes)[1];
}

/** @param  array<string, mixed>  $params */
function mcpRequest(Workspace $workspace, string $key, string $method, array $params = [], int|string $id = 1): TestResponse
{
    return test()->withHeaders(['Authorization' => "Bearer {$key}"])
        ->postJson('https://'.$workspace->host().'/mcp', ['jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object) $params]);
}

/**
 * @param  array<string, mixed>  $arguments
 * @return array{data: mixed, error: bool, text: string}
 */
function mcpCall(Workspace $workspace, string $key, string $tool, array $arguments = []): array
{
    $response = mcpRequest($workspace, $key, 'tools/call', ['name' => $tool, 'arguments' => (object) $arguments])->assertOk();
    $result = $response->json('result');
    if ($result === null) {
        throw new RuntimeException('JSON-RPC error: '.json_encode($response->json('error')));
    }
    if (! ($result['isError'] ?? false) && isset($result['structuredContent'])) {
        answersAsDocumented($tool, json_decode((string) $response->getContent())->result->structuredContent);
    }

    return [
        'data' => $result['structuredContent'] ?? json_decode($result['content'][0]['text'] ?? 'null', true),
        'error' => (bool) ($result['isError'] ?? false),
        'text' => $result['content'][0]['text'] ?? '',
    ];
}

function ownLogStore(Workspace $workspace, array $attributes = []): WorkspaceLogStore
{
    $path = tempnam(sys_get_temp_dir(), 'serveros-logstore-');
    $store = WorkspaceLogStore::query()->forceCreate([
        'workspace_id' => $workspace->id, 'driver' => 'sqlite', 'host' => 'db.example.com', 'port' => 5432,
        'database' => $path, 'username' => 'serveros', 'password' => 'secret', 'enabled' => true, ...$attributes,
    ]);
    LogStore::install($store);
    LogStore::flush();

    return $store;
}

function viewAsUrl(User $user): string
{
    if (! Route::has('test.view-as')) {
        Route::post('test/view-as/{user}', function (Request $request, User $user) {
            $reason = trim((string) $request->validate(['reason' => ['required', 'string', 'max:500']])['reason']);
            Impersonation::start($request, $request->user(), $user, $reason);

            return Inertia::location(route('dashboard'));
        })->middleware(['web', 'auth', 'verified', 'can:viewAdmin'])->name('test.view-as');
        app('router')->getRoutes()->refreshNameLookups();
    }

    return '/test/view-as/'.$user->id;
}

/**
 * Fails the test when a tool's answer doesn't match the schema it documents
 * (Tool::responseSchema()). mcpCall() runs it on every answer that isn't an
 * error. With SCHEMA_SEEN set to a file, it also notes each tool it checked.
 */
function answersAsDocumented(string $tool, mixed $data): void
{
    $schema = app(ToolRegistry::class)->find($tool)?->responseSchema();
    if ($schema === null) {
        return;
    }
    // MCP wraps a list in {"items": [...]}; the API sends the list itself.
    if (($schema['type'] ?? null) === 'array' && $data instanceof stdClass && array_keys((array) $data) === ['items']) {
        $data = $data->items;
    }
    static $components = null;
    $components ??= OpenApi::document(app(ToolRegistry::class))['components']['schemas'];
    expect(schemaProblems($data, $schema, $components))->toBe([], "{$tool}'s answer doesn't match its responseSchema()");
    if (is_string($seen = getenv('SCHEMA_SEEN')) && $seen !== '') {
        file_put_contents($seen, $tool."\n", FILE_APPEND | LOCK_EX);
    }
}

/**
 * Checks a decoded JSON value (objects as stdClass) against the JSON Schema
 * features the API's response schemas use, and returns what doesn't match.
 * Stricter than JSON Schema: an object with properties may only have the
 * ones it lists, unless it says additionalProperties.
 *
 * @param  array<string, mixed>  $schema
 * @param  array<string, mixed>  $components
 * @return list<string>
 */
function schemaProblems(mixed $value, array $schema, array $components, string $at = 'data'): array
{
    if (isset($schema['$ref'])) {
        return schemaProblems($value, $components[substr($schema['$ref'], strlen('#/components/schemas/'))], $components, $at);
    }
    if (isset($schema['anyOf'])) {
        $each = array_map(fn (array $option) => schemaProblems($value, $option, $components, $at), $schema['anyOf']);

        return in_array([], $each, true) ? [] : ["{$at} matches none of anyOf: ".implode('; ', array_merge(...$each))];
    }
    $types = (array) ($schema['type'] ?? []);
    $actual = match (true) {
        $value === null => 'null',
        is_bool($value) => 'boolean',
        is_int($value) => 'integer',
        is_float($value) => 'number',
        is_string($value) => 'string',
        is_array($value) => 'array',
        default => 'object',
    };
    if ($types !== [] && ! in_array($actual, $types, true) && ! ($actual === 'integer' && in_array('number', $types, true))) {
        return ["{$at} is {$actual}, the schema says ".implode('|', $types)];
    }
    if (isset($schema['enum']) && ! is_object($value) && ! in_array($value, $schema['enum'], true)) {
        return ["{$at} is ".json_encode($value).', not one of '.json_encode($schema['enum'])];
    }
    $problems = [];
    if ($value instanceof stdClass) {
        foreach ($schema['required'] ?? [] as $name) {
            if (! property_exists($value, $name)) {
                $problems[] = "{$at}.{$name} is missing";
            }
        }
        $properties = $schema['properties'] ?? [];
        $extra = $schema['additionalProperties'] ?? ($properties === [] ? true : false);
        foreach (get_object_vars($value) as $name => $item) {
            if (isset($properties[$name])) {
                array_push($problems, ...schemaProblems($item, $properties[$name], $components, "{$at}.{$name}"));
            } elseif (is_array($extra)) {
                array_push($problems, ...schemaProblems($item, $extra, $components, "{$at}.{$name}"));
            } elseif ($extra === false) {
                $problems[] = "{$at}.{$name} isn't in the schema";
            }
        }
    }
    if (is_array($value) && isset($schema['items'])) {
        foreach ($value as $i => $item) {
            array_push($problems, ...schemaProblems($item, $schema['items'], $components, "{$at}[{$i}]"));
        }
    }

    return $problems;
}
