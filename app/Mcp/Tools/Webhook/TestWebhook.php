<?php

namespace App\Mcp\Tools\Webhook;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Audit\AuditContext;
use App\Support\Webhooks\WebhookEndpoints;
use App\Support\Webhooks\Webhooks;

final class TestWebhook extends Tool
{
    public function __construct(private readonly Webhooks $webhooks) {}

    public function name(): string
    {
        return 'test_webhook';
    }

    public function title(): string
    {
        return 'Send a test event';
    }

    public function description(): string
    {
        return 'Sends a signed webhook.test event to an endpoint now, once, and says how the receiver answered, so you can check the address and the signature check. It works on an endpoint that’s turned off too, and isn’t retried.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['webhook' => WebhookRefs::schema()], ['webhook']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function responseSchema(): array
    {
        return ResponseSchemas::object(['delivered' => ['type' => 'boolean'], 'delivery' => ResponseSchemas::ref('WebhookDelivery')], ['delivered', 'delivery']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $delivery = $this->webhooks->test(WebhookRefs::find($context, $arguments['webhook'] ?? null));
        AuditContext::nothingChanged();

        return ['delivered' => $delivery->state === 'delivered', 'delivery' => WebhookEndpoints::delivery($delivery)];
    }
}
