<?php

namespace App\Support\Integrations\Sentry;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\Incidents\DeliversEvents;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;
use Illuminate\Support\Facades\Cache;

final class SentryProvider extends Provider implements DeliversEvents
{
    public const string SLUG = '/^[a-z0-9][a-z0-9_-]{0,99}$/';

    public function key(): string
    {
        return 'sentry';
    }

    public function name(): string
    {
        return 'Sentry';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'token',
                label: self::t('Auth token'),
                help: self::t('In Sentry, open Settings › Personal Tokens and make one with project:read, project:releases and org:read.'),
                link: 'https://sentry.io/settings/account/api/auth-tokens/',
                linkLabel: self::t('Open Sentry'),
                placeholder: self::t('Paste the token'),
                rules: ['required', 'string', 'min:20', 'max:300', 'regex:/^\S+$/'],
            ),
            new ConnectField(
                key: 'organization',
                label: self::t('Organization slug'),
                secret: false,
                help: self::t('The name in your Sentry address, like acme in acme.sentry.io.'),
                placeholder: 'acme',
                rules: ['required', 'string', 'max:100', 'regex:'.self::SLUG],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::Releases];
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $slug = strtolower(trim($credentials['organization'] ?? ''));
        $organization = (new SentryClient($credentials['token'] ?? ''))->get('organizations/'.rawurlencode($slug).'/');
        $region = (string) self::region($organization['links']['regionUrl'] ?? null);
        $projects = (new SentryClient($credentials['token'] ?? '', $region))->get('organizations/'.rawurlencode($slug).'/projects/', ['per_page' => 100]);

