<?php

use App\Enums\MachineEnvironment;
use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\McpToken;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Models\Workspace;
use App\Notifications\MonitorAlert;
use App\Support\Health\MachineWatcher;
use App\Support\Monitors\HostResolver;
use App\Support\Webhooks\Webhooks;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;

beforeEach(function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    // hooks.example.com is public; inside.example.com points at a private address.
    app()->instance(HostResolver::class, new class extends HostResolver
    {
        protected function addresses(string $host): array
        {
            return ['hooks.example.com' => ['93.184.216.34'], 'inside.example.com' => ['10.0.0.5']][$host] ?? [];
        }
    });
});

/** @return array{0: Workspace, 1: Machine, 2: string} */
function webhookWorkspace(array $scopes = Scopes::ALL): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    $machine = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'environment' => MachineEnvironment::Production]);
    $machine->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => ['scanned_at' => now()->getTimestamp(), 'complete' => true, 'listeners' => [], 'services' => [
        ['key' => 'docker:aaa', 'name' => 'shop', 'kind' => 'app', 'manager' => 'docker', 'status' => 'running', 'ports' => [], 'details' => ['managed' => 'true']],
    ]]])->save();
    [, $key] = McpToken::issueKey($workspace, $workspace->owner, 'CI', $scopes, McpToken::API);

    return [$workspace, $machine, $key];
}

function webhookApi(string $key, string $method, string $path, array $body = []): TestResponse
{
    return test()->flushHeaders()->withHeader('Authorization', "Bearer {$key}")->json($method, "/api/v1/{$path}", $body);
}

/** Adds an endpoint through the API and returns it with its secret. */
function addEndpoint(string $key, array $body = []): array
{
    $answer = webhookApi($key, 'POST', 'webhooks', ['url' => 'https://hooks.example.com/serveros?token=abc', ...$body])->assertOk();

    return [WebhookEndpoint::query()->findOrFail($answer->json('data.webhook.id')), $answer->json('data.secret')];
}

/** The webhook requests sent, as [event, payload, signature header]. */
function sentWebhooks(): array
{
    return collect(Http::recorded())
        ->map(fn ($pair) => $pair[0])
        ->filter(fn (Request $r) => str_starts_with($r->url(), 'https://hooks.example.com/'))
        ->map(fn (Request $r) => [$r->header('ServerOS-Event')[0] ?? null, json_decode($r->body(), true), $r->header('ServerOS-Signature')[0] ?? null, $r->body()])
        ->values()->all();
}

function gatewayJobUpdate(Machine $machine, DaemonJob $job, string $state): void
{
    test()->flushHeaders()->postJson('/api/gateway/machines/'.$machine->uid.'/ingest', [
        'envelopes' => [['v' => 1, 'kind' => 'job_update', 'payload' => ['job_id' => $job->id, 'state' => $state, 'log' => ['secret log line'], 'result' => ['exit_code' => 0]]]],
    ], ['X-Gateway-Secret' => 'test-gateway-secret'])->assertOk();
}

test('an endpoint is added with a secret shown once, and its address is checked', function () {
    Http::fake();
    [$workspace, , $key] = webhookWorkspace();

    $answer = webhookApi($key, 'POST', 'webhooks', ['url' => 'https://hooks.example.com/serveros?token=abc', 'events' => ['job.finished'], 'description' => 'CI'])->assertOk()
        ->assertJsonPath('data.webhook.url', 'https://hooks.example.com/serveros?…')
        ->assertJsonPath('data.webhook.events', ['job.finished'])
        ->assertJsonPath('data.webhook.enabled', true);
    expect($answer->json('data.secret'))->toStartWith('whsec_')
        ->and(WebhookEndpoint::query()->sole()->getRawOriginal('secret'))->not->toContain($answer->json('data.secret'));

    webhookApi($key, 'GET', 'webhooks')->assertOk()
        ->assertJsonPath('data.webhooks.0.url', 'https://hooks.example.com/serveros?…')
        ->assertJsonMissingPath('data.webhooks.0.secret');

    foreach (['http://hooks.example.com/x', 'https://inside.example.com/x', 'https://nowhere.example.com/x', 'https://user:pass@hooks.example.com/x', 'https://10.0.0.5/x'] as $url) {
        webhookApi($key, 'POST', 'webhooks', ['url' => $url])->assertStatus(422)->assertJsonPath('error.type', 'invalid_arguments');
    }
    webhookApi($key, 'POST', 'webhooks', ['url' => 'https://hooks.example.com/x', 'events' => ['job.exploded']])->assertStatus(422);

    foreach (range(2, Webhooks::MAX_ENDPOINTS) as $n) {
        webhookApi($key, 'POST', 'webhooks', ['url' => "https://hooks.example.com/{$n}"])->assertOk();
    }
    webhookApi($key, 'POST', 'webhooks', ['url' => 'https://hooks.example.com/more'])->assertStatus(422);

    expect(Activity::query()->where('workspace_id', $workspace->id)->where('action', 'api.webhook_create')->count())->toBe(Webhooks::MAX_ENDPOINTS);
});

