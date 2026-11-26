<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $machine_move_app_id
 * @property string $service
 * @property string $container
 * @property string $method
 * @property string $state
 * @property int $round
 * @property string|null $backup_job_id
 * @property string|null $restore_job_id
 * @property string|null $snapshot
 * @property string|null $received
 * @property int|null $bytes
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read MachineMoveApp $app
 */
#[Fillable(['service', 'container', 'method', 'state'])]
class MachineMoveCopy extends Model
{
    /** Dumped while the database runs, so it can keep running for the final copy. */
    public const array DUMPS = ['pg_dumpall', 'mysqldump'];

    /** @return BelongsTo<MachineMoveApp, $this> */
    public function app(): BelongsTo
    {
        return $this->belongsTo(MachineMoveApp::class, 'machine_move_app_id');
    }
}
