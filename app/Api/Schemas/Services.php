<?php

namespace App\Api\Schemas;

use App\Api\ResponseSchemas;

final class Services
{
    /** @return array<string, mixed> */
    public static function machine(): array
    {
        return ResponseSchemas::object(['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], ['id', 'name']);
    }

    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        $string = ['type' => 'string'];
        $maybeString = ['type' => ['string', 'null']];
        $maybeInteger = ['type' => ['integer', 'null']];
        $named = ResponseSchemas::object(['id' => ['type' => 'integer'], 'name' => $string], ['id', 'name']);

        return [
            'Service' => ResponseSchemas::object([
                'key' => ['type' => 'string', 'description' => 'Use it, with the machine, for the other service tools.'],
                'machine' => self::machine(),
                'name' => ['type' => 'string', 'description' => 'The name given in ServerOS, else the app’s or what was found.'],
                'foundAs' => ['type' => ['string', 'null'], 'description' => 'The name on the machine.'],
                'label' => ['type' => ['string', 'null'], 'description' => 'The name given in ServerOS, if any.'],
                'app' => ['type' => ['string', 'null'], 'description' => 'The app’s name, for an app ServerOS deployed.'],
                'kind' => ['type' => ['string', 'null'], 'description' => 'Like database, cache, web_server or app.'],
                'manager' => ['type' => ['string', 'null'], 'description' => 'Like docker or systemd.'],
                'status' => ['type' => 'string', 'description' => 'Like running, stopped, exited, failed, restarting or unknown.'],
                'managed' => ['type' => 'boolean', 'description' => 'Adopted by ServerOS, so it can be started, stopped and so on.'],
                'container' => ['type' => 'boolean'],
                'image' => $maybeString,
                'ports' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'icon' => ['type' => ['string', 'null'], 'description' => 'Its icon’s address, when it has one.'],
                'stats' => [...ResponseSchemas::object([
                    'cpu' => ['type' => ['number', 'null'], 'description' => 'Percent.'],
                    'memUsed' => ['type' => ['integer', 'null'], 'description' => 'Bytes.'],
                    'memLimit' => ['type' => ['integer', 'null'], 'description' => 'Bytes.'],
                    'pids' => $maybeInteger,
                    'netRx' => ['type' => ['integer', 'null'], 'description' => 'Bytes received.'],
                    'netTx' => ['type' => ['integer', 'null'], 'description' => 'Bytes sent.'],
                    'startedAt' => ['type' => ['integer', 'null'], 'description' => 'Unix time.'],
                    'restarts' => $maybeInteger,
                    'at' => ['type' => ['integer', 'null'], 'description' => 'Unix time of the reading.'],
                ], description: 'The latest usage, when there is any.'), 'type' => ['object', 'null']],
                'url' => ['type' => 'string', 'description' => 'Its page in the dashboard.'],
                'group' => [...$named, 'type' => ['object', 'null'], 'description' => 'Its service group, if any.'],
                'hidden' => ['type' => 'boolean', 'description' => 'Hidden from the panel’s lists.'],
            ], ['key', 'machine', 'name', 'status', 'managed', 'container', 'ports', 'url', 'group', 'hidden'], 'A service found on a machine.'),
            'ServiceRef' => ResponseSchemas::object([
                'key' => $string,
                'name' => $string,
                'machine' => self::machine(),
            ], ['key', 'name', 'machine'], 'The service it acted on.'),
            'ServiceJob' => ResponseSchemas::object([
                'service' => ResponseSchemas::ref('ServiceRef'),
                'job' => ResponseSchemas::ref('Job'),
                'note' => $string,
            ], ['service', 'job', 'note'], 'Work started on a service: follow the job with get_job.'),
            'ServiceSummary' => ResponseSchemas::object([
                'key' => $string,
                'name' => $string,
                'status' => $string,
            ], ['key', 'name', 'status']),
            'ServiceGroup' => ResponseSchemas::object([
                'id' => ['type' => 'integer'],
                'name' => $string,
                'machine' => self::machine(),
                'icon' => ['type' => ['string', 'null'], 'description' => 'Its icon’s address, when it has one.'],
                'services' => ['type' => 'array', 'items' => ResponseSchemas::ref('ServiceSummary'), 'description' => 'The services in it that the latest scan found.'],
                'missing' => ['type' => 'array', 'items' => $string, 'description' => 'Keys it holds that the latest scan didn’t find.'],
                'url' => $string,
            ], ['id', 'name', 'machine', 'icon', 'services', 'missing', 'url'], 'A named set of services on a machine.'),
            'ServiceGroupRef' => ResponseSchemas::object([
                'id' => ['type' => 'integer'],
                'name' => $string,
                'machine' => self::machine(),
            ], ['id', 'name', 'machine']),
            'ServiceGroupMove' => ResponseSchemas::object([
                'id' => ['type' => 'integer'],
                'name' => $string,
                'services' => ['type' => 'array', 'items' => $string, 'description' => 'The keys it lost.'],
            ], ['id', 'name', 'services'], 'Another group that services moved out of.'),
            'ServiceVisibility' => ResponseSchemas::object([
                'machine' => self::machine(),
                'services' => ['type' => 'array', 'items' => ResponseSchemas::object([
                    'key' => $string,
                    'name' => $string,
                    'hidden' => ['type' => 'boolean'],
                ], ['key', 'name', 'hidden'])],
                'changed' => ['type' => 'integer', 'description' => 'How many were not already that way.'],
                'hiddenOnMachine' => ['type' => 'integer', 'description' => 'How many services on the machine are hidden now.'],
            ], ['machine', 'services', 'changed', 'hiddenOnMachine']),
        ];
    }
}
