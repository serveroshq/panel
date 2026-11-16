<?php

namespace App\Api\Schemas;

use App\Api\ResponseSchemas;
use App\Enums\WorkspaceRole;
use App\Mcp\Tools\Monitor\Monitors;
use App\Models\Monitor;
use App\Models\StatusIncident;
use App\Models\StatusPage;
use App\Support\Monitors\AlertKinds;
use App\Support\StatusPages\StatusReport;

final class Monitoring
{
    private const STATUSES = [StatusReport::OPERATIONAL, StatusReport::DEGRADED, StatusReport::DOWN, StatusReport::UNKNOWN];

    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        $string = ['type' => 'string'];
        $maybeString = ['type' => ['string', 'null']];
        $int = ['type' => 'integer'];
        $maybeInt = ['type' => ['integer', 'null']];
        $bool = ['type' => 'boolean'];
        $percent = ['type' => ['number', 'null'], 'description' => 'Percent; null before any check.'];
        $time = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $at = ['type' => 'string', 'format' => 'date-time'];
        $ints = ['type' => 'array', 'items' => $int];

        $monitor = [
            'id' => $int,
            'name' => $string,
            'type' => ['type' => 'string', 'enum' => Monitor::TYPES],
            'target' => ['type' => 'string', 'description' => 'The URL, or host:port.'],
            'status' => ['type' => 'string', 'enum' => Monitors::STATUSES, 'description' => 'pending: no result yet, or one failed check not yet confirmed.'],
            'since' => $time,
            'lastCheckedAt' => $time,
            'responseMs' => $maybeInt,
            'error' => ['type' => ['string', 'null'], 'description' => 'The last check’s error.'],
            'uptime' => ResponseSchemas::object(['last7Days' => $percent, 'last30Days' => $percent], ['last7Days', 'last30Days']),
            'intervalSeconds' => $int,
            'paused' => $bool,
            'alerts' => ResponseSchemas::object([
                'enabled' => $bool,
                'muted' => ['type' => 'boolean', 'description' => 'Held by mute_monitor or its machine’s maintenance.'],
                'mutedUntil' => $time,
                'machineMaintenanceUntil' => $time,
            ], ['enabled', 'muted', 'mutedUntil', 'machineMaintenanceUntil']),
            'certificateExpiresAt' => $time,
            'machine' => [
                ...ResponseSchemas::object(['id' => $int, 'name' => $string, 'url' => $string], ['id', 'name', 'url']),
                'type' => ['object', 'null'],
                'description' => 'The machine it’s linked to.',
            ],
            'url' => ['type' => 'string', 'description' => 'The monitor in the dashboard.'],
        ];

        $addresses = [
            'url' => ['type' => 'string', 'description' => 'The page.'],
            'badgeUrl' => $string,
            'jsonUrl' => $string,
            'feedUrl' => $string,
        ];
        $page = [
            'exists' => ['type' => 'boolean', 'description' => 'false: there’s no page yet and these are the draft settings.'],
            'enabled' => $bool,
            'live' => ['type' => 'boolean', 'description' => 'Switched on, on a plan that serves it.'],
            'public' => $bool,
            'slug' => $string,
            'title' => $maybeString,
            'description' => $maybeString,
            'notice' => $maybeString,
            'noticeKind' => ['type' => ['string', 'null'], 'enum' => [...StatusPage::NOTICE_KINDS, null]],
        ];
        $subscribers = ['type' => 'integer', 'description' => 'People who confirmed email updates.'];
        $status = ['type' => 'string', 'enum' => self::STATUSES];

