<?php

namespace App\Support\Images;

use App\Models\ContainerImage;
use App\Models\ContainerRegistry;
use App\Models\DaemonJob;
use App\Models\ImageVulnerability;
use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Whether the containers on a machine run the newest image their tag
 * points at: the machine says which image each runs and the registry
 * digests Docker has for it, the registry says which image the tag points
 * at now. A container whose image was built on the machine, or that's
 * pinned to one exact image, has nothing newer to compare.
 */
class ImageUpdates
{
    /** How long an image update may take: a big pull, then the container's checks. */
    public const int UPDATE_TIMEOUT = 1500;

    public function __construct(private readonly RegistryClient $registry) {}

    /**
     * Machines due a check: connected, on a daemon that reports images.
     *
     * @return Collection<int, Machine>
     */
    public static function due(): Collection
    {
        return Machine::query()
            ->whereNotNull('uid')
            ->whereNull('disconnected_at')
            ->whereNotNull('inventory')
            ->where('last_seen_at', '>=', now()->subWeek())
            ->with('workspace')
            ->get()
            ->filter(fn (Machine $machine) => DaemonJobQueue::canManageImages($machine) && self::containers($machine) !== [])
            ->values();
    }

    /**
     * The containers in a machine's inventory that say which image they run.
     *
     * @return list<array{key: string, name: string, image: string, image_id: string, digests: list<string>}>
     */
    public static function containers(Machine $machine): array
    {
        $out = [];
        foreach ($machine->inventory['services'] ?? [] as $service) {
            if (! is_array($service) || ! str_starts_with((string) ($service['key'] ?? ''), 'docker:')) {
                continue;
            }
            $details = is_array($service['details'] ?? null) ? $service['details'] : [];
            if (! is_string($details['image_id'] ?? null) || ! is_string($details['image'] ?? null) || ! is_string($service['name'] ?? null)) {
                continue;
            }
            $digests = array_values(array_filter(array_map('trim', explode(',', (string) ($details['image_digests'] ?? '')))));
            $out[] = [
                'key' => (string) $service['key'],
                'name' => $service['name'],
                'image' => $details['image'],
                'image_id' => $details['image_id'],
                'digests' => $digests,
            ];
        }

        return $out;
    }

    /**
     * Checks every container on the machine against its registry and
     * records what it found. Returns how many have a newer image.
     */
    public function check(Machine $machine): int
    {
        $containers = self::containers($machine);
        $logins = $machine->workspace->containerRegistries()->get()->keyBy('host');

        // Asking the registries is the slow part, so it happens before
        // anything is written: a machine removed meanwhile is skipped.
        $found = array_map(fn (array $container) => $this->compare($container, $logins), $containers);

        return DB::transaction(function () use ($machine, $containers, $found) {
            if (! Machine::query()->whereKey($machine->id)->lockForUpdate()->exists()) {
                return 0;
            }

            $existing = $machine->containerImages()->get()->keyBy('container');
            $now = now();
            $available = 0;

            foreach ($containers as $i => $container) {
                $row = $existing->get($container['name']) ?? new ContainerImage(['machine_id' => $machine->id, 'container' => $container['name']]);
                [$state, $remote, $error] = $found[$i];

                $imageChanged = $row->image_id !== $container['image_id'];
                $row->fill([
                    'service_key' => $container['key'],
                    'image' => mb_substr($container['image'], 0, 512),
                    'image_id' => $container['image_id'],
                    'state' => $state,
                    'remote_digest' => $remote ?? ($state === 'unknown' ? $row->remote_digest : null),
                    'error' => $error === null ? null : mb_substr($error, 0, 300),
                    'checked_at' => $now,
                    'available_since' => $state === 'available'
                        ? ($row->state === 'available' && ! $imageChanged ? $row->available_since : $now)
                        : ($state === 'unknown' && ! $imageChanged ? $row->available_since : null),
                    'scanned_at' => $imageChanged ? null : $row->scanned_at,
                ])->save();
                $available += $state === 'available' ? 1 : 0;
            }

            // Containers that are gone, and findings for images nothing runs any more.
            $names = array_column($containers, 'name');
            $machine->containerImages()->whereNotIn('container', $names === [] ? [''] : $names)->delete();
            $ids = array_values(array_unique(array_column($containers, 'image_id')));
            ImageVulnerability::query()->where('machine_id', $machine->id)->whereNull('resolved_at')
                ->whereNotIn('image_id', $ids === [] ? [''] : $ids)
                ->update(['resolved_at' => $now, 'updated_at' => $now]);

            return $available;
        });
    }

    /**
     * @param  array{key: string, name: string, image: string, image_id: string, digests: list<string>}  $container
     * @param  Collection<string, ContainerRegistry>  $logins
     * @return array{0: string, 1: string|null, 2: string|null} state, the registry's digest, why it couldn't say
     */
    private function compare(array $container, Collection $logins): array
    {
        $reference = ImageReference::parse($container['image']);
        if ($reference === null) {
            return ['local', null, null];
        }
        $local = $reference->digestsIn($container['digests']);
        if ($local === []) {
            // Built on the machine, or never pulled from this registry.
            return ['local', null, null];
        }

        try {
            $remote = $this->registry->digest($reference, $logins->get($reference->registry));
        } catch (RuntimeException $e) {
            return ['unknown', null, $e->getMessage()];
        }

        return [in_array($remote, $local, true) ? 'current' : 'available', $remote, null];
    }

