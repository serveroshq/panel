<?php

use App\Actions\Machines\CreateJoinCommand;
use App\Enums\Plan;
use App\Models\Activity;
use App\Models\DaemonEnrolment;
use App\Models\Machine;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    config()->set('daemon.install_ready', true);
    Http::fake(['127.0.0.1:8090/*' => Http::response(['ok' => true])]);

    // Enrolling signs the daemon's certificate, so each test gets its own CA.
    $this->caDir = sys_get_temp_dir().'/serveros-ca-test-'.bin2hex(random_bytes(5));
    config()->set('daemon.ca_cert', $this->caDir.'/ca.crt');
    config()->set('daemon.ca_key', $this->caDir.'/ca.key');
    Artisan::call('daemon:ca', ['action' => 'init']);
});

afterEach(function () {
    File::deleteDirectory($this->caDir);
});

function hostedCsr(): string
{
    $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1', 'private_key_bits' => 384]);
    $csr = openssl_csr_new(['commonName' => 'pending'], $key);
    openssl_csr_export($csr, $pem);

    return $pem;
}

/** @return array<string, mixed> */
function hostedEnrol(string $token, string $hostname = 'sos-agents-1'): array
{
    return [
        'token' => $token, 'csr' => hostedCsr(), 'daemon_version' => 'v0.1.1-pre.24',
        'protocol_majors' => [1], 'facts' => ['hostname' => $hostname, 'os' => 'ubuntu', 'interfaces' => []],
    ];
}

/** A workspace on Essential with its one machine in use. */
function hostedFullWorkspace(): Workspace
{
    $workspace = Workspace::factory()->onPlan(Plan::Essential)->create();
    $limit = (int) $workspace->machineLimit();
    for ($i = 0; $i < $limit; $i++) {
        Machine::factory()->for($workspace)->create();
    }
    expect($workspace->canAddMachine())->toBeFalse();

    return $workspace;
}

test('a package’s machine joins without using the allowance, and doesn’t count towards it', function () {
    $workspace = hostedFullWorkspace();
    $join = app(CreateJoinCommand::class);

    expect($join->create($workspace, $workspace->owner))->toBeNull();
    $joined = $join->create($workspace, $workspace->owner, hostedBy: 'serveros-agents');
    expect($joined)->not->toBeNull()->and($joined['enrolment']->hosted_by)->toBe('serveros-agents');

    $this->postJson('/api/daemon/enrol', hostedEnrol($joined['token']))->assertCreated();

    $machine = Machine::query()->where('hosted_by', 'serveros-agents')->sole();
    expect($machine->workspace_id)->toBe($workspace->id)
        ->and($workspace->countedMachines()->count())->toBe((int) $workspace->machineLimit())
        ->and($workspace->billingSummary()['machineCount'])->toBe((int) $workspace->machineLimit())
        ->and(Activity::query()->where('workspace_id', $workspace->id)->where('action', 'machine.connect')->exists())->toBeFalse();
});

test('a package’s machine can join again as the same record, with a fresh server behind it', function () {
    $workspace = hostedFullWorkspace();
    $machine = new Machine(['name' => 'ServerOS agents', 'ip_address' => '0.0.0.0', 'provider' => 'Latitude.sh', 'environment' => 'production', 'services_count' => 0]);
    $machine->workspace()->associate($workspace);
    $machine->hosted_by = 'serveros-agents';
    $machine->save();
    $join = app(CreateJoinCommand::class);

    $first = $join->create($workspace, $workspace->owner, hostedBy: 'serveros-agents', machine: $machine);
    $this->postJson('/api/daemon/enrol', hostedEnrol($first['token'], 'sos-agents-a'))->assertCreated();
    $uid = $machine->refresh()->uid;
    $machine->forceFill(['inventory' => ['services' => [['key' => 'docker:old']]], 'disconnected_at' => now()])->save();

    $second = $join->create($workspace, $workspace->owner, hostedBy: 'serveros-agents', machine: $machine);
    $this->postJson('/api/daemon/enrol', hostedEnrol($second['token'], 'sos-agents-b'))->assertCreated();

    $machine->refresh();
    expect(Machine::query()->where('hosted_by', 'serveros-agents')->count())->toBe(1)
        ->and($machine->name)->toBe('ServerOS agents')
        ->and($machine->uid)->not->toBe($uid)
        ->and($machine->inventory)->toBeNull()
        ->and($machine->disconnected_at)->toBeNull()
        ->and(DaemonEnrolment::query()->find($second['enrolment']->id)->machine_id)->toBe($machine->id);
});

test('only a package’s own machine can be joined as again', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $own = Machine::factory()->for($workspace)->create();

    expect(fn () => app(CreateJoinCommand::class)->create($workspace, $workspace->owner, hostedBy: 'serveros-agents', machine: $own))
        ->toThrow(RuntimeException::class);
});
