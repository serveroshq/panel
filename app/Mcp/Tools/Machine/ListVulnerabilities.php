<?php

namespace App\Mcp\Tools\Machine;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\Machine;
use App\Models\MachineVulnerability;
use App\Models\Vulnerability;
use Illuminate\Support\Collection;

/**
 * Known vulnerabilities (CVEs) on the workspace's machines that waiting
 * updates fix, as security:cves found them, one entry per CVE.
 */
final class ListVulnerabilities extends Tool
{
    public function name(): string
    {
        return 'list_vulnerabilities';
    }

    public function title(): string
    {
        return 'List known vulnerabilities on machines';
    }

    public function description(): string
    {
        return 'Lists known vulnerabilities (CVEs) on the workspace’s machines that an OS update already waiting on them fixes. ServerOS checks every connected machine’s waiting updates against Ubuntu, Debian, AlmaLinux and Rocky’s security trackers (through OSV), CISA’s Known Exploited Vulnerabilities list, NVD and EPSS around the clock, after each updates check and at least twice a day. '
            .'One entry per CVE, worst first (being exploited, then by severity): its title, severity (critical, high, medium, low or unknown) and CVSS score, EPSS (the chance it’s exploited in the next 30 days, 0 to 1), whether it’s exploited (and since when), the distro advisories, a link, and each machine it’s on with the package (source package, and the installed packages it covers), the version installed and the waiting version that fixes it. Installing updates (install_updates, or a package rollout) fixes them; the next check marks them resolved. '
            .'Filter with machine (id or name), severity (this or worse), exploited (only ones being exploited), cve (one CVE id) and status (open, the default; resolved; or all). Also a summary. Up to limit CVEs (default 50) from offset.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine('Only vulnerabilities on this machine: its id or name.'),
            'severity' => Schema::string('Only this severity or worse.', ['critical', 'high', 'medium', 'low']),
            'exploited' => Schema::boolean('Only ones on CISA’s Known Exploited Vulnerabilities list.'),
            'cve' => Schema::string('Only this CVE, like CVE-2026-1234.', maxLength: 80),
            'status' => Schema::string('open (the default): not fixed yet; resolved: fixed since; all.', ['open', 'resolved', 'all']),
            'limit' => Schema::integer('How many CVEs to return (1–200, default 50).', 1, 200),
            'offset' => Schema::integer('How many to skip, for the next page (default 0).', 0),
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['sometimes', 'nullable', ServerWork::reference()],
            'severity' => ['sometimes', 'nullable', 'in:critical,high,medium,low'],
            'exploited' => ['sometimes', 'boolean'],
            'cve' => ['sometimes', 'nullable', 'string', 'max:80'],
            'status' => ['sometimes', 'nullable', 'in:open,resolved,all'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:200'],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ]);

        /** @var Collection<int, Machine> $machines */
        $machines = $context->workspace->machines()->whereNotNull('uid')->orderBy('name')->get();
        if (filled($data['machine'] ?? null)) {
            $only = $context->machine($data['machine']);
            $machines = $machines->where('id', $only->id)->values();
        }
        $names = $machines->pluck('name', 'id');
        $status = $data['status'] ?? 'open';

        $all = MachineVulnerability::query()
            ->whereIn('machine_id', $machines->pluck('id'))
            ->with('vulnerability')
            ->get();
        $findings = $all
            ->filter(fn (MachineVulnerability $f) => match ($status) {
                'open' => $f->resolved_at === null,
                'resolved' => $f->resolved_at !== null,
                default => true,
            })
            ->filter(fn (MachineVulnerability $f) => ! ($data['exploited'] ?? false) || $f->vulnerability->exploited)
            ->filter(fn (MachineVulnerability $f) => blank($data['cve'] ?? null) || strcasecmp($f->vulnerability->cve, trim((string) $data['cve'])) === 0)
            ->filter(function (MachineVulnerability $f) use ($data) {
                if (blank($data['severity'] ?? null)) {
                    return true;
                }
                $order = array_flip(Vulnerability::SEVERITIES);

                return ($order[$f->vulnerability->severity] ?? 9) <= $order[$data['severity']];
            });

        $byCve = $findings->groupBy('vulnerability_id')
            ->map(fn (Collection $list) => ['vulnerability' => $list->first()->vulnerability, 'findings' => $list])
            ->sortBy(fn (array $g) => [$g['vulnerability']->rank(), -($g['vulnerability']->cvss ?? 0), $g['vulnerability']->cve])
            ->values();

        $offset = (int) ($data['offset'] ?? 0);
        $page = $byCve->slice($offset, (int) ($data['limit'] ?? 50))->values();
        $open = $all->whereNull('resolved_at');
        $openVulns = $open->pluck('vulnerability')->unique('id');

        return [
            'summary' => [
                'open' => $openVulns->count(),
                'critical' => $openVulns->where('severity', 'critical')->count(),
                'high' => $openVulns->where('severity', 'high')->count(),
                'exploited' => $openVulns->where('exploited', true)->count(),
                'machinesAffected' => $open->pluck('machine_id')->unique()->count(),
                'machines' => $machines->count(),
                'lastScan' => $machines->max('vulns_scanned_at')?->toIso8601String(),
                'neverScanned' => $machines->whereNull('vulns_scanned_at')->count(),
            ],
            'total' => $byCve->count(),
            'count' => $page->count(),
            'offset' => $offset,
            'more' => $offset + $page->count() < $byCve->count(),
            'vulnerabilities' => $page->map(fn (array $g) => [
                ...self::present($g['vulnerability']),
                'machines' => $g['findings']->sortBy(fn (MachineVulnerability $f) => $names[$f->machine_id] ?? '')->map(fn (MachineVulnerability $f) => [
                    'id' => $f->machine_id,
                    'name' => $names[$f->machine_id] ?? null,
                    'package' => $f->source,
                    'packages' => $f->packages,
                    'installed' => $f->installed,
                    'fixedBy' => $f->fixed_by,
                    'detectedAt' => $f->detected_at->toIso8601String(),
                    'resolvedAt' => $f->resolved_at?->toIso8601String(),
                ])->values()->all(),
            ])->all(),
            'url' => $context->url('/updates'),
        ];
    }

    /** @return array<string, mixed> */
    public static function present(Vulnerability $v): array
    {
        return [
            'cve' => $v->cve,
            'title' => $v->title,
            'summary' => $v->summary,
            'severity' => $v->severity,
            'cvss' => $v->cvss,
            'epss' => $v->epss,
            'exploited' => $v->exploited,
            'exploitedSince' => $v->exploited_since?->toDateString(),
            'ransomware' => $v->ransomware,
            'publishedAt' => $v->published_at?->toIso8601String(),
            'advisories' => $v->advisories ?? [],
            'sources' => $v->sources ?? [],
            'url' => $v->url(),
        ];
    }
}
