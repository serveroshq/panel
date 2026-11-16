<?php

namespace App\Mcp\Tools\Machine;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\PackageRollout;
use App\Support\Machines\PackageRollouts;

final class GetPackageRollout extends Tool
{
    public function name(): string
    {
        return 'get_package_rollout';
    }

    public function title(): string
    {
        return 'Get an OS updates rollout';
    }

    public function description(): string
    {
        return 'Follows a rollout of operating system updates (from create_package_rollout or the Updates page): its state (pending, running, done, failed or cancelled), its plan (packages, latest or exact versions, batch size, stop on failure, restarts, when), how many machines are finished, and each machine’s state (pending, running, done, failed, skipped) with what happened in words. Leave rollout out for the 20 latest rollouts, without their machines.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'rollout' => Schema::integer('The rollout id. Leave out to list the latest.', 1),
        ]);
    }

    public function responseSchema(): array
    {
        return ResponseSchemas::object([
            'rollouts' => ['type' => 'array', 'description' => 'Without rollout: the latest 20.', 'items' => ResponseSchemas::ref('PackageRolloutSummary')],
            'rollout' => ResponseSchemas::ref('PackageRollout'),
            'url' => ['type' => 'string'],
        ], ['url'], 'rollouts, or with rollout that one in full.');
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['rollout' => ['sometimes', 'nullable', 'integer', 'min:1']]);
        $query = PackageRollout::query()->where('workspace_id', $context->workspace->id);

        if (! isset($data['rollout'])) {
            return [
                'rollouts' => $query->with('creator:id,name')->latest('id')->limit(20)->get()
                    ->map(fn (PackageRollout $r) => PackageRollouts::summary($r))->all(),
                'url' => $context->url('/updates?view=rollouts'),
            ];
        }

        $rollout = (clone $query)->whereKey((int) $data['rollout'])->first() ?? throw new ToolError("No rollout #{$data['rollout']} in {$context->workspace->name}. Leave rollout out to list them.");

        return ['rollout' => PackageRollouts::detail($rollout), 'url' => $context->url('/updates?view=rollouts')];
    }
}
