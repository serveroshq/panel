<?php

use App\Enums\Plan;
use App\Jobs\AdvanceIntegrationOperation;
use App\Models\Activity;
use App\Models\DaemonEnrolment;
use App\Models\Integration;
use App\Models\IntegrationOperation;
use App\Models\IntegrationServer;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Integrations\Servers\CloudServers;
use App\Support\Integrations\Servers\ServerOperations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeHetzner;

function hzProject(Workspace $workspace): Integration
{
    return $workspace->integrations()->create([
        'provider' => 'hetzner',
        'label' => 'Acme prod',
        'credentials' => ['token' => FakeHetzner::TOKEN],
        'fingerprint' => Integration::fingerprintOf('hetzner', ['token' => FakeHetzner::TOKEN]),
        'meta' => ['servers' => 0],
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
    ]);
}

/** @return array{0: Workspace, 1: Machine, 2: Integration, 3: FakeHetzner} */
function hzLinkedMachine(): array
{
    $hetzner = FakeHetzner::install()->server(42, 'web-1', '198.51.100.7', '2001:db8:42::/64');
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);
    $integration = hzProject($workspace);
    app(CloudServers::class)->sync($integration);
    $hetzner->calls = [];

    return [$workspace, $machine, $integration, $hetzner];
}

function hzMachineUrl(Machine $machine, string $path): string
{
    return $machine->workspace->url("/machines/{$machine->id}/server{$path}");
}

beforeEach(function () {
    Sleep::fake();
});

test('servers link to machines by public IPv4 or an address in their IPv6 network, and the rest wait to be imported', function () {
    $hetzner = FakeHetzner::install()
        ->server(42, 'web-1', '198.51.100.7')
        ->server(43, 'v6-only', null, '2001:db8:43::/64')
        ->server(44, 'not-in-serveros', '198.51.100.9');
    $workspace = Workspace::factory()->create();
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);
    $v6 = Machine::factory()->for($workspace)->create(['name' => 'db-1', 'ip_address' => '10.0.0.4']);
    $v6->forceFill(['facts' => ['interfaces' => [['name' => 'eth0', 'addresses' => ['10.0.0.4/24', '2001:db8:43::1/64']]]]])->save();
    $elsewhere = Machine::factory()->create(['ip_address' => '198.51.100.9']);
    $integration = hzProject($workspace);

    $rows = app(CloudServers::class)->sync($integration)->keyBy('server_id');

    expect($rows['42']->machine_id)->toBe($web->id)
        ->and($rows['43']->machine_id)->toBe($v6->id)
        ->and($rows['44']->machine_id)->toBeNull()
        ->and($rows['44']->details['ipv4'])->toBe('198.51.100.9')
        ->and($elsewhere->fresh()->cloudServer)->toBeNull()
        ->and($integration->fresh()->meta['servers'])->toBe(3);

    unset($hetzner->servers[44]);
    expect(app(CloudServers::class)->sync($integration)->pluck('server_id')->all())->toBe(['43', '42']);

    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$web->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cloudServer.integration.name', 'Hetzner Cloud')
            ->where('cloudServer.integration.label', 'Acme prod')
            ->where('cloudServer.server.type', 'cx22')
            ->where('cloudServer.server.city', 'Falkenstein')
            ->where('cloudServer.can', ['servers', 'create_servers', 'resize', 'power', 'snapshots', 'delete_servers']));
    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$web->id}/settings"))
        ->assertInertia(fn (Assert $page) => $page->where('cloudServer.server.id', '42'));
});

