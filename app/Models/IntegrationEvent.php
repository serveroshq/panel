<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Something ServerOS sent, or is sending, to a connected account: a page, an
 * issue, a comment, a release. Kept so the account page can show what went
 * out and what didn't, and so failures are tried again.
 *
 * @property int $id
 * @property int $integration_id
 * @property int|null $integration_incident_id
 * @property string $action open, update, resolve, test, release or rollback
 * @property string $subject
 * @property array<string, mixed>|null $payload what to send, never secrets
 * @property string $state queued, sending, retrying, sent, skipped or failed
 * @property int $attempts
 * @property string|null $error
 * @property array<string, mixed>|null $result
 * @property CarbonImmutable|null $next_attempt_at
 * @property CarbonImmutable|null $sent_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Integration $integration
 * @property-read IntegrationIncident|null $incident
 */
class IntegrationEvent extends Model
{
    public const array PENDING = ['queued', 'sending', 'retrying'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'next_attempt_at' => 'datetime',
            'sent_at' => 'datetime',
        ];
    }

    public function input(string $key): ?string
    {
        $value = $this->payload[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }

    /** @return BelongsTo<IntegrationIncident, $this> */
    public function incident(): BelongsTo
    {
        return $this->belongsTo(IntegrationIncident::class, 'integration_incident_id');
    }
}
