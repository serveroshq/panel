<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A copy of a workspace's dashboard theme anyone with the link can see and add to their own workspace.
 *
 * @property int $id
 * @property string $token
 * @property int $workspace_id
 * @property int|null $user_id
 * @property string $name
 * @property string $css
 * @property int $imports
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['token', 'name', 'css', 'user_id'])]
class ThemeShare extends Model
{
    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function url(): string
    {
        return rtrim((string) config('app.url'), '/').'/themes/'.$this->token;
    }
}
