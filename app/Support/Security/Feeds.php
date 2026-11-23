<?php

namespace App\Support\Security;

use App\Models\Vulnerability;
use Carbon\CarbonInterface;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The free vulnerability feeds, each read as its owner asks:
 *
 * - OSV (api.osv.dev): Ubuntu, Debian, AlmaLinux and Rocky's own security
 *   trackers, queried by package and version, so their own version rules
 *   decide what's affected.
 * - CISA's Known Exploited Vulnerabilities catalog: what's being used in
 *   attacks. One JSON file, fetched only when it's changed.
 * - NVD: severity scores and descriptions, and the newly published
 *   critical CVEs. Without an API key it allows 5 requests in 30 seconds,
 *   so requests are spaced out.
 * - FIRST's EPSS: the chance a CVE is exploited in the next 30 days.
 *
 * Every entry comes back as a plain array for Vulnerabilities::record().
 */
class Feeds
{
    public const string OSV = 'https://api.osv.dev/v1';

    public const string KEV = 'https://www.cisa.gov/sites/default/files/feeds/known_exploited_vulnerabilities.json';

    public const string NVD = 'https://services.nvd.nist.gov/rest/json/cves/2.0';

    public const string EPSS = 'https://api.first.org/data/v1/epss';

    /** OSV takes up to 1000 queries a request. */
    private const int OSV_BATCH = 1000;

    private ?float $lastNvd = null;

    /**
     * The OSV ids affecting each query, in the queries' order.
     *
     * @param  list<array{ecosystem: string, name: string, version: string}>  $queries
     * @return list<list<string>>
     */
    public function osvAffecting(array $queries): array
    {
        $out = [];
        foreach (array_chunk($queries, self::OSV_BATCH) as $chunk) {
            $body = ['queries' => array_map(fn (array $q) => [
                'package' => ['name' => $q['name'], 'ecosystem' => $q['ecosystem']],
                'version' => $q['version'],
            ], $chunk)];
            $results = $this->http()->timeout(60)->post(self::OSV.'/querybatch', $body)->throw()->json('results') ?? [];

            foreach ($chunk as $i => $query) {
                $result = $results[$i] ?? [];
                $ids = array_column($result['vulns'] ?? [], 'id');
                // A package with hundreds of entries comes in pages.
                $token = $result['next_page_token'] ?? null;
                for ($page = 0; is_string($token) && $token !== '' && $page < 10; $page++) {
                    $next = $this->http()->timeout(60)->post(self::OSV.'/query', [
                        'package' => ['name' => $query['name'], 'ecosystem' => $query['ecosystem']],
                        'version' => $query['version'],
                        'page_token' => $token,
                    ])->throw()->json();
                    $ids = [...$ids, ...array_column($next['vulns'] ?? [], 'id')];
                    $token = $next['next_page_token'] ?? null;
                }
                $out[] = array_values(array_unique(array_filter($ids, 'is_string')));
            }
        }

        return $out;
    }

    /**
     * OSV entries by id, parsed, each kept a day (they change rarely, and
     * the same ones come up for every machine on a release).
     *
     * @param  list<string>  $ids
     * @return array<string, array<int, array<string, mixed>>> by OSV id, the entry for each CVE it covers
     */
    public function osvEntries(array $ids): array
    {
        $out = [];
        $missing = [];
        foreach (array_unique($ids) as $id) {
            $cached = Cache::get('security:osv2:'.$id);
            is_array($cached) && array_is_list($cached) ? $out[$id] = $cached : $missing[] = $id;
        }

        foreach (array_chunk($missing, 16) as $chunk) {
            $responses = Http::pool(fn (Pool $pool) => array_map(
                fn (string $id) => $pool->as($id)->withHeaders(['User-Agent' => self::userAgent()])->timeout(30)->get(self::OSV.'/vulns/'.rawurlencode($id)),
                $chunk,
            ));
            foreach ($chunk as $id) {
                $response = $responses[$id] ?? null;
                if (! $response instanceof Response || ! $response->successful() || ! is_array($response->json())) {
                    continue;
                }
                $out[$id] = self::parseOsv($response->json());
                Cache::put('security:osv2:'.$id, $out[$id], now()->addDay());
            }
        }

        return $out;
    }

