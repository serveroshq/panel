<?php

use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Health\WorkspaceHealth;
use App\Support\Machines\MachinePolicies;
use App\Support\Security\Posture;
use App\Support\Security\SecurityFacts;
use Illuminate\Support\Str;

/**
 * @param  array<string, mixed>  $security
 * @param  list<array<string, mixed>>  $listeners
 * @param  array<string, mixed>  $firewall
 * @param  array<string, mixed>  $attributes
 */
function postureMachine(Workspace $workspace, string $name, array $security, array $listeners = [], array $firewall = ['backend' => 'ufw', 'installed' => true, 'active' => true, 'ports' => []], array $attributes = []): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name]);
    $machine->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'facts' => ['os' => 'ubuntu', 'os_version' => '24.04', 'security' => $security],
        'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => $listeners, 'services' => [], 'firewall' => $firewall],
        'updates_checked_at' => now(),
        'security_updates_count' => 0,
        'reboot_required' => false,
        ...$attributes,
    ])->save();

    return $machine;
}

/** @return array<string, mixed> */
function hardened(): array
{
    return [
        'ssh' => ['password_authentication' => false, 'permit_root_login' => 'prohibit-password', 'ports' => [22]],
        'logins' => ['window_secs' => 3600, 'failed' => 2, 'sources' => 1, 'root' => 0, 'accepted_password' => 0],
        'auto_updates' => true,
    ];
}

/** @return array<string, array<string, mixed>> */
function postureChecks(array $report): array
{
    return collect($report['checks'])->keyBy('key')->all();
}