test('making a server sends Hetzner a cloud-init that installs ServerOS with a fresh join token, and links the machine when it says hello', function () {
    config()->set('daemon.install_ready', true);
    Queue::fake([AdvanceIntegrationOperation::class]);
    $hetzner = FakeHetzner::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $integration = hzProject($workspace);

    $plans = $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$integration->id}/server-options"))->assertOk();
    expect(collect($plans->json('types'))->pluck('name')->all())->toBe(['cx22', 'cx32', 'cx42', 'cax11'])
        ->and($plans->json('types.0.prices.fsn1.monthly'))->toBe(4.59)
        ->and($plans->json('types.2.locations'))->toBe(['fsn1'])
        ->and(collect($plans->json('images'))->firstWhere('recommended', true)['id'])->toBe('ubuntu-24.04')
        ->and(collect($plans->json('images'))->pluck('os')->unique()->values()->all())->toBe(['ubuntu', 'debian'])
        ->and($plans->json('keys.0.name'))->toBe('sam@laptop')
        ->and($plans->json('currency'))->toBe('EUR');

    $made = $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$integration->id}/servers"), [
        'name' => 'web-3', 'location' => 'fsn1', 'type' => 'cx22', 'image' => 'ubuntu-24.04', 'keys' => ['7'],
    ])->assertCreated();

    $body = $hetzner->bodyOf('POST', 'servers');
    expect($body)->toMatchArray(['name' => 'web-3', 'location' => 'fsn1', 'server_type' => 'cx22', 'image' => 'ubuntu-24.04', 'start_after_create' => true, 'ssh_keys' => [7]])
        ->and($body['user_data'])->toStartWith("#cloud-config\nhostname: web-3\n")
        ->and($body['user_data'])->toContain('get.docker.com');
    preg_match('/--token=\x27([a-f0-9]{64})\x27/', $body['user_data'], $match);
    $enrolment = DaemonEnrolment::query()->sole();
    expect($match[1] ?? null)->not->toBeNull()
        ->and(hash('sha256', $match[1]))->toBe($enrolment->token_hash)
        ->and($enrolment->user_id)->toBe($workspace->owner->id);

    $row = IntegrationServer::query()->sole();
    $operation = IntegrationOperation::query()->sole();
    expect($row->daemon_enrolment_id)->toBe($enrolment->id)
        ->and($row->machine_id)->toBeNull()
        ->and($operation->only(['kind', 'state', 'step', 'server_id']))->toBe(['kind' => 'create', 'state' => 'running', 'step' => 'boot', 'server_id' => $row->server_id])
        ->and($made->json('operation.steps.1'))->toBe(['key' => 'boot', 'label' => 'Start it', 'state' => 'current'])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_create')->sole()))->toBe($workspace->owner->name.' made the server web-3 at Hetzner Cloud (CX22 in Falkenstein)');
    Queue::assertPushed(AdvanceIntegrationOperation::class);

    $operations = app(ServerOperations::class);
    expect($operations->advance($operation))->toBe(20)
        ->and($operation->fresh()->step)->toBe('install')
        ->and($operations->advance($operation->fresh()))->toBe(20);

    $dir = sys_get_temp_dir().'/serveros-ca-test-'.bin2hex(random_bytes(5));
    config()->set('daemon.ca_cert', $dir.'/ca.crt');
    config()->set('daemon.ca_key', $dir.'/ca.key');
    config()->set('daemon.gateway_host', 'gateway.serveros.test');
    Artisan::call('daemon:ca', ['action' => 'init']);
    try {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'secp384r1', 'private_key_bits' => 384]);
        openssl_csr_export(openssl_csr_new(['commonName' => 'pending'], $key), $csr);
        $this->app['auth']->forgetGuards();
        $uid = $this->postJson('/api/daemon/enrol', [
            'token' => $match[1], 'csr' => $csr, 'daemon_version' => 'v0.1.0', 'protocol_majors' => [1],
            'facts' => ['hostname' => 'web-3', 'os' => 'ubuntu', 'interfaces' => []],
        ])->assertCreated()->json('machine_id');
    } finally {
        @unlink($dir.'/ca.crt');
        @unlink($dir.'/ca.key');
        @rmdir($dir);
    }

    $machine = Machine::query()->where('uid', $uid)->sole();
    expect($row->fresh()->machine_id)->toBe($machine->id)
        ->and($operation->fresh()->state)->toBe('done')
        ->and($operation->fresh()->machine_id)->toBe($machine->id)
        ->and(ServerOperations::props($operation->fresh())['steps'][2]['state'])->toBe('done');
});

