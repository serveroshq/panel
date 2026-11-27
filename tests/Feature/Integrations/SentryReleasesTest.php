<?php

use App\Enums\Plan;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Integration;
use App\Models\IntegrationApp;
use App\Models\IntegrationEvent;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use App\Support\Integrations\Incidents\IncidentRouter;
use App\Support\Integrations\Sentry\SentryReleases;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeSentry;

beforeEach(function () {
    Sleep::fake();
});

const SN_OLD = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
const SN_NEW = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

function snAccount(Workspace $workspace): Integration
{
    $credentials = ['token' => FakeSentry::TOKEN, 'organization' => 'acme'];

    return $workspace->integrations()->create([
        'provider' => 'sentry',
        'label' => 'Acme',
        'credentials' => $credentials,
        'fingerprint' => Integration::fingerprintOf('sentry', $credentials),
        'meta' => ['organization' => 'Acme', 'slug' => 'acme', 'organization_url' => 'https://acme.sentry.io', 'region_url' => 'https://us.sentry.io', 'projects' => 2],
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
    ]);
}

/** @return array{0: Workspace, 1: Machine} */
function snShop(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'environment' => 'production']);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now()])->save();
    $svc = fn ($key, $name) => ['key' => $key, 'name' => $name, 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'confidence' => 90, 'details' => ['managed' => 'true']];
    $machine->forceFill(['inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
        $svc('docker:222222222222', '/serveros-shop-'.substr(SN_NEW, 0, 12)),
    ]], 'service_stats' => []])->save();

    return [$workspace, $machine];
}

function snJob(Machine $machine, string $kind, string $commit, int $minutesAgo = 0): DaemonJob
{
    return DaemonJob::query()->create([
        'id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => $kind, 'service' => 'shop', 'state' => 'succeeded', 'log' => [],
        'command' => ['job' => $kind === 'rollback'
            ? ['type' => 'rollback', 'service' => 'shop', 'release' => $commit]
            : ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => $commit]],
        'result' => $kind === 'rollback' ? ['commit' => substr($commit, 0, 12)] : null,
        'finished_at' => now()->subMinutes($minutesAgo),
        'created_at' => now()->subMinutes($minutesAgo + 1),
    ]);
}

function snLink(Integration $integration, Machine $machine, string $project = 'api'): IntegrationApp
{
    return IntegrationApp::query()->create([
        'integration_id' => $integration->id, 'machine_id' => $machine->id, 'service' => 'shop',
        'settings' => ['project' => $project, 'project_id' => $project === 'api' ? '11' : '12', 'name' => $project, 'environment' => 'production'],
    ]);
}

