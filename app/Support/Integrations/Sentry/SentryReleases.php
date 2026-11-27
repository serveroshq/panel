<?php

namespace App\Support\Integrations\Sentry;

use App\Models\DaemonJob;
use App\Models\Integration;
use App\Models\IntegrationApp;
use App\Models\IntegrationEvent;
use App\Models\Machine;
use App\Support\Deploys\CommitMessages;
use App\Support\Integrations\Incidents\IntegrationEvents;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Ties an app's deploys to the Sentry project it's linked to: a release for
 * each deploy, the older release marked deployed again on a roll back, and
 * SENTRY_RELEASE set on the app so its events say which release they're from.
 */
final class SentryReleases
{
    public const string ENV = 'SENTRY_RELEASE';

    private const int NEW_ISSUES_MINUTES = 5;

    public static function link(Machine $machine, string $service): ?IntegrationApp
    {
        return IntegrationApp::query()
            ->where('machine_id', $machine->id)
            ->where('service', $service)
            ->whereHas('integration', fn ($q) => $q->where('provider', 'sentry')->where('workspace_id', $machine->workspace_id))
            ->with('integration')
            ->first();
    }

    /**
     * The deploy job with SENTRY_RELEASE set, when the app is linked and deploys a commit.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    public static function withRelease(Machine $machine, array $job): array
    {
        $commit = is_string($job['commit'] ?? null) ? strtolower($job['commit']) : '';
        if ($commit === '' || ! is_string($job['service'] ?? null) || self::link($machine, $job['service']) === null) {
            return $job;
        }
        $job['env'] = (object) [...(array) ($job['env'] ?? []), self::ENV => $commit];

        return $job;
    }

    /** Called when a deploy or roll back finishes. */
    public function jobFinished(DaemonJob $job): void
    {
        if ($job->state !== 'succeeded' || ! is_string($job->service) || ! in_array($job->kind, ['deploy', 'rollback'], true)) {
            return;
        }
        try {
            $link = self::link($job->machine, $job->service);
            if ($link === null || $link->integration->status !== Integration::CONNECTED) {
                return;
            }
            $spec = (array) ($job->command['job'] ?? []);
            $version = strtolower((string) ($job->kind === 'rollback' ? ($spec['release'] ?? $job->result['commit'] ?? '') : ($spec['commit'] ?? '')));
            if ($version === '') {
                return;
            }
            $machine = $job->machine;
            $repo = self::repoName($spec['repo'] ?? null);
            $message = $job->kind === 'deploy' && $repo !== null ? self::message($machine, (string) $spec['repo'], $version) : null;

            IntegrationEvents::queue($link->integration, $job->kind === 'rollback' ? 'rollback' : 'release', "{$job->service} on {$machine->name} · ".substr($version, 0, 7), [
                'version' => $version,
                'project' => $link->setting('project'),
                'project_id' => $link->setting('project_id'),
                'environment' => $link->setting('environment') ?? $machine->environment->value,
                'commit' => $version,
                'repo' => $repo,
                'message' => $message,
                'name' => "{$job->service} on {$machine->name}",
                'machine_id' => $machine->id,
                'service' => $job->service,
                'job_id' => $job->id,
                'finished_at' => ($job->finished_at ?? now())->toIso8601String(),
            ]);
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * What the service page shows: the linked project and each kept version's
     * release, with the count of new issues Sentry saw in it.
     *
     * @param  list<string>  $commits
     * @return array{accounts: list<array{id: int, label: string}>, link: array{integrationId: int, account: string, project: string, projectId: string|null, projectName: string, environment: string}|null, releases: array<string, array{url: string, newIssues: int|null}>, error: string|null}
     */
    public static function props(Machine $machine, string $service, array $commits): array
    {
        $accounts = $machine->workspace->integrations()->where('provider', 'sentry')->orderBy('id')->get(['id', 'label']);
        $out = [
            'accounts' => array_values($accounts->map(fn (Integration $i) => ['id' => $i->id, 'label' => $i->label])->all()),
            'link' => null,
            'releases' => [],
            'error' => null,
        ];
        $link = self::link($machine, $service);
        if ($link === null) {
            return $out;
        }
        $integration = $link->integration;
        $provider = $integration->provider();
        $project = (string) $link->setting('project');
        $projectId = $link->setting('project_id');
        $out['link'] = [
            'integrationId' => $integration->id,
            'account' => $integration->label,
            'project' => $project,
            'projectId' => $projectId,
            'projectName' => $link->setting('name') ?? $project,
            'environment' => $link->setting('environment') ?? $machine->environment->value,
        ];
        if (! $provider instanceof SentryProvider) {
            return $out;
        }

        $sent = IntegrationEvent::query()
            ->where('integration_id', $integration->id)
            ->whereIn('action', ['release', 'rollback'])
            ->where('state', 'sent')
            ->where('payload->machine_id', $machine->id)
            ->where('payload->service', $service)
            ->get(['id', 'payload'])
            ->map(fn (IntegrationEvent $e) => (string) $e->input('version'))
            ->unique()
            ->all();

        foreach (array_slice(array_values(array_unique($commits)), 0, 5) as $commit) {
            $version = strtolower($commit);
            if (! in_array($version, $sent, true)) {
                continue;
            }
            $key = "integrations:{$integration->id}:sentry-release:".sha1($version.'|'.$projectId);
            $newIssues = Cache::get($key);
            if (! is_array($newIssues) && $out['error'] === null && $integration->status === Integration::CONNECTED) {
                try {
                    $newIssues = ['count' => $provider->newIssues($integration, $version, $projectId)];
                    Cache::put($key, $newIssues, now()->addMinutes(self::NEW_ISSUES_MINUTES));
                } catch (IntegrationFailed $e) {
                    $out['error'] = $e->getMessage();
                }
            }
            $out['releases'][$commit] = [
                'url' => $provider->releaseUrl($integration, $version, $projectId),
                'newIssues' => is_array($newIssues) && is_int($newIssues['count'] ?? null) ? $newIssues['count'] : null,
            ];
        }

        return $out;
    }

    /** "owner/name" from a repository's https address; null for uploads and anything else. */
    public static function repoName(mixed $url): ?string
    {
        if (! is_string($url) || str_contains($url, '/api/deploy-sources/') || preg_match('#^https://[^/\s]+/(.+?)(?:\.git)?/?$#', $url, $m) !== 1) {
            return null;
        }

        return mb_substr($m[1], 0, 200);
    }

    private static function message(Machine $machine, string $repo, string $commit): ?string
    {
        try {
            $messages = CommitMessages::for($machine->workspace, $repo, [$commit]);
        } catch (Throwable) {
            return null;
        }
        $message = $messages[$commit] ?? null;

        return is_string($message) ? mb_substr($message, 0, 500) : null;
    }
}
