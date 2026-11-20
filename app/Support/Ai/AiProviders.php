<?php

namespace App\Support\Ai;

use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class AiProviders
{
    public const PROVIDERS = [
        'anthropic' => ['label' => 'Anthropic', 'model' => 'claude-sonnet-5-5', 'prefix' => 'sk-ant-'],
        'openai' => ['label' => 'OpenAI', 'model' => 'gpt-5', 'prefix' => 'sk-'],
        'custom' => ['label' => 'OpenAI-compatible', 'model' => '', 'prefix' => ''],
    ];

    public const PREFERENCE = ['anthropic', 'openai', 'custom'];

    public const FALLBACK_MODELS = [
        'anthropic' => ['claude-opus-5-5' => 'Claude Opus 5.5', 'claude-sonnet-5-5' => 'Claude Sonnet 5.5', 'claude-haiku-5-5' => 'Claude Haiku 5.5', 'claude-fable-5-1' => 'Claude Fable 5.1'],
        'openai' => ['gpt-5' => 'GPT-5', 'gpt-5-mini' => 'GPT-5 mini'],
        'custom' => [],
    ];

    public const MODEL_PATTERN = '/^[A-Za-z0-9._:\/\[\]-]{1,80}$/';

    public function __construct(private readonly CompatibleEndpoint $endpoint) {}

    /** @return array{provider: string, key: string, model: string, own: bool, url?: string} */
    private static function own(string $provider, WorkspaceAiKey $key, ?string $model = null): array
    {
        return ['provider' => $provider, 'key' => $key->key, 'model' => $model ?? ($key->model ?: self::PROVIDERS[$provider]['model']), 'own' => true]
            + ($provider === 'custom' ? ['url' => (string) $key->base_url] : []);
    }

    /** @param  array{provider: string, model: string}  $ai */
    public static function seesImages(array $ai): bool
    {
        return match ($ai['provider']) {
            'anthropic' => true,
            // Every current OpenAI chat model reads images; only old text-only ones don't.
            'openai' => preg_match('/^(gpt-3\.5|gpt-4-|gpt-4$|o1-mini|o3-mini)/', $ai['model']) !== 1,
            default => $ai['model'] === config('services.groq.vision_model') || preg_match('/(llama-4|vision|qwen3\.[6-9])/i', $ai['model']) === 1,
        };
    }

    /**
     * The model for a message with images: Ask AI's own model moves to one that can see them.
     *
     * @param  array{provider: string, key: string, model: string, own: bool, url?: string}  $ai
     * @return array{provider: string, key: string, model: string, own: bool, url?: string}
     */
    public static function forImages(array $ai): array
    {
        $vision = (string) config('services.groq.vision_model');

        return ! $ai['own'] && ! self::seesImages($ai) && $vision !== '' ? [...$ai, 'model' => $vision] : $ai;
    }

    public function key(Workspace|int $workspace, string $provider): ?WorkspaceAiKey
    {
        return WorkspaceAiKey::query()
            ->where('workspace_id', $workspace instanceof Workspace ? $workspace->id : $workspace)
            ->where('provider', $provider)
            ->first();
    }

    /** @return array{provider: string, key: string, model: string, own: bool, url?: string} */
    public function forAgent(Workspace|int $workspace): array
    {
        foreach (self::PREFERENCE as $provider) {
            $key = $this->key($workspace, $provider);
            if ($key !== null) {
                return self::own($provider, $key);
            }
        }

        return ['provider' => 'groq', 'key' => (string) config('services.groq.key'), 'model' => (string) config('services.groq.model'), 'own' => false];
    }

    /** @return array{provider: string, key: string, model: string, own: bool, url?: string} */
    public function forChat(Workspace|int $workspace, ?string $choice): array
    {
        if ($choice !== null && str_contains($choice, ':')) {
            [$provider, $model] = explode(':', $choice, 2);
            $key = isset(self::PROVIDERS[$provider]) && preg_match(self::MODEL_PATTERN, $model) === 1 ? $this->key($workspace, $provider) : null;
            if ($key !== null) {
                return self::own($provider, $key, $model);
            }
        }

        return $this->forAgent($workspace);
    }

    /** @return list<array{provider: string, label: string, default: string, models: list<array{id: string, label: string}>}> */
    public function modelOptions(Workspace $workspace): array
    {
        $options = [];
        foreach (self::PREFERENCE as $provider) {
            $key = $this->key($workspace, $provider);
            if ($key === null) {
                continue;
            }
            $default = $key->model ?: self::PROVIDERS[$provider]['model'];
            $cacheKey = "ai-models:{$workspace->id}:{$provider}:".substr(hash('sha256', $key->key.'|'.$key->base_url), 0, 16);
            $models = Cache::get($cacheKey);
            if (! is_array($models) || $models === []) {
                $models = $this->listModels($provider, $key->key, $key->base_url);
                if ($models !== []) {
                    Cache::put($cacheKey, $models, now()->addHour());
                }
            }
            $models = $models ?: array_map(fn (string $id, string $label) => ['id' => $id, 'label' => $label], array_keys(self::FALLBACK_MODELS[$provider]), self::FALLBACK_MODELS[$provider]);
            if (! in_array($default, array_column($models, 'id'), true)) {
                array_unshift($models, ['id' => $default, 'label' => $default]);
            }
            $options[] = ['provider' => $provider, 'label' => self::PROVIDERS[$provider]['label'], 'default' => $default, 'models' => array_values($models)];
        }

        return $options;
    }

    /** @return list<array{id: string, label: string}> */
    private function listModels(string $provider, string $key, ?string $url): array
    {
        try {
            $response = match ($provider) {
                'anthropic' => Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(8)->get('https://api.anthropic.com/v1/models', ['limit' => 100]),
                'custom' => $this->endpoint->request((string) $url, $key, 8)->get(CompatibleEndpoint::url((string) $url, 'models')),
                default => Http::withToken($key)->timeout(8)->get('https://api.openai.com/v1/models'),
            };
        } catch (ConnectionException|RuntimeException) {
            return [];
        }
        if (! $response->successful()) {
            return [];
        }

        $models = [];
        foreach ((array) $response->json('data', []) as $model) {
            $id = (string) ($model['id'] ?? '');
            if ($id === '' || preg_match(self::MODEL_PATTERN, $id) !== 1) {
                continue;
            }
            if ($provider === 'openai' && (preg_match('/^(gpt-|o\d|chatgpt-)/', $id) !== 1 || preg_match('/(embedding|audio|realtime|transcribe|tts|image|search|instruct|moderation|dall-e|whisper)/', $id) === 1)) {
                continue;
            }
            $models[] = ['id' => $id, 'label' => (string) ($model['display_name'] ?? $id), 'created' => (int) ($model['created'] ?? (isset($model['created_at']) ? strtotime((string) $model['created_at']) : 0))];
        }
        usort($models, fn (array $a, array $b) => $b['created'] <=> $a['created']);

        return array_slice(array_map(fn (array $m) => ['id' => $m['id'], 'label' => $m['label']], $models), 0, 30);
    }

    /**
     * @param  array{provider: string, key: string, model: string, own: bool, url?: string}  $ai
     * @return array{0: array<string, mixed>, 1: string}
     *
     * @throws RuntimeException
     */
    public function json(array $ai, string $system, string $user, int $maxTokens = 1800): array
    {
        if ($ai['key'] === '') {
            throw new RuntimeException('The agent isn’t set up on this ServerOS: there’s no model key.');
        }
        $who = $ai['own'] ? 'your '.(self::PROVIDERS[$ai['provider']]['label'] ?? $ai['provider']).' key' : 'the model';

        try {
            $response = match ($ai['provider']) {
                'custom' => $this->endpoint->request((string) ($ai['url'] ?? ''), $ai['key'], 120)
                    ->post(CompatibleEndpoint::url((string) ($ai['url'] ?? ''), 'chat/completions'), [
                        'model' => $ai['model'],
                        'temperature' => 0.2,
                        'max_completion_tokens' => $maxTokens,
                        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                    ]),
                'anthropic' => Http::withHeaders(['x-api-key' => $ai['key'], 'anthropic-version' => '2023-06-01'])
                    ->connectTimeout(10)->timeout(120)
                    ->post('https://api.anthropic.com/v1/messages', [
                        'model' => $ai['model'],
                        'max_tokens' => $maxTokens,
                        // No temperature: newer Claude models (Haiku 5.5 and up) refuse it.
                        'system' => $system,
                        'messages' => [['role' => 'user', 'content' => $user]],
                    ]),
                'openai' => Http::withToken($ai['key'])->connectTimeout(10)->timeout(120)
                    ->post('https://api.openai.com/v1/responses', [
                        'model' => $ai['model'],
                        'instructions' => $system,
                        'input' => [['role' => 'user', 'content' => $user]],
                        'max_output_tokens' => $maxTokens * 2,
                        'text' => ['format' => ['type' => 'json_object']],
                        'store' => false,
                    ]),
                default => Http::withToken($ai['key'])->connectTimeout(10)->timeout(90)
                    ->post('https://api.groq.com/openai/v1/chat/completions', [
                        'model' => $ai['model'],
                        'temperature' => 0.2,
                        'max_completion_tokens' => $maxTokens,
                        'reasoning_effort' => 'medium',
                        'include_reasoning' => false,
                        'response_format' => ['type' => 'json_object'],
                        'messages' => [['role' => 'system', 'content' => $system], ['role' => 'user', 'content' => $user]],
                    ]),
            };
        } catch (ConnectionException) {
            throw new RuntimeException("Couldn’t reach {$who}. Try again in a minute.");
        }

        if (! $response->successful()) {
            $why = (string) ($response->json('error.message') ?? '');
            throw new RuntimeException(match (true) {
                in_array($response->status(), [401, 403], true) => ucfirst($who).' was refused. Check it in Settings › AI and MCP.',
                $response->status() === 404 && $ai['own'] => "That model isn’t available to {$who}: ".$ai['model'].'.',
                $response->status() === 429 => ucfirst($who).' is out of requests for now. Try again in a minute.',
                default => ucfirst($who).' didn’t answer ('.$response->status().')'.($why !== '' ? ': '.rtrim(mb_substr($why, 0, 160), '.') : '').'. Try again in a minute.',
            });
        }

        $text = match ($ai['provider']) {
            'openai' => $this->openaiJsonText($response),
            'anthropic' => implode('', array_map(fn ($c) => is_array($c) && ($c['type'] ?? null) === 'text' ? (string) ($c['text'] ?? '') : '', (array) $response->json('content', []))),
            default => (string) $response->json('choices.0.message.content'),
        };

        $start = strpos($text, '{');
        $end = strrpos($text, '}');
        $answer = $start === false || $end === false ? null : json_decode($ai['provider'] === 'openai' ? $text : substr($text, $start, $end - $start + 1), true);
        if (! is_array($answer) || ($ai['provider'] === 'openai' && ! str_starts_with(ltrim($text), '{'))) {
            throw new RuntimeException('The model’s answer wasn’t in the shape it was asked for. Try again.');
        }

        return [$answer, (string) ($response->json('model') ?: $ai['model'])];
    }

    private function openaiJsonText(Response $response): string
    {
        if ($response->json('status') !== 'completed') {
            throw new RuntimeException('The model didn’t finish its answer. Try again.');
        }

        $text = '';
        foreach ((array) $response->json('output', []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            if (($item['status'] ?? null) !== 'completed') {
                throw new RuntimeException('The model didn’t finish its answer. Try again.');
            }
            foreach ((array) ($item['content'] ?? []) as $part) {
                if (! is_array($part)) {
                    continue;
                }
                if (($part['type'] ?? null) === 'refusal') {
                    throw new RuntimeException('The model declined to answer. Try asking another way.');
                }
                if (($part['type'] ?? null) === 'output_text') {
                    $text .= (string) ($part['text'] ?? '');
                }
            }
        }

        return $text;
    }

    /** @throws RuntimeException */
    public function verify(string $provider, string $key, ?string $url = null): void
    {
        $name = $provider === 'custom' ? CompatibleEndpoint::host((string) $url) : self::PROVIDERS[$provider]['label'];
        try {
            $response = match ($provider) {
                'anthropic' => Http::withHeaders(['x-api-key' => $key, 'anthropic-version' => '2023-06-01'])->timeout(15)->get('https://api.anthropic.com/v1/models', ['limit' => 1]),
                'custom' => $this->endpoint->request((string) $url, $key, 15)->get(CompatibleEndpoint::url((string) $url, 'models')),
                default => Http::withToken($key)->timeout(15)->get('https://api.openai.com/v1/models'),
            };
        } catch (ConnectionException) {
            throw new RuntimeException('Couldn’t reach '.$name.' to check the key. Try again.');
        }
        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException($name.' didn’t accept that key.', 401);
        }
        if ($provider === 'custom' && $response->redirect()) {
            throw new RuntimeException($name.' sent the request somewhere else. Use the address it ends up at.');
        }
        $missing = 'There’s no OpenAI-compatible API at that address. It usually ends in /v1.';
        if ($provider === 'custom' && $response->status() === 404) {
            throw new RuntimeException($missing);
        }
        if (! $response->successful()) {
            throw new RuntimeException($name.' couldn’t check the key right now ('.$response->status().'). Try again.');
        }
        // A site that serves a page for every path answers 200 too; an API lists its models.
        if ($provider === 'custom' && (! is_array($response->json()) || ! is_array($response->json('data')))) {
            throw new RuntimeException($missing);
        }
    }
}
