<?php

namespace App\Support\Monitors;

use App\Models\Workspace;

final class AlertKinds
{
    public const EVENTS = [
        'machines' => ['machine.offline', 'machine.online'],
        'resources' => ['machine.disk', 'machine.memory', 'machine.memory_ok'],
        'services' => ['service.down', 'service.up', 'service.crash_loop'],
        'deploys' => ['deploy.failed', 'deploy.recovered'],
        'backups' => ['backup.failed', 'backup.recovered'],
        'monitors' => ['monitor.down', 'monitor.up', 'certificate.expiring'],
        'workflows' => ['workflow.failed'],
        'updates' => ['daemon.update_waiting'],
        'security' => ['security.vulnerability'],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::EVENTS);
    }

    public static function of(string $event): ?string
    {
        foreach (self::EVENTS as $kind => $events) {
            if (in_array($event, $events, true)) {
                return $kind;
            }
        }

        return null;
    }

    /** @return list<string> */
    public static function muted(Workspace $workspace): array
    {
        $muted = is_array($workspace->alert_muted) ? $workspace->alert_muted : [];

        return array_values(array_intersect(self::keys(), $muted));
    }

    /**
     * The alert settings a page shows. The webhook's address only to people
     * who may change it; the Telegram bot's token never, only that it's set.
     *
     * @return array{email: bool, webhook: string|null, webhookSet: bool, telegram: array{chat: string|null, set: bool}, muted: list<string>}
     */
    public static function props(Workspace $workspace, bool $manager): array
    {
        return [
            'email' => (bool) ($workspace->alert_email ?? true),
            'webhook' => $manager ? $workspace->alert_webhook_url : null,
            'webhookSet' => filled($workspace->alert_webhook_url),
            'telegram' => [
                'chat' => $manager ? $workspace->alert_telegram_chat : null,
                'set' => TelegramAlerts::configured($workspace),
            ],
            'muted' => self::muted($workspace),
        ];
    }

    public static function allows(Workspace $workspace, string $event): bool
    {
        $kind = self::of($event);

        return $kind === null || ! in_array($kind, self::muted($workspace), true);
    }
}
