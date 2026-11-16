<?php

use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Models\Machine;
use App\Models\McpToken;
use App\Models\PackageRollout;
use App\Models\Workspace;
use Illuminate\Support\Str;

/** @return array{0: Workspace, 1: string} */
function selectorWorkspace(array $scopes = Scopes::ALL): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $eu = $workspace->machineGroups()->create(['name' => 'eu-web']);
    $us = $workspace->machineGroups()->create(['name' => 'us-web']);
    $machine = fn (string $name, array $attributes) => Machine::factory()->for($workspace)->create(['name' => $name])
        ->forceFill([...$attributes, 'uid' => (string) Str::uuid()])->save();

    $machine('eu-1', ['machine_group_id' => $eu->id, 'labels' => ['env' => 'prod', 'app' => 'shop'], 'provider' => 'Hetzner', 'region' => 'eu-central', 'tier' => 'web', 'last_seen_at' => now()]);
    $machine('eu-2', ['machine_group_id' => $eu->id, 'labels' => ['env' => 'prod', 'app' => 'shop'], 'provider' => 'Hetzner', 'region' => 'eu-central', 'tier' => 'web', 'last_seen_at' => now()->subHour()]);
    $machine('us-1', ['machine_group_id' => $us->id, 'labels' => ['env' => 'staging', 'app' => 'shop'], 'provider' => 'DigitalOcean', 'region' => 'nyc', 'tier' => 'web', 'last_seen_at' => now()]);
    $machine('db-1', ['labels' => ['env' => 'prod'], 'provider' => 'Hetzner', 'region' => 'eu-central', 'tier' => 'db', 'last_seen_at' => now()]);

    [, $key] = McpToken::issueKey($workspace, $workspace->owner, 'CI', $scopes, McpToken::API);

    return [$workspace, $key];
}

function resolveSelector(string $key, array $body)
{
    return test()->withHeader('Authorization', "Bearer {$key}")->postJson('/api/v1/selectors/resolve', $body);
}

function resolvedNames(string $key, array $body): array
{
    return collect(resolveSelector($key, $body)->assertOk()->json('data.machines'))->pluck('name')->all();
}

test('a selector ANDs its fields, and a list of groups or label values matches any of them', function () {
    [, $key] = selectorWorkspace([Scopes::READ]);

    expect(resolvedNames($key, ['selector' => ['labels' => ['env' => 'prod']]]))->toBe(['db-1', 'eu-1', 'eu-2'])
        ->and(resolvedNames($key, ['selector' => ['labels' => ['env' => 'prod', 'app' => 'shop']]]))->toBe(['eu-1', 'eu-2'])
        ->and(resolvedNames($key, ['selector' => ['labels' => ['env' => ['prod', 'staging'], 'app' => 'shop']]]))->toBe(['eu-1', 'eu-2', 'us-1'])
        ->and(resolvedNames($key, ['selector' => ['groups' => 'eu-web', 'online' => true]]))->toBe(['eu-1'])
        ->and(resolvedNames($key, ['selector' => ['groups' => ['us-web', 'none']]]))->toBe(['db-1', 'us-1'])
        ->and(resolvedNames($key, ['selector' => ['provider' => 'hetzner', 'region' => 'EU-central', 'tier' => 'web']]))->toBe(['eu-1', 'eu-2'])
        ->and(resolvedNames($key, ['selector' => ['online' => false]]))->toBe(['eu-2'])
        ->and(resolvedNames($key, ['machines' => ['us-1', 'db-1']]))->toBe(['us-1', 'db-1']);

    resolveSelector($key, ['selector' => ['tier' => 'web']])->assertJsonPath('data.count', 3)
        ->assertJsonPath('data.machines.0.group.name', 'eu-web');
});

test('a selector that matches nothing, or says nothing, is refused rather than meaning every machine', function () {
    [, $key] = selectorWorkspace();

    resolveSelector($key, ['selector' => ['labels' => ['env' => 'dev']]])->assertStatus(422)
        ->assertJsonPath('error.type', 'unprocessable')
        ->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'No machines'));

    foreach ([
        [],
        ['selector' => []],
        ['selector' => ['labels' => []]],
        ['selector' => ['env' => 'prod']],
        ['selector' => ['labels' => ['env' => 'prod']], 'machines' => ['eu-1']],
        ['selector' => ['online' => 'yes']],
        ['selector' => ['labels' => ['env' => ['prod', 7]]]],
        ['machines' => []],
    ] as $body) {
        resolveSelector($key, $body)->assertStatus(422)->assertJsonPath('error.type', 'invalid_arguments');
    }

    resolveSelector($key, ['selector' => ['groups' => 'nope']])->assertStatus(422)->assertJsonPath('error.type', 'unprocessable');
    resolveSelector($key, ['machines' => ['nope']])->assertStatus(422);
});

test('a selector only sees the key’s own workspace', function () {
    [, $key] = selectorWorkspace();
    $other = Workspace::factory()->onPlan(Plan::Pro)->create();
    Machine::factory()->for($other)->create(['name' => 'theirs'])->forceFill(['labels' => ['env' => 'prod']])->save();

    expect(resolvedNames($key, ['selector' => ['labels' => ['env' => 'prod']]]))->not->toContain('theirs');
    resolveSelector($key, ['machines' => ['theirs']])->assertStatus(422);
});

test('routes that take a list of machines take a selector instead: groups, package rollouts and the fleet status page', function () {
    [$workspace, $key] = selectorWorkspace();
    $api = fn (string $method, string $path, array $body) => test()->flushHeaders()->withHeader('Authorization', "Bearer {$key}")->json($method, "/api/v1/{$path}", $body);
    $namesIn = fn (string $group) => $workspace->machines()->whereHas('group', fn ($q) => $q->where('name', $group))->orderBy('name')->pluck('name')->all();

    $api('POST', 'machine-groups', ['name' => 'prod-web', 'selector' => ['labels' => ['env' => 'prod'], 'tier' => 'web']])->assertOk();
    expect($namesIn('prod-web'))->toBe(['eu-1', 'eu-2']);

    $api('PATCH', 'machine-groups/prod-web', ['selector' => ['labels' => ['env' => 'prod']]])->assertOk();
    expect($namesIn('prod-web'))->toBe(['db-1', 'eu-1', 'eu-2']);

    $api('POST', 'package-rollouts', ['packages' => [['name' => 'openssl']], 'selector' => ['region' => 'nyc'], 'confirm' => '1 machine'])->assertOk();
    expect(PackageRollout::query()->sole()->targets()->pluck('machine_id')->all())->toBe([$workspace->machines()->where('name', 'us-1')->value('id')]);

    $api('PATCH', 'status-pages/fleet', ['selector' => ['tier' => 'db']])->assertOk();
    expect($workspace->fleetStatusPage()->first()?->machines)->toBe([$workspace->machines()->where('name', 'db-1')->value('id')]);

    $api('POST', 'machine-groups', ['name' => 'both', 'machines' => ['db-1'], 'selector' => ['tier' => 'db']])->assertStatus(422)
        ->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'not both'));
    $api('POST', 'machine-groups', ['name' => 'nobody', 'selector' => ['labels' => ['env' => 'dev']]])->assertStatus(422)
        ->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'No machines'));
    expect($workspace->machineGroups()->whereIn('name', ['both', 'nobody'])->exists())->toBeFalse();
});
