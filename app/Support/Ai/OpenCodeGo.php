<?php

namespace App\Support\Ai;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * OpenCode Go subscription inference (https://opencode.ai/inference/go).
 *
 * This is not the pay-as-you-go Console gateway at https://opencode.ai/inference.
 * Official docs: https://opencode.ai/docs/console/go/ and
 * https://opencode.ai/docs/console/harnesses
 */
class OpenCodeGo
{
    public const string PROVIDER = 'opencode-go';

    public const string LABEL = 'OpenCode Go';

    public const string BASE = 'https://opencode.ai/inference/go';

    public const string PAYG_BASE = 'https://opencode.ai/inference';

    public const string DEFAULT_MODEL = 'kimi-k3';

    public const string MODELS = '/v1/models';

    public const string CHAT_COMPLETIONS = '/openai/v1/chat/completions';

    /**
     * @var array<string, string>
     */
    public const array FALLBACK_MODELS = [
        'kimi-k3' => 'Kimi K3',
        'kimi-k2.7-code' => 'Kimi K2.7 Code',
        'glm-5.3' => 'GLM-5.3',
        'glm-5.3-flash' => 'GLM-5.3-Flash',
        'minimax-m3' => 'MiniMax M3',
    ];

    public static function url(string $path): string
    {
        return self::BASE.$path;
    }

    public static function userAgent(): string
    {
        $version = 'dev';
        $file = base_path('VERSION');
        if (is_readable($file)) {
            $raw = trim((string) file_get_contents($file));
            if ($raw !== '' && preg_match('/^[A-Za-z0-9._+-]{1,32}$/', $raw) === 1) {
                $version = $raw;
            }
        }

        return 'ServerOS-Panel/'.$version;
    }

    /**
     * Stable per-conversation session id. The same key always yields the same
     * value; different conversations do not share an id.
     */
    public static function sessionId(string $conversationKey): string
    {
        $conversationKey = trim($conversationKey);
        if ($conversationKey === '') {
            throw new \InvalidArgumentException('OpenCode Go needs a conversation key for the x-opencode-session header.');
        }

        return 'ses_'.substr(hash('sha256', 'serveros:opencode-go:v1:'.$conversationKey), 0, 32);
    }

    public static function http(string $key, ?string $session = null, bool $stream = false): PendingRequest
    {
        $headers = [
            'User-Agent' => self::userAgent(),
            'Accept' => $stream ? 'text/event-stream' : 'application/json',
        ];
        if (is_string($session) && $session !== '') {
            $headers['x-opencode-session'] = $session;
        }

        $request = Http::withToken($key)
            ->withHeaders($headers)
            ->withUserAgent(self::userAgent());

        if ($stream) {
            $request = $request->withOptions(['stream' => true]);
        }

        return $request;
    }
}
