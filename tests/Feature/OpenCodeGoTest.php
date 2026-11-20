<?php

use App\Enums\Plan;
use App\Jobs\DiagnoseFix;
use App\Models\Activity;
use App\Models\AgentFix;
use App\Models\Machine;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Support\Ai\AiProviders;
use App\Support\Ai\OpenCodeGo;
use App\Support\Assistant\AgentChat;
use App\Support\Fixes\FixDiagnoser;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('services.groq.key', 'gsk_ours');
    $this->withoutDefer();
});

function goUrl(Workspace $workspace, string $path = ''): string
{
    return 'https://'.$workspace->host().$path;
}

function goKey(): string
{
    return 'oc_sk_'.str_repeat('c', 24).'GEND';
}

function goChatTurn(string $content = '', array $calls = []): array
{
    return ['model' => 'kimi-k3', 'choices' => [['message' => array_filter([
        'role' => 'assistant',
        'content' => $content,
        'tool_calls' => array_map(fn (array $c, int $i) => ['id' => 'call_'.$c[0].$i, 'type' => 'function', 'function' => ['name' => $c[0], 'arguments' => json_encode((object) $c[1])]], $calls, array_keys($calls)) ?: null,
    ])]]];
}

test('an OpenCode Go key is checked at the Go gateway, kept encrypted, and never sent back', function () {
    Http::fake(['opencode.ai/inference/go/v1/models*' => Http::response(['object' => 'list', 'data' => [['id' => 'kimi-k3', 'name' => 'Kimi K3']]])]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $key = goKey();

    $this->actingAs($workspace->owner)->put(goUrl($workspace, '/settings/ai/opencode-go'), ['key' => $key, 'model' => 'kimi-k3'])
        ->assertSessionHasNoErrors();

    $saved = WorkspaceAiKey::query()->sole();
    expect($saved->provider)->toBe('opencode-go')
        ->and($saved->key)->toBe($key)
        ->and($saved->hint)->toBe('…GEND')
        ->and($saved->model)->toBe('kimi-k3')
        ->and(DB::table('workspace_ai_keys')->value('key'))->not->toContain('oc_sk_');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://opencode.ai/inference/go/v1/models'
        && $r->method() === 'GET'
        && $r->hasHeader('Authorization', 'Bearer '.$key)
        && str_starts_with((string) $r->header('User-Agent')[0], 'ServerOS-Panel/')
        && ! str_contains((string) $r->header('User-Agent')[0], 'Guzzle')
        && ! str_contains(strtolower((string) $r->header('User-Agent')[0]), 'claude')
        && ! str_contains(strtolower((string) $r->header('User-Agent')[0]), 'codex'));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com') || str_contains($r->url(), 'api.anthropic.com') || str_contains($r->url(), 'api.groq.com') || $r->url() === 'https://opencode.ai/inference/v1/models');

    $this->actingAs($workspace->owner)->get(goUrl($workspace, '/settings/mcp'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('ai.providers.2.provider', 'opencode-go')
            ->where('ai.providers.2.connected', true)
            ->where('ai.providers.2.hint', '…GEND')
            ->where('ai.agentUses', 'OpenCode Go · kimi-k3')
            ->missing('ai.providers.2.key'))
        ->assertDontSee('oc_sk_', false)
        ->assertDontSee($key, false);
});

test('a refused OpenCode Go key isn’t kept, and an unknown provider is rejected', function () {
    Http::fake(['opencode.ai/inference/go/*' => Http::response(['error' => ['message' => 'Incorrect API key']], 401)]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();

    $this->actingAs($workspace->owner)->put(goUrl($workspace, '/settings/ai/opencode-go'), ['key' => goKey()])
        ->assertSessionHasErrors(['key' => 'OpenCode Go didn’t accept that key.']);
    expect(WorkspaceAiKey::query()->count())->toBe(0);

    $this->actingAs($workspace->owner)->put(goUrl($workspace, '/settings/ai/openai-compatible'), ['key' => goKey()])
        ->assertNotFound();
});

test('OpenCode Go session ids are stable per conversation and not shared across conversations', function () {
    $first = OpenCodeGo::sessionId('assistant:1:chat-a');
    expect($first)->toStartWith('ses_')
        ->and($first)->toBe(OpenCodeGo::sessionId('assistant:1:chat-a'))
        ->and($first)->not->toBe(OpenCodeGo::sessionId('assistant:1:chat-b'))
        ->and($first)->not->toBe(OpenCodeGo::sessionId('fix:1'));

    expect(fn () => OpenCodeGo::sessionId(''))->toThrow(InvalidArgumentException::class);
});

test('the assistant sends OpenCode Go traffic to the Go chat-completions endpoint with a stable session header', function () {
    Http::fake(['opencode.ai/inference/go/openai/v1/chat/completions' => Http::sequence()
        ->push(goChatTurn(calls: [['list_machines', []]]))
        ->push(goChatTurn('Add an A record.'))]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'opencode-go', 'key' => goKey(), 'hint' => '…GEND', 'model' => 'kimi-k3']);

    $response = $this->actingAs($workspace->owner)->post(goUrl($workspace, '/assistant'), [
        'message' => 'How do I point my domain at an app?',
        'path' => '/machines',
    ]);

    $response->assertOk();
    $body = $response->streamedContent();
    expect($body)->toContain('Add an A record.');

    $sessions = [];
    Http::assertSent(function (Request $r) use (&$sessions) {
        if ($r->url() !== 'https://opencode.ai/inference/go/openai/v1/chat/completions') {
            return false;
        }
        $sessions[] = $r->header('x-opencode-session')[0] ?? null;

        return $r->hasHeader('Authorization', 'Bearer '.goKey())
            && str_starts_with((string) $r->header('User-Agent')[0], 'ServerOS-Panel/')
            && isset($r['messages'], $r['tools'])
            && $r['model'] === 'kimi-k3'
            && ! str_contains($r->url(), '/inference/openai/');
    });
    Http::assertSentCount(2);
    expect($sessions)->toHaveCount(2)
        ->and($sessions[0])->toStartWith('ses_')
        ->and($sessions[0])->toBe($sessions[1]);
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com') || str_contains($r->url(), 'api.anthropic.com') || str_contains($r->url(), 'api.groq.com'));
});

test('OpenCode Go sessions differ across assistant conversations', function () {
    Http::fake(['opencode.ai/inference/go/openai/v1/chat/completions' => Http::response(goChatTurn('Hello.'))]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'opencode-go', 'key' => goKey(), 'hint' => '…GEND', 'model' => 'kimi-k3']);

    $first = $this->actingAs($workspace->owner)->post(goUrl($workspace, '/assistant'), ['message' => 'First chat', 'path' => '/machines']);
    $first->assertOk();
    expect($first->streamedContent())->toContain('Hello.');

    $second = $this->actingAs($workspace->owner)->post(goUrl($workspace, '/assistant'), ['message' => 'Second chat', 'path' => '/machines']);
    $second->assertOk();
    expect($second->streamedContent())->toContain('Hello.');

    $sessions = [];
    Http::assertSent(function (Request $r) use (&$sessions) {
        if ($r->url() !== 'https://opencode.ai/inference/go/openai/v1/chat/completions') {
            return false;
        }
        $sessions[] = $r->header('x-opencode-session')[0] ?? null;

        return true;
    });
    expect($sessions)->toHaveCount(2)
        ->and($sessions[0])->not->toBe($sessions[1]);
});

