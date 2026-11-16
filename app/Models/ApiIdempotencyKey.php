<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One API key's use of an Idempotency-Key: which request it was for and,
 * once that finished, what it answered, so a retry gets the same answer.
 */
class ApiIdempotencyKey extends Model
{
    use MassPrunable;

    public const HOURS = 24;

    public const UPDATED_AT = null;

    protected $guarded = [];

    /** An answer can hold something shown only once, like a webhook's secret. */
    protected function casts(): array
    {
        return ['body' => 'encrypted'];
    }

    /** @return Builder<static> */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subHours(self::HOURS));
    }
}
