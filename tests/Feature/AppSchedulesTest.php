<?php

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Client\Request as GatewayRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/** @return array{0: Workspace, 1: Machine, 2: string} */
function scheduledApp(array $spec = []): array
{
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'daemon_version' => '0.1.1-pre.51', 'inventory' => ['services' => [
        ['key' => 'docker:abc123', 'name' => 'serveros-shop-aaaaaaaaaaaa', 'status' => 'running', 'details' => ['managed' => 'true']],
    ]]])->save();
    DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner_id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'succeeded',
        'command' => ['job' => ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => str_repeat('a', 40), 'port' => 3000, ...$spec]],
    ]);

    return [$workspace, $machine, $workspace->url()."/machines/{$machine->id}/apps/shop/schedules"];
}

function unlockSchedules(User $user, Workspace $workspace): void
{
    $user->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);
    test()->actingAs($user)->postJson($workspace->url().'/console/unlock', ['code' => '123456'])->assertOk();
}

test('scheduling a command needs what the console needs: two-factor on and the console unlocked', function () {
    [$workspace, , $url] = scheduledApp();
    $schedule = ['schedules' => [['command' => 'php artisan schedule:run', 'cron' => '* * * * *']]];

    $this->actingAs($workspace->owner)->putJson($url, $schedule)->assertForbidden()->assertJsonPath('code', 'two_factor_required');
    $workspace->owner->forceFill(['two_factor_secret' => encrypt('secret'), 'two_factor_confirmed_at' => now()])->save();
    $this->actingAs($workspace->owner)->putJson($url, $schedule)->assertStatus(423)->assertJsonPath('code', 'console_locked');

    unlockSchedules($workspace->owner, $workspace);
    $this->actingAs($workspace->owner)->putJson($url, ['schedules' => [['command' => 'x', 'cron' => 'every minute']]])
        ->assertUnprocessable()->assertJsonValidationErrors('schedules.0.cron');
    $this->actingAs($workspace->owner)->putJson($url, $schedule)->assertOk()
        ->assertJsonPath('schedules.0.command', 'php artisan schedule:run')
        ->assertJsonPath('schedules.0.lastRun', null);
    expect(Activity::query()->where('action', 'app.schedules')->sole()->meta)->toMatchArray(['added' => 1]);
});

test('a due schedule runs in the app’s live container once a minute, as whoever saved it, and never on top of itself', function () {
    Carbon::setTestNow(now()->startOfMinute()->addSeconds(5));
    [$workspace, $machine, $url] = scheduledApp();
    unlockSchedules($workspace->owner, $workspace);
    $later = (now()->minute + 30) % 60;
    $this->actingAs($workspace->owner)->putJson($url, ['schedules' => [
        ['command' => 'php artisan schedule:run', 'cron' => '* * * * *'],
        ['command' => 'php artisan backup', 'cron' => "{$later} * * * *", 'timeout' => 900],
        ['command' => 'off', 'cron' => '* * * * *', 'enabled' => false],
    ]])->assertOk();

    $this->artisan('apps:run-schedules')->expectsOutput('Started 1 scheduled command(s), skipped 0.')->assertSuccessful();
    $run = DaemonJob::query()->where('kind', 'service_exec')->sole();
    expect($run)->service->toBe('docker:abc123')->user_id->toBe($workspace->owner_id)
        ->and($run->trigger)->toStartWith('schedule:')
        ->and($run->command['job'])->toBe(['type' => 'service_exec', 'service' => 'docker:abc123', 'command' => 'php artisan schedule:run', 'timeout_secs' => 300]);
    Http::assertSent(fn (GatewayRequest $r) => str_ends_with($r->url(), '/commands') && $r['command']['job']['type'] === 'service_exec');
    expect(Activity::query()->where('action', 'console.command')->count())->toBe(0);

    // Not twice in one minute, and not while the last run is still going.
    $this->artisan('apps:run-schedules')->expectsOutput('Started 0 scheduled command(s), skipped 1.');
    Carbon::setTestNow(now()->addMinute());
    $machine->forceFill(['last_seen_at' => now()])->save();
    $this->artisan('apps:run-schedules')->expectsOutput('Started 0 scheduled command(s), skipped 1.');
    expect(Activity::query()->where('action', 'app.schedule_skipped')->sole()->meta)->toMatchArray(['reason' => 'the run before it is still going']);

    $run->forceFill(['state' => 'succeeded', 'result' => ['exit_code' => 0]])->save();
    Carbon::setTestNow(now()->addMinute());
    $machine->forceFill(['last_seen_at' => now()])->save();
    $this->artisan('apps:run-schedules')->expectsOutput('Started 1 scheduled command(s), skipped 0.');

    $this->actingAs($workspace->owner)->getJson($workspace->url()."/machines/{$machine->id}/apps/shop/jobs/{$run->id}")->assertOk()->assertJsonPath('result.exit_code', 0);
});

test('a Compose app can’t have scheduled commands', function () {
    [$workspace, , $url] = scheduledApp(['compose_file' => 'compose.yaml']);
    unlockSchedules($workspace->owner, $workspace);

    $this->actingAs($workspace->owner)->putJson($url, ['schedules' => [['command' => 'x', 'cron' => '@hourly']]])
        ->assertUnprocessable()->assertJsonValidationErrors(['schedules' => 'run in apps that run as one container']);
});
