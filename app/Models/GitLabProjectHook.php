<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The webhook ServerOS added to a GitLab project so pushes to it deploy the
 * apps made from it. GitLab sends the secret back in X-Gitlab-Token.
 *
 * @property int $id
 * @property string $uuid
 * @property int $integration_id
 * @property int $project_id
 * @property string $project the project's path, like group/app
 * @property int $hook_id
 * @property string $secret
 * @property CarbonImmutable|null $last_push_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Integration $integration
 */
#[Hidden(['secret'])]
class GitLabProjectHook extends Model
{
    protected $table = 'gitlab_project_hooks';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'project_id' => 'integer',
            'hook_id' => 'integer',
            'last_push_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
