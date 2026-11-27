<?php

namespace Tests\Support;

use GuzzleHttp\Promise\PromiseInterface;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * A stand-in for Linear's GraphQL API: a workspace with two teams, issues
 * with their comments and state, every operation recorded, and failures on
 * demand.
 */
final class FakeLinear
{
    public const string KEY = 'lin_api_test0123456789abcdefghijklmnopqrstu';

    /** @var array<string, array<string, mixed>> */
    public array $issues = [];

    /** @var list<array{op: string, variables: array<string, mixed>}> */
    public array $calls = [];

    /** @var array<string, list<array{status: int, body: array<string, mixed>, create?: bool}>> */
    private array $failures = [];

    private int $number = 0;

    public static function install(bool $strict = true): self
    {
        $fake = new self;
        if ($strict) {
            Http::preventStrayRequests();
        }
        Http::fake(['api.linear.app/*' => fn (Request $request) => $fake->handle($request)]);

        return $fake;
    }

    /**
     * Fails the next call of an operation (viewer, teams, team, issueCreate, commentCreate, issueUpdate, issue).
     * With $afterDoing it still does the work first, like a reply lost on the way back.
     *
     * @param  array<string, mixed>  $body
     */
    public function failNext(string $op, int $status, array $body = [], bool $afterDoing = false): self
    {
        $this->failures[$op][] = ['status' => $status, 'body' => $body, 'create' => $afterDoing];

        return $this;
    }

    /** @return list<string> */
    public function ops(): array
    {
        return array_map(fn (array $c) => $c['op'], $this->calls);
    }

    public function handle(Request $request): PromiseInterface
    {
        $data = (array) $request->data();
        $query = (string) ($data['query'] ?? '');
        $variables = (array) ($data['variables'] ?? []);
        $op = match (true) {
            str_contains($query, 'issueCreate') => 'issueCreate',
            str_contains($query, 'commentCreate') => 'commentCreate',
            str_contains($query, 'issueUpdate') => 'issueUpdate',
            str_contains($query, 'issue(id') => 'issue',
            str_contains($query, 'viewer') => 'viewer',
            str_contains($query, 'team(id') => 'team',
            str_contains($query, 'teams(') => 'teams',
            default => 'unknown',
        };
        $this->calls[] = ['op' => $op, 'variables' => $variables];

        if (($request->header('Authorization')[0] ?? null) !== self::KEY) {
            return Http::response(['errors' => [['message' => 'Authentication required, not authenticated', 'extensions' => ['code' => 'AUTHENTICATION_ERROR', 'type' => 'authentication error', 'userPresentableMessage' => 'You need to authenticate to access this operation.']]]], 400);
        }
        if (($this->failures[$op] ?? []) !== [] && ($failure = array_shift($this->failures[$op])) !== null) {
            if ($failure['create'] ?? false) {
                $this->answer($op, $variables);
            }

            return Http::response($failure['body'] === [] ? ['errors' => [['message' => 'Internal server error', 'extensions' => ['code' => 'INTERNAL_SERVER_ERROR']]]] : $failure['body'], $failure['status']);
        }

        return Http::response(['data' => $this->answer($op, $variables)]);
    }

    /**
     * @param  array<string, mixed>  $v
     * @return array<string, mixed>
     */
    private function answer(string $op, array $v): array
    {
        return match ($op) {
            'viewer' => [
                'viewer' => ['id' => 'user-1', 'name' => 'Dana Lee'],
                'organization' => ['id' => 'org-1', 'name' => 'Acme', 'urlKey' => 'acme'],
                'teams' => ['nodes' => [['id' => 'team-1'], ['id' => 'team-2']]],
            ],
            'teams' => ['teams' => ['nodes' => [
                ['id' => 'team-1', 'key' => 'ENG', 'name' => 'Engineering'],
                ['id' => 'team-2', 'key' => 'OPS', 'name' => 'Ops'],
            ]]],
            'team' => [
                'team' => ($v['id'] ?? null) === 'team-1' ? [
                    'id' => 'team-1', 'key' => 'ENG', 'name' => 'Engineering',
                    'states' => ['nodes' => [
                        ['id' => 'state-todo', 'name' => 'Todo', 'type' => 'unstarted', 'position' => 1],
                        ['id' => 'state-shipped', 'name' => 'Shipped', 'type' => 'completed', 'position' => 4],
                        ['id' => 'state-done', 'name' => 'Done', 'type' => 'completed', 'position' => 3],
                        ['id' => 'state-canceled', 'name' => 'Canceled', 'type' => 'canceled', 'position' => 5],
                    ]],
                    'projects' => ['nodes' => [
                        ['id' => 'proj-1', 'name' => 'Reliability', 'state' => 'started'],
                        ['id' => 'proj-old', 'name' => 'Old launch', 'state' => 'completed'],
                    ]],
                ] : null,
                'issueLabels' => ['nodes' => [
                    ['id' => 'label-1', 'name' => 'Incident', 'team' => null],
                    ['id' => 'label-2', 'name' => 'Bug', 'team' => ['id' => 'team-1']],
                    ['id' => 'label-3', 'name' => 'Ops only', 'team' => ['id' => 'team-2']],
                ]],
            ],
            'issueCreate' => $this->create((array) ($v['input'] ?? [])),
            'issue' => ['issue' => $this->issues[(string) ($v['id'] ?? '')] ?? null],
            'commentCreate' => $this->comment((array) ($v['input'] ?? [])),
            'issueUpdate' => $this->update((string) ($v['id'] ?? ''), (array) ($v['input'] ?? [])),
            default => [],
        };
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function create(array $input): array
    {
        $id = (string) ($input['id'] ?? Str::uuid());
        if (isset($this->issues[$id])) {
            return ['issueCreate' => ['success' => false, 'issue' => null]];
        }
        $this->number++;
        $this->issues[$id] = [
            'id' => $id,
            'identifier' => 'ENG-'.(100 + $this->number),
            'url' => 'https://linear.app/acme/issue/ENG-'.(100 + $this->number),
            'input' => $input,
            'stateId' => 'state-todo',
            'comments' => [],
        ];

        return ['issueCreate' => ['success' => true, 'issue' => array_intersect_key($this->issues[$id], array_flip(['id', 'identifier', 'url']))]];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function comment(array $input): array
    {
        $id = (string) ($input['issueId'] ?? '');
        if (isset($this->issues[$id])) {
            $this->issues[$id]['comments'][] = (string) ($input['body'] ?? '');
        }

        return ['commentCreate' => ['success' => isset($this->issues[$id])]];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function update(string $id, array $input): array
    {
        if (isset($this->issues[$id], $input['stateId'])) {
            $this->issues[$id]['stateId'] = $input['stateId'];
        }

        return ['issueUpdate' => ['success' => isset($this->issues[$id])]];
    }
}
