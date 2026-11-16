<?php

namespace App\Api\Schemas;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Models\MachinePolicy;
use App\Models\MaintenanceWindow;
use App\Models\PackageRollout;

final class Machines
{
    private const POLICY_REQUIRED = ['id', 'name', 'default', 'discovery', 'logs', 'logIpMasking', 'securityWatch', 'errorReports', 'statusPageNewServices', 'updates'];

    /** @var list<string> */
    public const JOB_STATES = ['queued', 'running', ...McpContext::FINISHED, 'stale'];

    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        $string = ['type' => 'string'];
        $maybeString = ['type' => ['string', 'null']];
        $int = ['type' => 'integer'];
        $maybeInt = ['type' => ['integer', 'null']];
        $maybeNumber = ['type' => ['number', 'null']];
        $bool = ['type' => 'boolean'];
        $time = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $ref = ResponseSchemas::ref('MachineRef');
        $error = ['type' => ['object', 'null'], 'properties' => ['code' => $string, 'message' => $string]];
        $package = ResponseSchemas::object(['name' => $string, 'version' => $maybeString], ['name', 'version']);

        $rollout = [
            'id' => $int,
            'name' => $string,
            'state' => $string,
            'mode' => ['type' => 'string', 'enum' => PackageRollout::MODES],
            'everything' => ['type' => 'boolean', 'description' => 'It installs every waiting update.'],
            'packages' => ['type' => 'array', 'items' => $package],
            'allowDowngrade' => $bool,
            'batchSize' => ['type' => ['integer', 'null'], 'description' => 'Machines started at a time; null for all at once.'],
            'stopOnFailure' => $bool,
            'reboot' => ['type' => 'string', 'enum' => PackageRollout::REBOOTS],
            'scheduledFor' => $time,
            'startedAt' => $time,
            'finishedAt' => $time,
            'createdAt' => $time,
            'by' => $maybeString,
            'total' => $int,
            'finished' => $int,
            'done' => $int,
            'failed' => $int,
            'skipped' => $int,
            'running' => $int,
        ];

