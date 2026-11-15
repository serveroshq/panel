<?php

namespace App\Mcp\Tools\Operation;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\Operation;
use App\Support\Operations\Operations;

final class ListOperations extends Tool
{
    private const STATES = ['running', 'paused', 'finished', 'cancelled'];

    public function name(): string
    {
        return 'list_operations';
    }

    public function title(): string
    {
        return 'List operations';
    }

    public function description(): string
    {
        return 'Lists the workspace’s operations (work across many machines), newest first: kind, state (running, paused, finished or cancelled), why it paused, the strategy and progress (how many machines are pending, running, checking, succeeded, failed or skipped). Filter with state. get_operation shows each machine.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'state' => Schema::string('Only operations in this state.', self::STATES),
            'limit' => Schema::integer('How many to return (1–100, default 20).', 1, 100),
            'offset' => Schema::integer('How many to skip, for the next page (default 0).', 0),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'state' => ['sometimes', 'nullable', 'in:'.implode(',', self::STATES)],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);
        $query = Operation::query()->where('workspace_id', $context->workspace->id)
            ->when($data['state'] ?? null, fn ($q, $state) => $q->where('state', $state));
        $total = (clone $query)->count();
        $offset = (int) ($data['offset'] ?? 0);
        $page = $query->with('creator')->orderByDesc('id')->skip($offset)->take((int) ($data['limit'] ?? 20))->get();

        return [
            'total' => $total,
            'offset' => $offset,
            'more' => $offset + $page->count() < $total,
            'operations' => $page->map(fn (Operation $o) => Operations::view($o, machines: false))->all(),
        ];
    }
}
