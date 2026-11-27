<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A change at a provider that takes more than one call, like making a server
 * or resizing one. AdvanceIntegrationOperation moves it on a step at a time.
 *
 * @property int $id
 * @property int $integration_id
 * @property int|null $machine_id
 * @property int|null $user_id
 * @property string $kind create or resize
 * @property string|null $server_id
 * @property string $state queued, running, done or failed
 * @property string|null $step
 * @property array<string, mixed>|null $input
 * @property array<string, mixed>|null $result
 * @property string|null $action_id the provider's action being waited on
 * @property int $checks
 * @property string|null $error
 * @property CarbonImmutable|null $step_started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Integration $integration
 * @property-read Machine|null $machine
 * @property-read User|null $user
 */
class IntegrationOperation extends Model
{
    public const array ACTIVE = ['queued', 'running'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'input' => 'array',
            'result' => 'array',
            'step_started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function active(): bool
    {
        return in_array($this->state, self::ACTIVE, true);
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

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