test('OpenCode Go streaming and tool-calling requests stay on the Go host', function () {
    Http::fake([
        'opencode.ai/inference/go/openai/v1/chat/completions' => Http::sequence()
            ->push("data: {\"choices\":[{\"delta\":{\"content\":\"Hi.\"}}]}\n\ndata: [DONE]\n\n")
            ->push(goChatTurn(calls: [['list_machines', []]])),
    ]);
    $ai = ['provider' => OpenCodeGo::PROVIDER, 'key' => goKey(), 'model' => 'kimi-k3', 'own' => true];

    $stream = app(AgentChat::class)->streamOpenCodeGo($ai, [['role' => 'user', 'content' => 'Hi']], 'assistant:9:stream-chat');
    expect($stream->successful() || $stream->status() === 200)->toBeTrue();

    $turn = app(AgentChat::class)->turn($ai, 'System', [['role' => 'user', 'content' => 'List machines']], [['name' => 'list_machines', 'description' => 'List machines', 'schema' => ['type' => 'object', 'properties' => new stdClass]]], conversationKey: 'assistant:9:tool-chat');
    expect($turn['calls'][0]['name'])->toBe('list_machines');

    $sessions = [];
    $streams = [];
    Http::assertSent(function (Request $r) use (&$sessions, &$streams) {
        $sessions[] = $r->header('x-opencode-session')[0] ?? '';
        $streams[] = $r['stream'] ?? false;

        return $r->url() === 'https://opencode.ai/inference/go/openai/v1/chat/completions'
            && str_starts_with((string) $r->header('User-Agent')[0], 'ServerOS-Panel/');
    });
    expect($sessions)->toHaveCount(2)
        ->and($sessions[0])->not->toBe($sessions[1])
        ->and($streams[0])->toBeTrue()
        ->and($streams[1] ?? false)->toBeFalse();
});