test('a finished job is sent signed, with ids and states only, to endpoints that want it', function () {
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202), 'hooks.example.com/*' => Http::response('ok')]);
    [, $machine, $key] = webhookWorkspace();
    [, $secret] = addEndpoint($key, ['events' => ['job.finished']]);
    addEndpoint($key, ['url' => 'https://hooks.example.com/offline-only', 'events' => ['machine.offline']]);

    $job = DaemonJob::query()->findOrFail(webhookApi($key, 'POST', "machines/{$machine->id}/services/docker:aaa/restart")->json('data.job.id'));
    gatewayJobUpdate($machine, $job, 'running');
    expect(sentWebhooks())->toBe([]);

    gatewayJobUpdate($machine, $job, 'succeeded');
    $sent = sentWebhooks();
    expect($sent)->toHaveCount(1);
    [$event, $payload, $signature, $body] = $sent[0];

    expect($event)->toBe('job.finished')
        ->and($payload['type'])->toBe('job.finished')
        ->and($payload['data'])->toBe(['job_id' => $job->id, 'kind' => 'service_action', 'state' => 'succeeded', 'machine_id' => $machine->id, 'service' => 'docker:aaa'])
        ->and($body)->not->toContain('secret log line');

    preg_match('/^t=(\d+),v1=([0-9a-f]{64})$/', $signature, $m);
    expect(hash_equals(hash_hmac('sha256', "{$m[1]}.{$body}", $secret), $m[2]))->toBeTrue()
        ->and(abs(now()->getTimestamp() - (int) $m[1]))->toBeLessThan(5);

    // A second update for the same job doesn't send it again.
    gatewayJobUpdate($machine, $job, 'succeeded');
    expect(sentWebhooks())->toHaveCount(1)
        ->and(WebhookDelivery::query()->sole()->only(['state', 'attempts', 'response_status']))->toBe(['state' => 'delivered', 'attempts' => 1, 'response_status' => 200]);
});

test('deploys finishing, machines going offline and back, and operations each send their event', function () {
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202), 'hooks.example.com/*' => Http::response('ok')]);
    [, $machine, $key] = webhookWorkspace();
    addEndpoint($key, ['events' => ['deploy.finished', 'machine.offline', 'machine.online', 'operation.started', 'operation.paused', 'operation.finished']]);

    $deploy = DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'kind' => 'deploy', 'service' => 'shop', 'state' => 'running', 'command' => ['job' => ['type' => 'deploy', 'service' => 'shop']]]);
    gatewayJobUpdate($machine, $deploy, 'failed');

    $machine->forceFill(['last_seen_at' => now()->subMinutes(10)])->save();
    app(MachineWatcher::class)->run();
    $machine->forceFill(['last_seen_at' => now()])->save();
    app(MachineWatcher::class)->run();

    webhookApi($key, 'POST', 'operations', ['kind' => 'maintenance', 'machines' => ['web-1'], 'maintenance' => ['action' => 'start', 'minutes' => 30]])->assertAccepted();

    $events = collect(sentWebhooks());
    expect($events->pluck(0)->all())->toBe(['deploy.finished', 'machine.offline', 'machine.online', 'operation.started', 'operation.finished'])
        ->and($events[0][1]['data'])->toBe(['job_id' => $deploy->id, 'machine_id' => $machine->id, 'service' => 'shop', 'outcome' => 'failed'])
        ->and($events[1][1]['data'])->toBe(['machine_id' => $machine->id, 'state' => 'offline'])
        ->and($events[2][1]['data']['state'])->toBe('online')
        ->and($events[4][1]['data'])->toMatchArray(['kind' => 'maintenance', 'state' => 'finished'])
        ->and($events[4][1]['data']['progress'])->toMatchArray(['total' => 1, 'succeeded' => 1]);
});

