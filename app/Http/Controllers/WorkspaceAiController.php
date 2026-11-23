<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Support\Ai\AiProviders;
use App\Support\Ai\CompatibleEndpoint;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use RuntimeException;

class WorkspaceAiController extends Controller
{
    /** @return array{providers: list<array<string, mixed>>, agentUses: string, canManage: bool} */
    public static function props(Workspace $workspace, bool $canManage): array
    {
        $keys = WorkspaceAiKey::query()->where('workspace_id', $workspace->id)->with('user:id,name')->get()->keyBy('provider');
        $uses = app(AiProviders::class)->forAgent($workspace);

        return [
            'providers' => array_values(collect(AiProviders::PROVIDERS)->map(fn (array $p, string $provider) => [
                'provider' => $provider,
                'label' => $p['label'],
                'defaultModel' => $p['model'],
                'connected' => $keys->has($provider),
                'hint' => $keys->get($provider)?->hint,
                'model' => $keys->get($provider)?->model,
                'baseUrl' => $keys->get($provider)?->base_url,
                'by' => $keys->get($provider)?->user?->name,
                'connectedAt' => $keys->get($provider)?->created_at?->toIso8601String(),
            ])->all()),
            'agentUses' => $uses['own'] ? AiProviders::PROVIDERS[$uses['provider']]['label'].' · '.$uses['model'] : 'ServerOS',
            'canManage' => $canManage,
        ];
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, string $provider, AiProviders $ai): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        abort_unless(isset(AiProviders::PROVIDERS[$provider]), 404);
        $existing = $ai->key($workspace, $provider);
        $custom = $provider === 'custom';
        $data = $request->validate([
            'key' => [$existing ? 'nullable' : 'required', 'string', $custom ? 'min:1' : 'min:20', 'max:300', 'regex:/^\S+$/'],
            'model' => [$custom ? 'required' : 'nullable', 'string', 'max:80', 'regex:/^[A-Za-z0-9._:\/\[\]-]+$/'],
            'url' => $custom ? ['required', 'string', 'max:255'] : ['prohibited'],
        ], [
            'key.regex' => 'Paste the key on its own, with no spaces.',
            'model.regex' => 'That isn’t a model name.',
            'model.required' => 'Name the model to use, like deepseek-chat.',
            'url.required' => 'Add the API address, like https://openrouter.ai/api/v1.',
        ]);

        $key = trim((string) ($data['key'] ?? ''));
        $url = null;
        if ($custom) {
            $url = CompatibleEndpoint::normalise((string) $data['url']);
            if ($url === null) {
                throw ValidationException::withMessages(['url' => 'That isn’t a web address. It starts with https://.']);
            }
        }
        $moved = $custom && $existing !== null && $url !== $existing->base_url;
        if ($key !== '' || $moved) {
            try {
                $ai->verify($provider, $key !== '' ? $key : $existing->key, $url);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages([$custom && $e->getCode() !== 401 ? 'url' : 'key' => $e->getMessage()]);
            }
        }

        WorkspaceAiKey::query()->updateOrCreate(['workspace_id' => $workspace->id, 'provider' => $provider], array_filter([
            'key' => $key !== '' ? $key : null,
            // A short key, like a local Ollama's, would be shown almost whole: keep no hint.
            'hint' => $key !== '' ? (strlen($key) >= 8 ? '…'.substr($key, -4) : '') : null,
            'model' => trim((string) ($data['model'] ?? '')) ?: null,
            'base_url' => $url,
            'user_id' => $key !== '' ? $request->user()->id : null,
            'verified_at' => $key !== '' || $moved ? now() : null,
        ], fn ($v) => $v !== null) + ['model' => trim((string) ($data['model'] ?? '')) ?: null]);
        Activity::record($workspace, $existing ? 'ai.update' : 'ai.connect', AiProviders::PROVIDERS[$provider]['label'], null, $request->user());

        return back();
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, string $provider): RedirectResponse
    {
        Gate::authorize('manageSettings', $workspace);
        $request->merge(['provider' => $provider])->validate(['provider' => [Rule::in(array_keys(AiProviders::PROVIDERS))]]);
        $deleted = WorkspaceAiKey::query()->where('workspace_id', $workspace->id)->where('provider', $provider)->delete();
        if ($deleted > 0) {
            Activity::record($workspace, 'ai.disconnect', AiProviders::PROVIDERS[$provider]['label'], null, $request->user());
            Inertia::flash('toast', ['type' => 'success', 'message' => 'Removed the '.AiProviders::PROVIDERS[$provider]['label'].' key.']);
        } else {
            AuditContext::nothingChanged();
        }

        return back();
    }
}
