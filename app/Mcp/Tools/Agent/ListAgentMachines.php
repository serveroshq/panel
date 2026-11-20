<?php

namespace App\Mcp\Tools\Agent;

use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Support\Agents\AgentTasks;
use App\Support\Extensions\AgentHosting;
use App\Support\Workflows\AgentSpec;

final class ListAgentMachines extends Tool
{
    public function __construct(private readonly AgentHosting $hosting) {}

    public function enabled(): bool
    {
        return Agents::enabled();
    }

    public function name(): string
    {
        return 'list_agent_machines';
    }

    public function title(): string
    {
        return 'Machines that can run agents';
    }

    public function description(): string
    {
        return 'Lists the workspace’s machines with the containers agents can run in, like the machine picker on the Agents page. '
            .'Each machine says whether it’s online, how many agents fit on it at once (slots, from its memory: about '.AgentTasks::GB_PER_AGENT.' GB each, at most '.AgentTasks::MAX_SLOTS.') and how many are working there now. '
            .'Containers marked agent are a Coding agent container (or named like one): start_agents picks the first of those when you don’t name one. A machine with none needs a Coding agent container first: the person can add one from the Agents page. '
            .'Also lists the agents you can choose: '.implode(', ', array_map(fn ($k, $v) => "{$k} ({$v})", array_keys(AgentSpec::AGENTS), AgentSpec::AGENTS)).'.';
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $machines = AgentTasks::machines($context->workspace);

        return [
            'machines' => array_map(fn (array $m) => [
                ...$m,
                'free' => max(0, $m['slots'] - $m['running']),
                'url' => $context->url("/machines/{$m['id']}"),
            ], $machines),
            'agents' => AgentSpec::AGENTS,
            // ServerOS's own machines, when offered: start_agents with on_serveros.
            'serverOS' => $this->hosting->offer($context->workspace, $context->user),
            'planAllows' => $context->workspace->hasAutomations(),
            'note' => collect($machines)->contains(fn (array $m) => collect($m['containers'])->contains('agent', true))
                ? null
                : 'No machine has a Coding agent container yet. The person can add one from the Agents page ('.$context->url('/agents').').',
        ];
    }
}
