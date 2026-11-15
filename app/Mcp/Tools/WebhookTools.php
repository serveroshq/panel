<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Webhook\CreateWebhook;
use App\Mcp\Tools\Webhook\DeleteWebhook;
use App\Mcp\Tools\Webhook\ListWebhookDeliveries;
use App\Mcp\Tools\Webhook\ListWebhooks;
use App\Mcp\Tools\Webhook\TestWebhook;
use App\Mcp\Tools\Webhook\UpdateWebhook;

final class WebhookTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            ListWebhooks::class,
            CreateWebhook::class,
            UpdateWebhook::class,
            DeleteWebhook::class,
            TestWebhook::class,
            ListWebhookDeliveries::class,
        ];
    }
}
