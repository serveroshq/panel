<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property int $machine_move_id
 * @property string $name
 * @property string $source
 * @property string $state
 * @property string|null $deploy_job_id
 * @property string|null $error
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read MachineMove $move
 * @property-read Collection<int, MachineMoveCopy> $copies
 */
#[Fillable(['name', 'source', 'state'])]
class MachineMoveApp extends Model
{
    /** @return BelongsTo<MachineMove, $this> */
    public function move(): BelongsTo
    {
        return $this->belongsTo(MachineMove::class, 'machine_move_id');
    }

    /** @return HasMany<MachineMoveCopy, $this> */
    public function copies(): HasMany
    {
        return $this->hasMany(MachineMoveCopy::class)->orderBy('id');
    }
}
