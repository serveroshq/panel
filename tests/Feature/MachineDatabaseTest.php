<?php

use App\Enums\WorkspaceRole;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Databases\DatabaseConsole;
use App\Support\Fixes\FixEvidence;
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

    $host = fn (string $key, string $name, ?string $backup, bool $managed = true) => [
        'key' => $key, 'name' => $name, 'kind' => 'database', 'manager' => 'systemd', 'status' => 'running', 'ports' => [],
        'confidence' => 95, 'origin' => 'discovered', 'details' => array_filter(['managed' => $managed ? 'true' : null, 'backup' => $backup]),
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
            // Databases the machine runs itself, from the distribution.
            $host('systemd:postgresql@16-main.service', 'postgresql', 'pg_dumpall'),
            $host('systemd:mariadb.service', 'mariadb', 'mysqldump'),
            $host('systemd:redis-server.service', 'redis', 'redis rdb'),
            $host('systemd:postgresql@15-main.service', 'postgresql', 'pg_dumpall', managed: false),
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
 * What the job's command prints, base64 like the command does, sealed as the
 * panel keeps it. $output is what the client wrote, line by line (or exactly,
 * as a string); $counts stands in for the whole output's size when it was cut.
 *
 * @param  list<string>|string  $output
 * @param  array{0: int, 1: int}|null  $counts
 */
function answerDatabaseJob(DaemonJob $job, array|string $output, int $code = 0, string $error = '', string $database = 'shop', ?array $counts = null): void
{
    $sent = $job->command['job'];
    $m = [1 => $sent['nonce'] ?? null];
    if (($sent['type'] ?? null) !== 'database_query') {
        preg_match("/^echo '([0-9a-f]{32}) result'$/m", (string) $sent['command'], $m);
    }
    $text = is_string($output) ? $output : ($output === [] ? '' : implode("\n", $output)."\n");
    $encode = fn (string $bytes) => $bytes === '' ? [''] : [...str_split(rtrim(base64_encode($bytes), '='), 3000), ''];
    [$bytes, $lines] = $counts ?? [strlen($text), substr_count($text, "\n")];
    $job->forceFill([
        'state' => 'succeeded',
        'finished_at' => now(),
        'log' => [DatabaseConsole::seal([
            "{$m[1]} database {$database}",
            "{$m[1]} result",
            ...$encode($text),
            "{$m[1]} error",
            ...$encode($error),
            "{$m[1]} end {$code} {$bytes} {$lines}",
        ])],
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
        ->and($job['command'])->toContain('default_transaction_read_only=on')
        // Stopped by the database, then the client, before the daemon gives up.
        ->and($job['command'])->toContain('statement_timeout=50s')
        ->and($job['command'])->toContain('timeout 55')
        // What it prints comes back as base64, safe to read line by line.
        ->and($job['command'])->toContain("| base64 | tr -d '=\\n' | fold -w 3000");

    $record = DaemonJob::query()->sole();
    expect($record->kind)->toBe('database_query')
        ->and($record->service)->toBe('docker:aaa111aaa111');

    $queries = fn () => Activity::query()->where('action', 'console.command');
    $activity = $queries()->sole();
    expect($activity->action)->toBe('console.command')
        ->and($activity->meta)->toBe(['command' => $sql, 'database' => 'shop', 'write' => false])
        ->and(ActivityLine::sentence($activity))->toContain('ran a query in shop-db-1 (read-only mode)');

    $this->actingAs($owner)->postJson($url.'docker:bbb222bbb222/database', ['action' => 'query', 'query' => 'DELETE FROM posts', 'write' => true])
        ->assertAccepted();
    expect(lastDatabaseJob()['command'])->not->toContain('READ ONLY')
        // The client's own commands (system, \!) are off, and the server stops a slow statement.
        ->and(lastDatabaseJob()['command'])->toContain('--binary-mode')
        ->and(lastDatabaseJob()['command'])->toContain('max_statement_time=50')
        ->and(lastDatabaseJob()['command'])->toContain('max_execution_time=50000')
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
    answerDatabaseJob(DaemonJob::query()->findOrFail($id), $many, counts: [900000, 5000]);

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
    // psql runs a query that starts with \ as its own command, and \! is a shell.
    $ask(['action' => 'query', 'query' => '  \\! id'])->assertJsonValidationErrors(['query' => 'Backslash commands']);
    // A connection string would send the container's password elsewhere.
    $ask(['action' => 'tables', 'database' => 'host=elsewhere user=x'])->assertJsonValidationErrors('database');
    $ask(['action' => 'tables', 'database' => 'postgresql://elsewhere/db'])->assertJsonValidationErrors('database');

    expect(DaemonJob::query()->count())->toBe(0);
});

test('results that aren’t plain text still read: cut mid-character, other alphabets, binary columns and token-like values', function () {
    [$workspace, , $url] = databaseMachine();
    $owner = $workspace->owner;
    unlockDatabases($owner, $workspace);
    $run = function (string $service, string|array $output, ?array $counts = null) use ($url, $owner) {
        $id = $this->actingAs($owner)->postJson($url."{$service}/database", ['action' => 'query', 'query' => 'SELECT * FROM t'])->json('jobId');
        answerDatabaseJob(DaemonJob::query()->findOrFail($id), $output, counts: $counts);

        return $this->actingAs($owner)->getJson($url."{$service}/database/{$id}")->assertOk()->json('result');
    };

    // Cut off inside a three-byte character: the partial last line is dropped, not the reply.
    $cut = substr("name\nZoë\n日本語\n東京", 0, -2);
    expect($run('docker:aaa111aaa111', $cut, [900000, 3]))
        ->toMatchArray(['columns' => ['name'], 'rows' => [['Zoë']], 'truncated' => true]);
    // MySQL keeps one row per line, so every whole line before the cut stays.
    expect($run('docker:bbb222bbb222', $cut, [900000, 3]))
        ->toMatchArray(['columns' => ['name'], 'rows' => [['Zoë'], ['日本語']], 'truncated' => true]);

    // MySQL --batch prints a BINARY(4) id as its raw bytes (with \0 escaped).
    expect($run('docker:bbb222bbb222', "id\tname\n\\0\x01\xff\xfe\tAnn\nAB\\0\\0\tBo\n"))
        ->toMatchArray(['columns' => ['id', 'name'], 'rows' => [['0x0001FFFE', 'Ann'], ['0x41420000', 'Bo']]]);

    // The daemon scrubs `token=…` from what it reads; the rows are base64 by then, so nothing is lost.
    expect($run('docker:aaa111aaa111', "a,b,c\n1,token=abc,foo\n"))
        ->toMatchArray(['columns' => ['a', 'b', 'c'], 'rows' => [['1', 'token=abc', 'foo']]]);
});

test('an answer that was changed on the way, or a query that ran too long, says so', function () {
    [$workspace, , $url] = databaseMachine();
    $owner = $workspace->owner;
    unlockDatabases($owner, $workspace);
    $ask = fn () => $this->actingAs($owner)->postJson($url.'docker:aaa111aaa111/database', ['action' => 'query', 'query' => 'SELECT 1'])->json('jobId');

    $id = $ask();
    answerDatabaseJob(DaemonJob::query()->findOrFail($id), [], 124);
    expect($this->actingAs($owner)->getJson($url."docker:aaa111aaa111/database/{$id}")->json('result.error'))
        ->toBe('The query ran for over 50 seconds, so it was stopped.');

    $id = $ask();
    $job = DaemonJob::query()->findOrFail($id);
    preg_match("/^echo '([0-9a-f]{32}) result'$/m", (string) $job->command['job']['command'], $m);
    $job->forceFill(['state' => 'succeeded', 'finished_at' => now(), 'log' => [
        "{$m[1]} database shop", "{$m[1]} result", 'YSxi[redacted]', "{$m[1]} error", '', "{$m[1]} end 0 9 2",
    ]])->save();
    $this->actingAs($owner)->getJson($url."docker:aaa111aaa111/database/{$id}")
        ->assertJson(['result' => null, 'error' => ['message' => 'The database’s answer couldn’t be read.']]);
});

test('what the machine sends back is encrypted as it arrives, and kept out of Fixes', function () {
    [$workspace, $machine, $url] = databaseMachine();
    $owner = $workspace->owner;
    unlockDatabases($owner, $workspace);
    $id = $this->actingAs($owner)->postJson($url.'docker:aaa111aaa111/database', ['action' => 'query', 'query' => 'SELECT email FROM users'])->json('jobId');

    $this->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', ['envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => [
        'job_id' => $id, 'state' => 'failed', 'log' => ['$ psql -c \'SELECT email FROM users\'', 'ann@example.com'],
        'error' => ['message' => 'the command was still running after 60s'],
    ]]]], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();

    $log = DaemonJob::query()->findOrFail($id)->log;
    expect($log)->toHaveCount(1)
        ->and($log[0])->toStartWith('sealed:')
        ->and(implode("\n", $log))->not->toContain('ann@example.com')
        ->and(implode("\n", $log))->not->toContain('SELECT email');

    expect(FixEvidence::gather($machine, null)['jobs'])->toBe([]);
});

