<?php

namespace App\Support\Monitors;

use App\Models\IntegrationIncident;
use App\Models\Monitor;
use App\Models\MonitorCheck;
use App\Models\MonitorIncident;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\Response;

class MonitorReport
{
    public const LONG_OUTAGE_SECONDS = 1800;

    /** @var array<string, array{bars: int, step: int}> */
    public const BARS = [
        '24h' => ['bars' => 96, 'step' => 900],
        '7d' => ['bars' => 84, 'step' => 7200],
    ];

    public const RECENT_CHECKS = 5;

    public const INCIDENTS = 50;

    public function __construct(private readonly Monitor $monitor) {}

    /**
     * @return array{uptime30: float|null, uptime90: float|null, averageMs: int|null, lastIncident: array{startedAt: string, resolvedAt: string|null}|null}
     */
    public function figures(): array
    {
        $average = $this->monitor->checks()
            ->where('checked_at', '>=', now()->subDay())
            ->where('ok', true)
            ->whereNotNull('response_ms')
            ->avg('response_ms');
        $last = $this->monitor->incidents()->latest('started_at')->first();

        return [
            'uptime30' => $this->monitor->uptime(30),
            'uptime90' => $this->monitor->uptime(Monitor::DAYS),
            'averageMs' => $average === null ? null : (int) round((float) $average),
            'lastIncident' => $last ? [
                'startedAt' => $last->started_at->toIso8601String(),
                'resolvedAt' => $last->resolved_at?->toIso8601String(),
            ] : null,
        ];
    }

    /**
     * @return array{step: int, bars: list<array{at: string, ms: int|null, failed: int}>, averageMs: int|null}
     */
    public function chart(string $range): array
    {
        ['bars' => $count, 'step' => $step] = self::BARS[$range] ?? self::BARS['24h'];
        $end = (intdiv(now()->getTimestamp(), $step) + 1) * $step;
        $start = $end - $count * $step;
        $total = $timed = $failed = array_fill(0, $count, 0);

        $rows = $this->monitor->checks()
            ->where('checked_at', '>=', CarbonImmutable::createFromTimestamp($start))
            ->toBase()
            ->get(['checked_at', 'ok', 'response_ms']);

        foreach ($rows as $row) {
            $at = strtotime((string) $row->checked_at);
            if ($at === false) {
                continue;
            }
            $i = intdiv($at - $start, $step);
            if ($i < 0 || $i >= $count) {
                continue;
            }
            if (! $row->ok) {
                $failed[$i]++;
            } elseif ($row->response_ms !== null) {
                $total[$i] += (int) $row->response_ms;
                $timed[$i]++;
            }
        }

        $bars = [];
        foreach (range(0, $count - 1) as $i) {
            $bars[] = [
                'at' => CarbonImmutable::createFromTimestamp($start + $i * $step)->toIso8601String(),
                'ms' => $timed[$i] > 0 ? (int) round($total[$i] / $timed[$i]) : null,
                'failed' => $failed[$i],
            ];
        }
        $checks = array_sum($timed);

        return [
            'step' => $step,
            'bars' => $bars,
            'averageMs' => $checks > 0 ? (int) round(array_sum($total) / $checks) : null,
        ];
    }

