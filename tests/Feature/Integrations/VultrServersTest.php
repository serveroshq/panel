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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeVultr;

function vultrAccount(Workspace $workspace): Integration
{
    return $workspace->integrations()->create([
        'provider' => 'vultr',
        'label' => 'Vultr',
        'credentials' => ['api_key' => FakeVultr::KEY],
        'fingerprint' => Integration::fingerprintOf('vultr', ['api_key' => FakeVultr::KEY]),
        'meta' => ['servers' => 0],
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
    ]);
}

function vultrConnect(Workspace $workspace, string $key = FakeVultr::KEY): TestResponse
{
    return test()->actingAs($workspace->owner)
        ->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/vultr'), ['credentials' => ['api_key' => $key]]);
}

/** @return array{0: Workspace, 1: Machine, 2: Integration, 3: FakeVultr, 4: string} */
function vultrLinkedMachine(): array
{
    $id = FakeVultr::id(1);
    $vultr = FakeVultr::install()->instance($id, 'web-1', '198.51.100.7', '2001:db8:aa::');
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);
    $integration = vultrAccount($workspace);
    app(CloudServers::class)->sync($integration);
    $vultr->calls = [];

    return [$workspace, $machine, $integration, $vultr, $id];
}

function vultrUrl(Machine $machine, string $path): string
{
    return $machine->workspace->url("/machines/{$machine->id}/server{$path}");
}

beforeEach(function () {
    Sleep::fake();
});

test('connecting Vultr checks the key, names the account after Vultr’s and links instances by address', function () {
    FakeVultr::install()
        ->instance(FakeVultr::id(1), 'web-1', '198.51.100.7')
        ->instance(FakeVultr::id(2), 'v6-only', null, '2001:db8:77::')
        ->instance(FakeVultr::id(3), 'new-one', null, null, ['status' => 'pending', 'power_status' => 'stopped']);
    $workspace = Workspace::factory()->create();
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1', 'ip_address' => '10.0.0.4']);
    $db->forceFill(['facts' => ['interfaces' => [['name' => 'enp1s0', 'addresses' => ['10.0.0.4/24', '2001:db8:77::5/64']]]]])->save();

    vultrConnect($workspace)->assertSessionHasNoErrors()->assertRedirect();

    $integration = Integration::query()->sole();
    $rows = IntegrationServer::query()->get()->keyBy('server_id');
    expect($integration->label)->toBe('Sam Rivera')
        ->and($integration->meta)->toBe(['account' => 'Sam Rivera', 'email' => 'sam@example.com', 'servers' => 3])
        ->and($integration->credential('api_key'))->toBe(FakeVultr::KEY)
        ->and(DB::table('integrations')->value('credentials'))->not->toContain(FakeVultr::KEY)
        ->and($rows[FakeVultr::id(1)]->machine_id)->toBe($web->id)
        ->and($rows[FakeVultr::id(2)]->machine_id)->toBe($db->id)
        ->and($rows[FakeVultr::id(3)]->machine_id)->toBeNull()
        ->and($rows[FakeVultr::id(3)]->details)->toMatchArray(['ipv4' => null, 'status' => 'starting'])
        ->and($rows[FakeVultr::id(1)]->details)->toMatchArray(['type' => 'vc2-1c-2gb', 'cores' => 1, 'memoryGb' => 2, 'diskGb' => 55, 'location' => 'ams', 'city' => 'Amsterdam', 'country' => 'NL', 'status' => 'running']);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.FakeVultr::KEY));
    expect(ActivityLine::sentence(Activity::query()->where('action', 'integration.connect')->sole()))->toBe($workspace->owner->name.' connected Sam Rivera (Vultr)')
        ->and(Activity::query()->get()->toJson())->not->toContain(FakeVultr::KEY);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('integration.facts', [
                ['label' => 'Vultr account', 'value' => 'Sam Rivera'],
                ['label' => 'Email', 'value' => 'sam@example.com'],
                ['label' => 'Servers', 'value' => '3'],
            ])
            ->where('provider.fields.0.key', 'api_key')
            ->where('provider.capabilities.2.description', 'Upgrades a linked machine to a bigger plan, in place. Vultr can’t move one to a smaller plan.'));
    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$web->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cloudServer.integration.name', 'Vultr')
            ->where('cloudServer.rules', ['powerOff' => false, 'disk' => 'always', 'down' => false, 'gracefulRestart' => false, 'snapshotsPerServer' => false]));
});

