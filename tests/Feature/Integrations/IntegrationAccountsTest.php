<?php

use App\Models\Activity;
use App\Models\Integration;
use App\Models\IntegrationServer;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Activity\ActivityLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\FakeHetzner;

function hzConnect(Workspace $workspace, string $token = FakeHetzner::TOKEN, ?string $label = null, ?User $as = null): TestResponse
{
    return test()->actingAs($as ?? $workspace->owner)
        ->from($workspace->url('/integrations'))
        ->post($workspace->url('/integrations/connect/hetzner'), ['label' => $label, 'credentials' => ['token' => $token]]);
}

function hzAccount(Workspace $workspace, string $label = 'Hetzner Cloud'): Integration
{
    return $workspace->integrations()->create([
        'provider' => 'hetzner',
        'label' => $label,
        'credentials' => ['token' => FakeHetzner::TOKEN],
        'fingerprint' => Integration::fingerprintOf('hetzner', ['token' => FakeHetzner::TOKEN.$label]),
        'meta' => ['servers' => 0],
        'status' => Integration::CONNECTED,
        'last_checked_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function hzIndexProps(Workspace $workspace, ?User $user = null): array
{
    return test()->actingAs($user ?? $workspace->owner)->get($workspace->url('/integrations'))->assertOk()->viewData('page')['props'];
}

function hzMember(Workspace $workspace, string $role): User
{
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => $role]);

    return $user;
}

beforeEach(function () {
    Sleep::fake();
});

test('connecting Hetzner checks the token with Hetzner, keeps it encrypted and links machines by address', function () {
    $hetzner = FakeHetzner::install()->server(42, 'web-1', '198.51.100.7')->server(43, 'spare', '198.51.100.8');
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);

    hzConnect($workspace, label: 'Acme prod')->assertSessionHasNoErrors()->assertRedirect();

    $integration = Integration::query()->sole();
    expect($integration->label)->toBe('Acme prod')
        ->and($integration->credential('token'))->toBe(FakeHetzner::TOKEN)
        ->and(DB::table('integrations')->value('credentials'))->not->toContain(FakeHetzner::TOKEN)
        ->and($integration->meta)->toBe(['servers' => 2])
        ->and($integration->status)->toBe('connected')
        ->and($integration->created_by)->toBe($workspace->owner->id)
        ->and($hetzner->called('GET'))->toContain('GET servers')
        ->and(IntegrationServer::query()->where('machine_id', $machine->id)->value('server_id'))->toBe('42')
        ->and(IntegrationServer::query()->count())->toBe(2);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer '.FakeHetzner::TOKEN));
    $activity = Activity::query()->where('action', 'integration.connect')->sole();
    expect(ActivityLine::sentence($activity))->toBe($workspace->owner->name.' connected Acme prod (Hetzner Cloud)')
        ->and(json_encode($activity->meta))->not->toContain(FakeHetzner::TOKEN);
});

test('a token Hetzner turns down is never saved, and the form says why', function () {
    FakeHetzner::install();
    $workspace = Workspace::factory()->create();

    hzConnect($workspace, 'wrong-token-0123456789abcdef')
        ->assertSessionHasErrors(['credentials.token' => 'Hetzner turned the token down. Check it’s copied in full and hasn’t been deleted.']);
    hzConnect($workspace, '')->assertSessionHasErrors('credentials.token');

    expect(Integration::query()->count())->toBe(0)
        ->and(Activity::query()->where('action', 'like', 'integration.%')->count())->toBe(0);
});

test('the same project can’t be connected twice, but another one can', function () {
    FakeHetzner::install();
    $workspace = Workspace::factory()->create();

    hzConnect($workspace)->assertSessionHasNoErrors();
    hzConnect($workspace)->assertSessionHasErrors(['credentials.token' => 'This account is connected already, as Hetzner Cloud.']);

    expect(Integration::query()->count())->toBe(1);
});

