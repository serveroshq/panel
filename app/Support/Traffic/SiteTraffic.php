<?php

namespace App\Support\Traffic;

use App\Mcp\Tools\Deploy\Deploys;
use App\Models\Machine;
use App\Models\TrafficRollup;
use App\Support\Deploys\DeployRequest;

/**
 * An app's traffic over a range, as the Traffic tab, get_site_traffic and
 * the API show it: totals, a series of points, and the top paths and
 * referrers. 24 hours come from the minutes, longer ranges from the hours.
 */
final class SiteTraffic
{
    /** @var array<string, array{window: int, step: int, source: int}> */
    public const RANGES = [
        '24h' => ['window' => 86400, 'step' => 900, 'source' => TrafficRollup::MINUTE],
        '7d' => ['window' => 604800, 'step' => 3600, 'source' => TrafficRollup::HOUR],
        '30d' => ['window' => 2592000, 'step' => 21600, 'source' => TrafficRollup::HOUR],
    ];

    public const TOP = 10;

    /**
     * What the app's page needs to show the tab, without the numbers.
     *
     * @param  array<mixed>  $domains  the app's domains, as its last deploy had them
     * @return array{site: string, domain: string|null, collecting: bool, blocked: string|null}
     */
    public static function state(Machine $machine, string $app, array $domains): array
    {
        return [
            'site' => TrafficRollup::siteOf($app),
            'domain' => DeployRequest::plainDomains(array_values(array_filter($domains, 'is_string')))[0] ?? null,
            'collecting' => TrafficSwitch::on($machine),
            'blocked' => TrafficSwitch::blocked($machine),
        ];
    }

    /**
     * The app's domains, as its last deploy had them.
     *
     * @return list<string>
     */
    public static function domains(Machine $machine, string $app): array
    {
        return array_values(array_filter((array) (Deploys::lastDeploy($machine, $app)?->command['job']['domains'] ?? []), 'is_string'));
    }

    /**
     * @return array{range: string, step: int, from: int, to: int, hasData: bool, summary: array{requests: int, status: array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}, errorRate: float|null, clientErrorRate: float|null, bytes: int, p50: float|null, p95: float|null, visitors: int}, series: list<array{ts: int, requests: int|null, s2xx: int|null, s3xx: int|null, s4xx: int|null, s5xx: int|null, bytes: int|null, p50: float|null, p95: float|null, visitors: int|null}>, paths: list<array{name: string, requests: int, share: float}>, referrers: list<array{name: string, requests: int, share: float}>}
     */
    public static function report(Machine $machine, string $app, string $range): array
    {
        return self::reportSite($machine, TrafficRollup::siteOf($app), $range);
    }

    /**
     * The same report for a site by its key: an app's (siteOf) or a site of
     * the machine's own web server (ExistingSites).
     *
     * @return array{range: string, step: int, from: int, to: int, hasData: bool, summary: array{requests: int, status: array{'2xx': int, '3xx': int, '4xx': int, '5xx': int}, errorRate: float|null, clientErrorRate: float|null, bytes: int, p50: float|null, p95: float|null, visitors: int}, series: list<array{ts: int, requests: int|null, s2xx: int|null, s3xx: int|null, s4xx: int|null, s5xx: int|null, bytes: int|null, p50: float|null, p95: float|null, visitors: int|null}>, paths: list<array{name: string, requests: int, share: float}>, referrers: list<array{name: string, requests: int, share: float}>}
     */
    public static function reportSite(Machine $machine, string $site, string $range): array
    {
        $range = array_key_exists($range, self::RANGES) ? $range : '24h';
        ['window' => $window, 'step' => $step, 'source' => $source] = self::RANGES[$range];
        $now = now()->getTimestamp();
        $first = intdiv($now - $window, $step) * $step + $step;

        $base = fn () => TrafficRollup::query()
            ->where('machine_id', $machine->id)
            ->where('site', $site)
            ->where('seconds', $source)
            ->where('bucket', '>=', $first);

        $slots = $base()
            ->selectRaw('bucket - (bucket % ?) as slot', [$step])
            ->selectRaw('sum(requests) as requests, sum(status_2xx) as s2xx, sum(status_3xx) as s3xx, sum(status_4xx) as s4xx, sum(status_5xx) as s5xx, sum(bytes) as bytes, sum(visitors) as visitors')
            ->selectRaw('sum(case when p50_ms is null then 0 else p50_ms * requests end) as p50_sum, sum(case when p50_ms is null then 0 else requests end) as p50_weight')
            ->selectRaw('sum(case when p95_ms is null then 0 else p95_ms * requests end) as p95_sum, sum(case when p95_ms is null then 0 else requests end) as p95_weight')
            ->groupBy('slot')
            ->toBase()
            ->get()
            ->keyBy(fn (object $row) => (int) $row->slot);

        $weighted = fn (mixed $sum, mixed $weight) => (float) $weight > 0 ? round((float) $sum / (float) $weight, 1) : null;
        $series = [];
        $started = false;
        for ($ts = $first; $ts <= $now; $ts += $step) {
            $row = $slots->get($ts);
            $started = $started || $row !== null;
            // Before the first visit there's nothing to say; after it, a gap is a quiet spell.
            $count = fn (string $key) => $row !== null ? (int) $row->{$key} : ($started ? 0 : null);
            $series[] = [
                'ts' => $ts,
                'requests' => $count('requests'),
                's2xx' => $count('s2xx'),
                's3xx' => $count('s3xx'),
                's4xx' => $count('s4xx'),
                's5xx' => $count('s5xx'),
                'bytes' => $count('bytes'),
                'p50' => $row === null ? null : $weighted($row->p50_sum, $row->p50_weight),
                'p95' => $row === null ? null : $weighted($row->p95_sum, $row->p95_weight),
                'visitors' => $count('visitors'),
            ];
        }

        $totals = TrafficRollup::combine($base()->get(['requests', 'status_2xx', 'status_3xx', 'status_4xx', 'status_5xx', 'bytes', 'visitors', 'p50_ms', 'p95_ms', 'paths', 'referrers']), self::TOP);
        $requests = $totals['requests'];
        /** @param  list<array{0: string, 1: int}>  $pairs */
        $share = fn (array $pairs) => array_values(array_map(fn (array $pair) => [
            'name' => $pair[0],
            'requests' => $pair[1],
            'share' => $requests > 0 ? round(min(1, $pair[1] / $requests), 4) : 0.0,
        ], $pairs));

        return [
            'range' => $range,
            'step' => $step,
            'from' => $first,
            'to' => $now,
            'hasData' => $slots->isNotEmpty(),
            'summary' => [
                'requests' => $requests,
                'status' => ['2xx' => $totals['status_2xx'], '3xx' => $totals['status_3xx'], '4xx' => $totals['status_4xx'], '5xx' => $totals['status_5xx']],
                'errorRate' => $requests > 0 ? round($totals['status_5xx'] / $requests, 4) : null,
                'clientErrorRate' => $requests > 0 ? round($totals['status_4xx'] / $requests, 4) : null,
                'bytes' => $totals['bytes'],
                'p50' => $totals['p50_ms'] === null ? null : round($totals['p50_ms'], 1),
                'p95' => $totals['p95_ms'] === null ? null : round($totals['p95_ms'], 1),
                'visitors' => $totals['visitors'],
            ],
            'series' => $series,
            'paths' => $share($totals['paths']),
            'referrers' => $share($totals['referrers']),
        ];
    }