test('a failed delivery is retried with growing waits, and stops once it gets through', function () {
    $this->freezeSecond();
    Http::fake(['hooks.example.com/*' => Http::sequence()->push('down', 500)->push('down', 503)->push('ok', 200)]);
    [$workspace, , $key] = webhookWorkspace();
    addEndpoint($key);

    Webhooks::dispatch($workspace->id, 'machine.offline', ['machine_id' => 1, 'state' => 'offline']);
    $delivery = WebhookDelivery::query()->sole();
    expect($delivery->only(['state', 'attempts', 'response_status', 'error']))->toBe(['state' => 'pending', 'attempts' => 1, 'response_status' => 500, 'error' => 'It answered 500.'])
        ->and((int) round(now()->diffInSeconds($delivery->next_attempt_at)))->toBe(60);

    Artisan::call('webhooks:retry');
    expect($delivery->refresh()->attempts)->toBe(1);

    $this->travel(61)->seconds();
    Artisan::call('webhooks:retry');
    expect($delivery->refresh()->only(['state', 'attempts']))->toBe(['state' => 'pending', 'attempts' => 2])
        ->and((int) round(now()->diffInSeconds($delivery->next_attempt_at)))->toBe(300);

    $this->travel(301)->seconds();
    Artisan::call('webhooks:retry');
    expect($delivery->refresh()->only(['state', 'attempts', 'response_status']))->toBe(['state' => 'delivered', 'attempts' => 3, 'response_status' => 200]);

    // Every attempt carried the same event id, freshly signed.
    $sent = sentWebhooks();
    expect(collect($sent)->pluck('1.id')->unique()->all())->toHaveCount(1)->and($sent)->toHaveCount(3);
});

test('an endpoint that keeps failing for 24 hours is turned off, and whoever added it gets an email', function () {
    Notification::fake();
    $up = false;
    Http::fake(['hooks.example.com/*' => function () use (&$up) {
        return $up ? Http::response('ok') : Http::response('nope', 500);
    }]);
    [$workspace, , $key] = webhookWorkspace();
    [$endpoint] = addEndpoint($key);

    Webhooks::dispatch($workspace->id, 'machine.offline', ['machine_id' => 1, 'state' => 'offline']);
    $delivery = WebhookDelivery::query()->sole();
    foreach (range(1, 40) as $ignored) {
        if ($delivery->refresh()->state !== 'pending') {
            break;
        }
        $this->travelTo($delivery->next_attempt_at->addSecond());
        Artisan::call('webhooks:retry');
    }

    expect($delivery->refresh()->state)->toBe('failed')
        ->and($delivery->attempts)->toBeGreaterThan(5)
        ->and($delivery->created_at->diffInHours(now()))->toBeLessThanOrEqual(24)
        ->and($endpoint->refresh()->enabled)->toBeFalse()
        ->and($endpoint->disabled_reason)->toContain('kept failing')
        ->and(Activity::query()->where('action', 'api.webhook_disabled')->count())->toBe(1);
    Notification::assertSentTo($workspace->owner, MonitorAlert::class, fn (MonitorAlert $mail) => str_contains($mail->subject, 'hooks.example.com'));

    // Off means no new deliveries, until someone turns it back on.
    Webhooks::dispatch($workspace->id, 'machine.offline', ['machine_id' => 1, 'state' => 'offline']);
    expect(WebhookDelivery::query()->count())->toBe(1);

    $up = true;
    webhookApi($key, 'PATCH', "webhooks/{$endpoint->id}", ['enabled' => true])->assertOk()
        ->assertJsonPath('data.webhook.enabled', true)->assertJsonPath('data.webhook.disabledReason', null);
    Webhooks::dispatch($workspace->id, 'machine.offline', ['machine_id' => 1, 'state' => 'offline']);
    expect(WebhookDelivery::query()->latest('id')->first()->state)->toBe('delivered');
});

test('a test event goes out once and says how the receiver answered, and deliveries are listed', function () {
    Http::fake(['hooks.example.com/*' => Http::sequence()->push('nope', 404)->push('ok', 200)]);
    [, , $key] = webhookWorkspace();
    [$endpoint] = addEndpoint($key);

    webhookApi($key, 'POST', "webhooks/{$endpoint->id}/test")->assertOk()
        ->assertJsonPath('data.delivered', false)
        ->assertJsonPath('data.delivery.responseStatus', 404)
        ->assertJsonPath('data.delivery.state', 'failed');
    expect($endpoint->refresh()->enabled)->toBeTrue();

    webhookApi($key, 'POST', "webhooks/{$endpoint->id}/test")->assertOk()->assertJsonPath('data.delivered', true);
    expect(sentWebhooks()[0][0])->toBe('webhook.test');

    webhookApi($key, 'GET', "webhooks/{$endpoint->id}/deliveries")->assertOk()
        ->assertJsonPath('data.total', 2)
        ->assertJsonPath('data.deliveries.0.state', 'delivered')
        ->assertJsonPath('data.deliveries.1.responseStatus', 404);
    webhookApi($key, 'GET', "webhooks/{$endpoint->id}/deliveries?state=failed")->assertOk()->assertJsonPath('data.total', 1);
});

