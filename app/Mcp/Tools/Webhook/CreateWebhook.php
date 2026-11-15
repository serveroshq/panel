<?php

namespace App\Mcp\Tools\Webhook;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Webhooks\WebhookEndpoints;
use App\Support\Webhooks\Webhooks;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class CreateWebhook extends Tool
{
    public function __construct(private readonly WebhookEndpoints $endpoints) {}

    public function name(): string
    {
        return 'create_webhook';
    }

    public function title(): string
    {
        return 'Add a webhook endpoint';
    }

    public function description(): string
    {
        return 'Adds an https:// address that gets signed events from this workspace as they happen, instead of polling: '.implode(', ', Webhooks::EVENTS).'. Each event carries ids and states (like operation_id and state), never logs or customer data; fetch the details with an API key. '
            .'Returns the endpoint and its secret, which is shown only once: receivers check the ServerOS-Signature header with it (the SDK’s verifyWebhook does). A failed delivery is retried with backoff for up to 24 hours; an endpoint that keeps failing that long is turned off and whoever added it gets an email. Up to '.Webhooks::MAX_ENDPOINTS.' per workspace.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'url' => Schema::string('The https:// address to send events to.', maxLength: 2000),
            'events' => WebhookRefs::events(),
            'description' => Schema::string('A note on what it’s for, like “CI notifier”.', maxLength: 120),
        ], ['url']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        try {
            [$endpoint, $secret] = $this->endpoints->create($context->workspace, $context->user, $arguments);
        } catch (ValidationException $e) {
            throw new ToolError('Invalid arguments: '.implode(' ', Arr::flatten($e->errors())));
        }
        $context->record('api.webhook_create', (string) parse_url($endpoint->url, PHP_URL_HOST), null, ['endpoint' => $endpoint->id, 'events' => $endpoint->events ?? 'all']);

        return ['webhook' => WebhookEndpoints::view($endpoint), 'secret' => $secret, 'note' => 'Store the secret now: it isn’t shown again.'];
    }
}
