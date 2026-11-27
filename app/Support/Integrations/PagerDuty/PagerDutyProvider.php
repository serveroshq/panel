<?php

namespace App\Support\Integrations\PagerDuty;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\Incidents\Problem;
use App\Support\Integrations\Incidents\RoutesIncidents;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;

final class PagerDutyProvider extends Provider implements RoutesIncidents
{
    public const string KEY_PATTERN = '/^[A-Za-z0-9]{32}$/';

    public const array MONITORS = ['all', 'some', 'none'];

    public function key(): string
    {
        return 'pagerduty';
    }

    public function name(): string
    {
        return 'PagerDuty';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'routing_key',
                label: self::t('Integration key'),
                help: self::t('In PagerDuty, open the service, then Integrations › Add an integration › Events API v2, and copy its Integration Key. Name it after the service so you know where pages go.'),
                link: 'https://support.pagerduty.com/main/docs/services-and-integrations#add-integrations-to-an-existing-service',
                linkLabel: self::t('How to add one'),
                placeholder: self::t('32 letters and numbers'),
                rules: ['required', 'string', 'regex:'.self::KEY_PATTERN],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::Pages];
    }

    public function verify(array $credentials): ConnectedAccount
    {
        if (preg_match(self::KEY_PATTERN, $credentials['routing_key'] ?? '') !== 1) {
            throw new IntegrationFailed(self::t('That isn’t an Events API v2 integration key. It’s 32 letters and numbers.'), IntegrationFailed::CREDENTIALS);
        }

        return new ConnectedAccount('PagerDuty', ['checked' => 'format']);
    }

    public function summary(Integration $integration): string
    {
        $pages = $this->pages($integration);

        return $pages === [] ? self::t('Pages nothing yet') : self::t('Pages for :list', ['list' => implode(', ', $pages)]);
    }

    public function facts(Integration $integration): array
    {
        $open = $integration->incidents()->whereNotNull('open_key')->count();

        return [['label' => self::t('Open alerts'), 'value' => (string) $open]];
    }

    /** @return list<string> */
    private function pages(Integration $integration): array
    {
        $s = $this->current($integration);

        return array_values(array_filter([
            match ($s['monitors']) {
                'all' => self::t('monitors'),
                'some' => self::t('some monitors'),
                default => null,
            },
            $s['machines'] ? self::t('offline machines') : null,
            $s['cves'] ? self::t('exploited CVEs') : null,
        ]));
    }

    /** @return array{monitors: string, monitor_ids: list<int>, machines: bool, cves: bool} */
    public function current(Integration $integration): array
    {
        $monitors = $integration->setting('monitors', 'all');

        return [
            'monitors' => in_array($monitors, self::MONITORS, true) ? $monitors : 'all',
            'monitor_ids' => array_values(array_map('intval', (array) $integration->setting('monitor_ids', []))),
            'machines' => (bool) $integration->setting('machines', true),
            'cves' => (bool) $integration->setting('cves', true),
        ];
    }

    public function wants(Integration $integration, Problem $problem): bool
    {
        $s = $this->current($integration);

        return match ($problem->kind) {
            Problem::MONITOR => $s['monitors'] === 'all' || ($s['monitors'] === 'some' && in_array($problem->monitorId, $s['monitor_ids'], true)),
            Problem::MACHINE => $s['machines'],
            Problem::CVE => $s['cves'] && $problem->critical && $problem->exploited,
            default => false,
        };
    }

    public function settingsRules(Integration $integration): array
    {
        return [
            'monitors' => ['required', 'string', 'in:'.implode(',', self::MONITORS)],
            'monitor_ids' => ['array', 'max:500'],
            'monitor_ids.*' => ['integer'],
            'machines' => ['required', 'boolean'],
            'cves' => ['required', 'boolean'],
        ];
    }

    public function settings(Integration $integration, array $data): array
    {
        $ids = $integration->workspace->monitors()->whereIn('id', array_map('intval', (array) ($data['monitor_ids'] ?? [])))->orderBy('id')->pluck('id')->all();

        return [
            'monitors' => $data['monitors'] === 'some' && $ids === [] ? 'none' : (string) $data['monitors'],
            'monitor_ids' => $data['monitors'] === 'some' ? array_values(array_map('intval', $ids)) : [],
            'machines' => (bool) $data['machines'],
            'cves' => (bool) $data['cves'],
        ];
    }

    public function settingsProps(Integration $integration): array
    {
        return [
            ...$this->current($integration),
            'monitorOptions' => $integration->workspace->monitors()->orderBy('name')->get(['id', 'name', 'status'])
                ->map(fn ($m) => ['id' => $m->id, 'name' => $m->name, 'status' => $m->status])->all(),
        ];
    }

    public function deliver(Integration $integration, IntegrationEvent $event): array
    {
        $client = new PagerDutyClient;
        $key = $integration->credential('routing_key');

        if ($event->action === 'test') {
            $dedup = 'serveros-test-'.$integration->id.'-'.$event->id;
            $client->enqueue([
                'routing_key' => $key,
                'event_action' => 'trigger',
                'dedup_key' => $dedup,
                'payload' => [
                    'summary' => 'Test from ServerOS: pages for '.$integration->workspace->name.' will arrive here',
                    'source' => 'ServerOS',
                    'severity' => 'info',
                ],
                'client' => 'ServerOS',
                'client_url' => $integration->workspace->url('/integrations/'.$integration->id),
            ]);
            $client->enqueue(['routing_key' => $key, 'event_action' => 'resolve', 'dedup_key' => $dedup]);

            return ['dedup' => $dedup];
        }

        $dedup = (string) ($event->incident !== null ? 'serveros:'.$integration->workspace_id.':'.$event->incident->key : $event->input('dedup'));

        if ($event->action === 'resolve') {
            $client->enqueue(['routing_key' => $key, 'event_action' => 'resolve', 'dedup_key' => $dedup]);

            return ['dedup' => $dedup];
        }

        if (! in_array($event->action, ['open', 'update'], true)) {
            return ['skipped' => true];
        }

        $p = (array) $event->payload;
        $url = (string) ($p['url'] ?? $integration->workspace->url('/'));
        $client->enqueue([
            'routing_key' => $key,
            'event_action' => 'trigger',
            'dedup_key' => $dedup,
            'payload' => array_filter([
                'summary' => mb_substr((string) ($p['title'] ?? $event->subject), 0, 1024),
                'source' => mb_substr((string) ($p['source'] ?? 'ServerOS'), 0, 255) ?: 'ServerOS',
                'severity' => ($p['critical'] ?? true) ? 'critical' : 'error',
                'timestamp' => $p['since'] ?? null,
                'component' => match ($p['kind'] ?? null) {
                    Problem::MONITOR => 'monitor',
                    Problem::MACHINE => 'machine',
                    default => 'security',
                },
                'group' => (string) ($p['workspace'] ?? $integration->workspace->name),
                'class' => (string) ($p['kind'] ?? 'problem'),
                'custom_details' => ['details' => array_values((array) ($p['lines'] ?? [])), 'workspace' => $p['workspace'] ?? null],
            ], fn ($v) => $v !== null),
            'links' => [['href' => $url, 'text' => 'Open in ServerOS']],
            'client' => 'ServerOS',
            'client_url' => $url,
        ]);

        return ['dedup' => $dedup];
    }

    public function disconnect(Integration $integration): ?string
    {
        $open = $integration->incidents()->whereNotNull('open_key')->get();
        $resolved = 0;
        foreach ($open as $incident) {
            try {
                (new PagerDutyClient)->enqueue(['routing_key' => $integration->credential('routing_key'), 'event_action' => 'resolve', 'dedup_key' => 'serveros:'.$integration->workspace_id.':'.$incident->key]);
                $resolved++;
            } catch (IntegrationFailed) {
            }
        }

        return $resolved === 0 ? null : (string) trans_choice('Disconnected :name and resolved its open alert in PagerDuty.|Disconnected :name and resolved its :count open alerts in PagerDuty.', $resolved, ['name' => $integration->label, 'count' => $resolved]);
    }

    public function eventLabel(IntegrationEvent $event): string
    {
        return match ($event->action) {
            'open' => self::t('Triggered'),
            'update' => self::t('Updated'),
            'resolve' => self::t('Resolved'),
            'test' => self::t('Test event'),
            default => self::t('Sent'),
        };
    }
}
