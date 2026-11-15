<?php

namespace App\Mcp\Tools;

use App\Mcp\ToolGroup;
use App\Mcp\Tools\Operation\CancelOperation;
use App\Mcp\Tools\Operation\CreateOperation;
use App\Mcp\Tools\Operation\GetOperation;
use App\Mcp\Tools\Operation\ListOperations;
use App\Mcp\Tools\Operation\PauseOperation;
use App\Mcp\Tools\Operation\ResumeOperation;

final class OperationTools implements ToolGroup
{
    public static function tools(): array
    {
        return [
            CreateOperation::class,
            ListOperations::class,
            GetOperation::class,
            PauseOperation::class,
            ResumeOperation::class,
            CancelOperation::class,
        ];
    }
}