test('with its own OpenCode Go key, server fixes run on the Go gateway', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'opencode-go', 'key' => goKey(), 'hint' => '…GEND', 'model' => 'kimi-k3']);
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['services' => [
        ['key' => 'docker:shop', 'name' => 'shop', 'status' => 'exited', 'details' => ['managed' => 'true']],
    ]]])->save();
    Http::fake(['opencode.ai/inference/go/openai/v1/chat/completions' => Http::response([
        'model' => 'kimi-k3',
        'choices' => [['message' => ['role' => 'assistant', 'content' => json_encode(['title' => 'shop stopped', 'cause' => 'It exited.', 'explanation' => 'It exited.', 'confidence' => 'medium', 'steps' => [['type' => 'start', 'service' => 'docker:shop', 'why' => 'Bring it back.']]])]]],
    ])]);
    $fix = AgentFix::query()->create(['workspace_id' => $workspace->id, 'machine_id' => $machine->id, 'service_key' => 'docker:shop', 'service_name' => 'shop', 'state' => 'investigating']);

    (new DiagnoseFix($fix->id))->handle(app(FixDiagnoser::class));

    expect($fix->refresh()->only(['state', 'title', 'model']))->toBe(['state' => 'needs_approval', 'title' => 'shop stopped', 'model' => 'kimi-k3']);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://opencode.ai/inference/go/openai/v1/chat/completions'
        && ($r->header('x-opencode-session')[0] ?? '') === OpenCodeGo::sessionId('fix:'.$fix->id)
        && $r['model'] === 'kimi-k3');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.openai.com') || str_contains($r->url(), 'api.anthropic.com') || str_contains($r->url(), 'api.groq.com'));
});

test('an OpenAI key still talks only to OpenAI when a Go key is also saved', function () {
    Http::fake(['api.openai.com/v1/models*' => Http::response(['data' => []])]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'opencode-go', 'key' => goKey(), 'hint' => '…GEND']);

    $this->actingAs($workspace->owner)->put(goUrl($workspace, '/settings/ai/openai'), ['key' => 'sk-proj-'.str_repeat('b', 40)])
        ->assertSessionHasNoErrors();

    Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/models');
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'opencode.ai') && str_contains($r->url(), 'sk-proj-') === false && $r->hasHeader('Authorization', 'Bearer '.'sk-proj-'.str_repeat('b', 40)));
    // The OpenAI key must not be sent to the Go or Anthropic hosts.
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'opencode.ai') && $r->hasHeader('Authorization', 'Bearer '.'sk-proj-'.str_repeat('b', 40)));
    Http::assertNotSent(fn (Request $r) => str_contains($r->url(), 'api.anthropic.com'));

    $uses = app(AiProviders::class)->forAgent($workspace);
    expect($uses['provider'])->toBe('openai');
});

test('removing a saved OpenCode Go key deletes only that key', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $openai = WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'openai', 'key' => 'sk-proj-theirs', 'hint' => '…eirs']);
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'opencode-go', 'key' => goKey(), 'hint' => '…GEND']);

    $this->actingAs($workspace->owner)->delete(goUrl($workspace, '/settings/ai/opencode-go'))
        ->assertSessionHasNoErrors()
        ->assertInertiaFlash('toast.message', 'Removed the OpenCode Go key.');

    expect(WorkspaceAiKey::query()->pluck('id')->all())->toBe([$openai->id])
        ->and(Activity::query()->where('action', 'ai.disconnect')->where('subject', 'OpenCode Go')->exists())->toBeTrue();
});

test('the assistant can be asked to use a chosen OpenCode Go model', function () {
    Http::fake(['opencode.ai/inference/go/openai/v1/chat/completions' => Http::response(goChatTurn('Using Go.'))]);
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'opencode-go', 'key' => goKey(), 'hint' => '…GEND', 'model' => 'kimi-k3']);

    $response = $this->actingAs($workspace->owner)->post(goUrl($workspace, '/assistant'), [
        'message' => 'Hello',
        'path' => '/machines',
        'model' => 'opencode-go:kimi-k3',
    ]);
    $response->assertOk();
    expect($response->streamedContent())->toContain('Using Go.');

    Http::assertSent(fn (Request $r) => $r->url() === 'https://opencode.ai/inference/go/openai/v1/chat/completions'
        && $r['model'] === 'kimi-k3');
});
