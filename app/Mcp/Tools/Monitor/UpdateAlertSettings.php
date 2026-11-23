<?php

namespace App\Mcp\Tools\Monitor;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Monitors\AlertKinds;
use App\Support\Monitors\HostResolver;
use App\Support\Monitors\TelegramAlerts;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class UpdateAlertSettings extends Tool
{
    public function name(): string
    {
        return 'update_alert_settings';
    }

    public function title(): string
    {
        return 'Change where alerts go';
    }

    public function description(): string
    {
        return 'Changes where the workspace’s alerts go (monitors down and back, machines that stop reporting, full disks, expiring certificates). email turns alert emails to owners and admins on or off. webhook sets the https address alerts are posted to (a Slack or Discord incoming webhook works as is); an empty string removes it. telegram_chat and telegram_token send alerts to a Telegram chat through the person’s own bot (made with @BotFather, and added to the group or channel): the chat id is a number like -1001234567890 or a public channel’s @name; the token is kept encrypted and never returned, and left out it keeps the saved one; an empty telegram_chat removes Telegram. off lists the kinds of alert not to send at all (machines, resources, services, deploys, backups, monitors, workflows, updates, security); an empty list sends every kind. Give only what changes: what’s set stays when it’s left out. '
            .'The webhook must be reachable from the internet. Try it first with send_test_alert. Returns the settings (the webhook’s address and the bot’s token aren’t echoed back).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'email' => Schema::boolean('Email alerts to the workspace’s owners and admins.'),
            'webhook' => Schema::string('The https address to post alerts to, like a Slack incoming webhook. An empty string removes it.', maxLength: 2000),
            'telegram_chat' => Schema::string('The Telegram chat to post alerts to: its id, like -1001234567890, or a public channel’s @name. An empty string removes Telegram.', maxLength: 64),
            'telegram_token' => Schema::string('The Telegram bot’s token from @BotFather, like 123456789:AA… Left out, the saved one stays.', maxLength: 128),
            'off' => Schema::array(Schema::string('A kind of alert.', AlertKinds::keys()), 'The kinds of alert not to send, by email or webhook: machines (stopped reporting), resources (disk or memory low), services (stopped or crash-looping), deploys, backups, monitors (down, certificates expiring), workflows (failed runs), updates (a daemon update waiting for approval, and installed, on machines whose policy asks first), security (known vulnerabilities, CVEs, that a waiting OS update fixes). The whole list: an empty list turns every kind on.', count(AlertKinds::keys())),
        ]);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        if (array_intersect(['email', 'webhook', 'telegram_chat', 'telegram_token', 'off'], array_keys($arguments)) === []) {
            throw new ToolError('Nothing to change: give email, webhook, telegram_chat, telegram_token or off.');
        }
        Monitors::throttle($context, 'alerts', 20, 'That’s a lot of changes to alerts in a minute.');

        $workspace = $context->workspace;
        $data = $this->validate([...['email' => (bool) ($workspace->alert_email ?? true)], ...$arguments], [
            'email' => ['required', 'boolean'],
            'webhook' => ['nullable', 'string', 'max:2000', 'url:https'],
            ...TelegramAlerts::rules(),
            'off' => ['sometimes', 'array'],
            'off.*' => ['string', Rule::in(AlertKinds::keys())],
        ], ['webhook.url' => 'The webhook needs an https:// address.', 'off.*.in' => 'Kinds are: '.implode(', ', AlertKinds::keys()).'.', ...TelegramAlerts::messages()]);

        $webhook = $workspace->alert_webhook_url;
        if (array_key_exists('webhook', $arguments)) {
            $webhook = isset($data['webhook']) && trim($data['webhook']) !== '' ? trim($data['webhook']) : null;
            if ($webhook !== null && app(HostResolver::class)->resolve((string) parse_url($webhook, PHP_URL_HOST)) === []) {
                throw new ToolError('That address can’t be reached from the panel.');
            }
        }

        try {
            $telegram = TelegramAlerts::merge($workspace, $data);
        } catch (ValidationException $e) {
            throw new ToolError(implode(' ', Arr::flatten($e->errors())));
        }

        $muted = array_key_exists('off', $data) ? array_values(array_intersect(AlertKinds::keys(), $data['off'])) : AlertKinds::muted($workspace);

        $workspace->forceFill([
            'alert_email' => (bool) $data['email'],
            'alert_webhook_url' => $webhook,
            'alert_telegram_chat' => $telegram['chat'],
            'alert_telegram_token' => $telegram['token'],
            'alert_muted' => $muted,
        ])->save();
        $context->record('alerts.update', null, null, ['email' => (bool) $data['email'], 'webhook' => $webhook !== null, 'telegram' => $telegram['chat'] !== null, 'muted' => $muted]);

        return [...AlertSettings::present($context), 'note' => 'Saved.'];
    }
}
