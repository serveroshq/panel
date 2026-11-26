<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Models\Vulnerability;
use App\Support\Security\Feeds;
use App\Support\Security\SecurityDiscord;
use App\Support\Security\Vulnerabilities;
use App\Support\Security\VulnerabilityAlerts;
use App\Support\Security\VulnerabilityScanner;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * The CVE watcher, every 15 minutes: reads CISA's exploited list (when
 * it's changed) and NVD's newly published critical CVEs, matches the
 * updates waiting on connected machines against OSV, fills in scores
 * from NVD and EPSS, then emails and posts to the workspaces affected,
 * and posts the notable ones to the Discord feed. A feed that's down is
 * reported and skipped; the rest still runs.
 */
class WatchVulnerabilities extends Command
{
    protected $signature = 'security:cves
        {--machine= : Scan this machine (its id) now, whether it’s due or not}
        {--no-send : Find and record, but email, post and announce nothing}';

    protected $description = 'Check connected machines for known vulnerabilities (CVEs) and tell the workspaces affected';

    public function handle(Feeds $feeds, VulnerabilityScanner $scanner, VulnerabilityAlerts $alerts, SecurityDiscord $discord): int
    {
        if (! config('security.cves.enabled') && ! $this->option('machine')) {
            return self::SUCCESS;
        }

        $this->step('CISA exploited list', fn () => $this->kev($feeds));
        $this->step('NVD critical', fn () => $this->nvdCritical($feeds));

        $machines = $this->option('machine')
            ? Machine::query()->whereKey((int) $this->option('machine'))->get()
            : VulnerabilityScanner::due();
        $this->step('scan', fn () => $machines->isEmpty() ? 'no machines due' : $scanner->scan($machines).' new findings on '.$machines->count().' machines');

        $this->step('NVD scores', fn () => $this->nvdScores($feeds));
        $this->step('EPSS', fn () => $this->epss($feeds));

        if (! $this->option('no-send')) {
            $this->step('alerts', fn () => $alerts->send().' workspaces told');
            $this->step('Discord', fn () => $discord->configured() ? $discord->announce().' posted' : 'no webhook set');
        }

        return self::SUCCESS;
    }

    /** CISA's catalog: hourly, and only read in full when it's changed. */
    private function kev(Feeds $feeds): string
    {
        if (Cache::has('security:kev:checked')) {
            return 'checked recently';
        }
        $catalog = $feeds->kev();
        Cache::put('security:kev:checked', true, now()->addHour());
        if ($catalog === null) {
            return 'unchanged';
        }

        $map = [];
        foreach ($catalog as $entry) {
            $map[$entry['cve']] = ['since' => $entry['exploited_since'], 'ransomware' => $entry['ransomware']];
        }
        Cache::forever('security:kev', $map);

        // Ones we know that it's newly listed, and anything added lately (for the feed).
        $known = [];
        foreach (array_chunk(array_keys($map), 500) as $chunk) {
            array_push($known, ...Vulnerability::query()->whereIn('cve', $chunk)->where('exploited', false)->pluck('cve')->all());
        }
        $known = array_flip($known);
        $recent = now()->subDays(3)->startOfDay();
        $marked = 0;
        foreach ($catalog as $entry) {
            if (isset($known[$entry['cve']]) || ($entry['exploited_since'] !== null && Carbon::parse($entry['exploited_since'])->gte($recent))) {
                Vulnerabilities::record($entry);
                $marked++;
            }
        }

        return count($catalog).' listed, '.$marked.' new to us';
    }

    /** Critical CVEs NVD published since the last run, kept when they're about server software. */
    private function nvdCritical(Feeds $feeds): string
    {
        $from = Carbon::parse(Cache::get('security:nvd:cursor', now()->subDay()->toIso8601String()));
        $to = now();
        // NVD takes at most 120 days; a week is plenty to catch up after an outage.
        if ($from->lt($to->copy()->subWeek())) {
            $from = $to->copy()->subWeek();
        }

        $kept = 0;
        $rows = $feeds->nvdCritical($from, $to);
        foreach ($rows as $entry) {
            if (Vulnerabilities::watched($entry['products'])) {
                Vulnerabilities::record($entry)->forceFill(['nvd_checked_at' => now()])->saveQuietly();
                $kept++;
            }
        }
        Cache::forever('security:nvd:cursor', $to->toIso8601String());

        return count($rows).' published, '.$kept.' about server software';
    }

    /**
     * NVD's score and description for CVEs found on machines that don't
     * have them yet: a few a run without an API key (NVD allows 5 in 30
     * seconds), more with one.
     */
    private function nvdScores(Feeds $feeds): string
    {
        $limit = filled(config('services.nvd.key')) ? 40 : 4;
        $list = Vulnerability::query()
            ->whereNull('nvd_checked_at')
            ->where('cve', 'like', 'CVE-%')
            ->where(fn ($q) => $q->whereHas('findings', fn ($q) => $q->whereNull('resolved_at'))
                ->orWhereHas('imageFindings', fn ($q) => $q->whereNull('resolved_at')))
            // Unrated first: their alerts wait for this.
            ->orderByRaw("case when severity = 'unknown' then 0 else 1 end")
            ->orderByDesc('id')
            ->limit($limit)
            ->get();

        foreach ($list as $vulnerability) {
            $entry = $feeds->nvdCve($vulnerability->cve);
            if ($entry !== null) {
                Vulnerabilities::record($entry);
            }
            Vulnerability::query()->whereKey($vulnerability->id)->update(['nvd_checked_at' => now()]);
        }

        return $list->count().' looked up';
    }

    /** EPSS for open findings and the feed's candidates, once a day each. */
    private function epss(Feeds $feeds): string
    {
        $list = Vulnerability::query()
            ->where('cve', 'like', 'CVE-%')
            ->where(fn ($q) => $q->whereNull('epss_checked_at')->orWhere('epss_checked_at', '<', now()->subDay()))
            ->where(fn ($q) => $q->whereHas('findings', fn ($q) => $q->whereNull('resolved_at'))
                ->orWhereHas('imageFindings', fn ($q) => $q->whereNull('resolved_at'))
                ->orWhereNull('announced_at'))
            ->limit(500)
            ->pluck('cve', 'id');
        if ($list->isEmpty()) {
            return 'nothing due';
        }

        $scores = $feeds->epss(array_values($list->filter(fn ($cve) => is_string($cve))->all()));
        foreach ($list as $id => $cve) {
            Vulnerability::query()->whereKey($id)->update(['epss' => $scores[$cve] ?? null, 'epss_checked_at' => now()]);
        }

        return count($scores).' scored';
    }

    /**
     * One step, its outcome on a line; the rest carry on if it fails. A
     * feed that's down or busy (NVD often is) is only logged: what it
     * didn't answer is asked again next run. Anything else is reported.
     */
    private function step(string $name, callable $run): void
    {
        try {
            $this->line($name.': '.$run());
        } catch (Throwable $e) {
            if (self::feedDown($e)) {
                Log::warning('CVE watcher: a feed is unavailable', ['step' => $name, 'error' => Str::limit($e->getMessage(), 200)]);
            } else {
                report($e);
            }
            $this->warn($name.' failed: '.Str::limit($e->getMessage(), 200));
        }
    }

    private static function feedDown(Throwable $e): bool
    {
        return $e instanceof ConnectionException
            || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429));
    }
}
