<?php

namespace App\Mcp\Tools\Webhook;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Mcp\Paging;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\WebhookDelivery;
use App\Support\Webhooks\WebhookEndpoints;

final class ListWebhookDeliveries extends Tool
{
    private const STATES = ['pending', 'delivered', 'failed'];

    public function name(): string
    {
        return 'list_webhook_deliveries';
    }

    public function title(): string
    {
        return 'List webhook deliveries';
    }

    public function description(): string
    {
        return 'Lists an endpoint’s recent deliveries, newest first (kept 30 days): the event and its id, state (pending: being tried, delivered, or failed: gave up), how many attempts, the status code the receiver answered, what went wrong, and when the next try is.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'webhook' => WebhookRefs::schema(),
            'state' => Schema::string('Only deliveries in this state.', self::STATES),
            'limit' => Schema::integer('How many to return (1–100, default 20).', 1, 100),
            ...Paging::schema(),
        ], ['webhook']);
    }

    public function responseSchema(): array
    {
        return ResponseSchemas::page('deliveries', ResponseSchemas::ref('WebhookDelivery'));
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $endpoint = WebhookRefs::find($context, $arguments['webhook'] ?? null);
        $data = $this->validate($arguments, [
            'state' => ['sometimes', 'nullable', 'in:'.implode(',', self::STATES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            ...Paging::rules(),
        ]);
        $query = $endpoint->deliveries()->when($data['state'] ?? null, fn ($q, $state) => $q->where('state', $state));
        $total = (clone $query)->count();
        $offset = Paging::offset($data);
        $page = $query->orderByDesc('id')->skip($offset)->take((int) ($data['limit'] ?? 20))->get();

        return [
            'total' => $total,
            'offset' => $offset,
            'more' => $offset + $page->count() < $total,
            'next_cursor' => Paging::next($offset + $page->count(), $total),
            'deliveries' => $page->map(fn (WebhookDelivery $d) => WebhookEndpoints::delivery($d))->all(),
        ];
    }
}
