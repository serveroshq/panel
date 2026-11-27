<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An app tied to something in a connected account, like the Sentry project
 * its errors go to.
 *
 * @property int $id
 * @property int $integration_id
 * @property int $machine_id
 * @property string $service the app's name
 * @property array<string, mixed>|null $settings what it's tied to, never secrets
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Integration $integration
 * @property-read Machine $machine
 */
class IntegrationApp extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['settings' => 'array'];
    }

    public function setting(string $key): ?string
    {
        $value = $this->settings[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
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
