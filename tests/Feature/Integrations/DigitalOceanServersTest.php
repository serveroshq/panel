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
use Tests\Support\FakeDigitalOcean;

function oceanTeam(Workspace $workspace): Integration
{
    return $workspace->integrations()->create([
        'provider' => 'digitalocean',
        'label' => 'Acme',
        'credentials' => ['token' => FakeDigitalOcean::TOKEN],
        'fingerprint' => Integration::fingerprintOf('digitalocean', ['token' => FakeDigitalOcean::TOKEN]),
        'meta' => ['team' => 'Acme', 'servers' => 0],
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
    ]);
}

function oceanConnect(Workspace $workspace, string $token = FakeDigitalOcean::TOKEN): TestResponse
{
    return test()->actingAs($workspace->owner)
        ->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/digitalocean'), ['credentials' => ['token' => $token]]);
}

/** @return array{0: Workspace, 1: Machine, 2: Integration, 3: FakeDigitalOcean} */
function oceanLinkedMachine(): array
{
    $do = FakeDigitalOcean::install()->droplet(101, 'web-1', '198.51.100.7', '2001:db8:101::1');
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);
    $integration = oceanTeam($workspace);
    app(CloudServers::class)->sync($integration);
    $do->calls = [];

    return [$workspace, $machine, $integration, $do];
}

function oceanUrl(Machine $machine, string $path): string
{
    return $machine->workspace->url("/machines/{$machine->id}/server{$path}");
}

beforeEach(function () {
    Sleep::fake();
});

test('connecting DigitalOcean checks the token, names the account after the team and links droplets by address', function () {
    FakeDigitalOcean::install()
        ->droplet(101, 'web-1', '198.51.100.7')
        ->droplet(102, 'v6-only', null, '2001:db8:66::1')
        ->droplet(103, 'spare', '198.51.100.9');
    $workspace = Workspace::factory()->create();
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1', 'ip_address' => '10.0.0.4']);
    $db->forceFill(['facts' => ['interfaces' => [['name' => 'eth0', 'addresses' => ['10.0.0.4/24', '2001:db8:66::1/64']]]]])->save();

    oceanConnect($workspace)->assertSessionHasNoErrors()->assertRedirect();

    $integration = Integration::query()->sole();
    $rows = IntegrationServer::query()->get()->keyBy('server_id');
    expect($integration->label)->toBe('Acme')
        ->and($integration->meta)->toBe(['team' => 'Acme', 'email' => 'sam@example.com', 'servers' => 3, 'droplet_limit' => 25])
        ->and(DB::table('integrations')->value('credentials'))->not->toContain(FakeDigitalOcean::TOKEN)
        ->and($rows['101']->machine_id)->toBe($web->id)
        ->and($rows['102']->machine_id)->toBe($db->id)
        ->and($rows['103']->machine_id)->toBeNull()
        ->and($rows['101']->details)->toMatchArray(['type' => 's-1vcpu-2gb', 'cores' => 1, 'memoryGb' => 2, 'diskGb' => 50, 'location' => 'ams3', 'city' => 'Amsterdam', 'country' => 'NL', 'ipv4' => '198.51.100.7', 'status' => 'running']);
    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.FakeDigitalOcean::TOKEN));
    expect(ActivityLine::sentence(Activity::query()->where('action', 'integration.connect')->sole()))->toBe($workspace->owner->name.' connected Acme (DigitalOcean)')
        ->and(Activity::query()->get()->toJson())->not->toContain(FakeDigitalOcean::TOKEN);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('integration.facts', [
                ['label' => 'Team', 'value' => 'Acme'],
                ['label' => 'Email', 'value' => 'sam@example.com'],
                ['label' => 'Droplets', 'value' => '3'],
                ['label' => 'Droplet limit', 'value' => '25'],
            ])
            ->where('provider.fields.0.secret', true)
            ->where('provider.capabilities.2.description', 'Moves a linked machine to another droplet size. Change only CPU and RAM to keep the way back, or grow the disk for good.'));
    $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$web->id}"))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cloudServer.integration.name', 'DigitalOcean')
            ->where('cloudServer.rules', ['powerOff' => true, 'disk' => 'optional', 'down' => true, 'gracefulRestart' => true, 'snapshotsPerServer' => true]));
});

test('a token DigitalOcean turns down is never saved', function () {
    FakeDigitalOcean::install();
    $workspace = Workspace::factory()->create();

    oceanConnect($workspace, 'wrong-digitalocean-token-for-tests')
        ->assertSessionHasErrors(['credentials.token' => 'DigitalOcean turned the token down. Check it’s copied in full and hasn’t been deleted.']);

    expect(Integration::query()->count())->toBe(0);
});

