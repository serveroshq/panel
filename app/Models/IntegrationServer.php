<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A server in a connected cloud account, as ServerOS last saw it, and the
 * machine it is linked to once one matches it.
 *
 * @property int $id
 * @property int $integration_id
 * @property int|null $machine_id
 * @property int|null $daemon_enrolment_id the join token a server made from ServerOS installs with
 * @property string $server_id the provider's id
 * @property string $name
 * @property array<string, mixed>|null $details Server::toArray() from the last sync
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Integration $integration
 * @property-read Machine|null $machine
 * @property-read DaemonEnrolment|null $enrolment
 */
class IntegrationServer extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'synced_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return BelongsTo<DaemonEnrolment, $this> */
    public function enrolment(): BelongsTo
    {
        return $this->belongsTo(DaemonEnrolment::class, 'daemon_enrolment_id');
    }
}
