<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\IntegrationApp;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Sentry\SentryProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class AppSentryController extends Controller
{
    #[Audited]
    public function update(Request $request, Workspace $workspace, Machine $machine, string $service): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        abort_unless($machine->workspace_id === $workspace->id, 404);
        abort_if(AppVersionController::lastDeployOf($machine, $service) === null, 404, 'This service wasn’t deployed from code.');

        $data = $request->validate([
            'integration' => ['nullable', 'integer', Rule::exists('integrations', 'id')->where('workspace_id', $workspace->id)->where('provider', 'sentry')],
            'project' => ['required_with:integration', 'nullable', 'string', 'max:100', 'regex:'.SentryProvider::SLUG],
            'environment' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9_.-]+$/'],
        ], ['integration.exists' => __('That Sentry account isn’t connected to this workspace.')]);

        $links = IntegrationApp::query()->where('machine_id', $machine->id)->where('service', $service)
            ->whereHas('integration', fn ($q) => $q->where('provider', 'sentry'))->with('integration')->get();
        $before = $links->first();

        if (($data['integration'] ?? null) === null) {
            if ($before === null) {
                AuditContext::nothingChanged();

                return response()->json(['ok' => true]);
            }
            IntegrationApp::query()->whereKey($links->pluck('id'))->delete();
            Activity::record($workspace, 'app.sentry_off', $service, $machine, $request->user(), ['project' => $before->setting('name') ?? $before->setting('project')]);

            return response()->json(['ok' => true]);
        }

        $integration = Integration::query()->findOrFail((int) $data['integration']);
        $provider = $integration->provider();
        abort_unless($provider instanceof SentryProvider, 404);
        try {
            $project = collect($provider->projects($integration))->firstWhere('slug', $data['project']);
        } catch (IntegrationFailed $e) {
            return response()->json(['errors' => ['project' => [$e->getMessage()]]], 422);
        }
        if ($project === null) {
            return response()->json(['errors' => ['project' => [__('That project isn’t in :name.', ['name' => $integration->label])]]], 422);
        }

        $settings = [
            'project' => $project['slug'],
            'project_id' => $project['id'],
            'name' => $project['name'],
            'environment' => filled($data['environment'] ?? null) ? (string) $data['environment'] : $machine->environment->value,
        ];
        if ($before !== null && $before->integration_id === $integration->id && $before->settings == $settings && $links->count() === 1) {
            AuditContext::nothingChanged();

            return response()->json(['ok' => true]);
        }
        IntegrationApp::query()->whereKey($links->pluck('id'))->where('integration_id', '!=', $integration->id)->delete();
        IntegrationApp::query()->updateOrCreate(
            ['integration_id' => $integration->id, 'machine_id' => $machine->id, 'service' => $service],
            ['settings' => $settings],
        );
        Activity::record($workspace, 'app.sentry', $service, $machine, $request->user(), ['project' => $project['name'], 'account' => $integration->label]);

        return response()->json(['ok' => true]);
    }
}