    /**
     * One OSV entry as an entry per CVE it's about (or one under its own
     * id when it names none).
     *
     * @param  array<string, mixed>  $osv
     * @return list<array<string, mixed>>
     */
    public static function parseOsv(array $osv): array
    {
        $id = (string) ($osv['id'] ?? '');
        $aliases = array_merge((array) ($osv['upstream'] ?? []), (array) ($osv['aliases'] ?? []));
        $cves = array_values(array_unique(array_filter($aliases, fn ($a) => is_string($a) && preg_match('/^CVE-\d{4}-\d+$/', $a))));
        if (preg_match('/^CVE-\d{4}-\d+$/', $id)) {
            $cves = array_values(array_unique([$id, ...$cves]));
        }

        $rating = null;
        $score = null;
        foreach ((array) ($osv['severity'] ?? []) as $severity) {
            $type = (string) ($severity['type'] ?? '');
            $value = (string) ($severity['score'] ?? '');
            if ($type === 'Ubuntu') {
                $rating = self::rating($value);
            } elseif (str_starts_with($type, 'CVSS_V3')) {
                $vector = Cvss::score($value);
                $score = $vector !== null && $vector > ($score ?? 0) ? $vector : $score;
            }
        }
        // AlmaLinux and Rocky lead with Red Hat's rating: "Important: openssl security update".
        $title = is_string($osv['summary'] ?? null) ? trim($osv['summary']) : null;
        if ($title !== null && preg_match('/^(Critical|Important|Moderate|Low):\s*(.+)$/i', $title, $m)) {
            $rating ??= self::rating($m[1]);
            $title = $m[2];
        }

        $advisories = array_values(array_filter(
            [$id, ...(array) ($osv['related'] ?? [])],
            fn ($a) => is_string($a) && preg_match('/^(USN|DSA|DLA|ALSA|RLSA|RHSA)-/', $a),
        ));
        $references = array_slice(array_values(array_filter(array_column((array) ($osv['references'] ?? []), 'url'), 'is_string')), 0, 8);
        $products = array_values(array_unique(array_filter(array_map(
            fn ($a) => is_array($a) ? ($a['package']['name'] ?? null) : null,
            (array) ($osv['affected'] ?? []),
        ), 'is_string')));

        // The first fixed version of each package it's about, by
        // ecosystem: what an image's packages need to reach.
        $fixed = [];
        foreach ((array) ($osv['affected'] ?? []) as $affected) {
            $ecosystem = $affected['package']['ecosystem'] ?? null;
            $name = $affected['package']['name'] ?? null;
            if (! is_string($ecosystem) || ! is_string($name)) {
                continue;
            }
            foreach ((array) ($affected['ranges'] ?? []) as $range) {
                foreach ((array) ($range['events'] ?? []) as $event) {
                    if (is_string($event['fixed'] ?? null) && $event['fixed'] !== '') {
                        $fixed[$ecosystem.'|'.$name] ??= $event['fixed'];
                    }
                }
            }
        }

        $base = [
            'fixed' => $fixed,
            'title' => $title !== null && $title !== '' ? Str::limit($title, 290) : null,
            'summary' => is_string($osv['details'] ?? null) && trim($osv['details']) !== '' ? Str::limit(trim($osv['details']), 2000) : null,
            'severity' => $rating ?? Vulnerability::severityFromScore($score),
            'severity_by' => $rating !== null ? 'distro' : ($score !== null ? 'cvss' : null),
            'cvss' => $score,
            'published_at' => self::date($osv['published'] ?? null),
            'advisories' => $advisories,
            'references' => $references,
            'products' => array_slice($products, 0, 6),
            'source' => 'osv',
        ];

        return array_map(fn (string $cve) => ['cve' => $cve, ...$base], $cves === [] ? [$id] : $cves);
    }

