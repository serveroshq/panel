<?php

namespace App\Mcp\Tools\Service;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Support\Audit\NotAudited;
use App\Support\Deploys\ServiceLabels;

final class RemoveServiceIcon extends Tool
{
    public function name(): string
    {
        return 'remove_service_icon';
    }

    public function title(): string
    {
        return 'Remove a service’s icon';
    }

    public function description(): string
    {
        return 'Removes the icon given to a service in ServerOS, so it shows the one ServerOS picks for its kind or image again. Nothing changes on the machine. Does nothing if it has no icon of its own.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'service' => Schema::service(),
        ], ['machine', 'service']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function idempotent(): bool
    {
        return true;
    }

    public function responseSchema(): array
    {
        return ResponseSchemas::object([
            'service' => ResponseSchemas::ref('ServiceRef'),
            'removed' => ['type' => 'boolean', 'description' => 'False when it had no icon.'],
        ], ['service', 'removed']);
    }

    #[NotAudited('Cosmetic')]
    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ServiceWork::serviceArguments());
        [$machine, $service] = ServiceWork::find($context, $data);
        $had = $service['icon'] !== null;

        ServiceLabels::setIcon($machine, $service, null);

        return [
            'service' => ['key' => $service['key'], 'name' => $service['display'], 'machine' => ['id' => $machine->id, 'name' => $machine->name]],
            'removed' => $had,
        ];
    }
}
