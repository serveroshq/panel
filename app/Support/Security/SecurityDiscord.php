<?php

namespace App\Support\Security;

use App\Models\Vulnerability;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

/**
 * The public security feed: notable new vulnerabilities posted to a
 * Discord channel through a webhook (services.discord.security_webhook).
 * Notable means new to CISA's exploited list, or critical, and about
 * software people run on servers (the watchlist) or found on a connected
 * machine. Never anything about a customer: no names, no counts.
 */
class SecurityDiscord
{
    /** Posts a run, so a busy day can't flood the channel. */
    public const int PER_RUN = 5;

    private const array COLOURS = [
        'critical' => 0xE5484D,
        'high' => 0xF76B15,
        'medium' => 0xE2A03F,
        'low' => 0x3B7BFF,
        'unknown' => 0x8B8D98,
    ];

    public function configured(): bool
    {
        return filled(config('services.discord.security_webhook'));
    }

    /** Post what's new and notable. Returns how many went. */
    public function announce(): int
    {
        if (! $this->configured()) {
            return 0;
        }

        $posted = 0;
        foreach ($this->notable() as $vulnerability) {
            if ($posted >= self::PER_RUN) {
                break;
            }
            try {
                Http::timeout(10)->post($this->webhook(), $this->message($vulnerability))->throw();
                $vulnerability->forceFill(['announced_at' => now()])->saveQuietly();
                $posted++;
            } catch (Throwable $e) {
                report($e);

                break;
            }
        }

        return $posted;
    }

    /**
     * New and notable: added to CISA's list in the last three days, or
     * published in the last fortnight, and not posted yet. A feed set up
     * later doesn't post the whole history.
     *
     * @return list<Vulnerability>
     */
    public function notable(): array
    {
        return array_values(Vulnerability::query()
            ->whereNull('announced_at')
            ->where(fn ($q) => $q->where('exploited_since', '>=', now()->subDays(3)->startOfDay())
                ->orWhere(fn ($q) => $q->where('severity', 'critical')->where('published_at', '>=', now()->subDays(14))))
            ->orderByDesc('exploited')
            ->orderByDesc('cvss')
            ->limit(200)
            ->get()
            ->filter(fn (Vulnerability $v) => Vulnerabilities::watched($v->products) || $v->findings()->exists())
            ->all());
    }

    /** @return array<string, mixed> */
    public function message(Vulnerability $v): array
    {
        $fields = [
            ['name' => 'Severity', 'value' => $v->severityLabel(), 'inline' => true],
        ];
        if ($v->exploited) {
            $fields[] = ['name' => 'Exploited', 'value' => 'Yes, on CISA’s list'.($v->exploited_since ? ' since '.$v->exploited_since->toFormattedDateString() : '').($v->ransomware ? ', used by ransomware' : ''), 'inline' => true];
        }
        if ($v->epss !== null) {
            $fields[] = ['name' => 'Chance of attacks (EPSS)', 'value' => number_format($v->epss * 100, $v->epss < 0.01 ? 2 : 0).'% in 30 days', 'inline' => true];
        }
        if ($v->products !== null && $v->products !== []) {
            $fields[] = ['name' => 'Affects', 'value' => Str::limit(implode(', ', array_slice($v->products, 0, 6)), 200), 'inline' => false];
        }
        if ($v->advisories !== null && $v->advisories !== []) {
            $fields[] = ['name' => 'Advisories', 'value' => Str::limit(implode(', ', $v->advisories), 200), 'inline' => false];
        }

        $title = $v->title !== null ? $v->cve.': '.$v->title : $v->cve;

        return [
            'allowed_mentions' => ['parse' => []],
            'embeds' => [array_filter([
                'title' => Str::limit($title, 250),
                'url' => $v->url(),
                'description' => Str::limit((string) $v->summary, 900)
                    ."\n\nConnected machines are checked for this automatically, and you’ll get an email if an update on yours fixes it.",
                'color' => self::COLOURS[$v->exploited ? 'critical' : $v->severity] ?? self::COLOURS['unknown'],
                'fields' => $fields,
                'footer' => ['text' => 'ServerOS security feed'],
                'timestamp' => ($v->exploited_since ?? $v->published_at)?->toIso8601String(),
            ])],
        ];
    }

    private function webhook(): string
    {
        return rtrim((string) config('services.discord.security_webhook'), '/');
    }
}
