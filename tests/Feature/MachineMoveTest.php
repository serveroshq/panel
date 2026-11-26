<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\BackupDestination;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineMove;
use App\Models\MachineMoveApp;
use App\Models\MachineMoveCopy;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Backups\ReceiverKey;
use App\Support\Machines\MachineMoves;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

/** @return array{0: Workspace, 1: Machine, 2: Machine, 3: string} */
function moveSetup(): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $from = Machine::factory()->for($workspace)->create(['name' => 'old-box']);
    $from->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'daemon_version' => 'v0.1.1-pre.51',
        'facts' => ['arch' => 'x86_64', 'docker_version' => '27.0.1', 'memory_bytes' => 8 << 30],
        'memory_used_mb' => 2048,
        'disks' => [['mount' => '/', 'used' => 30 << 30, 'total' => 80 << 30]],
        'inventory' => ['services' => [
            ['key' => 'docker:web', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'kind' => 'app', 'status' => 'running', 'managed' => true, 'origin' => 'created', 'details' => ['managed' => 'true', 'backup' => 'tar']],
            ['key' => 'docker:pg', 'name' => 'serveros-shopdb-postgres-1', 'kind' => 'database', 'status' => 'running', 'managed' => true, 'origin' => 'created', 'details' => ['managed' => 'true', 'compose_project' => 'serveros-shopdb', 'backup' => 'pg_dumpall']],
            ['key' => 'docker:legacy', 'name' => 'legacy-api', 'kind' => 'app', 'managed' => true, 'origin' => 'adopted', 'details' => ['managed' => 'true']],
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web_server', 'details' => []],
        ], 'scheduled' => [
            ['command' => 'curl -H "Authorization: Bearer s3cret-token" https://example.com/ping', 'schedule' => '0 3 * * *', 'source' => '/etc/crontab', 'user' => 'root'],
            ['command' => '/usr/bin/serverosd backfill', 'schedule' => '*/5 * * * *', 'source' => '/etc/cron.d/serveros', 'user' => 'root'],
        ]],
    ])->save();

    $to = Machine::factory()->for($workspace)->create(['name' => 'new-box']);
    $to->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'ip_address' => '203.0.113.20',
        'daemon_version' => 'v0.1.1-pre.51',
        'facts' => ['arch' => 'x86_64', 'docker_version' => '27.0.1', 'memory_bytes' => 16 << 30],
        'disks' => [['mount' => '/', 'used' => 5 << 30, 'total' => 160 << 30]],
        'inventory' => ['services' => [], 'listeners' => [['port' => 22, 'address' => '0.0.0.0', 'proto' => 'tcp']]],
    ])->save();

    moveDeploy($from, 'shop', [
        'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40), 'dockerfile' => 'Dockerfile',
        'env' => ['DB_PASSWORD' => 'hunter2-secret', 'APP_URL' => 'https://shop.example.com'],
        'files' => ['.env.production' => 'API_KEY=file-secret'],
        'domains' => ['shop.example.com'], 'port' => 3000,
        'health' => ['path' => '/up', 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => 5],
    ]);
    moveDeploy($from, 'shopdb', [
        'repo' => '', 'commit' => 'stack', 'compose_file' => 'compose.yaml',
        'env' => ['POSTGRES_PASSWORD' => 'pg-secret'], 'port' => 5433,
    ]);

    return [$workspace, $from, $to, 'https://'.$workspace->host().'/machines/'.$from->id];
}

/** @param  array<string, mixed>  $spec */
function moveDeploy(Machine $machine, string $app, array $spec): DaemonJob
{
    return DaemonJob::query()->create([
        'id' => (string) Str::uuid(),
        'machine_id' => $machine->id,
        'user_id' => $machine->workspace->owner_id,
        'kind' => 'deploy',
        'service' => $app,
        'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => $app, ...$spec]],
    ]);
}

