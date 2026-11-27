<?php

namespace App\Support\Deploys;

use App\Models\Workspace;
use App\Support\GitHub\GitHubApp;
use App\Support\Integrations\GitLab\GitLabRepositories;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;

/**
 * The first line of each deployed commit's message, read from GitHub through
 * the workspace's GitHub App, or from a connected GitLab account. A commit never changes, so an answer is kept
 * for good; a miss is tried again after an hour.
 */
final class CommitMessages
{
    private const API = 'https://api.github.com';

    /**
     * @param  list<string>  $commits
     * @return array<string, string> by commit
     */
    public static function for(Workspace $workspace, ?string $repo, array $commits): array
    {
        $name = DeployOnPush::repoName($repo);
        if ($name === null) {
            return is_string($repo) ? app(GitLabRepositories::class)->commitMessages($workspace, $repo, $commits) : [];
        }
        [$owner, $project] = explode('/', $name, 2);
        $token = false;
        $messages = [];

        foreach (array_values(array_unique($commits)) as $commit) {
            if (preg_match('/^[0-9a-f]{7,40}$/', $commit) !== 1) {
                continue;
            }
            $key = "github:commit-message:{$name}:{$commit}";
            $known = Cache::get($key);
            if (! is_string($known)) {
                $token = $token === false ? app(GitHubApp::class)->tokenFor($workspace, $owner, $project) : $token;
                if ($token === null) {
                    return $messages;
                }
                $known = self::fetch($token, $owner, $project, $commit);
                $known === null ? Cache::put($key, '', now()->addHour()) : Cache::forever($key, $known);
            }
            if (is_string($known) && $known !== '') {
                $messages[$commit] = $known;
            }
        }

        return $messages;
    }

    private static function fetch(string $token, string $owner, string $repo, string $commit): ?string
    {
        try {
            $response = app(GitHubApp::class)->rest($token)->get(self::API.'/repos/'.rawurlencode($owner).'/'.rawurlencode($repo).'/commits/'.rawurlencode($commit));
        } catch (ConnectionException) {
            return null;
        }
        $message = $response->json('commit.message');

        return $response->successful() && is_string($message)
            ? (string) str($message)->before("\n")->trim()->limit(120)
            : null;
    }
}