test('credentials never reach the browser or the activity log', function () {
    $hetzner = FakeHetzner::install()->server(42, 'web-1', '198.51.100.7');
    $workspace = Workspace::factory()->create();
    hzConnect($workspace)->assertSessionHasNoErrors();
    $integration = Integration::query()->sole();

    $index = $this->actingAs($workspace->owner)->get($workspace->url('/integrations'))->assertOk();
    $show = $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))->assertOk();
    $deferred = null;
    $show->assertInertia(function (Assert $page) use (&$deferred) {
        $page->loadDeferredProps(function (Assert $reload) use (&$deferred) {
            $deferred = $reload->toArray()['props'];
        });
    });

    foreach ([$index->getContent(), $show->getContent(), json_encode($deferred), Activity::query()->get()->toJson(), $integration->toJson()] as $text) {
        expect($text)->not->toContain(FakeHetzner::TOKEN);
    }
    expect($deferred['servers']['list'][0]['name'])->toBe('web-1');
});

test('the Integrations page shows Hetzner as connectable, then as a real account with what it is doing', function () {
    FakeHetzner::install()->server(42, 'web-1', '198.51.100.7');
    $workspace = Workspace::factory()->create();
    Machine::factory()->for($workspace)->create(['name' => 'web-1', 'ip_address' => '198.51.100.7']);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('providers.hetzner.name', 'Hetzner Cloud')
            ->where('providers.hetzner.fields.0.key', 'token')
            ->where('providers.hetzner.fields.0.secret', true)
            ->where('providers.hetzner.fields.0.link', 'https://console.hetzner.com/projects')
            ->has('providers.hetzner.capabilities', 6));
    $hetzner = collect(hzIndexProps($workspace)['integrations'])->firstWhere('key', 'hetzner');
    expect($hetzner['action'])->toBe(['label' => 'Connect', 'connect' => 'hetzner'])
        ->and($hetzner['connected'])->toBeFalse();

    hzConnect($workspace, label: 'Acme prod')->assertSessionHasNoErrors();
    $integration = Integration::query()->sole();
    $props = hzIndexProps($workspace);
    $hetzner = collect($props['integrations'])->firstWhere('key', 'hetzner');

    expect($hetzner['connected'])->toBeTrue()
        ->and($hetzner['action'])->toBe(['label' => 'Manage', 'href' => '/integrations/'.$integration->id])
        ->and($props['connections'][0])->toMatchArray(['key' => 'integration-'.$integration->id, 'name' => 'Acme prod', 'logo' => 'hetzner', 'detail' => '1 server', 'failing' => false]);

    $this->actingAs($workspace->owner)->get($workspace->url('/integrations/'.$integration->id))
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/IntegrationShow')
            ->where('integration.label', 'Acme prod')
            ->where('integration.providerName', 'Hetzner Cloud')
            ->where('usage.0', 'web-1 is linked to its server here.')
            ->where('dependents.0', '1 machine loses resizing, restarts and snapshots from ServerOS.')
            ->missing('servers'));
});

test('a second project shows both accounts, and Manage lists them', function () {
    FakeHetzner::install();
    $workspace = Workspace::factory()->create();
    $first = hzAccount($workspace, 'Prod');
    $second = hzAccount($workspace, 'Staging');

    $hetzner = collect(hzIndexProps($workspace)['integrations'])->firstWhere('key', 'hetzner');
    expect($hetzner['action'])->toBe(['label' => 'Manage', 'connect' => 'hetzner', 'accounts' => [
        ['id' => $first->id, 'label' => 'Prod', 'href' => '/integrations/'.$first->id],
        ['id' => $second->id, 'label' => 'Staging', 'href' => '/integrations/'.$second->id],
    ]]);
});

