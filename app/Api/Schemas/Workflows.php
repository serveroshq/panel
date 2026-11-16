<?php

namespace App\Api\Schemas;

use App\Api\ResponseSchemas;
use App\Models\ProjectNode;
use App\Support\Workflows\WorkflowSteps;

final class Workflows
{
    public const RUN_STATES = ['running', 'succeeded', 'failed', 'cancelled'];

    public const STEP_STATES = ['pending', 'running', 'waiting', 'succeeded', 'failed', 'cancelled'];

    public const PORTS = ['out', 'failed', 'true', 'false'];

    /** @return array<string, mixed> */
    public static function named(): array
    {
        return ResponseSchemas::object(['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], ['id', 'name']);
    }

    /**
     * A workflow run; with $full the steps, logs and trigger data too.
     *
     * @param  array<string, array<string, mixed>>  $extra
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    public static function run(array $extra = [], bool $full = false, array $required = []): array
    {
        $string = ['type' => 'string'];
        $time = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $base = ['id', 'workflowId', 'state', 'finished', 'trigger', 'user', 'startedAt', 'finishedAt', 'seconds', 'failedBecause', 'url'];

        return ResponseSchemas::object([
            'id' => ['type' => 'integer'],
            'workflowId' => ['type' => 'integer'],
            'state' => ['type' => 'string', 'enum' => self::RUN_STATES],
            'finished' => ['type' => 'boolean'],
            'trigger' => ResponseSchemas::object([
                'kind' => ['type' => 'string', 'enum' => WorkflowSteps::EVENTS],
                'summary' => $string,
                'data' => ['type' => 'object', 'description' => 'What the trigger carried, like a webhook’s JSON.'],
            ], $full ? ['kind', 'summary', 'data'] : ['kind', 'summary']),
            'user' => ['type' => ['string', 'null'], 'description' => 'Who started it, if a person did.'],
            'startedAt' => ['type' => 'string', 'format' => 'date-time'],
            'finishedAt' => $time,
            'seconds' => ['type' => 'integer', 'description' => 'How long it ran, or has run so far.'],
            'failedBecause' => ['type' => ['string', 'null'], 'description' => 'The message of the step that failed the run.'],
            'url' => ['type' => 'string', 'description' => 'The run in the dashboard.'],
            'steps' => ['type' => 'array', 'description' => 'Each step the run reached.', 'items' => ResponseSchemas::object([
                'id' => $string,
                'kind' => ['type' => 'string', 'enum' => array_keys(WorkflowSteps::PORTS)],
                'title' => $string,
                'state' => ['type' => 'string', 'enum' => self::STEP_STATES],
                'message' => $string,
                'leftBy' => ['type' => 'string', 'enum' => self::PORTS, 'description' => 'The port it left by.'],
                'unhandled' => ['type' => 'boolean', 'description' => 'It failed with nothing on its failed port, so the run failed.'],
                'jobs' => ['type' => 'array', 'items' => $string, 'description' => 'The machine jobs it started (get_job).'],
                'until' => ['type' => 'string', 'format' => 'date-time', 'description' => 'While waiting: when it picks up again.'],
                'startedAt' => ['type' => 'string', 'format' => 'date-time'],
                'finishedAt' => ['type' => 'string', 'format' => 'date-time'],
            ], ['id', 'title', 'state'])],
            'notReached' => ['type' => 'array', 'items' => $string, 'description' => 'The ids of steps the run never reached.'],
            'logs' => ['type' => 'array', 'description' => 'The latest log lines.', 'items' => ResponseSchemas::object([
                'at' => ['type' => 'string', 'format' => 'date-time'],
                'step' => ['type' => ['string', 'null'], 'description' => 'The step it is about; null for the run.'],
                'level' => ['type' => 'string', 'enum' => ['info', 'success', 'warning', 'error']],
                'message' => $string,
            ], ['at', 'step', 'level', 'message'])],
            'logLines' => ['type' => 'integer', 'description' => 'How many log lines there are in all.'],
            ...$extra,
        ], [...$base, ...($full ? ['steps', 'notReached', 'logs', 'logLines'] : []), ...$required], 'A run of a workflow. steps, notReached, logs and logLines come with the full run.');
    }

    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        $string = ['type' => 'string'];
        $maybeString = ['type' => ['string', 'null']];
        $time = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $kind = ['type' => 'string', 'enum' => array_keys(WorkflowSteps::PORTS)];
        $ports = ResponseSchemas::object(array_fill_keys(self::PORTS, ['type' => 'array', 'items' => $string]));

        return [
            'WorkflowRun' => self::run(),
            'WorkflowRunFull' => self::run(full: true),
            'Workflow' => ResponseSchemas::object([
                'id' => ['type' => 'integer'],
                'name' => $string,
                'description' => $maybeString,
                'enabled' => ['type' => 'boolean'],
                'trigger' => ResponseSchemas::object([
                    'event' => ['type' => 'string', 'description' => 'What starts it, like manual, schedule or service.down.'],
                    'label' => $string,
                    'words' => ['type' => 'string', 'description' => 'The trigger in words.'],
                ], ['event', 'label', 'words']),
                'webhook' => [...ResponseSchemas::object([
                    'hasUrl' => ['type' => 'boolean'],
                    'note' => $string,
                ], ['hasUrl', 'note']), 'type' => ['object', 'null'], 'description' => 'With a webhook trigger. The URL itself is never shown.'],
                'runAs' => ['type' => ['string', 'null'], 'description' => 'Whose permissions its machine work uses: whoever last saved it.'],
                'nextRunAt' => [...$time, 'description' => 'When a schedule runs it next, while it is on.'],
                'lastRunAt' => $time,
                'updatedAt' => $time,
                'graph' => ResponseSchemas::object([
                    'nodes' => ['type' => 'array', 'items' => ResponseSchemas::object([
                        'id' => $string,
                        'kind' => $kind,
                        'title' => $string,
                        'description' => $string,
                        'rules' => ['type' => 'object', 'description' => 'The step’s settings, by kind (get_workflow_reference).', 'additionalProperties' => ['type' => ['string', 'number', 'boolean', 'null']]],
                        'x' => ['type' => 'integer'],
                        'y' => ['type' => 'integer'],
                    ], ['id', 'kind', 'title', 'description', 'rules', 'x', 'y'])],
                    'edges' => ['type' => 'array', 'items' => ResponseSchemas::object([
                        'id' => $string,
                        'source' => $string,
                        'port' => ['type' => 'string', 'enum' => self::PORTS],
                        'target' => $string,
                    ], ['id', 'source', 'port', 'target'])],
                ], ['nodes', 'edges'], 'The steps and how they connect, as update_workflow takes them.'),
                'steps' => ['type' => 'array', 'description' => 'The steps in the order a run reaches them, in words.', 'items' => ResponseSchemas::object([
                    'id' => $string,
                    'kind' => $kind,
                    'label' => $string,
                    'title' => $string,
                    'does' => ['type' => 'string', 'description' => 'What it does, in words.'],
                    'next' => [...$ports, 'description' => 'The steps each of its ports leads to.'],
                    'problem' => ['type' => 'string', 'description' => 'What stops it from running.'],
                    'connected' => ['type' => 'boolean', 'description' => 'False when nothing leads to it.'],
                ], ['id', 'kind', 'label', 'title', 'does', 'next'])],
                'problems' => ['type' => 'array', 'description' => 'What must be fixed before it can be turned on or run.', 'items' => ResponseSchemas::object([
                    'step' => $maybeString,
                    'title' => $maybeString,
                    'problem' => $string,
                ], ['step', 'title', 'problem'])],
                'hints' => ['type' => 'array', 'description' => 'Things that look wrong but don’t stop it.', 'items' => ResponseSchemas::object([
                    'step' => $string,
                    'hint' => $string,
                ], ['step', 'hint'])],
                'canTurnOn' => ['type' => 'boolean'],
                'planAllows' => ['type' => 'boolean', 'description' => 'The workspace’s plan includes workflows.'],
                'recentRuns' => ['type' => 'array', 'items' => ResponseSchemas::ref('WorkflowRun')],
                'url' => ['type' => 'string', 'description' => 'The workflow in the dashboard.'],
            ], ['id', 'name', 'description', 'enabled', 'trigger', 'webhook', 'runAs', 'nextRunAt', 'lastRunAt', 'updatedAt', 'graph', 'steps', 'problems', 'hints', 'canTurnOn', 'planAllows', 'recentRuns', 'url'], 'A workflow: a trigger and the steps that follow it.'),
            'Project' => ResponseSchemas::object([
                'id' => ['type' => 'string', 'description' => 'The end of its address, like bdvrhz14sn.'],
                'name' => $string,
                'url' => ['type' => 'string', 'description' => 'The project in the dashboard.'],
                'environments' => ['type' => 'array', 'items' => self::named()],
                'parts' => ['type' => 'array', 'description' => 'Its apps and resources.', 'items' => ResponseSchemas::object([
                    'id' => ['type' => 'integer'],
                    'name' => $string,
                    'kind' => ['type' => 'string', 'enum' => ProjectNode::KINDS],
                    'template' => $maybeString,
                    'environment' => ['type' => ['integer', 'null'], 'description' => 'The id of its environment.'],
                    'machine' => self::named(),
                    'status' => ['type' => ['string', 'null'], 'enum' => ['running', 'stopped', null], 'description' => 'Null when it isn’t found on the machine.'],
                ], ['id', 'name', 'kind', 'template', 'environment', 'machine', 'status'])],
                'connections' => ['type' => 'array', 'description' => 'Apps connected to resources.', 'items' => ResponseSchemas::object([
                    'id' => ['type' => 'integer'],
                    'app' => $string,
                    'resource' => $string,
                    'state' => ['type' => 'string', 'enum' => ['pending', 'applied', 'failed']],
                    'error' => $maybeString,
                    'settings' => ['type' => 'array', 'items' => $string, 'description' => 'The names of the settings it gave the app.'],
                ], ['id', 'app', 'resource', 'state', 'error', 'settings'])],
                'network' => ['type' => 'array', 'description' => 'Each machine on the project’s private network.', 'items' => ResponseSchemas::object([
                    'machine' => $string,
                    'state' => ['type' => 'string', 'enum' => ['pending', 'up', 'failed']],
                    'error' => $maybeString,
                ], ['machine', 'state', 'error'])],
            ], ['id', 'name', 'url', 'environments', 'parts', 'connections', 'network'], 'Apps and the resources they use, across environments.'),
        ];
    }
}