test('the machine file describes what ServerOS deployed without any secret values', function () {
    [$workspace, $from, , $url] = moveSetup();

    $response = $this->actingAs($workspace->owner)->get($url.'/machine-file')
        ->assertOk()
        ->assertHeader('Content-Disposition', 'attachment; filename="old-box.serveros.json"');

    $file = $response->json();
    expect($file)->toMatchArray(['kind' => 'serveros-machine', 'version' => 1])
        ->and($file['machine'])->toMatchArray(['name' => 'old-box', 'arch' => 'x86_64', 'daemon_version' => 'v0.1.1-pre.51'])
        ->and(array_column($file['apps'], 'name'))->toBe(['shop', 'shopdb'])
        ->and($file['apps'][0])->toMatchArray([
            'source' => 'git', 'repo' => 'https://github.com/acme/shop', 'env' => ['APP_URL', 'DB_PASSWORD'],
            'files' => ['.env.production'], 'domains' => ['shop.example.com'], 'data' => ['tar'],
            'health' => ['path' => '/up', 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => 5],
        ])
        ->and($file['apps'][0])->not->toHaveKey('services')
        ->and($file['apps'][1])->toMatchArray(['source' => 'stack', 'repo' => null, 'commit' => null, 'port' => 5433, 'data' => ['pg_dumpall']])
        ->and(array_column($file['not_moved'], 'key'))->toBe(['docker:legacy', 'systemd:nginx.service'])
        ->and($file['not_moved'][0]['reason'])->toContain('adopted')
        ->and($file['scheduled'])->toBe([['kind' => 'cron', 'schedule' => '0 3 * * *', 'user' => 'root', 'service' => null]]);

    $raw = $response->getContent();
    foreach (['hunter2-secret', 'file-secret', 'pg-secret', 's3cret-token'] as $secret) {
        expect($raw)->not->toContain($secret);
    }

    $activity = Activity::query()->where('action', 'machine.file')->sole();
    expect($activity->machine_id)->toBe($from->id)
        ->and(ActivityLine::describe($activity, false)['verb'])->toBe('downloaded the machine file of');
});

test('an uploaded app is described without the upload’s secret address', function () {
    [$workspace, $from, , $url] = moveSetup();
    moveDeploy($from, 'shop', ['repo' => 'https://panel.test/api/deploy-sources/'.str_repeat('t', 40).'.git', 'commit' => str_repeat('b', 40)])
        ->forceFill(['created_at' => now()->addMinute()])->save();

    $raw = $this->actingAs($workspace->owner)->get($url.'/machine-file')->assertOk()->getContent();

    expect(json_decode($raw, true)['apps'][0])->toMatchArray(['source' => 'upload', 'repo' => null])
        ->and($raw)->not->toContain(str_repeat('t', 40));
});

test('only people who manage machines can download the file or check a move', function () {
    [$workspace, , $to, $url] = moveSetup();
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);

    $this->actingAs($viewer)->get($url.'/machine-file')->assertForbidden();
    $this->actingAs($viewer)->postJson($url.'/move-check', ['to' => $to->id])->assertForbidden();
    expect(Activity::query()->where('action', 'machine.file')->exists())->toBeFalse();
});

test('a move is checked against a machine in the same workspace only', function () {
    [$workspace, $from, , $url] = moveSetup();
    $elsewhere = Machine::factory()->create();

    $this->actingAs($workspace->owner)->postJson($url.'/move-check', ['to' => $from->id])->assertJsonValidationErrors('to');
    $this->actingAs($workspace->owner)->postJson($url.'/move-check', ['to' => $elsewhere->id])->assertJsonValidationErrors('to');
});

