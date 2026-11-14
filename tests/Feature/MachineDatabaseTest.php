<?php

use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Databases\DatabaseConsole;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/** @return array{0: Workspace, 1: Machine, 2: string} */
function databaseMachine(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $container = fn (string $key, string $name, string $image, ?string $backup, bool $managed = true) => [
        'key' => $key, 'name' => $name, 'kind' => 'database', 'manager' => 'compose', 'status' => 'running', 'ports' => [],
        'confidence' => 90, 'origin' => 'created', 'details' => array_filter(['managed' => $managed ? 'true' : null, 'image' => $image, 'backup' => $backup]),
    ];

    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
            $container('docker:aaa111aaa111', 'shop-db-1', 'postgres:17-alpine', 'pg_dumpall'),
            $container('docker:bbb222bbb222', 'blog-db-1', 'mariadb:11', 'mysqldump'),
            // Older daemons marked tools named after a database as one.
            $container('docker:ccc333ccc333', 'shop-rest-1', 'postgrest/postgrest:v12', 'pg_dumpall'),
            $container('docker:ddd444ddd444', 'cache-1', 'redis:7-alpine', null),
            $container('docker:eee555eee555', 'old-db-1', 'postgres:16', 'pg_dumpall', managed: false),
        ]],
    ])->save();

    return [$workspace, $machine, 'https://'.$workspace->host().'/machines/'.$machine->id.'/services/'];
}

/** @return array<string, mixed> */
function lastDatabaseJob(): array
{
    $request = collect(Http::recorded())
        ->map(fn (array $pair) => $pair[0])
        ->filter(fn (GatewayRequest $r) => str_ends_with($r->url(), '/commands'))
        ->last();

    return json_decode($request->body(), true)['command']['job'];
}

function unlockDatabases(User $user, Workspace $workspace): void
{
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);

    test()->actingAs($user)->postJson('https://'.$workspace->host().'/console/unlock', ['code' => '123456'])->assertOk();
}

/**
 * What the job's command prints, as the daemon would log it.
 *
 * @param  list<string>  $output
 */
function answerDatabaseJob(DaemonJob $job, array $output, int $code = 0, string $error = '', string $database = 'shop'): void
{
    preg_match("/^echo '([0-9a-f]{32}) result'$/m", (string) $job->command['job']['command'], $m);
    $text = implode("\n", $output);
    $job->forceFill([
        'state' => 'succeeded',
        'finished_at' => now(),
        'log' => [
            "{$m[1]} database {$database}",
            "{$m[1]} result",
            ...$output,
            '',
            "{$m[1]} error",
            ...($error === '' ? [] : [$error]),
            '',
            "{$m[1]} end {$code} ".strlen($text).' '.count($output),
        ],
    ])->save();
}

test('the Database tab is offered for database containers ServerOS manages, and nothing else', function () {
    [$workspace, , $url] = databaseMachine();
    $tab = fn (string $key) => $this->actingAs($workspace->owner)->get($url.$key);

    $tab('docker:aaa111aaa111')->assertInertia(fn (Assert $page) => $page->where('database', ['engine' => 'postgres'])->etc());
    $tab('docker:bbb222bbb222')->assertInertia(fn (Assert $page) => $page->where('database', ['engine' => 'mysql'])->etc());
    foreach (['docker:ccc333ccc333', 'docker:ddd444ddd444', 'docker:eee555eee555'] as $key) {
        $tab($key)->assertInertia(fn (Assert $page) => $page->where('database', null)->etc());
    }

    $this->actingAs($workspace->owner)->postJson($url.'docker:ddd444ddd444/database', ['action' => 'tables'])
        ->assertStatus(409);
    $this->actingAs($workspace->owner)->postJson($url.'docker:eee555eee555/database', ['action' => 'tables'])
        ->assertStatus(409)->assertJson(['error' => 'Adopt this service first so ServerOS can control it.']);
});

