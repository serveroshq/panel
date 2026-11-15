<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A URL that gets signed events from a workspace (Settings › API › Webhooks).
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property string $url
 * @property string|null $description
 * @property list<string>|null $events
 * @property string $secret
 * @property bool $enabled
 * @property string|null $disabled_reason
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $creator
 */
class WebhookEndpoint extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return [
            'events' => 'array',
            'secret' => 'encrypted',
            'enabled' => 'boolean',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<WebhookDelivery, $this> */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function wants(string $event): bool
    {
        return $this->events === null || $this->events === [] || in_array($event, $this->events, true);
    }
}
