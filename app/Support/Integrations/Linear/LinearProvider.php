<?php

namespace App\Support\Integrations\Linear;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\IntegrationIncident;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\Incidents\Problem;
use App\Support\Integrations\Incidents\RoutesIncidents;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;

final class LinearProvider extends Provider implements RoutesIncidents
{
    public const int MINUTES = 10;

    private const string TEAM = 'query($id: String!) { team(id: $id) { id key name states { nodes { id name type position } } projects(first: 100) { nodes { id name state } } } issueLabels(first: 250) { nodes { id name team { id } } } }';

    public function key(): string
    {
        return 'linear';
    }

    public function name(): string
    {
        return 'Linear';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'api_key',
                label: self::t('Personal API key'),
                help: self::t('In Linear, open Settings › Security & access › Personal API keys and make a key. ServerOS opens, comments on and closes issues as you.'),
                link: 'https://linear.app/settings/account/security',
                linkLabel: self::t('Open Linear settings'),
                placeholder: 'lin_api_…',
                rules: ['required', 'string', 'min:20', 'max:200', 'regex:/^\S+$/'],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::Issues];
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $data = (new LinearClient($credentials['api_key'] ?? ''))->query('query { viewer { id name } organization { id name urlKey } teams(first: 100) { nodes { id } } }');
        $organization = (string) ($data['organization']['name'] ?? 'Linear');