test('a move onto a ready machine has no blockers, and says what won’t move', function () {
    [$workspace, , $to, $url] = moveSetup();

    $check = $this->actingAs($workspace->owner)->postJson($url.'/move-check', ['to' => $to->id])->assertOk()->json();

    expect($check['ready'])->toBeTrue()
        ->and($check['blocking'])->toBe([])
        ->and(array_column($check['apps'], 'name'))->toBe(['shop', 'shopdb'])
        ->and(implode("\n", $check['warnings']))
        ->toContain('shop keeps files outside its container')
        ->toContain('Point shop.example.com at new-box only when you switch over')
        ->toContain('2 services on old-box weren’t deployed by ServerOS')
        ->toContain('1 scheduled task on old-box won’t move');

    expect(DaemonJob::query()->where('kind', '!=', 'deploy')->exists())->toBeFalse();
    Http::assertNothingSent();
});

test('name and port clashes, a missing Docker and an offline machine block a move', function () {
    [$workspace, , $to, $url] = moveSetup();
    $to->forceFill([
        'last_seen_at' => now()->subMinutes(10),
        'facts' => ['arch' => 'aarch64'],
        'inventory' => ['services' => [
            ['key' => 'systemd:shop.service', 'name' => 'serveros-shop-cccccccccccc', 'kind' => 'app', 'details' => []],
        ], 'listeners' => [['port' => 5433, 'address' => '127.0.0.1', 'proto' => 'tcp']]],
    ])->save();

    $check = $this->actingAs($workspace->owner)->postJson($url.'/move-check', ['to' => $to->id])->assertOk()->json();

    expect($check['ready'])->toBeFalse()
        ->and(implode("\n", $check['blocking']))
        ->toContain('new-box is offline')
        ->toContain('Docker isn’t installed on new-box')
        ->toContain('Something called shop is already on new-box')
        ->toContain('Port 5433, which shopdb uses, is already taken on new-box')
        ->and(implode("\n", $check['warnings']))->toContain('old-box is x86_64 and new-box is aarch64');
});

test('a move warns about a smaller or older destination and blocks cleared uploads', function () {
    [$workspace, $from, $to, $url] = moveSetup();
    moveDeploy($from, 'shop', ['repo' => 'https://panel.test/api/deploy-sources/'.str_repeat('c', 40).'.git', 'commit' => str_repeat('b', 40)])
        ->forceFill(['created_at' => now()->addMinute()])->save();
    $to->forceFill([
        'daemon_version' => 'v0.1.1-pre.40',
        'facts' => ['arch' => 'x86_64', 'docker_version' => '27.0.1', 'memory_bytes' => 1 << 30],
        'disks' => [['mount' => '/', 'used' => 15 << 30, 'total' => 20 << 30]],
    ])->save();

    $check = $this->actingAs($workspace->owner)->postJson($url.'/move-check', ['to' => $to->id])->assertOk()->json();

    expect(implode("\n", $check['blocking']))->toContain('The code uploaded for shop has been cleared')
        ->and(implode("\n", $check['warnings']))
        ->toContain('new-box runs an older daemon (v0.1.1-pre.40)')
        ->toContain('new-box has less memory than old-box uses now')
        ->toContain('new-box has less free disk than old-box uses now')
        ->and(implode("\n", $check['blocking']))->toContain('new-box’s daemon is too old to receive apps’ data');
});

test('the settings page offers the workspace’s other machines to move to', function () {
    [$workspace, , $to, $url] = moveSetup();
    Machine::factory()->create(['name' => 'not-ours']);

    $this->actingAs($workspace->owner)->get($url.'/settings')
        ->assertInertia(fn (Assert $page) => $page->where('moveTargets', [['id' => $to->id, 'name' => 'new-box']]));
});

test('nothing to move is a blocker', function () {
    [$workspace, $from, $to] = moveSetup();
    $to->forceFill(['inventory' => ['services' => []]])->save();

    $check = $this->actingAs($workspace->owner)
        ->postJson('https://'.$workspace->host().'/machines/'.$to->id.'/move-check', ['to' => $from->id])
        ->assertOk()->json();

    expect($check['ready'])->toBeFalse()
        ->and($check['blocking'])->toContain('Nothing on new-box was deployed by ServerOS, so there’s nothing to move.');
});

