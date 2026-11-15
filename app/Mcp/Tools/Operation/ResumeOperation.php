<?php

namespace App\Mcp\Tools\Operation;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Operations\Operations;

final class ResumeOperation extends Tool
{
    public function __construct(private readonly Operations $operations) {}

    public function name(): string
    {
        return 'resume_operation';
    }

    public function title(): string
    {
        return 'Resume an operation';
    }

    public function description(): string
    {
        return 'Carries on with a paused operation from where it stopped. Machines that already failed don’t count towards max_failures again.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['operation' => OperationRefs::schema()], ['operation']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $operation = OperationRefs::find($context, $arguments['operation'] ?? null);
        $this->operations->resume($operation, $context);

        return ['operation' => Operations::view($operation->fresh() ?? $operation)];
    }
}
