<?php

namespace App\Http\Controllers;

use App\Models\ThemeShare;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\Theme\DashboardTheme;
use App\Support\Theme\ThemeCss;
use App\Support\Theme\ThemeSharing;
use App\Support\Theme\ThemeTemplates;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

class WorkspaceThemeController extends Controller
{
    public function __construct(
        private readonly DashboardTheme $theme,
        private readonly ThemeTemplates $templates,
    ) {}

    public function show(Request $request, Workspace $workspace): Response
    {
        Gate::authorize('manageSettings', $workspace);

        return Inertia::render('workspace/settings/Theme', [
            'workspace' => $workspace->toPageProps(),
            'css' => $this->theme->editing($workspace, $request->user()),
            'publishedCss' => $workspace->theme_css,
            'hasDraft' => $this->theme->draftOf($workspace, $request->user()) !== null,
            'publishedAt' => $workspace->theme_published_at?->toIso8601String(),
            'templates' => $this->templates->all(),
            'maxBytes' => ThemeCss::MAX_BYTES,
            'images' => $this->theme->images($workspace),
            'maxImages' => DashboardTheme::MAX_IMAGES,
            'shares' => ThemeShare::query()->where('workspace_id', $workspace->id)->latest()->get()
                ->map(fn (ThemeShare $share) => [
                    'id' => $share->id,
                    'name' => $share->name,
                    'url' => $share->url(),
                    'imports' => $share->imports,
                    'createdAt' => $share->created_at?->toIso8601String(),
                ])->all(),
            'sharedUrl' => $request->session()->get('sharedUrl'),
        ]);
    }

    #[NotAudited('A draft only the person editing sees; publishing it is audited')]
    public function saveDraft(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $data = $request->validate(['css' => ['present', 'nullable', 'string', 'max:'.ThemeCss::MAX_BYTES]]);

        try {
            $this->theme->saveDraft($workspace, $request->user(), (string) ($data['css'] ?? ''));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['css' => $e->getMessage()]);
        }

        return back();
    }

    #[NotAudited('A draft only the person editing sees')]
    public function discardDraft(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $this->theme->discardDraft($workspace, $request->user());

        return back();
    }

    #[Audited]
    public function publish(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $data = $request->validate(['css' => ['sometimes', 'nullable', 'string', 'max:'.ThemeCss::MAX_BYTES]]);

        try {
            if (array_key_exists('css', $data)) {
                $this->theme->saveDraft($workspace, $request->user(), (string) ($data['css'] ?? ''));
            }
            if (! $this->theme->publish($workspace, $request->user())) {
                AuditContext::nothingChanged();
            }
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['css' => $e->getMessage()]);
        }

        return back();
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        if (! $this->theme->turnOff($workspace, $request->user())) {
            AuditContext::nothingChanged();
        }

        return back();
    }

    #[Audited]
    public function storeImage(Request $request, Workspace $workspace): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $data = $request->validate(['image' => ['required', 'file', 'mimes:'.implode(',', DashboardTheme::IMAGE_TYPES), 'max:5120']]);

        try {
            $image = $this->theme->addImage($workspace, $request->user(), $data['image']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['image' => $e->getMessage()]);
        }

        return back()->with('uploadedImage', $image['url']);
    }

    #[Audited]
    public function destroyImage(Request $request, Workspace $workspace, string $image): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        if (! $this->theme->removeImage($workspace, $request->user(), $image)) {
            AuditContext::nothingChanged();
        }

        return back();
    }

    #[Audited]
    public function storeShare(Request $request, Workspace $workspace, ThemeSharing $sharing): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'css' => ['required', 'string', 'max:'.ThemeCss::MAX_BYTES],
        ]);

        try {
            $share = $sharing->share($workspace, $request->user(), trim($data['name']), $data['css']);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['share' => $e->getMessage()]);
        }

        return back()->with('sharedUrl', $share->url());
    }

    #[Audited]
    public function destroyShare(Request $request, Workspace $workspace, ThemeShare $share, ThemeSharing $sharing): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        abort_unless($share->workspace_id === $workspace->id, 404);
        $sharing->stop($share, $request->user());

        return back();
    }

    #[Audited]
    public function import(Request $request, Workspace $workspace, ThemeSharing $sharing): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $data = $request->validate([
            'link' => ['required_without:file', 'nullable', 'string', 'max:300'],
            'file' => ['required_without:link', 'nullable', 'file', 'max:'.(int) ceil(ThemeCss::MAX_BYTES / 1024)],
        ]);

        try {
            if (isset($data['file'])) {
                $this->theme->saveDraft($workspace, $request->user(), (string) file_get_contents($data['file']->getRealPath()));
                AuditContext::nothingChanged();
            } else {
                $share = ThemeSharing::find((string) $data['link']);
                if ($share === null) {
                    throw ValidationException::withMessages(['link' => 'That link doesn’t lead to a shared theme. It may have stopped being shared.']);
                }
                $sharing->import($share, $workspace, $request->user());
            }
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([isset($data['file']) ? 'file' : 'link' => $e->getMessage()]);
        }

        return back();
    }
}