test('a server that can’t be made says why in the form and gives its join token back', function () {
    config()->set('daemon.install_ready', true);
    Queue::fake([AdvanceIntegrationOperation::class]);
    $hetzner = FakeHetzner::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $integration = hzProject($workspace);
    $url = $workspace->url("/integrations/{$integration->id}/servers");

    $this->actingAs($workspace->owner)->postJson($url, ['name' => 'web 3', 'location' => 'fsn1', 'type' => 'cx22', 'image' => 'ubuntu-24.04'])
        ->assertUnprocessable()->assertJsonValidationErrors(['name' => 'Use letters, numbers and dashes, like web-3.']);
    $this->actingAs($workspace->owner)->postJson($url, ['name' => 'web-3', 'location' => 'hel1', 'type' => 'cx42', 'image' => 'ubuntu-24.04'])
        ->assertUnprocessable()->assertJsonValidationErrors(['type' => 'That server type can’t be made in this location. Pick another.']);
    $this->actingAs($workspace->owner)->postJson($url, ['name' => 'arm-1', 'location' => 'fsn1', 'type' => 'cax11', 'image' => 'debian-12'])
        ->assertUnprocessable()->assertJsonValidationErrors(['image' => 'That system doesn’t run on this server type. Pick another.']);

    $hetzner->failNext('POST', 'servers', 403, ['error' => ['code' => 'resource_limit_exceeded', 'message' => 'server limit exceeded']]);
    $this->actingAs($workspace->owner)->postJson($url, ['name' => 'web-3', 'location' => 'fsn1', 'type' => 'cx22', 'image' => 'ubuntu-24.04'])
        ->assertUnprocessable()->assertJsonValidationErrors(['form' => 'Hetzner says this project has reached a limit: server limit exceeded.']);

    expect(DaemonEnrolment::query()->sole()->expires_at->isPast())->toBeTrue()
        ->and(IntegrationOperation::query()->count())->toBe(0)
        ->and(Activity::query()->where('action', 'machine.server_create')->count())->toBe(0);
});

test('resizing shuts the server down, changes its type and starts it again', function () {
    [$workspace, $machine, $integration, $hetzner] = hzLinkedMachine();
    $hetzner->actionPolls = 1;

    $types = $this->actingAs($workspace->owner)->getJson(hzMachineUrl($machine, '/types'))->assertOk();
    expect(collect($types->json('types'))->pluck('name')->all())->toBe(['cx32', 'cx42'])
        ->and($types->json('current.name'))->toBe('cx22')
        ->and($types->json('types.0.monthly'))->toBe(7.59)
        ->and($types->json('needsPowerOff'))->toBeTrue();

    $this->actingAs($workspace->owner)->postJson(hzMachineUrl($machine, '/resize'), ['type' => 'cax11'])
        ->assertUnprocessable()->assertJsonValidationErrors('type');

    $hetzner->calls = [];
    $this->actingAs($workspace->owner)->postJson(hzMachineUrl($machine, '/resize'), ['type' => 'cx32'])->assertCreated();

    expect(array_values(array_filter($hetzner->called('POST'), fn ($c) => str_contains($c, '/actions/'))))->toBe([
        'POST servers/42/actions/shutdown',
        'POST servers/42/actions/change_type',
        'POST servers/42/actions/poweron',
    ])
        ->and($hetzner->bodyOf('POST', 'servers/42/actions/change_type'))->toBe(['server_type' => 'cx32', 'upgrade_disk' => false])
        ->and($hetzner->servers[42]['status'])->toBe('running');

    $operation = IntegrationOperation::query()->sole();
    expect($operation->state)->toBe('done')
        ->and($operation->machine_id)->toBe($machine->id)
        ->and($machine->cloudServer->details['type'])->toBe('cx32')
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_resize')->sole()))->toBe($workspace->owner->name.' started resizing web-1 from CX22 to CX32');
});

