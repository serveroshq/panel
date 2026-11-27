<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\IntegrationDnsRecord;
use App\Models\IntegrationOperation;
use App\Models\IntegrationServer;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Integrations\Capability;
use App\Support\Integrations\Cloudflare\CloudflareDns;
use App\Support\Integrations\Cloudflare\CloudflareProvider;
use App\Support\Integrations\HostsRepositories;
use App\Support\Integrations\Incidents\AccountPage;
use App\Support\Integrations\IntegrationCatalogue;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\IntegrationProviders;
use App\Support\Integrations\Provider;
use App\Support\Integrations\Servers\CloudServers;
use App\Support\Integrations\Servers\Server;
use App\Support\Integrations\Servers\ServerOperations;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class IntegrationAccountController extends Controller
{
    public function show(Request $request, Workspace $workspace, Integration $integration, CloudServers $clouds): Response
    {
        abort_unless($integration->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        ServerOperations::expireLost();
        $provider = $integration->provider();
        $entry = collect(IntegrationCatalogue::all())->firstWhere('key', $provider->key());

        return Inertia::render('workspace/IntegrationShow', [
            'workspace' => $workspace->toPageProps(),
            'integration' => [
                'id' => $integration->id,
                'provider' => $provider->key(),
                'providerName' => $provider->name(),
                'logo' => $entry['logo'] ?? $provider->key(),
                'label' => $integration->label,
                'status' => $integration->status,
                'summary' => $provider->summary($integration),
                'facts' => $provider->facts($integration),
                'lastCheckedAt' => $integration->last_checked_at?->toIso8601String(),
                'lastError' => $integration->last_error,
                'createdAt' => $integration->created_at?->toIso8601String(),
                'createdBy' => $integration->creator?->name,
            ],
            'provider' => $provider->toArray(),
            'usage' => $this->usage($integration, $provider),
            'dependents' => $this->dependents($integration),
            'managesServers' => $provider->managesServers(),
            'canMakeServers' => $provider->can(Capability::CreateServers) && (bool) config('daemon.install_ready'),
            'canImport' => (bool) config('daemon.install_ready'),
            'servers' => $provider->managesServers() ? Inertia::defer(fn () => $this->servers($integration, $clouds)) : null,
            'cloudflare' => $provider instanceof CloudflareProvider ? Inertia::defer(fn () => app(CloudflareDns::class)->accountProps($integration)) : null,
            'projects' => $provider instanceof HostsRepositories ? Inertia::defer(fn () => $provider->projects($integration)) : null,
            'operations' => $integration->operations()->latest('id')->limit(8)->get()
                ->map(fn (IntegrationOperation $op) => ServerOperations::props($op))->all(),
            ...AccountPage::props($integration, $provider),
        ]);
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace, string $provider, IntegrationProviders $providers, CloudServers $clouds): RedirectResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $built = $providers->find($provider) ?? abort(404);

        $rules = ['label' => ['nullable', 'string', 'max:80']];
        $names = ['label' => __('name')];
        foreach ($built->fields() as $field) {
            $rules['credentials.'.$field->key] = $field->rules;
            $names['credentials.'.$field->key] = mb_strtolower($field->label);
        }
        $data = $request->validate($rules, [], $names);
        $credentials = array_map(fn ($value) => trim((string) $value), (array) ($data['credentials'] ?? []));
        $first = 'credentials.'.($built->fields()[0]->key ?? 'token');

        if (! $built->multipleAccounts() && $workspace->integrations()->where('provider', $provider)->exists()) {
            throw ValidationException::withMessages([$first => __(':provider is connected already. Disconnect it first to connect another account.', ['provider' => $built->name()])]);
        }
        $fingerprint = Integration::fingerprintOf($provider, $credentials);
        $same = $workspace->integrations()->where('provider', $provider)->where('fingerprint', $fingerprint)->first();
        if ($same !== null) {
            throw ValidationException::withMessages([$first => __('This account is connected already, as :label.', ['label' => $same->label])]);
        }

        try {
            $account = $built->verify($credentials);
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages([$first => $e->getMessage()]);
        }

        $integration = $workspace->integrations()->create([
            'provider' => $provider,
            'label' => $this->freeLabel($workspace, $provider, trim((string) ($data['label'] ?? '')) ?: $account->label),
            'credentials' => $credentials,
            'fingerprint' => $fingerprint,
            'meta' => $account->meta,
            'status' => Integration::CONNECTED,
            'last_checked_at' => now(),
            'created_by' => $request->user()->id,
        ]);
        Activity::record($workspace, 'integration.connect', $integration->label, null, $request->user(), ['provider' => $built->name()]);

        $linked = 0;
        if ($built->managesServers()) {
            try {
                $linked = $clouds->sync($integration)->whereNotNull('machine_id')->count();
            } catch (IntegrationFailed) {
            }
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => $linked > 0
            ? trans_choice('Connected :name and linked :count machine.|Connected :name and linked :count machines.', $linked, ['name' => $integration->label, 'count' => $linked])
            : __('Connected :name.', ['name' => $integration->label])]);

        return redirect($workspace->url('/integrations/'.$integration->id));
    }

    #[Audited]
    public function check(Request $request, Workspace $workspace, Integration $integration, CloudServers $clouds): RedirectResponse
    {
        abort_unless($integration->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $provider = $integration->provider();
        $was = $integration->status;

        try {
            $account = $provider->verify($integration->credentials);
            $integration->forceFill([
                'meta' => [...($integration->meta ?? []), ...$account->meta],
                'status' => Integration::CONNECTED,
                'last_checked_at' => now(),
                'last_error' => null,
            ])->save();
            if ($provider->managesServers()) {
                $clouds->sync($integration);
            }
            $ok = true;
        } catch (IntegrationFailed $e) {
            CloudServers::failed($integration, $e);
            $ok = false;
        }

        if ($integration->status !== $was) {
            Activity::record($workspace, 'integration.check', $integration->label, null, $request->user(), ['provider' => $provider->name(), 'ok' => $ok]);
        } else {
            AuditContext::nothingChanged();
        }
        Inertia::flash('toast', $ok
            ? ['type' => 'success', 'message' => __(':name works.', ['name' => $integration->label])]
            : ['type' => 'error', 'message' => (string) $integration->last_error]);

        return back();
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, Integration $integration): RedirectResponse
    {
        abort_unless($integration->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate(['label' => ['required', 'string', 'max:80']], [], ['label' => __('name')]);
        $label = trim($data['label']);
        $from = $integration->label;

        if ($label === $from) {
            AuditContext::nothingChanged();

            return back();
        }
        if ($workspace->integrations()->where('provider', $integration->provider)->where('label', $label)->whereKeyNot($integration->id)->exists()) {
            throw ValidationException::withMessages(['label' => __('Another account already has that name.')]);
        }

        $integration->forceFill(['label' => $label])->save();
        Activity::record($workspace, 'integration.rename', $label, null, $request->user(), ['from' => $from, 'provider' => $integration->provider()->name()]);

        return back();
    }

    #[Audited]
    public function destroy(Request $request, Workspace $workspace, Integration $integration): RedirectResponse
    {
        abort_unless($integration->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        if ($this->dependents($integration) !== [] && $request->string('confirm')->trim()->toString() !== $integration->label) {
            throw ValidationException::withMessages(['confirm' => __('Type :name to disconnect it.', ['name' => $integration->label])]);
        }

        $provider = $integration->provider();
        $machines = $integration->servers()->whereNotNull('machine_id')->count();
        Activity::record($workspace, 'integration.disconnect', $integration->label, null, $request->user(), ['provider' => $provider->name(), 'machines' => $machines]);
        $done = $provider->disconnect($integration);
        $integration->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => $done ?? __('Disconnected :name. Nothing changed at :provider.', ['name' => $integration->label, 'provider' => $provider->name()])]);

        return redirect($workspace->url('/integrations'));
    }

    /** @return array{list: list<array<string, mixed>>, error: string|null} */
    private function servers(Integration $integration, CloudServers $clouds): array
    {
        $error = null;
        try {
            $rows = $clouds->sync($integration);
        } catch (IntegrationFailed $e) {
            $error = $e->getMessage();
            $rows = $integration->servers()->with('machine:id,name,provider,icon_path,last_seen_at,disconnected_at')->orderBy('name')->get();
        }
        $making = $integration->operations()->where('kind', ServerOperations::CREATE)->whereIn('state', IntegrationOperation::ACTIVE)->pluck('server_id')->all();

        return [
            'list' => array_values($rows->map(fn (IntegrationServer $row) => [
                'id' => $row->id,
                ...Server::fromArray($row->details ?? [])->toArray(),
                'machine' => $row->machine === null ? null : [
                    'id' => $row->machine->id,
                    'name' => $row->machine->name,
                    'href' => '/machines/'.$row->machine->id,
                ],
                'joining' => $row->machine === null && in_array($row->server_id, $making, true),
            ])->all()),
            'error' => $error,
        ];
    }

    /** @return list<string> */
    private function usage(Integration $integration, Provider $provider): array
    {
        if ($provider instanceof HostsRepositories) {
            return $provider->usage($integration);
        }
        if (! $provider->managesServers()) {
            return [];
        }
        $linked = $integration->servers()->whereNotNull('machine_id')->with('machine:id,name')->get()->pluck('machine.name')->filter()->sort()->values();
        $made = $integration->operations()->where('kind', ServerOperations::CREATE)->where('state', 'done')->count();
        $out = [];
        $out[] = match ($linked->count()) {
            0 => __('No machine is linked to a server here yet.'),
            1 => __(':machine is linked to its server here.', ['machine' => $linked->first()]),
            2, 3 => __(':machines are linked to their servers here.', ['machines' => $linked->implode(', ')]),
            default => __(':count machines are linked to their servers here.', ['count' => $linked->count()]),
        };
        if ($linked->isNotEmpty()) {
            $out[] = __('On those machines you can resize, restart, power cycle, take snapshots and delete the server from ServerOS.');
        }
        if ($made > 0) {
            $out[] = trans_choice('ServerOS made :count server here.|ServerOS made :count servers here.', $made, ['count' => $made]);
        }

        return array_map('strval', $out);
    }

    /** @return list<string> */
    private function dependents(Integration $integration): array
    {
        $linked = $integration->servers()->whereNotNull('machine_id')->count();
        $active = $integration->operations()->whereIn('state', IntegrationOperation::ACTIVE)->count();
        $out = [];
        if ($linked > 0) {
            $out[] = (string) trans_choice(':count machine loses resizing, restarts and snapshots from ServerOS.|:count machines lose resizing, restarts and snapshots from ServerOS.', $linked, ['count' => $linked]);
        }
        if ($active > 0) {
            $out[] = (string) trans_choice(':count change in progress stops being followed.|:count changes in progress stop being followed.', $active, ['count' => $active]);
        }
        $host = $integration->provider();
        if ($host instanceof HostsRepositories) {
            array_push($out, ...$host->dependents($integration));
        }
        $records = IntegrationDnsRecord::query()->where('integration_id', $integration->id)->count();
        if ($records > 0) {
            $out[] = (string) trans_choice(':count DNS record stops being kept pointed at its machine. It stays at Cloudflare.|:count DNS records stop being kept pointed at their machines. They stay at Cloudflare.', $records, ['count' => $records]);
        }

        return $out;
    }

    private function freeLabel(Workspace $workspace, string $provider, string $label): string
    {
        $label = mb_substr($label, 0, 76);
        $taken = $workspace->integrations()->where('provider', $provider)->pluck('label')->all();
        $candidate = $label;
        for ($n = 2; in_array($candidate, $taken, true); $n++) {
            $candidate = "{$label} {$n}";
        }

        return $candidate;
    }
}
