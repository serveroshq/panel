<?php

namespace App\Mcp\Tools\Workflow;

use App\Api\ResponseSchemas;
use App\Api\Schemas\Workflows as WorkflowSchemas;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\Workflows\WorkflowInputs;
use App\Support\Workflows\WorkflowSteps;

final class GetWorkflowReference extends Tool
{
    public function name(): string
    {
        return 'get_workflow_reference';
    }

    public function title(): string
    {
        return 'How workflows are built';
    }

    public function description(): string
    {
        return 'Read this before writing a graph for update_workflow. Returns the graph format (nodes, edges, ports, how a run follows them), every trigger event with its filter rules and the data it carries, every step kind with its ports and rules (type, required, allowed values, defaults, and where “@trigger” or “@all” may be used), the If step’s conditions, the {{placeholders}} alerts, requests and commands can use, the limits, and a small complete example graph. '
            .'`choices` (unless include_choices is false) lists what this workspace’s steps can point at, as the editor’s menus do: machines (id, name, services with key, name, managed and container, and the apps deployed there by name) monitors (id, name), and projects (id, name, environments with id, name and source: the environment each was copied from).';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'include_choices' => Schema::boolean('Include this workspace’s machines, services, deployed apps and monitors (default true).'),
        ]);
    }

    public function responseSchema(): array
    {
        $string = ['type' => 'string'];
        $integer = ['type' => 'integer'];
        $strings = ['type' => 'array', 'items' => $string];
        $words = ['type' => 'object', 'additionalProperties' => $string];
        $rules = ['type' => 'object', 'description' => 'Each rule, by its name.', 'additionalProperties' => ResponseSchemas::object([
            'type' => ['type' => 'string', 'description' => 'Like string, integer, boolean or string|integer.'],
            'required' => ['type' => 'boolean'],
            'values' => ['type' => 'array', 'items' => ['type' => ['string', 'integer']], 'description' => 'The values it allows.'],
            'default' => ['description' => 'What it is when left out.'],
            'min' => $integer,
            'max' => $integer,
            'description' => $string,
        ], ['type', 'required', 'description'])];
        $kind = ['type' => 'string', 'enum' => array_keys(WorkflowSteps::PORTS)];
        $named = WorkflowSchemas::named();

        return ResponseSchemas::object([
            'graph' => ResponseSchemas::object([
                'shape' => $string,
                'node' => [...$words, 'description' => 'Each field of a node, with what it means.'],
                'edge' => [...$words, 'description' => 'Each field of an edge, with what it means.'],
                'howRunsGo' => $strings,
            ], ['shape', 'node', 'edge', 'howRunsGo'], 'The graph format update_workflow takes.'),
            'events' => ['type' => 'array', 'description' => 'Every trigger event.', 'items' => ResponseSchemas::object([
                'event' => ['type' => 'string', 'enum' => WorkflowSteps::EVENTS],
                'label' => $string,
                'description' => $string,
                'filters' => [...$rules, 'description' => 'The trigger’s rules for this event, by name.'],
                'carries' => [...$strings, 'description' => 'The data a run started by it has.'],
                'machineFromTrigger' => ['type' => 'boolean', 'description' => 'Steps can use “@trigger” for the machine.'],
                'serviceFromTrigger' => ['type' => ['string', 'null'], 'enum' => ['key', 'deploy', null], 'description' => 'Steps can use “@trigger” for the service, by key or by deploy name.'],
            ], ['event', 'label', 'description', 'filters', 'carries', 'machineFromTrigger', 'serviceFromTrigger'])],
            'kinds' => ['type' => 'array', 'description' => 'Every step kind.', 'items' => ResponseSchemas::object([
                'kind' => $kind,
                'label' => $string,
                'does' => $string,
                'ports' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => WorkflowSchemas::PORTS]],
                'rules' => $rules,
                'note' => ['type' => ['string', 'null']],
            ], ['kind', 'label', 'does', 'ports', 'rules', 'note'])],
            'conditions' => ['type' => 'array', 'description' => 'What an If step can check.', 'items' => ResponseSchemas::object([
                'condition' => ['type' => 'string', 'enum' => WorkflowSteps::CONDITIONS],
                'label' => $string,
                'rules' => $rules,
                'true' => ['type' => 'string', 'description' => 'When it leaves by true.'],
            ], ['condition', 'label', 'rules', 'true'])],
            'specialValues' => [...$words, 'description' => 'Values like “@trigger” and “@all”, with where they can be used.'],
            'placeholders' => ResponseSchemas::object([
                'values' => [...$words, 'description' => 'Each placeholder, with what it becomes.'],
                'usedIn' => $string,
            ], ['values', 'usedIn']),
            'limits' => ResponseSchemas::object([
                'nodes' => $integer,
                'edges' => $integer,
                'stepsPerRun' => $integer,
                'workflowsPerWorkspace' => $integer,
                'runsPerHour' => $integer,
                'secondsBetweenEventRuns' => $integer,
                'serverJobMinutes' => $integer,
                'alertEmails' => $integer,
                'runsKeptDays' => $integer,
                'note' => $string,
            ], ['nodes', 'edges', 'stepsPerRun', 'workflowsPerWorkspace', 'runsPerHour', 'secondsBetweenEventRuns', 'serverJobMinutes', 'alertEmails', 'runsKeptDays', 'note']),
            'turningOn' => $string,
            'example' => ResponseSchemas::object([
                'nodes' => ['type' => 'array', 'items' => ResponseSchemas::object([
                    'id' => $string,
                    'kind' => $kind,
                    'title' => $string,
                    'rules' => ['type' => 'object', 'additionalProperties' => ['type' => ['string', 'number', 'boolean', 'null']]],
                ], ['id', 'kind', 'title', 'rules'])],
                'edges' => ['type' => 'array', 'items' => ResponseSchemas::object([
                    'source' => $string,
                    'port' => ['type' => 'string', 'enum' => WorkflowSchemas::PORTS],
                    'target' => $string,
                ], ['source', 'port', 'target'])],
            ], ['nodes', 'edges'], 'A small complete graph.'),
            'choices' => [...ResponseSchemas::object([
                'machines' => ['type' => 'array', 'items' => ResponseSchemas::object([
                    'id' => $integer,
                    'name' => $string,
                    'services' => ['type' => 'array', 'items' => ResponseSchemas::object([
                        'key' => $string,
                        'name' => $string,
                        'managed' => ['type' => 'boolean'],
                        'container' => ['type' => 'boolean'],
                    ], ['key', 'name', 'managed', 'container'])],
                    'deploys' => [...$strings, 'description' => 'The apps deployed there, by deploy name.'],
                ], ['id', 'name', 'services', 'deploys'])],
                'monitors' => ['type' => 'array', 'items' => $named],
                'projects' => ['type' => 'array', 'items' => ResponseSchemas::object([
                    'id' => $integer,
                    'name' => $string,
                    'environments' => ['type' => 'array', 'items' => ResponseSchemas::object([
                        'id' => $integer,
                        'name' => $string,
                        'source' => ['type' => ['integer', 'null'], 'description' => 'The id of the environment it was copied from.'],
                    ], ['id', 'name', 'source'])],
                ], ['id', 'name', 'environments'])],
            ], ['machines', 'monitors', 'projects']), 'type' => ['object', 'null'], 'description' => 'What this workspace’s steps can point at; null with include_choices false.'],
        ], ['graph', 'events', 'kinds', 'conditions', 'specialValues', 'placeholders', 'limits', 'turningOn', 'example', 'choices']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['include_choices' => ['sometimes', 'boolean']]);

        return [
            ...WorkflowReference::all(),
            'choices' => ($data['include_choices'] ?? true) ? WorkflowInputs::options($context->workspace) : null,
        ];
    }
}