    /**
     * Each site's last 24 hours on the machine, by site key: requests,
     * client and server errors and visitors.
     *
     * @return array<string, array{requests: int, errors: int, clientErrors: int, visitors: int}>
     */
    public static function lastDay(Machine $machine): array
    {
        $from = intdiv(now()->getTimestamp() - 86400, 60) * 60 + 60;

        return TrafficRollup::query()
            ->where('machine_id', $machine->id)
            ->where('seconds', TrafficRollup::MINUTE)
            ->where('bucket', '>=', $from)
            ->groupBy('site')
            ->select('site')
            ->selectRaw('sum(requests) as requests, sum(status_5xx) as errors, sum(status_4xx) as client_errors, sum(visitors) as visitors')
            ->toBase()
            ->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->site => [
                'requests' => (int) $row->requests,
                'errors' => (int) $row->errors,
                'clientErrors' => (int) $row->client_errors,
                'visitors' => (int) $row->visitors,
            ]])
            ->all();
    }

    /**
     * Every site the machine counts, ServerOS's apps and the sites of its
     * own web server, with their last 24 hours: what the machine's traffic
     * table and list_site_traffic show. `url` is the path of the page that
     * shows the site.
     *
     * @return list<array{site: string, kind: string, name: string, server: string, container: string|null, names: list<string>, logs: list<string>, shared: bool, timed: bool|null, catchAll: bool, unlogged: string|null, url: string|null, requests: int, errors: int, clientErrors: int, visitors: int}>
     */
    public static function sites(Machine $machine): array
    {
        $numbers = self::lastDay($machine);
        $zero = ['requests' => 0, 'errors' => 0, 'clientErrors' => 0, 'visitors' => 0];
        $rows = [];

        $apps = [];
        foreach ((array) ($machine->inventory['services'] ?? []) as $service) {
            if (is_array($service) && preg_match('/^\/?serveros-(.+)-[0-9a-f]{12}(?:-b)?$/', (string) ($service['name'] ?? ''), $match)) {
                $apps[$match[1]] = true;
            }
        }
        foreach (array_keys($apps) as $app) {
            $app = (string) $app;
            $domains = DeployRequest::plainDomains(self::domains($machine, $app));
            $site = TrafficRollup::siteOf($app);
            if ($domains === [] && ! isset($numbers[$site])) {
                continue;
            }
            $rows[] = [
                'site' => $site,
                'kind' => 'app',
                'name' => $app,
                'server' => 'ServerOS',
                'container' => null,
                'names' => $domains,
                'logs' => [],
                'shared' => false,
                'timed' => true,
                'catchAll' => false,
                'unlogged' => null,
                'url' => "/machines/{$machine->id}/services/app:{$app}/traffic",
                ...($numbers[$site] ?? $zero),
            ];
        }

        if (ExistingSites::available($machine)) {
            foreach (ExistingSites::all($machine) as $site) {
                $rows[] = [
                    'site' => $site['site'],
                    'kind' => 'existing',
                    'name' => ExistingSites::label($site),
                    'server' => ExistingSites::SERVERS[$site['server']],
                    'container' => $site['container'],
                    'names' => $site['names'],
                    'logs' => $site['logs'],
                    'shared' => $site['shared'],
                    'timed' => $site['timed'],
                    'catchAll' => $site['catchAll'],
                    'unlogged' => $site['unlogged'],
                    'url' => ExistingSites::url($machine, $site),
                    ...($numbers[$site['site']] ?? $zero),
                ];
            }
        }

        // Busiest first; sites with no log after the ones counted.
        usort($rows, fn (array $a, array $b) => [$a['unlogged'] !== null, $b['requests'], $a['name']] <=> [$b['unlogged'] !== null, $a['requests'], $b['name']]);

        return $rows;
    }
}