    public static function unavailable(Machine $machine): ?string
    {
        if (! DaemonJobQueue::canManageImages($machine)) {
            return __('Its ServerOS daemon is too old to update container images. Update it from the machine’s page first.');
        }

        return app(DaemonJobQueue::class)->unavailable($machine);
    }

    /**
     * Pulls the container's image again and recreates it on the newer one.
     *
     * @throws RuntimeException
     */
    public function update(ContainerImage $image, User $user): DaemonJob
    {
        $machine = $image->machine;
        if (($reason = self::unavailable($machine)) !== null) {
            throw new RuntimeException($reason);
        }

        return app(DaemonJobQueue::class)->queue(
            $machine,
            $user,
            'image_update',
            ['type' => 'image_update', 'service' => $image->service_key],
            $image->service_key,
            self::UPDATE_TIMEOUT,
        );
    }

    /** The update job running for a container, if there is one. */
    public static function updating(ContainerImage $image): ?DaemonJob
    {
        return DaemonJob::query()
            ->where('machine_id', $image->machine_id)
            ->where('kind', 'image_update')
            ->whereIn('state', ['queued', 'running'])
            ->where('service', $image->service_key)
            ->latest()
            ->first();
    }

    /**
     * After an update: the container runs the image it pulled, which is
     * the newest there is, under its new key.
     */
    public static function finished(DaemonJob $job): void
    {
        $result = is_array($job->result) ? $job->result : [];
        $row = ContainerImage::query()->where('machine_id', $job->machine_id)->where('service_key', $job->service)->first();
        if ($row === null || $job->state !== 'succeeded') {
            return;
        }
        $imageId = is_string($result['image_id'] ?? null) ? $result['image_id'] : $row->image_id;
        $row->forceFill([
            'service_key' => is_string($result['service'] ?? null) ? $result['service'] : $row->service_key,
            'image_id' => $imageId,
            'state' => 'current',
            'available_since' => null,
            'error' => null,
            'scanned_at' => $imageId === $row->image_id ? $row->scanned_at : null,
        ])->save();
    }

    /**
     * The workspace's containers for the Updates page: newer images first.
     *
     * @param  Collection<int, Machine>  $machines
     * @return list<array<string, mixed>>
     */
    public static function present(Collection $machines): array
    {
        $rows = ContainerImage::query()->whereIn('machine_id', $machines->pluck('id'))->orderBy('container')->get();
        $vulns = ImageVulnerability::query()
            ->whereIn('machine_id', $machines->pluck('id'))
            ->whereNull('resolved_at')
            ->with('vulnerability:id,cve,severity,exploited')
            ->get()
            ->groupBy(fn (ImageVulnerability $v) => $v->machine_id.'|'.$v->image_id);
        $updating = DaemonJob::query()->whereIn('machine_id', $machines->pluck('id'))->where('kind', 'image_update')
            ->whereIn('state', ['queued', 'running'])->get(['id', 'machine_id', 'service'])
            ->keyBy(fn (DaemonJob $job) => $job->machine_id.'|'.$job->service);
        $byId = $machines->keyBy('id');
        $order = ['available' => 0, 'unknown' => 1, 'current' => 2, 'local' => 3];

        return array_values($rows
            ->map(function (ContainerImage $row) use ($vulns, $updating, $byId) {
                $found = $vulns->get($row->machine_id.'|'.$row->image_id, collect());
                $severities = $found->map(fn (ImageVulnerability $v) => $v->vulnerability->severity);

                return [
                    'id' => $row->id,
                    'machineId' => $row->machine_id,
                    'machine' => $byId->get($row->machine_id)?->name,
                    'container' => $row->container,
                    'service' => $row->service_key,
                    'image' => $row->image,
                    'state' => $row->state,
                    'error' => $row->error,
                    'checkedAt' => $row->checked_at?->toIso8601String(),
                    'availableSince' => $row->available_since?->toIso8601String(),
                    'scannedAt' => $row->scanned_at?->toIso8601String(),
                    'updateJob' => $updating->get($row->machine_id.'|'.$row->service_key)?->id,
                    'vulnerabilities' => [
                        'total' => $found->count(),
                        'critical' => $severities->filter(fn ($s) => $s === 'critical')->count(),
                        'high' => $severities->filter(fn ($s) => $s === 'high')->count(),
                        'exploited' => $found->filter(fn (ImageVulnerability $v) => $v->vulnerability->exploited)->count(),
                    ],
                ];
            })
            ->sortBy(fn (array $row) => [$order[$row['state']] ?? 9, $row['vulnerabilities']['total'] > 0 ? 0 : 1, $row['container']])
            ->all());
    }
}
