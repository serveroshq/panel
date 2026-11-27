<?php

use App\Models\Activity;
use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Models\IntegrationIncident;
use App\Models\Machine;
use App\Models\MachineVulnerability;
use App\Models\Monitor;
use App\Models\User;
use App\Models\Vulnerability;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Health\MachineWatcher;
use App\Support\Integrations\Incidents\IncidentRouter;
use App\Support\Monitors\MonitorRunner;
use App\Support\Monitors\ProbeResult;
use App\Support\Security\VulnerabilityAlerts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeLinear;
use Tests\Support\FakePagerDuty;

beforeEach(function () {
    Sleep::fake();
    Notification::fake();
});

function irAccount(Workspace $workspace, string $provider, array $settings = [], string $label = 'On call'): Integration
{
    $credentials = $provider === 'pagerduty' ? ['routing_key' => FakePagerDuty::KEY] : ['api_key' => FakeLinear::KEY];

    return $workspace->integrations()->create([
        'provider' => $provider,
        'label' => $label,
        'credentials' => $credentials,
        'fingerprint' => Integration::fingerprintOf($provider, $credentials),
        'meta' => $provider === 'linear' ? ['organization' => 'Acme', 'viewer' => 'Dana Lee', 'teams' => 2] : ['checked' => 'format'],
        'settings' => $settings === [] ? null : $settings,
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
    ]);
}

function irLinearSettings(array $overrides = []): array
{
    return [
        'team' => ['id' => 'team-1', 'key' => 'ENG', 'name' => 'Engineering'],
        'project' => null,
        'label' => null,
        'done' => ['id' => 'state-done', 'name' => 'Done'],
        'monitors' => true,
        'minutes' => 10,
        'machines' => true,
        'cves' => true,
        ...$overrides,
    ];
}

function irMonitor(Workspace $workspace, array $overrides = []): Monitor
{
    return $workspace->monitors()->create([
        'name' => 'Shop', 'type' => 'http', 'url' => 'https://shop.example.com/',
        'interval_seconds' => 60, 'timeout_seconds' => 10, 'status' => 'up', 'alerts' => true,
        ...$overrides,
    ]);
}

function irDown(Monitor $monitor): Monitor
{
    foreach (range(1, Monitor::CONFIRM_AFTER) as $_) {
        app(MonitorRunner::class)->apply($monitor->fresh('workspace'), ProbeResult::fail('Connection refused'));
    }

    return $monitor->fresh();
}

function irUp(Monitor $monitor): Monitor
{
    app(MonitorRunner::class)->apply($monitor->fresh('workspace'), new ProbeResult(true, 120, 200));

    return $monitor->fresh();
}

function irMachine(Workspace $workspace, string $name = 'web-1'): Machine
{
    $machine = Machine::factory()->for($workspace)->create(['name' => $name, 'environment' => 'production']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();

    return $machine;
}

function irCve(Machine $machine, string $cve, string $severity = 'critical', bool $exploited = true): MachineVulnerability
{
    $vulnerability = Vulnerability::query()->firstOrCreate(['cve' => $cve], ['severity' => $severity, 'exploited' => $exploited, 'title' => 'OpenSSL can be made to run code', 'cvss' => 9.8]);

    return MachineVulnerability::query()->create(['machine_id' => $machine->id, 'vulnerability_id' => $vulnerability->id, 'source' => 'openssl', 'packages' => ['openssl'], 'installed' => '3.0.13-0ubuntu3.4', 'fixed_by' => '3.0.13-0ubuntu3.5', 'detected_at' => now()]);
}

function irMember(Workspace $workspace, string $role): User
{
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => $role]);

    return $user;
}

