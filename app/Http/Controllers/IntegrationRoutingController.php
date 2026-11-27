<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Integrations\Incidents\IntegrationEvents;
use App\Support\Integrations\Incidents\RoutesIncidents;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Linear\LinearProvider;
use App\Support\Integrations\PagerDuty\PagerDutyProvider;
use App\Support\Integrations\Sentry\SentryProvider;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class IntegrationRoutingController extends Controller
{
    #[Audited]
    public function update(Request $request, Workspace $workspace, Integration $integration): RedirectResponse
    {
        $this->authorizeFor($workspace, $integration);
        $provider = $integration->provider();
        abort_unless($provider instanceof RoutesIncidents, 404);

        $data = $request->validate($provider->settingsRules($integration));
        try {
            $settings = $provider->settings($integration, $data);
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages([array_key_first($provider->settingsRules($integration)) ?? 'form' => $e->getMessage()]);
        }

        $before = $integration->settings ?? [];
        if ($settings == $before) {
            AuditContext::nothingChanged();

            return back();
        }
        $integration->forceFill(['settings' => $settings])->save();
        Activity::record($workspace, 'integration.routing', $integration->label, null, $request->user(), [
            'provider' => $provider->name(),
            'changed' => array_keys(array_filter($settings, fn ($value, $key) => ($before[$key] ?? null) != $value, ARRAY_FILTER_USE_BOTH)),
        ]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Saved. :provider gets what you picked from now on.', ['provider' => $provider->name()])]);

        return back();
    }

    public function options(Request $request, Workspace $workspace, Integration $integration): JsonResponse
    {
        $this->authorizeFor($workspace, $integration);
        $provider = $integration->provider();

        try {
            return match (true) {
                $provider instanceof LinearProvider => response()->json($provider->options($integration, $request->string('team')->trim()->toString() ?: null)),
                $provider instanceof SentryProvider => response()->json(['projects' => $provider->projects($integration)]),
                default => abort(404),
            };
        } catch (IntegrationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    #[Audited]
    public function test(Request $request, Workspace $workspace, Integration $integration, IntegrationEvents $events): RedirectResponse
    {
        $this->authorizeFor($workspace, $integration);
        $provider = $integration->provider();
        abort_unless($provider instanceof PagerDutyProvider, 404);

        $event = IntegrationEvents::queue($integration, 'test', __('Test event'), [], dispatch: false);
        $events->deliver($event);
        $event->refresh();
        if ($event->state === 'retrying') {
            $event->forceFill(['state' => 'failed', 'next_attempt_at' => null])->save();
        }
        $ok = $event->state === 'sent';

        Activity::record($workspace, 'integration.test', $integration->label, null, $request->user(), ['provider' => $provider->name(), 'ok' => $ok]);
        Inertia::flash('toast', $ok
            ? ['type' => 'success', 'message' => __('PagerDuty took the test event: it opened an alert and resolved it straight away.')]
            : ['type' => 'error', 'message' => (string) ($event->error ?? __('The test event didn’t go through.'))]);

        return back();
    }

    #[Audited]
    public function retry(Request $request, Workspace $workspace, Integration $integration, IntegrationEvent $event, IntegrationEvents $events): RedirectResponse
    {
        $this->authorizeFor($workspace, $integration);
        abort_unless($event->integration_id === $integration->id, 404);
        if ($event->state !== 'failed') {
            AuditContext::nothingChanged();

            return back();
        }

        $events->retry($event);
        Activity::record($workspace, 'integration.retry', $integration->label, null, $request->user(), [
            'provider' => $integration->provider()->name(),
            'event' => $event->subject,
        ]);
        $event->refresh();
        Inertia::flash('toast', match ($event->state) {
            'sent', 'skipped' => ['type' => 'success', 'message' => __('Sent.')],
            'failed', 'retrying' => ['type' => 'error', 'message' => (string) $event->error],
            default => ['type' => 'success', 'message' => __('Sending it again.')],
        });

        return back();
    }

    private function authorizeFor(Workspace $workspace, Integration $integration): void
    {
        abort_unless($integration->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
    }
}
