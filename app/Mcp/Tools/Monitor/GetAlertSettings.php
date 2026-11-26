<?php

namespace App\Mcp\Tools\Monitor;

use App\Mcp\McpContext;
use App\Mcp\Tool;

final class GetAlertSettings extends Tool
{
    public function name(): string
    {
        return 'get_alert_settings';
    }

    public function title(): string
    {
        return 'Get alert settings';
    }

    public function description(): string
    {
        return 'Returns where the workspace’s alerts go: email (on or off; it goes to owners and admins, each getting all alerts, only problems, or none as they chose; recipients lists them to people who may change the settings) whether a webhook (a Slack or Discord incoming webhook, or any https endpoint) is set, and whether a Telegram chat is set (its bot posts there). The webhook’s address is never returned, only its host, and the Telegram bot’s token never is, only its chat id (to people who may change them). '
            .'Also what sends an alert, and how many monitors have their alerts off or muted. Change it with update_alert_settings; send_test_alert tries a webhook or the Telegram chat.';
    }

    public function handle(McpContext $context, array $arguments): array
    {
        return AlertSettings::present($context);
    }
}