test('PagerDuty connects with an integration key it only checks the shape of, kept encrypted', function () {
    $pagerduty = FakePagerDuty::install();
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/pagerduty'), ['label' => 'Platform on call', 'credentials' => ['routing_key' => 'too-short']])
        ->assertSessionHasErrors('credentials.routing_key');
    $this->actingAs($workspace->owner)->post($workspace->url('/integrations/connect/pagerduty'), ['label' => 'Platform on call', 'credentials' => ['routing_key' => FakePagerDuty::KEY]])
        ->assertSessionHasNoErrors()->assertRedirect();

    $integration = Integration::query()->sole();
    expect($integration->label)->toBe('Platform on call')
        ->and($integration->credential('routing_key'))->toBe(FakePagerDuty::KEY)
        ->and(DB::table('integrations')->value('credentials'))->not->toContain(FakePagerDuty::KEY)
        ->and($pagerduty->events)->toBe([]);

    $show = $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('routing.monitors', 'all')
            ->where('routing.machines', true)
            ->where('routing.cves', true)
            ->where('canTest', true)
            ->where('events', [])
            ->where('provider.capabilities.0.key', 'pages'));
    expect($show->getContent())->not->toContain(FakePagerDuty::KEY);
});

test('a test event opens a PagerDuty alert and resolves it at once, and says when the key is wrong', function () {
    $pagerduty = FakePagerDuty::install();
    $workspace = Workspace::factory()->create();
    $integration = irAccount($workspace, 'pagerduty');

    $this->actingAs($workspace->owner)->post($workspace->url("/integrations/{$integration->id}/test-event"))->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'success');

    expect($pagerduty->sent())->toHaveCount(2)
        ->and($pagerduty->events[0]['event_action'])->toBe('trigger')
        ->and($pagerduty->events[1]['event_action'])->toBe('resolve')
        ->and($pagerduty->events[0]['dedup_key'])->toBe($pagerduty->events[1]['dedup_key'])
        ->and($pagerduty->open)->toBe([])
        ->and(IntegrationEvent::query()->sole()->state)->toBe('sent');
    $activity = Activity::query()->where('action', 'integration.test')->sole();
    expect(ActivityLine::sentence($activity))->toBe($workspace->owner->name.' sent a test event to On call');

    $pagerduty->failNext(503);
    $this->actingAs($workspace->owner)->post($workspace->url("/integrations/{$integration->id}/test-event"))->assertRedirect()
        ->assertSessionHas('inertia.flash_data.toast.type', 'error');
    expect(IntegrationEvent::query()->latest('id')->first()->state)->toBe('failed');

    $integration->forceFill(['credentials' => ['routing_key' => str_repeat('Z', 32)]])->save();
    $this->actingAs($workspace->owner)->post($workspace->url("/integrations/{$integration->id}/test-event"))
        ->assertSessionHas('inertia.flash_data.toast.message', 'PagerDuty doesn’t know this routing key. Copy the Integration Key from the service’s Events API v2 integration and connect it again.');
    expect($integration->fresh()->status)->toBe(Integration::FAILING);
});

