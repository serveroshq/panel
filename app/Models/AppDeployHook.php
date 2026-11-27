<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An app's secret deploy address: a POST to it deploys the app. Only a
 * hash of its token is kept, so the address is shown once, when it's made.
 *
 * @property int $id
 * @property int $machine_id
 * @property string $service
 * @property string $token_hash
 * @property string $hint
 * @property int|null $user_id
 * @property CarbonImmutable|null $last_used_at
 * @property string|null $last_trigger
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Machine $machine
 * @property-read User|null $user
 */
class AppDeployHook extends Model
{
    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected function casts(): array
    {
        return ['last_used_at' => 'datetime'];
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
