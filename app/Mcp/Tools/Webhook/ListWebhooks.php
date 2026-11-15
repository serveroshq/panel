<?php

namespace App\Mcp\Tools\Webhook;

use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Models\WebhookEndpoint;
use App\Support\Webhooks\WebhookEndpoints;
use App\Support\Webhooks\Webhooks;

final class ListWebhooks extends Tool
{
    public function name(): string
    {
        return 'list_webhooks';
    }

    public function title(): string
    {
        return 'List webhook endpoints';
    }

    public function description(): string
    {
        return 'Lists the workspace’s webhook endpoints: where events go (the address without its query string), which events (all, or a list from: '.implode(', ', Webhooks::EVENTS).'), whether it’s on (and why it was turned off, if it was), and its last delivery. Every delivery is signed with a ServerOS-Signature header: t=<unix time>,v1=<hex HMAC-SHA256 of "t.body"> with the endpoint’s secret.';
    }

    public function handle(McpContext $context, array $arguments): array
    {
        return [
            'events' => Webhooks::EVENTS,
            'webhooks' => WebhookEndpoint::query()->where('workspace_id', $context->workspace->id)->orderBy('id')->get()
                ->map(fn (WebhookEndpoint $e) => WebhookEndpoints::view($e))->all(),
        ];
    }
}