test('opening a database needs machine access, two-factor and an unlock', function () {
    [$workspace, , $url] = databaseMachine();
    $owner = $workspace->owner;
    $ask = fn (User $user) => $this->actingAs($user)->postJson($url.'docker:aaa111aaa111/database', ['action' => 'tables']);

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
    $ask($viewer)->assertForbidden();

    $ask($owner)->assertForbidden()->assertJson(['code' => 'two_factor_required']);
    $owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $ask($owner)->assertStatus(423)->assertJson(['code' => 'console_locked']);

    unlockDatabases($owner, $workspace);
    $ask($owner)->assertAccepted()->assertJsonStructure(['jobId']);
    expect(DaemonJob::query()->where('kind', 'database_query')->count())->toBe(1);
});

test('a query runs read-only in the container unless changes are allowed, and goes in the activity with its SQL', function () {
    [$workspace, $machine, $url] = databaseMachine();
    $owner = $workspace->owner;
    unlockDatabases($owner, $workspace);
    $sql = "SELECT * FROM users WHERE name = 'o''brien'; -- $(rm -rf /)";

    $this->actingAs($owner)->postJson($url.'docker:aaa111aaa111/database', ['action' => 'query', 'database' => 'shop', 'query' => $sql])
        ->assertAccepted();

    $job = lastDatabaseJob();
    expect($job['type'])->toBe('service_exec')
        ->and($job['service'])->toBe('docker:aaa111aaa111')
        ->and($job)->not->toHaveKey('env')
        // The query and the name go in as quoted words, never through the shell.
        ->and($job['command'])->toContain(escapeshellarg($sql))
        ->and($job['command'])->toContain("D='shop'")
        ->and($job['command'])->toContain('default_transaction_read_only=on');

    $record = DaemonJob::query()->sole();
    expect($record->kind)->toBe('database_query')
        ->and($record->service)->toBe('docker:aaa111aaa111');

    $queries = fn () => Activity::query()->where('action', 'console.command');
    $activity = $queries()->sole();
    expect($activity->action)->toBe('console.command')
        ->and($activity->meta)->toBe(['command' => $sql, 'database' => 'shop', 'write' => false])
        ->and(ActivityLine::sentence($activity))->toContain('ran a read-only query in shop-db-1');

    $this->actingAs($owner)->postJson($url.'docker:bbb222bbb222/database', ['action' => 'query', 'query' => 'DELETE FROM posts', 'write' => true])
        ->assertAccepted();
    expect(lastDatabaseJob()['command'])->not->toContain('READ ONLY')
        ->and(ActivityLine::sentence($queries()->latest('id')->first()))->toContain('ran a query that can change data in blog-db-1');

    // Looking at the list of tables isn't worth an entry.
    $this->actingAs($owner)->postJson($url.'docker:aaa111aaa111/database', ['action' => 'tables'])->assertAccepted();
    expect($queries()->count())->toBe(2);

    // Nor do database jobs crowd the machine's own job list.
    $this->actingAs($owner)->get('https://'.$workspace->host().'/machines/'.$machine->id)
        ->assertInertia(fn (Assert $page) => $page->has('jobs', 0)->etc());
});

test('the rows go to the person who ran the query, once', function () {
    [$workspace, $machine, $url] = databaseMachine();
    $owner = $workspace->owner;
    unlockDatabases($owner, $workspace);

    $id = $this->actingAs($owner)->postJson($url.'docker:aaa111aaa111/database', ['action' => 'query', 'query' => 'SELECT * FROM users'])
        ->json('jobId');
    $poll = fn (User $user) => $this->actingAs($user)->getJson($url."docker:aaa111aaa111/database/{$id}");

    $poll($owner)->assertOk()->assertJson(['state' => 'queued', 'result' => null]);

    answerDatabaseJob(DaemonJob::query()->findOrFail($id), ['id,name,bio', '1,"Lee, Ann","said ""hi""', 'twice"', '2,Bo,NULL']);

    $other = User::factory()->create();
    $workspace->members()->attach($other, ['role' => WorkspaceRole::Admin->value]);
    $poll($other)->assertNotFound();

    $poll($owner)->assertOk()->assertJson(['state' => 'succeeded', 'error' => null, 'result' => [
        'database' => 'shop',
        'columns' => ['id', 'name', 'bio'],
        'rows' => [['1', 'Lee, Ann', "said \"hi\"\ntwice"], ['2', 'Bo', null]],
        'truncated' => false,
        'changed' => null,
        'error' => null,
    ]]);

    expect(DaemonJob::query()->findOrFail($id)->log)->toBe([]);
    $poll($owner)->assertJson(['result' => null, 'error' => ['message' => 'The database’s answer couldn’t be read.']]);
});

