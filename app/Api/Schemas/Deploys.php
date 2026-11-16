<?php

namespace App\Api\Schemas;

use App\Api\ResponseSchemas;
use App\Http\Controllers\BackupController;
use App\Mcp\McpContext;
use App\Mcp\Tools\Backup\BackupView;
use App\Models\BackupDestination;
use App\Models\LogEntry;
use App\Support\ServiceTemplates\ServiceTemplate;

final class Deploys
{
    /** @return array<string, mixed> */
    public static function machine(): array
    {
        return ResponseSchemas::object(['id' => ['type' => 'integer'], 'name' => ['type' => 'string']], ['id', 'name']);
    }

    /** @return array<string, mixed> */
    public static function jobState(): array
    {
        return ['type' => 'string', 'enum' => ['queued', 'running', ...McpContext::FINISHED]];
    }

    /**
     * What the tools that start a deploy answer: the job and where to follow it, plus $properties.
     *
     * @param  array<string, array<string, mixed>>  $properties
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    public static function started(array $properties, array $required): array
    {
        return ResponseSchemas::object([
            'jobId' => ['type' => 'string'],
            'state' => self::jobState(),
            'machine' => self::machine(),
            ...$properties,
            'job' => ResponseSchemas::ref('Job'),
            'deploymentsUrl' => ['type' => 'string', 'description' => 'The deploy on its machine’s page in the dashboard.'],
            'next' => ['type' => 'string', 'description' => 'What happens now, and how to follow it.'],
        ], ['jobId', 'state', 'machine', ...$required, 'job', 'deploymentsUrl', 'next']);
    }

    /**
     * A template's summary, plus $extra.
     *
     * @param  array<string, array<string, mixed>>  $extra
     * @param  list<string>  $required
     * @return array<string, mixed>
     */
    public static function template(array $extra = [], array $required = [], ?string $description = null): array
    {
        return ResponseSchemas::object([
            'key' => ['type' => 'string', 'description' => 'Pass as template to deploy_template.'],
            'name' => ['type' => 'string'],
            'category' => ['type' => 'string', 'enum' => ServiceTemplate::CATEGORIES],
            'kind' => ['type' => 'string', 'enum' => ['app', 'stack'], 'description' => 'app builds your code from a repository; stack is ready-made.'],
            'description' => ['type' => 'string'],
            'web' => ['type' => 'boolean', 'description' => 'Serves a website, so it can have a domain.'],
            'port' => ['type' => 'integer'],
            'portEditable' => ['type' => 'boolean'],
            'needs' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'What deploy_template must be given.'],
            ...$extra,
        ], ['key', 'name', 'category', 'kind', 'description', 'web', 'port', 'portEditable', 'needs', ...$required], $description);
    }

    /** @return array<string, array<string, mixed>> */
    public static function components(): array
    {
        $string = ['type' => 'string'];
        $strings = ['type' => 'array', 'items' => $string];
        $maybeString = ['type' => ['string', 'null']];
        $time = ['type' => ['string', 'null'], 'format' => 'date-time'];
        $named = ResponseSchemas::object(['id' => ['type' => 'integer'], 'name' => $string], ['id', 'name']);

        return [
            'Deploy' => ResponseSchemas::object([
                'id' => ['type' => 'string', 'description' => 'The deploy job’s id.'],
                'machine' => ResponseSchemas::object(['id' => ['type' => 'integer'], 'name' => $string], ['id']),
                'app' => ['type' => ['string', 'null'], 'description' => 'The name it was deployed as.'],
                'state' => self::jobState(),
                'finished' => ['type' => 'boolean'],
                'phase' => $maybeString,
                'progress' => ['type' => ['integer', 'null'], 'description' => '0 to 100.'],
                'kind' => ['type' => ['string', 'null'], 'enum' => ['app', 'stack', null], 'description' => 'app: built from code; stack: a ready-made template.'],
                'source' => ['type' => ['string', 'null'], 'enum' => ['git', 'upload', null], 'description' => 'Where an app’s code came from.'],
                'repo' => ['type' => ['string', 'null'], 'description' => 'The Git repository, when it was built from one.'],
                'commit' => $maybeString,
                'domains' => $strings,
                'port' => ['type' => ['integer', 'null']],
                'live' => ['type' => 'boolean', 'description' => 'This is the deploy running now.'],
                'by' => ['type' => ['string', 'null'], 'description' => 'Who started it.'],
                'error' => [...ResponseSchemas::object([
                    'phase' => $string,
                    'message' => $string,
                    'nextStep' => ['type' => 'string', 'description' => 'What to do about it.'],
                    'explanation' => ResponseSchemas::object([
                        'cause' => $string,
                        'fix' => $string,
                        'command' => ['type' => 'string', 'description' => 'A command that fixes it.'],
                        'alternative' => $string,
                        'install' => ResponseSchemas::object(['label' => $string, 'packages' => $strings], ['label', 'packages']),
                    ], ['cause', 'fix'], 'Why it likely failed and how to fix it, when that is known.'),
                ]), 'type' => ['object', 'null']],
                'retryable' => ['type' => 'boolean', 'description' => 'redeploy_app can send it again.'],
                'stale' => ['type' => 'boolean', 'description' => 'Under way with no news for 10 minutes.'],
                'createdAt' => $time,
                'finishedAt' => $time,
                'url' => ['type' => 'string', 'description' => 'The deploy on its machine’s page in the dashboard.'],
                'log' => $strings,
                'logLines' => ['type' => 'integer'],
            ], ['id', 'machine', 'app', 'state', 'finished', 'phase', 'progress', 'kind', 'source', 'repo', 'commit', 'domains', 'port', 'live', 'by', 'error', 'retryable', 'stale', 'createdAt', 'finishedAt', 'url'], 'One deploy of an app or stack.'),
            'Template' => self::template(description: 'A template deploy_template can deploy.'),
            'Backup' => ResponseSchemas::object([
                'id' => ['type' => 'integer'],
                'machine' => $named,
                'service' => ['type' => 'string', 'description' => 'The service’s key.'],
                'serviceName' => $string,
                'trigger' => ['type' => 'string', 'enum' => BackupView::TRIGGERS],
                'state' => ['type' => 'string', 'enum' => BackupView::STATES],
                'status' => ['type' => 'string', 'description' => 'The state as the dashboard says it.'],
                'bytes' => ['type' => ['integer', 'null']],
                'size' => ['type' => ['string', 'null'], 'description' => 'bytes, readable, like 12 MB.'],
                'destination' => [...$named, 'type' => ['object', 'null']],
                'location' => ['type' => ['string', 'null'], 'description' => 'Where it is kept at the destination.'],
                'error' => $maybeString,
                'startedAt' => $time,
                'finishedAt' => $time,
                'seconds' => ['type' => ['integer', 'null'], 'description' => 'How long it took.'],
                'jobId' => $maybeString,
                'canRestore' => ['type' => 'boolean'],
                'cannotRestore' => ['type' => ['string', 'null'], 'description' => 'Why it can’t be restored now, when it can’t.'],
                'lastRestore' => [...ResponseSchemas::object([
                    'state' => $string,
                    'error' => $maybeString,
                    'at' => $time,
                    'jobId' => $maybeString,
                ], ['state', 'error', 'at', 'jobId']), 'type' => ['object', 'null']],
            ], ['id', 'machine', 'service', 'serviceName', 'trigger', 'state', 'status', 'bytes', 'size', 'destination', 'location', 'error', 'startedAt', 'finishedAt', 'seconds', 'jobId', 'canRestore', 'cannotRestore', 'lastRestore'], 'A backup of one service.'),
            'BackupMachine' => ResponseSchemas::object([
                'id' => ['type' => 'integer'],
                'name' => $string,
                'status' => ['type' => 'string', 'enum' => ['online', 'offline', 'connecting', 'demo']],
                'schedule' => ['type' => 'string', 'enum' => BackupController::SCHEDULES],
                'destination' => [...ResponseSchemas::object([
                    'id' => ['type' => 'integer'],
                    'name' => $string,
                    'type' => ['type' => 'string', 'enum' => BackupDestination::TYPES],
                    'status' => ['type' => 'string', 'description' => 'Like ready or failed.'],
                ], ['id', 'name', 'type', 'status']), 'type' => ['object', 'null']],
                'lastBackupAt' => $time,
                'backupableServices' => ['type' => 'integer', 'description' => 'How many services it can back up.'],
                'canBackUpNow' => ['type' => 'boolean'],
                'unavailable' => ['type' => ['string', 'null'], 'description' => 'Why it can’t back up now, when it can’t.'],
                'url' => $string,
            ], ['id', 'name', 'status', 'schedule', 'destination', 'lastBackupAt', 'backupableServices', 'canBackUpNow', 'unavailable', 'url'], 'A machine’s backup settings.'),
            'LogFilters' => ResponseSchemas::object([
                'machine' => [...ResponseSchemas::object(['id' => ['type' => 'integer'], 'name' => $maybeString], ['id', 'name']), 'type' => ['object', 'null']],
                'service' => [...ResponseSchemas::object([
                    'asked' => $string,
                    'matches' => ['type' => 'array', 'items' => $string, 'description' => 'The service names it matched.'],
                ], ['asked', 'matches']), 'type' => ['object', 'null']],
                'level' => ['type' => ['string', 'null'], 'enum' => [...LogEntry::LEVELS, null]],
                'q' => $maybeString,
                'since' => $time,
                'until' => $time,
            ], ['machine', 'service'], 'The filters the lines were read with.'),
        ];
    }
}
