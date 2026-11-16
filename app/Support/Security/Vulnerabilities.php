<?php

namespace App\Support\Security;

use App\Models\Vulnerability;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Keeps one row per CVE, whichever feeds have spoken about it. A distro's
 * own rating (Ubuntu's priority, Red Hat's) beats a CVSS score, since it
 * says how bad it is in their build; otherwise the higher score wins.
 * CISA saying it's exploited always sticks.
 */
final class Vulnerabilities
{
    /** Whose word on severity wins: lower beats higher. */
    private const array TRUST = ['distro' => 0, 'nvd' => 1, 'cvss' => 2];

    /**
     * @param  array<string, mixed>  $entry  from Feeds
     */
    public static function record(array $entry): Vulnerability
    {
        $source = (string) ($entry['source'] ?? 'osv');
        $vulnerability = Vulnerability::query()->firstOrNew(['cve' => (string) $entry['cve']]);
        $fresh = ! $vulnerability->exists;

        $vulnerability->title ??= $entry['title'] ?? null;
        if (filled($entry['summary'] ?? null) && ($vulnerability->summary === null || $source === 'nvd')) {
            $vulnerability->summary = $entry['summary'];
        }

        // Severity: a distro's own rating, else NVD's score, else a CVSS vector OSV gave.
        $severity = (string) ($entry['severity'] ?? 'unknown');
        $by = $entry['severity_by'] ?? null;
        if ($severity !== 'unknown' && isset(self::TRUST[$by])
            && ($vulnerability->severity_by === null || self::TRUST[$by] <= (self::TRUST[$vulnerability->severity_by] ?? 9))) {
            $vulnerability->severity = $severity;
            $vulnerability->severity_by = $by;
        }
        $vulnerability->severity ??= 'unknown';
        if (is_numeric($entry['cvss'] ?? null) && ($source === 'nvd' || $vulnerability->cvss === null)) {
            $vulnerability->cvss = (float) $entry['cvss'];
        }

        if (filled($entry['published_at'] ?? null)) {
            $published = CarbonImmutable::parse($entry['published_at']);
            if ($vulnerability->published_at === null || $published->lt($vulnerability->published_at)) {
                $vulnerability->published_at = $published;
            }
        }
        if (filled($entry['exploited_since'] ?? null)) {
            $vulnerability->exploited = true;
            $vulnerability->exploited_since = CarbonImmutable::parse($entry['exploited_since']);
            $vulnerability->ransomware = (bool) ($entry['ransomware'] ?? false);
            // CISA's name for it reads better than a distro's "openssl security update".
            if (filled($entry['title'] ?? null)) {
                $vulnerability->title = $entry['title'];
            }
        }

        $vulnerability->sources = Vulnerability::merge($vulnerability->sources, [$source]);
        $vulnerability->advisories = Vulnerability::merge($vulnerability->advisories, self::strings($entry['advisories'] ?? []), 12);
        $vulnerability->references = Vulnerability::merge($vulnerability->references, self::strings($entry['references'] ?? []), 10);
        $vulnerability->products = Vulnerability::merge($vulnerability->products, self::strings($entry['products'] ?? []), 12);

        // New to us but already on CISA's list.
        if ($fresh && ! $vulnerability->exploited && is_array($kev = (Cache::get('security:kev')[$vulnerability->cve] ?? null))) {
            $vulnerability->exploited = true;
            $vulnerability->exploited_since = CarbonImmutable::parse($kev['since']);
            $vulnerability->ransomware = (bool) $kev['ransomware'];
            $vulnerability->sources = Vulnerability::merge($vulnerability->sources, ['kev']);
        }

        if ($vulnerability->isDirty()) {
            $vulnerability->save();
        }

        return $vulnerability;
    }

    /** @return list<string> */
    private static function strings(mixed $list): array
    {
        return is_array($list) ? array_values(array_filter($list, 'is_string')) : [];
    }

    /**
     * Whether NVD or CISA's products include something on the watchlist.
     *
     * @param  list<string>|null  $products
     */
    public static function watched(?array $products): bool
    {
        $watch = array_flip((array) config('security.cves.watchlist', []));
        foreach ($products ?? [] as $product) {
            $name = preg_replace('/[^a-z0-9]/', '', strtolower((string) $product));
            if ($name !== '' && isset($watch[$name])) {
                return true;
            }
        }

        return false;
    }
}
