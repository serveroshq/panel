<?php

namespace App\Support\Integrations\Cloudflare;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Integration;
use App\Models\IntegrationDnsRecord;
use App\Models\Machine;
use App\Models\User;
use App\Support\Deploys\DeployRequest;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\IntegrationProviders;
use App\Support\Machines\CloudflareTunnel;
use App\Support\Projects\Placement;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * DNS on autopilot: an app's domains whose zone is in a connected Cloudflare
 * account point at its machine, and the records ServerOS made go again when
 * the app lets go of them. Records it didn't make are never deleted.
 *
 * @phpstan-import-type Zone from CloudflareProvider
 *
 * @phpstan-type RecordProps array{hostname: string, zone: string, type: string|null, content: string|null, proxied: bool, forced: bool, status: string, error: string|null, warning: string|null, created: bool, here: bool, app: string, machine: string|null, machineId: int, syncedAt: string|null}
 * @phpstan-type Match array{integration: Integration, zone: Zone}
 */
final class CloudflareDns
{
    public const string PROVIDER = 'cloudflare';

    public const string COMMENT = 'Managed by ServerOS';

    private const array KINDS = ['A', 'AAAA', 'CNAME'];

    private const int PURGE_CHUNK = 30;

    /** @var array<int, Collection<int, Integration>> */
    private array $accounts = [];

    /** @var array<int, list<Zone>> */
    private array $zones = [];

    /** @return Collection<int, Integration> */
    public function accounts(int $workspaceId): Collection
    {
        return $this->accounts[$workspaceId] ??= Integration::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider', self::PROVIDER)
            ->where('status', Integration::CONNECTED)
            ->orderBy('id')
            ->get();
    }

    public function connected(int $workspaceId): bool
    {
        return $this->accounts($workspaceId)->isNotEmpty();
    }

    /**
     * The connected zone a hostname is in, the longest name that matches
     * across the workspace's accounts. *.example.com matches example.com.
     *
     * @return Match|null
     */
    public function zoneFor(int $workspaceId, string $hostname): ?array
    {
        $name = self::bare($hostname);
        $best = null;

        foreach ($this->accounts($workspaceId) as $integration) {
            foreach ($this->zonesOf($integration) as $zone) {
                if ($zone['status'] !== 'active' || ($name !== $zone['name'] && ! str_ends_with($name, '.'.$zone['name']))) {
                    continue;
                }
                if ($best === null || strlen($zone['name']) > strlen($best['zone']['name'])) {
                    $best = ['integration' => $integration, 'zone' => $zone];
                }
            }
        }

        return $best;
    }

    /**
     * Where an app's records point: the machine's public address, or its
     * Cloudflare Tunnel (a CNAME that has to be proxied).
     *
     * @return array{0: string, 1: string, 2: bool}|null type, content, and whether it must be proxied
     */
    public static function target(Machine $machine): ?array
    {
        if (CloudflareTunnel::active($machine) && ($tunnel = CloudflareTunnel::target($machine)) !== null) {
            return ['CNAME', $tunnel, true];
        }
        $ip = (string) $machine->ip_address;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || IpUtils::checkIp($ip, ['100.64.0.0/10', 'fc00::/7', 'fe80::/10'])) {
            return null;
        }

