<?php

namespace App\Mcp\Tools\Operation;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Operations\Operations;

final class CancelOperation extends Tool
{
    public function __construct(private readonly Operations $operations) {}

    public function name(): string
    {
        return 'cancel_operation';
    }

    public function title(): string
    {
        return 'Cancel an operation';
    }

    public function description(): string
    {
        return 'Cancels a running or paused operation: machines that haven’t started are skipped, and those already running finish. It can’t be undone; start a new operation to do the rest.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['operation' => OperationRefs::schema()], ['operation']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function responseSchema(): array
    {
        return ResponseSchemas::object(['operation' => ResponseSchemas::ref('Operation')], ['operation']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $operation = OperationRefs::find($context, $arguments['operation'] ?? null);
        $this->operations->cancel($operation, $context);

        return ['operation' => Operations::view($operation->fresh() ?? $operation)];
    }
}
