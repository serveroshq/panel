<?php

namespace App\Mcp\Tools\Operation;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\Operations\Operations;

final class GetOperation extends Tool
{
    public function name(): string
    {
        return 'get_operation';
    }

    public function title(): string
    {
        return 'Get an operation';
    }

    public function description(): string
    {
        return 'Returns an operation (work across many machines) with its progress and each machine: its batch, state (pending, running, checking: done and staying healthy, succeeded, failed or skipped), the job it started (follow it with get_job) and what happened.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['operation' => OperationRefs::schema()], ['operation']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        return ['operation' => Operations::view(OperationRefs::find($context, $arguments['operation'] ?? null))];
    }
}