        return new ConnectedAccount($organization, [
            'organization' => $organization,
            'url_key' => isset($data['organization']['urlKey']) ? (string) $data['organization']['urlKey'] : null,
            'viewer' => isset($data['viewer']['name']) ? (string) $data['viewer']['name'] : null,
            'teams' => count((array) ($data['teams']['nodes'] ?? [])),
        ]);
    }

    public function summary(Integration $integration): string
    {
        $team = $this->current($integration)['team'];

        return $team === null ? self::t('Pick a team for its issues') : self::t('Issues in :team', ['team' => $team['name']]);
    }

    public function facts(Integration $integration): array
    {
        $s = $this->current($integration);

        return array_values(array_filter([
            isset($integration->meta['organization']) ? ['label' => self::t('Workspace'), 'value' => (string) $integration->meta['organization']] : null,
            isset($integration->meta['viewer']) ? ['label' => self::t('Opens issues as'), 'value' => (string) $integration->meta['viewer']] : null,
            ['label' => self::t('Team'), 'value' => $s['team']['name'] ?? '–'],
            ['label' => self::t('Open issues'), 'value' => (string) $integration->incidents()->whereNotNull('open_key')->whereNotNull('external_id')->count()],
        ]));
    }

    /**
     * @return array{team: array{id: string, key: string, name: string}|null, project: array{id: string, name: string}|null, label: array{id: string, name: string}|null, done: array{id: string, name: string}|null, monitors: bool, minutes: int, machines: bool, cves: bool}
     */
    public function current(Integration $integration): array
    {
        $pick = function (string $key) use ($integration): ?array {
            $value = $integration->setting($key);

            return is_array($value) && is_string($value['id'] ?? null) ? ['id' => $value['id'], 'name' => (string) ($value['name'] ?? '')] : null;
        };
        $team = $pick('team');
        $saved = $integration->setting('team');

        return [
            'team' => $team === null ? null : [...$team, 'key' => is_array($saved) ? (string) ($saved['key'] ?? '') : ''],
            'project' => $pick('project'),
            'label' => $pick('label'),
            'done' => $pick('done'),
            'monitors' => (bool) $integration->setting('monitors', true),
            'minutes' => max(0, (int) $integration->setting('minutes', self::MINUTES)),
            'machines' => (bool) $integration->setting('machines', true),
            'cves' => (bool) $integration->setting('cves', true),
        ];
    }

    public function wants(Integration $integration, Problem $problem): bool
    {
        $s = $this->current($integration);
        if ($s['team'] === null) {
            return false;
        }
        $long = $problem->since->lte(now()->subMinutes($s['minutes']));

        return match ($problem->kind) {
            Problem::MONITOR => $s['monitors'] && $long,
            Problem::MACHINE => $s['machines'] && $long,
            Problem::CVE => $s['cves'] && $problem->critical,
            default => false,
        };
    }

    public function settingsRules(Integration $integration): array
    {
        return [
            'team' => ['required', 'string', 'max:64'],
            'project' => ['nullable', 'string', 'max:64'],
            'label' => ['nullable', 'string', 'max:64'],
            'monitors' => ['required', 'boolean'],
            'minutes' => ['required', 'integer', 'min:0', 'max:1440'],
            'machines' => ['required', 'boolean'],
            'cves' => ['required', 'boolean'],
        ];
    }

    public function settings(Integration $integration, array $data): array
    {
        $options = $this->options($integration, (string) $data['team']);
        $team = $options['team'];
        if ($team === null) {
            throw new IntegrationFailed(self::t('That team isn’t in this Linear workspace any more.'), IntegrationFailed::NOT_FOUND);
        }
        $project = collect($options['projects'])->firstWhere('id', $data['project'] ?? null);
        $label = collect($options['labels'])->firstWhere('id', $data['label'] ?? null);
        if (filled($data['project'] ?? null) && $project === null) {
            throw new IntegrationFailed(self::t('That project isn’t in the team.'), IntegrationFailed::NOT_FOUND);
        }
        if (filled($data['label'] ?? null) && $label === null) {
            throw new IntegrationFailed(self::t('That label isn’t in the team.'), IntegrationFailed::NOT_FOUND);
        }

        return [
            'team' => $team,
            'project' => $project,
            'label' => $label,
            'done' => $options['done'],
            'monitors' => (bool) $data['monitors'],
            'minutes' => (int) $data['minutes'],
            'machines' => (bool) $data['machines'],
            'cves' => (bool) $data['cves'],
        ];
    }

    public function settingsProps(Integration $integration): array
    {
        return $this->current($integration);
    }

    /**
     * The teams, and for one team its projects, labels and where finished issues go.
     *
     * @return array{teams: list<array{id: string, key: string, name: string}>, team: array{id: string, key: string, name: string}|null, projects: list<array{id: string, name: string}>, labels: list<array{id: string, name: string}>, done: array{id: string, name: string}|null}
     */
    public function options(Integration $integration, ?string $teamId): array
    {
        $client = $this->client($integration);
        $teams = array_values(array_map(fn (array $t) => ['id' => (string) $t['id'], 'key' => (string) ($t['key'] ?? ''), 'name' => (string) ($t['name'] ?? '')], array_filter(
            (array) ($client->query('query { teams(first: 100) { nodes { id key name } } }')['teams']['nodes'] ?? []),
            fn ($t) => is_array($t) && is_string($t['id'] ?? null),
        )));
        $out = ['teams' => $teams, 'team' => null, 'projects' => [], 'labels' => [], 'done' => null];
        if ($teamId === null || $teamId === '' || ! in_array($teamId, array_column($teams, 'id'), true)) {
            return $out;
        }

        $data = $client->query(self::TEAM, ['id' => $teamId]);
        $team = (array) ($data['team'] ?? []);
        $states = array_values(array_filter((array) ($team['states']['nodes'] ?? []), fn ($s) => is_array($s) && ($s['type'] ?? null) === 'completed'));
        usort($states, fn (array $a, array $b) => (float) ($a['position'] ?? 0) <=> (float) ($b['position'] ?? 0));

        $out['team'] = ['id' => (string) ($team['id'] ?? $teamId), 'key' => (string) ($team['key'] ?? ''), 'name' => (string) ($team['name'] ?? '')];
        $out['done'] = isset($states[0]['id']) ? ['id' => (string) $states[0]['id'], 'name' => (string) ($states[0]['name'] ?? '')] : null;
        foreach ((array) ($team['projects']['nodes'] ?? []) as $p) {
            if (is_array($p) && is_string($p['id'] ?? null) && ! in_array($p['state'] ?? null, ['completed', 'canceled'], true)) {
                $out['projects'][] = ['id' => $p['id'], 'name' => (string) ($p['name'] ?? '')];
            }
        }
        foreach ((array) ($data['issueLabels']['nodes'] ?? []) as $l) {
            $owner = is_array($l) ? ($l['team']['id'] ?? null) : null;
            if (is_array($l) && is_string($l['id'] ?? null) && ($owner === null || $owner === $out['team']['id'])) {
                $out['labels'][] = ['id' => $l['id'], 'name' => (string) ($l['name'] ?? '')];
            }
        }

        return $out;
    }

    public function deliver(Integration $integration, IntegrationEvent $event): array
    {
        $incident = $event->incident;
        if (! $incident instanceof IntegrationIncident) {
            return ['skipped' => true];
        }
        $client = $this->client($integration);

        return match ($event->action) {
            'open' => $this->open($integration, $client, $event, $incident),
            'update' => $this->comment($client, $incident, $this->body($event)),
            'resolve' => $this->close($integration, $client, $incident, trim(((string) $event->input('note')).' ServerOS closed this issue.')),
            default => ['skipped' => true],
        };
    }

    public function eventLabel(IntegrationEvent $event): string
    {
        return match ($event->action) {
            'open' => self::t('Opened an issue'),
            'update' => self::t('Commented'),
            'resolve' => self::t('Closed the issue'),
            default => self::t('Sent'),
        };
    }

    /** @return array<string, mixed> */
    private function open(Integration $integration, LinearClient $client, IntegrationEvent $event, IntegrationIncident $incident): array
    {
        if ($incident->external_id !== null) {
            return ['key' => $incident->external_key, 'url' => $incident->external_url];
        }
        $s = $this->current($integration);
        if ($s['team'] === null) {
            throw new IntegrationFailed(self::t('Pick a team for Linear’s issues first.'));
        }
        $id = (string) $event->input('id');
        $issue = $event->attempts > 0 ? $this->find($client, $id) : null;

        if ($issue === null) {
            $created = $client->query('mutation($input: IssueCreateInput!) { issueCreate(input: $input) { success issue { id identifier url } } }', ['input' => array_filter([
                'id' => $id !== '' ? $id : null,
                'teamId' => $s['team']['id'],
                'projectId' => $s['project']['id'] ?? null,
                'labelIds' => isset($s['label']['id']) ? [$s['label']['id']] : null,
                'title' => mb_substr($event->subject, 0, 255),
                'description' => $this->body($event)."\n\nOpened by ServerOS. It comments here and closes this when it’s fixed.",
                'priority' => ($event->payload['critical'] ?? true) ? 1 : 2,
            ], fn ($v) => $v !== null)]);
            $issue = is_array($created['issueCreate']['issue'] ?? null) ? $created['issueCreate']['issue'] : null;
        }
        if ($issue === null || ! is_string($issue['id'] ?? null)) {
            throw new IntegrationFailed(self::t('Linear didn’t say which issue it opened.'));
        }

        $incident->forceFill([
            'external_id' => $issue['id'],
            'external_key' => isset($issue['identifier']) ? mb_substr((string) $issue['identifier'], 0, 40) : null,
            'external_url' => isset($issue['url']) ? mb_substr((string) $issue['url'], 0, 500) : null,
        ])->save();

        return ['key' => $incident->external_key, 'url' => $incident->external_url];
    }

    /** @return array<string, mixed>|null */
    private function find(LinearClient $client, string $id): ?array
    {
        if ($id === '') {
            return null;
        }
        try {
            $issue = $client->query('query($id: String!) { issue(id: $id) { id identifier url } }', ['id' => $id])['issue'] ?? null;
        } catch (IntegrationFailed $e) {
            if ($e->reason === IntegrationFailed::NOT_FOUND || $e->reason === IntegrationFailed::INVALID) {
                return null;
            }
            throw $e;
        }

        return is_array($issue) ? $issue : null;
    }

    /** @return array<string, mixed> */
    private function comment(LinearClient $client, IntegrationIncident $incident, string $body): array
    {
        if ($incident->external_id === null) {
            return ['skipped' => true];
        }
        $client->query('mutation($input: CommentCreateInput!) { commentCreate(input: $input) { success } }', ['input' => ['issueId' => $incident->external_id, 'body' => $body]]);

        return ['key' => $incident->external_key, 'url' => $incident->external_url];
    }

    /** @return array<string, mixed> */
    private function close(Integration $integration, LinearClient $client, IntegrationIncident $incident, string $note): array
    {
        if ($incident->external_id === null) {
            return ['skipped' => true];
        }
        $this->comment($client, $incident, $note);
        $done = $this->current($integration)['done'];
        if ($done === null && ($team = $this->current($integration)['team']) !== null) {
            $done = $this->options($integration, $team['id'])['done'];
        }
        if ($done !== null) {
            $client->query('mutation($id: String!, $input: IssueUpdateInput!) { issueUpdate(id: $id, input: $input) { success } }', ['id' => $incident->external_id, 'input' => ['stateId' => $done['id']]]);
        }

        return ['key' => $incident->external_key, 'url' => $incident->external_url, 'state' => $done['name'] ?? null];
    }

    private function body(IntegrationEvent $event): string
    {
        $lines = array_map('strval', array_values((array) ($event->payload['lines'] ?? [])));
        $url = (string) ($event->payload['url'] ?? '');

        return trim(implode("\n\n", $lines).($url !== '' ? "\n\n[Open in ServerOS]({$url})" : ''));
    }

    private function client(Integration $integration): LinearClient
    {
        return new LinearClient($integration->credential('api_key'));
    }
}
