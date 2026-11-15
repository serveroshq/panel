<?php

namespace App\Mcp\Tools\Webhook;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\ToolError;
use App\Models\WebhookEndpoint;
use App\Support\Webhooks\Webhooks;

final class WebhookRefs
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return Schema::integer('The webhook endpoint’s id, as list_webhooks shows it.', 1);
    }

    /** @return array<string, mixed> */
    public static function events(): array
    {
        return Schema::array(Schema::string('An event.', Webhooks::EVENTS), 'Which events it gets. Leave out (or empty) for all of them.', count(Webhooks::EVENTS));
    }

    public static function find(McpContext $context, mixed $id): WebhookEndpoint
    {
        $endpoint = is_numeric($id) ? WebhookEndpoint::query()->where('workspace_id', $context->workspace->id)->find((int) $id) : null;

        return $endpoint ?? throw new ToolError("No webhook endpoint #{$id} in {$context->workspace->name}. list_webhooks shows them.");
    }
}