test('only people who manage machines can connect, see or change an account', function () {
    FakeHetzner::install();
    $workspace = Workspace::factory()->create();
    $integration = hzAccount($workspace);
    $viewer = hzMember($workspace, 'viewer');

    hzConnect($workspace, as: $viewer)->assertForbidden();
    $this->actingAs($viewer)->get($workspace->url('/integrations/'.$integration->id))->assertForbidden();
    $this->actingAs($viewer)->post($workspace->url('/integrations/'.$integration->id.'/check'))->assertForbidden();
    $this->actingAs($viewer)->patch($workspace->url('/integrations/'.$integration->id), ['label' => 'Mine'])->assertForbidden();
    $this->actingAs($viewer)->delete($workspace->url('/integrations/'.$integration->id))->assertForbidden();

    $props = hzIndexProps($workspace, $viewer);
    expect(collect($props['integrations'])->firstWhere('key', 'hetzner')['action'])->toBeNull()
        ->and($props['providers'])->toBe([])
        ->and($props['connections'][0]['target'])->toBeNull();

    $other = Workspace::factory()->create();
    $this->actingAs($other->owner)->get($other->url('/integrations/'.$integration->id))->assertNotFound();
    expect($integration->fresh()->label)->toBe('Hetzner Cloud');
});

test('checking again marks a revoked token as failing, and a working one as connected again', function () {
    $hetzner = FakeHetzner::install();
    $workspace = Workspace::factory()->create();
    $integration = hzAccount($workspace);
    $url = $workspace->url('/integrations/'.$integration->id.'/check');

    $hetzner->failNext('GET', 'servers', 401, ['error' => ['code' => 'unauthorized', 'message' => 'unable to authenticate']]);
    $this->actingAs($workspace->owner)->post($url)->assertRedirect();
    $integration->refresh();
    expect($integration->status)->toBe('failing')
        ->and($integration->last_error)->toBe('Hetzner turned the token down. Check it’s copied in full and hasn’t been deleted.')
        ->and(collect(hzIndexProps($workspace)['connections'])->firstWhere('key', 'integration-'.$integration->id))->toMatchArray(['failing' => true, 'detail' => 'Needs a new token']);

    $this->actingAs($workspace->owner)->post($url)->assertRedirect();
    expect($integration->fresh()->status)->toBe('connected')
        ->and($integration->fresh()->last_error)->toBeNull();

    $this->actingAs($workspace->owner)->post($url)->assertRedirect();
    expect(Activity::query()->where('action', 'integration.check')->pluck('meta')->map(fn ($m) => $m['ok'])->all())->toBe([false, true]);
});

test('renaming and disconnecting are recorded, and disconnecting a linked account needs its name typed', function () {
    FakeHetzner::install();
    $workspace = Workspace::factory()->create();
    $integration = hzAccount($workspace);
    $machine = Machine::factory()->for($workspace)->create();
    $integration->servers()->create(['server_id' => '42', 'name' => 'web-1', 'machine_id' => $machine->id, 'details' => []]);

    $this->actingAs($workspace->owner)->patch($workspace->url('/integrations/'.$integration->id), ['label' => 'Acme prod'])->assertSessionHasNoErrors();
    expect($integration->fresh()->label)->toBe('Acme prod')
        ->and(ActivityLine::sentence(Activity::query()->where('action', 'integration.rename')->sole()))->toBe($workspace->owner->name.' renamed an account to Acme prod (was Hetzner Cloud)');

    $this->actingAs($workspace->owner)->delete($workspace->url('/integrations/'.$integration->id))->assertSessionHasErrors('confirm');
    expect(Integration::query()->count())->toBe(1);

    $this->actingAs($workspace->owner)->delete($workspace->url('/integrations/'.$integration->id), ['confirm' => 'Acme prod'])
        ->assertRedirect($workspace->url('/integrations'));
    expect(Integration::query()->count())->toBe(0)
        ->and(IntegrationServer::query()->count())->toBe(0)
        ->and($machine->fresh())->not->toBeNull()
        ->and(Activity::query()->where('action', 'integration.disconnect')->sole()->meta)->toBe(['provider' => 'Hetzner Cloud', 'machines' => 1]);
});

test('an account nothing depends on disconnects without typing its name', function () {
    FakeHetzner::install();
    $workspace = Workspace::factory()->create();
    $integration = hzAccount($workspace);

    $this->actingAs($workspace->owner)->delete($workspace->url('/integrations/'.$integration->id))->assertSessionHasNoErrors();
    expect(Integration::query()->count())->toBe(0);
});
