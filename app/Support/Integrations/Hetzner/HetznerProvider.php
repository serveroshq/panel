<?php

namespace App\Support\Integrations\Hetzner;

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

final class HetznerProvider extends Provider implements ManagesServers
{
    private const array SYSTEMS = ['ubuntu', 'debian'];

    private const array LABELS = ['managed-by' => 'serveros'];

    public function key(): string
    {
        return 'hetzner';
    }

    public function name(): string
    {
        return 'Hetzner Cloud';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'token',
                label: self::t('API token'),
                help: self::t('In the Hetzner Console, open the project, then Security › API tokens, and generate a token with Read & Write.'),
                link: 'https://console.hetzner.com/projects',
                linkLabel: self::t('Open the Hetzner Console'),
                placeholder: self::t('Paste the token'),
                rules: ['required', 'string', 'min:20', 'max:200', 'regex:/^\S+$/'],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::Servers, Capability::CreateServers, Capability::Resize, Capability::Power, Capability::Snapshots, Capability::DeleteServers];
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $body = (new HetznerClient($credentials['token'] ?? ''))->get('servers', ['per_page' => 1]);
        $servers = $body['meta']['pagination']['total_entries'] ?? count((array) ($body['servers'] ?? []));

        return new ConnectedAccount('Hetzner Cloud', ['servers' => (int) $servers]);
    }

    public function summary(Integration $integration): string
    {
        $count = (int) ($integration->meta['servers'] ?? 0);

        return $count === 1 ? self::t('1 server') : self::t(':count servers', ['count' => $count]);
    }

    public function facts(Integration $integration): array
    {
        return [['label' => self::t('Servers in the project'), 'value' => (string) (int) ($integration->meta['servers'] ?? 0)]];
    }

    public function servers(Integration $integration): array
    {
        return array_map(fn (array $server) => $this->toServer($server), $this->client($integration)->all('servers', 'servers'));
    }

    public function server(Integration $integration, string $id): ?Server
    {
        try {
            $body = $this->client($integration)->get('servers/'.rawurlencode($id));
        } catch (IntegrationFailed $e) {
            if ($e->reason === IntegrationFailed::NOT_FOUND) {
                return null;
            }
            throw $e;
        }

        return is_array($body['server'] ?? null) ? $this->toServer($body['server']) : null;
    }

    public function plans(Integration $integration): ServerPlans
    {
        return ServerPlans::fromArray(Cache::remember("integrations:{$integration->id}:plans", now()->addMinutes(10), function () use ($integration): array {
            $client = $this->client($integration);

            $locations = array_map(fn (array $l) => new ServerLocation(
                (string) ($l['name'] ?? ''),
                (string) ($l['city'] ?? $l['name'] ?? ''),
                (string) ($l['country'] ?? ''),
                isset($l['network_zone']) ? (string) $l['network_zone'] : null,
            ), $client->all('locations', 'locations'));

            $types = [];
            foreach ($client->all('server_types', 'server_types') as $type) {
                $made = $this->toType($type);
                if ($made !== null) {
                    $types[] = $made;
                }
            }
            usort($types, fn (ServerType $a, ServerType $b) => [$a->architecture !== 'x86', $a->cpu, $a->cores, $a->memoryGb] <=> [$b->architecture !== 'x86', $b->cpu, $b->cores, $b->memoryGb]);

            $images = [];
            foreach ($client->all('images', 'images', ['type' => 'system', 'status' => 'available']) as $image) {
                if (in_array($image['os_flavor'] ?? null, self::SYSTEMS, true) && is_string($image['name'] ?? null) && ($image['deprecation'] ?? null) === null) {
                    $images[] = $image;
                }
            }

            $keys = array_map(fn (array $k) => new SshKey((string) ($k['id'] ?? ''), (string) ($k['name'] ?? ''), isset($k['fingerprint']) ? (string) $k['fingerprint'] : null), $client->all('ssh_keys', 'ssh_keys'));
            $currency = $client->get('pricing')['pricing']['currency'] ?? 'EUR';

            return (new ServerPlans($locations, $types, $this->toImages($images), $keys, is_string($currency) ? $currency : 'EUR'))->toArray();
        }));
    }

    public function create(Integration $integration, NewServer $server, string $userData): StartedServer
    {
        $body = $this->client($integration)->post('servers', [
            'name' => $server->name,
            'location' => $server->location,
            'server_type' => $server->type,
            'image' => $server->image,
            'user_data' => $userData,
            'start_after_create' => true,
            'ssh_keys' => array_map(fn (string $id) => ctype_digit($id) ? (int) $id : $id, $server->keys),
            'public_net' => ['enable_ipv4' => true, 'enable_ipv6' => true],
            'labels' => self::LABELS,
        ]);

        if (! is_array($body['server'] ?? null)) {
            throw new IntegrationFailed(self::t('Hetzner didn’t say which server it made. Check the Hetzner Console before trying again.'));
        }

        return new StartedServer($this->toServer($body['server']), $this->actionId($body));
    }

    public function resizeTypes(Integration $integration, Server $server): array
    {
        $plans = $this->plans($integration);
        $current = $plans->type($server->type);

        return array_values(array_filter($plans->types, fn (ServerType $type) => $type->name !== $server->type
            && in_array($server->location, $type->locations, true)
            && $type->architecture === ($current->architecture ?? $server->architecture ?? $type->architecture)
            && $type->storage === ($current->storage ?? $type->storage)
            && $type->diskGb >= $server->diskGb));
    }

    public function resize(Integration $integration, string $id, string $type, bool $growDisk): ?string
    {
        return $this->act($integration, $id, 'change_type', ['server_type' => $type, 'upgrade_disk' => $growDisk]);
    }

    public function rules(): ServerRules
    {
        return new ServerRules(resizeNeedsPowerOff: true);
    }

    public function shutdown(Integration $integration, string $id): ?string
    {
        return $this->act($integration, $id, 'shutdown');
    }

    public function powerOff(Integration $integration, string $id): ?string
    {
        return $this->act($integration, $id, 'poweroff');
    }

    public function powerOn(Integration $integration, string $id): ?string
    {
        return $this->act($integration, $id, 'poweron');
    }

    public function reboot(Integration $integration, string $id): ?string
    {
        return $this->act($integration, $id, 'reboot');
    }

    public function powerCycle(Integration $integration, string $id): ?string
    {
        return $this->act($integration, $id, 'reset');
    }

    public function action(Integration $integration, string $actionId): ActionState
    {
        $action = (array) ($this->client($integration)->get('actions/'.rawurlencode($actionId))['action'] ?? []);
        $error = $action['error']['message'] ?? null;

        return match ($action['status'] ?? null) {
            'success' => new ActionState(ActionState::DONE, progress: 100),
            'error' => new ActionState(ActionState::FAILED, is_string($error) ? $error : self::t('Hetzner couldn’t finish it.')),
            default => new ActionState(ActionState::RUNNING, progress: isset($action['progress']) ? (int) $action['progress'] : null),
        };
    }

    public function snapshots(Integration $integration, string $serverId): array
    {
        $snapshots = [];
        foreach ($this->client($integration)->all('images', 'images', ['type' => 'snapshot', 'sort' => 'created:desc']) as $image) {
            if ((string) ($image['created_from']['id'] ?? '') === $serverId) {
                $snapshots[] = $this->toSnapshot($image);
            }
        }

        return $snapshots;
    }

    public function snapshot(Integration $integration, string $serverId, string $name): Snapshot
    {
        $body = $this->client($integration)->post('servers/'.rawurlencode($serverId).'/actions/create_image', [
            'description' => $name,
            'type' => 'snapshot',
            'labels' => self::LABELS,
        ]);

        return $this->toSnapshot((array) ($body['image'] ?? []));
    }

    public function deleteSnapshot(Integration $integration, string $snapshotId): void
    {
        $this->client($integration)->delete('images/'.rawurlencode($snapshotId));
    }

    public function delete(Integration $integration, string $id): void
    {
        $this->client($integration)->delete('servers/'.rawurlencode($id));
    }

    private function client(Integration $integration): HetznerClient
    {
        return new HetznerClient($integration->credential('token'));
    }

    /** @param  array<string, mixed>  $body */
    private function act(Integration $integration, string $id, string $action, array $body = []): ?string
    {
        return $this->actionId($this->client($integration)->post('servers/'.rawurlencode($id).'/actions/'.$action, $body));
    }

    /** @param  array<string, mixed>  $body */
    private function actionId(array $body): ?string
    {
        $id = $body['action']['id'] ?? null;

        return is_int($id) || is_string($id) ? (string) $id : null;
    }

    /** @param  array<string, mixed>  $s */
    private function toServer(array $s): Server
    {
        $type = (array) ($s['server_type'] ?? []);
        $location = (array) ($s['location'] ?? $s['datacenter']['location'] ?? []);

        return new Server(
            id: (string) ($s['id'] ?? ''),
            name: (string) ($s['name'] ?? ''),
            status: match ($s['status'] ?? null) {
                'running' => 'running',
                'initializing', 'starting' => 'starting',
                'stopping' => 'stopping',
                'off' => 'off',
                'deleting' => 'deleting',
                default => 'other',
            },
            type: (string) ($type['name'] ?? ''),
            typeLabel: (string) ($type['description'] ?? $type['name'] ?? ''),
            cores: (int) ($type['cores'] ?? 0),
            memoryGb: (float) ($type['memory'] ?? 0),
            diskGb: (int) ($s['primary_disk_size'] ?? $type['disk'] ?? 0),
            location: (string) ($location['name'] ?? ''),
            city: isset($location['city']) ? (string) $location['city'] : null,
            country: isset($location['country']) ? (string) $location['country'] : null,
            ipv4: is_string($s['public_net']['ipv4']['ip'] ?? null) ? $s['public_net']['ipv4']['ip'] : null,
            ipv6: is_string($s['public_net']['ipv6']['ip'] ?? null) ? $s['public_net']['ipv6']['ip'] : null,
            architecture: isset($type['architecture']) ? (string) $type['architecture'] : null,
            createdAt: isset($s['created']) ? (string) $s['created'] : null,
        );
    }

    /** @param  array<string, mixed>  $type */
    private function toType(array $type): ?ServerType
    {
        if (($type['deprecation'] ?? null) !== null || ($type['deprecated'] ?? false) === true) {
            return null;
        }

        $prices = [];
        foreach ((array) ($type['prices'] ?? []) as $price) {
            if (is_array($price) && is_string($price['location'] ?? null)) {
                $prices[$price['location']] = [
                    'monthly' => (float) ($price['price_monthly']['gross'] ?? 0),
                    'hourly' => (float) ($price['price_hourly']['gross'] ?? 0),
                ];
            }
        }

        $locations = [];
        if (is_array($type['locations'] ?? null)) {
            foreach ($type['locations'] as $location) {
                if (is_array($location) && is_string($location['name'] ?? null) && ($location['available'] ?? true) !== false && ($location['deprecation'] ?? null) === null) {
                    $locations[] = $location['name'];
                }
            }
        } else {
            $locations = array_keys($prices);
        }
        $locations = array_values(array_filter($locations, fn (string $name) => isset($prices[$name])));
        if ($locations === []) {
            return null;
        }

        return new ServerType(
            name: (string) ($type['name'] ?? ''),
            label: (string) ($type['description'] ?? $type['name'] ?? ''),
            cores: (int) ($type['cores'] ?? 0),
            memoryGb: (float) ($type['memory'] ?? 0),
            diskGb: (int) ($type['disk'] ?? 0),
            cpu: (string) ($type['cpu_type'] ?? 'shared'),
            architecture: (string) ($type['architecture'] ?? 'x86'),
            storage: (string) ($type['storage_type'] ?? 'local'),
            locations: $locations,
            prices: $prices,
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
        usort($images, fn (array $a, array $b) => array_search($a['os_flavor'], self::SYSTEMS, true) <=> array_search($b['os_flavor'], self::SYSTEMS, true)
            ?: version_compare((string) ($b['os_version'] ?? '0'), (string) ($a['os_version'] ?? '0'))
            ?: strcmp((string) ($a['architecture'] ?? ''), (string) ($b['architecture'] ?? '')));

        $lts = [];
        foreach ($images as $image) {
            $arch = (string) ($image['architecture'] ?? 'x86');
            if (! isset($lts[$arch]) && $image['os_flavor'] === 'ubuntu' && preg_match('/^(\d+)\.04$/', (string) ($image['os_version'] ?? ''), $m) === 1 && (int) $m[1] % 2 === 0) {
                $lts[$arch] = (string) $image['name'];
            }
        }

        return array_map(fn (array $image) => new ServerImage(
            id: (string) $image['name'],
            label: (string) ($image['description'] ?? $image['name']),
            os: (string) $image['os_flavor'],
            version: isset($image['os_version']) ? (string) $image['os_version'] : null,
            architecture: (string) ($image['architecture'] ?? 'x86'),
            recommended: ($lts[(string) ($image['architecture'] ?? 'x86')] ?? null) === $image['name'],
        ), $images);
    }

    /** @param  array<string, mixed>  $image */
    private function toSnapshot(array $image): Snapshot
    {
        return new Snapshot(
            id: (string) ($image['id'] ?? ''),
            name: (string) ($image['description'] ?? ''),
            status: (string) ($image['status'] ?? 'creating'),
            sizeGb: isset($image['image_size']) ? (float) $image['image_size'] : null,
            diskGb: isset($image['disk_size']) ? (int) $image['disk_size'] : null,
            createdAt: isset($image['created']) ? (string) $image['created'] : null,
        );
    }
}
