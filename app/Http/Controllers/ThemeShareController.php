<?php

namespace App\Http\Controllers;

use App\Models\ThemeShare;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Theme\ThemeSharing;
use App\Support\Theme\ThemeTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

class ThemeShareController extends Controller
{
    public function show(Request $request, string $token): InertiaResponse
    {
        $share = ThemeShare::query()->where('token', $token)->firstOrFail();
        $user = $request->user();

        return Inertia::render('themes/Show', [
            'theme' => [
                'name' => $share->name,
                'css' => $share->css,
                'url' => $share->url(),
                'imports' => $share->imports,
                'preview' => [
                    'light' => ThemeTemplates::variables($share->css, 'html:root'),
                    'dark' => ThemeTemplates::variables($share->css, 'html.dark'),
                ],
            ],
            'signedIn' => $user !== null,
            'workspaces' => $user === null ? [] : $this->manageable($user),
        ]);
    }

    /** Sends people who weren't signed in back to the theme once they are. */
    public function add(string $token): RedirectResponse
    {
        return redirect()->route('themes.show', $token);
    }

    #[Audited]
    public function import(Request $request, string $token, ThemeSharing $sharing): Response
    {
        $share = ThemeShare::query()->where('token', $token)->firstOrFail();
        $data = $request->validate(['workspace' => ['required', 'string', 'max:100']]);
        $workspace = Workspace::query()->where('slug', $data['workspace'])->first();
        abort_if($workspace === null || ! Gate::allows('manageSettings', $workspace), 403);

        try {
            $sharing->import($share, $workspace, $request->user());
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['workspace' => $e->getMessage()]);
        }

        return Inertia::location($workspace->url().'/settings/theme');
    }

    /** @return list<array{slug: string, name: string}> Workspaces the person can change the theme of. */
    private function manageable(User $user): array
    {
        $workspaces = [];
        foreach ($user->workspaces()->orderBy('name')->get() as $workspace) {
            if (Gate::forUser($user)->allows('manageSettings', $workspace)) {
                $workspaces[] = ['slug' => $workspace->slug, 'name' => $workspace->name];
            }
        }

        return $workspaces;
    }
}