test('a monitor going down pages once with a stable dedup key and a link back, and resolves when it’s up', function () {
    $pagerduty = FakePagerDuty::install();
    $workspace = Workspace::factory()->create();
    $integration = irAccount($workspace, 'pagerduty');
    $monitor = irMonitor($workspace);
    $dedup = "serveros:{$workspace->id}:monitor:{$monitor->id}";

    irDown($monitor);
    app(IncidentRouter::class)->sweep();
    app(IncidentRouter::class)->sweep();

    expect($pagerduty->sent())->toBe(["trigger {$dedup}"]);
    $trigger = $pagerduty->events[0];
    expect($trigger['payload']['summary'])->toBe('Shop is down')
        ->and($trigger['payload']['severity'])->toBe('critical')
        ->and($trigger['payload']['source'])->toBe('https://shop.example.com/')
        ->and($trigger['payload']['custom_details']['details'])->toContain('Connection refused')
        ->and($trigger['links'][0]['href'])->toBe($workspace->url('/monitors/'.$monitor->id))
        ->and($trigger['client'])->toBe('ServerOS')
        ->and(IntegrationIncident::query()->whereNotNull('open_key')->count())->toBe(1);

    irUp($monitor);
    expect($pagerduty->sent())->toBe(["trigger {$dedup}", "resolve {$dedup}"])
        ->and($pagerduty->open)->toBe([])
        ->and(IntegrationIncident::query()->sole()->resolved_at)->not->toBeNull();

    irDown($monitor);
    expect($pagerduty->sent())->toBe(["trigger {$dedup}", "resolve {$dedup}", "trigger {$dedup}"])
        ->and(IntegrationIncident::query()->count())->toBe(2);

    $lines = Activity::query()->whereIn('action', ['integration.incident_open', 'integration.incident_resolve'])->oldest('id')->get()->map(fn ($a) => ActivityLine::sentence($a))->all();
    expect($lines)->toBe(['ServerOS opened Shop is down in PagerDuty', 'ServerOS closed Shop is down in PagerDuty', 'ServerOS opened Shop is down in PagerDuty']);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page
            ->has('events', 3)
            ->where('events.0.label', 'Triggered')
            ->where('events.0.state', 'sent')
            ->where('events.1.label', 'Resolved')
            ->has('incidents', 1)
            ->where('incidents.0.title', 'Shop is down'));
});

test('PagerDuty only pages for what was picked, and never for muted monitors', function () {
    $pagerduty = FakePagerDuty::install();
    $workspace = Workspace::factory()->create();
    $integration = irAccount($workspace, 'pagerduty');
    $shop = irMonitor($workspace);
    $api = irMonitor($workspace, ['name' => 'API', 'url' => 'https://api.example.com/']);
    $muted = irMonitor($workspace, ['name' => 'Blog', 'url' => 'https://blog.example.com/', 'muted_until' => now()->addHour()]);

    $this->actingAs($workspace->owner)->put($workspace->url("/integrations/{$integration->id}/routing"), [
        'monitors' => 'some', 'monitor_ids' => [$api->id, 999999], 'machines' => false, 'cves' => true,
    ])->assertSessionHasNoErrors()->assertRedirect();
    expect($integration->fresh()->settings)->toBe(['monitors' => 'some', 'monitor_ids' => [$api->id], 'machines' => false, 'cves' => true]);
    $activity = Activity::query()->where('action', 'integration.routing')->sole();
    expect(ActivityLine::sentence($activity))->toBe($workspace->owner->name.' changed what goes to On call (PagerDuty)');

    irDown($shop);
    irDown($api);
    irDown($muted);
    expect($pagerduty->sent())->toBe(["trigger serveros:{$workspace->id}:monitor:{$api->id}"]);

    $machine = irMachine($workspace);
    $machine->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
    app(MachineWatcher::class)->check($machine->fresh('workspace'));
    expect($pagerduty->sent())->toHaveCount(1);

    $this->actingAs($workspace->owner)->put($workspace->url("/integrations/{$integration->id}/routing"), [
        'monitors' => 'some', 'monitor_ids' => [$api->id], 'machines' => false, 'cves' => true,
    ])->assertRedirect();
    expect(Activity::query()->where('action', 'integration.routing')->count())->toBe(1);
});

test('a machine that stops reporting pages, and resolves when it reports again', function () {
    $pagerduty = FakePagerDuty::install();
    $workspace = Workspace::factory()->create();
    irAccount($workspace, 'pagerduty');
    $machine = irMachine($workspace);
    $dedup = "serveros:{$workspace->id}:machine:{$machine->id}";

    $machine->forceFill(['last_seen_at' => now()->subMinutes(6)])->save();
    app(MachineWatcher::class)->check($machine->fresh('workspace'));
    app(MachineWatcher::class)->check($machine->fresh('workspace'));
    expect($pagerduty->sent())->toBe(["trigger {$dedup}"])
        ->and($pagerduty->events[0]['payload']['summary'])->toBe('web-1 stopped reporting')
        ->and($pagerduty->events[0]['links'][0]['href'])->toBe($workspace->url('/machines/'.$machine->id));

    $machine->forceFill(['last_seen_at' => now()])->save();
    app(MachineWatcher::class)->check($machine->fresh('workspace'));
    expect($pagerduty->sent())->toBe(["trigger {$dedup}", "resolve {$dedup}"]);
});