        return [str_contains($ip, ':') ? 'AAAA' : 'A', $ip, false];
    }

    /**
     * Points each hostname whose zone is in a connected account at the
     * machine. Hostnames in no connected zone are left alone.
     *
     * @param  array<int, mixed>  $domains
     * @return list<IntegrationDnsRecord>
     */
    public function point(Machine $machine, string $service, array $domains, ?bool $proxied = null, ?User $user = null): array
    {
        $rows = [];
        foreach (self::hostnames($domains) as $hostname) {
            $match = $this->zoneFor($machine->workspace_id, $hostname);
            if ($match !== null) {
                $rows[] = $this->locked($match['integration'], $hostname, fn () => $this->pointOne($machine, $service, $hostname, $match, $proxied, $user));
            }
        }

        return $rows;
    }

    /**
     * Lets go of the app's records for hostnames it no longer serves.
     *
     * @param  array<int, mixed>  $keep
     */
    public function prune(Machine $machine, string $service, array $keep): void
    {
        $keep = self::hostnames($keep);
        $this->rowsOf($machine, $service)
            ->reject(fn (IntegrationDnsRecord $row) => in_array($row->hostname, $keep, true))
            ->each(fn (IntegrationDnsRecord $row) => $this->remove($row));
    }

    /** The app is gone: lets go of every record it had. */
    public function forget(Machine $machine, string $service): void
    {
        $this->rowsOf($machine, $service)->each(fn (IntegrationDnsRecord $row) => $this->remove($row));
    }

    /**
     * A deploy or a removal finished on the machine: records follow what
     * the app serves now, and a deploy that worked purges the cache when
     * the app asks for it.
     */
    public function jobFinished(DaemonJob $job): void
    {
        $machine = $job->machine;
        $service = $job->service;
        if (! is_string($service) || ! $this->connected($machine->workspace_id)) {
            return;
        }

        if ($job->kind === 'service_remove') {
            if ($job->state === 'succeeded') {
                $this->forgetRemovedApps($machine->fresh() ?? $machine);
            }

            return;
        }
        if ($job->kind !== 'deploy') {
            return;
        }

        $succeeded = $job->state === 'succeeded';
        if ($succeeded) {
            $this->point($machine, $service, (array) ($job->command['job']['domains'] ?? []));
        }
        $this->prune($machine, $service, $this->liveDomains($machine, $service));

        if ($succeeded && self::purgesAfterDeploy($machine, $service)) {
            $result = $this->purge($machine, $service, (array) ($job->command['job']['domains'] ?? []));
            Cache::put(self::purgeKey($machine, $service), [...$result, 'at' => now()->toIso8601String(), 'auto' => true], now()->addDays(30));
        }
    }

    /**
     * A service went from the machine: apps with records here that have no
     * container left (and no deploy on its way) let go of them.
     */
    public function forgetRemovedApps(Machine $machine): void
    {
        if (! is_array($machine->inventory['services'] ?? null)) {
            return;
        }
        $apps = IntegrationDnsRecord::query()->where('machine_id', $machine->id)->distinct()->pluck('service');
        foreach ($apps as $app) {
            $deploying = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->where('service', $app)
                ->whereIn('state', ['queued', 'running'])->get()->contains(fn (DaemonJob $job) => ! $job->isStale());
            if (! $deploying && Placement::containers($machine, $app) === []) {
                $this->forget($machine, $app);
            }
        }
    }

    /**
     * Turns Cloudflare's proxy on or off for one of ServerOS's records.
     *
     * @throws IntegrationFailed
     */
    public function setProxied(IntegrationDnsRecord $row, bool $proxied): void
    {
        if ($row->record_id === null || $row->status === IntegrationDnsRecord::FAILED) {
            throw new IntegrationFailed(self::t('Point it here first, then choose whether it’s proxied.'));
        }
        if (! $proxied && self::forced($row)) {
            throw new IntegrationFailed(self::t('Records for a Cloudflare Tunnel have to be proxied.'));
        }

        $this->client($row->integration)->patch($this->recordPath($row->zone_id, $row->record_id), ['proxied' => $proxied]);
        $row->forceFill(['proxied' => $proxied, 'synced_at' => now()])->save();
    }

    /**
     * Purges the app's hostnames from Cloudflare's cache, zone by zone.
     * Wildcards can't be purged by name, so only plain hostnames go.
     *
     * @param  array<int, mixed>  $domains
     * @return array{hosts: list<string>, error: string|null}
     */
    public function purge(Machine $machine, string $service, array $domains): array
    {
        $byZone = [];
        foreach (DeployRequest::plainDomains(self::hostnames($domains)) as $hostname) {
            $match = $this->zoneFor($machine->workspace_id, $hostname);
            if ($match !== null) {
                $byZone[$match['zone']['id']] ??= ['integration' => $match['integration'], 'hosts' => []];
                $byZone[$match['zone']['id']]['hosts'][] = $hostname;
            }
        }
        if ($byZone === []) {
            return ['hosts' => [], 'error' => self::t('None of :app’s domains are in a connected Cloudflare account.', ['app' => $service])];
        }

        $purged = [];
        $error = null;
        foreach ($byZone as $zoneId => ['integration' => $integration, 'hosts' => $hosts]) {
            try {
                foreach (array_chunk($hosts, self::PURGE_CHUNK) as $chunk) {
                    $this->client($integration)->post('zones/'.rawurlencode((string) $zoneId).'/purge_cache', ['hosts' => $chunk]);
                    $purged = [...$purged, ...$chunk];
                }
            } catch (IntegrationFailed $e) {
                $this->failed($integration, $e);
                $error = $e->getMessage();
            }
        }

        return ['hosts' => $purged, 'error' => $error];
    }

    public static function purgesAfterDeploy(Machine $machine, string $service): bool
    {
        return (bool) ($machine->app_settings[$service]['purge_cloudflare'] ?? false);
    }

    /** @return array{hosts: list<string>, error: string|null, at: string, auto: bool}|null */
    public static function lastPurge(Machine $machine, string $service): ?array
    {
        $last = Cache::get(self::purgeKey($machine, $service));
        if (! is_array($last) || ! is_string($last['at'] ?? null)) {
            return null;
        }

        return [
            'hosts' => array_values(array_filter((array) ($last['hosts'] ?? []), 'is_string')),
            'error' => is_string($last['error'] ?? null) ? $last['error'] : null,
            'at' => $last['at'],
            'auto' => (bool) ($last['auto'] ?? false),
        ];
    }

    public static function purgeKey(Machine $machine, string $service): string
    {
        return "cloudflare:purge:{$machine->id}:{$service}";
    }

    /**
     * What the domain check adds when the hostname's zone is in a connected
     * account: whose zone it is, and the record ServerOS keeps there.
     *
     * @return array{account: string, zone: string, canPoint: bool, forced: bool, record: RecordProps|null}|null
     */
    public function checkInfo(Machine $machine, string $hostname): ?array
    {
        if (! $this->connected($machine->workspace_id)) {
            return null;
        }
        $match = $this->zoneFor($machine->workspace_id, $hostname);
        if ($match === null) {
            return null;
        }
        $row = IntegrationDnsRecord::query()->where('integration_id', $match['integration']->id)->where('hostname', strtolower($hostname))->with('machine:id,name')->first();
        $target = self::target($machine);

        return [
            'account' => $match['integration']->label,
            'zone' => $match['zone']['name'],
            'canPoint' => $target !== null,
            'forced' => $target !== null && $target[2],
            'record' => $row === null ? null : self::props($row, $machine),
        ];
    }

    /**
     * The app page's view of its domains at Cloudflare.
     *
     * @param  array<int, mixed>  $domains
     * @return array{domains: list<array{hostname: string, account: string|null, zone: string|null, record: RecordProps|null}>, canPurge: bool, purgeAfterDeploy: bool, lastPurge: array{hosts: list<string>, error: string|null, at: string, auto: bool}|null, canPoint: bool, forced: bool}|null
     */
    public function appProps(Machine $machine, string $service, array $domains): ?array
    {
        if (! $this->connected($machine->workspace_id)) {
            return null;
        }
        $rows = $this->rowsOf($machine, $service)->keyBy('hostname');
        $out = [];
        foreach (self::hostnames($domains) as $hostname) {
            $match = $this->zoneFor($machine->workspace_id, $hostname);
            $row = $rows->get($hostname);
            if ($row === null && $match !== null) {
                $row = IntegrationDnsRecord::query()->where('integration_id', $match['integration']->id)->where('hostname', $hostname)->with('machine:id,name')->first();
            }
            $out[] = [
                'hostname' => $hostname,
                'account' => $match['integration']->label ?? null,
                'zone' => $match['zone']['name'] ?? null,
                'record' => $row === null ? null : self::props($row, $machine, $service),
            ];
        }
        $target = self::target($machine);

        return [
            'domains' => $out,
            'canPurge' => collect($out)->contains(fn (array $d) => $d['zone'] !== null && ! str_starts_with($d['hostname'], '*.')),
            'purgeAfterDeploy' => self::purgesAfterDeploy($machine, $service),
            'lastPurge' => self::lastPurge($machine, $service),
            'canPoint' => $target !== null,
            'forced' => $target !== null && $target[2],
        ];
    }

    /**
     * The account page: its zones with how many records ServerOS keeps in
     * each, and those records.
     *
     * @return array{zones: list<array{id: string, name: string, status: string, managed: int}>, records: list<RecordProps>, error: string|null}
     */
    public function accountProps(Integration $integration, bool $fresh = false): array
    {
        $error = null;
        try {
            $zones = $this->provider()->zones($integration, $fresh);
        } catch (IntegrationFailed $e) {
            $this->failed($integration, $e);
            $error = $e->getMessage();
            $zones = [];
        }
        $rows = IntegrationDnsRecord::query()->where('integration_id', $integration->id)->with('machine:id,name')->orderBy('zone_name')->orderBy('hostname')->get();
        $counts = $rows->countBy('zone_id');

        return [
            'zones' => array_map(fn (array $zone) => [
                'id' => $zone['id'],
                'name' => $zone['name'],
                'status' => $zone['status'],
                'managed' => (int) ($counts[$zone['id']] ?? 0),
            ], $zones),
            'records' => array_values($rows->map(fn (IntegrationDnsRecord $row) => self::props($row))->all()),
            'error' => $error,
        ];
    }

    /** @return RecordProps */
    public static function props(IntegrationDnsRecord $row, ?Machine $machine = null, ?string $service = null): array
    {
        return [
            'hostname' => $row->hostname,
            'zone' => $row->zone_name,
            'type' => $row->type,
            'content' => $row->content,
            'proxied' => $row->proxied,
            'forced' => self::forced($row),
            'status' => $row->status,
            'error' => $row->error,
            'warning' => $row->warning,
            'created' => $row->created_record,
            'here' => $machine === null || ($row->machine_id === $machine->id && ($service === null || $row->service === $service)),
            'app' => $row->service,
            'machine' => $row->machine->name,
            'machineId' => $row->machine_id,
            'syncedAt' => $row->synced_at?->toIso8601String(),
        ];
    }

    /** @return Collection<int, IntegrationDnsRecord> */
    public function rowsOf(Machine $machine, string $service): Collection
    {
        return IntegrationDnsRecord::query()
            ->where('machine_id', $machine->id)
            ->where('service', $service)
            ->whereIn('integration_id', $this->accounts($machine->workspace_id)->pluck('id'))
            ->with(['integration', 'machine:id,name'])
            ->orderBy('hostname')
            ->get();
    }

    /**
     * @param  Match  $match
     */
    private function pointOne(Machine $machine, string $service, string $hostname, array $match, ?bool $proxied, ?User $user): IntegrationDnsRecord
    {
        $integration = $match['integration'];
        $zone = $match['zone'];
        $row = IntegrationDnsRecord::query()->firstOrNew(['integration_id' => $integration->id, 'hostname' => $hostname]);
        $row->forceFill(['machine_id' => $machine->id, 'service' => $service, 'zone_id' => $zone['id'], 'zone_name' => $zone['name']]);

        $target = self::target($machine);
        if ($target === null) {
            $row->forceFill(['status' => IntegrationDnsRecord::FAILED, 'error' => self::t('ServerOS doesn’t know a public address for :machine to point :host at.', ['machine' => $machine->name, 'host' => $hostname]), 'synced_at' => now()])->save();

            return $row;
        }
        [$type, $content, $forced] = $target;
        $choice = $forced ? true : $proxied;

        if ($row->exists && $row->status === IntegrationDnsRecord::ACTIVE && $row->record_id !== null
            && $row->type === $type && $row->content === $content && ($choice === null || $choice === $row->proxied)) {
            $row->save();

            return $row;
        }

        try {
            $client = $this->client($integration);
            $records = array_values(array_filter(
                $client->all('zones/'.rawurlencode($zone['id']).'/dns_records', ['name.exact' => $hostname]),
                fn (array $r) => in_array($r['type'] ?? null, self::KINDS, true) && strtolower((string) ($r['name'] ?? '')) === $hostname,
            ));
            $changed = $this->upsert($client, $row, $records, $type, $content, $choice);
            $row->forceFill([
                'type' => $type,
                'content' => $content,
                'status' => IntegrationDnsRecord::ACTIVE,
                'error' => null,
                'warning' => self::strayWarning($records, $row, $type, $hostname),
                'synced_at' => now(),
            ])->save();
        } catch (IntegrationFailed $e) {
            $this->failed($integration, $e);
            $row->forceFill(['status' => IntegrationDnsRecord::FAILED, 'error' => $e->getMessage(), 'synced_at' => now()])->save();

            return $row;
        }

        if ($changed !== null) {
            $meta = ['domain' => $hostname, 'type' => $type, 'content' => $content, 'proxied' => $row->proxied, 'provider' => 'Cloudflare', 'change' => $changed];
            $user === null
                ? Activity::system($machine->workspace_id, 'app.dns_point', $service, $machine, $meta)
                : Activity::record($machine->workspace_id, 'app.dns_point', $service, $machine, $user, $meta);
        }

        return $row;
    }

    /**
     * Makes the record right at Cloudflare. Returns what changed there
     * (created, updated, took_over), or null when it already was.
     *
     * @param  list<array<string, mixed>>  $records
     *
     * @throws IntegrationFailed
     */
    private function upsert(CloudflareClient $client, IntegrationDnsRecord $row, array $records, string $type, string $content, ?bool $choice): ?string
    {
        $ours = $row->record_id === null ? null : collect($records)->firstWhere('id', $row->record_id);

        if ($ours !== null && ($ours['type'] ?? null) !== $type) {
            if (! $row->created_record) {
                throw new IntegrationFailed(self::t('The :type record for :host at Cloudflare needs to be a :want record now. Delete it there, then try again.', ['type' => (string) $ours['type'], 'host' => $row->hostname, 'want' => $type]), IntegrationFailed::CONFLICT);
            }
            $client->delete($this->recordPath($row->zone_id, (string) $ours['id']));
            $records = array_values(array_filter($records, fn (array $r) => ($r['id'] ?? null) !== $ours['id']));
            $ours = null;
            $row->record_id = null;
        }

        if ($ours !== null) {
            $proxied = $choice ?? $row->proxied;
            if (($ours['content'] ?? null) === $content && (bool) ($ours['proxied'] ?? false) === $proxied) {
                $row->proxied = $proxied;

                return null;
            }
            $client->patch($this->recordPath($row->zone_id, (string) $ours['id']), ['content' => $content, 'proxied' => $proxied]);
            $row->proxied = $proxied;

            return 'updated';
        }

        $clash = array_values(array_filter($records, fn (array $r) => $type === 'CNAME' ? $r['type'] !== 'CNAME' : $r['type'] === 'CNAME'));
        if ($clash !== []) {
            throw new IntegrationFailed(self::t('There’s already a :type record for :host at Cloudflare. Delete it there, then try again.', ['type' => (string) $clash[0]['type'], 'host' => $row->hostname]), IntegrationFailed::CONFLICT);
        }

        $same = array_values(array_filter($records, fn (array $r) => $r['type'] === $type));
        if ($same === []) {
            $proxied = $choice ?? ($row->exists ? $row->proxied : false);
            $made = (array) ($client->post('zones/'.rawurlencode($row->zone_id).'/dns_records', [
                'type' => $type,
                'name' => $row->hostname,
                'content' => $content,
                'ttl' => 1,
                'proxied' => $proxied,
                'comment' => self::COMMENT,
            ])['result'] ?? []);
            if (! is_string($made['id'] ?? null)) {
                throw new IntegrationFailed(self::t('Cloudflare didn’t say which record it made. Check the zone in Cloudflare before trying again.'));
            }
            $row->forceFill(['record_id' => $made['id'], 'created_record' => true, 'proxied' => $proxied]);

            return 'created';
        }

        $already = collect($same)->firstWhere('content', $content);
        if ($already !== null) {
            $proxied = $choice ?? (bool) ($already['proxied'] ?? false);
            $row->forceFill(['record_id' => (string) $already['id'], 'created_record' => false, 'proxied' => $proxied]);
            if ((bool) ($already['proxied'] ?? false) === $proxied) {
                return null;
            }
            $client->patch($this->recordPath($row->zone_id, (string) $already['id']), ['proxied' => $proxied]);

            return 'updated';
        }
        if (count($same) > 1) {
            throw new IntegrationFailed(self::t('There are :count :type records for :host at Cloudflare. Keep one there, then try again.', ['count' => count($same), 'type' => $type, 'host' => $row->hostname]), IntegrationFailed::CONFLICT);
        }

        $proxied = $choice ?? ($row->exists ? $row->proxied : false);
        $client->patch($this->recordPath($row->zone_id, (string) $same[0]['id']), ['content' => $content, 'proxied' => $proxied]);
        $row->forceFill(['record_id' => (string) $same[0]['id'], 'created_record' => false, 'proxied' => $proxied]);

        return 'took_over';
    }

    private function remove(IntegrationDnsRecord $row): void
    {
        $machine = $row->machine;
        if ($row->created_record && $row->record_id !== null) {
            try {
                $this->client($row->integration)->delete($this->recordPath($row->zone_id, $row->record_id));
            } catch (IntegrationFailed $e) {
                if ($e->reason !== IntegrationFailed::NOT_FOUND) {
                    $this->failed($row->integration, $e);
                    $row->forceFill(['status' => IntegrationDnsRecord::FAILED, 'error' => self::t('Couldn’t remove it from Cloudflare: :reason', ['reason' => $e->getMessage()]), 'synced_at' => now()])->save();

                    return;
                }
            }
        }

        if ($row->record_id !== null) {
            Activity::system($row->integration->workspace_id, 'app.dns_remove', $row->service, $machine, ['domain' => $row->hostname, 'provider' => 'Cloudflare', 'deleted' => $row->created_record]);
        }
        $row->delete();
    }

    /**
     * Hostnames the app serves now: its last deploy that worked, and any
     * deploy still on its way, so a quicker one doesn't drop a slower one's.
     *
     * @return list<string>
     */
    private function liveDomains(Machine $machine, string $service): array
    {
        $deploys = DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'deploy')
            ->where('service', $service)
            ->whereIn('state', ['queued', 'running', 'succeeded'])
            ->latest()
            ->limit(20)
            ->get();
        $live = $deploys->firstWhere('state', 'succeeded');
        $domains = (array) ($live?->command['job']['domains'] ?? []);
        foreach ($deploys->whereIn('state', ['queued', 'running']) as $active) {
            if (! $active->isStale()) {
                $domains = [...$domains, ...(array) ($active->command['job']['domains'] ?? [])];
            }
        }

        return self::hostnames($domains);
    }

    /**
     * A record of the other address family still pointing somewhere else:
     * visitors (and the certificate check) over it would miss the machine.
     *
     * @param  list<array<string, mixed>>  $records
     */
    private static function strayWarning(array $records, IntegrationDnsRecord $row, string $type, string $hostname): ?string
    {
        if ($type === 'CNAME') {
            return null;
        }
        $other = $type === 'A' ? 'AAAA' : 'A';
        $stray = collect($records)->first(fn (array $r) => $r['type'] === $other && ($r['id'] ?? null) !== $row->record_id);

        return $stray === null ? null : self::t('An :type record for :host still points to :address. Delete it at Cloudflare unless that’s this machine too.', ['type' => $other, 'host' => $hostname, 'address' => (string) ($stray['content'] ?? '')]);
    }

    private static function forced(IntegrationDnsRecord $row): bool
    {
        return $row->type === 'CNAME' && str_ends_with((string) $row->content, '.cfargotunnel.com');
    }

    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    private function locked(Integration $integration, string $hostname, callable $work): mixed
    {
        try {
            return Cache::lock("cloudflare:dns:{$integration->id}:{$hostname}", 60)->block(20, $work);
        } catch (LockTimeoutException) {
            return $work();
        }
    }

    /** @return list<Zone> */
    private function zonesOf(Integration $integration): array
    {
        if (! isset($this->zones[$integration->id])) {
            try {
                $this->zones[$integration->id] = $this->provider()->zones($integration);
            } catch (IntegrationFailed $e) {
                $this->failed($integration, $e);
                $this->zones[$integration->id] = [];
            }
        }

        return $this->zones[$integration->id];
    }

    private function failed(Integration $integration, IntegrationFailed $e): void
    {
        if ($e->refusedCredentials()) {
            $integration->forceFill(['status' => Integration::FAILING, 'last_checked_at' => now(), 'last_error' => $e->getMessage()])->save();
            unset($this->accounts[$integration->workspace_id]);
        }
    }

    private function recordPath(string $zoneId, string $recordId): string
    {
        return 'zones/'.rawurlencode($zoneId).'/dns_records/'.rawurlencode($recordId);
    }

    private function client(Integration $integration): CloudflareClient
    {
        return $this->provider()->client($integration);
    }

    private function provider(): CloudflareProvider
    {
        $provider = app(IntegrationProviders::class)->get(self::PROVIDER);
        assert($provider instanceof CloudflareProvider);

        return $provider;
    }

    /**
     * @param  array<int, mixed>  $domains
     * @return list<string>
     */
    private static function hostnames(array $domains): array
    {
        return array_values(array_unique(array_map(fn (string $d) => strtolower(trim($d)), array_filter($domains, fn ($d) => is_string($d) && trim($d) !== ''))));
    }

    private static function bare(string $hostname): string
    {
        $hostname = strtolower(trim($hostname));

        return str_starts_with($hostname, '*.') ? substr($hostname, 2) : $hostname;
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