test('a key Vultr turns down is never saved, and one limited to other addresses says so', function () {
    $vultr = FakeVultr::install();
    $workspace = Workspace::factory()->create();

    vultrConnect($workspace, 'WRONGKEY0123456789ABCDEFGHIJKLMNOPQR')
        ->assertSessionHasErrors(['credentials.api_key' => 'Vultr turned the API key down. Check it’s copied in full and hasn’t been deleted.']);
    $vultr->failNext('GET', 'account', 401, ['error' => 'Unauthorized IP address: 203.0.113.50', 'status' => 401]);
    vultrConnect($workspace)
        ->assertSessionHasErrors(['credentials.api_key' => 'Vultr won’t take this key from this panel’s address. Allow it under Access control in Vultr’s API settings.']);

    expect(Integration::query()->count())->toBe(0);
});

test('making an instance sends base64 cloud-init that installs ServerOS, waits for it to boot, and links it when it joins', function () {
    config()->set('daemon.install_ready', true);
    Queue::fake([AdvanceIntegrationOperation::class]);
    $vultr = FakeVultr::install();
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $integration = vultrAccount($workspace);

    $plans = $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$integration->id}/server-options"))->assertOk();
    expect(collect($plans->json('types'))->pluck('name')->all())->toBe(['vc2-1c-1gb', 'vc2-1c-2gb', 'vc2-2c-4gb', 'vc2-4c-8gb', 'voc-g-2c-8gb-50s'])
        ->and(collect($plans->json('locations'))->pluck('name')->all())->toBe(['ams', 'fra', 'ewr'])
        ->and($plans->json('locations.2'))->toBe(['name' => 'ewr', 'city' => 'New Jersey', 'country' => 'US', 'zone' => 'North America'])
        ->and($plans->json('types.0.prices.ams.monthly'))->toEqual(5)
        ->and($plans->json('types.3.locations'))->toBe(['fra', 'ewr'])
        ->and($plans->json('types.4.cpu'))->toBe('dedicated')
        ->and(collect($plans->json('images'))->firstWhere('recommended', true)['id'])->toBe('2284')
        ->and(collect($plans->json('images'))->pluck('label')->all())->toBe(['Ubuntu 25.04 x64', 'Ubuntu 24.04 LTS x64', 'Ubuntu 22.04 LTS x64', 'Debian 12 x64 (bookworm)'])
        ->and($plans->json('keys.0.name'))->toBe('sam@laptop')
        ->and($plans->json('currency'))->toBe('USD');

    $key = '3b8066a7-b438-455a-9688-44afc9a3597f';
    $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$integration->id}/servers"), [
        'name' => 'web-3', 'location' => 'ams', 'type' => 'vc2-1c-2gb', 'image' => '2284', 'keys' => [$key],
    ])->assertCreated();

    $body = $vultr->bodyOf('POST', 'instances');
    expect($body)->toMatchArray(['region' => 'ams', 'plan' => 'vc2-1c-2gb', 'os_id' => 2284, 'label' => 'web-3', 'hostname' => 'web-3', 'sshkey_id' => [$key], 'enable_ipv6' => true, 'backups' => 'disabled', 'tags' => ['serveros']]);
    $userData = (string) base64_decode((string) $body['user_data'], true);
    expect($userData)->toStartWith("#cloud-config\nhostname: web-3\n")->toContain('get.docker.com');
    preg_match('/--token=\x27([a-f0-9]{64})\x27/', $userData, $match);
    $enrolment = DaemonEnrolment::query()->sole();
    expect(hash('sha256', $match[1] ?? ''))->toBe($enrolment->token_hash);

    $operation = IntegrationOperation::query()->sole();
    $row = IntegrationServer::query()->sole();
    expect($operation->only(['kind', 'step', 'action_id']))->toBe(['kind' => 'create', 'step' => 'boot', 'action_id' => null])
        ->and($row->details['ipv4'])->toBeNull()
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_create')->sole()))->toBe($workspace->owner->name.' made the server web-3 at Vultr (vc2-1c-2gb in Amsterdam)');

    $operations = app(ServerOperations::class);
    expect($operations->advance($operation))->toBe(5)
        ->and($operation->step)->toBe('boot')
        ->and($operations->advance($operation))->toBe(20)
        ->and($operation->fresh()->step)->toBe('install')
        ->and($row->fresh()->details['ipv4'])->toStartWith('203.0.113.')
        ->and($row->fresh()->details['status'])->toBe('running');

    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-3']);
    app(CloudServers::class)->claim($enrolment, $machine);
    expect($row->fresh()->machine_id)->toBe($machine->id)
        ->and($operation->fresh()->state)->toBe('done');
});

