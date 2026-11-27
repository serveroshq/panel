<?php

namespace App\Http\Controllers;

use App\Models\GitLabProjectHook;
use App\Support\Deploys\DeployOnPush;
use App\Support\Integrations\GitLab\GitLabRepositories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Pushes from the webhooks ServerOS added to GitLab projects. Each webhook
 * has its own address and secret, which GitLab sends back as X-Gitlab-Token.
 */
class GitLabWebhookController extends Controller
{
    public function __invoke(Request $request, string $hook, DeployOnPush $apps): JsonResponse
    {
        $row = GitLabProjectHook::query()->where('uuid', $hook)->with('integration')->first();
        $token = $request->header('X-Gitlab-Token');
        if ($row === null || ! is_string($token) || $token === '' || ! hash_equals($row->secret, $token)) {
            return response()->json(['error' => 'Bad token.'], 401);
        }
        if ($request->header('X-Gitlab-Event') !== 'Push Hook' || $request->input('object_kind') !== 'push') {
            return response()->json(['ignored' => true]);
        }

        $ref = (string) $request->input('ref');
        $commit = strtolower((string) $request->input('after'));
        $project = (int) $request->input('project.id', $request->input('project_id'));
        if (! str_starts_with($ref, 'refs/heads/') || preg_match('/^[0-9a-f]{40}$/', $commit) !== 1 || $commit === str_repeat('0', 40)
            || $project !== $row->project_id) {
            return response()->json(['ignored' => true]);
        }
        $branch = substr($ref, strlen('refs/heads/'));
        $row->forceFill(['last_push_at' => now()])->save();

        $instance = GitLabRepositories::instanceOf($row->integration);
        $path = strtolower($row->project);
        $deploys = $apps->handle([$row->integration->workspace_id], $path, $branch, $commit,
            fn (?string $url): bool => GitLabRepositories::pathOn($instance, $url) === $path);

        return response()->json(['deploys' => $deploys['started'], 'deploysSkipped' => $deploys['skipped']], 202);
    }
}
