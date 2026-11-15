<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One event sent, or still being tried, to one endpoint: pending, delivered or failed.
 *
 * @property int $id
 * @property int $webhook_endpoint_id
 * @property string $event_id
 * @property string $event
 * @property array<string, mixed> $payload
 * @property string $state
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $error
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $delivered_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read WebhookEndpoint $endpoint
 */
class WebhookDelivery extends Model
{
    use MassPrunable;

    public const int KEEP_DAYS = 30;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<WebhookEndpoint, $this> */
    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(WebhookEndpoint::class, 'webhook_endpoint_id');
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS))->where('state', '!=', 'pending');
    }
}