test('a hardened machine scores 100 and every check passes', function () {
    $machine = postureMachine(Workspace::factory()->create(), 'web-1', hardened(), [
        ['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 22, 'exe' => '/usr/sbin/sshd', 'pid' => 1],
        ['proto' => 'tcp', 'address' => '127.0.0.1', 'port' => 5432, 'exe' => '/usr/lib/postgresql/16/bin/postgres', 'pid' => 2],
    ]);

    $report = Posture::report($machine);

    expect($report['state'])->toBe('scored')
        ->and($report['score'])->toBe(100)
        ->and($report['grade'])->toBe('A')
        ->and(collect($report['checks'])->pluck('status')->unique()->all())->toBe(['pass']);
});

test('a machine failing every check scores 0, worst first, with the details to show', function () {
    $machine = postureMachine(Workspace::factory()->create(), 'db-1', [
        'ssh' => ['password_authentication' => true, 'permit_root_login' => 'yes'],
        'logins' => ['window_secs' => 3600, 'failed' => 140, 'sources' => 9, 'root' => 60, 'accepted_password' => 0],
        'auto_updates' => false,
    ], [
        ['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 5432, 'exe' => '/usr/lib/postgresql/16/bin/postgres', 'pid' => 2],
        ['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 23, 'exe' => '/usr/sbin/in.telnetd', 'pid' => 3],
    ], ['backend' => 'ufw', 'installed' => true, 'active' => false, 'ports' => [
        ['port' => 5432, 'proto' => 'tcp', 'exposure' => 'open'],
        ['port' => 23, 'proto' => 'tcp', 'exposure' => 'open'],
    ]], ['security_updates_count' => 3, 'reboot_required' => true]);

    $report = Posture::report($machine);
    $checks = postureChecks($report);

    expect($report['score'])->toBe(0)
        ->and($report['grade'])->toBe('F')
        ->and(collect($report['checks'])->pluck('status')->unique()->all())->toBe(['fail'])
        ->and($report['checks'][0]['severity'])->toBe('high')
        ->and($checks['exposed']['params']['services'])->toBe('PostgreSQL (5432/tcp)')
        ->and($checks['cleartext']['params']['services'])->toBe('Telnet (23/tcp)')
        ->and($checks['sign_ins']['params'])->toMatchArray(['failed' => 140, 'sources' => 9, 'root' => 60, 'minutes' => 60])
        ->and($checks['security_updates']['params']['count'])->toBe(3);
});

test('the score is out of what was checked, so unknown checks cost nothing', function () {
    $check = fn (string $severity, string $status) => ['key' => 'x', 'severity' => $severity, 'status' => $status, 'params' => []];

    expect(Posture::score([$check('high', 'pass'), $check('medium', 'fail'), $check('low', 'pass'), $check('high', 'unknown')]))->toBe(75)
        ->and(Posture::score([$check('low', 'unknown')]))->toBe(0);
});

test('a machine missing a high-severity report is incomplete, not graded', function () {
    $machine = postureMachine(Workspace::factory()->create(), 'new-1', ['ssh' => ['password_authentication' => false]]);

    $report = Posture::report($machine);

    expect($report['state'])->toBe('incomplete')
        ->and($report['score'])->toBeNull()
        ->and(postureChecks($report)['ssh_root']['status'])->toBe('unknown')
        ->and(postureChecks($report)['ssh_password']['status'])->toBe('pass');

    $nothing = Machine::factory()->for(Workspace::factory()->create())->create();
    $nothing->forceFill(['uid' => (string) Str::uuid(), 'facts' => null, 'inventory' => null])->save();
    expect(Posture::report($nothing)['state'])->toBe('waiting');
});

test('a machine that hasn’t reported for a day is stale, not graded', function () {
    $machine = postureMachine(Workspace::factory()->create(), 'gone-1', hardened(), attributes: ['last_seen_at' => now()->subDays(2)]);

    $report = Posture::report($machine);

    expect($report['state'])->toBe('stale')
        ->and($report['score'])->toBeNull()
        ->and($report['grade'])->toBeNull();
});

test('values the daemon couldn’t read are unknown, not a pass', function () {
    $machine = postureMachine(Workspace::factory()->create(), 'odd-1', [
        'ssh' => ['password_authentication' => null, 'permit_root_login' => null],
        'logins' => null,
        'auto_updates' => null,
    ]);

    $checks = postureChecks(Posture::report($machine));

    expect($checks['ssh_password']['status'])->toBe('unknown')
        ->and($checks['ssh_root']['status'])->toBe('unknown')
        ->and($checks['sign_ins']['status'])->toBe('unknown')
        ->and($checks['auto_updates']['status'])->toBe('unknown');
});

test('a cleartext service the firewall blocks doesn’t fail', function () {
    $machine = postureMachine(Workspace::factory()->create(), 'ftp-1', hardened(), [
        ['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 21, 'exe' => '/usr/sbin/vsftpd', 'pid' => 3],
    ], ['backend' => 'ufw', 'installed' => true, 'active' => true, 'ports' => [
        ['port' => 21, 'proto' => 'tcp', 'exposure' => 'blocked'],
    ]]);

    expect(postureChecks(Posture::report($machine))['cleartext']['status'])->toBe('pass');
});

test('root sign-in and failed sign-ins only fail when SSH accepts passwords', function () {
    $keysOnly = postureMachine(Workspace::factory()->create(), 'keys-1', [
        ...hardened(),
        'ssh' => ['password_authentication' => false, 'permit_root_login' => 'yes'],
        'logins' => ['window_secs' => 3600, 'failed' => 500, 'sources' => 40, 'root' => 300],
    ]);
    $passwords = postureMachine(Workspace::factory()->create(), 'pw-1', [
        ...hardened(),
        'ssh' => ['password_authentication' => true, 'permit_root_login' => 'yes'],
        'logins' => ['window_secs' => 3600, 'failed' => 500, 'sources' => 40, 'root' => 300],
    ]);

    expect(postureChecks(Posture::report($keysOnly))['ssh_root']['status'])->toBe('pass')
        ->and(postureChecks(Posture::report($keysOnly))['sign_ins']['status'])->toBe('pass')
        ->and(postureChecks(Posture::report($passwords))['ssh_root']['status'])->toBe('fail')
        ->and(postureChecks(Posture::report($passwords))['sign_ins']['status'])->toBe('fail');
});

test('keyboard-interactive sign-in through PAM counts as SSH taking passwords', function () {
    $machine = postureMachine(Workspace::factory()->create(), 'pam-1', [
        ...hardened(),
        'ssh' => ['password_authentication' => false, 'kbd_interactive_authentication' => true, 'use_pam' => true, 'permit_root_login' => 'yes'],
    ]);
    $noPam = postureMachine(Workspace::factory()->create(), 'pam-2', [
        ...hardened(),
        'ssh' => ['password_authentication' => false, 'kbd_interactive_authentication' => true, 'use_pam' => false, 'permit_root_login' => 'yes'],
    ]);

    expect(postureChecks(Posture::report($machine))['ssh_password']['status'])->toBe('fail')
        ->and(postureChecks(Posture::report($machine))['ssh_root']['status'])->toBe('fail')
        ->and(postureChecks(Posture::report($noPam))['ssh_password']['status'])->toBe('pass')
        ->and(postureChecks(Posture::report($noPam))['ssh_root']['status'])->toBe('pass');
});

test('a machine with security watch off says so instead of incomplete', function () {
    $workspace = Workspace::factory()->create();
    $machine = postureMachine($workspace, 'quiet-1', hardened());
    MachinePolicies::defaultFor($workspace)->update(['security_watch' => false]);

    $report = Posture::report($machine->fresh());

    expect($report['state'])->toBe('watch_off')
        ->and($report['score'])->toBeNull()
        ->and(postureChecks($report)['ssh_root']['status'])->toBe('unknown')
        ->and(postureChecks($report)['firewall']['status'])->toBe('pass');
});

test('the reader turns the daemon’s security report into typed values', function () {
    $machine = postureMachine(Workspace::factory()->create(), 'typed-1', [
        'ssh' => ['password_authentication' => 'yes', 'permit_root_login' => 'Prohibit-Password'],
        'logins' => ['window_secs' => 1800, 'failed' => -3, 'sources' => 'many'],
        'auto_updates' => true,
    ]);

    $facts = SecurityFacts::of($machine);

    expect($facts->passwordAuthentication)->toBeNull()
        ->and($facts->permitRootLogin)->toBe('prohibit-password')
        ->and($facts->failedSignIns)->toBeNull()
        ->and($facts->failedSources)->toBe(0)
        ->and($facts->windowMinutes)->toBe(30)
        ->and($facts->autoUpdates)->toBeTrue();
});

test('an exposed database fails here exactly when the machine page warns about it', function () {
    $workspace = Workspace::factory()->create();
    $open = postureMachine($workspace, 'redis-1', hardened(), [
        ['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 6379, 'exe' => '/usr/bin/redis-server', 'pid' => 4],
    ], ['backend' => 'ufw', 'installed' => true, 'active' => true, 'ports' => [['port' => 6379, 'proto' => 'tcp', 'exposure' => 'open']]]);
    $closed = postureMachine($workspace, 'redis-2', hardened(), [
        ['proto' => 'tcp', 'address' => '0.0.0.0', 'port' => 6379, 'exe' => '/usr/bin/redis-server', 'pid' => 4],
    ], ['backend' => 'ufw', 'installed' => true, 'active' => true, 'ports' => [['port' => 6379, 'proto' => 'tcp', 'exposure' => 'blocked']]]);

    foreach ([$open, $closed] as $machine) {
        $warned = collect(app(WorkspaceHealth::class)->forMachine($machine))->contains(fn (array $i) => str_contains($i['id'], '-exposed-'));
        expect(postureChecks(Posture::report($machine))['exposed']['status'])->toBe($warned ? 'fail' : 'pass');
    }
    expect(postureChecks(Posture::report($open))['exposed']['status'])->toBe('fail');
});