const MOVE_PUBLIC_KEY = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAImovekeymovekeymovekeymovekeymovekeymove serveros-backups';

function fakeMoveKeys(): void
{
    app()->instance(ReceiverKey::class, new class extends ReceiverKey
    {
        public function generate(): array
        {
            return ['private' => "-----BEGIN OPENSSH PRIVATE KEY-----\nmove-private\n-----END OPENSSH PRIVATE KEY-----\n", 'public' => MOVE_PUBLIC_KEY];
        }
    });
}

/** @return list<DaemonJob> */
function moveJobs(Machine $machine, string $kind): array
{
    return DaemonJob::query()->where('machine_id', $machine->id)->where('kind', $kind)->oldest()->get()->all();
}

/** @param  array<string, mixed>  $result */
function finishMoveJob(DaemonJob $job, string $state = 'succeeded', array $result = [], ?string $error = null): void
{
    $job->forceFill(['state' => $state, 'result' => $result, 'error' => $error === null ? null : ['message' => $error], 'finished_at' => now()])->save();
    app(MachineMoves::class)->jobFinished($job->refresh());
}

/** The destination reports its copies of the source's containers. */
function destinationRuns(Machine $to): void
{
    $to->forceFill(['inventory' => ['services' => [
        ['key' => 'docker:web2', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'kind' => 'app', 'status' => 'running', 'details' => ['managed' => 'true', 'backup' => 'tar']],
        ['key' => 'docker:pg2', 'name' => 'serveros-shopdb-postgres-1', 'kind' => 'database', 'status' => 'running', 'details' => ['managed' => 'true', 'compose_project' => 'serveros-shopdb', 'backup' => 'pg_dumpall']],
    ], 'listeners' => []]])->save();
}

/** Sends and restores every copy waiting, as both machines would. */
function copyEverything(Machine $from, Machine $to, MachineMove $move): void
{
    foreach (MachineMoveCopy::query()->where('state', 'sending')->get() as $copy) {
        finishMoveJob(DaemonJob::query()->findOrFail($copy->backup_job_id), result: [
            'snapshot' => '1700-move'.$copy->round, 'bytes' => 2048,
            'location' => "sftp://203.0.113.20/moves/{$move->id}/{$copy->container}/1700-move{$copy->round}",
        ]);
    }
    foreach (MachineMoveCopy::query()->where('state', 'restoring')->get() as $copy) {
        finishMoveJob(DaemonJob::query()->findOrFail($copy->restore_job_id));
    }
}

/** @return array{0: Workspace, 1: Machine, 2: Machine, 3: string, 4: MachineMove} */
function moveToReady(): array
{
    fakeMoveKeys();
    [$workspace, $from, $to, $url] = moveSetup();
    test()->actingAs($workspace->owner)->postJson($url.'/moves', ['to' => $to->id])->assertAccepted();
    $move = MachineMove::query()->sole();

    finishMoveJob(moveJobs($to, 'backup_receiver')[0], result: ['host_key' => 'ssh-ed25519 AAAAhostkey']);
    destinationRuns($to);
    foreach (moveJobs($to, 'deploy') as $deploy) {
        finishMoveJob($deploy, result: ['commit' => $deploy->command['job']['commit'] ?? null]);
    }
    copyEverything($from, $to, $move);

    return [$workspace, $from, $to, $url, $move->refresh()];
}