test('the result says how many rows changed, what went wrong, and when it was cut short', function () {
    [$workspace, , $url] = databaseMachine();
    $owner = $workspace->owner;
    unlockDatabases($owner, $workspace);
    $run = function (string $service, array $output, int $code = 0, string $error = '') use ($url, $owner) {
        $id = $this->actingAs($owner)->postJson($url."{$service}/database", ['action' => 'query', 'query' => 'UPDATE t SET a = 1', 'write' => true])->json('jobId');
        $job = DaemonJob::query()->findOrFail($id);
        preg_match("/^echo '([0-9a-f]{32}) result'$/m", (string) $job->command['job']['command'], $m);
        answerDatabaseJob($job, array_map(fn (string $line) => str_replace('NONCE', $m[1], $line), $output), $code, $error);

        return $this->actingAs($owner)->getJson($url."{$service}/database/{$id}")->json('result');
    };

    // Postgres: psql echoes the count after the query.
    expect($run('docker:aaa111aaa111', ['NONCE 3']))->toMatchArray(['columns' => [], 'rows' => [], 'changed' => 3]);

    // MySQL: tab-separated with escapes, then the count as a result of its own.
    expect($run('docker:bbb222bbb222', ["id\tnote", "1\ta\\tb\\nc\\\\d", "2\tNULL", 'NONCE', 'NONCE -1']))
        ->toMatchArray(['columns' => ['id', 'note'], 'rows' => [['1', "a\tb\nc\\d"], ['2', null]], 'changed' => null]);
    expect($run('docker:bbb222bbb222', ['NONCE', 'NONCE 0']))->toMatchArray(['columns' => [], 'changed' => 0]);

    expect($run('docker:aaa111aaa111', [], 1, 'ERROR:  cannot execute UPDATE in a read-only transaction'))
        ->toMatchArray(['error' => 'ERROR:  cannot execute UPDATE in a read-only transaction', 'changed' => null]);

    $many = ['n', ...array_map('strval', range(1, 399))];
    $id = $this->actingAs($owner)->postJson($url.'docker:aaa111aaa111/database', ['action' => 'query', 'query' => 'SELECT n FROM big'])->json('jobId');
    answerDatabaseJob(DaemonJob::query()->findOrFail($id), $many);
    $job = DaemonJob::query()->findOrFail($id);
    $log = $job->log;
    $log[count($log) - 1] = preg_replace('/ \d+ \d+$/', ' 900000 5000', $log[count($log) - 1]);
    $job->forceFill(['log' => $log])->save();

    $result = $this->actingAs($owner)->getJson($url."docker:aaa111aaa111/database/{$id}")->json('result');
    expect($result['truncated'])->toBeTrue()
        ->and($result['rows'])->toHaveCount(DatabaseConsole::MAX_ROWS);
});

test('a database name or query the panel won’t send is turned away', function () {
    [$workspace, , $url] = databaseMachine();
    $owner = $workspace->owner;
    unlockDatabases($owner, $workspace);
    $ask = fn (array $data) => $this->actingAs($owner)->postJson($url.'docker:aaa111aaa111/database', $data);

    $ask(['action' => 'drop'])->assertJsonValidationErrors('action');
    $ask(['action' => 'query'])->assertJsonValidationErrors('query');
    $ask(['action' => 'tables', 'database' => "shop\nrm -rf /"])->assertJsonValidationErrors('database');
    $ask(['action' => 'query', 'query' => str_repeat('x', DatabaseConsole::MAX_QUERY + 1)])->assertJsonValidationErrors('query');

    expect(DaemonJob::query()->count())->toBe(0);
});
