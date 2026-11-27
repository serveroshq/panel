<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One ongoing problem as a connected account knows it: a PagerDuty alert or
 * a Linear issue. `open_key` is the problem's key while it's open and null
 * after, so the same problem is never opened twice at once.
 *
 * @property int $id
 * @property int $integration_id
 * @property string $key monitor:12, machine:4 or cve:CVE-2026-1234
 * @property string|null $open_key
 * @property string $kind monitor, machine or cve
 * @property int|null $monitor_incident_id
 * @property int|null $machine_id
 * @property string $title
 * @property string|null $external_id the provider's id, like a Linear issue's
 * @property string|null $external_key what people call it there, like ENG-123
 * @property string|null $external_url
 * @property CarbonImmutable $opened_at
 * @property CarbonImmutable|null $resolved_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Integration $integration
 * @property-read MonitorIncident|null $monitorIncident
 */
class IntegrationIncident extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return BelongsTo<MonitorIncident, $this> */
    public function monitorIncident(): BelongsTo
    {
        return $this->belongsTo(MonitorIncident::class);
    }

    /** @return HasMany<IntegrationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class);
    }

    /** @return array{key: string, url: string}|null */
    public function link(): ?array
    {
        return $this->external_url === null ? null : [
            'key' => $this->external_key ?? $this->title,
            'url' => $this->external_url,
        ];
    }
}