test('an instance is upgraded in place to a bigger plan, without being turned off, and only upgrades are offered', function () {
    [$workspace, $machine, $integration, $vultr, $id] = vultrLinkedMachine();

    $types = $this->actingAs($workspace->owner)->getJson(vultrUrl($machine, '/types'))->assertOk();
    expect(collect($types->json('types'))->pluck('name')->all())->toBe(['vc2-2c-4gb'])
        ->and($types->json('current.name'))->toBe('vc2-1c-2gb')
        ->and($types->json('needsPowerOff'))->toBeFalse()
        ->and($types->json('rules'))->toMatchArray(['disk' => 'always', 'down' => false]);

    $this->actingAs($workspace->owner)->postJson(vultrUrl($machine, '/resize'), ['type' => 'vc2-1c-1gb'])
        ->assertUnprocessable()->assertJsonValidationErrors('type');
    $this->actingAs($workspace->owner)->postJson(vultrUrl($machine, '/resize'), ['type' => 'vc2-4c-8gb'])
        ->assertUnprocessable()->assertJsonValidationErrors('type');

    $vultr->calls = [];
    $this->actingAs($workspace->owner)->postJson(vultrUrl($machine, '/resize'), ['type' => 'vc2-2c-4gb'])->assertCreated();

    expect($vultr->called('PATCH'))->toBe(["PATCH instances/{$id}"])
        ->and($vultr->bodyOf('PATCH', "instances/{$id}"))->toBe(['plan' => 'vc2-2c-4gb'])
        ->and($vultr->called('POST'))->toBe([])
        ->and($vultr->instances[$id]['plan'])->toBe('vc2-2c-4gb');
    $operation = IntegrationOperation::query()->sole();
    expect($operation->state)->toBe('done')
        ->and($operation->input['grow_disk'])->toBeTrue()
        ->and(ServerOperations::props($operation)['steps'])->toBe([['key' => 'change', 'label' => 'Change the type', 'state' => 'done']])
        ->and($machine->cloudServer->details)->toMatchArray(['type' => 'vc2-2c-4gb', 'diskGb' => 80, 'status' => 'running']);
});

test('when Vultr turns an upgrade down, it fails with the reason and nothing is switched on or off', function () {
    [$workspace, $machine, $integration, $vultr, $id] = vultrLinkedMachine();
    $vultr->failNext('PATCH', "instances/{$id}", 400, ['error' => 'Server is currently locked', 'status' => 400]);

    $this->actingAs($workspace->owner)->postJson(vultrUrl($machine, '/resize'), ['type' => 'vc2-2c-4gb'])->assertCreated();

    $operation = IntegrationOperation::query()->sole();
    expect($operation->state)->toBe('failed')
        ->and($operation->error)->toBe('Vultr is busy with something else on this server. Try again in a minute.')
        ->and($vultr->called('POST'))->toBe([])
        ->and($vultr->instances[$id]['plan'])->toBe('vc2-1c-2gb');
});

test('restart reboots the instance, power cycle halts and starts it, and both need permission', function () {
    [$workspace, $machine, $integration, $vultr, $id] = vultrLinkedMachine();

    $this->actingAs($workspace->owner)->postJson(vultrUrl($machine, '/reboot'))->assertOk()
        ->assertJson(['message' => 'Restarting web-1 through Vultr.']);
    $this->actingAs($workspace->owner)->postJson(vultrUrl($machine, '/power-cycle'))->assertOk();

    expect($vultr->called('POST'))->toBe(["POST instances/{$id}/reboot", "POST instances/{$id}/halt", "POST instances/{$id}/start"])
        ->and($vultr->instances[$id]['power_status'])->toBe('running')
        ->and(Activity::query()->orderBy('id')->pluck('action')->all())->toBe(['machine.server_reboot', 'machine.server_power_cycle'])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_power_cycle')->sole()))->toBe($workspace->owner->name.' power cycled web-1 through Vultr');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson(vultrUrl($machine, '/power-cycle'))->assertForbidden();
    $other = Machine::factory()->for($workspace)->create();
    $this->actingAs($workspace->owner)->postJson(vultrUrl($other, '/reboot'))->assertNotFound();
});

