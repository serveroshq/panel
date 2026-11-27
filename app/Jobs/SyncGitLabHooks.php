<?php

namespace App\Jobs;

use App\Models\Workspace;
use App\Support\Integrations\GitLab\GitLabHooks;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Takes the webhooks no app needs any more off the workspace's GitLab projects. */
class SyncGitLabHooks implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(public int $workspaceId) {}

    public function handle(GitLabHooks $hooks): void
    {
        $workspace = Workspace::query()->find($this->workspaceId);
        if ($workspace !== null) {
            $hooks->sync($workspace);
        }
    }
}