test('a server that won’t shut down is powered off after a minute, and one already off stays off', function () {
    [$workspace, $machine, $integration, $hetzner] = hzLinkedMachine();
    Queue::fake([AdvanceIntegrationOperation::class]);
    $hetzner->shutdownPolls = 1000;
    $this->actingAs($workspace->owner)->postJson(hzMachineUrl($machine, '/resize'), ['type' => 'cx32', 'grow_disk' => true])->assertCreated();
    $operation = IntegrationOperation::query()->sole();
    $operations = app(ServerOperations::class);

    $operations->advance($operation);
    expect($operation->step)->toBe('stopping')
        ->and($hetzner->called('POST'))->not->toContain('POST servers/42/actions/poweroff');
    $this->travel(61)->seconds();
    $operations->advance($operation);
    expect($hetzner->called('POST'))->toContain('POST servers/42/actions/poweroff');
    while ($operations->advance($operation) !== null) {
    }
    expect($operation->fresh()->state)->toBe('done')
        ->and($hetzner->bodyOf('POST', 'servers/42/actions/change_type'))->toBe(['server_type' => 'cx32', 'upgrade_disk' => true]);

    $hetzner->servers[42]['status'] = 'off';
    $hetzner->calls = [];
    $this->actingAs($workspace->owner)->postJson(hzMachineUrl($machine, '/resize'), ['type' => 'cx42'])->assertCreated();
    $second = IntegrationOperation::query()->latest('id')->first();
    while ($operations->advance($second) !== null) {
    }
    expect($second->fresh()->state)->toBe('done')
        ->and($hetzner->called('POST'))->toBe(['POST servers/42/actions/change_type'])
        ->and($hetzner->servers[42]['status'])->toBe('off');
});

test('when Hetzner can’t change the type, the resize fails with its reason and the server is started again', function () {
    [$workspace, $machine, $integration, $hetzner] = hzLinkedMachine();
    $hetzner->failActions = 'change_type';

    $this->actingAs($workspace->owner)->postJson(hzMachineUrl($machine, '/resize'), ['type' => 'cx32'])->assertCreated();

    $operation = IntegrationOperation::query()->sole();
    expect($operation->state)->toBe('failed')
        ->and($operation->error)->toBe('The type wasn’t changed: cannot change to this server type. ServerOS turned it back on.')
        ->and(array_slice($hetzner->called('POST'), -2))->toBe(['POST servers/42/actions/change_type', 'POST servers/42/actions/poweron'])
        ->and($hetzner->servers[42]['server_type']['name'])->toBe('cx22')
        ->and(ServerOperations::props($operation)['steps'][1]['state'])->toBe('failed');
});

test('restart and power cycle go through Hetzner, even with the daemon offline, and are recorded', function () {
    [$workspace, $machine, $integration, $hetzner] = hzLinkedMachine();
    $machine->forceFill(['last_seen_at' => now()->subDay(), 'disconnected_at' => now()->subDay()])->save();

    $this->actingAs($workspace->owner)->postJson(hzMachineUrl($machine, '/reboot'))->assertOk()
        ->assertJson(['message' => 'Restarting web-1 through Hetzner Cloud.']);
    $this->actingAs($workspace->owner)->postJson(hzMachineUrl($machine, '/power-cycle'))->assertOk();

    expect($hetzner->called('POST'))->toBe(['POST servers/42/actions/reboot', 'POST servers/42/actions/reset'])
        ->and(Activity::query()->orderBy('id')->pluck('action')->all())->toBe(['machine.server_reboot', 'machine.server_power_cycle'])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_power_cycle')->sole()))->toBe($workspace->owner->name.' power cycled web-1 through Hetzner Cloud');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson(hzMachineUrl($machine, '/reboot'))->assertForbidden();
    $other = Machine::factory()->for($workspace)->create();
    $this->actingAs($workspace->owner)->postJson(hzMachineUrl($other, '/reboot'))->assertNotFound();
});

