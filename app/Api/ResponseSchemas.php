<?php

namespace App\Api;

use App\Models\Operation;
use App\Models\OperationMachine;
use App\Support\Webhooks\Webhooks;

/**
 * What the API answers under `data`, for the tools that describe it
 * (Tool::responseSchema()). Shared shapes live under components/schemas.
 * tests/Feature/ApiResponseSchemasTest.php checks real answers against them.
 */
final class ResponseSchemas
{
    /** @return array<string, mixed> a reference to a shared shape */
    public static function ref(string $name): array
    {
        return ['$ref' => "#/components/schemas/{$name}"];
    }

    /**
     * An object; every property listed is optional unless named in $required.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    public static function object(array $properties, array $required = [], ?string $description = null): array
    {
        return array_filter([
            'type' => 'object',
            'description' => $description,
            'required' => $required === [] ? null : $required,
            'properties' => $properties,
        ], fn ($v) => $v !== null);
    }

    /**
     * A page of a list, with the cursor for the next.
     *
     * @param  array<string, mixed>  $items
     * @return array<string, mixed>
     */
    public static function page(string $key, array $items): array
    {
        return self::object([
            'total' => ['type' => 'integer'],
            'offset' => ['type' => 'integer', 'deprecated' => true],
            'more' => ['type' => 'boolean'],
            'next_cursor' => ['type' => ['string', 'null'], 'description' => 'Pass as cursor for the next page; null on the last.'],
            $key => ['type' => 'array', 'items' => $items],
        ], ['total', 'next_cursor', $key]);
    }

    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        $string = ['type' => 'string'];
        $maybeString = ['type' => ['string', 'null']];
        $maybeNumber = ['type' => ['number', 'null']];
        $time = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $named = self::object(['id' => ['type' => 'integer'], 'name' => $string], ['id', 'name']);

        return [
            'Job' => self::object([
                'id' => $string,
                'kind' => $string,
                'state' => ['type' => 'string', 'enum' => ['queued', 'running', 'succeeded', 'failed', 'cancelled', 'timed_out', 'refused']],
                'finished' => ['type' => 'boolean'],
                'machineId' => ['type' => 'integer'],
                'service' => $maybeString,
                'phase' => $maybeString,
                'progress' => $maybeNumber,
                'log' => ['type' => 'array', 'items' => $string, 'description' => 'The latest log lines.'],
                'logLines' => ['type' => 'integer', 'description' => 'How many log lines there are in all.'],
                'result' => ['description' => 'What the job produced, like a command’s exit_code.'],
                'error' => ['type' => ['object', 'null'], 'properties' => ['message' => $string]],
                'createdAt' => $time,
                'finishedAt' => $time,
                'action' => $maybeString,
                'command' => $maybeString,
                'machine' => $named,
                'startedBy' => $maybeString,
                'stale' => ['type' => 'boolean'],
                'retryable' => ['type' => 'boolean'],
            ], ['id', 'kind', 'state', 'finished', 'machineId', 'log', 'logLines'], 'Work on a machine.'),
            'Machine' => self::object([
                'id' => ['type' => 'integer'],
                'name' => $string,
                'environment' => ['type' => 'string', 'enum' => ['production', 'staging', 'development']],
                'status' => ['type' => 'string', 'enum' => ['online', 'offline', 'connecting', 'demo']],
                'ip' => $maybeString,
                'provider' => $maybeString,
                'os' => $maybeString,
                'cpuPercent' => $maybeNumber,
                'memoryUsedMb' => $maybeNumber,
                'diskPercent' => $maybeNumber,
                'services' => ['type' => 'integer'],
                'groupId' => ['type' => ['integer', 'null']],
                'group' => ['type' => ['object', 'null'], 'properties' => ['id' => ['type' => 'integer'], 'name' => $string]],
                'region' => $maybeString,
                'tier' => $maybeString,
                'labels' => ['type' => 'object', 'additionalProperties' => $string],
                'maintenance' => ['type' => ['object', 'null'], 'properties' => ['until' => $string, 'reason' => $maybeString]],
                'lastSeen' => $time,
                'daemonVersion' => $maybeString,
                'url' => $string,
            ], ['id', 'name', 'environment', 'status'], 'A machine running the ServerOS daemon.'),
            'Operation' => self::object([
                'id' => ['type' => 'integer'],
                'kind' => ['type' => 'string', 'enum' => Operation::KINDS],
                'state' => ['type' => 'string', 'enum' => ['running', 'paused', 'finished', 'cancelled']],
                'pausedReason' => $maybeString,
                'selector' => ['type' => ['object', 'null']],
                'strategy' => self::object([
                    'batchSize' => ['type' => ['string', 'null'], 'description' => 'As given: a number or a percentage.'],
                    'machinesPerBatch' => ['type' => 'integer'],
                    'stopOnFailure' => ['type' => 'boolean'],
                    'maxFailures' => ['type' => 'integer'],
                    'waitHealthySeconds' => ['type' => 'integer'],
                    'pauseBetweenSeconds' => ['type' => 'integer'],
                ], ['machinesPerBatch']),
                'progress' => self::object(
                    ['total' => ['type' => 'integer'], ...array_fill_keys(OperationMachine::STATES, ['type' => 'integer'])],
                    ['total', ...OperationMachine::STATES],
                ),
                'startedBy' => $maybeString,
                'via' => $string,
                'createdAt' => $time,
                'finishedAt' => $time,
                'machines' => ['type' => 'array', 'description' => 'Each machine, with get_operation.', 'items' => self::object([
                    'machine' => self::object(['id' => ['type' => ['integer', 'null']], 'name' => $string], ['name']),
                    'batch' => ['type' => 'integer'],
                    'state' => ['type' => 'string', 'enum' => OperationMachine::STATES],
                    'jobId' => $maybeString,
                    'outcome' => $maybeString,
                    'startedAt' => $time,
                    'finishedAt' => $time,
                ], ['machine', 'batch', 'state'])],
            ], ['id', 'kind', 'state', 'strategy', 'progress'], 'Work across many machines. Its kind’s own settings are under a key named after the kind.'),
            'Webhook' => self::object([
                'id' => ['type' => 'integer'],
                'url' => ['type' => 'string', 'description' => 'Without its query string.'],
                'description' => $maybeString,
                'events' => ['description' => '"all", or a list of events.', 'type' => ['string', 'array'], 'items' => ['type' => 'string', 'enum' => Webhooks::EVENTS]],
                'enabled' => ['type' => 'boolean'],
                'disabledReason' => $maybeString,
                'createdAt' => $time,
                'lastDelivery' => ['type' => ['object', 'null']],
            ], ['id', 'url', 'events', 'enabled'], 'Where signed events go.'),
            'WebhookDelivery' => self::object([
                'id' => ['type' => 'integer'],
                'eventId' => $string,
                'event' => $string,
                'state' => ['type' => 'string', 'enum' => ['pending', 'delivered', 'failed']],
                'attempts' => ['type' => 'integer'],
                'responseStatus' => ['type' => ['integer', 'null']],
                'error' => $maybeString,
                'createdAt' => $time,
                'deliveredAt' => $time,
                'nextAttemptAt' => $time,
            ], ['id', 'eventId', 'event', 'state', 'attempts'], 'One event sent to one endpoint.'),
        ];
    }
}
