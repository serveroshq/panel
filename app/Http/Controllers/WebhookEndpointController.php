<?php

namespace App\Http\Controllers;

use App\Enums\WorkspacePermission;
use App\Models\Activity;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Webhooks\WebhookEndpoints;
use App\Support\Webhooks\Webhooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/** Settings › API › Webhooks: the same endpoints the API's webhook tools manage. */
class WebhookEndpointController extends Controller
{
    public function __construct(private readonly WebhookEndpoints $endpoints) {}

    #[Audited]
    public function store(Request $request, Workspace $workspace): JsonResponse
    {
        $this->authorizeChange($request, $workspace);
        [$endpoint, $secret] = $this->endpoints->create($workspace, $request->user(), $request->only(['url', 'events', 'description']));
        Activity::record($workspace, 'api.webhook_create', self::host($endpoint), meta: ['endpoint' => $endpoint->id, 'events' => $endpoint->events ?? 'all']);

        return response()->json(['id' => $endpoint->id, 'secret' => $secret], 201);
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, WebhookEndpoint $webhook): RedirectResponse
    {
        $this->authorizeChange($request, $workspace, $webhook);
        $this->endpoints->update($webhook, $request->only(['url', 'events', 'description', 'enabled']));
        Activity::record($workspace, 'api.webhook_update', self::host($webhook), meta: ['endpoint' => $webhook->id, 'enabled' => $webhook->enabled]);

        return back();
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, WebhookEndpoint $webhook): RedirectResponse
    {
        $this->authorizeChange($request, $workspace, $webhook);
        $webhook->delete();
        Activity::record($workspace, 'api.webhook_delete', self::host($webhook), meta: ['endpoint' => $webhook->id]);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Removed the webhook to :host.', ['host' => self::host($webhook)])]);

        return back();
    }

    #[Audited]
    public function test(Request $request, Workspace $workspace, WebhookEndpoint $webhook, Webhooks $webhooks): JsonResponse
    {
        $this->authorizeChange($request, $workspace, $webhook);
        $delivery = $webhooks->test($webhook);
        AuditContext::nothingChanged();

        return response()->json(['delivered' => $delivery->state === 'delivered', 'delivery' => WebhookEndpoints::delivery($delivery)]);
    }

    private function authorizeChange(Request $request, Workspace $workspace, ?WebhookEndpoint $webhook = null): void
    {
        abort_unless($webhook === null || $webhook->workspace_id === $workspace->id, 404);
        abort_unless($workspace->allows($request->user(), WorkspacePermission::ManageMachines), 403);
    }

    private static function host(WebhookEndpoint $endpoint): string
    {
        return (string) parse_url($endpoint->url, PHP_URL_HOST);
    }
}
