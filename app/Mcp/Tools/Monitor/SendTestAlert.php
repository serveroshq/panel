<?php

namespace App\Mcp\Tools\Monitor;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\NotAudited;
use App\Support\Monitors\MonitorAlerts;
use App\Support\Monitors\TelegramAlerts;

final class SendTestAlert extends Tool
{
    public function name(): string
    {
        return 'send_test_alert';
    }

    public function title(): string
    {
        return 'Send a test alert';
    }

    public function description(): string
    {
        return 'Posts a test message (“Test from ServerOS: alerts for … will arrive here.”) to a webhook or a Telegram chat, to check alerts will arrive. channel webhook (the default) tries the webhook given, or the workspace’s saved one when left out. channel telegram tries telegram_chat and telegram_token, each falling back to the saved one when left out. It posts to the person’s real channel, so do it when they ask. Returns ok, or what went wrong (not https, can’t be reached from the panel, the status it answered, Telegram turning down the token or the chat). Nothing is saved: update_alert_settings does that.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'channel' => Schema::string('Where to send it: webhook (the default) or telegram.', ['webhook', 'telegram']),
            'webhook' => Schema::string('The https address to try, like a Slack incoming webhook. Left out: the saved webhook.', maxLength: 2000),
            'telegram_chat' => Schema::string('With channel telegram: the chat id to try, like -1001234567890 or @channelname. Left out: the saved one.', maxLength: 64),
            'telegram_token' => Schema::string('With channel telegram: the bot token to try. Left out: the saved one.', maxLength: 128),
        ]);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return false;
    }

    public function openWorld(): bool
    {
        return true;
    }

    #[NotAudited('Sends a test alert; nothing changes')]
    public function handle(McpContext $context, array $arguments): array
    {
        $workspace = $context->workspace;
        if (($arguments['channel'] ?? 'webhook') === 'telegram') {
            return $this->telegram($context, $arguments);
        }
        $saved = is_string($workspace->alert_webhook_url) && $workspace->alert_webhook_url !== '' ? $workspace->alert_webhook_url : null;
        $given = isset($arguments['webhook']) && ! (is_string($arguments['webhook']) && trim($arguments['webhook']) === '');
        if (! $given && $saved === null) {
            throw new ToolError('No webhook is set for this workspace. Pass webhook to try one, or set one with update_alert_settings.');
        }

        $url = $given
            ? $this->validate($arguments, ['webhook' => ['required', 'string', 'max:2000', 'url:https']], ['webhook.url' => 'The webhook needs an https:// address.'])['webhook']
            : $saved;
        Monitors::throttle($context, 'test-alert', 10, 'That’s a lot of test alerts in a minute.');

        $error = app(MonitorAlerts::class)->test($workspace, trim($url));

        return [
            'ok' => $error === null,
            'error' => $error,
            'webhook' => $given ? 'given' : 'saved',
            'host' => parse_url(trim($url), PHP_URL_HOST),
            'note' => $error === null ? 'Sent. Ask the person to check the channel.' : 'It didn’t arrive: '.$error,
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function telegram(McpContext $context, array $arguments): array
    {
        $workspace = $context->workspace;
        $data = $this->validate($arguments, TelegramAlerts::rules(), TelegramAlerts::messages());
        $chat = filled($data['telegram_chat'] ?? null) ? trim((string) $data['telegram_chat']) : $workspace->alert_telegram_chat;
        $token = filled($data['telegram_token'] ?? null) ? trim((string) $data['telegram_token']) : $workspace->alert_telegram_token;
        if (blank($chat) || blank($token)) {
            throw new ToolError('No Telegram chat is set for this workspace. Pass telegram_chat and telegram_token to try one, or set them with update_alert_settings.');
        }
        Monitors::throttle($context, 'test-alert', 10, 'That’s a lot of test alerts in a minute.');

        $error = app(TelegramAlerts::class)->test($workspace, (string) $token, (string) $chat);

        return [
            'ok' => $error === null,
            'error' => $error,
            'channel' => 'telegram',
            'chat' => $chat,
            'note' => $error === null ? 'Sent. Ask the person to check the Telegram chat.' : 'It didn’t arrive: '.$error,
        ];
    }
}
