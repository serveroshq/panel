<?php

namespace App\Mcp\Tools\Backup;

use App\Api\ResponseSchemas;
use App\Api\Schemas\Deploys as DeploySchemas;
use App\Http\Controllers\BackupController;
use App\Mcp\McpContext;
use App\Mcp\Tool;
use App\Models\Backup;
use App\Models\BackupDestination;

final class ListBackupDestinations extends Tool
{
    public function name(): string
    {
        return 'list_backup_destinations';
    }

    public function title(): string
    {
        return 'List backup destinations';
    }

    public function description(): string
    {
        return 'The places this workspace’s backups can go, as the Backups page’s Destinations tab lists them: an S3-compatible bucket (type s3, `where` like s3://bucket/prefix) or another of the workspace’s machines that receives them over SFTP (type machine, with receivingMachine). '
            .'Each has its id and name (update_backup_settings takes either), status (ready, setting_up or failed, with statusMessage saying why), the machines that send their backups there with each one’s schedule, and the latest backup sent there. '
            .'Access keys, secrets, storage endpoints and SSH keys are never shown. Destinations are added, edited and removed on the Backups page, since they need credentials.';
    }

    public function responseSchema(): array
    {
        $string = ['type' => 'string'];
        $time = ['type' => ['string', 'null'], 'format' => 'date-time'];

        return ResponseSchemas::object([
            'destinations' => ['type' => 'array', 'items' => ResponseSchemas::object([
                'id' => ['type' => 'integer'],
                'name' => $string,
                'type' => ['type' => 'string', 'enum' => BackupDestination::TYPES],
                'kind' => ['type' => 'string', 'description' => 'The type, readable.'],
                'where' => ['type' => 'string', 'description' => 'The bucket, or the machine that receives them.'],
                'receivingMachine' => [...DeploySchemas::machine(), 'type' => ['object', 'null']],
                'status' => ['type' => 'string', 'description' => 'Like ready or failed.'],
                'statusMessage' => ['type' => ['string', 'null']],
                'machines' => ['type' => 'array', 'description' => 'The machines that back up to it.', 'items' => ResponseSchemas::object([
                    'id' => ['type' => 'integer'],
                    'name' => $string,
                    'schedule' => ['type' => 'string', 'enum' => BackupController::SCHEDULES],
                ], ['id', 'name', 'schedule'])],
                'machineCount' => ['type' => 'integer'],
                'lastBackup' => [...ResponseSchemas::object([
                    'id' => ['type' => 'integer'],
                    'machine' => ['type' => 'string', 'description' => 'The machine’s name.'],
                    'serviceName' => $string,
                    'state' => ['type' => 'string', 'enum' => BackupView::STATES],
                    'at' => $time,
                ], ['id', 'machine', 'serviceName', 'state', 'at']), 'type' => ['object', 'null']],
                'createdAt' => $time,
            ], ['id', 'name', 'type', 'kind', 'where', 'receivingMachine', 'status', 'statusMessage', 'machines', 'machineCount', 'lastBackup', 'createdAt'])],
            'count' => ['type' => 'integer'],
            'url' => $string,
        ], ['destinations', 'count', 'url']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $workspace = $context->workspace;
        $machines = $workspace->machines()->orderBy('name')->get();
        $destinations = $workspace->backupDestinations()->with('machine:id,name')->orderBy('name')->get();

        $latest = Backup::query()
            ->whereIn('backup_destination_id', $destinations->modelKeys())
            ->whereIn('machine_id', $machines->modelKeys())
            ->selectRaw('max(id) as id')
            ->groupBy('backup_destination_id')
            ->pluck('id');
        $last = Backup::query()->whereKey($latest)->with('machine:id,name')->get()->keyBy('backup_destination_id');

        return [
            'destinations' => $destinations->map(fn (BackupDestination $d) => BackupView::destination($d, $machines, $last->get($d->id)))->values()->all(),
            'count' => $destinations->count(),
            'url' => $context->url('/backups'),
        ];
    }
}