test('making a droplet sends a cloud-init that installs ServerOS, follows its create action, and links it when it joins', function () {
    config()->set('daemon.install_ready', true);
    Queue::fake([AdvanceIntegrationOperation::class]);
    $do = FakeDigitalOcean::install();
    $do->actionPolls = 1;
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $integration = oceanTeam($workspace);

    $plans = $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$integration->id}/server-options"))->assertOk();
    expect(collect($plans->json('types'))->pluck('name')->all())->toBe(['s-1vcpu-1gb', 's-1vcpu-2gb', 's-2vcpu-4gb', 'g-2vcpu-8gb'])
        ->and(collect($plans->json('locations'))->pluck('name')->all())->toBe(['ams3', 'fra1'])
        ->and($plans->json('locations.0'))->toBe(['name' => 'ams3', 'city' => 'Amsterdam', 'country' => 'NL', 'zone' => null])
        ->and($plans->json('types.1.prices.fra1'))->toEqual(['monthly' => 12, 'hourly' => 0.01786])
        ->and($plans->json('types.3.cpu'))->toBe('dedicated')
        ->and($plans->json('types.3.locations'))->toBe(['ams3'])
        ->and(collect($plans->json('images'))->firstWhere('recommended', true)['id'])->toBe('ubuntu-24-04-x64')
        ->and(collect($plans->json('images'))->pluck('os')->unique()->values()->all())->toBe(['ubuntu', 'debian'])
        ->and($plans->json('keys.0'))->toBe(['id' => '512190', 'name' => 'sam@laptop', 'fingerprint' => '3b:16:bf:e4'])
        ->and($plans->json('currency'))->toBe('USD');

    $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$integration->id}/servers"), [
        'name' => 'web-3', 'location' => 'fra1', 'type' => 'g-2vcpu-8gb', 'image' => 'ubuntu-24-04-x64',
    ])->assertUnprocessable()->assertJsonValidationErrors(['type' => 'That server type can’t be made in this location. Pick another.']);

    $this->actingAs($workspace->owner)->postJson($workspace->url("/integrations/{$integration->id}/servers"), [
        'name' => 'web-3', 'location' => 'ams3', 'type' => 's-1vcpu-2gb', 'image' => 'ubuntu-24-04-x64', 'keys' => ['512190'],
    ])->assertCreated();

    $body = $do->bodyOf('POST', 'droplets');
    expect($body)->toMatchArray(['name' => 'web-3', 'region' => 'ams3', 'size' => 's-1vcpu-2gb', 'image' => 'ubuntu-24-04-x64', 'ssh_keys' => [512190], 'ipv6' => true, 'backups' => false])
        ->and($body['user_data'])->toStartWith("#cloud-config\nhostname: web-3\n")
        ->and($body['user_data'])->toContain('get.docker.com');
    preg_match('/--token=\x27([a-f0-9]{64})\x27/', $body['user_data'], $match);
    $enrolment = DaemonEnrolment::query()->sole();
    expect(hash('sha256', $match[1] ?? ''))->toBe($enrolment->token_hash);

    $operation = IntegrationOperation::query()->sole();
    $row = IntegrationServer::query()->sole();
    expect($operation->only(['kind', 'step', 'server_id']))->toBe(['kind' => 'create', 'step' => 'boot', 'server_id' => '7000'])
        ->and($operation->action_id)->not->toBeNull()
        ->and($row->daemon_enrolment_id)->toBe($enrolment->id)
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_create')->sole()))->toBe($workspace->owner->name.' made the server web-3 at DigitalOcean (s-1vcpu-2gb in Amsterdam)');

    $operations = app(ServerOperations::class);
    expect($operations->advance($operation))->toBe(5)
        ->and($operation->step)->toBe('boot')
        ->and($operations->advance($operation))->toBe(20)
        ->and($operation->fresh()->step)->toBe('install')
        ->and($row->fresh()->details['ipv4'])->toBe('203.0.113.0')
        ->and($row->fresh()->details['ipv6'])->toBe('2001:db8:1b58::1/124');

    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-3']);
    app(CloudServers::class)->claim($enrolment, $machine);
    expect($row->fresh()->machine_id)->toBe($machine->id)
        ->and($operation->fresh()->state)->toBe('done');
});

