<?php

use App\Enums\Plan;
use App\Jobs\DiagnoseFix;
use App\Models\AgentFix;
use App\Models\Machine;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Support\Assistant\AgentChat;
use App\Support\Fixes\FixDiagnoser;
use App\Support\Monitors\HostResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('services.groq.key', 'gsk_ours');
    $this->withoutDefer();
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        protected function addresses(string $host): array
        {
            return [str_contains($host, 'internal') ? '10.0.0.5' : '104.18.2.115'];
        }
    });
});

function customAiUrl(Workspace $workspace, string $path = ''): string
{
    return 'https://'.$workspace->host().$path;
}

function customAiKey(Workspace $workspace, string $url = 'https://openrouter.ai/api/v1', string $model = 'deepseek/deepseek-chat'): WorkspaceAiKey
{
    return WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'custom', 'key' => 'sk-or-v1-theirs', 'hint' => '…eirs', 'model' => $model, 'base_url' => $url]);
}

test('an OpenAI-compatible API is checked at its own address, kept with its model, and shown without the key', function () {
    Http::fake(['openrouter.ai/api/v1/models' => Http::response(['data' => [['id' => 'deepseek/deepseek-chat']]])]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)->put(customAiUrl($workspace, '/settings/ai/custom'), [
        'url' => 'https://openrouter.ai/api/v1/',
        'key' => 'sk-or-v1-abcdWXYZ',
        'model' => 'deepseek/deepseek-chat',
    ])->assertSessionHasNoErrors();

    $key = WorkspaceAiKey::query()->sole();
    expect($key->only(['provider', 'base_url', 'model', 'hint']))->toBe(['provider' => 'custom', 'base_url' => 'https://openrouter.ai/api/v1', 'model' => 'deepseek/deepseek-chat', 'hint' => '…WXYZ']);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://openrouter.ai/api/v1/models' && $r->header('Authorization')[0] === 'Bearer sk-or-v1-abcdWXYZ');

    $this->actingAs($workspace->owner)->get(customAiUrl($workspace, '/settings/mcp'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ai.providers.2.provider', 'custom')
            ->where('ai.providers.2.connected', true)
            ->where('ai.providers.2.baseUrl', 'https://openrouter.ai/api/v1')
            ->where('ai.agentUses', 'OpenAI-compatible · deepseek/deepseek-chat')
            ->missing('ai.providers.2.key'));
    $this->actingAs($workspace->owner)->get(customAiUrl($workspace, '/settings/mcp'))->assertDontSee('abcdWXYZ', false);
});

test('an OpenAI-compatible API needs an address and a model, and only it takes an address', function () {
    Http::fake();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)->put(customAiUrl($workspace, '/settings/ai/custom'), ['key' => 'sk-x'])
        ->assertSessionHasErrors(['url', 'model']);
    $this->actingAs($workspace->owner)->put(customAiUrl($workspace, '/settings/ai/custom'), ['url' => 'ftp://example.com', 'key' => 'sk-x', 'model' => 'm'])
        ->assertSessionHasErrors(['url' => 'That isn’t a web address. It starts with https://.']);
    $this->actingAs($workspace->owner)->put(customAiUrl($workspace, '/settings/ai/openai'), ['url' => 'https://example.com', 'key' => 'sk-proj-'.str_repeat('b', 40)])
        ->assertSessionHasErrors('url');

    expect(WorkspaceAiKey::query()->count())->toBe(0);
    Http::assertNothingSent();
});

test('on ServerOS cloud the address must be https and must not point into a private network', function (string $url, string $error) {
    Http::fake();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)->put(customAiUrl($workspace, '/settings/ai/custom'), ['url' => $url, 'key' => 'sk-x', 'model' => 'llama3'])
        ->assertSessionHasErrors(['url' => $error]);

    expect(WorkspaceAiKey::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'plain http' => ['http://api.example.com/v1', 'The address has to start with https://.'],
    'a private name' => ['https://ollama.internal/v1', 'Couldn’t find ollama.internal, or it points into a private network.'],
    'a private address' => ['https://192.168.1.20:11434/v1', 'Couldn’t find 192.168.1.20, or it points into a private network.'],
    'the metadata service' => ['https://169.254.169.254/latest', 'Couldn’t find 169.254.169.254, or it points into a private network.'],
]);

test('a self-hosted panel can use a model on its own network, like Ollama, once private addresses are allowed', function () {
    config()->set(['serveros.edition' => 'self-hosted', 'monitors.allow_private' => false]);
    Http::fake(['ollama.internal:11434/v1/models' => Http::response(['object' => 'list', 'data' => [['id' => 'llama3.1']]])]);
    $workspace = Workspace::factory()->create();
    $put = fn () => $this->actingAs($workspace->owner)->put(customAiUrl($workspace, '/settings/ai/custom'), ['url' => 'http://ollama.internal:11434/v1', 'key' => 'ollama', 'model' => 'llama3.1']);

    $put()->assertSessionHasErrors(['url' => 'Couldn’t find ollama.internal, or it points into a private network. To use one, set MONITORS_ALLOW_PRIVATE=true.']);
    Http::assertNothingSent();

    config()->set('monitors.allow_private', true);
    $put()->assertSessionHasNoErrors();

    // A key this short would be shown almost whole, so it gets no hint.
    expect(WorkspaceAiKey::query()->sole()->only(['base_url', 'hint']))->toBe(['base_url' => 'http://ollama.internal:11434/v1', 'hint' => '']);
});