test('PagerDuty pages for critical CVEs being exploited, not other ones, and resolves when they’re fixed', function () {
    $pagerduty = FakePagerDuty::install();
    $workspace = Workspace::factory()->create();
    irAccount($workspace, 'pagerduty');
    $machine = irMachine($workspace);
    $exploited = irCve($machine, 'CVE-2026-1001');
    irCve($machine, 'CVE-2026-1002', exploited: false);

    app(VulnerabilityAlerts::class)->send();
    app(IncidentRouter::class)->sweep();
    $dedup = "serveros:{$workspace->id}:cve:CVE-2026-1001";
    expect($pagerduty->sent())->toBe(["trigger {$dedup}"])
        ->and($pagerduty->events[0]['payload']['summary'])->toBe('CVE-2026-1001 on web-1')
        ->and($pagerduty->events[0]['payload']['custom_details']['details'])->toContain('Fixed by openssl 3.0.13-0ubuntu3.5.');

    $second = irCve(irMachine($workspace, 'web-2'), 'CVE-2026-1001');
    app(IncidentRouter::class)->sweep();
    app(IncidentRouter::class)->sweep();
    expect($pagerduty->sent())->toBe(["trigger {$dedup}", "trigger {$dedup}"])
        ->and($pagerduty->events[1]['payload']['summary'])->toBe('CVE-2026-1001 on web-1 and web-2')
        ->and(IntegrationEvent::query()->latest('id')->first()->action)->toBe('update');

    $exploited->forceFill(['resolved_at' => now()])->save();
    app(IncidentRouter::class)->sweep();
    expect($pagerduty->sent())->toHaveCount(3)
        ->and($pagerduty->events[2]['payload']['summary'])->toBe('CVE-2026-1001 on web-2');
    $second->forceFill(['resolved_at' => now()])->save();
    app(IncidentRouter::class)->sweep();
    expect($pagerduty->sent())->toBe(["trigger {$dedup}", "trigger {$dedup}", "trigger {$dedup}", "resolve {$dedup}"]);
});

test('a page PagerDuty can’t take is tried again later, and the resolve waits for it', function () {
    $pagerduty = FakePagerDuty::install();
    $workspace = Workspace::factory()->create();
    $integration = irAccount($workspace, 'pagerduty');
    $monitor = irMonitor($workspace);

    $pagerduty->failNext(500);
    irDown($monitor);
    $trigger = IntegrationEvent::query()->sole();
    expect($trigger->state)->toBe('retrying')
        ->and($trigger->attempts)->toBe(1)
        ->and($trigger->error)->toBe('PagerDuty isn’t answering properly right now. Try again in a few minutes.')
        ->and($trigger->next_attempt_at->isFuture())->toBeTrue();

    irUp($monitor);
    expect(IntegrationEvent::query()->latest('id')->first()->state)->toBe('queued')
        ->and(collect($pagerduty->events)->pluck('event_action')->all())->toBe(['trigger']);

    $this->travel(2)->minutes();
    app(IncidentRouter::class)->sweep();
    $dedup = "serveros:{$workspace->id}:monitor:{$monitor->id}";
    expect(collect($pagerduty->sent())->slice(1)->values()->all())->toBe(["trigger {$dedup}", "resolve {$dedup}"])
        ->and(IntegrationEvent::query()->pluck('state')->all())->toBe(['sent', 'sent']);

    $pagerduty->failNext(500, times: 5);
    $this->travel(5)->minutes();
    irDown($monitor);
    foreach ([2, 6, 16, 61] as $minutes) {
        $this->travel($minutes)->minutes();
        app(IncidentRouter::class)->sweep();
    }
    $failed = IntegrationEvent::query()->latest('id')->first();
    expect($failed->state)->toBe('failed')->and($failed->attempts)->toBe(5);

    $this->actingAs($workspace->owner)->post($workspace->url("/integrations/{$integration->id}/events/{$failed->id}/retry"))->assertRedirect();
    expect($failed->fresh()->state)->toBe('sent')
        ->and(Activity::query()->where('action', 'integration.retry')->count())->toBe(1);
});

