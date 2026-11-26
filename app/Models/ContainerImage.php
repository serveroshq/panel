<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A container on a machine and its image against the registry, kept by
 * App\Support\Images\ImageUpdates.
 *
 * @property int $id
 * @property int $machine_id
 * @property string $container the container's name
 * @property string $service_key docker:<id>, which changes when it's recreated
 * @property string $image the reference it was made from (postgres:16)
 * @property string|null $image_id
 * @property string $state current, available, local or unknown
 * @property string|null $remote_digest
 * @property string|null $error
 * @property CarbonImmutable|null $checked_at
 * @property CarbonImmutable|null $available_since
 * @property CarbonImmutable|null $scanned_at
 * @property string|null $scan_job_id
 * @property-read Machine $machine
 */
class ContainerImage extends Model
{
    public const array STATES = ['current', 'available', 'local', 'unknown'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'checked_at' => 'datetime',
            'available_since' => 'datetime',
            'scanned_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }
}
