<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * A site's traffic for a minute or an hour, counted by the daemon from the
 * proxy's access logs. Minutes come in on the telemetry envelope, are kept
 * for two days and are rolled into hours, which are kept for 90 days.
 *
 * `site` is the app's site-file stem: its name with anything outside
 * [A-Za-z0-9_-] turned into "-". `visitors` are the visitors first seen that
 * UTC day in the bucket, so a day's buckets add up to its unique visitors.
 *
 * @property int $id
 * @property int $machine_id
 * @property string $site
 * @property int $bucket unix time the bucket starts at
 * @property int $seconds 60 or 3600
 * @property int $requests
 * @property int $status_2xx
 * @property int $status_3xx
 * @property int $status_4xx
 * @property int $status_5xx
 * @property int $bytes
 * @property float|null $p50_ms
 * @property float|null $p95_ms
 * @property int $visitors
 * @property string|null $paths JSON [name, count] pairs
 * @property string|null $referrers JSON [host, count] pairs
 * @property bool $rolled
 */
class TrafficRollup extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['rolled' => 'boolean'];
    }

    public const MINUTE = 60;

    public const HOUR = 3600;

    /** The most rollups one request may carry. */
    public const MAX_PER_REQUEST = 2000;

    /** Minutes are kept this long, then only their hours are. */
    public const KEEP_MINUTES_SECONDS = 172800;

    /** Minutes older than this are turned away, so they're rolled up before they're pruned. */
    public const ACCEPT_SECONDS = 169200;

    public const KEEP_HOURS_DAYS = 90;

    /** The top paths and referrers an hour keeps. */
    public const TOP_KEPT = 20;

    private const MAX_COUNT = 1_000_000_000_000;

    private const MAX_MS = 3_600_000;

    private const COUNTERS = ['requests', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx', 'bytes', 'visitors'];

    /** The site-file stem of an app: what the daemon calls its site. */
    public static function siteOf(string $app): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_-]/', '-', $app), 0, 120);
    }

    /**
     * A site key as the daemon sends it: an app's stem, or a site of the
     * machine's own web server, whose key has a dot (ExistingSites).
     */
    public static function keyOf(string $site): string
    {
        return substr((string) preg_replace('/[^A-Za-z0-9_.-]/', '-', $site), 0, 120);
    }

    /**
     * Stores a batch of per-minute rollups from the daemon, in one upsert. A
     * minute sent again replaces the one kept. Returns how many were kept.
     *
     * @param  array<mixed>  $rollups
     */
    public static function ingest(Machine $machine, array $rollups): int
    {
        $now = now()->getTimestamp();
        $rows = [];
        foreach (array_slice(array_values($rollups), 0, self::MAX_PER_REQUEST) as $rollup) {
            if (! is_array($rollup) || ! is_string($rollup['site'] ?? null) || ! is_int($rollup['minute'] ?? null)) {
                continue;
            }
            $site = self::keyOf($rollup['site']);
            $minute = $rollup['minute'];
            if ($site === '' || $minute % 60 !== 0 || $minute > $now + 120 || $minute < $now - self::ACCEPT_SECONDS) {
                continue;
            }

            $row = ['machine_id' => $machine->id, 'site' => $site, 'bucket' => $minute, 'seconds' => self::MINUTE];
            foreach (self::COUNTERS as $counter) {
                $row[$counter] = self::count($rollup[$counter] ?? null);
            }
            $row['p50_ms'] = self::ms($rollup['p50_ms'] ?? null);
            $row['p95_ms'] = self::ms($rollup['p95_ms'] ?? null);
            $row['paths'] = self::encode(self::top($rollup['paths'] ?? null, 500));
            $row['referrers'] = self::encode(self::top($rollup['referrers'] ?? null, 253));
            $row['rolled'] = false;
            // Sent twice in one batch: the last one counts.
            $rows[$site.'|'.$minute] = $row;
        }

        $update = [...self::COUNTERS, 'p50_ms', 'p95_ms', 'paths', 'referrers', 'rolled'];
        foreach (array_chunk(array_values($rows), 500) as $chunk) {
            self::query()->upsert($chunk, ['machine_id', 'site', 'seconds', 'bucket'], $update);
        }

        return count($rows);
    }

    /**
     * Counts minutes that came in since the last run into their hours. An
     * hour is worked out again from all its minutes, so it's right however
     * late or often they came. Returns how many hours were written.
     */
    public static function rollUp(): int
    {
        $written = 0;
        $hours = self::query()
            ->where('rolled', false)
            ->where('seconds', self::MINUTE)
            ->selectRaw('bucket - (bucket % 3600) as hour')
            ->distinct()
            ->pluck('hour')
            ->map(fn ($hour) => (int) $hour)
            ->sort()
            ->values();

        foreach ($hours as $hour) {
            $minutes = self::query()
                ->where('seconds', self::MINUTE)
                ->where('bucket', '>=', $hour)
                ->where('bucket', '<', $hour + 3600)
                ->get(['id', 'machine_id', 'site', ...self::COUNTERS, 'p50_ms', 'p95_ms', 'paths', 'referrers', 'rolled'])
                ->groupBy(fn (self $row) => $row->machine_id.'|'.$row->site)
                ->filter(fn ($rows) => $rows->contains(fn (self $row) => ! $row->rolled));

            $rows = [];
            $ids = [];
            foreach ($minutes as $group) {
                /** @var self $first */
                $first = $group->first();
                $hourly = self::combine($group->all(), self::TOP_KEPT);
                $rows[] = [
                    'machine_id' => $first->machine_id,
                    'site' => $first->site,
                    'bucket' => $hour,
                    'seconds' => self::HOUR,
                    ...$hourly,
                    'paths' => self::encode($hourly['paths']),
                    'referrers' => self::encode($hourly['referrers']),
                    'rolled' => true,
                ];
                foreach ($group as $row) {
                    if (! $row->rolled) {
                        $ids[] = $row->id;
                    }
                }
            }

            foreach (array_chunk($rows, 500) as $chunk) {
                self::query()->upsert($chunk, ['machine_id', 'site', 'seconds', 'bucket'], [...self::COUNTERS, 'p50_ms', 'p95_ms', 'paths', 'referrers', 'rolled']);
            }
            foreach (array_chunk($ids, 1000) as $chunk) {
                self::query()->whereIn('id', $chunk)->update(['rolled' => true]);
            }
            $written += count($rows);
        }

        return $written;
    }

    /** Deletes minutes past two days and hours past 90 days. */
    public static function prune(): int
    {
        $now = now()->getTimestamp();

        return self::query()->where('seconds', self::MINUTE)->where('bucket', '<', $now - self::KEEP_MINUTES_SECONDS)->delete()
            + self::query()->where('seconds', self::HOUR)->where('bucket', '<', $now - self::KEEP_HOURS_DAYS * 86400)->delete();
    }

    /**
     * Rows added together: counters summed, response times weighted by
     * requests, and the top lists merged.
     *
     * @param  iterable<self>  $rows
     * @return array{requests: int, status_2xx: int, status_3xx: int, status_4xx: int, status_5xx: int, bytes: int, visitors: int, p50_ms: float|null, p95_ms: float|null, paths: list<array{0: string, 1: int}>, referrers: list<array{0: string, 1: int}>}
     */
    public static function combine(iterable $rows, int $keep): array
    {
        $sums = ['requests' => 0, 'status_2xx' => 0, 'status_3xx' => 0, 'status_4xx' => 0, 'status_5xx' => 0, 'bytes' => 0, 'visitors' => 0];
        $weighted = ['p50_ms' => [0.0, 0], 'p95_ms' => [0.0, 0]];
        $lists = ['paths' => [], 'referrers' => []];

        foreach ($rows as $row) {
            foreach (array_keys($sums) as $counter) {
                $sums[$counter] += (int) $row->{$counter};
            }
            foreach (['p50_ms', 'p95_ms'] as $key) {
                if ($row->{$key} !== null) {
                    $weight = max(1, (int) $row->requests);
                    $weighted[$key][0] += (float) $row->{$key} * $weight;
                    $weighted[$key][1] += $weight;
                }
            }
            foreach (['paths', 'referrers'] as $key) {
                foreach (self::decode($row->{$key}) as [$name, $count]) {
                    $lists[$key][$name] = ($lists[$key][$name] ?? 0) + $count;
                }
            }
        }

        /** @return list<array{0: string, 1: int}> */
        $top = function (array $counts) use ($keep): array {
            arsort($counts);
            $pairs = [];
            foreach (array_slice($counts, 0, $keep, true) as $name => $count) {
                $pairs[] = [(string) $name, $count];
            }

            return $pairs;
        };

        return [
            ...$sums,
            'p50_ms' => $weighted['p50_ms'][1] > 0 ? round($weighted['p50_ms'][0] / $weighted['p50_ms'][1], 2) : null,
            'p95_ms' => $weighted['p95_ms'][1] > 0 ? round($weighted['p95_ms'][0] / $weighted['p95_ms'][1], 2) : null,
            'paths' => $top($lists['paths']),
            'referrers' => $top($lists['referrers']),
        ];
    }

    /** @return list<array{0: string, 1: int}> */
    public static function decode(mixed $json): array
    {
        $pairs = is_string($json) ? json_decode($json, true) : null;
        if (! is_array($pairs)) {
            return [];
        }

        $out = [];
        foreach ($pairs as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null) && is_int($pair[1] ?? null)) {
                $out[] = [$pair[0], $pair[1]];
            }
        }

        return $out;
    }

    private static function count(mixed $value): int
    {
        return is_int($value) || is_float($value) ? (int) max(0, min(self::MAX_COUNT, $value)) : 0;
    }

    private static function ms(mixed $value): ?float
    {
        return is_int($value) || is_float($value) ? round(max(0, min(self::MAX_MS, (float) $value)), 2) : null;
    }

    /**
     * The first 20 [name, count] pairs that make sense, names cut to a length.
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function top(mixed $pairs, int $length): array
    {
        if (! is_array($pairs)) {
            return [];
        }

        $out = [];
        foreach (array_slice(array_values($pairs), 0, self::TOP_KEPT) as $pair) {
            if (! is_array($pair) || ! is_string($pair[0] ?? null) || $pair[0] === '' || ! is_numeric($pair[1] ?? null)) {
                continue;
            }
            $count = self::count($pair[1] + 0);
            if ($count > 0) {
                $out[] = [mb_strcut($pair[0], 0, $length), $count];
            }
        }

        return $out;
    }

    /** @param  list<array{0: string, 1: int}>  $pairs */
    private static function encode(array $pairs): ?string
    {
        if ($pairs === []) {
            return null;
        }

        return json_encode($pairs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?: null;
    }
}