test('only keys and people that may make changes manage endpoints, and only their own workspace’s', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    [$workspace, , $key] = webhookWorkspace();
    [$endpoint] = addEndpoint($key);
    [, $readKey] = McpToken::issueKey($workspace, $workspace->owner, 'read', [Scopes::READ], McpToken::API);

    webhookApi($readKey, 'GET', 'webhooks')->assertOk();
    webhookApi($readKey, 'POST', 'webhooks', ['url' => 'https://hooks.example.com/x'])->assertForbidden();
    webhookApi($readKey, 'DELETE', "webhooks/{$endpoint->id}")->assertForbidden();
    webhookApi($readKey, 'POST', "webhooks/{$endpoint->id}/test")->assertForbidden();

    [, , $otherKey] = webhookWorkspace();
    webhookApi($otherKey, 'PATCH', "webhooks/{$endpoint->id}", ['enabled' => false])->assertStatus(422);
    webhookApi($otherKey, 'GET', "webhooks/{$endpoint->id}/deliveries")->assertStatus(422);

    Webhooks::dispatch($workspace->id, 'job.finished', ['job_id' => 'x']);
    webhookApi($key, 'DELETE', "webhooks/{$endpoint->id}")->assertOk();
    expect(WebhookEndpoint::query()->count())->toBe(0)->and(WebhookDelivery::query()->count())->toBe(0);
});

test('a webhook problem never breaks what raised the event', function () {
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202), 'hooks.example.com/*' => fn () => throw new RuntimeException('boom')]);
    [, $machine, $key] = webhookWorkspace();
    addEndpoint($key);

    $job = DaemonJob::query()->findOrFail(webhookApi($key, 'POST', "machines/{$machine->id}/services/docker:aaa/restart")->json('data.job.id'));
    gatewayJobUpdate($machine, $job, 'succeeded');

    expect($job->refresh()->state)->toBe('succeeded')
        ->and(WebhookDelivery::query()->sole()->only(['state', 'error']))->toBe(['state' => 'pending', 'error' => 'Couldn’t connect.']);
});

test('the API settings page lists endpoints with their deliveries, and owners manage them there', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    [$workspace] = webhookWorkspace();
    $owner = $workspace->owner;
    $url = fn (string $path = '') => 'https://'.$workspace->host().'/settings/api'.$path;

    $created = $this->actingAs($owner)->postJson($url('/webhooks'), ['url' => 'https://hooks.example.com/ci?token=abc', 'events' => ['operation.finished'], 'description' => 'CI'])
        ->assertCreated();
    expect($created->json('secret'))->toStartWith('whsec_');
    $id = $created->json('id');

    $this->actingAs($owner)->postJson($url("/webhooks/{$id}/test"))->assertOk()->assertJsonPath('delivered', true);

    $this->actingAs($owner)->get($url())->assertInertia(fn ($page) => $page
        ->where('webhookEvents', Webhooks::EVENTS)
        ->where('webhooks.0.url', 'https://hooks.example.com/ci?…')
        ->where('webhooks.0.events', ['operation.finished'])
        ->where('webhooks.0.deliveries.0.event', 'webhook.test')
        ->missing('webhooks.0.secret'));

    $this->actingAs($owner)->patch($url("/webhooks/{$id}"), ['enabled' => false])->assertRedirect();
    expect(WebhookEndpoint::query()->sole()->only(['enabled', 'disabled_reason']))->toBe(['enabled' => false, 'disabled_reason' => 'Turned off by hand.']);

    $this->actingAs($owner)->postJson($url('/webhooks'), ['url' => 'https://inside.example.com/x'])->assertStatus(422)->assertJsonValidationErrors('url');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson($url('/webhooks'), ['url' => 'https://hooks.example.com/x'])->assertForbidden();
    $this->actingAs($viewer)->delete($url("/webhooks/{$id}"))->assertForbidden();

    [$other] = webhookWorkspace();
    $this->actingAs($other->owner)->delete('https://'.$other->host()."/settings/api/webhooks/{$id}")->assertNotFound();

    $this->actingAs($owner)->delete($url("/webhooks/{$id}"))->assertRedirect();
    expect(WebhookEndpoint::query()->count())->toBe(0)
        ->and(Activity::query()->where('workspace_id', $workspace->id)->whereIn('action', ['api.webhook_create', 'api.webhook_update', 'api.webhook_delete'])->count())->toBe(3);
});