test('a database the machine runs itself gets the Database tab, and its query goes to the daemon on its own', function () {
    [$workspace, $machine, $url] = databaseMachine();
    $machine->forceFill(['daemon_version' => 'v0.1.1-pre.39'])->save();
    $owner = $workspace->owner;
    $tab = fn (string $key) => $this->actingAs($owner)->get($url.$key);

    $tab('systemd:postgresql@16-main.service')->assertInertia(fn (Assert $page) => $page->where('database', ['engine' => 'postgres'])->etc());
    $tab('systemd:mariadb.service')->assertInertia(fn (Assert $page) => $page->where('database', ['engine' => 'mysql'])->etc());
    foreach (['systemd:redis-server.service', 'systemd:postgresql@15-main.service'] as $key) {
        $tab($key)->assertInertia(fn (Assert $page) => $page->where('database', null)->etc());
    }

    unlockDatabases($owner, $workspace);
    $sql = "SELECT * FROM users WHERE name = 'o''brien'; -- $(rm -rf /)";
    $this->actingAs($owner)->postJson($url.'systemd:postgresql@16-main.service/database', ['action' => 'query', 'database' => 'shop', 'query' => $sql])
        ->assertAccepted();

    // Only the query goes over: no command for the daemon to run on the machine.
    $job = lastDatabaseJob();
    expect($job)->toMatchArray([
        'type' => 'database_query',
        'service' => 'systemd:postgresql@16-main.service',
        'engine' => 'postgres',
        'database' => 'shop',
        'sql' => $sql,
        'read_only' => true,
        'count' => true,
    ])->and($job)->not->toHaveKey('command')
        ->and($job['nonce'])->toMatch('/^[0-9a-f]{32}$/');

    expect(DaemonJob::query()->sole()->kind)->toBe('database_query')
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'console.command')->sole()))->toContain('ran a query in postgresql (read-only mode)');

    $this->actingAs($owner)->postJson($url.'systemd:mariadb.service/database', ['action' => 'query', 'query' => 'DELETE FROM posts', 'write' => true])
        ->assertAccepted();
    expect(lastDatabaseJob())->toMatchArray(['engine' => 'mysql', 'database' => null, 'read_only' => false, 'count' => true]);

    $this->actingAs($owner)->postJson($url.'systemd:postgresql@16-main.service/database', ['action' => 'databases'])->assertAccepted();
    expect(lastDatabaseJob())->toMatchArray(['sql' => DatabaseConsole::sql('postgres', 'databases', null), 'count' => false]);

    $this->actingAs($owner)->postJson($url.'systemd:postgresql@16-main.service/database', ['action' => 'query', 'query' => '\! id'])
        ->assertUnprocessable();
});

