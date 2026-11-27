<?php

namespace App\Support\Integrations\DigitalOcean;

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

final class DigitalOceanProvider extends Provider implements ManagesServers
{
    private const array SYSTEMS = ['ubuntu', 'debian'];

    private const array COUNTRIES = ['nyc' => 'US', 'sfo' => 'US', 'atl' => 'US', 'ams' => 'NL', 'sgp' => 'SG', 'lon' => 'GB', 'fra' => 'DE', 'tor' => 'CA', 'blr' => 'IN', 'syd' => 'AU'];

    private const string PENDING = 'action-';

    public function key(): string
    {
        return 'digitalocean';
    }

    public function name(): string
    {
        return 'DigitalOcean';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'token',
                label: self::t('API token'),
                help: self::t('In the DigitalOcean Control Panel, open API › Tokens and generate a token with Full Access, or with custom scopes that can read and write droplets.'),
                link: 'https://cloud.digitalocean.com/account/api/tokens',
                linkLabel: self::t('Open DigitalOcean'),
                placeholder: self::t('Paste the token'),
                rules: ['required', 'string', 'min:20', 'max:200', 'regex:/^\S+$/'],
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
            Capability::Resize => self::t('Moves a linked machine to another droplet size. Change only CPU and RAM to keep the way back, or grow the disk for good.'),
            default => parent::describe($capability),
        };
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $client = new DigitalOceanClient($credentials['token'] ?? '');
        $account = (array) ($client->get('account')['account'] ?? []);
        $droplets = $client->get('droplets', ['per_page' => 1]);
        $team = is_string($account['team']['name'] ?? null) && $account['team']['name'] !== '' ? $account['team']['name'] : null;

