<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\Machine;
use App\Support\Machines\MachineSelector;

final class ResolveSelector extends Tool
{
    public function name(): string
    {
        return 'resolve_selector';
    }

    public function title(): string
    {
        return 'See which machines a selector picks';
    }

    public function description(): string
    {
        return 'Returns the machines a selector matches right now, so you can check what a change across machines would touch before making it. '
            .'A selector picks machines by groups, labels, provider, region, tier and online; every field given must match, and a list of groups or of one label’s values matches any of them. Example: {"labels": {"env": "prod"}, "online": true}. '
            .'machines (ids or names) works instead of a selector, but not with one. A selector that matches nothing is refused, never read as every machine.';
    }

    public function inputSchema(): array
    {
        return Schema::object(MachineSelector::arguments());
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $groups = $context->workspace->machineGroups()->get()->keyBy('id');
        $machines = MachineSelector::pick($context, $arguments);

        return [
            'count' => $machines->count(),
            'machines' => $machines->map(fn (Machine $m) => MachineView::row($m, $context, $m->machine_group_id === null ? null : $groups->get($m->machine_group_id)))->all(),
        ];
    }
}
