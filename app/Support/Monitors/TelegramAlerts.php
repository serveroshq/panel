<?php

namespace App\Support\Monitors;

use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Alerts posted to a Telegram chat by the workspace's own bot (made with
 *
 * @BotFather). The bot's token is a password: it's kept encrypted, goes only
 * to api.telegram.org, and is never logged or shown again.
 */
final class TelegramAlerts
{
    // A chat's number (groups and channels are negative, like -1001234567890), or a public channel's @name.
    public const string CHAT_PATTERN = '/^(?:-?\d{1,20}|@[A-Za-z][A-Za-z0-9_]{4,31})$/';

    // What @BotFather hands out: the bot's number, a colon, then the secret.
    public const string TOKEN_PATTERN = '/^\d{3,20}:[A-Za-z0-9_-]{30,80}$/';

    private const int MAX_TEXT = 4000;

    public static function configured(Workspace $workspace): bool
    {
        return filled($workspace->alert_telegram_chat) && filled($workspace->alert_telegram_token);
    }

    /** @return array<string, list<string>> */
    public static function rules(string $chat = 'telegram_chat', string $token = 'telegram_token'): array
    {
        return [
            $chat => ['nullable', 'string', 'max:64', 'regex:'.self::CHAT_PATTERN],
            $token => ['nullable', 'string', 'max:128', 'regex:'.self::TOKEN_PATTERN],
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $chat = 'telegram_chat', string $token = 'telegram_token'): array
    {
        return [
            $chat.'.regex' => 'A chat id is a number, like -1001234567890, or a public channel’s @name.',
            $token.'.regex' => 'That isn’t a bot token. @BotFather gives one like 123456789:AA…',
        ];
    }

    /**
     * The chat and token to save from validated input. A chat left out keeps
     * what's saved, an empty one removes Telegram, and a token left out or
     * empty keeps the saved one (it's never shown, so it can't be sent back).
     *
     * @param  array<string, mixed>  $data
     * @return array{chat: string|null, token: string|null}
     *
     * @throws ValidationException
     */
    public static function merge(Workspace $workspace, array $data, string $chatKey = 'telegram_chat', string $tokenKey = 'telegram_token'): array
    {
        $given = fn (string $key): ?string => is_string($data[$key] ?? null) && trim($data[$key]) !== '' ? trim($data[$key]) : null;

        $chat = array_key_exists($chatKey, $data) ? $given($chatKey) : $workspace->alert_telegram_chat;
        if ($chat === null) {
            return ['chat' => null, 'token' => null];
        }

        $token = $given($tokenKey) ?? $workspace->alert_telegram_token;
        if (blank($token)) {
            throw ValidationException::withMessages([$tokenKey => 'Add the bot’s token from @BotFather too.']);
        }

        return ['chat' => $chat, 'token' => $token];
    }

    /**
     * Post the alert to the workspace's chat, if it has one. Failures are
     * logged (never with the token), never thrown.
     */
    public function notify(Workspace $workspace, string $event, string $text): void
    {
        if (! self::configured($workspace)) {
            return;
        }

        $error = $this->send((string) $workspace->alert_telegram_token, (string) $workspace->alert_telegram_chat, $text);
        if ($error !== null) {
            Log::warning('Telegram alert failed', ['workspace' => $workspace->id, 'event' => $event, 'error' => $error]);
        }
    }

    public function test(Workspace $workspace, string $token, string $chat): ?string
    {
        return $this->send($token, $chat, "Test from ServerOS: alerts for {$workspace->name} will arrive here.");
    }

    /** What went wrong, in words a person can act on; null when it was sent. */
    public function send(string $token, string $chat, string $text): ?string
    {
        try {
            $response = Http::timeout(8)
                ->withOptions(['allow_redirects' => false])
                ->asJson()
                ->post('https://api.telegram.org/bot'.$token.'/sendMessage', [
                    'chat_id' => $chat,
                    'text' => mb_substr($text, 0, self::MAX_TEXT),
                    'disable_web_page_preview' => true,
                ]);
        } catch (Throwable) {
            // The exception's message can hold the address, and with it the token.
            return 'Couldn’t reach Telegram.';
        }

        if ($response->successful()) {
            return null;
        }

        $said = strtolower((string) $response->json('description'));

        return match (true) {
            in_array($response->status(), [401, 404], true) => 'Telegram didn’t accept the bot token.',
            str_contains($said, 'chat not found') => 'Telegram couldn’t find that chat. Add the bot to the group or channel first, or send the bot a message.',
            $response->status() === 403 => 'The bot can’t post in that chat. Add it to the group or channel, or start a chat with it.',
            $response->status() === 429 => 'Telegram is asking to slow down. Try again in a minute.',
            default => "Telegram answered {$response->status()}.",
        };
    }
}
