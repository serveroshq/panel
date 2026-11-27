<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * A stand-in for Sentry's web API, on sentry.io and its US region: one
 * organization with two projects, releases with their deploys, every call
 * recorded, and failures on demand.
 */
final class FakeSentry
{
    public const string TOKEN = 'sntryu_test0123456789abcdef0123456789abcdef0123';

    /** @var array<string, array<string, mixed>> */
    public array $releases = [];

    /** @var list<array{method: string, host: string, path: string, body: array<string, mixed>, query: array<string, mixed>}> */
    public array $calls = [];

    public bool $rejectCommits = false;

    /** @var array<string, list<array{status: int, body: array<string, mixed>}>> */
    private array $failures = [];

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake(['sentry.io/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    /** @param  array<string, mixed>  $body */
    public function failNext(string $method, string $path, int $status, array $body = []): self
    {
        $this->failures[strtoupper($method).' '.$path][] = ['status' => $status, 'body' => $body];

        return $this;
    }

    /** @return list<string> */
    public function called(): array
    {
        return array_map(fn (array $c) => $c['method'].' '.$c['path'], $this->calls);
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
        $path = trim(substr((string) ($url['path'] ?? ''), strlen('/api/0')), '/');
        parse_str((string) ($url['query'] ?? ''), $query);
        $method = strtoupper($request->method());
        $body = (array) $request->data();
        $this->calls[] = ['method' => $method, 'host' => (string) ($url['host'] ?? ''), 'path' => $path, 'body' => $body, 'query' => $query];

        $key = $method.' '.$path;
        if (($this->failures[$key] ?? []) !== [] && ($failure = array_shift($this->failures[$key])) !== null) {
            return Http::response($failure['body'] === [] ? ['detail' => 'Internal Error'] : $failure['body'], $failure['status']);
        }
        if (($request->header('Authorization')[0] ?? null) !== 'Bearer '.self::TOKEN) {
            return Http::response(['detail' => 'Invalid token'], 401);
        }
        $parts = explode('/', $path);
        if (($parts[0] ?? null) !== 'organizations' || ($parts[1] ?? null) !== 'acme') {
            return Http::response(['detail' => 'The requested resource does not exist'], 404);
        }
        $rest = array_slice($parts, 2);
        $version = isset($rest[1]) ? rawurldecode($rest[1]) : null;

        return match (true) {
            $method === 'GET' && $rest === [] => Http::response([
                'id' => '1', 'slug' => 'acme', 'name' => 'Acme',
                'links' => ['organizationUrl' => 'https://acme.sentry.io', 'regionUrl' => 'https://us.sentry.io'],
            ]),
            $method === 'GET' && $rest === ['projects'] => Http::response([
                ['id' => '11', 'slug' => 'api', 'name' => 'api', 'platform' => 'php'],
                ['id' => '12', 'slug' => 'web', 'name' => 'Web app', 'platform' => 'javascript'],
            ]),
            $method === 'POST' && $rest === ['releases'] => $this->create($body),
            $method === 'GET' && ($rest[0] ?? null) === 'releases' && count($rest) === 2 => isset($this->releases[$version])
                ? Http::response($this->releases[$version])
                : Http::response(['detail' => 'The requested resource does not exist'], 404),
            $method === 'PUT' && ($rest[0] ?? null) === 'releases' && count($rest) === 2 => $this->update((string) $version, $body),
            $method === 'POST' && ($rest[0] ?? null) === 'releases' && ($rest[2] ?? null) === 'deploys' => $this->deploy((string) $version, $body),
            default => Http::response(['detail' => 'Not found'], 404),
        };
    }

    /** @param  array<string, mixed>  $body */
    private function create(array $body): PromiseInterface
    {
        if ($this->rejectCommits && isset($body['commits'])) {
            return Http::response(['commits' => ['Unable to create commits for this repository.']], 400);
        }
        $version = (string) ($body['version'] ?? '');
        if (isset($this->releases[$version])) {
            $this->releases[$version]['projects'] = array_values(array_unique([...$this->releases[$version]['projects'], ...array_map(fn ($p) => ['id' => $p === 'api' ? '11' : '12', 'slug' => $p, 'newGroups' => 0], (array) $body['projects'])], SORT_REGULAR));

            return Http::response($this->releases[$version], 208);
        }
        $this->releases[$version] = [
            'version' => $version,
            'ref' => $body['ref'] ?? null,
            'commits' => $body['commits'] ?? [],
            'projects' => array_map(fn ($p) => ['id' => $p === 'api' ? '11' : '12', 'slug' => $p, 'newGroups' => 0], (array) ($body['projects'] ?? [])),
            'deploys' => [],
            'dateReleased' => null,
            'newGroups' => 0,
        ];

        return Http::response($this->releases[$version], 201);
    }

    /** @param  array<string, mixed>  $body */
    private function update(string $version, array $body): PromiseInterface
    {
        if (! isset($this->releases[$version])) {
            return Http::response(['detail' => 'The requested resource does not exist'], 404);
        }
        $this->releases[$version]['dateReleased'] = $body['dateReleased'] ?? null;

        return Http::response($this->releases[$version]);
    }

    /** @param  array<string, mixed>  $body */
    private function deploy(string $version, array $body): PromiseInterface
    {
        if (! isset($this->releases[$version])) {
            return Http::response(['detail' => 'The requested resource does not exist'], 404);
        }
        $this->releases[$version]['deploys'][] = $body;

        return Http::response(['id' => (string) count($this->releases[$version]['deploys']), ...$body], 201);
    }
}
