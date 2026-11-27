<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A small stand-in for the GitLab REST API on gitlab.com and on a
 * self-managed instance, so tests never call the real one. It keeps
 * projects with their branches, commits, files and webhooks, records every
 * call, and can be told to fail the next call to a path.
 */
final class FakeGitLab
{
    public const string TOKEN = 'glpat-test-token-0123456789abcdef';

    public const string HOOKS_TOKEN = 'glpat-hooks-token-0123456789abcdef';

    /** @var list<string> */
    public array $scopes = ['read_api', 'read_repository'];

    /** @var array<string, array<string, mixed>> by lowercased path */
    public array $projects = [];

    /** @var array<int, array<string, mixed>> */
    public array $hooks = [];

    /** @var list<array{method: string, host: string, path: string, query: array<string, mixed>, body: array<string, mixed>, token: string|null}> */
    public array $calls = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $failures = [];

    private int $nextHook = 300;

    /** @param  list<string>  $hosts */
    public static function install(array $hosts = ['gitlab.com', 'gitlab.example.com'], bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        $routes = [];
        foreach ($hosts as $host) {
            $routes["{$host}/api/v4/*"] = fn (Request $request) => $fake->handle($request);
        }
        Http::fake($routes);

        return $fake;
    }

    /**
     * @param  array<string, string>  $files  path => contents, on every branch
     * @param  array<string, string>  $branches  name => commit
     * @param  array<string, mixed>  $overrides
     */
    public function project(int $id, string $path, array $files = [], array $branches = ['main' => '1111111111111111111111111111111111111111'], array $overrides = []): self
    {
        $this->projects[strtolower($path)] = [
            'id' => $id,
            'path_with_namespace' => $path,
            'name_with_namespace' => str_replace('/', ' / ', $path),
            'web_url' => 'https://gitlab.com/'.$path,
            'default_branch' => array_key_first($branches) ?? 'main',
            'visibility' => 'private',
            'last_activity_at' => '2026-11-20T10:00:00.000Z',
            'empty_repo' => false,
            'archived' => false,
            ...$overrides,
            '_files' => $files,
            '_branches' => $branches,
            '_titles' => [],
        ];

        return $this;
    }

    public function title(string $path, string $commit, string $title): self
    {
        $this->projects[strtolower($path)]['_titles'][$commit] = $title;

        return $this;
    }

