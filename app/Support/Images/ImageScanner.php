<?php

namespace App\Support\Images;

use App\Models\ContainerImage;
use App\Models\DaemonJob;
use App\Models\ImageVulnerability;
use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\Security\Ecosystems;
use App\Support\Security\Feeds;
use App\Support\Security\Vulnerabilities;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Known vulnerabilities in the images containers run: the machine reads
 * each image's OS release and installed packages (dpkg or apk), and OSV
 * says which known vulnerabilities affect those versions. Only ones the
 * image's own distribution has fixed are kept, so every finding goes away
 * with a newer image (or a rebuild of it).
 *
 * In two halves, a run apart: `queue` asks machines for the packages,
 * `process` looks up whatever's come back.
 */
class ImageScanner
{
    public const int RESCAN_HOURS = 12;

    private const int PER_MACHINE = 4;

    public function __construct(private readonly Feeds $feeds) {}

    /** Asks machines for the packages of images not read lately. Returns how many it asked about. */
    public function queue(int $machines = 50): int
    {
        $due = ContainerImage::query()
            ->whereNotNull('image_id')
            ->whereNull('scan_job_id')
            ->where(fn ($q) => $q->whereNull('scanned_at')->orWhere('scanned_at', '<', now()->subHours(self::RESCAN_HOURS)))
            ->with('machine.workspace')
            ->orderByRaw('scanned_at is not null')
            ->orderBy('scanned_at')
            ->get()
            ->groupBy('machine_id')
            ->take($machines);

        $queue = app(DaemonJobQueue::class);
        $asked = 0;
        foreach ($due as $rows) {
            $machine = $rows->first()?->machine;
            $owner = $machine?->workspace->owner;
            if ($machine === null || $owner === null || ! DaemonJobQueue::canManageImages($machine) || $queue->unavailable($machine) !== null) {
                continue;
            }
            foreach ($rows->groupBy('image_id')->take(self::PER_MACHINE) as $imageId => $same) {
                try {
                    $job = $queue->queue($machine, $owner, 'image_packages', ['type' => 'image_packages', 'image' => (string) $imageId], null, 120);
                } catch (RuntimeException) {
                    continue;
                }
                ContainerImage::query()->whereKey($same->pluck('id'))->update(['scan_job_id' => $job->id]);
                $asked++;
            }
        }

        return $asked;
    }

    /** Looks up the packages machines have sent back. Returns how many new findings there were. */
    public function process(int $limit = 40): int
    {
        $pending = ContainerImage::query()->whereNotNull('scan_job_id')->get()->groupBy('scan_job_id');
        if ($pending->isEmpty()) {
            return 0;
        }
        $jobs = DaemonJob::query()->whereIn('id', $pending->keys())->get()->keyBy('id');

        $found = 0;
        $done = 0;
        foreach ($pending as $jobId => $rows) {
            $job = $jobs->get($jobId);
            $first = $rows->first();
            if ($first === null) {
                continue;
            }
            // Never answered: ask again next time.
            if ($job === null || ($job->isStale() && in_array($job->state, ['queued', 'running'], true))) {
                ContainerImage::query()->whereKey($rows->pluck('id'))->update(['scan_job_id' => null]);

                continue;
            }
            if (in_array($job->state, ['queued', 'running'], true) || $done >= $limit) {
                continue;
            }
            if ($job->state === 'succeeded' && is_array($job->result)) {
                $found += $this->match($first->machine_id, (string) $first->image_id, (string) $first->image, $job->result);
            }
            ContainerImage::query()->whereKey($rows->pluck('id'))->update(['scan_job_id' => null, 'scanned_at' => now()]);
            $done++;
        }

        return $found;
    }

