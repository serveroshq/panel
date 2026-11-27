<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A DNS record ServerOS keeps pointed at an app's machine, in a zone of a
 * connected DNS account. Only records it made itself (created_record) are
 * ever deleted there; one it found already pointing the right way, or took
 * over, is left in place when the app lets go of it.
 *
 * @property int $id
 * @property int $integration_id
 * @property int $machine_id
 * @property string $service the app's name on the machine
 * @property string $zone_id
 * @property string $zone_name
 * @property string $hostname the record's name, like shop.example.com or *.example.com
 * @property string|null $record_id the provider's id, once the record exists
 * @property string|null $type A, AAAA or CNAME
 * @property string|null $content where it points
 * @property bool $proxied
 * @property bool $created_record whether ServerOS made it (and so may delete it)
 * @property string $status pending, active or failed
 * @property string|null $error
 * @property string|null $warning something that still needs a hand at the provider, while the record itself is right
 * @property CarbonImmutable|null $synced_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Integration $integration
 * @property-read Machine $machine
 */
class IntegrationDnsRecord extends Model
{
    public const string PENDING = 'pending';

    public const string ACTIVE = 'active';

    public const string FAILED = 'failed';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'proxied' => 'boolean',
            'created_record' => 'boolean',
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
}