test('a daemon too old for databases on the machine itself is asked to update, while containers still open', function () {
    [$workspace, $machine, $url] = databaseMachine();
    $machine->forceFill(['daemon_version' => 'v0.1.1-pre.38'])->save();
    $owner = $workspace->owner;
    unlockDatabases($owner, $workspace);

    $this->actingAs($owner)->postJson($url.'systemd:postgresql@16-main.service/database', ['action' => 'tables'])
        ->assertStatus(409)
        ->assertJsonPath('error', "Update the ServerOS daemon on {$machine->name} to open databases installed on the machine itself. Containers work on any version.");
    $this->actingAs($owner)->postJson($url.'docker:aaa111aaa111/database', ['action' => 'tables'])->assertAccepted();
});

test('the answer from a database on the machine itself reads like a container’s', function () {
    [$workspace, $machine, $url] = databaseMachine();
    $machine->forceFill(['daemon_version' => 'v0.1.1-pre.39'])->save();
    $owner = $workspace->owner;
    unlockDatabases($owner, $workspace);
    $key = 'systemd:postgresql@16-main.service';

    $id = $this->actingAs($owner)->postJson($url."{$key}/database", ['action' => 'query', 'query' => 'SELECT datname FROM pg_database'])->json('jobId');
    $job = DaemonJob::query()->findOrFail($id);
    $nonce = $job->command['job']['nonce'];
    answerDatabaseJob($job, ['name,size', 'postgres,7503 kB', 'serveros,31 GB', "{$nonce} 2"], database: 'postgres');

    $this->actingAs($owner)->getJson($url."{$key}/database/{$id}")->assertOk()->assertJson(['state' => 'succeeded', 'error' => null, 'result' => [
        'database' => 'postgres',
        'columns' => ['name', 'size'],
        'rows' => [['postgres', '7503 kB'], ['serveros', '31 GB']],
        'truncated' => false,
        'error' => null,
    ]]);

    // A job whose nonce isn't hex never reads as a result.
    $bad = DaemonJob::query()->findOrFail($this->actingAs($owner)->postJson($url."{$key}/database", ['action' => 'tables'])->json('jobId'));
    $bad->forceFill(['command' => [...$bad->command, 'job' => [...$bad->command['job'], 'nonce' => 'not-hex']]])->save();
    expect(DatabaseConsole::result($bad))->toBeNull();
});