test('link-local addresses, like the metadata service, are refused even when private addresses are allowed', function (string $url, string $host) {
    config()->set(['serveros.edition' => 'self-hosted', 'monitors.allow_private' => true]);
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        protected function addresses(string $host): array
        {
            return ['169.254.169.254'];
        }
    });
    Http::fake();
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->put(customAiUrl($workspace, '/settings/ai/custom'), ['url' => $url, 'key' => 'sk-x', 'model' => 'm'])
        ->assertSessionHasErrors(['url' => "Couldn’t find {$host}, or it points into a private network. To use one, set MONITORS_ALLOW_PRIVATE=true."]);

    expect(WorkspaceAiKey::query()->count())->toBe(0);
    Http::assertNothingSent();
})->with([
    'the address itself' => ['http://169.254.169.254/latest', '169.254.169.254'],
    'a name that resolves there' => ['http://metadata.example.com/v1', 'metadata.example.com'],
    'IPv6 link-local' => ['http://[fe80::1]:11434/v1', 'fe80::1'],
]);

test('an address that answers 200 with something other than a model list isn’t saved', function (mixed $body) {
    Http::fake(['site.example.com/*' => Http::response($body, 200, is_string($body) ? ['Content-Type' => 'text/html'] : [])]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)->put(customAiUrl($workspace, '/settings/ai/custom'), ['url' => 'https://site.example.com', 'key' => 'sk-x', 'model' => 'm'])
        ->assertSessionHasErrors(['url' => 'There’s no OpenAI-compatible API at that address. It usually ends in /v1.']);

    expect(WorkspaceAiKey::query()->count())->toBe(0);
})->with([
    'an HTML page' => ['<!doctype html><html><body>Welcome</body></html>'],
    'JSON without a data list' => [['status' => 'ok']],
]);

test('an answer larger than 5 MB is refused', function () {
    Http::fake(['huge.example.com/*' => Http::response(['data' => [], 'padding' => str_repeat('a', 5 * 1024 * 1024)])]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)->put(customAiUrl($workspace, '/settings/ai/custom'), ['url' => 'https://huge.example.com/v1', 'key' => 'sk-x', 'model' => 'm'])
        ->assertSessionHasErrors(['url' => 'huge.example.com sent back more than 5 MB. Check the address.']);

    expect(WorkspaceAiKey::query()->count())->toBe(0);
});

test('a redirect isn’t followed, a missing API says where to look, and a refused key is shown on the key', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $put = fn (string $url) => $this->actingAs($workspace->owner)->put(customAiUrl($workspace, '/settings/ai/custom'), ['url' => $url, 'key' => 'sk-x', 'model' => 'm']);
    Http::fake([
        'moved.example.com/*' => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
        'blog.example.com/*' => Http::response('Not found', 404),
        'api.example.com/*' => Http::response(['error' => ['message' => 'bad key']], 401),
    ]);

    $put('https://moved.example.com/v1')->assertSessionHasErrors(['url' => 'moved.example.com sent the request somewhere else. Use the address it ends up at.']);
    $put('https://blog.example.com')->assertSessionHasErrors(['url' => 'There’s no OpenAI-compatible API at that address. It usually ends in /v1.']);
    $put('https://api.example.com/v1')->assertSessionHasErrors(['key' => 'api.example.com didn’t accept that key.']);

    expect(WorkspaceAiKey::query()->count())->toBe(0);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), '169.254.169.254'));
});

test('moving to another address checks the saved key there first', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    customAiKey($workspace);
    Http::fake([
        'api.deepseek.com/*' => Http::response(['data' => []]),
        'api.together.xyz/*' => Http::response(['error' => ['message' => 'no']], 401),
    ]);

    $this->actingAs($workspace->owner)->put(customAiUrl($workspace, '/settings/ai/custom'), ['url' => 'https://api.together.xyz/v1', 'model' => 'deepseek-chat'])
        ->assertSessionHasErrors('key');
    expect(WorkspaceAiKey::query()->sole()->base_url)->toBe('https://openrouter.ai/api/v1');

    $this->actingAs($workspace->owner)->put(customAiUrl($workspace, '/settings/ai/custom'), ['url' => 'https://api.deepseek.com', 'model' => 'deepseek-chat'])
        ->assertSessionHasNoErrors();
    expect(WorkspaceAiKey::query()->sole()->only(['base_url', 'model', 'key']))->toBe(['base_url' => 'https://api.deepseek.com', 'model' => 'deepseek-chat', 'key' => 'sk-or-v1-theirs']);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.deepseek.com/models' && $r->header('Authorization')[0] === 'Bearer sk-or-v1-theirs');
});

