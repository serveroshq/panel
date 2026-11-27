<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\IntegrationDnsRecord;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Deploys\DeployRequest;
use App\Support\Domains\DomainCheck;
use App\Support\Integrations\Cloudflare\CloudflareDns;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * An app's domains at a connected Cloudflare account: point one at its
 * machine now, choose whether it's proxied, and purge the cache.
 */
class CloudflareDomainController extends Controller
{
    #[Audited]
    public function point(Request $request, Workspace $workspace, Machine $machine, CloudflareDns $dns, DomainCheck $check): JsonResponse
    {
        $this->authorizeFor($workspace, $machine);
        if (is_string($domain = $request->input('domain'))) {
            $request->merge(['domain' => DeployRequest::bareDomain($domain)]);
        }
        $data = $request->validate([
            'domain' => ['required', 'string', 'max:253', 'regex:'.DeployRequest::DOMAIN_PATTERN],
            'wildcard' => ['sometimes', 'boolean'],
            'service' => ['required', 'string', 'max:63', 'regex:'.DeployRequest::SERVICE_PATTERN],
            'proxied' => ['sometimes', 'nullable', 'boolean'],
        ], ['domain.regex' => __('Enter a domain like app.example.com.')]);

        $hostnames = DeployRequest::domains($data['domain'], (bool) ($data['wildcard'] ?? false));
        if ($dns->zoneFor($workspace->id, $data['domain']) === null) {
            throw ValidationException::withMessages(['domain' => __(':domain isn’t in a connected Cloudflare account.', ['domain' => $data['domain']])]);
        }

        $proxied = array_key_exists('proxied', $data) && $data['proxied'] !== null ? (bool) $data['proxied'] : null;
        $rows = $dns->point($machine, $data['service'], $hostnames, $proxied, $request->user());
        if (! AuditContext::recorded()) {
            AuditContext::nothingChanged();
        }

        $failed = collect($rows)->firstWhere('status', IntegrationDnsRecord::FAILED);
        $result = [...$check->check($machine, $data['domain']), 'cloudflare' => $dns->checkInfo($machine, $data['domain'])];
        if ($data['wildcard'] ?? false) {
            $result['wildcard'] = [...$check->checkWildcard($machine, $data['domain']), 'cloudflare' => $dns->checkInfo($machine, '*.'.$data['domain'])];
        }

        return response()->json([...$result, 'error' => $failed?->error], $failed === null ? 200 : 422);
    }

    #[Audited]
    public function proxied(Request $request, Workspace $workspace, Machine $machine, string $service, CloudflareDns $dns): JsonResponse
    {
        $this->authorizeFor($workspace, $machine);
        $data = $request->validate([
            'hostname' => ['required', 'string', 'max:253'],
            'proxied' => ['required', 'boolean'],
        ]);
        $row = $dns->rowsOf($machine, $service)->firstWhere('hostname', strtolower($data['hostname'])) ?? abort(404);
        $proxied = (bool) $data['proxied'];

        if ($row->proxied === $proxied) {
            AuditContext::nothingChanged();

            return response()->json(['record' => CloudflareDns::props($row, $machine, $service)]);
        }
        try {
            $dns->setProxied($row, $proxied);
        } catch (IntegrationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
        Activity::record($workspace, 'app.dns_proxied', $service, $machine, $request->user(), ['domain' => $row->hostname, 'proxied' => $proxied, 'provider' => 'Cloudflare']);

        return response()->json(['record' => CloudflareDns::props($row, $machine, $service)]);
    }

    #[Audited]
    public function purge(Request $request, Workspace $workspace, Machine $machine, string $service, CloudflareDns $dns): JsonResponse
    {
        $this->authorizeFor($workspace, $machine);
        $domains = $this->servedDomains($machine, $service);

        $result = $dns->purge($machine, $service, $domains);
        if ($result['hosts'] === []) {
            AuditContext::nothingChanged();

            return response()->json(['error' => $result['error']], 422);
        }
        cache()->put(CloudflareDns::purgeKey($machine, $service), [...$result, 'at' => now()->toIso8601String(), 'auto' => false], now()->addDays(30));
        Activity::record($workspace, 'app.cache_purge', $service, $machine, $request->user(), ['hosts' => $result['hosts'], 'provider' => 'Cloudflare']);

        return response()->json([...$result, 'lastPurge' => CloudflareDns::lastPurge($machine, $service)], $result['error'] === null ? 200 : 207);
    }

    #[Audited]
    public function purgeSetting(Request $request, Workspace $workspace, Machine $machine, string $service): JsonResponse
    {
        $this->authorizeFor($workspace, $machine);
        abort_if($this->servedDomains($machine, $service) === [], 404, 'This app has no domain.');
        $on = (bool) $request->validate(['on' => ['required', 'boolean']])['on'];

        if (CloudflareDns::purgesAfterDeploy($machine, $service) === $on) {
            AuditContext::nothingChanged();

            return response()->json(['purgeAfterDeploy' => $on]);
        }
        $settings = $machine->app_settings ?? [];
        $settings[$service] = [...($settings[$service] ?? []), 'purge_cloudflare' => $on];
        $machine->forceFill(['app_settings' => $settings])->save();
        Activity::record($workspace, 'app.cache_purge_setting', $service, $machine, $request->user(), ['on' => $on, 'provider' => 'Cloudflare']);

        return response()->json(['purgeAfterDeploy' => $on]);
    }

    private function authorizeFor(Workspace $workspace, Machine $machine): void
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
    }

    /** @return list<string> */
    private function servedDomains(Machine $machine, string $service): array
    {
        $deploy = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->where('service', $service)->where('state', 'succeeded')->latest()->first();

        return array_values(array_filter((array) ($deploy?->command['job']['domains'] ?? []), 'is_string'));
    }
}