test('a CPU and RAM resize shuts the droplet down, keeps its disk so it can go back down, and starts it again', function () {
    [$workspace, $machine, $integration, $do] = oceanLinkedMachine();
    $do->actionPolls = 1;

    $types = $this->actingAs($workspace->owner)->getJson(oceanUrl($machine, '/types'))->assertOk();
    expect(collect($types->json('types'))->pluck('name')->all())->toBe(['s-2vcpu-4gb'])
        ->and($types->json('current.name'))->toBe('s-1vcpu-2gb')
        ->and($types->json('types.0.monthly'))->toEqual(24)
        ->and($types->json('needsPowerOff'))->toBeTrue()
        ->and($types->json('rules.disk'))->toBe('optional');

    $this->actingAs($workspace->owner)->postJson(oceanUrl($machine, '/resize'), ['type' => 's-1vcpu-1gb'])
        ->assertUnprocessable()->assertJsonValidationErrors(['type' => 'This server can’t move to that type. Pick another from the list.']);

    $do->calls = [];
    $this->actingAs($workspace->owner)->postJson(oceanUrl($machine, '/resize'), ['type' => 's-2vcpu-4gb'])->assertCreated();

    expect($do->actionsAsked())->toBe(['shutdown', 'resize', 'power_on'])
        ->and($do->bodyOf('POST', 'droplets/101/actions', 'resize'))->toBe(['type' => 'resize', 'size' => 's-2vcpu-4gb', 'disk' => false])
        ->and($do->droplets[101]['status'])->toBe('active')
        ->and($do->droplets[101]['disk'])->toBe(50);
    $operation = IntegrationOperation::query()->sole();
    expect($operation->state)->toBe('done')
        ->and(collect(ServerOperations::props($operation)['steps'])->pluck('key')->all())->toBe(['stop', 'change', 'start'])
        ->and($machine->cloudServer->details)->toMatchArray(['type' => 's-2vcpu-4gb', 'cores' => 2, 'diskGb' => 50])
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_resize')->sole()))->toBe($workspace->owner->name.' started resizing web-1 from s-1vcpu-2gb to s-2vcpu-4gb');

    $back = $this->actingAs($workspace->owner)->getJson(oceanUrl($machine, '/types'))->assertOk();
    expect(collect($back->json('types'))->pluck('name')->all())->toBe(['s-1vcpu-2gb']);
});

test('growing the disk is for good: smaller sizes are no longer offered', function () {
    [$workspace, $machine, $integration, $do] = oceanLinkedMachine();

    $this->actingAs($workspace->owner)->postJson(oceanUrl($machine, '/resize'), ['type' => 's-2vcpu-4gb', 'grow_disk' => true])->assertCreated();

    expect($do->bodyOf('POST', 'droplets/101/actions', 'resize'))->toBe(['type' => 'resize', 'size' => 's-2vcpu-4gb', 'disk' => true])
        ->and($do->droplets[101]['disk'])->toBe(80)
        ->and(IntegrationOperation::query()->sole()->state)->toBe('done');
    $this->actingAs($workspace->owner)->getJson(oceanUrl($machine, '/types'))->assertOk()->assertJsonPath('types', []);
});

test('when DigitalOcean can’t resize, the droplet is started again and the reason is kept', function () {
    [$workspace, $machine, $integration, $do] = oceanLinkedMachine();
    $do->failActions = 'resize';

    $this->actingAs($workspace->owner)->postJson(oceanUrl($machine, '/resize'), ['type' => 's-2vcpu-4gb'])->assertCreated();

    $operation = IntegrationOperation::query()->sole();
    expect($operation->state)->toBe('failed')
        ->and($operation->error)->toBe('The type wasn’t changed: DigitalOcean couldn’t finish it. ServerOS turned it back on.')
        ->and(array_slice($do->actionsAsked(), -2))->toBe(['resize', 'power_on'])
        ->and($do->droplets[101]['size_slug'])->toBe('s-1vcpu-2gb');
});

test('restart and power cycle are droplet actions, need permission and are recorded', function () {
    [$workspace, $machine, $integration, $do] = oceanLinkedMachine();

    $this->actingAs($workspace->owner)->postJson(oceanUrl($machine, '/reboot'))->assertOk()
        ->assertJson(['message' => 'Restarting web-1 through DigitalOcean.']);
    $this->actingAs($workspace->owner)->postJson(oceanUrl($machine, '/power-cycle'))->assertOk();

    expect($do->actionsAsked())->toBe(['reboot', 'power_cycle'])
        ->and(Activity::query()->orderBy('id')->pluck('action')->all())->toBe(['machine.server_reboot', 'machine.server_power_cycle']);

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson(oceanUrl($machine, '/reboot'))->assertForbidden();

    $do->failNext('POST', 'droplets/101/actions', 403, ['id' => 'forbidden', 'message' => 'You are not authorized to perform this operation']);
    $this->actingAs($workspace->owner)->postJson(oceanUrl($machine, '/reboot'))
        ->assertJsonValidationErrors(['form' => 'DigitalOcean says this token can’t do that. Make a token with Full Access, or with write scopes for droplets, and connect it again.']);
    $do->failNext('POST', 'droplets/101/actions', 422, ['id' => 'unprocessable_entity', 'message' => 'Droplet already has a pending event.']);
    $this->actingAs($workspace->owner)->postJson(oceanUrl($machine, '/reboot'))
        ->assertJsonValidationErrors(['form' => 'DigitalOcean is busy with another action on this droplet. Try again in a minute.']);
    expect(Activity::query()->count())->toBe(2);
});