test('a read-only token is told so in plain words', function () {
    [$workspace, $machine, $integration, $hetzner] = hzLinkedMachine();
    $hetzner->failNext('POST', 'servers/42/actions/reboot', 403, ['error' => ['code' => 'forbidden', 'message' => 'insufficient permissions']]);

    $this->actingAs($workspace->owner)->postJson(hzMachineUrl($machine, '/reboot'))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['form' => 'This token can only read. Make a Read & Write token in the Hetzner Console and connect it again.']);
    expect(Activity::query()->count())->toBe(0);
});

test('snapshots are listed for the machine’s server only, and can be taken and deleted', function () {
    [$workspace, $machine, $integration, $hetzner] = hzLinkedMachine();
    $hetzner->images[700] = ['id' => 700, 'type' => 'snapshot', 'status' => 'available', 'description' => 'before upgrade', 'image_size' => 1.8, 'disk_size' => 40, 'created' => '2026-11-20T10:00:00+00:00', 'created_from' => ['id' => 42, 'name' => 'web-1']];
    $hetzner->images[701] = ['id' => 701, 'type' => 'snapshot', 'status' => 'available', 'description' => 'other server', 'image_size' => 2.0, 'disk_size' => 40, 'created' => '2026-11-21T10:00:00+00:00', 'created_from' => ['id' => 99, 'name' => 'other']];

    $this->actingAs($workspace->owner)->getJson(hzMachineUrl($machine, '/snapshots'))->assertOk()
        ->assertJsonCount(1, 'snapshots')
        ->assertJsonPath('snapshots.0', ['id' => '700', 'name' => 'before upgrade', 'status' => 'available', 'sizeGb' => 1.8, 'diskGb' => 40, 'createdAt' => '2026-11-20T10:00:00+00:00']);

    $this->actingAs($workspace->owner)->postJson(hzMachineUrl($machine, '/snapshots'), ['name' => 'before resize'])->assertCreated()
        ->assertJsonPath('snapshot.status', 'creating');
    expect($hetzner->bodyOf('POST', 'servers/42/actions/create_image'))->toMatchArray(['description' => 'before resize', 'type' => 'snapshot']);

    $this->actingAs($workspace->owner)->deleteJson(hzMachineUrl($machine, '/snapshots/701'))->assertNotFound();
    $this->actingAs($workspace->owner)->deleteJson(hzMachineUrl($machine, '/snapshots/700'))->assertOk();

    expect($hetzner->called('DELETE'))->toBe(['DELETE images/700'])
        ->and(isset($hetzner->images[701]))->toBeTrue()
        ->and(Activity::query()->orderBy('id')->pluck('action')->all())->toBe(['machine.snapshot_create', 'machine.snapshot_delete']);
});

test('deleting the server needs the machine’s name typed, and removes the machine unless asked to keep it', function () {
    [$workspace, $machine, $integration, $hetzner] = hzLinkedMachine();
    $url = hzMachineUrl($machine, '');

    $this->actingAs($workspace->owner)->delete($url, ['confirm' => 'web-2'])->assertSessionHasErrors('confirm');
    expect($hetzner->called('DELETE'))->toBe([]);

    $this->actingAs($workspace->owner)->delete($url, ['confirm' => 'web-1', 'keep' => true])->assertRedirect();
    expect($hetzner->called('DELETE'))->toBe(['DELETE servers/42'])
        ->and($machine->fresh())->not->toBeNull()
        ->and(IntegrationServer::query()->count())->toBe(0)
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_delete')->sole()))->toBe($workspace->owner->name.' deleted the server of web-1 at Hetzner Cloud, and kept the machine');

});

