<?php

namespace App\Mcp\Tools\Operation;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\ToolError;
use App\Models\Operation;

final class OperationRefs
{
    /** @return array<string, mixed> */
    public static function schema(): array
    {
        return Schema::integer('The operation’s id, as create_operation or list_operations gives it.', 1);
    }

    public static function find(McpContext $context, mixed $id): Operation
    {
        $operation = is_numeric($id) ? Operation::query()->where('workspace_id', $context->workspace->id)->find((int) $id) : null;

        $id = is_scalar($id) ? (string) $id : '';

        return $operation ?? throw new ToolError("No operation #{$id} in {$context->workspace->name}. list_operations shows them.");
    }
}
