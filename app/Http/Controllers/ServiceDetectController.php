<?php

namespace App\Http\Controllers;

use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\NotAudited;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\GitRemote;
use App\Support\ServiceTemplates\GitHubRateLimited;
use App\Support\ServiceTemplates\RepositoryDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ServiceDetectController extends Controller
{
    #[NotAudited('Looks at a repository; nothing changes')]
    public function __invoke(Request $request, Workspace $workspace, RepositoryDetector $detector): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $data = $request->validate([
            'repository' => ['required', 'string', 'max:300'],
            'machine' => ['sometimes', 'nullable', 'integer'],
        ]);
        $machine = isset($data['machine']) ? $workspace->machines()->find($data['machine']) : null;

        // Other hosts are read by the machine (deploy/inspect), not GitHub's API.
        if (! RepositoryDetector::onGitHub($data['repository'])) {
            $repository = trim($data['repository']);
            $looksLikeRepo = GitRemote::isSsh($repository) || str_starts_with(strtolower($repository), 'https://');

            return response()->json([
                'message' => $looksLikeRepo ? 'This repository isn’t on GitHub, so the machine reads it.' : 'Paste a repository link, like https://github.com/you/app.',
                'errors' => ['repository' => [$looksLikeRepo ? 'This repository isn’t on GitHub, so the machine you deploy to reads it. Choose the machine, then look again.' : 'Paste a repository link, like https://github.com/you/app or https://gitlab.com/you/app.']],
                'inspect' => $looksLikeRepo,
            ], 422);
        }

        try {
            return response()->json($detector->detect($data['repository'], $workspace, $machine instanceof Machine ? DaemonJobQueue::canDeployBuild($machine) : true));
        } catch (GitHubRateLimited $limited) {
            // GitHub's API is out of requests for now: the machine can read
            // a public repository itself, with no limit.
            if (! $machine instanceof Machine || ! DaemonJobQueue::canDeployBuild($machine)) {
                throw $limited;
            }

            return response()->json([
                'message' => 'GitHub is busy, so the machine reads the repository.',
                'errors' => ['repository' => ['GitHub is busy, so the machine you deploy to reads the repository instead.']],
                'inspect' => true,
            ], 422);
        }
    }
}
