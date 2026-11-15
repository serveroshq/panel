<?php

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('the services page lists every machine\'s services with where they run and their usage', function () {
    $workspace = Workspace::factory()->create();
    $online = Machine::factory()->for($workspace)->create(['name' => 'alpha']);
    $online->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now(),
        'inventory' => ['services' => [
            ['key' => 'docker:abc123def456', 'name' => 'mc-survival', 'kind' => 'game_server', 'manager' => 'compose',
                'status' => 'running', 'ports' => [25565], 'confidence' => 90, 'details' => []],
        ]],
        'service_stats' => ['docker:abc123def456' => ['cpu' => 12.5, 'memUsed' => 1024]],
    ])->save();
    $offline = Machine::factory()->for($workspace)->create(['name' => 'beta']);
    $offline->forceFill([
        'uid' => (string) Str::uuid(),
        'last_seen_at' => now()->subHour(),
        'inventory' => ['services' => [
            ['key' => 'systemd:nginx.service', 'name' => 'nginx', 'kind' => 'web_server', 'manager' => 'systemd',
                'status' => 'running', 'ports' => [80], 'confidence' => 90, 'details' => []],
        ]],
    ])->save();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/services')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Services')
            ->has('services', 2)
            ->where('services.0.name', 'mc-survival')
            ->where('services.0.machine', ['id' => $online->id, 'name' => 'alpha'])
            ->where('services.0.stat.cpu', 12.5)
            ->where('services.1.machine.name', 'beta')
            ->where('services.1.stat', null)
            ->where('machines.0.connection', 'online')
            ->where('machines.1.connection', 'offline')
            ->has('templates')
            ->etc());
});

test('another workspace\'s services stay out of the list', function () {
    $workspace = Workspace::factory()->create();
    $other = Machine::factory()->create();
    $other->forceFill(['inventory' => ['services' => [['key' => 'docker:x', 'name' => 'secret', 'ports' => []]]]])->save();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/services')
        ->assertInertia(fn (Assert $page) => $page->has('services', 0)->etc());

    $this->actingAs(User::factory()->create())->get('https://'.$workspace->host().'/services')
        ->assertForbidden();
});

test('a service saved without ports still lists, with none', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['inventory' => ['services' => [['key' => 'docker:old', 'name' => 'old-db', 'kind' => 'container', 'status' => 'running']]]])->save();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/services')
        ->assertInertia(fn (Assert $page) => $page
            ->has('services', 1)
            ->where('services.0.ports', [])
            ->etc());
});

function servicesDeploy(Machine $machine, string $service, string $state, int $createdMinutesAgo, ?int $updatedMinutesAgo = null, string $kind = 'deploy'): DaemonJob
{
    $job = new DaemonJob(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => $kind, 'service' => $service, 'state' => $state]);
    $job->timestamps = false;
    $job->created_at = now()->subMinutes($createdMinutesAgo);
    $job->updated_at = now()->subMinutes($updatedMinutesAgo ?? $createdMinutesAgo);
    $job->save();

    return $job;
}

test('the services page sends this workspace’s deploys that are building or just finished, and when each machine was scanned', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $machine->forceFill(['inventory' => ['scanned_at' => 1700000000, 'services' => []]])->save();
    $theirs = Machine::factory()->create();

    $building = servicesDeploy($machine, 'building', 'running', 2, 1);
    $slow = servicesDeploy($machine, 'slow', 'queued', 20, 1);
    $failed = servicesDeploy($machine, 'failed', 'failed', 5);
    servicesDeploy($machine, 'old-failure', 'failed', 15);
    servicesDeploy($machine, 'stuck', 'running', 25, 15);
    servicesDeploy($machine, 'past-timeout', 'running', 40, 0);
    servicesDeploy($machine, 'scan', 'running', 1, 0, 'scan');
    servicesDeploy($theirs, 'secret', 'running', 1, 0);

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/services')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('machines.0.scannedAt', 1700000000)
            ->where('deploys', fn ($deploys) => collect($deploys)->pluck('id')->all() === [$building->id, $failed->id, $slow->id])
            ->where('deploys.0', [
                'id' => $building->id, 'machineId' => $machine->id, 'service' => 'building', 'state' => 'running',
                'phase' => null, 'progress' => null, 'createdAt' => $building->created_at->toIso8601String(),
            ])
            ->etc());
});

test('a busy workspace’s finished deploys are capped and never push out one still building', function () {
    $workspace = Workspace::factory()->create();
    $machine = Machine::factory()->for($workspace)->create();
    $running = servicesDeploy($machine, 'long-build', 'running', 9, 0);
    foreach (range(1, 60) as $i) {
        servicesDeploy($machine, "app-{$i}", 'failed', 1);
    }

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/services')
        ->assertInertia(fn (Assert $page) => $page
            ->has('deploys', 51)
            ->where('deploys.50.id', $running->id)
            ->etc());
});