    /**
     * CISA's catalog, when it's changed since the last read (null when it
     * hasn't).
     *
     * @return list<array<string, mixed>>|null
     */
    public function kev(): ?array
    {
        $etag = Cache::get('security:kev:etag');
        $response = $this->http()->timeout(60)
            ->withHeaders(is_string($etag) ? ['If-None-Match' => $etag] : [])
            ->get(self::KEV);

        if ($response->status() === 304) {
            return null;
        }
        $response->throw();

        $out = [];
        foreach ((array) $response->json('vulnerabilities') as $row) {
            if (! is_array($row) || ! is_string($row['cveID'] ?? null)) {
                continue;
            }
            $out[] = [
                'cve' => $row['cveID'],
                'title' => is_string($row['vulnerabilityName'] ?? null) ? Str::limit($row['vulnerabilityName'], 290) : null,
                'summary' => is_string($row['shortDescription'] ?? null) ? Str::limit($row['shortDescription'], 2000) : null,
                'exploited_since' => is_string($row['dateAdded'] ?? null) ? $row['dateAdded'] : null,
                'ransomware' => strtolower((string) ($row['knownRansomwareCampaignUse'] ?? '')) === 'known',
                'products' => array_values(array_filter([$row['vendorProject'] ?? null, $row['product'] ?? null], 'is_string')),
                'source' => 'kev',
            ];
        }

        $tag = $response->header('ETag');
        if ($tag !== '') {
            Cache::forever('security:kev:etag', $tag);
        }

        return $out;
    }

    /**
     * One CVE as NVD has it, or null if NVD doesn't (yet).
     *
     * @return array<string, mixed>|null
     */
    public function nvdCve(string $cve): ?array
    {
        $rows = $this->nvd(['cveId' => $cve]);

        return $rows[0] ?? null;
    }

    /**
     * Critical CVEs NVD published between the two times, at most 2000.
     *
     * @return list<array<string, mixed>>
     */
    public function nvdCritical(CarbonInterface $from, CarbonInterface $to): array
    {
        return $this->nvd([
            'pubStartDate' => $from->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'pubEndDate' => $to->copy()->utc()->format('Y-m-d\TH:i:s.v\Z'),
            'cvssV3Severity' => 'CRITICAL',
            'resultsPerPage' => 2000,
        ]);
    }

    /**
     * @param  array<string, string|int>  $query
     * @return list<array<string, mixed>>
     */
    private function nvd(array $query): array
    {
        $this->spaceNvd();
        $key = config('services.nvd.key');
        $response = $this->http()->timeout(60)
            ->withHeaders(filled($key) ? ['apiKey' => (string) $key] : [])
            ->get(self::NVD, $query);
        if ($response->status() === 404) {
            return [];
        }
        $response->throw();

        return array_values(array_filter(array_map(
            fn ($row) => is_array($row['cve'] ?? null) ? self::parseNvd($row['cve']) : null,
            (array) $response->json('vulnerabilities'),
        )));
    }