        return new ConnectedAccount($team ?? 'DigitalOcean', [
            'team' => $team,
            'email' => is_string($account['email'] ?? null) ? $account['email'] : null,
            'servers' => (int) ($droplets['meta']['total'] ?? count((array) ($droplets['droplets'] ?? []))),
            'droplet_limit' => isset($account['droplet_limit']) ? (int) $account['droplet_limit'] : null,
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
            is_string($meta['team'] ?? null) ? ['label' => self::t('Team'), 'value' => $meta['team']] : null,
            is_string($meta['email'] ?? null) ? ['label' => self::t('Email'), 'value' => $meta['email']] : null,
            ['label' => self::t('Droplets'), 'value' => (string) (int) ($meta['servers'] ?? 0)],
            isset($meta['droplet_limit']) ? ['label' => self::t('Droplet limit'), 'value' => (string) (int) $meta['droplet_limit']] : null,
        ]));
    }

    public function servers(Integration $integration): array
    {
        return array_map(fn (array $droplet) => $this->toServer($droplet), $this->client($integration)->all('droplets', 'droplets'));
    }

    public function server(Integration $integration, string $id): ?Server
    {
        try {
            $body = $this->client($integration)->get('droplets/'.rawurlencode($id));
        } catch (IntegrationFailed $e) {
            if ($e->reason === IntegrationFailed::NOT_FOUND) {
                return null;
            }
            throw $e;
        }

        return is_array($body['droplet'] ?? null) ? $this->toServer($body['droplet']) : null;
    }

    public function plans(Integration $integration): ServerPlans
    {
        return ServerPlans::fromArray(Cache::remember("integrations:{$integration->id}:plans", now()->addMinutes(10), function () use ($integration): array {
            $client = $this->client($integration);

            $locations = [];
            foreach ($client->all('regions', 'regions') as $region) {
                if (($region['available'] ?? false) === true && is_string($region['slug'] ?? null)) {
                    $locations[] = $this->toLocation($region);
                }
            }
            $open = array_map(fn (ServerLocation $l) => $l->name, $locations);

            $types = [];
            foreach ($client->all('sizes', 'sizes') as $size) {
                $made = $this->toType($size, $open);
                if ($made !== null) {
                    $types[] = $made;
                }
            }
            usort($types, fn (ServerType $a, ServerType $b) => [$a->cpu !== 'shared', $a->cores, $a->memoryGb, $a->diskGb, $a->name] <=> [$b->cpu !== 'shared', $b->cores, $b->memoryGb, $b->diskGb, $b->name]);

            $images = [];
            foreach ($client->all('images', 'images', ['type' => 'distribution']) as $image) {
                if (in_array(strtolower((string) ($image['distribution'] ?? '')), self::SYSTEMS, true) && is_string($image['slug'] ?? null) && ($image['status'] ?? 'available') === 'available') {
                    $images[] = $image;
                }
            }

            $keys = array_map(fn (array $k) => new SshKey((string) ($k['id'] ?? ''), (string) ($k['name'] ?? ''), isset($k['fingerprint']) ? (string) $k['fingerprint'] : null), $client->all('account/keys', 'ssh_keys'));

            return (new ServerPlans($locations, $types, $this->toImages($images), $keys, 'USD'))->toArray();
        }));
    }

    public function create(Integration $integration, NewServer $server, string $userData): StartedServer
    {
        $body = $this->client($integration)->post('droplets', [
            'name' => $server->name,
            'region' => $server->location,
            'size' => $server->type,
            'image' => $server->image,
            'ssh_keys' => array_map(fn (string $id) => ctype_digit($id) ? (int) $id : $id, $server->keys),
            'ipv6' => true,
            'backups' => false,
            'user_data' => $userData,
        ]);

        if (! is_array($body['droplet'] ?? null)) {
            throw new IntegrationFailed(self::t('DigitalOcean didn’t say which droplet it made. Check the DigitalOcean Control Panel before trying again.'));
        }

        $action = null;
        foreach ((array) ($body['links']['actions'] ?? []) as $link) {
            if (is_array($link) && (is_int($link['id'] ?? null) || is_string($link['id'] ?? null))) {
                $action = (string) $link['id'];
                break;
            }
        }

        return new StartedServer($this->toServer($body['droplet']), $action);
    }

    // A droplet's disk never shrinks, so it can only move to sizes with a disk at least as big.
    public function resizeTypes(Integration $integration, Server $server): array
    {
        return array_values(array_filter($this->plans($integration)->types, fn (ServerType $type) => $type->name !== $server->type
            && in_array($server->location, $type->locations, true)
            && $type->diskGb >= $server->diskGb));
    }

    public function resize(Integration $integration, string $id, string $type, bool $growDisk): ?string
    {
        return $this->act($integration, $id, 'resize', ['size' => $type, 'disk' => $growDisk]);
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
        return $this->act($integration, $id, 'power_off');
    }

    public function powerOn(Integration $integration, string $id): ?string
    {
        return $this->act($integration, $id, 'power_on');
    }

    public function reboot(Integration $integration, string $id): ?string
    {
        return $this->act($integration, $id, 'reboot');
    }

    public function powerCycle(Integration $integration, string $id): ?string
    {
        return $this->act($integration, $id, 'power_cycle');
    }

    public function action(Integration $integration, string $actionId): ActionState
    {
        $action = (array) ($this->client($integration)->get('actions/'.rawurlencode($actionId))['action'] ?? []);

        return match ($action['status'] ?? null) {
            'completed' => new ActionState(ActionState::DONE, progress: 100),
            'errored' => new ActionState(ActionState::FAILED, self::t('DigitalOcean couldn’t finish it.')),
            default => new ActionState(ActionState::RUNNING),
        };
    }

    public function snapshots(Integration $integration, string $serverId): array
    {
        $client = $this->client($integration);
        $path = 'droplets/'.rawurlencode($serverId);

        $taking = [];
        foreach ((array) ($client->get($path.'/actions', ['per_page' => 20])['actions'] ?? []) as $action) {
            if (is_array($action) && ($action['type'] ?? null) === 'snapshot' && ($action['status'] ?? null) === 'in-progress') {
                $taking[] = new Snapshot(self::PENDING.(string) ($action['id'] ?? ''), '', 'creating', null, null, isset($action['started_at']) ? (string) $action['started_at'] : null);
            }
        }

        $done = array_map(fn (array $s) => new Snapshot(
            id: (string) ($s['id'] ?? ''),
            name: (string) ($s['name'] ?? ''),
            status: 'available',
            sizeGb: isset($s['size_gigabytes']) ? (float) $s['size_gigabytes'] : null,
            diskGb: isset($s['min_disk_size']) ? (int) $s['min_disk_size'] : null,
            createdAt: isset($s['created_at']) ? (string) $s['created_at'] : null,
        ), $client->all($path.'/snapshots', 'snapshots'));
        usort($done, fn (Snapshot $a, Snapshot $b) => strcmp((string) $b->createdAt, (string) $a->createdAt));

        return [...$taking, ...$done];
    }

    // DigitalOcean only lists a snapshot once it's taken, so until then it's the action taking it.
    public function snapshot(Integration $integration, string $serverId, string $name): Snapshot
    {
        $action = $this->act($integration, $serverId, 'snapshot', ['name' => $name]);

        return new Snapshot(self::PENDING.$action, $name, 'creating', null, null, now()->toIso8601String());
    }

    public function deleteSnapshot(Integration $integration, string $snapshotId): void
    {
        $this->client($integration)->delete('snapshots/'.rawurlencode($snapshotId));
    }

    public function delete(Integration $integration, string $id): void
    {
        $this->client($integration)->delete('droplets/'.rawurlencode($id));
    }

    private function client(Integration $integration): DigitalOceanClient
    {
        return new DigitalOceanClient($integration->credential('token'));
    }

    /** @param  array<string, mixed>  $body */
    private function act(Integration $integration, string $id, string $type, array $body = []): ?string
    {
        $response = $this->client($integration)->post('droplets/'.rawurlencode($id).'/actions', ['type' => $type, ...$body]);
        $actionId = $response['action']['id'] ?? null;

        return is_int($actionId) || is_string($actionId) ? (string) $actionId : null;
    }

    /** @param  array<string, mixed>  $d */
    private function toServer(array $d): Server
    {
        $size = (array) ($d['size'] ?? []);
        $region = (array) ($d['region'] ?? []);
        $location = $this->toLocation($region);

        return new Server(
            id: (string) ($d['id'] ?? ''),
            name: (string) ($d['name'] ?? ''),
            status: match ($d['status'] ?? null) {
                'active' => 'running',
                'new' => 'starting',
                'off' => 'off',
                default => 'other',
            },
            type: (string) ($d['size_slug'] ?? $size['slug'] ?? ''),
            typeLabel: (string) ($d['size_slug'] ?? $size['slug'] ?? ''),
            cores: (int) ($d['vcpus'] ?? $size['vcpus'] ?? 0),
            memoryGb: round((float) ($d['memory'] ?? $size['memory'] ?? 0) / 1024, 2),
            diskGb: (int) ($d['disk'] ?? $size['disk'] ?? 0),
            location: $location->name,
            city: $location->city !== '' ? $location->city : null,
            country: $location->country !== '' ? $location->country : null,
            ipv4: $this->address($d, 'v4'),
            ipv6: ($v6 = $this->address($d, 'v6')) === null ? null : $v6.'/124',
            architecture: 'x86',
            createdAt: isset($d['created_at']) ? (string) $d['created_at'] : null,
        );
    }

    /** @param  array<string, mixed>  $droplet */
    private function address(array $droplet, string $family): ?string
    {
        foreach ((array) ($droplet['networks'][$family] ?? []) as $network) {
            if (is_array($network) && ($network['type'] ?? null) === 'public' && is_string($network['ip_address'] ?? null)) {
                return $network['ip_address'];
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $region */
    private function toLocation(array $region): ServerLocation
    {
        $slug = (string) ($region['slug'] ?? '');

        return new ServerLocation(
            $slug,
            trim((string) preg_replace('/\s*\d+$/', '', (string) ($region['name'] ?? $slug))),
            self::COUNTRIES[substr($slug, 0, 3)] ?? '',
        );
    }

    /**
     * @param  array<string, mixed>  $size
     * @param  list<string>  $open  regions that take new droplets
     */
    private function toType(array $size, array $open): ?ServerType
    {
        $slug = (string) ($size['slug'] ?? '');
        if ($slug === '' || ($size['available'] ?? false) !== true || str_starts_with($slug, 'gpu-') || isset($size['gpu_info'])) {
            return null;
        }
        $locations = array_values(array_intersect(array_map('strval', (array) ($size['regions'] ?? [])), $open));
        if ($locations === []) {
            return null;
        }
        $price = ['monthly' => (float) ($size['price_monthly'] ?? 0), 'hourly' => (float) ($size['price_hourly'] ?? 0)];

        return new ServerType(
            name: $slug,
            label: $slug,
            cores: (int) ($size['vcpus'] ?? 0),
            memoryGb: round((float) ($size['memory'] ?? 0) / 1024, 2),
            diskGb: (int) ($size['disk'] ?? 0),
            cpu: str_starts_with($slug, 's-') ? 'shared' : 'dedicated',
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
        $os = fn (array $image): string => strtolower((string) ($image['distribution'] ?? ''));
        $version = fn (array $image): string => preg_match('/^(\d+(?:\.\d+)?)/', (string) ($image['name'] ?? ''), $m) === 1 ? $m[1] : '0';
        usort($images, fn (array $a, array $b) => array_search($os($a), self::SYSTEMS, true) <=> array_search($os($b), self::SYSTEMS, true)
            ?: version_compare($version($b), $version($a)));

        $lts = null;
        foreach ($images as $image) {
            if ($lts === null && $os($image) === 'ubuntu' && str_contains((string) ($image['name'] ?? ''), 'LTS')) {
                $lts = (string) $image['slug'];
            }
        }

        return array_map(fn (array $image) => new ServerImage(
            id: (string) $image['slug'],
            label: trim(($image['distribution'] ?? '').' '.($image['name'] ?? '')),
            os: $os($image),
            version: $version($image) === '0' ? null : $version($image),
            architecture: 'x86',
            recommended: $image['slug'] === $lts,
        ), $images);
    }
}