test('a move deploys every app on the destination without its domains, then copies its data over', function () {
    fakeMoveKeys();
    [$workspace, $from, $to, $url] = moveSetup();

    $started = $this->actingAs($workspace->owner)->postJson($url.'/moves', ['to' => $to->id])->assertAccepted()->json();
    expect($started['state'])->toBe('copying')
        ->and(array_column($started['apps'], 'name'))->toBe(['shop', 'shopdb'])
        ->and(json_encode($started))->not->toContain('move-private')->not->toContain('hunter2-secret');

    $receiver = moveJobs($to, 'backup_receiver');
    expect($receiver)->toHaveCount(1)
        ->and($receiver[0]->command['job']['public_keys'])->toBe([MOVE_PUBLIC_KEY]);

    $deploys = moveJobs($to, 'deploy');
    expect(array_map(fn (DaemonJob $j) => $j->service, $deploys))->toBe(['shop', 'shopdb'])
        ->and($deploys[0]->command['job']['domains'])->toBe([])
        ->and($deploys[0]->command['job']['env']['DB_PASSWORD'])->toBe('hunter2-secret')
        ->and(Activity::query()->where('action', 'machine.move_start')->sole()->meta)->toMatchArray(['to' => 'new-box', 'apps' => 2]);

    // Nothing is sent until the receiver is ready and the apps are deployed.
    expect(moveJobs($from, 'backup_to'))->toBe([]);
    finishMoveJob($receiver[0], result: ['host_key' => 'ssh-ed25519 AAAAhostkey']);
    foreach ($deploys as $deploy) {
        finishMoveJob($deploy, result: ['commit' => $deploy->command['job']['commit'] ?? null]);
    }

    $sends = moveJobs($from, 'backup_to');
    $move = MachineMove::query()->sole();
    expect(array_map(fn (DaemonJob $j) => $j->command['job']['service'], $sends))->toBe(['docker:web', 'docker:pg'])
        ->and($sends[0]->command['job'])->toMatchArray(['reason' => 'move', 'prefix' => "moves/{$move->id}"])
        ->and($sends[0]->command['job']['destination'])->toMatchArray(['type' => 'sftp', 'host' => '203.0.113.20', 'user' => 'serveros-backups', 'host_key' => 'ssh-ed25519 AAAAhostkey']);

    // The destination hasn't reported its containers yet, so it's asked to look again.
    copyEverything($from, $to, $move);
    expect(moveJobs($to, 'restore'))->toBe([])
        ->and(moveJobs($to, 'discover'))->not->toBe([]);

    destinationRuns($to);
    app(MachineMoves::class)->tick(CarbonImmutable::now());
    $restores = moveJobs($to, 'restore');
    expect(array_map(fn (DaemonJob $j) => $j->command['job']['service'], $restores))->toBe(['docker:web2', 'docker:pg2'])
        ->and($restores[0]->command['job'])->toMatchArray(['snapshot' => '1700-move1', 'received' => "moves/{$move->id}/serveros-shop-aaaaaaaaaaaa/1700-move1"]);

    copyEverything($from, $to, $move);
    expect($move->refresh()->state)->toBe('ready');

    $shown = $this->actingAs($workspace->owner)->getJson($url.'/moves/'.$move->id)->assertOk()->json();
    expect($shown['can'])->toMatchArray(['switch' => true, 'cancel' => true, 'rollBack' => false])
        ->and($shown['apps'][0]['copies'][0])->toMatchArray(['state' => 'copied', 'bytes' => 2048]);
});

