<?php

namespace App\Models;

use App\Support\GitHub\GitHubApp;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Cache;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $installation_id
 * @property string $account_login
 * @property string|null $account_type
 * @property int|null $connected_by
 * @property list<int>|null $repository_ids
 */
#[Fillable(['installation_id', 'account_login', 'account_type', 'connected_by', 'repository_ids'])]
class GitHubInstallation extends Model
{
    protected $table = 'github_installations';

    protected static function booted(): void
    {
        // Connecting, limiting or disconnecting an account changes the repository list.
        $forget = fn (self $installation) => Cache::forget(GitHubApp::repositoriesKey($installation->workspace_id));
        static::saved($forget);
        static::deleted($forget);
    }

    protected function casts(): array
    {
        return ['installation_id' => 'integer', 'repository_ids' => 'array'];
    }

    public function allows(int $repositoryId): bool
    {
        return $this->repository_ids === null || in_array($repositoryId, $this->repository_ids, true);
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