test('only people who manage machines change what pages, send tests or retry, and only in their workspace', function () {
    FakePagerDuty::install();
    $workspace = Workspace::factory()->create();
    $integration = irAccount($workspace, 'pagerduty');
    $viewer = irMember($workspace, 'viewer');
    $other = Workspace::factory()->create();
    $event = $integration->events()->create(['action' => 'open', 'subject' => 'Shop is down', 'state' => 'failed']);
    $body = ['monitors' => 'none', 'machines' => false, 'cves' => false];

    $this->actingAs($viewer)->put($workspace->url("/integrations/{$integration->id}/routing"), $body)->assertForbidden();
    $this->actingAs($viewer)->post($workspace->url("/integrations/{$integration->id}/test-event"))->assertForbidden();
    $this->actingAs($viewer)->post($workspace->url("/integrations/{$integration->id}/events/{$event->id}/retry"))->assertForbidden();
    $this->actingAs($viewer)->get($workspace->url("/integrations/{$integration->id}"))->assertForbidden();
    $this->actingAs($other->owner)->put($other->url("/integrations/{$integration->id}/routing"), $body)->assertNotFound();

    expect($integration->fresh()->settings)->toBeNull()
        ->and(Activity::query()->where('action', 'like', 'integration.%')->count())->toBe(0);
});

test('disconnecting PagerDuty resolves the alerts ServerOS left open', function () {
    $pagerduty = FakePagerDuty::install();
    $workspace = Workspace::factory()->create();
    $integration = irAccount($workspace, 'pagerduty');
    irDown(irMonitor($workspace));

    $this->actingAs($workspace->owner)->delete($workspace->url("/integrations/{$integration->id}"))
        ->assertSessionHas('inertia.flash_data.toast.message', 'Disconnected On call and resolved its open alert in PagerDuty.');
    expect($pagerduty->open)->toBe([])->and(Integration::query()->count())->toBe(0);
});

test('Linear connects with a personal API key it checks with Linear', function () {
    $linear = FakeLinear::install();
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/linear'), ['credentials' => ['api_key' => 'lin_api_wrong0123456789abcdefghijkl']])
        ->assertSessionHasErrors(['credentials.api_key' => 'Linear turned the API key down. Check it’s copied in full and hasn’t been revoked.']);
    $this->actingAs($workspace->owner)->post($workspace->url('/integrations/connect/linear'), ['credentials' => ['api_key' => FakeLinear::KEY]])
        ->assertSessionHasNoErrors();

    $integration = Integration::query()->sole();
    expect($integration->label)->toBe('Acme')
        ->and($integration->meta)->toBe(['organization' => 'Acme', 'url_key' => 'acme', 'viewer' => 'Dana Lee', 'teams' => 2])
        ->and(DB::table('integrations')->value('credentials'))->not->toContain(FakeLinear::KEY);
    $linear->calls = [];

    $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$integration->id}/options?team=team-1"))->assertOk()
        ->assertJsonPath('teams.0.key', 'ENG')
        ->assertJsonPath('projects', [['id' => 'proj-1', 'name' => 'Reliability']])
        ->assertJsonPath('labels', [['id' => 'label-1', 'name' => 'Incident'], ['id' => 'label-2', 'name' => 'Bug']])
        ->assertJsonPath('done', ['id' => 'state-done', 'name' => 'Done']);

    $this->actingAs($workspace->owner)->put($workspace->url("/integrations/{$integration->id}/routing"), [
        'team' => 'team-1', 'project' => 'proj-1', 'label' => 'label-3', 'monitors' => true, 'minutes' => 5, 'machines' => true, 'cves' => false,
    ])->assertSessionHasErrors(['team' => 'That label isn’t in the team.']);
    $this->actingAs($workspace->owner)->put($workspace->url("/integrations/{$integration->id}/routing"), [
        'team' => 'team-1', 'project' => 'proj-1', 'label' => 'label-1', 'monitors' => true, 'minutes' => 5, 'machines' => true, 'cves' => false,
    ])->assertSessionHasNoErrors();

    expect($integration->fresh()->settings)->toBe([
        'team' => ['id' => 'team-1', 'key' => 'ENG', 'name' => 'Engineering'],
        'project' => ['id' => 'proj-1', 'name' => 'Reliability'],
        'label' => ['id' => 'label-1', 'name' => 'Incident'],
        'done' => ['id' => 'state-done', 'name' => 'Done'],
        'monitors' => true, 'minutes' => 5, 'machines' => true, 'cves' => false,
    ]);
    $this->actingAs($workspace->owner)->get($workspace->url('/integrations'))
        ->assertInertia(fn (Assert $page) => $page->where('connections', fn ($rows) => collect($rows)->contains(fn ($row) => $row['detail'] === 'Issues in Engineering')));
});

