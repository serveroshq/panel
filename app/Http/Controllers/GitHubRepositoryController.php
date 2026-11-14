<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\GitHub\GitHubApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;

class GitHubRepositoryController extends Controller
{
    /** The connected GitHub accounts' repositories, to pick one to deploy. */
    public function index(Workspace $workspace, GitHubApp $github): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $connected = GitHubApp::enabled() && $workspace->githubInstallations()->exists();

        return response()->json([
            'enabled' => GitHubApp::enabled(),
            'connected' => $connected,
            'repositories' => $connected
                ? Cache::remember("github:repositories:{$workspace->id}", 60, fn () => $github->repositoriesFor($workspace))
                : [],
        ]);
    }

    public function branches(Workspace $workspace, string $owner, string $repo, GitHubApp $github): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);

        return response()->json(['branches' => $github->branchesFor($workspace, $owner, $repo) ?? []]);
    }
}
