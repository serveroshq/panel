<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\ContainerRegistry;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;

/**
 * Logins for container registries, for deploying private images. Kept in
 * the dashboard only: the password never passes through an AI app.
 */
class WorkspaceRegistryController extends Controller
{
    public const int MAX = 20;

    #[Audited]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);

        $request->merge(['host' => ContainerRegistry::normalizeHost($request->input('host'))]);
        $data = $request->validate([
            'host' => ['required', 'string', 'max:253', 'regex:'.ContainerRegistry::HOST_PATTERN],
            'username' => ['required', 'string', 'max:255', 'not_regex:/[:\s]/'],
            'password' => ['required', 'string', 'max:4096'],
        ], [
            'host.regex' => 'Enter the registry’s address, like ghcr.io or registry.example.com:5000.',
            'username.not_regex' => 'A username can’t have spaces or a colon in it.',
        ]);

        $registry = $workspace->containerRegistries()->where('host', $data['host'])->first();
        if ($registry === null && $workspace->containerRegistries()->count() >= self::MAX) {
            return back()->withErrors(['host' => 'A workspace can keep up to '.self::MAX.' registry logins.']);
        }

        $registry ??= $workspace->containerRegistries()->make(['host' => $data['host']]);
        $registry->fill(['username' => $data['username'], 'password' => $data['password'], 'user_id' => $request->user()->id])->save();
        Activity::record($workspace, $registry->wasRecentlyCreated ? 'registry.add' : 'registry.update', $registry->host, meta: ['username' => $registry->username]);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Saved the login for {$registry->label()}. Deploys pull from it with this login."]);

        return back();
    }

    #[Audited]
    public function destroy(Workspace $workspace, ContainerRegistry $registry): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless($registry->workspace_id === $workspace->id, 404);

        $registry->delete();
        Activity::record($workspace, 'registry.remove', $registry->host);

        return back();
    }

    /** @return list<array{id: int, host: string, label: string, username: string, addedAt: string|null}> */
    public static function props(Workspace $workspace): array
    {
        return array_values($workspace->containerRegistries()->orderBy('host')->get()
            ->map(fn (ContainerRegistry $registry) => [
                'id' => $registry->id,
                'host' => $registry->host,
                'label' => $registry->label(),
                'username' => $registry->username,
                'addedAt' => $registry->created_at?->toIso8601String(),
            ])->all());
    }
}