    /** @param  array<string, mixed>  $body */
    public function failNext(string $method, string $path, int $status, array $body = []): self
    {
        $this->failures[strtoupper($method).' '.$path][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** @return list<string> */
    public function called(?string $method = null): array
    {
        return array_values(array_map(
            fn (array $call) => $call['method'].' '.$call['path'],
            array_filter($this->calls, fn (array $call) => $method === null || $call['method'] === $method),
        ));
    }

    /** @return array<string, mixed>|null */
    public function bodyOf(string $method, string $path): ?array
    {
        foreach (array_reverse($this->calls) as $call) {
            if ($call['method'] === $method && $call['path'] === $path) {
                return $call['body'];
            }
        }

        return null;
    }

    public function handle(Request $request): PromiseInterface
    {
        $url = parse_url($request->url());
        $raw = substr((string) ($url['path'] ?? ''), strlen('/api/v4/'));
        $segments = array_map('rawurldecode', explode('/', $raw));
        $path = implode('/', array_map(fn (string $s) => str_replace('/', '%2F', $s), $segments));
        parse_str((string) ($url['query'] ?? ''), $query);
        $method = strtoupper($request->method());
        $body = $request->data();
        $token = $request->header('PRIVATE-TOKEN')[0] ?? null;
        $this->calls[] = ['method' => $method, 'host' => (string) ($url['host'] ?? ''), 'path' => $path, 'query' => $query, 'body' => is_array($body) ? $body : [], 'token' => $token];

        $key = $method.' '.$path;
        if (($this->failures[$key] ?? []) !== [] && ($failure = array_shift($this->failures[$key])) !== null) {
            return Http::response($failure['body'] === [] ? ['message' => '500 Internal Server Error'] : $failure['body'], $failure['status']);
        }

        $anonymous = $token === null;
        if (! $anonymous && ! in_array($token, [self::TOKEN, self::HOOKS_TOKEN], true)) {
            return Http::response(['message' => '401 Unauthorized'], 401);
        }
        $scopes = $token === self::HOOKS_TOKEN ? ['api'] : $this->scopes;

        if ($segments === ['user']) {
            return $anonymous ? Http::response(['message' => '401 Unauthorized'], 401) : Http::response(['id' => 7, 'username' => 'liam', 'name' => 'Liam']);
        }
        if ($segments === ['personal_access_tokens', 'self']) {
            return Http::response(['id' => 1, 'name' => 'ServerOS', 'scopes' => $scopes, 'active' => true]);
        }
        if ($segments === ['projects'] && $method === 'GET') {
            $list = array_values(array_map(fn (array $p) => $this->public($p), array_filter($this->projects, fn (array $p) => ! $anonymous)));
            $page = max(1, (int) ($query['page'] ?? 1));
            $per = max(1, (int) ($query['per_page'] ?? 20));

            return Http::response(array_slice($list, ($page - 1) * $per, $per));
        }
        if (($segments[0] ?? null) !== 'projects' || ! isset($segments[1])) {
            return Http::response(['message' => '404 Not Found'], 404);
        }

        $project = $this->projects[strtolower($segments[1])] ?? null;
        if ($project === null || ($anonymous && $project['visibility'] !== 'public')) {
            return Http::response(['message' => '404 Project Not Found'], 404);
        }
        $rest = array_slice($segments, 2);

        if ($rest === []) {
            return Http::response($this->public($project));
        }
        if ($rest === ['repository', 'branches']) {
            $default = $project['default_branch'];

            return Http::response(array_values(array_map(fn (string $name, string $sha) => ['name' => $name, 'default' => $name === $default, 'commit' => ['id' => $sha]], array_keys($project['_branches']), $project['_branches'])));
        }
        if (($rest[0] ?? null) === 'repository' && ($rest[1] ?? null) === 'commits' && isset($rest[2])) {
            $ref = implode('/', array_slice($rest, 2));
            $sha = $project['_branches'][$ref] ?? (preg_match('/^[0-9a-f]{40}$/', $ref) === 1 ? $ref : null);
            if ($sha === null) {
                return Http::response(['message' => '404 Commit Not Found'], 404);
            }

            return Http::response(['id' => $sha, 'short_id' => substr($sha, 0, 8), 'title' => $project['_titles'][$sha] ?? 'Commit '.substr($sha, 0, 7)]);
        }
        if ($rest === ['repository', 'tree']) {
            $folder = trim((string) ($query['path'] ?? ''), '/');
            $names = [];
            foreach (array_keys($project['_files']) as $file) {
                $inside = $folder === '' ? $file : (str_starts_with($file, $folder.'/') ? substr($file, strlen($folder) + 1) : null);
                if ($inside !== null) {
                    $names[explode('/', $inside)[0]] = ['name' => explode('/', $inside)[0], 'type' => str_contains($inside, '/') ? 'tree' : 'blob'];
                }
            }

            return Http::response(array_values($names));
        }
        if (($rest[0] ?? null) === 'repository' && ($rest[1] ?? null) === 'files' && ($rest[3] ?? null) === 'raw') {
            $file = $rest[2];

            return array_key_exists($file, $project['_files'])
                ? Http::response($project['_files'][$file], 200, ['Content-Type' => 'text/plain'])
                : Http::response(['message' => '404 File Not Found'], 404);
        }
        if ($rest === ['hooks'] && $method === 'POST') {
            if (! in_array('api', $scopes, true)) {
                return Http::response(['error' => 'insufficient_scope', 'error_description' => 'The request requires higher privileges than provided by the access token.', 'scope' => 'api'], 403);
            }
            $id = $this->nextHook++;
            $this->hooks[$id] = ['id' => $id, 'project_id' => $project['id'], 'url' => $body['url'] ?? null, 'token' => $body['token'] ?? null, 'push_events' => $body['push_events'] ?? null, 'alert_status' => 'executable'];

            return Http::response(array_diff_key($this->hooks[$id], ['token' => true]), 201);
        }
        if (($rest[0] ?? null) === 'hooks' && isset($rest[1])) {
            $hook = $this->hooks[(int) $rest[1]] ?? null;
            if ($hook === null || $hook['project_id'] !== $project['id']) {
                return Http::response(['message' => '404 Not Found'], 404);
            }
            if ($method === 'DELETE') {
                unset($this->hooks[(int) $rest[1]]);

                return Http::response(null, 204);
            }

            return Http::response(array_diff_key($hook, ['token' => true]));
        }

        return Http::response(['message' => '404 Not Found'], 404);
    }

    /**
     * @param  array<string, mixed>  $project
     * @return array<string, mixed>
     */
    private function public(array $project): array
    {
        return array_diff_key($project, ['_files' => true, '_branches' => true, '_titles' => true]);
    }
}