    /**
     * @param  array<string, mixed>  $cve
     * @return array<string, mixed>|null
     */
    public static function parseNvd(array $cve): ?array
    {
        if (! is_string($cve['id'] ?? null)) {
            return null;
        }

        $summary = null;
        foreach ((array) ($cve['descriptions'] ?? []) as $description) {
            if (($description['lang'] ?? '') === 'en' && is_string($description['value'] ?? null)) {
                $summary = Str::limit(trim($description['value']), 2000);
                break;
            }
        }

        // NVD's own score first, then the reporter's; newest CVSS version first.
        $score = null;
        foreach (['cvssMetricV40', 'cvssMetricV31', 'cvssMetricV30'] as $version) {
            $metrics = (array) ($cve['metrics'][$version] ?? []);
            usort($metrics, fn ($a, $b) => (($a['type'] ?? '') === 'Primary' ? 0 : 1) <=> (($b['type'] ?? '') === 'Primary' ? 0 : 1));
            $value = $metrics[0]['cvssData']['baseScore'] ?? null;
            if (is_numeric($value)) {
                $score = (float) $value;
                break;
            }
        }

        $products = [];
        foreach ((array) ($cve['configurations'] ?? []) as $config) {
            foreach ((array) ($config['nodes'] ?? []) as $node) {
                foreach ((array) ($node['cpeMatch'] ?? []) as $match) {
                    $parts = explode(':', (string) ($match['criteria'] ?? ''));
                    if (count($parts) > 4) {
                        $products[] = $parts[3];
                        $products[] = $parts[4];
                    }
                }
            }
        }
        foreach ((array) ($cve['affected'] ?? []) as $affected) {
            foreach ((array) ($affected['affectedData'] ?? []) as $data) {
                foreach (['vendor', 'product', 'packageName'] as $field) {
                    if (is_string($data[$field] ?? null)) {
                        $products[] = $data[$field];
                    }
                }
            }
        }

        return [
            'cve' => $cve['id'],
            'summary' => $summary,
            'cvss' => $score,
            'severity' => Vulnerability::severityFromScore($score),
            'severity_by' => $score !== null ? 'nvd' : null,
            'published_at' => self::date($cve['published'] ?? null),
            'references' => array_slice(array_values(array_filter(array_column((array) ($cve['references'] ?? []), 'url'), 'is_string')), 0, 8),
            'products' => array_slice(array_values(array_unique(array_filter($products, fn ($p) => $p !== '' && $p !== '*'))), 0, 12),
            'source' => 'nvd',
        ];
    }

    /**
     * EPSS scores by CVE, 100 a request.
     *
     * @param  list<string>  $cves
     * @return array<string, float>
     */
    public function epss(array $cves): array
    {
        $out = [];
        foreach (array_chunk(array_values(array_unique($cves)), 100) as $chunk) {
            $rows = $this->http()->timeout(30)->get(self::EPSS, ['cve' => implode(',', $chunk), 'limit' => 100])->throw()->json('data') ?? [];
            foreach ($rows as $row) {
                if (is_string($row['cve'] ?? null) && is_numeric($row['epss'] ?? null)) {
                    $out[$row['cve']] = (float) $row['epss'];
                }
            }
        }

        return $out;
    }

    /** Ubuntu's priorities and Red Hat's ratings, as ours. */
    public static function rating(string $value): ?string
    {
        return match (strtolower(trim($value))) {
            'critical' => 'critical',
            'high', 'important' => 'high',
            'medium', 'moderate' => 'medium',
            'low', 'negligible' => 'low',
            default => null,
        };
    }

    public static function userAgent(): string
    {
        return 'ServerOS-SecurityWatch/1.0 (+'.rtrim((string) config('app.url'), '/').'/security)';
    }

    private static function date(mixed $value): ?string
    {
        if (! is_string($value) || $value === '') {
            return null;
        }
        try {
            return Carbon::parse($value)->utc()->toDateTimeString();
        } catch (Throwable) {
            return null;
        }
    }

    /** NVD without a key: one request every 6 seconds; with one, every 0.6. */
    private function spaceNvd(): void
    {
        $gap = filled(config('services.nvd.key')) ? 0.6 : 6.0;
        if ($this->lastNvd !== null && ! app()->runningUnitTests()) {
            $wait = $gap - (microtime(true) - $this->lastNvd);
            if ($wait > 0) {
                usleep((int) ($wait * 1_000_000));
            }
        }
        $this->lastNvd = microtime(true);
    }

    private function http(): PendingRequest
    {
        // Tried again when the feed is down or busy, not when it says no.
        return Http::withHeaders(['User-Agent' => self::userAgent()])->acceptJson()->retry(
            2,
            app()->runningUnitTests() ? 0 : 2000,
            fn (Throwable $e) => ! $e instanceof RequestException || $e->response->serverError() || $e->response->status() === 429,
            throw: false,
        );
    }
}