test('a monitor that stays down opens one Linear issue, and its recovery comments and closes it', function () {
    $linear = FakeLinear::install();
    $workspace = Workspace::factory()->create();
    $integration = irAccount($workspace, 'linear', irLinearSettings(['label' => ['id' => 'label-1', 'name' => 'Incident']]));
    $monitor = irMonitor($workspace);

    irDown($monitor);
    app(IncidentRouter::class)->sweep();
    expect($linear->issues)->toBe([]);

    $this->travel(11)->minutes();
    app(IncidentRouter::class)->sweep();
    app(IncidentRouter::class)->sweep();
    expect($linear->issues)->toHaveCount(1);
    $issue = array_values($linear->issues)[0];
    expect($issue['input']['teamId'])->toBe('team-1')
        ->and($issue['input']['labelIds'])->toBe(['label-1'])
        ->and($issue['input']['title'])->toBe('Shop is down')
        ->and($issue['input']['priority'])->toBe(1)
        ->and($issue['input']['description'])->toContain('Connection refused')
        ->and($issue['input']['description'])->toContain('[Open in ServerOS]('.$workspace->url('/monitors/'.$monitor->id).')');

    $incident = IntegrationIncident::query()->sole();
    expect($incident->external_key)->toBe('ENG-101')->and($incident->external_url)->toBe('https://linear.app/acme/issue/ENG-101');

    $this->actingAs($workspace->owner)->get($workspace->url('/monitors/'.$monitor->id))
        ->assertInertia(fn (Assert $page) => $page->where('incidents.list.0.issues', [['key' => 'ENG-101', 'url' => 'https://linear.app/acme/issue/ENG-101']]));
    $issues = $this->actingAs($workspace->owner)->get($workspace->url('/'))->viewData('page')['props']['issues'];
    expect(collect($issues)->firstWhere('id', "monitor-{$monitor->id}-down")['linked'] ?? null)->toBe(['key' => 'ENG-101', 'url' => 'https://linear.app/acme/issue/ENG-101']);

    irUp($monitor);
    $issue = array_values($linear->issues)[0];
    expect($issue['stateId'])->toBe('state-done')
        ->and($issue['comments'])->toHaveCount(1)
        ->and($issue['comments'][0])->toStartWith('Back up after 11 min down.')
        ->and($linear->ops())->toBe(['issueCreate', 'commentCreate', 'issueUpdate']);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page
            ->where('events.0.label', 'Closed the issue')
            ->where('events.0.link.key', 'ENG-101')
            ->where('events.1.label', 'Opened an issue')
            ->where('incidents', []));
});

