<?php

namespace App\Mcp\Tools\Operation;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Operations\Operations;

final class PauseOperation extends Tool
{
    public function __construct(private readonly Operations $operations) {}

    public function name(): string
    {
        return 'pause_operation';
    }

    public function title(): string
    {
        return 'Pause an operation';
    }

    public function description(): string
    {
        return 'Pauses a running operation: no new machines start, and machines already running finish. resume_operation carries on.';
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
        $this->operations->pause($operation, $context);

        return ['operation' => Operations::view($operation->fresh() ?? $operation)];
    }
}
