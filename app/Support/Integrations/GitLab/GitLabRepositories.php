<?php

namespace App\Support\Integrations\GitLab;

use App\Models\Integration;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Integrations\IntegrationFailed;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * The GitLab accounts a workspace connected, as a place to deploy code from:
 * their projects for the deploy dialog, the account to clone a project's
 * address with, its branches and its commit messages.
 */
final class GitLabRepositories
{
    public const string PROVIDER = 'gitlab';

    private const string PATH = '[A-Za-z0-9_.][A-Za-z0-9_.-]*(?:/[A-Za-z0-9_.][A-Za-z0-9_.-]*){1,20}';

    /** @return Collection<int, Integration> */
    public function accounts(Workspace $workspace): Collection
    {
        return $workspace->integrations()->where('provider', self::PROVIDER)->orderBy('created_at')->orderBy('id')->get()
            ->sortBy(fn (Integration $integration) => $integration->status === Integration::CONNECTED ? 0 : 1)
            ->values();
    }

    public static function instanceOf(Integration $integration): string
    {
        return GitLabClient::normalise($integration->credential('url')) ?? GitLabClient::GITLAB_COM;
    }

    public static function client(Integration $integration): GitLabClient
    {
        return new GitLabClient(self::instanceOf($integration), $integration->credential('token'));
    }

    /**
     * A project address on an instance, split into the project's path and
     * what follows /-/tree/ or /tree/ (a branch and folder), or null when the
     * address isn't on that instance.
     *
     * @return array{path: string, tree: string|null}|null
     */
    public static function parse(string $instance, ?string $url): ?array
    {
        $url = trim((string) $url);
        $base = strtolower($instance).'/';
        if (! str_starts_with(strtolower($url), $base)) {
            return null;
        }
        $rest = (string) preg_replace('#[?\#].*$#', '', substr($url, strlen($base)));
        $tree = null;
        foreach (['/-/tree/', '/tree/'] as $marker) {
            if (str_contains($rest, $marker)) {
                [$rest, $tree] = explode($marker, $rest, 2);
                $tree = trim($tree, '/') === '' ? null : trim($tree, '/');
                break;
            }
        }
        $rest = (string) preg_replace('#(?:\.git)?/*$#', '', $rest);
        if (preg_match('#^'.self::PATH.'$#', $rest) !== 1 || str_contains($rest, '..') || str_contains($rest, '/-')) {
            return null;
        }

        return ['path' => $rest, 'tree' => $tree];
    }

    /** The project's path, lowercased, when the address is a project on this instance. */
    public static function pathOn(string $instance, ?string $url): ?string
    {
        $parsed = self::parse($instance, $url);

        return $parsed === null || $parsed['tree'] !== null ? null : strtolower($parsed['path']);
    }

    /**
     * The connected account a project address belongs to, and the project's
     * path. With several accounts on one instance, the first that can see
     * the project wins.
     *
     * @return array{0: Integration, 1: string}|null
     */
    public function find(Workspace $workspace, ?string $url): ?array
    {
        if (! is_string($url) || ! str_starts_with(strtolower($url), 'https://')) {
            return null;
        }
        $candidates = [];
        foreach ($this->accounts($workspace) as $integration) {
            $parsed = self::parse(self::instanceOf($integration), $url);
            if ($parsed !== null) {
                $candidates[] = [$integration, $parsed['path']];
            }
        }
        if (count($candidates) <= 1) {
            return $candidates[0] ?? null;
        }
        foreach ($candidates as [$integration, $path]) {
            if ($this->canSee($integration, $path)) {
                return [$integration, $path];
            }
        }

        return $candidates[0];
    }

    /** Whether the address is a project on the instance of one of the workspace's GitLab accounts, without asking GitLab. */
    public function onConnectedInstance(Workspace $workspace, mixed $url): bool
    {
        return is_string($url) && $this->accounts($workspace)->contains(fn (Integration $integration) => self::parse(self::instanceOf($integration), $url) !== null);
    }

    private function canSee(Integration $integration, string $path): bool
    {
        $key = "gitlab:sees:{$integration->id}:".strtolower($path);
        $known = Cache::get($key);
        if (is_bool($known)) {
            return $known;
        }
        try {
            $seen = isset(self::client($integration)->get(GitLabClient::project($path))['id']);
        } catch (IntegrationFailed) {
            $seen = false;
        }
        Cache::put($key, $seen, now()->addMinutes(10));

        return $seen;
    }