test('snapshots are the account’s, newest first, and can be taken from the machine and deleted once ready', function () {
    [$workspace, $machine, $integration, $vultr, $id] = vultrLinkedMachine();
    $older = '5359435d-28b6-4ec5-ad8f-000000000001';
    $newer = '5359435d-28b6-4ec5-ad8f-000000000002';
    $vultr->snapshots[$older] = FakeVultr::snapshotOf($older, 'before upgrade', created: '2026-11-02T09:00:00+00:00');
    $vultr->snapshots[$newer] = FakeVultr::snapshotOf($newer, 'db-1 nightly', created: '2026-11-20T09:00:00+00:00');

    $this->actingAs($workspace->owner)->getJson(vultrUrl($machine, '/snapshots'))->assertOk()
        ->assertJsonPath('snapshots.0', ['id' => $newer, 'name' => 'db-1 nightly', 'status' => 'available', 'sizeGb' => 2, 'diskGb' => 50, 'createdAt' => '2026-11-20T09:00:00+00:00'])
        ->assertJsonPath('snapshots.1.id', $older);

    $taken = $this->actingAs($workspace->owner)->postJson(vultrUrl($machine, '/snapshots'), ['name' => 'before resize'])->assertCreated();
    expect($taken->json('snapshot.status'))->toBe('creating')
        ->and($vultr->bodyOf('POST', 'snapshots'))->toBe(['instance_id' => $id, 'description' => 'before resize']);

    $this->actingAs($workspace->owner)->deleteJson(vultrUrl($machine, '/snapshots/'.$taken->json('snapshot.id')))
        ->assertJsonValidationErrors(['form' => 'It’s still being taken. Delete it once it’s ready.']);
    $this->actingAs($workspace->owner)->deleteJson(vultrUrl($machine, '/snapshots/'.$older))->assertOk();

    expect($vultr->called('DELETE'))->toBe(["DELETE snapshots/{$older}"])
        ->and(array_keys($vultr->snapshots))->toHaveCount(2)
        ->and(Activity::query()->orderBy('id')->pluck('action')->all())->toBe(['machine.snapshot_create', 'machine.snapshot_delete']);
});

test('deleting the server destroys the instance and can keep the machine', function () {
    [$workspace, $machine, $integration, $vultr, $id] = vultrLinkedMachine();

    $this->actingAs($workspace->owner)->delete(vultrUrl($machine, ''), ['confirm' => 'web-2'])->assertSessionHasErrors('confirm');
    $this->actingAs($workspace->owner)->delete(vultrUrl($machine, ''), ['confirm' => 'web-1', 'keep' => true])->assertRedirect();

    expect($vultr->called('DELETE'))->toBe(["DELETE instances/{$id}"])
        ->and($vultr->instances)->toBe([])
        ->and($machine->fresh())->not->toBeNull()
        ->and(IntegrationServer::query()->count())->toBe(0)
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_delete')->sole()))->toBe($workspace->owner->name.' deleted the server of web-1 at Vultr, and kept the machine');
});

test('busy Vultr reads are tried again, but a POST is never sent twice after a server error', function () {
    [$workspace, $machine, $integration, $vultr, $id] = vultrLinkedMachine();

    $vultr->failNext('GET', 'instances', 429, ['error' => 'Rate limit reached', 'status' => 429], ['Retry-After' => '1']);
    $vultr->failNext('GET', 'instances', 503);
    expect(app(CloudServers::class)->sync($integration)->count())->toBe(1)
        ->and($vultr->called('GET'))->toBe(['GET instances', 'GET instances', 'GET instances']);
    Sleep::assertSleptTimes(2);

    $vultr->calls = [];
    $vultr->failNext('POST', "instances/{$id}/reboot", 500);
    $this->actingAs($workspace->owner)->postJson(vultrUrl($machine, '/reboot'))
        ->assertJsonValidationErrors(['form' => 'Vultr isn’t answering properly right now. Try again in a few minutes.']);
    expect($vultr->called('POST'))->toBe(["POST instances/{$id}/reboot"]);
});
