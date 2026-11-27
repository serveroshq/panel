<?php

namespace App\Support\Integrations\Vultr;

use App\Models\Integration;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;
use App\Support\Integrations\Servers\ActionState;
use App\Support\Integrations\Servers\ManagesServers;
use App\Support\Integrations\Servers\NewServer;
use App\Support\Integrations\Servers\Server;
use App\Support\Integrations\Servers\ServerImage;
use App\Support\Integrations\Servers\ServerLocation;
use App\Support\Integrations\Servers\ServerPlans;
use App\Support\Integrations\Servers\ServerRules;
use App\Support\Integrations\Servers\ServerType;
use App\Support\Integrations\Servers\Snapshot;
use App\Support\Integrations\Servers\SshKey;
use App\Support\Integrations\Servers\StartedServer;
use Illuminate\Support\Facades\Cache;

/**
 * Vultr has no action objects: changes are followed by watching the
 * instance itself, so every method that starts something returns null.
 */
final class VultrProvider extends Provider implements ManagesServers
{
    private const array SYSTEMS = ['ubuntu', 'debian'];

    private const array DEDICATED = ['vdc', 'voc'];

    private const array TAGS = ['serveros'];

    public function key(): string
    {
        return 'vultr';
    }

    public function name(): string
    {
        return 'Vultr';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'api_key',
                label: self::t('API key'),
                help: self::t('In the Vultr customer portal, open Account › API, enable the API and copy the key. If Access control limits where it works from, allow this panel’s address.'),
                link: 'https://my.vultr.com/settings/#settingsapi',
                linkLabel: self::t('Open Vultr'),
                placeholder: self::t('Paste the key'),
                rules: ['required', 'string', 'min:20', 'max:100', 'regex:/^\S+$/'],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::Servers, Capability::CreateServers, Capability::Resize, Capability::Power, Capability::Snapshots, Capability::DeleteServers];
    }

    public function describe(Capability $capability): string
    {
        return match ($capability) {
            Capability::Resize => self::t('Upgrades a linked machine to a bigger plan, in place. Vultr can’t move one to a smaller plan.'),
            Capability::Snapshots => self::t('Takes snapshots of a linked machine, and lists and deletes the account’s snapshots. Vultr keeps them for the whole account.'),
            default => parent::describe($capability),
        };
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $client = new VultrClient($credentials['api_key'] ?? '');
        $account = (array) ($client->get('account')['account'] ?? []);
        $instances = $client->get('instances', ['per_page' => 1]);
        $name = is_string($account['name'] ?? null) && trim($account['name']) !== '' ? trim($account['name']) : null;

        return new ConnectedAccount($name ?? 'Vultr', [
            'account' => $name,
            'email' => is_string($account['email'] ?? null) && $account['email'] !== '' ? $account['email'] : null,
            'servers' => (int) ($instances['meta']['total'] ?? count((array) ($instances['instances'] ?? []))),
        ]);
    }

    public function summary(Integration $integration): string
    {
        $count = (int) ($integration->meta['servers'] ?? 0);

        return $count === 1 ? self::t('1 server') : self::t(':count servers', ['count' => $count]);
    }

    public function facts(Integration $integration): array
    {
        $meta = $integration->meta ?? [];

        return array_values(array_filter([
            is_string($meta['account'] ?? null) ? ['label' => self::t('Vultr account'), 'value' => $meta['account']] : null,
            is_string($meta['email'] ?? null) ? ['label' => self::t('Email'), 'value' => $meta['email']] : null,
            ['label' => self::t('Servers'), 'value' => (string) (int) ($meta['servers'] ?? 0)],
        ]));
    }

    public function servers(Integration $integration): array
    {
        $regions = $this->regions($integration);

        return array_map(fn (array $instance) => $this->toServer($instance, $regions), $this->client($integration)->all('instances', 'instances'));
    }

    public function server(Integration $integration, string $id): ?Server
    {
        try {
            $body = $this->client($integration)->get('instances/'.rawurlencode($id));
        } catch (IntegrationFailed $e) {
            if ($e->reason === IntegrationFailed::NOT_FOUND) {
                return null;
            }
            throw $e;
        }

        return is_array($body['instance'] ?? null) ? $this->toServer($body['instance'], $this->regions($integration)) : null;
    }

    public function plans(Integration $integration): ServerPlans
    {
        return ServerPlans::fromArray(Cache::remember("integrations:{$integration->id}:plans", now()->addMinutes(10), function () use ($integration): array {
            $client = $this->client($integration);
            $locations = array_values($this->regions($integration));

            $types = [];
            foreach ($client->all('plans', 'plans', ['type' => 'all']) as $plan) {
                $made = $this->toType($plan);
                if ($made !== null) {
                    $types[] = $made;
                }
            }
            usort($types, fn (ServerType $a, ServerType $b) => [$a->cpu !== 'shared', $a->cores, $a->memoryGb, $a->diskGb, $a->name] <=> [$b->cpu !== 'shared', $b->cores, $b->memoryGb, $b->diskGb, $b->name]);

            $images = [];
            foreach ($client->all('os', 'os') as $os) {
                if (in_array(strtolower((string) ($os['family'] ?? '')), self::SYSTEMS, true) && ($os['arch'] ?? null) === 'x64' && isset($os['id'])) {
                    $images[] = $os;
                }
            }

            $keys = array_map(fn (array $k) => new SshKey((string) ($k['id'] ?? ''), (string) ($k['name'] ?? '')), $client->all('ssh-keys', 'ssh_keys'));

            return (new ServerPlans($locations, $types, $this->toImages($images), $keys, 'USD'))->toArray();
        }));
    }

    public function create(Integration $integration, NewServer $server, string $userData): StartedServer
    {
        $body = $this->client($integration)->post('instances', [
            'region' => $server->location,
            'plan' => $server->type,
            'os_id' => ctype_digit($server->image) ? (int) $server->image : $server->image,
            'label' => $server->name,
            'hostname' => $server->name,
            'sshkey_id' => $server->keys,
            'user_data' => base64_encode($userData),
            'enable_ipv6' => true,
            'backups' => 'disabled',
            'activation_email' => false,
            'tags' => self::TAGS,
        ]);

        if (! is_array($body['instance'] ?? null)) {
            throw new IntegrationFailed(self::t('Vultr didn’t say which server it made. Check the Vultr customer portal before trying again.'));
        }

        return new StartedServer($this->toServer($body['instance'], $this->regions($integration)), null);
    }

    // Vultr lists the plans an instance can move to, and they only go up.
    public function resizeTypes(Integration $integration, Server $server): array
    {
        $upgrades = $this->client($integration)->get('instances/'.rawurlencode($server->id).'/upgrades', ['type' => 'plans']);
        $allowed = array_map('strval', (array) ($upgrades['upgrades']['plans'] ?? []));

        return array_values(array_filter($this->plans($integration)->types, fn (ServerType $type) => $type->name !== $server->type
            && in_array($type->name, $allowed, true)
            && in_array($server->location, $type->locations, true)));
    }

    public function resize(Integration $integration, string $id, string $type, bool $growDisk): ?string
    {
        $this->client($integration)->patch('instances/'.rawurlencode($id), ['plan' => $type]);

        return null;
    }

    public function rules(): ServerRules
    {
        return new ServerRules(
            resizeNeedsPowerOff: false,
            resizeDisk: ServerRules::DISK_ALWAYS,
            resizeDown: false,
            gracefulRestart: false,
            snapshotsPerServer: false,
        );
    }

    public function shutdown(Integration $integration, string $id): ?string
    {
        return $this->powerOff($integration, $id);
    }

    public function powerOff(Integration $integration, string $id): ?string
    {
        $this->client($integration)->post('instances/'.rawurlencode($id).'/halt');

        return null;
    }

    public function powerOn(Integration $integration, string $id): ?string
    {
        $this->client($integration)->post('instances/'.rawurlencode($id).'/start');

        return null;
    }

    public function reboot(Integration $integration, string $id): ?string
    {
        $this->client($integration)->post('instances/'.rawurlencode($id).'/reboot');

        return null;
    }

    public function powerCycle(Integration $integration, string $id): ?string
    {
        $this->powerOff($integration, $id);

        return $this->powerOn($integration, $id);
    }

    public function action(Integration $integration, string $actionId): ActionState
    {
        return new ActionState(ActionState::DONE);
    }

    public function snapshots(Integration $integration, string $serverId): array
    {
        $snapshots = array_map(fn (array $s) => $this->toSnapshot($s), $this->client($integration)->all('snapshots', 'snapshots'));
        usort($snapshots, fn (Snapshot $a, Snapshot $b) => strcmp((string) $b->createdAt, (string) $a->createdAt));

        return $snapshots;
    }

    public function snapshot(Integration $integration, string $serverId, string $name): Snapshot
    {
        $body = $this->client($integration)->post('snapshots', ['instance_id' => $serverId, 'description' => $name]);

        return $this->toSnapshot((array) ($body['snapshot'] ?? []));
    }

    public function deleteSnapshot(Integration $integration, string $snapshotId): void
    {
        $this->client($integration)->delete('snapshots/'.rawurlencode($snapshotId));
    }

    public function delete(Integration $integration, string $id): void
    {
        $this->client($integration)->delete('instances/'.rawurlencode($id));
    }

    private function client(Integration $integration): VultrClient
    {
        return new VultrClient($integration->credential('api_key'));
    }

    /**
     * Vultr's regions by id. They're the same for every account, and an
     * instance only names its region's id.
     *
     * @return array<string, ServerLocation>
     */
    private function regions(Integration $integration): array
    {
        $rows = Cache::remember("integrations:{$integration->id}:regions", now()->addDay(), fn (): array => array_map(fn (array $r) => (new ServerLocation(
            (string) ($r['id'] ?? ''),
            (string) ($r['city'] ?? $r['id'] ?? ''),
            (string) ($r['country'] ?? ''),
            isset($r['continent']) ? (string) $r['continent'] : null,
        ))->toArray(), $this->client($integration)->all('regions', 'regions')));

        $regions = [];
        foreach ($rows as $row) {
            $regions[$row['name']] = new ServerLocation($row['name'], $row['city'], $row['country'], $row['zone']);
        }

        return $regions;
    }

    /**
     * @param  array<string, mixed>  $i
     * @param  array<string, ServerLocation>  $regions
     */
    private function toServer(array $i, array $regions): Server
    {
        $region = $regions[(string) ($i['region'] ?? '')] ?? null;
        $ipv4 = is_string($i['main_ip'] ?? null) && ! in_array($i['main_ip'], ['', '0.0.0.0'], true) ? $i['main_ip'] : null;
        $ipv6 = match (true) {
            is_string($i['v6_network'] ?? null) && $i['v6_network'] !== '' => $i['v6_network'].'/'.(int) ($i['v6_network_size'] ?? 64),
            is_string($i['v6_main_ip'] ?? null) && $i['v6_main_ip'] !== '' => $i['v6_main_ip'].'/64',
            default => null,
        };

        return new Server(
            id: (string) ($i['id'] ?? ''),
            name: (string) (($i['label'] ?? '') !== '' ? $i['label'] : ($i['hostname'] ?? '')),
            status: match (true) {
                ($i['status'] ?? null) === 'pending' => 'starting',
                in_array($i['status'] ?? null, ['resizing', 'suspended'], true) => 'other',
                ($i['power_status'] ?? null) === 'stopped' => 'off',
                ($i['server_status'] ?? null) === 'locked' => 'other',
                ($i['server_status'] ?? null) === 'installingbooting' => 'starting',
                ($i['power_status'] ?? null) === 'running' => 'running',
                default => 'other',
            },
            type: (string) ($i['plan'] ?? ''),
            typeLabel: (string) ($i['plan'] ?? ''),
            cores: (int) ($i['vcpu_count'] ?? 0),
            memoryGb: round((float) ($i['ram'] ?? 0) / 1024, 2),
            diskGb: (int) ($i['disk'] ?? 0),
            location: (string) ($i['region'] ?? ''),
            city: $region?->city,
            country: $region === null || $region->country === '' ? null : $region->country,
            ipv4: $ipv4,
            ipv6: $ipv6,
            architecture: 'x86',
            createdAt: isset($i['date_created']) ? (string) $i['date_created'] : null,
        );
    }

    /** @param  array<string, mixed>  $plan */
    private function toType(array $plan): ?ServerType
    {
        $id = (string) ($plan['id'] ?? '');
        $kind = (string) ($plan['type'] ?? strtok($id, '-'));
        $locations = array_values(array_map('strval', (array) ($plan['locations'] ?? [])));
        if ($id === '' || $locations === [] || $kind === 'vcg' || str_starts_with($id, 'vcg') || isset($plan['gpu_vram_gb'])) {
            return null;
        }
        $price = ['monthly' => (float) ($plan['monthly_cost'] ?? 0), 'hourly' => (float) ($plan['hourly_cost'] ?? 0)];

        return new ServerType(
            name: $id,
            label: $id,
            cores: (int) ($plan['vcpu_count'] ?? 0),
            memoryGb: round((float) ($plan['ram'] ?? 0) / 1024, 2),
            diskGb: (int) ($plan['disk'] ?? 0),
            cpu: in_array($kind, self::DEDICATED, true) || in_array(strtok($id, '-'), self::DEDICATED, true) ? 'dedicated' : 'shared',
            architecture: 'x86',
            storage: 'local',
            locations: $locations,
            prices: array_fill_keys($locations, $price),
        );
    }

    /**
     * Ubuntu first, newest first, with the newest Ubuntu LTS picked.
     *
     * @param  list<array<string, mixed>>  $images
     * @return list<ServerImage>
     */
    private function toImages(array $images): array
    {
        $os = fn (array $image): string => strtolower((string) ($image['family'] ?? ''));
        $version = fn (array $image): string => preg_match('/(\d+(?:\.\d+)?)/', (string) ($image['name'] ?? ''), $m) === 1 ? $m[1] : '0';
        usort($images, fn (array $a, array $b) => array_search($os($a), self::SYSTEMS, true) <=> array_search($os($b), self::SYSTEMS, true)
            ?: version_compare($version($b), $version($a)));

        $lts = null;
        foreach ($images as $image) {
            if ($lts === null && $os($image) === 'ubuntu' && str_contains((string) ($image['name'] ?? ''), 'LTS')) {
                $lts = (string) $image['id'];
            }
        }

        return array_map(fn (array $image) => new ServerImage(
            id: (string) $image['id'],
            label: (string) ($image['name'] ?? ''),
            os: $os($image),
            version: $version($image) === '0' ? null : $version($image),
            architecture: 'x86',
            recommended: (string) $image['id'] === $lts,
        ), $images);
    }

    /** @param  array<string, mixed>  $s */
    private function toSnapshot(array $s): Snapshot
    {
        $gb = fn (mixed $bytes): ?float => is_numeric($bytes) && (float) $bytes > 0 ? round((float) $bytes / 1024 ** 3, 2) : null;
        $disk = $gb($s['size'] ?? null);

        return new Snapshot(
            id: (string) ($s['id'] ?? ''),
            name: (string) ($s['description'] ?? ''),
            status: match ($s['status'] ?? null) {
                'complete' => 'available',
                'pending' => 'creating',
                default => (string) ($s['status'] ?? 'creating'),
            },
            sizeGb: $gb($s['compressed_size'] ?? null),
            diskGb: $disk === null ? null : (int) ceil($disk),
            createdAt: isset($s['date_created']) ? (string) $s['date_created'] : null,
        );
    }
}