    /**
     * Every connected account's projects, most recently active first, for
     * picking one to deploy. Each account's list is kept for a minute.
     *
     * @return array{accounts: list<array{id: int, login: string, label: string, host: string, connectedBy: string|null, error: string|null}>, repositories: list<array{name: string, url: string, private: bool, visibility: string, branch: string, pushed: string, language: null, provider: string, account: int}>}
     */
    public function listFor(Workspace $workspace): array
    {
        $accounts = $this->accounts($workspace);
        $people = User::query()->whereIn('id', $accounts->pluck('created_by')->filter())->pluck('name', 'id');
        $out = [];
        $repositories = [];

        foreach ($accounts as $integration) {
            $error = null;
            try {
                foreach ($this->projects($integration) as $project) {
                    $repositories[] = [...$project, 'account' => $integration->id];
                }
            } catch (IntegrationFailed $e) {
                $error = $e->getMessage();
                self::failed($integration, $e);
            }
            $out[] = [
                'id' => $integration->id,
                'login' => (string) ($integration->meta['username'] ?? $integration->label),
                'label' => $integration->label,
                'host' => GitLabClient::host(self::instanceOf($integration)),
                'connectedBy' => $people[$integration->created_by] ?? null,
                'error' => $error,
            ];
        }
        usort($repositories, fn (array $a, array $b) => strcmp($b['pushed'], $a['pushed']));

        return ['accounts' => $out, 'repositories' => $repositories];
    }

    /**
     * @return list<array{name: string, url: string, private: bool, visibility: string, branch: string, pushed: string, language: null, provider: string}>
     *
     * @throws IntegrationFailed
     */
    public function projects(Integration $integration): array
    {
        return Cache::remember("gitlab:projects:{$integration->id}", 60, fn () => $this->fetchProjects($integration));
    }

    /**
     * @return list<array{name: string, url: string, private: bool, visibility: string, branch: string, pushed: string, language: null, provider: string}>
     *
     * @throws IntegrationFailed
     */
    private function fetchProjects(Integration $integration): array
    {
        $instance = self::instanceOf($integration);
        $list = [];
        $projects = self::client($integration)->all('projects', [
            'membership' => 'true',
            'archived' => 'false',
            'min_access_level' => 20,
            'order_by' => 'last_activity_at',
            'sort' => 'desc',
        ]);
        foreach ($projects as $project) {
            $path = $project['path_with_namespace'] ?? null;
            if (! is_string($path) || ($project['empty_repo'] ?? false) === true || self::parse($instance, $instance.'/'.$path) === null) {
                continue;
            }
            $visibility = in_array($project['visibility'] ?? null, ['public', 'internal', 'private'], true) ? (string) $project['visibility'] : 'private';
            $list[] = [
                'name' => $path,
                'url' => $instance.'/'.$path,
                'private' => $visibility !== 'public',
                'visibility' => $visibility,
                'branch' => is_string($project['default_branch'] ?? null) ? $project['default_branch'] : '',
                'pushed' => is_string($project['last_activity_at'] ?? null) ? $project['last_activity_at'] : '',
                'language' => null,
                'provider' => self::PROVIDER,
            ];
        }

        return $list;
    }

    public static function forget(Integration $integration): void
    {
        Cache::forget("gitlab:projects:{$integration->id}");
    }

    /**
     * A project's branches, default first, up to five hundred.
     *
     * @return list<string>
     *
     * @throws IntegrationFailed
     */
    public function branches(Integration $integration, string $path): array
    {
        $names = [];
        $default = null;
        foreach (self::client($integration)->all(GitLabClient::project($path).'/repository/branches') as $branch) {
            if (is_string($branch['name'] ?? null)) {
                ($branch['default'] ?? false) === true ? $default = $branch['name'] : $names[] = $branch['name'];
            }
        }

        return $default === null ? $names : [$default, ...$names];
    }

    /**
     * The first line of each commit's message. A commit never changes, so an
     * answer is kept for good; a miss is tried again after an hour.
     *
     * @param  list<string>  $commits
     * @return array<string, string> by commit
     */
    public function commitMessages(Workspace $workspace, string $url, array $commits): array
    {
        $found = $this->find($workspace, $url);
        if ($found === null) {
            return [];
        }
        [$integration, $path] = $found;
        $name = GitLabClient::host(self::instanceOf($integration)).'/'.strtolower($path);
        $client = self::client($integration);
        $messages = [];

        foreach (array_values(array_unique($commits)) as $commit) {
            if (preg_match('/^[0-9a-f]{7,40}$/', $commit) !== 1) {
                continue;
            }
            $key = "gitlab:commit-message:{$name}:{$commit}";
            $known = Cache::get($key);
            if (! is_string($known)) {
                try {
                    $title = $client->get(GitLabClient::project($path).'/repository/commits/'.$commit)['title'] ?? null;
                    $known = is_string($title) ? (string) str($title)->trim()->limit(120) : null;
                } catch (IntegrationFailed $e) {
                    if ($e->reason !== IntegrationFailed::NOT_FOUND) {
                        return $messages;
                    }
                    $known = null;
                }
                $known === null ? Cache::put($key, '', now()->addHour()) : Cache::forever($key, $known);
            }
            if (is_string($known) && $known !== '') {
                $messages[$commit] = $known;
            }
        }

        return $messages;
    }

    public static function failed(Integration $integration, IntegrationFailed $e): void
    {
        if (! $e->refusedCredentials()) {
            return;
        }
        $integration->forceFill([
            'status' => Integration::FAILING,
            'last_checked_at' => now(),
            'last_error' => $e->getMessage(),
        ])->save();
    }
}
