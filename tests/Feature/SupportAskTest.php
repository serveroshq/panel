<?php

use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Entitlements\Entitlements;
use App\Support\Licensing\SupportAsk;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config()->set('serveros.coming_soon', false);
    config()->set('serveros.edition', 'self-hosted');
    app()->forgetInstance(Entitlements::class);
});

function supportWorkspace(int $daysOld = 10): Workspace
{
    $workspace = Workspace::factory()->create(['onboarded_at' => now()]);
    $workspace->forceFill(['created_at' => now()->subDays($daysOld)])->save();
    Machine::factory()->for($workspace)->create();

    return $workspace;
}

test('a self-hosted workspace’s admins are asked to donate or get a license after a week', function () {
    $workspace = supportWorkspace();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/')
        ->assertInertia(fn (Assert $page) => $page->where('supportAsk', [
            'donateUrl' => 'https://serveros.com/donate',
            'licenseUrl' => 'https://serveros.com/pricing',
        ]));

    expect(SupportAsk::for($workspace, $workspace->owner, 0))->toBeNull()
        ->and(SupportAsk::for(supportWorkspace(3), $workspace->owner, 1))->toBeNull();
});

test('nobody is asked on serveros.com, in a licensed workspace, or without a say in it', function () {
    $workspace = supportWorkspace();
    $member = User::factory()->create();
    $workspace->members()->attach($member, ['role' => 'viewer']);
    expect(SupportAsk::for($workspace, $member, 1))->toBeNull();

    config()->set('serveros.support_ask.enabled', false);
    expect(SupportAsk::for($workspace, $workspace->owner, 1))->toBeNull();
    config()->set('serveros.support_ask.enabled', true);

    config()->set('serveros.edition', 'saas');
    app()->forgetInstance(Entitlements::class);
    expect(SupportAsk::for($workspace, $workspace->owner, 1))->toBeNull();

    config()->set('serveros.edition', 'self-hosted');
    $this->mock(Entitlements::class, fn ($mock) => $mock->shouldReceive('has')->andReturn(true));
    expect(SupportAsk::for($workspace, $workspace->owner, 1))->toBeNull();
});
