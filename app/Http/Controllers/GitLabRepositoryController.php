<?php

namespace App\Http\Controllers;

use App\Models\Workspace;
use App\Support\Integrations\GitLab\GitLabRepositories;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class GitLabRepositoryController extends Controller
{
    /** The connected GitLab accounts' projects, to pick one to deploy. */
    public function index(Workspace $workspace, GitLabRepositories $gitlab): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $list = $gitlab->listFor($workspace);

        return response()->json(['connected' => $list['accounts'] !== [], ...$list]);
    }

    public function branches(Request $request, Workspace $workspace, GitLabRepositories $gitlab): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $repo = $request->validate(['repo' => ['required', 'string', 'max:500']])['repo'];
        $found = $gitlab->find($workspace, $repo) ?? abort(404);

        try {
            return response()->json(['branches' => $gitlab->branches($found[0], $found[1])]);
        } catch (IntegrationFailed) {
            return response()->json(['branches' => []]);
        }
    }
}