    /**
     * @return list<array{day: string, percent: float|null, down: int, tone: string}>
     */
    public function days(): array
    {
        $days = $this->monitor->days(Monitor::DAYS);
        $first = CarbonImmutable::parse($days[0]['day'])->startOfDay();
        $now = CarbonImmutable::now();
        $down = [];
        $worst = [];

        $incidents = $this->monitor->incidents()
            ->where(fn (Builder $q) => $q->whereNull('resolved_at')->orWhere('resolved_at', '>=', $first))
            ->get();

        foreach ($incidents as $incident) {
            $to = $incident->resolved_at ?? $now;
            $length = (int) $incident->started_at->diffInSeconds($to);
            $cursor = $incident->started_at->gt($first) ? $incident->started_at : $first;
            while ($cursor->lt($to)) {
                $next = $cursor->startOfDay()->addDay();
                $slice = $next->lt($to) ? $next : $to;
                $key = $cursor->toDateString();
                $down[$key] = ($down[$key] ?? 0) + (int) $cursor->diffInSeconds($slice);
                $worst[$key] = max($worst[$key] ?? 0, $length);
                $cursor = $slice;
            }
        }

        return array_map(function (array $day) use ($down, $worst): array {
            $longest = $worst[$day['day']] ?? null;

            return [
                ...$day,
                'down' => $down[$day['day']] ?? 0,
                'tone' => match (true) {
                    $longest === null => $day['percent'] === null ? 'none' : 'up',
                    $longest >= self::LONG_OUTAGE_SECONDS => 'long',
                    default => 'short',
                },
            ];
        }, $days);
    }

    /**
     * @return array{count: int, list: list<array{id: int, startedAt: string, resolvedAt: string|null, cause: string, seconds: int, tone: string}>}
     */
    public function incidents(): array
    {
        $query = fn () => $this->monitor->incidents()
            ->where(fn (Builder $q) => $q->whereNull('resolved_at')->orWhere('started_at', '>=', now()->subDays(Monitor::DAYS)));

        $list = $query()->latest('started_at')->limit(self::INCIDENTS)->get();
        $issues = IntegrationIncident::query()->whereIn('monitor_incident_id', $list->pluck('id'))->whereNotNull('external_url')
            ->get()->groupBy('monitor_incident_id');

        return [
            'count' => $query()->count(),
            'list' => array_values($list
                ->map(function (MonitorIncident $incident) use ($issues): array {
                    $props = $incident->toPageProps();

                    return [...$props, 'cause' => self::cause($props['cause']), 'issues' => array_values(array_filter($issues->get($incident->id, collect())->map(fn (IntegrationIncident $i) => $i->link())->all())), 'tone' => match (true) {
                        $incident->resolved_at === null => 'ongoing',
                        $props['seconds'] >= self::LONG_OUTAGE_SECONDS => 'long',
                        default => 'short',
                    }];
                })
                ->all()),
        ];
    }

    /**
     * @return list<array{id: int, at: string, ok: bool, ms: int|null, code: int|null, label: string, error: string|null}>
     */
    public function checks(): array
    {
        return array_values($this->monitor->checks()->latest('checked_at')->latest('id')->limit(self::RECENT_CHECKS)->get()
            ->map(fn (MonitorCheck $check) => [
                'id' => $check->id,
                'at' => $check->checked_at->toIso8601String(),
                'ok' => $check->ok,
                'ms' => $check->response_ms,
                'code' => $check->status_code,
                'label' => $this->label($check),
                'error' => $check->error,
            ])
            ->all());
    }

    public static function cause(string $cause): string
    {
        $cause = (string) preg_replace_callback(
            '/^Timed out after (\d+) s\./',
            fn (array $m) => 'Timed out after '.$m[1].($m[1] === '1' ? ' second.' : ' seconds.'),
            $cause,
        );

        return (string) preg_replace_callback(
            '/^Returned (\d{3})(?=[.,])/',
            fn (array $m) => 'Returned '.$m[1].(isset(Response::$statusTexts[(int) $m[1]]) ? ' '.Response::$statusTexts[(int) $m[1]] : ''),
            $cause,
        );
    }

    private function label(MonitorCheck $check): string
    {
        $code = $check->status_code;
        $status = $code === null ? null : trim($code.' '.(Response::$statusTexts[$code] ?? ''));

        if ($check->ok) {
            return $status ?? __('Connected');
        }
        if ($status !== null && str_starts_with((string) $check->error, 'Returned')) {
            return $status;
        }

        return $check->error ?? __('Check failed.');
    }
}