test('a monitor back up before the wait opens no Linear issue at all', function () {
    $linear = FakeLinear::install();
    $workspace = Workspace::factory()->create();
    irAccount($workspace, 'linear', irLinearSettings());
    $monitor = irMonitor($workspace);

    irDown($monitor);
    $this->travel(3)->minutes();
    app(IncidentRouter::class)->sweep();
    irUp($monitor);
    $this->travel(20)->minutes();
    app(IncidentRouter::class)->sweep();

    expect($linear->calls)->toBe([])->and(IntegrationIncident::query()->count())->toBe(0);
});

test('when Linear’s reply to a new issue is lost, trying again finds it instead of opening another', function () {
    $linear = FakeLinear::install();
    $workspace = Workspace::factory()->create();
    irAccount($workspace, 'linear', irLinearSettings(['minutes' => 0]));
    $monitor = irMonitor($workspace);

    $linear->failNext('issueCreate', 502, afterDoing: true);
    irDown($monitor);
    expect(IntegrationEvent::query()->sole()->state)->toBe('retrying')->and($linear->issues)->toHaveCount(1);

    $this->travel(2)->minutes();
    app(IncidentRouter::class)->sweep();
    expect($linear->issues)->toHaveCount(1)
        ->and(IntegrationEvent::query()->sole()->state)->toBe('sent')
        ->and(IntegrationIncident::query()->sole()->external_key)->toBe('ENG-101')
        ->and($linear->ops())->toBe(['issueCreate', 'issue']);
});

test('a critical CVE opens a Linear issue, comments when more machines have it and closes when it’s fixed', function () {
    $linear = FakeLinear::install();
    $workspace = Workspace::factory()->create();
    irAccount($workspace, 'linear', irLinearSettings());
    $web = irMachine($workspace, 'web-1');
    $findings = [irCve($web, 'CVE-2026-2001', exploited: false)];
    irCve($web, 'CVE-2026-2002', severity: 'high', exploited: false);

    app(IncidentRouter::class)->vulnerabilities($workspace);
    expect($linear->issues)->toHaveCount(1)
        ->and(array_values($linear->issues)[0]['input']['title'])->toBe('CVE-2026-2001 on web-1');

    $findings[] = irCve(irMachine($workspace, 'web-2'), 'CVE-2026-2001', exploited: false);
    app(IncidentRouter::class)->sweep();
    app(IncidentRouter::class)->sweep();
    $issue = array_values($linear->issues)[0];
    expect($issue['comments'])->toHaveCount(1)->and($issue['comments'][0])->toContain('web-1, web-2');

    foreach ($findings as $finding) {
        $finding->forceFill(['resolved_at' => now()])->save();
    }
    app(IncidentRouter::class)->sweep();
    $issue = array_values($linear->issues)[0];
    expect($issue['stateId'])->toBe('state-done')
        ->and(end($issue['comments']))->toStartWith('Fixed: no machine has it any more.');
});

test('a revoked Linear key fails the issue without trying again, and marks the account', function () {
    $linear = FakeLinear::install();
    $workspace = Workspace::factory()->create();
    $integration = irAccount($workspace, 'linear', irLinearSettings(['minutes' => 0]));
    $integration->forceFill(['credentials' => ['api_key' => 'lin_api_revoked0123456789abcdefghij']])->save();

    irDown(irMonitor($workspace));
    $event = IntegrationEvent::query()->sole();
    expect($event->state)->toBe('failed')
        ->and($event->error)->toBe('Linear turned the API key down. Check it’s copied in full and hasn’t been revoked.')
        ->and($integration->fresh()->status)->toBe(Integration::FAILING)
        ->and($linear->issues)->toBe([]);

    $this->travel(2)->hours();
    app(IncidentRouter::class)->sweep();
    expect($linear->ops())->toBe(['issueCreate']);
});