test('Sentry connects with a token and organization, through the organization’s region', function () {
    $sentry = FakeSentry::install();
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/sentry'), ['credentials' => ['token' => 'sntryu_wrong0123456789abcdef', 'organization' => 'acme']])
        ->assertSessionHasErrors(['credentials.token' => 'Sentry turned the token down. Check it’s copied in full and hasn’t been deleted.']);
    $this->actingAs($workspace->owner)->post($workspace->url('/integrations/connect/sentry'), ['credentials' => ['token' => FakeSentry::TOKEN, 'organization' => 'acme']])
        ->assertSessionHasNoErrors()->assertRedirect();

    $integration = Integration::query()->sole();
    expect($integration->label)->toBe('Acme')
        ->and($integration->meta)->toBe(['organization' => 'Acme', 'slug' => 'acme', 'organization_url' => 'https://acme.sentry.io', 'region_url' => 'https://us.sentry.io', 'projects' => 2])
        ->and(DB::table('integrations')->value('credentials'))->not->toContain(FakeSentry::TOKEN)
        ->and(collect($sentry->calls)->pluck('host')->all())->toBe(['sentry.io', 'sentry.io', 'us.sentry.io']);

    $this->actingAs($workspace->owner)->getJson($workspace->url("/integrations/{$integration->id}/options"))->assertOk()
        ->assertJsonPath('projects.0', ['id' => '11', 'slug' => 'api', 'name' => 'api', 'platform' => 'php'])
        ->assertJsonCount(2, 'projects');
    $page = $this->actingAs($workspace->owner)->get($workspace->url("/integrations/{$integration->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('linkedApps', [])->where('events', [])->where('routing', null));
    expect($page->getContent())->not->toContain(FakeSentry::TOKEN);
});

test('an app is linked to a Sentry project from its deploy settings, and unlinked again', function () {
    FakeSentry::install();
    [$workspace, $machine] = snShop();
    snJob($machine, 'deploy', SN_NEW);
    $integration = snAccount($workspace);
    $url = $workspace->url("/machines/{$machine->id}/apps/shop/sentry");
    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $other = snAccount(Workspace::factory()->create());

    $this->actingAs($viewer)->putJson($url, ['integration' => $integration->id, 'project' => 'web'])->assertForbidden();
    $this->actingAs($workspace->owner)->putJson($url, ['integration' => $integration->id, 'project' => 'nope'])->assertUnprocessable()
        ->assertJsonPath('errors.project.0', 'That project isn’t in Acme.');
    $this->actingAs($workspace->owner)->putJson($url, ['integration' => $other->id, 'project' => 'web'])->assertUnprocessable()
        ->assertJsonValidationErrors('integration');
    $this->actingAs($workspace->owner)->putJson($url, ['integration' => $integration->id, 'project' => 'web'])->assertOk();

    expect(IntegrationApp::query()->sole()->settings)->toBe(['project' => 'web', 'project_id' => '12', 'name' => 'Web app', 'environment' => 'production']);
    expect(ActivityLine::sentence(Activity::query()->where('action', 'app.sentry')->sole()))->toBe($workspace->owner->name.' linked shop to the Sentry project Web app on web-1');

    $this->actingAs($workspace->owner)->get($workspace->url("/integrations/{$integration->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('linkedApps.0.service', 'shop')->where('linkedApps.0.project', 'Web app')->where('linkedApps.0.machine', 'web-1'));

    $this->actingAs($workspace->owner)->putJson($url, ['integration' => null])->assertOk();
    expect(IntegrationApp::query()->count())->toBe(0)
        ->and(Activity::query()->where('action', 'app.sentry_off')->count())->toBe(1);
});

test('each deploy of a linked app makes a Sentry release with its commit, a deploy and a finalized date', function () {
    $sentry = FakeSentry::install();
    [$workspace, $machine] = snShop();
    $integration = snAccount($workspace);
    snLink($integration, $machine);

    app(SentryReleases::class)->jobFinished(snJob($machine, 'deploy', SN_NEW));

    expect($sentry->called())->toBe([
        'POST organizations/acme/releases',
        'POST organizations/acme/releases/'.SN_NEW.'/deploys',
        'PUT organizations/acme/releases/'.SN_NEW,
    ])->and(collect($sentry->calls)->pluck('host')->unique()->all())->toBe(['us.sentry.io']);
    expect($sentry->bodyOf('POST', 'organizations/acme/releases'))->toBe([
        'version' => SN_NEW,
        'projects' => ['api'],
        'ref' => SN_NEW,
        'commits' => [['id' => SN_NEW, 'repository' => 'acme/shop']],
    ]);
    $deploy = $sentry->bodyOf('POST', 'organizations/acme/releases/'.SN_NEW.'/deploys');
    expect($deploy['environment'])->toBe('production')
        ->and($deploy['name'])->toBe('shop on web-1')
        ->and($deploy['projects'])->toBe(['api'])
        ->and($sentry->releases[SN_NEW]['dateReleased'])->not->toBeNull();

    $event = IntegrationEvent::query()->sole();
    expect($event->state)->toBe('sent')
        ->and($event->result['url'])->toBe('https://acme.sentry.io/releases/'.SN_NEW.'/?project=11');

    app(SentryReleases::class)->jobFinished(tap(snJob($machine, 'deploy', SN_OLD), fn ($job) => $job->forceFill(['state' => 'failed'])->save()));
    $other = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'blog', 'state' => 'succeeded', 'command' => ['job' => ['commit' => SN_OLD, 'repo' => 'https://github.com/acme/blog']]]);
    app(SentryReleases::class)->jobFinished($other);
    expect(IntegrationEvent::query()->count())->toBe(1);
});

test('rolling back marks the older release deployed, making it first if Sentry never had it', function () {
    $sentry = FakeSentry::install();
    [$workspace, $machine] = snShop();
    $integration = snAccount($workspace);
    snLink($integration, $machine);
    app(SentryReleases::class)->jobFinished(snJob($machine, 'deploy', SN_NEW));
    $sentry->calls = [];

    app(SentryReleases::class)->jobFinished(snJob($machine, 'rollback', SN_NEW));
    expect($sentry->called())->toBe(['GET organizations/acme/releases/'.SN_NEW, 'POST organizations/acme/releases/'.SN_NEW.'/deploys'])
        ->and($sentry->releases[SN_NEW]['deploys'])->toHaveCount(2);

    $sentry->calls = [];
    app(SentryReleases::class)->jobFinished(snJob($machine, 'rollback', SN_OLD));
    expect($sentry->called())->toBe(['GET organizations/acme/releases/'.SN_OLD, 'POST organizations/acme/releases', 'POST organizations/acme/releases/'.SN_OLD.'/deploys'])
        ->and($sentry->releases[SN_OLD]['deploys'])->toHaveCount(1)
        ->and(IntegrationEvent::query()->where('action', 'rollback')->pluck('state')->all())->toBe(['sent', 'sent']);

    $this->actingAs($workspace->owner)->get($workspace->url("/integrations/{$integration->id}"))
        ->assertInertia(fn (Assert $page) => $page->where('events.0.label', 'Rolled back to')->where('events.0.subject', 'shop on web-1 · aaaaaaa'));
});

test('a release that fails half way is tried again without making anything twice', function () {
    $sentry = FakeSentry::install();
    [$workspace, $machine] = snShop();
    snLink(snAccount($workspace), $machine);

    $sentry->failNext('PUT', 'organizations/acme/releases/'.SN_NEW, 503, ['detail' => 'Service unavailable']);
    $sentry->failNext('PUT', 'organizations/acme/releases/'.SN_NEW, 503);
    $sentry->failNext('PUT', 'organizations/acme/releases/'.SN_NEW, 503);
    app(SentryReleases::class)->jobFinished(snJob($machine, 'deploy', SN_NEW));
    $event = IntegrationEvent::query()->sole();
    expect($event->state)->toBe('retrying')
        ->and($event->result['steps'])->toBe(['release' => true, 'deploy' => true]);

    $this->travel(2)->minutes();
    app(IncidentRouter::class)->sweep();
    expect($event->fresh()->state)->toBe('sent')
        ->and(collect($sentry->called())->filter(fn ($c) => str_starts_with($c, 'POST'))->count())->toBe(2)
        ->and($sentry->releases[SN_NEW]['deploys'])->toHaveCount(1)
        ->and($sentry->releases[SN_NEW]['dateReleased'])->not->toBeNull();
});

test('a release Sentry won’t take commits for is made without them', function () {
    $sentry = FakeSentry::install();
    $sentry->rejectCommits = true;
    [$workspace, $machine] = snShop();
    snLink(snAccount($workspace), $machine);

    app(SentryReleases::class)->jobFinished(snJob($machine, 'deploy', SN_NEW));

    expect($sentry->releases[SN_NEW]['commits'])->toBe([])
        ->and(IntegrationEvent::query()->sole()->state)->toBe('sent');
});

test('a linked app deploys with SENTRY_RELEASE set to its commit', function () {
    [$workspace, $machine] = snShop();
    $job = ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'commit' => strtoupper(SN_NEW), 'env' => (object) ['APP_ENV' => 'production']];

    expect(SentryReleases::withRelease($machine, $job))->toBe($job);

    snLink(snAccount($workspace), $machine);
    expect((array) SentryReleases::withRelease($machine, $job)['env'])->toBe(['APP_ENV' => 'production', 'SENTRY_RELEASE' => SN_NEW])
        ->and(SentryReleases::withRelease($machine, [...$job, 'service' => 'blog']))->toBe([...$job, 'service' => 'blog']);
});

test('the Deploys card links each release and counts its new issues, asking Sentry at most every few minutes', function () {
    $sentry = FakeSentry::install();
    [$workspace, $machine] = snShop();
    $integration = snAccount($workspace);
    snJob($machine, 'deploy', SN_OLD, 30);
    snLink($integration, $machine);
    app(SentryReleases::class)->jobFinished(snJob($machine, 'deploy', SN_NEW));
    $sentry->releases[SN_NEW]['projects'][0]['newGroups'] = 3;
    $sentry->calls = [];

    $props = function () use ($workspace, $machine): array {
        $deferred = null;
        $this->actingAs($workspace->owner)->get($workspace->url("/machines/{$machine->id}/services/app:shop"))->assertOk()
            ->assertInertia(function (Assert $page) use (&$deferred) {
                $page->loadDeferredProps(function (Assert $reload) use (&$deferred) {
                    $deferred = $reload->toArray()['props'];
                });
            });

        return $deferred['sentry'];
    };

    $sentryProps = $props();
    expect($sentryProps['link'])->toBe(['integrationId' => $integration->id, 'account' => 'Acme', 'project' => 'api', 'projectId' => '11', 'projectName' => 'api', 'environment' => 'production'])
        ->and($sentryProps['accounts'])->toBe([['id' => $integration->id, 'label' => 'Acme']])
        ->and($sentryProps['releases'])->toBe([SN_NEW => ['url' => 'https://acme.sentry.io/releases/'.SN_NEW.'/?project=11', 'newIssues' => 3]])
        ->and($sentry->called())->toBe(['GET organizations/acme/releases/'.SN_NEW]);

    $props();
    expect($sentry->calls)->toHaveCount(1);
    $this->travel(6)->minutes();
    $props();
    expect($sentry->calls)->toHaveCount(2);
});
