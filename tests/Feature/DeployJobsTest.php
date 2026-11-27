<?php

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/** @return array{0: Workspace, 1: Machine} */
function jobsMachine(string $version = '0.1.1-pre.51', bool $docker = true): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill([
        'uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => $version,
        'facts' => $docker ? ['docker_version' => '27.1'] : [], 'inventory' => ['services' => []],
    ])->save();

    return [$workspace, $machine];
}

function deployJobUpdate(Machine $machine, string $id, array $payload): void
{
    test()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $id, ...$payload]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
}

test('a deploy the person stopped waiting on still records how it really ended', function () {
    [$workspace, $machine] = jobsMachine();
    $job = DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'running', 'log' => ['building'],
        'command' => ['timeout_secs' => 1800, 'job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40)]],
    ]);

    $this->actingAs($workspace->owner)->postJson($workspace->url()."/machines/{$machine->id}/jobs/{$job->id}/abandon")->assertOk();
    expect($job->fresh()->state)->toBe('cancelled');
    Http::assertSent(fn (GatewayRequest $r) => str_ends_with($r->url(), '/control') && $r['control']['type'] === 'cancel_job');

    // Word that's not the end is still ignored; the end isn't.
    deployJobUpdate($machine, $job->id, ['state' => 'running', 'log' => ['still going']]);
    expect($job->fresh()->log)->toBe(['building']);
    deployJobUpdate($machine, $job->id, ['state' => 'succeeded', 'log' => ['Health check passed']]);

    $job->refresh();
    expect($job->state)->toBe('succeeded')->and($job->error)->toBeNull()
        ->and($job->log)->toBe(['building', 'Health check passed', 'The machine finished it after ServerOS stopped waiting: this is how it went.']);

    // A finished job is still final.
    deployJobUpdate($machine, $job->id, ['state' => 'failed']);
    expect($job->fresh()->state)->toBe('succeeded');
});

test('a deploy that’s quiet but within its timeout keeps the app busy, so a retry waits', function () {
    [$workspace, $machine] = jobsMachine();
    $spec = ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40)];
    $failed = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'failed', 'command' => ['job' => $spec]]);
    $running = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'running', 'command' => ['timeout_secs' => 1800, 'job' => $spec]]);
    $running->forceFill(['updated_at' => now()->subMinutes(15)])->saveQuietly();

    expect($running->fresh()->isStale())->toBeFalse();
    $this->actingAs($workspace->owner)->postJson($workspace->url()."/machines/{$machine->id}/jobs/{$failed->id}/retry")
        ->assertStatus(409)->assertJsonPath('error', 'shop is deploying now. Wait for it to finish first.');

    $running->forceFill(['updated_at' => now()->subMinutes(40)])->saveQuietly();
    expect($running->fresh()->isStale())->toBeTrue();
    $this->actingAs($workspace->owner)->postJson($workspace->url()."/machines/{$machine->id}/jobs/{$failed->id}/retry")->assertAccepted();
});

test('Docker is installed from the dashboard on a daemon that can, and a deploy says so when it’s missing', function () {
    [$workspace, $machine] = jobsMachine(docker: false);
    $base = $workspace->url()."/machines/{$machine->id}";

    $this->actingAs($workspace->owner)->postJson($base.'/deploy', ['template' => 'auto-build', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40)])
        ->assertUnprocessable()->assertJsonValidationErrors(['docker' => 'install it here first']);
    $this->actingAs($workspace->owner)->getJson($base.'/deploy/taken')->assertJsonPath('docker', ['installed' => false, 'canInstall' => true]);

    $this->actingAs($workspace->owner)->postJson($base.'/docker/install')->assertUnprocessable()->assertJsonValidationErrors('confirm');
    $answer = $this->actingAs($workspace->owner)->postJson($base.'/docker/install', ['confirm' => true])->assertAccepted()->assertJsonPath('kind', 'docker_install');
    $job = DaemonJob::query()->findOrFail($answer->json('jobId'));
    expect($job->command['job'])->toBe(['type' => 'docker_install'])->and($job->command['confirmed'])->toBeTrue();
    expect(Activity::query()->where('action', 'machine.docker_install')->count())->toBe(1);

    // Asking again while it runs gives the same job.
    $this->actingAs($workspace->owner)->postJson($base.'/docker/install', ['confirm' => true])->assertJsonPath('jobId', $job->id);

    deployJobUpdate($machine, $job->id, ['state' => 'succeeded', 'result' => ['version' => '27.3.1', 'installed' => true]]);
    expect($machine->fresh()->hasDocker())->toBeTrue()->and($machine->fresh()->facts['docker_version'])->toBe('27.3.1');
    $this->actingAs($workspace->owner)->getJson($base.'/docker/'.$job->id)->assertJsonPath('installed', true)->assertJsonPath('version', '27.3.1');
});

test('an older daemon can’t install Docker, and the deploy keeps saying how to by hand', function () {
    [$workspace, $machine] = jobsMachine('0.1.1-pre.49', docker: false);
    $base = $workspace->url()."/machines/{$machine->id}";

    $this->actingAs($workspace->owner)->postJson($base.'/docker/install', ['confirm' => true])->assertStatus(409);
    $this->actingAs($workspace->owner)->postJson($base.'/deploy', ['template' => 'custom-dockerfile', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40)])
        ->assertUnprocessable()->assertJsonValidationErrors(['form' => 'get.docker.com']);
});
