<?php

use App\Models\Workspace;
use App\Support\Assistant\AgentChat;
use App\Support\Theme\DashboardTheme;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

function assistantPng(): string
{
    $image = imagecreatetruecolor(8, 8);
    imagefill($image, 0, 0, imagecolorallocate($image, 220, 20, 60));
    ob_start();
    imagepng($image);

    return 'data:image/png;base64,'.base64_encode((string) ob_get_clean());
}

function groqImageTurn(string $content = '', array $calls = []): array
{
    return ['model' => 'qwen/qwen3.8-27b', 'choices' => [['message' => array_filter([
        'role' => 'assistant',
        'content' => $content,
        'tool_calls' => array_map(fn (array $c, int $i) => ['id' => 'call_'.$i, 'type' => 'function', 'function' => ['name' => $c[0], 'arguments' => json_encode((object) $c[1])]], $calls, array_keys($calls)) ?: null,
    ])]]];
}

test('images sent to Ask AI reach a model that can see them, even on the free model', function () {
    config()->set('services.groq.key', 'test-key');
    config()->set('services.groq.model', 'openai/gpt-oss-120b');
    config()->set('services.groq.vision_model', 'qwen/qwen3.8-27b');
    Storage::fake('public');
    Http::fake(['api.groq.com/*' => Http::sequence()
        ->push(groqImageTurn(calls: [['add_image_to_theme', ['image' => 1, 'name' => 'minions']]]))
        ->push(groqImageTurn('Saved it.'))]);

    $workspace = Workspace::factory()->create();
    $response = $this->actingAs($workspace->owner)->post('https://'.$workspace->host().'/assistant', [
        'message' => 'Put this on my dashboard',
        'images' => [assistantPng()],
    ])->assertOk();

    $events = array_values(array_map(fn (string $line) => json_decode($line, true), array_filter(explode("\n", $response->streamedContent()))));
    expect(array_column($events, 'type'))->toBe(['chat', 'step', 'result', 'text', 'done'])
        ->and($events[1]['tool'])->toBe('add_image_to_theme')
        ->and($events[2]['ok'])->toBeTrue();

    Http::assertSent(function (Request $r) {
        $user = $r['messages'][1];

        return $r['model'] === 'qwen/qwen3.8-27b'
            && $user['content'][0]['type'] === 'text'
            && str_contains($user['content'][0]['text'], 'Put this on my dashboard')
            && str_starts_with($user['content'][1]['image_url']['url'], 'data:image/png;base64,');
    });

    $images = app(DashboardTheme::class)->images($workspace);
    expect($images)->toHaveCount(1)->and($images[0]['name'])->toStartWith('minions-')->toEndWith('.png');
});

test('Claude and OpenAI get images in their own formats, and models that can’t see them are told', function () {
    Http::preventStrayRequests();
    Http::fake([
        'api.anthropic.com/*' => Http::response(['model' => 'claude-sonnet-5-5', 'content' => [['type' => 'text', 'text' => 'Red.']]]),
        'api.openai.com/*' => Http::response(['status' => 'completed', 'model' => 'gpt-6-sol', 'output' => [['type' => 'message', 'status' => 'completed', 'content' => [['type' => 'output_text', 'text' => 'Red.']]]]]),
    ]);
    $data = substr(assistantPng(), strlen('data:image/png;base64,'));
    $messages = [['role' => 'user', 'content' => 'What colour?', 'image_data' => [['type' => 'image/png', 'data' => $data]]]];

    app(AgentChat::class)->turn(['provider' => 'anthropic', 'key' => 'sk-ant-test', 'model' => 'claude-sonnet-5-5', 'own' => true], 'Look.', $messages, []);
    app(AgentChat::class)->turn(['provider' => 'openai', 'key' => 'sk-test', 'model' => 'gpt-6-sol', 'own' => true], 'Look.', $messages, []);

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'anthropic')
        && $r['messages'][0]['content'][0] === ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/png', 'data' => $data]]
        && $r['messages'][0]['content'][1] === ['type' => 'text', 'text' => 'What colour?']);
    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'openai')
        && $r['input'][0]['content'][0] === ['type' => 'input_text', 'text' => 'What colour?']
        && $r['input'][0]['content'][1] === ['type' => 'input_image', 'image_url' => "data:image/png;base64,{$data}"]);
});

test('Ask AI refuses files that aren’t images, and too many images', function () {
    config()->set('services.groq.key', 'test-key');
    Http::preventStrayRequests();
    $workspace = Workspace::factory()->create();
    $url = 'https://'.$workspace->host().'/assistant';

    $this->actingAs($workspace->owner)->postJson($url, ['message' => 'x', 'images' => ['data:image/png;base64,'.base64_encode('not really a png')]])
        ->assertStatus(422);
    $this->actingAs($workspace->owner)->postJson($url, ['message' => 'x', 'images' => ['data:text/html;base64,PGgxPmhpPC9oMT4=']])
        ->assertStatus(422);
    $this->actingAs($workspace->owner)->postJson($url, ['message' => 'x', 'images' => array_fill(0, 5, assistantPng())])
        ->assertStatus(422);
});