    /**
     * One image's packages against OSV, replacing its findings.
     *
     * @param  array<string, mixed>  $result  the image_packages job's result
     */
    public function match(int $machineId, string $imageId, string $image, array $result): int
    {
        $os = is_array($result['os'] ?? null) ? $result['os'] : [];
        $ecosystem = Ecosystems::forRelease((string) ($os['id'] ?? ''), (string) ($os['version_id'] ?? ''));
        $packages = is_array($result['packages'] ?? null) ? $result['packages'] : [];
        if ($ecosystem === null || $packages === []) {
            return $this->replace($machineId, $imageId, $image, []);
        }

        // Source packages and their versions: binaries built from one source share its entries.
        $queries = [];
        foreach ($packages as $package) {
            $source = is_array($package) ? ($package['source'] ?? $package['name'] ?? null) : null;
            $version = is_array($package) ? ($package['source_version'] ?? $package['version'] ?? null) : null;
            if (! is_string($source) || ! is_string($version) || ! preg_match('/^[a-z0-9][a-z0-9+._-]*$/i', $source)
                || preg_match('/^(linux|kernel)(-|$)/', $source)) {
                continue;
            }
            $queries[$source."\0".$version] = ['ecosystem' => $ecosystem, 'name' => $source, 'version' => $version];
        }
        $list = array_values($queries);
        $affecting = $this->feeds->osvAffecting($list);
        $entries = $this->feeds->osvEntries(array_values(array_unique(array_merge(...($affecting ?: [[]])))));

        $findings = [];
        foreach ($list as $i => $query) {
            foreach ($affecting[$i] ?? [] as $osvId) {
                foreach ($entries[$osvId] ?? [] as $entry) {
                    $fixed = $entry['fixed'][$ecosystem.'|'.$query['name']] ?? null;
                    if (! is_string($fixed)) {
                        // Not fixed in the distribution yet: nothing a newer image would change.
                        continue;
                    }
                    $vulnerability = Vulnerabilities::record($entry);
                    $findings[$vulnerability->id.'|'.$query['name']] = [
                        'vulnerability_id' => $vulnerability->id,
                        'source' => $query['name'],
                        'installed' => $query['version'],
                        'fixed_in' => $fixed,
                    ];
                }
            }
        }

        return $this->replace($machineId, $imageId, $image, $findings);
    }

    /**
     * @param  array<string, array{vulnerability_id: int, source: string, installed: string, fixed_in: string}>  $findings
     */
    private function replace(int $machineId, string $imageId, string $image, array $findings): int
    {
        $now = now();
        $new = 0;
        DB::transaction(function () use ($machineId, $imageId, $image, $findings, $now, &$new) {
            $existing = ImageVulnerability::query()->where('machine_id', $machineId)->where('image_id', $imageId)->get()
                ->keyBy(fn (ImageVulnerability $f) => $f->vulnerability_id.'|'.$f->source);
            $keep = [];
            foreach ($findings as $key => $finding) {
                $values = ['image' => mb_substr($image, 0, 512), 'installed' => mb_substr($finding['installed'], 0, 128), 'fixed_in' => mb_substr($finding['fixed_in'], 0, 128), 'resolved_at' => null];
                $row = $existing->get($key);
                if ($row !== null) {
                    $row->fill($values)->save();
                    $keep[] = $row->id;

                    continue;
                }
                $keep[] = ImageVulnerability::query()->create([
                    'machine_id' => $machineId,
                    'image_id' => $imageId,
                    'vulnerability_id' => $finding['vulnerability_id'],
                    'source' => $finding['source'],
                    'detected_at' => $now,
                    ...$values,
                ])->id;
                $new++;
            }
            ImageVulnerability::query()->where('machine_id', $machineId)->where('image_id', $imageId)->whereNull('resolved_at')
                ->whereNotIn('id', $keep === [] ? [0] : $keep)
                ->update(['resolved_at' => $now, 'updated_at' => $now]);
        });

        return $new;
    }

    /**
     * A machine's open findings for one image, worst first.
     *
     * @return list<array<string, mixed>>
     */
    public static function findings(Machine $machine, string $imageId): array
    {
        return array_values(ImageVulnerability::query()
            ->where('machine_id', $machine->id)
            ->where('image_id', $imageId)
            ->whereNull('resolved_at')
            ->with('vulnerability')
            ->get()
            ->sortBy([fn ($a, $b) => $a->vulnerability->rank() <=> $b->vulnerability->rank(), fn ($a, $b) => strcmp($a->vulnerability->cve, $b->vulnerability->cve)])
            ->map(fn (ImageVulnerability $f) => [
                'cve' => $f->vulnerability->cve,
                'title' => $f->vulnerability->title,
                'severity' => $f->vulnerability->severity,
                'severityLabel' => $f->vulnerability->severityLabel(),
                'exploited' => $f->vulnerability->exploited,
                'epss' => $f->vulnerability->epss,
                'url' => $f->vulnerability->url(),
                'package' => $f->source,
                'installed' => $f->installed,
                'fixedIn' => $f->fixed_in,
            ])
            ->all());
    }
}