test('switching over stops the source’s writers, copies the data again and gives the apps their domains', function () {
    [$workspace, $from, $to, $url, $move] = moveToReady();

    $this->actingAs($workspace->owner)->postJson($url.'/moves/'.$move->id.'/switch')->assertOk()->assertJsonPath('state', 'switching');

    // The database keeps running so it can be dumped; the app stops first.
    $stops = moveJobs($from, 'service_action');
    expect($stops)->toHaveCount(1)
        ->and($stops[0]->command['job'])->toMatchArray(['service' => 'docker:web', 'action' => 'stop'])
        ->and(moveJobs($from, 'backup_to'))->toHaveCount(2);

    finishMoveJob($stops[0]);
    expect(moveJobs($from, 'backup_to'))->toHaveCount(4);
    copyEverything($from, $to, $move);

    $withDomains = array_values(array_filter(moveJobs($to, 'deploy'), fn (DaemonJob $j) => ($j->command['job']['domains'] ?? []) !== []));
    expect($withDomains)->toHaveCount(1)
        ->and($withDomains[0]->command['job']['domains'])->toBe(['shop.example.com']);

    foreach (MachineMoveApp::query()->where('state', 'deploying_domains')->get() as $app) {
        finishMoveJob(DaemonJob::query()->findOrFail($app->deploy_job_id));
    }
    expect($move->refresh())->state->toBe('switched')->receiver_state->toBe('revoked')
        ->and(collect(moveJobs($to, 'backup_receiver'))->last()->command['job']['public_keys'])->toBe([]);

    $shown = $this->actingAs($workspace->owner)->getJson($url.'/moves/'.$move->id)->json();
    expect($shown['domains'])->toBe(['shop.example.com'])
        ->and($shown['to']['address'])->toBe('203.0.113.20');

    $this->actingAs($workspace->owner)->postJson($url.'/moves/'.$move->id.'/finish')->assertOk()->assertJsonPath('state', 'completed');
    expect(Activity::query()->where('action', 'machine.move_finish')->exists())->toBeTrue()
        ->and(DaemonJob::query()->where('kind', 'service_remove')->exists())->toBeFalse();
});

test('rolling back starts the source’s apps again and takes the domains off the destination', function () {
    [$workspace, $from, $to, $url, $move] = moveToReady();
    $this->actingAs($workspace->owner)->postJson($url.'/moves/'.$move->id.'/switch')->assertOk();
    finishMoveJob(moveJobs($from, 'service_action')[0]);
    copyEverything($from, $to, $move);
    foreach (MachineMoveApp::query()->where('state', 'deploying_domains')->get() as $app) {
        finishMoveJob(DaemonJob::query()->findOrFail($app->deploy_job_id));
    }
    $deploysBefore = count(moveJobs($to, 'deploy'));

    $this->actingAs($workspace->owner)->postJson($url.'/moves/'.$move->id.'/rollback')->assertOk()->assertJsonPath('state', 'rolled_back');

    $starts = array_values(array_filter(moveJobs($from, 'service_action'), fn (DaemonJob $j) => $j->command['job']['action'] === 'start'));
    $redeploys = array_slice(moveJobs($to, 'deploy'), $deploysBefore);
    expect($starts)->toHaveCount(1)
        ->and($starts[0]->command['job']['service'])->toBe('docker:web')
        ->and(array_map(fn (DaemonJob $j) => [$j->service, $j->command['job']['domains']], $redeploys))->toBe([['shop', []], ['shopdb', []]]);
});

test('a failed copy stops the move until it’s tried again', function () {
    fakeMoveKeys();
    [$workspace, $from, $to, $url] = moveSetup();
    $this->actingAs($workspace->owner)->postJson($url.'/moves', ['to' => $to->id])->assertAccepted();
    $move = MachineMove::query()->sole();
    finishMoveJob(moveJobs($to, 'backup_receiver')[0], result: ['host_key' => 'k']);
    destinationRuns($to);
    foreach (moveJobs($to, 'deploy') as $deploy) {
        finishMoveJob($deploy, result: ['commit' => $deploy->command['job']['commit'] ?? null]);
    }
    foreach (MachineMoveCopy::query()->get() as $copy) {
        finishMoveJob(DaemonJob::query()->findOrFail($copy->backup_job_id), result: [
            'snapshot' => '1700-move1', 'location' => "sftp://203.0.113.20/moves/{$move->id}/{$copy->container}/1700-move1",
        ]);
    }
    finishMoveJob(moveJobs($to, 'restore')[0], 'failed', error: 'files.tar.gz does not match its checksum; the snapshot is damaged');

    $shown = $this->actingAs($workspace->owner)->getJson($url.'/moves/'.$move->id)->json();
    expect($shown['state'])->toBe('failed')
        ->and($shown['error'])->toContain('does not match its checksum')
        ->and($shown['can'])->toMatchArray(['retry' => true, 'cancel' => true, 'switch' => false]);

    $this->actingAs($workspace->owner)->postJson($url.'/moves/'.$move->id.'/retry')->assertOk()->assertJsonPath('state', 'copying');
    expect(moveJobs($to, 'restore'))->toHaveCount(3)
        ->and(moveJobs($from, 'backup_to'))->toHaveCount(2);
});