test('server fixes run on the OpenAI-compatible API, not on ServerOS’s model', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    customAiKey($workspace, 'https://api.deepseek.com', 'deepseek-chat');
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['services' => [
        ['key' => 'docker:shop', 'name' => 'shop', 'status' => 'exited', 'details' => ['managed' => 'true']],
    ]]])->save();
    Http::fake(['api.deepseek.com/chat/completions' => Http::response([
        'model' => 'deepseek-chat',
        'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(['title' => 'shop stopped', 'cause' => 'It exited.', 'explanation' => 'It exited.', 'confidence' => 'medium', 'steps' => [['type' => 'start', 'service' => 'docker:shop', 'why' => 'Bring it back.']]])]]],
    ])]);
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'service_key' => 'docker:shop', 'service_name' => 'shop', 'state' => 'investigating']);

    (new DiagnoseFix($fix->id))->handle(app(FixDiagnoser::class));

    expect($fix->refresh()->only(['state', 'title', 'model']))->toBe(['state' => 'needs_approval', 'title' => 'shop stopped', 'model' => 'deepseek-chat']);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.deepseek.com/chat/completions'
        && $r->header('Authorization')[0] === 'Bearer sk-or-v1-theirs'
        && $r['model'] === 'deepseek-chat'
        && isset($r['max_completion_tokens'])
        && ! isset($r['max_tokens'])
        && $r['messages'][0]['role'] === 'system');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'groq.com'));
});

test('Ask AI talks to the OpenAI-compatible API with its tools and none of Groq’s own options', function () {
    $workspace = Workspace::factory()->create();
    customAiKey($workspace);
    Http::fake(['openrouter.ai/api/v1/chat/completions' => Http::response(['model' => 'deepseek/deepseek-chat', 'choices' => [['message' => [
        'role' => 'assistant',
        'content' => '',
        'tool_calls' => [['id' => 'call_1', 'type' => 'function', 'function' => ['name' => 'list_machines', 'arguments' => '{"status":"online"}']]],
    ]]]])]);

    $answer = app(AgentChat::class)->turn(
        ['provider' => 'custom', 'key' => 'sk-or-v1-theirs', 'model' => 'deepseek/deepseek-chat', 'own' => true, 'url' => 'https://openrouter.ai/api/v1'],
        'You help.',
        [['role' => 'user', 'content' => 'What’s online?']],
        [['name' => 'list_machines', 'description' => 'Lists machines.', 'schema' => ['type' => 'object']]],
    );

    expect($answer['calls'])->toBe([['id' => 'call_1', 'name' => 'list_machines', 'arguments' => ['status' => 'online']]]);
    Http::assertSent(fn (Request $r) => $r['tools'][0]['function']['name'] === 'list_machines'
        && ! isset($r['reasoning_effort'])
        && ! isset($r['include_reasoning'])
        && $r['max_completion_tokens'] === 3200
        && ! isset($r['max_tokens'])
        && $r['messages'][1] === ['role' => 'user', 'content' => 'What’s online?']);
});

test('the chat lists the API’s own models, unfiltered, and a chat can pick one', function () {
    $workspace = Workspace::factory()->create();
    customAiKey($workspace);
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response(['data' => [
            ['id' => 'deepseek/deepseek-chat', 'name' => 'DeepSeek V3', 'created' => 1700000000],
            ['id' => 'qwen/qwen3-coder', 'created' => 1750000000],
        ]]),
        'openrouter.ai/api/v1/chat/completions' => Http::response(['model' => 'qwen/qwen3-coder', 'choices' => [['message' => ['role' => 'assistant', 'content' => 'Hi.']]]]),
    ]);
    $url = 'https://'.$workspace->host().'/assistant';

    $options = $this->actingAs($workspace->owner)->getJson("{$url}/models")->assertOk()->json('options');
    expect($options)->toHaveCount(1)
        ->and($options[0])->toMatchArray(['provider' => 'custom', 'label' => 'OpenAI-compatible', 'default' => 'deepseek/deepseek-chat'])
        ->and(array_column($options[0]['models'], 'id'))->toBe(['qwen/qwen3-coder', 'deepseek/deepseek-chat']);

    $this->actingAs($workspace->owner)->post($url, ['message' => 'Hi', 'model' => 'custom:qwen/qwen3-coder'])->assertOk()->streamedContent();
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/chat/completions') && $r['model'] === 'qwen/qwen3-coder');
});

test('a model call never goes to a private address, even if the saved address now points there', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    customAiKey($workspace, 'https://llm.internal/v1', 'llama3');
    Http::fake();
    $machine = Machine::factory()->for($workspace)->create();
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'state' => 'investigating']);

    (new DiagnoseFix($fix->id))->handle(app(FixDiagnoser::class));

    expect($fix->refresh()->only(['state', 'error']))->toBe(['state' => 'error', 'error' => 'Couldn’t find llm.internal, or it points into a private network.']);
    Http::assertNothingSent();
});