test('deleting the server removes its machine too, by default', function () {
    [$workspace, $machine, $integration, $hetzner] = hzLinkedMachine();

    $this->actingAs($workspace->owner)->delete(hzMachineUrl($machine, ''), ['confirm' => 'web-1'])
        ->assertRedirect(route('workspace.machines.index', $workspace->slug));
    expect(Machine::query()->find($machine->id))->toBeNull()
        ->and($hetzner->called('DELETE'))->toBe(['DELETE servers/42'])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_delete')->sole()))->toBe($workspace->owner->name.' deleted the server of web-1 at Hetzner Cloud, and removed the machine');
});

test('busy or failing Hetzner calls are tried again, but a POST is never sent twice after a server error', function () {
    [$workspace, $machine, $integration, $hetzner] = hzLinkedMachine();

    $hetzner->failNext('GET', 'servers', 429, ['error' => ['code' => 'rate_limit_exceeded', 'message' => 'limit']], ['Retry-After' => '1']);
    $hetzner->failNext('GET', 'servers', 502);
    expect(app(CloudServers::class)->sync($integration)->count())->toBe(1)
        ->and($hetzner->called('GET'))->toBe(['GET servers', 'GET servers', 'GET servers']);
    Sleep::assertSleptTimes(2);

    $hetzner->calls = [];
    foreach (range(1, 3) as $i) {
        $hetzner->failNext('GET', 'servers', 503);
    }
    $this->actingAs($workspace->owner)->post($workspace->url("/integrations/{$integration->id}/check"));
    expect($integration->fresh()->last_error)->toBe('Hetzner isn’t answering properly right now. Try again in a few minutes.')
        ->and($integration->fresh()->status)->toBe('connected')
        ->and($hetzner->called('GET'))->toHaveCount(3);

    $hetzner->calls = [];
    $hetzner->failNext('POST', 'servers/42/actions/reboot', 503);
    $this->actingAs($workspace->owner)->postJson(hzMachineUrl($machine, '/reboot'))->assertUnprocessable();
    expect($hetzner->called('POST'))->toBe(['POST servers/42/actions/reboot']);

    $hetzner->calls = [];
    $hetzner->failNext('POST', 'servers/42/actions/reboot', 429, [], ['Retry-After' => '1']);
    $this->actingAs($workspace->owner)->postJson(hzMachineUrl($machine, '/reboot'))->assertOk();
    expect($hetzner->called('POST'))->toBe(['POST servers/42/actions/reboot', 'POST servers/42/actions/reboot']);

    $hetzner->failNext('POST', 'servers/42/actions/reboot', 429, [], ['Retry-After' => '3600']);
    $this->actingAs($workspace->owner)->postJson(hzMachineUrl($machine, '/reboot'))
        ->assertJsonValidationErrors(['form' => 'Hetzner is limiting requests from this account right now. Try again in a few minutes.']);
});

test('a change whose job was lost stops counting as in progress, so the machine can be resized again', function () {
    [$workspace, $machine, $integration, $hetzner] = hzLinkedMachine();
    $lost = $integration->operations()->create(['kind' => 'resize', 'machine_id' => $machine->id, 'server_id' => '42', 'state' => 'running', 'step' => 'changing', 'action_id' => '1']);
    $lost->forceFill(['updated_at' => now()->subHour()])->saveQuietly();

    $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$integration->id}/operations/{$lost->id}"))->assertOk()
        ->assertJsonPath('operation.state', 'failed')
        ->assertJsonPath('operation.error', 'ServerOS lost track of this change. Check the server in the provider’s console.');
    expect(ServerOperations::busy($machine))->toBeFalse();

    $other = hzProject(Workspace::factory()->create());
    $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$other->id}/operations/{$lost->id}"))->assertNotFound();
});