        return [
            'Monitor' => ResponseSchemas::object($monitor, array_keys($monitor), 'An uptime monitor.'),
            'MonitorDetail' => ResponseSchemas::object([
                ...$monitor,
                'settings' => ResponseSchemas::object([
                    'type' => ['type' => 'string', 'enum' => Monitor::TYPES],
                    'url' => $maybeString,
                    'host' => $maybeString,
                    'port' => $maybeInt,
                    'intervalSeconds' => $int,
                    'timeoutSeconds' => $int,
                    'expectedStatus' => ['type' => ['integer', 'null'], 'description' => 'null: any 2xx or 3xx.'],
                    'keyword' => $maybeString,
                    'machineId' => $maybeInt,
                    'alerts' => $bool,
                ], ['type', 'url', 'host', 'port', 'intervalSeconds', 'timeoutSeconds', 'expectedStatus', 'keyword', 'machineId', 'alerts']),
                'createdAt' => $time,
            ], [...array_keys($monitor), 'settings', 'createdAt'], 'An uptime monitor with its settings.'),
            'MonitorCheck' => ResponseSchemas::object([
                'at' => $at,
                'ok' => $bool,
                'ms' => $maybeInt,
                'statusCode' => $maybeInt,
                'error' => $maybeString,
            ], ['at', 'ok', 'ms', 'statusCode', 'error'], 'One check of a monitor.'),
            'AlertSettings' => ResponseSchemas::object([
                'email' => ResponseSchemas::object([
                    'on' => $bool,
                    'to' => ['type' => 'string', 'description' => 'Who gets alert emails, in words.'],
                    'recipients' => [
                        'type' => ['array', 'null'],
                        'description' => 'Null unless the caller may change the settings.',
                        'items' => ResponseSchemas::object([
                            'name' => $string,
                            'role' => ['type' => 'string', 'enum' => [WorkspaceRole::Owner->value, WorkspaceRole::Admin->value]],
                            'gets' => ['type' => 'string', 'enum' => ['all', 'problems', 'none']],
                        ], ['name', 'role', 'gets']),
                    ],
                ], ['on', 'to', 'recipients']),
                'webhook' => ResponseSchemas::object([
                    'set' => $bool,
                    'host' => ['type' => ['string', 'null'], 'description' => 'Only to people who may change it.'],
                ], ['set', 'host']),
                'events' => ['type' => 'string', 'description' => 'What sends an alert, in words.'],
                'off' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => AlertKinds::keys()], 'description' => 'Kinds of alert not sent.'],
                'kinds' => ['type' => 'array', 'items' => ['type' => 'string', 'enum' => AlertKinds::keys()]],
                'monitors' => ResponseSchemas::object(['total' => $int, 'alertsOff' => $int, 'muted' => $int], ['total', 'alertsOff', 'muted']),
                'canChange' => $bool,
                'url' => $string,
                'note' => ['type' => 'string', 'description' => 'With update_alert_settings.'],
            ], ['email', 'webhook', 'events', 'off', 'kinds', 'monitors', 'canChange', 'url'], 'Where the workspace’s alerts go.'),
            'StatusPageSettings' => ResponseSchemas::object([
                ...$page,
                'includeNew' => ['type' => 'boolean', 'description' => 'Services and monitors found later show by default.'],
                'showUptime' => $bool,
                'subscribers' => $subscribers,
                ...$addresses,
            ], [...array_keys($page), 'includeNew', 'showUptime', 'subscribers', ...array_keys($addresses)], 'A machine’s status page settings.'),
            'FleetStatusPageSettings' => ResponseSchemas::object([
                ...$page,
                'showUptime' => $bool,
                'subscribers' => $subscribers,
                'everyMachine' => ['type' => 'boolean', 'description' => 'Covers every machine, including ones connected later.'],
                'machines' => ['type' => ['array', 'null'], 'items' => $int, 'description' => 'The machine ids it covers; null with everyMachine.'],
                'machineGroups' => ['type' => 'array', 'items' => $int, 'description' => 'Machine group ids whose machines it also covers.'],
                'newMachines' => ['type' => 'string', 'enum' => ['machine', 'services'], 'description' => 'How a machine with no status page of its own shows.'],
                'layout' => [
                    ...ResponseSchemas::object([
                        'sections' => ['type' => 'array', 'items' => ResponseSchemas::object(['name' => $string, 'components' => ['type' => 'array', 'items' => $string]], ['name', 'components'])],
                        'headings' => ['type' => 'object', 'additionalProperties' => $string, 'description' => 'Headings for machines’ sections, keyed by machine id.'],
                        'order' => $ints,
                    ]),
                    'type' => ['object', 'null'],
                ],
                'monitors' => ['type' => 'array', 'items' => $int, 'description' => 'Ids of the monitors on no machine that it shows.'],
                ...$addresses,
            ], [...array_keys($page), 'showUptime', 'subscribers', 'everyMachine', 'machines', 'machineGroups', 'newMachines', 'layout', 'monitors', ...array_keys($addresses)], 'The fleet status page’s settings.'),
            'StatusPageSection' => ResponseSchemas::object([
                'id' => ['type' => 'integer', 'description' => 'The service group.'],
                'name' => $string,
                'services' => ['type' => 'array', 'items' => $string],
                'onPage' => ['type' => 'array', 'items' => $string, 'description' => 'Its services the page shows.'],
                'iconUrl' => $maybeString,
            ], ['id', 'name', 'services', 'onPage', 'iconUrl'], 'A section of a machine’s status page: one of its service groups.'),
            'StatusPagePreview' => ResponseSchemas::object([
                'title' => $string,
                'description' => $maybeString,
                'notice' => [
                    ...ResponseSchemas::object([
                        'text' => $string,
                        'kind' => ['type' => 'string', 'enum' => StatusPage::NOTICE_KINDS],
                        'reason' => $maybeString,
                        'until' => $at,
                    ], ['text', 'kind']),
                    'type' => ['object', 'null'],
                ],
                'overall' => ResponseSchemas::object(['status' => $status, 'label' => $string], ['status', 'label'], 'The headline.'),
                'server' => ['type' => 'string', 'enum' => [StatusReport::OPERATIONAL, StatusReport::UNKNOWN], 'description' => 'Whether the machine is reporting.'],
                'uptimePercent' => ['type' => ['number', 'null']],
                'sectionsFold' => ['type' => 'boolean', 'description' => 'The page is long, so sections that are all operational start folded.'],
                'sections' => ['type' => 'array', 'items' => ResponseSchemas::object([
                    'name' => $maybeString,
                    'status' => $status,
                    'startsFolded' => $bool,
                    'components' => ['type' => 'array', 'items' => ResponseSchemas::object([
                        'key' => $string,
                        'label' => $string,
                        'status' => $status,
                        'uptimePercent' => ['type' => 'number'],
                        'responseMs' => $int,
                        'uptimeDays' => ['type' => 'array', 'items' => ['type' => ['number', 'null']], 'description' => 'With include_days: percent per day, oldest first.'],
                    ], ['key', 'label', 'status'])],
                ], ['name', 'status', 'startsFolded', 'components'])],
                'incidents' => ['type' => ['array', 'null'], 'description' => 'Monitor outages in the last two weeks; null when it shows no monitors.', 'items' => ResponseSchemas::object([
                    'label' => $string,
                    'startedAt' => $at,
                    'resolvedAt' => $time,
                    'seconds' => $int,
                ], ['label', 'startedAt', 'resolvedAt', 'seconds'])],
                'updatedAt' => $time,
                'checkedAt' => $time,
                'days' => ['type' => 'array', 'items' => ['type' => 'string', 'format' => 'date'], 'description' => 'With include_days: the days uptimeDays covers.'],
            ], ['title', 'description', 'notice', 'overall', 'server', 'uptimePercent', 'sectionsFold', 'sections', 'incidents', 'updatedAt', 'checkedAt'], 'What a status page shows visitors right now.'),
            'StatusIncident' => ResponseSchemas::object([
                'id' => $int,
                'title' => $string,
                'impact' => ['type' => 'string', 'enum' => StatusIncident::IMPACTS],
                'state' => ['type' => 'string', 'enum' => StatusIncident::STATES],
                'machine' => ['type' => ['string', 'null'], 'description' => 'The machine’s name; null for the workspace page only.'],
                'machineId' => $maybeInt,
                'startedAt' => $at,
                'resolvedAt' => $time,
                'updates' => ['type' => 'array', 'description' => 'Newest first.', 'items' => ResponseSchemas::object([
                    'state' => ['type' => 'string', 'enum' => StatusIncident::STATES],
                    'message' => $string,
                    'at' => $at,
                ], ['state', 'message', 'at'])],
            ], ['id', 'title', 'impact', 'state', 'machine', 'machineId', 'startedAt', 'resolvedAt', 'updates'], 'An incident written for the status pages.'),
        ];
    }
}