        return [
            'MachineRef' => ResponseSchemas::object(['id' => $int, 'name' => $string], ['id', 'name']),
            'MachineGroup' => ResponseSchemas::object([
                'id' => $int,
                'name' => $string,
                'policy' => ['type' => ['string', 'null'], 'description' => 'The policy its machines follow: its name, or "default".'],
                'machineCount' => $int,
                'machines' => ['type' => 'array', 'items' => ResponseSchemas::object([
                    'id' => $int,
                    'name' => $string,
                    'environment' => ['type' => 'string', 'enum' => ['production', 'staging', 'development']],
                    'status' => ['type' => 'string', 'enum' => ['online', 'offline', 'connecting', 'demo']],
                ], ['id', 'name', 'environment', 'status'])],
            ], ['id', 'name', 'policy', 'machineCount', 'machines'], 'A named set of machines.'),
            'MachineGroupMove' => ResponseSchemas::object([
                'id' => $int,
                'name' => $string,
                'fromGroup' => ['type' => ['string', 'null'], 'description' => 'The group it was in before.'],
            ], ['id', 'name', 'fromGroup'], 'A machine moved here from another group.'),
            'MachinePolicy' => ResponseSchemas::object(self::policy(), self::POLICY_REQUIRED, 'What the daemon on a machine does.'),
            'MachineUpdatesJob' => ResponseSchemas::object([
                'machine' => $ref,
                'jobId' => $string,
                'kind' => $string,
                'check' => ['type' => 'boolean', 'description' => 'It only checks for updates, without installing them.'],
                'state' => ['type' => 'string', 'enum' => self::JOB_STATES],
                'finished' => $bool,
                'phase' => $maybeString,
                'log' => ['type' => 'array', 'items' => $string],
                'logLines' => $int,
                'error' => $error,
                'canPick' => ['type' => 'boolean', 'description' => 'The daemon can install chosen packages, not only every update.'],
                'report' => ['type' => ['object', 'null'], 'description' => 'What it found or installed, once it has.', 'required' => ['manager', 'total', 'securityCount', 'packages', 'rebootRequired', 'applied', 'held'], 'properties' => [
                    'manager' => $maybeString,
                    'total' => $int,
                    'securityCount' => $int,
                    'packages' => ['type' => 'array', 'description' => 'Up to 500.', 'items' => ResponseSchemas::object([
                        'name' => $string,
                        'from' => $maybeString,
                        'to' => $maybeString,
                        'security' => $bool,
                    ], ['name', 'from', 'to', 'security'])],
                    'rebootRequired' => $bool,
                    'applied' => ['type' => 'boolean', 'description' => 'The updates were installed.'],
                    'held' => ['type' => 'array', 'items' => $string],
                ]],
                'createdAt' => $time,
                'finishedAt' => $time,
            ], ['machine', 'jobId', 'kind', 'check', 'state', 'finished', 'log', 'logLines', 'canPick', 'report'], 'A check for, or install of, OS package updates.'),
            'MachinePortChange' => ResponseSchemas::object([
                'machine' => $ref,
                'port' => ['type' => 'string', 'description' => 'Like 443/tcp.'],
                'job' => ResponseSchemas::ref('Job'),
                'note' => $string,
            ], ['machine', 'port', 'job', 'note'], 'A port being opened or closed in the firewall.'),
            'PlannedMaintenance' => ResponseSchemas::object([
                'id' => $int,
                'startsAt' => ['type' => 'string', 'format' => 'date-time'],
                'endsAt' => ['type' => 'string', 'format' => 'date-time'],
                'reason' => $maybeString,
                'state' => ['type' => 'string', 'enum' => ['scheduled', 'active', 'done', 'cancelled']],
                'action' => ['type' => 'string', 'enum' => MaintenanceWindow::ACTIONS],
                'public' => $bool,
                'phase' => $maybeString,
                'outcome' => $maybeString,
                'by' => ['type' => ['string', 'null'], 'description' => 'Who planned it.'],
            ], ['id', 'startsAt', 'endsAt', 'reason', 'state', 'action', 'public', 'phase', 'outcome', 'by'], 'Maintenance planned for later.'),
            'PackageRollout' => ResponseSchemas::object([
                ...$rollout,
                'machines' => ['type' => 'array', 'items' => ResponseSchemas::object([
                    'machineId' => $int,
                    'name' => $string,
                    'provider' => $maybeString,
                    'state' => $string,
                    'outcome' => $maybeString,
                    'installed' => ['type' => 'array', 'items' => $package],
                    'jobId' => $maybeString,
                    'rebootJobId' => $maybeString,
                    'startedAt' => $time,
                    'finishedAt' => $time,
                ], ['machineId', 'name', 'state', 'installed'])],
            ], array_keys([...$rollout, 'machines' => true]), 'OS packages installed across machines, a batch at a time.'),
            'PackageRolloutSummary' => ResponseSchemas::object($rollout, array_keys($rollout), 'A package rollout, without its machines.'),
            'MachineDetail' => ResponseSchemas::object([
                'id' => $int,
                'name' => $string,
                'environment' => ['type' => 'string', 'enum' => ['production', 'staging', 'development']],
                'status' => ['type' => 'string', 'enum' => ['online', 'offline', 'connecting', 'demo']],
                'ip' => $maybeString,
                'provider' => $maybeString,
                'os' => $maybeString,
                'cpuPercent' => $maybeNumber,
                'memoryUsedMb' => $maybeNumber,
                'diskPercent' => $maybeNumber,
                'groupId' => $maybeInt,
                'group' => ['type' => ['object', 'null'], 'required' => ['id', 'name'], 'properties' => ['id' => $int, 'name' => $string]],
                'region' => $maybeString,
                'tier' => $maybeString,
                'labels' => ['type' => 'object', 'additionalProperties' => $string],
                'maintenance' => ['type' => ['object', 'null'], 'required' => ['until', 'reason', 'public'], 'properties' => ['until' => $string, 'reason' => $maybeString, 'public' => $bool]],
                'lastSeen' => $time,
                'daemonVersion' => $maybeString,
                'icon' => ['type' => ['string', 'null'], 'description' => 'Its icon’s address, when it has one.'],
                'url' => $string,
                'osUpdates' => ResponseSchemas::object([
                    'checkedAt' => $time,
                    'waiting' => ['type' => ['integer', 'null'], 'description' => 'Null until it has been checked.'],
                    'security' => $maybeInt,
                    'rebootRequired' => ['type' => ['boolean', 'null']],
                    'held' => ['type' => 'array', 'items' => $string],
                    'complete' => ['type' => ['boolean', 'null']],
                ], ['checkedAt', 'waiting', 'security', 'rebootRequired', 'held', 'complete']),
                'plannedMaintenance' => ['type' => 'array', 'items' => ResponseSchemas::ref('PlannedMaintenance')],
                'connectedAt' => $time,
                'policy' => ResponseSchemas::object([
                    ...self::policy(),
                    'from' => ['type' => 'string', 'enum' => ['machine', 'group', 'rule', 'default'], 'description' => 'Why it follows this policy.'],
                    'waitingToReachMachine' => $bool,
                    'daemonCanFollowAll' => ['type' => 'boolean', 'description' => 'The daemon is new enough to follow every setting.'],
                ], [...self::POLICY_REQUIRED, 'from', 'waitingToReachMachine', 'daemonCanFollowAll']),
                'facts' => ResponseSchemas::object([
                    'hostname' => $maybeString,
                    'os' => $maybeString,
                    'osVersion' => $maybeString,
                    'kernel' => $maybeString,
                    'arch' => $maybeString,
                    'cpuCores' => $maybeInt,
                    'cpuModel' => $maybeString,
                    'memoryTotalMb' => $maybeInt,
                    'docker' => ['type' => ['boolean', 'null'], 'description' => 'Null until it has been scanned.'],
                    'dockerVersion' => $maybeString,
                    'initSystem' => $maybeString,
                    'timezone' => $maybeString,
                    'bootedAt' => $time,
                    'hosting' => ResponseSchemas::object([
                        'provider' => $maybeString,
                        'region' => $maybeString,
                        'zone' => $maybeString,
                        'instanceType' => $maybeString,
                    ], ['provider', 'region', 'zone', 'instanceType']),
                    'interfaces' => ['type' => 'array', 'items' => ResponseSchemas::object([
                        'name' => $string,
                        'addresses' => ['type' => 'array', 'items' => $string],
                    ], ['name', 'addresses'])],
                ], ['hosting', 'interfaces'], 'What the daemon reported about the machine.'),
                'usage' => ResponseSchemas::object([
                    'cpuPercent' => $maybeNumber,
                    'memoryUsedMb' => $maybeNumber,
                    'memoryTotalMb' => $maybeInt,
                    'memoryPercent' => $maybeInt,
                    'diskPercent' => $maybeNumber,
                    'disks' => ['type' => 'array', 'items' => ResponseSchemas::object([
                        'mount' => $string,
                        'device' => $maybeString,
                        'fsType' => $maybeString,
                        'totalGb' => $maybeNumber,
                        'usedGb' => $maybeNumber,
                        'percent' => $maybeInt,
                    ], ['mount', 'device', 'fsType', 'totalGb', 'usedGb', 'percent'])],
                ], ['cpuPercent', 'memoryUsedMb', 'memoryTotalMb', 'memoryPercent', 'diskPercent', 'disks']),
                'services' => ResponseSchemas::object([
                    'total' => $int,
                    'byStatus' => self::countsByStatus(),
                    'containers' => $int,
                    'managed' => $int,
                    'notRunning' => ['type' => 'array', 'description' => 'Up to 15.', 'items' => ResponseSchemas::object([
                        'key' => $string,
                        'name' => $string,
                        'status' => $string,
                    ], ['key', 'name', 'status'])],
                    'lastScannedAt' => $time,
                    'hint' => $string,
                ], ['total', 'byStatus', 'containers', 'managed', 'notRunning', 'lastScannedAt'], 'A summary of the services found on it.'),
                'serviceGroups' => ['type' => 'array', 'items' => ResponseSchemas::object([
                    'id' => $int,
                    'name' => $string,
                    'services' => $int,
                ], ['id', 'name', 'services'])],
                'backups' => ResponseSchemas::object([
                    'schedule' => ['type' => 'string', 'enum' => ['off', 'daily', 'weekly']],
                    'destination' => ['type' => ['object', 'null'], 'required' => ['id', 'name', 'type', 'where', 'status'], 'properties' => [
                        'id' => $int,
                        'name' => $string,
                        'type' => $string,
                        'where' => $string,
                        'status' => $string,
                    ]],
                    'lastBackupAt' => $time,
                    'lastRun' => ['type' => ['object', 'null'], 'required' => ['service', 'state', 'trigger', 'finishedAt', 'error'], 'properties' => [
                        'service' => $string,
                        'state' => $string,
                        'trigger' => $string,
                        'finishedAt' => $time,
                        'error' => $maybeString,
                    ]],
                    'backupableServices' => $int,
                ], ['schedule', 'destination', 'lastBackupAt', 'lastRun', 'backupableServices']),
                'statusPage' => ResponseSchemas::object([
                    'planAllows' => $bool,
                    'own' => ['type' => ['object', 'null'], 'description' => 'The machine’s own status page.', 'required' => ['slug', 'title', 'enabled', 'public', 'live', 'url'], 'properties' => [
                        'slug' => $string,
                        'title' => $maybeString,
                        'enabled' => $bool,
                        'public' => $bool,
                        'live' => $bool,
                        'url' => $string,
                    ]],
                    'fleet' => ['type' => ['object', 'null'], 'description' => 'The workspace’s status page for all its machines.', 'required' => ['slug', 'live', 'showsThisMachine', 'url'], 'properties' => [
                        'slug' => $string,
                        'live' => $bool,
                        'showsThisMachine' => $bool,
                        'url' => $string,
                    ]],
                ], ['planAllows', 'own', 'fleet']),
                'updates' => ['type' => ['object', 'null'], 'description' => 'The last successful check for, or install of, OS updates.', 'required' => ['jobId', 'checkedAt', 'waiting', 'security', 'applied', 'rebootRequired', 'hint'], 'properties' => [
                    'jobId' => $string,
                    'checkedAt' => $time,
                    'waiting' => $maybeInt,
                    'security' => $maybeInt,
                    'applied' => $bool,
                    'rebootRequired' => $bool,
                    'hint' => $string,
                ]],
                'recentJobs' => ['type' => 'array', 'description' => 'The latest 10.', 'items' => ResponseSchemas::object([
                    'id' => $string,
                    'kind' => $string,
                    'service' => $maybeString,
                    'state' => ['type' => 'string', 'enum' => self::JOB_STATES],
                    'phase' => $maybeString,
                    'error' => $maybeString,
                    'createdAt' => $time,
                    'finishedAt' => $time,
                ], ['id', 'kind', 'service', 'state', 'phase', 'error', 'createdAt', 'finishedAt'])],
                'scheduledTasks' => ['type' => 'array', 'description' => 'Cron jobs and systemd timers on it.', 'items' => ResponseSchemas::object([
                    'command' => $string,
                    'schedule' => $string,
                    'when' => ['type' => 'string', 'description' => 'The schedule in words, when it can be read.'],
                    'user' => $string,
                    'kind' => ['type' => 'string', 'enum' => ['cron', 'timer']],
                    'source' => $string,
                    'description' => $string,
                    'nextRun' => ['type' => 'string', 'format' => 'date-time'],
                    'lastRun' => ['type' => 'string', 'format' => 'date-time'],
                    'service' => ['type' => 'string', 'description' => 'The key of the service it belongs to.'],
                    'ours' => ['type' => 'boolean', 'description' => 'ServerOS set it up.'],
                ], ['command', 'schedule', 'kind', 'source', 'ours'])],
                'settingsUrl' => $string,
            ], ['id', 'name', 'environment', 'status', 'url', 'osUpdates', 'plannedMaintenance', 'policy', 'facts', 'usage', 'services', 'serviceGroups', 'backups', 'statusPage', 'updates', 'recentJobs', 'scheduledTasks', 'settingsUrl'], 'A machine in full.'),
        ];
    }

    /** @return array<string, mixed> service counts keyed by status; an empty list when there are none */
    public static function countsByStatus(): array
    {
        return ['type' => ['object', 'array'], 'additionalProperties' => ['type' => 'integer'], 'description' => 'How many services have each status. An empty list when there are none.'];
    }

    /** @return array<string, array<string, mixed>> */
    private static function policy(): array
    {
        return [
            'id' => ['type' => 'integer'],
            'name' => ['type' => 'string'],
            'default' => ['type' => 'boolean', 'description' => 'The workspace’s default policy.'],
            'discovery' => ['type' => 'boolean'],
            'logs' => ['type' => 'boolean'],
            'logIpMasking' => ['type' => 'string', 'enum' => MachinePolicy::MASKING],
            'securityWatch' => ['type' => 'boolean'],
            'errorReports' => ['type' => 'boolean'],
            'statusPageNewServices' => ['type' => 'boolean'],
            'updates' => ['type' => 'string', 'enum' => MachinePolicy::UPDATES],
            'pinnedVersion' => ['type' => 'string', 'description' => 'With updates pinned.'],
            'appliesTo' => ['type' => 'array', 'description' => 'Rules: machines with any of these details follow it.', 'items' => ResponseSchemas::object([
                'field' => ['type' => 'string'],
                'value' => ['type' => 'string'],
            ], ['field', 'value'])],
            'machines' => ['type' => 'integer', 'description' => 'How many machines follow it.'],
        ];
    }
}