        return new ConnectedAccount((string) ($organization['name'] ?? $slug), [
            'organization' => (string) ($organization['name'] ?? $slug),
            'slug' => (string) ($organization['slug'] ?? $slug),
            'organization_url' => self::region($organization['links']['organizationUrl'] ?? null, null),
            'region_url' => $region,
            'projects' => count($projects),
        ]);
    }

    public function summary(Integration $integration): string
    {
        $apps = $integration->apps()->count();

        return match ($apps) {
            0 => self::t('No app sends releases yet'),
            1 => self::t('Releases for 1 app'),
            default => self::t('Releases for :count apps', ['count' => $apps]),
        };
    }

    public function facts(Integration $integration): array
    {
        return [
            ['label' => self::t('Organization'), 'value' => (string) ($integration->meta['organization'] ?? $integration->credential('organization'))],
            ['label' => self::t('Projects'), 'value' => (string) (int) ($integration->meta['projects'] ?? 0)],
        ];
    }

    /**
     * The organization's projects, from Sentry at most every few minutes.
     *
     * @return list<array{id: string, slug: string, name: string, platform: string|null}>
     */
    public function projects(Integration $integration): array
    {
        return Cache::remember("integrations:{$integration->id}:sentry-projects", now()->addMinutes(5), function () use ($integration): array {
            $list = [];
            foreach ($this->client($integration)->get($this->org($integration).'projects/', ['per_page' => 100]) as $project) {
                if (is_array($project) && is_string($project['slug'] ?? null)) {
                    $list[] = [
                        'id' => (string) ($project['id'] ?? ''),
                        'slug' => $project['slug'],
                        'name' => (string) ($project['name'] ?? $project['slug']),
                        'platform' => is_string($project['platform'] ?? null) ? $project['platform'] : null,
                    ];
                }
            }
            usort($list, fn (array $a, array $b) => strcasecmp($a['name'], $b['name']));

            return $list;
        });
    }

    public function releaseUrl(Integration $integration, string $version, ?string $projectId): string
    {
        $base = is_string($integration->meta['organization_url'] ?? null)
            ? rtrim($integration->meta['organization_url'], '/')
            : SentryClient::URL.'/organizations/'.rawurlencode($this->slug($integration));

        return $base.'/releases/'.rawurlencode($version).'/'.($projectId !== null && $projectId !== '' ? '?project='.rawurlencode($projectId) : '');
    }

    /**
     * How many issues Sentry first saw in the release, or null when it has no such release.
     *
     * @throws IntegrationFailed
     */
    public function newIssues(Integration $integration, string $version, ?string $projectId): ?int
    {
        try {
            $release = $this->client($integration)->get($this->org($integration).'releases/'.rawurlencode($version).'/', array_filter(['project' => $projectId, 'health' => 0], fn ($v) => $v !== null && $v !== ''));
        } catch (IntegrationFailed $e) {
            if ($e->reason === IntegrationFailed::NOT_FOUND) {
                return null;
            }
            throw $e;
        }
        foreach ((array) ($release['projects'] ?? []) as $project) {
            if (is_array($project) && (string) ($project['id'] ?? '') === (string) $projectId && isset($project['newGroups'])) {
                return (int) $project['newGroups'];
            }
        }

        return isset($release['newGroups']) ? (int) $release['newGroups'] : null;
    }

    public function deliver(Integration $integration, IntegrationEvent $event): array
    {
        $version = (string) $event->input('version');
        $project = (string) $event->input('project');
        if ($version === '' || $project === '') {
            return ['skipped' => true];
        }
        $client = $this->client($integration);
        $org = $this->org($integration);
        $done = (array) ($event->result['steps'] ?? []);
        $steps = function (array $more) use ($event, &$done): void {
            $done = [...$done, ...$more];
            $event->forceFill(['result' => [...($event->result ?? []), 'steps' => $done]])->save();
        };
        $release = $org.'releases/'.rawurlencode($version).'/';

        if (! isset($done['release'])) {
            if ($event->action === 'rollback') {
                $exists = true;
                try {
                    $client->get($release);
                } catch (IntegrationFailed $e) {
                    if ($e->reason !== IntegrationFailed::NOT_FOUND) {
                        throw $e;
                    }
                    $exists = false;
                }
                if (! $exists) {
                    $this->createRelease($client, $org, $event, $version, $project);
                }
            } else {
                $this->createRelease($client, $org, $event, $version, $project);
            }
            $steps(['release' => true]);
        }

        if (! isset($done['deploy'])) {
            $client->post($release.'deploys/', array_filter([
                'environment' => (string) ($event->input('environment') ?: 'production'),
                'name' => $event->input('name'),
                'url' => $event->input('url'),
                'projects' => [$project],
                'dateFinished' => $event->input('finished_at'),
            ], fn ($v) => $v !== null && $v !== ''));
            $steps(['deploy' => true]);
        }

        if ($event->action === 'release' && ! isset($done['finalized'])) {
            $client->put($release, ['dateReleased' => $event->input('finished_at') ?? now()->toIso8601String()]);
            $steps(['finalized' => true]);
        }

        return ['steps' => $done, 'url' => $this->releaseUrl($integration, $version, $event->input('project_id'))];
    }

    public function eventLabel(IntegrationEvent $event): string
    {
        return match ($event->action) {
            'release' => self::t('Release and deploy'),
            'rollback' => self::t('Rolled back to'),
            default => self::t('Sent'),
        };
    }

    private function createRelease(SentryClient $client, string $org, IntegrationEvent $event, string $version, string $project): void
    {
        $commit = $event->input('commit');
        $repo = $event->input('repo');
        $body = array_filter([
            'version' => $version,
            'projects' => [$project],
            'ref' => $commit,
            'commits' => $commit !== null && $repo !== null ? [array_filter(['id' => $commit, 'repository' => $repo, 'message' => $event->input('message')], fn ($v) => $v !== null && $v !== '')] : null,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $client->post($org.'releases/', $body);
        } catch (IntegrationFailed $e) {
            if ($e->reason !== IntegrationFailed::INVALID || ! isset($body['commits'])) {
                throw $e;
            }
            unset($body['commits']);
            $client->post($org.'releases/', $body);
        }
    }

    private function client(Integration $integration): SentryClient
    {
        return new SentryClient($integration->credential('token'), (string) self::region($integration->meta['region_url'] ?? null));
    }

    // The token only ever goes to Sentry's own hosts, whatever a response says.
    private static function region(mixed $url, ?string $fallback = SentryClient::URL): ?string
    {
        $host = is_string($url) && str_starts_with($url, 'https://') ? strtolower((string) parse_url($url, PHP_URL_HOST)) : '';

        return $host === 'sentry.io' || str_ends_with($host, '.sentry.io') ? rtrim((string) $url, '/') : $fallback;
    }

    private function slug(Integration $integration): string
    {
        return (string) ($integration->meta['slug'] ?? $integration->credential('organization'));
    }

    private function org(Integration $integration): string
    {
        return 'organizations/'.rawurlencode($this->slug($integration)).'/';
    }
}
