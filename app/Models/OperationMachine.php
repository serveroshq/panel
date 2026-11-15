<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One machine's part in an operation: pending, running (its job is going),
 * checking (done, and staying healthy for wait_healthy_seconds), then
 * succeeded, failed or skipped.
 *
 * @property int $id
 * @property int $operation_id
 * @property int|null $machine_id
 * @property string $machine_name
 * @property int $position
 * @property string $state
 * @property string|null $job_id
 * @property string|null $outcome
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $healthy_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Operation $operation
 * @property-read Machine|null $machine
 */
class OperationMachine extends Model
{
    public const array STATES = ['pending', 'running', 'checking', 'succeeded', 'failed', 'skipped'];

    public const array SETTLED = ['succeeded', 'failed', 'skipped'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'healthy_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Operation, $this> */
    public function operation(): BelongsTo
    {
        return $this->belongsTo(Operation::class);
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