test('snapshots list the droplet’s own, show one being taken, and only finished ones can be deleted', function () {
    [$workspace, $machine, $integration, $do] = oceanLinkedMachine();
    $do->snapshots[880] = FakeDigitalOcean::snapshotOf(880, 'before upgrade', 101, created: '2026-11-20T10:00:00Z');
    $do->snapshots[881] = FakeDigitalOcean::snapshotOf(881, 'someone else', 999);

    $this->actingAs($workspace->owner)->getJson(oceanUrl($machine, '/snapshots'))->assertOk()
        ->assertJsonPath('snapshots', [['id' => '880', 'name' => 'before upgrade', 'status' => 'available', 'sizeGb' => 2.36, 'diskGb' => 50, 'createdAt' => '2026-11-20T10:00:00Z']]);

    $taken = $this->actingAs($workspace->owner)->postJson(oceanUrl($machine, '/snapshots'), ['name' => 'before resize'])->assertCreated();
    $pending = (string) $taken->json('snapshot.id');
    expect($taken->json('snapshot.status'))->toBe('creating')
        ->and($pending)->toStartWith('action-')
        ->and($do->bodyOf('POST', 'droplets/101/actions', 'snapshot'))->toBe(['type' => 'snapshot', 'name' => 'before resize']);

    $list = $this->actingAs($workspace->owner)->getJson(oceanUrl($machine, '/snapshots'))->assertOk();
    expect(collect($list->json('snapshots'))->pluck('id')->all())->toBe([$pending, '880']);
    $this->actingAs($workspace->owner)->deleteJson(oceanUrl($machine, '/snapshots/'.$pending))
        ->assertJsonValidationErrors(['form' => 'It’s still being taken. Delete it once it’s ready.']);

    $do->settle();
    $list = $this->actingAs($workspace->owner)->getJson(oceanUrl($machine, '/snapshots'))->assertOk();
    expect(collect($list->json('snapshots'))->pluck('name')->all())->toBe(['before resize', 'before upgrade']);

    $this->actingAs($workspace->owner)->deleteJson(oceanUrl($machine, '/snapshots/881'))->assertNotFound();
    $this->actingAs($workspace->owner)->deleteJson(oceanUrl($machine, '/snapshots/880'))->assertOk();
    expect($do->called('DELETE'))->toBe(['DELETE snapshots/880'])
        ->and(isset($do->snapshots[881]))->toBeTrue()
        ->and(Activity::query()->orderBy('id')->pluck('action')->all())->toBe(['machine.snapshot_create', 'machine.snapshot_delete']);
});

test('deleting the server deletes the droplet and the machine', function () {
    [$workspace, $machine, $integration, $do] = oceanLinkedMachine();

    $this->actingAs($workspace->owner)->delete(oceanUrl($machine, ''), ['confirm' => 'web-1'])
        ->assertRedirect(route('workspace.machines.index', $workspace->slug));

    expect($do->called('DELETE'))->toBe(['DELETE droplets/101'])
        ->and($do->droplets)->toBe([])
        ->and(Machine::query()->find($machine->id))->toBeNull()
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'machine.server_delete')->sole()))->toBe($workspace->owner->name.' deleted the server of web-1 at DigitalOcean, and removed the machine');
});

test('busy DigitalOcean reads are tried again, but an action is never sent twice after a server error', function () {
    [$workspace, $machine, $integration, $do] = oceanLinkedMachine();

    $do->failNext('GET', 'droplets', 429, ['id' => 'too_many_requests', 'message' => 'API Rate limit exceeded.'], ['ratelimit-reset' => (string) (time() + 1)]);
    $do->failNext('GET', 'droplets', 502);
    expect(app(CloudServers::class)->sync($integration)->count())->toBe(1)
        ->and($do->called('GET'))->toBe(['GET droplets', 'GET droplets', 'GET droplets']);
    Sleep::assertSleptTimes(2);

    $do->calls = [];
    $do->failNext('POST', 'droplets/101/actions', 503);
    $this->actingAs($workspace->owner)->postJson(oceanUrl($machine, '/reboot'))
        ->assertJsonValidationErrors(['form' => 'DigitalOcean isn’t answering properly right now. Try again in a few minutes.']);
    expect($do->called('POST'))->toBe(['POST droplets/101/actions']);
});
