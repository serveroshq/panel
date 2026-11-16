<?php

namespace App\Mcp\Tools\Webhook;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Audit\AuditContext;
use App\Support\Webhooks\WebhookEndpoints;
use App\Support\Webhooks\Webhooks;
use Illuminate\Support\Facades\RateLimiter;

final class TestWebhook extends Tool
{
    private const int PER_MINUTE = 10;

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
        $endpoint = WebhookRefs::find($context, $arguments['webhook'] ?? null);
        // Each test holds a request open while the receiver answers, so a workspace gets a few a minute.
        $limit = 'webhook-test:'.$context->workspace->id;
        if (RateLimiter::tooManyAttempts($limit, self::PER_MINUTE)) {
            throw new ToolError('A workspace can send '.self::PER_MINUTE.' test events a minute. Try again in '.RateLimiter::availableIn($limit).' seconds.');
        }
        RateLimiter::hit($limit, 60);
        $delivery = $this->webhooks->test($endpoint);
        AuditContext::nothingChanged();

        return ['delivered' => $delivery->state === 'delivered', 'delivery' => WebhookEndpoints::delivery($delivery)];
    }
}