test('the tick picks up a finish it missed and fails a job that’s gone', function () {
    fakeMoveKeys();
    [$workspace, , $to, $url] = moveSetup();
    $this->actingAs($workspace->owner)->postJson($url.'/moves', ['to' => $to->id])->assertAccepted();
    $move = MachineMove::query()->sole();

    $receiver = moveJobs($to, 'backup_receiver')[0];
    $receiver->forceFill(['state' => 'succeeded', 'result' => ['host_key' => 'k'], 'finished_at' => now()])->save();
    DaemonJob::query()->whereKey(moveJobs($to, 'deploy')[0]->id)->delete();

    app(MachineMoves::class)->tick(CarbonImmutable::now());

    expect($move->refresh())->receiver_state->toBe('ready')->state->toBe('failed')
        ->and($move->error)->toContain('The job is gone');
});

test('cancelling takes the key back and leaves the copies where they are', function () {
    fakeMoveKeys();
    [$workspace, $from, $to, $url] = moveSetup();
    $this->actingAs($workspace->owner)->postJson($url.'/moves', ['to' => $to->id])->assertAccepted();
    $this->actingAs($workspace->owner)->postJson($url.'/moves', ['to' => $to->id])->assertConflict()
        ->assertJsonPath('error', 'A move from old-box is already under way.');
    $move = MachineMove::query()->sole();

    $this->actingAs($workspace->owner)->postJson($url.'/moves/'.$move->id.'/cancel')->assertOk()->assertJsonPath('state', 'cancelled');
    $this->actingAs($workspace->owner)->postJson($url.'/moves/'.$move->id.'/switch')->assertConflict();

    expect($move->refresh()->receiver_state)->toBe('revoked')
        ->and(collect(moveJobs($to, 'backup_receiver'))->last()->command['job']['public_keys'])->toBe([])
        ->and(MachineMoves::active($from))->toBeNull()
        ->and(DaemonJob::query()->where('kind', 'service_remove')->exists())->toBeFalse();
});

test('a move keeps the destination’s other receiver keys, and is only seen from its own workspace', function () {
    fakeMoveKeys();
    [$workspace, $from, $to, $url] = moveSetup();
    (new BackupDestination)->forceFill([
        'workspace_id' => $workspace->id, 'name' => 'old-box backups', 'type' => 'machine', 'machine_id' => $to->id,
        'public_key' => 'ssh-ed25519 AAAAotherkey', 'status' => 'ready',
    ])->save();

    $this->actingAs($workspace->owner)->postJson($url.'/moves', ['to' => $to->id])->assertAccepted();
    expect(moveJobs($to, 'backup_receiver')[0]->command['job']['public_keys'])->toBe(['ssh-ed25519 AAAAotherkey', MOVE_PUBLIC_KEY]);

    $move = MachineMove::query()->sole();
    $stranger = Workspace::factory()->onPlan(Plan::Pro)->create();
    $this->actingAs($stranger->owner)->getJson('https://'.$stranger->host().'/machines/'.$from->id.'/moves/'.$move->id)->assertNotFound();

    $this->actingAs($workspace->owner)->get($url.'/settings')
        ->assertInertia(fn (Assert $page) => $page->where('move.id', $move->id)->where('move.state', 'copying'));
});
