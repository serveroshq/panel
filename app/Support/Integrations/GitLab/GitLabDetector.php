<?php

namespace App\Support\Integrations\GitLab;

use App\Models\Integration;
use App\Models\Workspace;
use App\Support\Integrations\IntegrationFailed;
use App\Support\ServiceTemplates\RepositoryDetector;
use Illuminate\Validation\ValidationException;

/**
 * Looks at a GitLab project the way RepositoryDetector looks at a GitHub
 * repository: the branch's commit, the files at the root (or in a folder)
 * and the manifests among them. Private projects are read with the
 * workspace's GitLab account; public gitlab.com ones work without one.
 */
final class GitLabDetector
{
    public function __construct(private readonly GitLabRepositories $repositories) {}

    /** Whether the address is a GitLab project this detector should look at. */
    public function handles(string $url, ?Workspace $workspace): bool
    {
        return $this->source($url, $workspace) !== null;
    }

    /**
     * @return array{0: string, 1: Integration|null, 2: string, 3: string|null}|null instance, account, path, tree
     */
    private function source(string $url, ?Workspace $workspace): ?array
    {
        $url = trim($url);
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $url = (string) preg_replace('#^http://#i', 'https://', $url);
        $found = $workspace === null ? null : $this->repositories->find($workspace, $url);
        if ($found !== null) {
            $instance = GitLabRepositories::instanceOf($found[0]);
            $parsed = GitLabRepositories::parse($instance, $url);

            return $parsed === null ? null : [$instance, $found[0], $parsed['path'], $parsed['tree']];
        }
        $parsed = GitLabRepositories::parse(GitLabClient::GITLAB_COM, (string) preg_replace('#^https://www\.gitlab\.com/#i', GitLabClient::GITLAB_COM.'/', $url));

        return $parsed === null ? null : [GitLabClient::GITLAB_COM, null, $parsed['path'], $parsed['tree']];
    }

    /**
     * @return array{detected: bool, template: string|null, framework: string|null, type: string|null, repo: string, branch: string, commit: string, baseDir: string|null, reasons: list<string>, php: string|null, healthPath: string|null, composeFile: string|null, composePort: int|null}
     */
    public function detect(RepositoryDetector $detector, string $url, ?Workspace $workspace): array
    {
        [$instance, $integration, $path, $tree] = $this->source($url, $workspace) ?? throw ValidationException::withMessages(['repository' => self::t('Paste a GitLab project’s address, like https://gitlab.com/you/app.')]);
        $client = $integration === null ? new GitLabClient($instance, '') : GitLabRepositories::client($integration);
        $project = GitLabClient::project($path);

        try {
            $found = $client->get($project);
            if ($found === [] || ($integration === null && ($found['visibility'] ?? null) !== 'public')) {
                throw new IntegrationFailed('', IntegrationFailed::NOT_FOUND, 404);
            }
            if (($found['empty_repo'] ?? false) === true) {
                throw ValidationException::withMessages(['repository' => self::t(':project has no commits yet. Push some code to it first.', ['project' => $path])]);
            }
            $path = is_string($found['path_with_namespace'] ?? null) ? $found['path_with_namespace'] : $path;
            $project = GitLabClient::project($path);
            [$branch, $baseDir, $sha] = $this->branchAndFolder($client, $project, $tree, (string) ($found['default_branch'] ?? 'main'));

            $files = [];
            foreach ($client->all($project.'/repository/tree', array_filter(['ref' => $sha, 'path' => $baseDir]), 3) as $entry) {
                if (is_string($entry['name'] ?? null)) {
                    $files[] = $entry['name'];
                }
            }
            if ($baseDir !== null && $files === []) {
                throw ValidationException::withMessages(['repository' => self::t('Couldn’t find the folder :folder on the :branch branch.', ['folder' => $baseDir, 'branch' => $branch])]);
            }

            $contents = [];
            foreach (RepositoryDetector::MANIFESTS as $manifest) {
                if (in_array($manifest, $files, true)) {
                    $file = $baseDir === null ? $manifest : "{$baseDir}/{$manifest}";
                    $contents[$manifest] = $client->raw($project.'/repository/files/'.rawurlencode($file).'/raw', ['ref' => $sha]);
                }
            }
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages(['repository' => match (true) {
                $e->reason === IntegrationFailed::NOT_FOUND && $integration === null => self::t('That project doesn’t exist, or it’s private. To deploy private GitLab projects, connect GitLab in Integrations.'),
                $e->reason === IntegrationFailed::NOT_FOUND => self::t('That project doesn’t exist, or :account can’t see it.', ['account' => $integration->label]),
                default => $e->getMessage(),
            }]);
        }

        $match = $detector->match($files, $contents);
        if ($baseDir !== null && isset($match['composeFile'])) {
            $match['composeFile'] = "{$baseDir}/{$match['composeFile']}";
        }

        return [
            'detected' => $match !== null,
            'template' => $match['template'] ?? null,
            'framework' => $match['framework'] ?? null,
            'type' => $match['type'] ?? null,
            'repo' => "{$instance}/{$path}.git",
            'branch' => $branch,
            'commit' => $sha,
            'baseDir' => $baseDir,
            'reasons' => $match['reasons'] ?? [],
            'php' => $match['php'] ?? null,
            'healthPath' => $match['healthPath'] ?? null,
            'composeFile' => $match['composeFile'] ?? null,
            'composePort' => $match['composePort'] ?? null,
        ];
    }

    /**
     * The branch and folder a tree address points at: branch names can have
     * slashes, so the shortest prefix GitLab knows as a ref wins.
     *
     * @return array{0: string, 1: string|null, 2: string}
     *
     * @throws IntegrationFailed
     */
    private function branchAndFolder(GitLabClient $client, string $project, ?string $tree, string $default): array
    {
        $parts = $tree === null ? [] : array_values(array_filter(explode('/', $tree), fn (string $p) => $p !== ''));
        $candidates = $parts === [] ? [[$default, '']] : [];
        for ($i = 1; $i <= min(count($parts), 4); $i++) {
            $candidates[] = [implode('/', array_slice($parts, 0, $i)), implode('/', array_slice($parts, $i))];
        }

        foreach ($candidates as [$branch, $folder]) {
            try {
                $sha = $client->get($project.'/repository/commits/'.rawurlencode($branch))['id'] ?? null;
            } catch (IntegrationFailed $e) {
                if ($e->reason === IntegrationFailed::NOT_FOUND) {
                    continue;
                }
                throw $e;
            }
            if (! is_string($sha) || preg_match('/^[0-9a-f]{40}$/', $sha) !== 1) {
                continue;
            }
            if ($folder !== '' && preg_match(RepositoryDetector::BASE_DIR_PATTERN, $folder) !== 1) {
                throw ValidationException::withMessages(['repository' => self::t(':folder isn’t a folder ServerOS can build from.', ['folder' => $folder])]);
            }

            return [$branch, $folder === '' ? null : $folder, $sha];
        }

        throw ValidationException::withMessages(['repository' => self::t('Couldn’t find the :branch branch.', ['branch' => $candidates[0][0] ?? $default])]);
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
