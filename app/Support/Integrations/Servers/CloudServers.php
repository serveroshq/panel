<?php

namespace App\Support\Integrations\Servers;

use App\Models\DaemonEnrolment;
use App\Models\Integration;
use App\Models\IntegrationOperation;
use App\Models\IntegrationServer;
use App\Models\Machine;
use App\Support\Integrations\Capability;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * The servers in connected cloud accounts, and the machines they are.
 */
final class CloudServers
{
    public static function manager(Integration $integration): ?ManagesServers
    {
        $provider = $integration->provider();

        return $provider instanceof ManagesServers ? $provider : null;
    }

    /**
     * Lists the account's servers at the provider, keeps them, and links each
     * one to the machine with its address.
     *
     * @return Collection<int, IntegrationServer>
     *
     * @throws IntegrationFailed
     */
    public function sync(Integration $integration): Collection
    {
        $manager = self::manager($integration);
        if ($manager === null) {
            return collect();
        }

        try {
            $servers = $manager->servers($integration);
        } catch (IntegrationFailed $e) {
            self::failed($integration, $e);
            throw $e;
        }

        DB::transaction(function () use ($integration, $servers): void {
            $seen = [];
            foreach ($servers as $server) {
                $seen[] = $server->id;
                $row = IntegrationServer::query()->firstOrNew(['integration_id' => $integration->id, 'server_id' => $server->id]);
                $row->forceFill(['name' => $server->name, 'details' => $server->toArray(), 'synced_at' => now()])->save();
            }
            $integration->servers()->whereNotIn('server_id', $seen === [] ? [''] : $seen)->delete();
        });

        $integration->forceFill([
            'meta' => [...($integration->meta ?? []), 'servers' => count($servers)],
            'status' => Integration::CONNECTED,
            'last_checked_at' => now(),
            'last_error' => null,
        ])->save();

        $this->linkByAddress($integration);

        return $integration->servers()->with('machine:id,name,provider,icon_path,last_seen_at,disconnected_at')->orderBy('name')->get();
    }

    public static function failed(Integration $integration, IntegrationFailed $e): void
    {
        $integration->forceFill([
            'status' => $e->refusedCredentials() || $e->reason === IntegrationFailed::PERMISSION ? Integration::FAILING : $integration->status,
            'last_checked_at' => now(),
            'last_error' => $e->getMessage(),
        ])->save();
    }

    public function linkByAddress(Integration $integration): int
    {
        $unlinked = $integration->servers()->whereNull('machine_id')->get();
        if ($unlinked->isEmpty()) {
            return 0;
        }
        $linkedIds = IntegrationServer::query()->whereNotNull('machine_id')
            ->whereHas('integration', fn ($q) => $q->where('workspace_id', $integration->workspace_id))
            ->pluck('machine_id')->all();
        $machines = Machine::query()
            ->where('workspace_id', $integration->workspace_id)
            ->whereNull('hosted_by')
            ->whereNotIn('id', $linkedIds === [] ? [0] : $linkedIds)
            ->get(['id', 'ip_address', 'facts']);

        $linked = 0;
        foreach ($unlinked as $row) {
            $server = Server::fromArray($row->details ?? []);
            $matches = $machines->filter(fn (Machine $machine) => self::isAt($server, $machine));
            if ($matches->count() !== 1) {
                continue;
            }
            $machine = $matches->first();
            $row->forceFill(['machine_id' => $machine->id])->save();
            $machines = $machines->reject(fn (Machine $m) => $m->id === $machine->id);
            $linked++;
        }

        return $linked;
    }

    public static function isAt(Server $server, Machine $machine): bool
    {
        foreach (self::addresses($machine) as $address) {
            if ($server->ipv4 !== null && $address === $server->ipv4) {
                return true;
            }
            if ($server->ipv6 !== null && str_contains($address, ':') && IpUtils::checkIp($address, str_contains($server->ipv6, '/') ? $server->ipv6 : $server->ipv6.'/64')) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    public static function addresses(Machine $machine): array
    {
        $addresses = [$machine->ip_address];
        foreach ((array) ($machine->facts['interfaces'] ?? []) as $interface) {
            foreach ((array) ($interface['addresses'] ?? []) as $address) {
                $addresses[] = explode('/', (string) $address)[0];
            }
        }

        return array_values(array_unique(array_filter($addresses, fn (string $a) => filter_var($a, FILTER_VALIDATE_IP) !== false)));
    }

    /**
     * Called when a machine's daemon says hello for the first time: a server
     * made from ServerOS becomes that machine, and so does an imported one.
     */
    public function claim(DaemonEnrolment $enrolment, Machine $machine): void
    {
        if (IntegrationServer::query()->where('machine_id', $machine->id)->exists()) {
            return;
        }

        $made = IntegrationServer::query()->where('daemon_enrolment_id', $enrolment->id)->whereNull('machine_id')->first();
        if ($made !== null) {
            $made->forceFill(['machine_id' => $machine->id])->save();
            IntegrationOperation::query()
                ->where('integration_id', $made->integration_id)
                ->where('kind', ServerOperations::CREATE)
                ->where('server_id', $made->server_id)
                ->whereIn('state', IntegrationOperation::ACTIVE)
                ->get()
                ->each(fn (IntegrationOperation $op) => ServerOperations::finish($op, ['machine_id' => $machine->id]));

            return;
        }

        Integration::query()->where('workspace_id', $machine->workspace_id)->get()
            ->each(fn (Integration $integration) => $this->linkByAddress($integration));
    }

    /**
     * What the machine page shows about the server a machine is.
     *
     * @return array<string, mixed>|null
     */
    public static function machineProps(Machine $machine): ?array
    {
        $row = IntegrationServer::query()->where('machine_id', $machine->id)->with('integration')->first();
        $manager = $row === null ? null : self::manager($row->integration);
        if ($row === null || $manager === null) {
            return null;
        }
        $provider = $row->integration->provider();
        $server = Server::fromArray($row->details ?? []);
        ServerOperations::expireLost();
        $operation = IntegrationOperation::query()->where('machine_id', $machine->id)->latest('id')->first();

        return [
            'integration' => [
                'id' => $row->integration->id,
                'provider' => $provider->key(),
                'name' => $provider->name(),
                'label' => $row->integration->label,
                'status' => $row->integration->status,
            ],
            'server' => $server->toArray(),
            'syncedAt' => $row->synced_at?->toIso8601String(),
            'can' => array_map(fn (Capability $c) => $c->value, $provider->capabilities()),
            'rules' => $manager->rules()->toArray(),
            'operation' => $operation !== null && ($operation->active() || $operation->finished_at?->gt(now()->subMinutes(10))) ? ServerOperations::props($operation) : null,
        ];
    }

    /** @return array{0: IntegrationServer, 1: ManagesServers}|null */
    public static function of(Machine $machine): ?array
    {
        $row = IntegrationServer::query()->where('machine_id', $machine->id)->with('integration')->first();
        $manager = $row === null ? null : self::manager($row->integration);

        return $row === null || $manager === null ? null : [$row, $manager];
    }
}
