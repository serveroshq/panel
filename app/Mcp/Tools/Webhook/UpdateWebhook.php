<?php

namespace App\Mcp\Tools\Webhook;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Webhooks\WebhookEndpoints;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class UpdateWebhook extends Tool
{
    public function __construct(private readonly WebhookEndpoints $endpoints) {}

    public function name(): string
    {
        return 'update_webhook';
    }

    public function title(): string
    {
        return 'Change a webhook endpoint';
    }

    public function description(): string
    {
        return 'Changes a webhook endpoint’s address, events or note, or turns it off or back on (enabled). Turning it back on after it was turned off for failing starts sending new events again. The secret stays the same.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'webhook' => WebhookRefs::schema(),
            'url' => Schema::string('A new https:// address.', maxLength: 2000),
            'events' => WebhookRefs::events(),
            'description' => Schema::string('A new note; empty clears it.', maxLength: 120),
            'enabled' => Schema::boolean('false turns it off; true turns it back on.'),
        ], ['webhook']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $endpoint = WebhookRefs::find($context, $arguments['webhook'] ?? null);
        try {
            $this->endpoints->update($endpoint, Arr::except($arguments, ['webhook']));
        } catch (ValidationException $e) {
            throw new ToolError('Invalid arguments: '.implode(' ', Arr::flatten($e->errors())));
        }
        $context->record('api.webhook_update', (string) parse_url($endpoint->url, PHP_URL_HOST), null, ['endpoint' => $endpoint->id, 'enabled' => $endpoint->enabled]);

        return ['webhook' => WebhookEndpoints::view($endpoint)];
    }
}
