<?php

namespace App\Mcp\Tools\Webhook;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;

final class DeleteWebhook extends Tool
{
    public function name(): string
    {
        return 'delete_webhook';
    }

    public function title(): string
    {
        return 'Remove a webhook endpoint';
    }

    public function description(): string
    {
        return 'Removes a webhook endpoint and its delivery history. Deliveries still being retried stop.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['webhook' => WebhookRefs::schema()], ['webhook']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function destructive(): bool
    {
        return true;
    }

    public function responseSchema(): array
    {
        return ResponseSchemas::object(['deleted' => ['type' => 'boolean'], 'webhook' => ResponseSchemas::object(['id' => ['type' => 'integer']], ['id'])], ['deleted', 'webhook']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $endpoint = WebhookRefs::find($context, $arguments['webhook'] ?? null);
        $host = (string) parse_url($endpoint->url, PHP_URL_HOST);
        $endpoint->delete();
        $context->record('api.webhook_delete', $host, null, ['endpoint' => $endpoint->id]);

        return ['deleted' => true, 'webhook' => ['id' => $endpoint->id]];
    }
}