test('reads the dashboard makes for itself, like rescans and logs, send no job.finished', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    [$workspace, $machine, $key] = webhookWorkspace();
    addEndpoint($key);

    foreach (['discover', 'service_logs', 'file_list'] as $kind) {
        DaemonJob::query()->create(['id' => (string) Str::uuid(), 'machine_id' => $machine->id, 'user_id' => $workspace->owner->id, 'kind' => $kind, 'state' => 'queued', 'command' => []])
            ->forceFill(['state' => 'succeeded', 'finished_at' => now()])->save();
    }

    expect(WebhookDelivery::query()->count())->toBe(0);
});

test('a delivery picked up twice at once is sent once', function () {
    Http::fake(['hooks.example.com/*' => Http::response('down', 500)]);
    [$workspace, , $key] = webhookWorkspace();
    addEndpoint($key);
    Webhooks::dispatch($workspace->id, 'machine.offline', ['machine_id' => 1, 'state' => 'offline']);
    $this->travel(61)->seconds();

    // Two workers load the same due delivery before either sends it.
    [$one, $two] = [WebhookDelivery::query()->sole(), WebhookDelivery::query()->sole()];
    app(Webhooks::class)->attempt($one);
    app(Webhooks::class)->attempt($two);

    expect(sentWebhooks())->toHaveCount(2)
        ->and(WebhookDelivery::query()->sole()->attempts)->toBe(2);
});

test('one event a receiver keeps refusing doesn’t turn off an endpoint that takes the others', function () {
    Notification::fake();
    Http::fake(['hooks.example.com/*' => fn (Request $r) => str_contains($r->body(), 'machine.offline') ? Http::response('no', 400) : Http::response('ok')]);
    [$workspace, , $key] = webhookWorkspace();
    [$endpoint] = addEndpoint($key);

    Webhooks::dispatch($workspace->id, 'machine.offline', ['machine_id' => 1, 'state' => 'offline']);
    $refused = WebhookDelivery::query()->sole();
    foreach (range(1, 40) as $ignored) {
        if ($refused->refresh()->state !== 'pending') {
            break;
        }
        $this->travelTo($refused->next_attempt_at->addSecond());
        Webhooks::dispatch($workspace->id, 'machine.online', ['machine_id' => 1, 'state' => 'online']);
        Artisan::call('webhooks:retry');
    }

    expect($refused->state)->toBe('failed')
        ->and($endpoint->refresh()->enabled)->toBeTrue();
    Notification::assertNothingSent();
});

test('an address that could be read two ways is refused, and tests are limited', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    [, , $key] = webhookWorkspace();

    foreach (['https://hooks.example.com\\@inside.example.com/x', 'https://hooks.example.com/a b', "https://hooks.example.com/a\nb", 'https:///x'] as $url) {
        webhookApi($key, 'POST', 'webhooks', ['url' => $url])->assertStatus(422);
    }

    [$endpoint] = addEndpoint($key);
    foreach (range(1, 10) as $ignored) {
        webhookApi($key, 'POST', "webhooks/{$endpoint->id}/test")->assertOk();
    }
    webhookApi($key, 'POST', "webhooks/{$endpoint->id}/test")->assertStatus(422)
        ->assertJsonPath('error.message', fn (string $m) => str_contains($m, '10 test events a minute'));
    expect(sentWebhooks())->toHaveCount(10);
});

test('a delivery looks its address up once, and one that turns private in between is refused, not sent', function () {
    Http::fake(['hooks.example.com/*' => Http::response('ok')]);
    [$workspace, , $key] = webhookWorkspace();
    addEndpoint($key);

    // From here on the name answers publicly the first time it's asked, and privately after.
    $resolver = new class extends HostResolver
    {
        public int $asked = 0;

        protected function addresses(string $host): array
        {
            return [++$this->asked === 1 ? '93.184.216.34' : '10.0.0.5'];
        }
    };
    app()->instance(HostResolver::class, $resolver);
    app()->forgetInstance(Webhooks::class);

    Webhooks::dispatch($workspace->id, 'machine.offline', ['machine_id' => 1, 'state' => 'offline']);

    expect($resolver->asked)->toBe(1)
        ->and(WebhookDelivery::query()->sole()->only(['state', 'attempts']))->toBe(['state' => 'delivered', 'attempts' => 1]);
});
