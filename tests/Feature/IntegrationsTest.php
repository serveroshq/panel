<?php

use App\Enums\Plan;
use App\Models\BackupDestination;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use Inertia\Testing\AssertableInertia as Assert;

function integrationsUrl(Workspace $workspace, string $path = '/integrations'): string
{
    return $workspace->url().$path;
}

/** @return array<string, array<string, mixed>> */
function integrationsByKey(Workspace $workspace, ?User $user = null): array
{
    $props = test()->actingAs($user ?? $workspace->owner)->get(integrationsUrl($workspace))->assertOk()->viewData('page')['props'];

    return collect($props['integrations'])->keyBy('key')->all();
}

/** @return array<string, mixed> */
function integrationsProps(Workspace $workspace, ?User $user = null): array
{
    return test()->actingAs($user ?? $workspace->owner)->get(integrationsUrl($workspace))->assertOk()->viewData('page')['props'];
}

function integrationsMember(Workspace $workspace, string $role, ?int $customRole = null): User
{
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => $role, 'custom_role_id' => $customRole]);

    return $user;
}

function integrationsDestination(Workspace $workspace, string $name, ?string $endpoint, ?Machine $target = null): BackupDestination
{
    $destination = new BackupDestination([
        'name' => $name,
        'type' => $endpoint === null ? 'machine' : 's3',
        'settings' => $endpoint === null ? null : ['endpoint' => $endpoint, 'region' => 'auto', 'bucket' => 'b', 'access_key' => 'k', 'secret_key' => 's', 'path_style' => true, 'prefix' => ''],
        'machine_id' => $target?->id,
    ]);
    $destination->workspace_id = $workspace->id;
    $destination->save();

    return $destination;
}

/** @param  array<string, mixed>  $rules */
function integrationsWorkflow(Workspace $workspace, string $name, array $rules, bool $enabled = true): void
{
    $workspace->workflows()->create([
        'name' => $name,
        'enabled' => $enabled,
        'trigger' => 'machine.offline',
        'graph' => ['nodes' => [
            ['id' => 't', 'kind' => 'trigger', 'x' => 0, 'y' => 0, 'title' => '', 'description' => '', 'rules' => ['event' => 'machine.offline']],
            ['id' => 'h', 'kind' => 'http', 'x' => 0, 'y' => 200, 'title' => '', 'description' => '', 'rules' => ['method' => 'POST', ...$rules]],
        ], 'edges' => [['id' => 'e', 'source' => 't', 'port' => 'out', 'target' => 'h']]],
    ]);
}

test('the page lists the whole catalogue by category, with nothing connected in a new workspace', function () {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->get(integrationsUrl($workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('workspace/Integrations')
            ->has('integrations', 27)
            ->has('categories', 4)
            ->where('categories.0.key', 'developer')
            ->where('categories.3.icon', 'sparkle-fill')
            ->where('integrations.0.key', 'github')
            ->where('integrations.0.tags', ['Deploys', 'Previews', 'Checks'])
            ->where('canConnect', true)
            ->has('connections', 0)
            ->has('alerts'));

    $items = integrationsByKey($workspace);
    expect(collect($items)->where('connected', true))->toBeEmpty()
        ->and(collect($items)->countBy('category')->all())->toBe(['developer' => 5, 'communication' => 6, 'cloud' => 10, 'ai' => 6])
        ->and($items['teams']['initials'])->toBe('MT')
        ->and($items['openrouter']['initials'])->toBe('OR')
        ->and($items['slack']['action'])->toBe(['label' => 'Connect', 'alerts' => true])
        ->and($items['docker-hub']['action'])->toBe(['label' => 'Connect', 'href' => '/settings/general#registries'])
        ->and($items['backblaze']['action'])->toBe(['label' => 'Connect', 'href' => '/backups/destinations'])
        ->and($items['anthropic']['action'])->toBe(['label' => 'Connect', 'href' => '/settings/mcp'])
        ->and($items['pagerduty']['action'])->toBe(['label' => 'Connect', 'connect' => 'pagerduty']);
});

test('what the panel can’t connect yet is never connected and opens the not available dialog', function () {
    $workspace = Workspace::factory()->create();
    Machine::factory()->for($workspace)->create(['provider' => 'Hetzner']);
    $workspace->forceFill(['alert_webhook_url' => 'https://acme.webhook.office.com/hook'])->save();

    $items = integrationsByKey($workspace);

    foreach (['teams', 'aws', 'synteq', 'ovhcloud', 'proxmox', 'tailscale', 'n8n', 'grafana'] as $key) {
        expect($items[$key]['connected'])->toBeFalse($key)
            ->and($items[$key]['action'])->toBe(['label' => 'Connect', 'unavailable' => true], $key);
    }
    foreach (['hetzner', 'digitalocean', 'vultr', 'gitlab', 'sentry', 'pagerduty', 'linear'] as $key) {
        expect($items[$key]['connected'])->toBeFalse($key)
            ->and($items[$key]['action'])->toBe(['label' => 'Connect', 'connect' => $key], $key);
    }
});

test('GitHub is connected from the workspace’s real installations, and connects through the GitHub App when it is set up', function () {
    $workspace = Workspace::factory()->create();

    expect(integrationsByKey($workspace)['github']['action'])->toBe(['label' => 'Connect', 'unavailable' => true]);

    config()->set('services.github_app', ['id' => '1', 'slug' => 'serveros', 'client_id' => 'Iv1.abc', 'client_secret' => 'shh', 'private_key' => base64_encode('key')]);
    expect(integrationsByKey($workspace)['github']['action'])->toBe(['label' => 'Connect', 'href' => '/settings/github/connect']);

    $workspace->githubInstallations()->create(['installation_id' => 77, 'account_login' => 'acme', 'account_type' => 'Organization', 'repository_ids' => [1, 2, 3, 4, 5, 6]]);
    $props = integrationsProps($workspace);
    $github = collect($props['integrations'])->firstWhere('key', 'github');

    expect($github['connected'])->toBeTrue()
        ->and($github['action'])->toBe(['label' => 'Manage', 'href' => '/settings/general#github'])
        ->and($props['connections'][0])->toMatchArray(['key' => 'github', 'name' => 'GitHub', 'logo' => 'github', 'detail' => 'acme · 6 repositories']);

    $workspace->githubInstallations()->create(['installation_id' => 78, 'account_login' => 'sam', 'account_type' => 'User']);
    expect(collect(integrationsProps($workspace)['connections'])->firstWhere('key', 'github')['detail'])->toBe('acme and 1 more account');
});

test('the alert webhook is Slack, Discord or a plain webhook from its address, and Telegram from its chat', function () {
    $workspace = Workspace::factory()->create();
    $workspace->forceFill(['alert_webhook_url' => 'https://hooks.slack.com/services/T0/B0/x'])->save();

    $items = integrationsByKey($workspace);
    expect($items['slack']['connected'])->toBeTrue()
        ->and($items['slack']['action'])->toBe(['label' => 'Manage', 'alerts' => true])
        ->and($items['discord']['connected'])->toBeFalse()
        ->and(collect(integrationsProps($workspace)['connections'])->firstWhere('key', 'slack')['detail'])->toBe('Alerts to your channel');

    $workspace->forceFill(['alert_webhook_url' => 'https://discord.com/api/webhooks/1/x'])->save();
    $items = integrationsByKey($workspace);
    expect($items['discord']['connected'])->toBeTrue()->and($items['slack']['connected'])->toBeFalse();

    $workspace->forceFill([
        'alert_webhook_url' => 'https://alerts.acme.dev/in',
        'alert_telegram_chat' => '-1001234567890',
        'alert_telegram_token' => '123456:'.str_repeat('a', 35),
    ])->save();
    $props = integrationsProps($workspace);
    $items = collect($props['integrations'])->keyBy('key');

    expect($items['slack']['connected'])->toBeFalse()
        ->and($items['discord']['connected'])->toBeFalse()
        ->and($items['telegram']['connected'])->toBeTrue()
        ->and(collect($props['connections'])->firstWhere('key', 'telegram')['detail'])->toBe('Alerts to -1001234567890')
        ->and(collect($props['connections'])->firstWhere('key', 'webhook'))->toMatchArray(['name' => 'Webhook', 'detail' => 'Alerts to alerts.acme.dev', 'target' => ['alerts' => true]]);
});

test('workflow HTTP steps to Slack, Discord and PagerDuty count once they’re set up and switched on', function () {
    $workspace = Workspace::factory()->create();
    integrationsWorkflow($workspace, 'Page on-call', ['url' => 'https://events.pagerduty.com/v2/enqueue', 'body' => '{"routing_key": "YOUR_ROUTING_KEY"}']);
    integrationsWorkflow($workspace, 'Tell Slack', ['url' => 'https://hooks.slack.com/services/T0/B0/x', 'body' => '{}'], enabled: false);

    $items = integrationsByKey($workspace);
    expect($items['pagerduty']['connected'])->toBeFalse()->and($items['slack']['connected'])->toBeFalse();

    integrationsWorkflow($workspace, 'Page the team', ['url' => 'https://events.pagerduty.com/v2/enqueue', 'body' => '{"routing_key": "R0UT1NG"}']);
    integrationsWorkflow($workspace, 'Post deploys', ['url' => 'https://hooks.slack.com/services/T0/B0/y', 'body' => '{}']);
    $workflow = $workspace->workflows()->where('name', 'Post deploys')->sole();

    $props = integrationsProps($workspace);
    $items = collect($props['integrations'])->keyBy('key');
    expect($items['pagerduty']['connected'])->toBeTrue()
        ->and($items['slack']['connected'])->toBeTrue()
        ->and($items['slack']['action'])->toBe(['label' => 'Manage', 'href' => "/workflows/{$workflow->id}"])
        ->and(collect($props['connections'])->firstWhere('key', 'pagerduty')['detail'])->toBe('Used by Page the team');
});

test('container registries, backup destinations and machine tunnels come from what the workspace has', function () {
    $workspace = Workspace::factory()->create();
    $web = Machine::factory()->for($workspace)->create(['name' => 'web-1', 'provider' => 'Hetzner']);
    $db = Machine::factory()->for($workspace)->create(['name' => 'db-1']);
    $spare = Machine::factory()->for($workspace)->create(['name' => 'spare-1', 'provider' => 'Vultr']);
    $workspace->containerRegistries()->create(['host' => 'docker.io', 'username' => 'acme', 'password' => 'secret']);
    $workspace->containerRegistries()->create(['host' => 'ghcr.io', 'username' => 'acme-bot', 'password' => 'secret']);
    $b2 = integrationsDestination($workspace, 'offsite', 's3.eu-central-003.backblazeb2.com');
    $s3 = integrationsDestination($workspace, 'archive', 's3.eu-west-2.amazonaws.com');
    $sftp = integrationsDestination($workspace, 'spare', null, $spare);
    $web->forceFill(['backup_destination_id' => $b2->id, 'backup_schedule' => 'daily', 'tunnel' => ['provider' => 'cloudflare', 'tunnel_id' => 'x', 'state' => 'running', 'plain_http' => true, 'since' => now()->toIso8601String()]])->save();
    $db->forceFill(['backup_destination_id' => $sftp->id, 'backup_schedule' => 'weekly'])->save();

    $props = integrationsProps($workspace);
    $items = collect($props['integrations'])->keyBy('key');
    $connections = collect($props['connections'])->keyBy('key');

    expect($items['docker-hub']['connected'])->toBeTrue()
        ->and($connections['docker-hub']['detail'])->toBe('Signed in as acme')
        ->and($connections["registry-{$workspace->containerRegistries()->where('host', 'ghcr.io')->value('id')}"])->toMatchArray(['name' => 'GitHub Container Registry', 'logo' => 'github'])
        ->and($items['backblaze']['connected'])->toBeTrue()
        ->and($items['backblaze']['action'])->toBe(['label' => 'Manage', 'href' => '/backups/destinations'])
        ->and($connections['backblaze']['detail'])->toBe('Daily backups of web-1')
        ->and($items['r2']['connected'])->toBeFalse()
        ->and($connections["backups-{$s3->id}"])->toMatchArray(['name' => 'Amazon S3', 'logo' => 'aws', 'detail' => 'No machine backs up here yet'])
        ->and($connections["backups-{$sftp->id}"])->toMatchArray(['name' => 'spare-1 over SFTP', 'logo' => 'Vultr', 'detail' => 'Weekly backups of db-1'])
        ->and($items['cloudflare']['connected'])->toBeTrue()
        ->and($items['cloudflare']['action'])->toBe(['label' => 'Connect', 'connect' => 'cloudflare'])
        ->and($connections['cloudflare']['detail'])->toBe('Tunnel on web-1')
        ->and($items['aws']['connected'])->toBeFalse()
        ->and($items['hetzner']['connected'])->toBeFalse();
});

test('AI keys connect Anthropic and OpenAI, and an OpenAI-compatible address can be OpenRouter or Ollama', function () {
    $workspace = Workspace::factory()->create();
    $key = fn (string $provider, ?string $url = null) => WorkspaceAiKey::query()->updateOrCreate(
        ['workspace_id' => $workspace->id, 'provider' => $provider],
        ['key' => 'sk-test-key-0000000000000000', 'hint' => '…0000', 'model' => $url ? 'some-model' : null, 'base_url' => $url],
    );
    $key('openai');
    $key('custom', 'https://openrouter.ai/api/v1');

    $props = integrationsProps($workspace);
    $items = collect($props['integrations'])->keyBy('key');
    $connections = collect($props['connections'])->keyBy('key');
    expect($items['openai']['connected'])->toBeTrue()
        ->and($items['openai']['action'])->toBe(['label' => 'Manage', 'href' => '/settings/mcp'])
        ->and($connections['openai']['detail'])->toBe('Agents use your key')
        ->and($items['openrouter']['connected'])->toBeTrue()
        ->and($connections['openrouter']['detail'])->toBe('Used when you pick its models')
        ->and($items['anthropic']['connected'])->toBeFalse()
        ->and($items['ollama']['connected'])->toBeFalse();

    $key('anthropic');
    $key('custom', 'https://ollama.acme.dev:11434/v1');
    $props = integrationsProps($workspace);
    $items = collect($props['integrations'])->keyBy('key');
    expect(collect($props['connections'])->firstWhere('key', 'anthropic')['detail'])->toBe('Agents use your key')
        ->and($items['ollama']['connected'])->toBeTrue()
        ->and($items['openrouter']['connected'])->toBeFalse();

    $key('custom', 'https://api.deepseek.com/v1');
    $props = integrationsProps($workspace);
    expect(collect($props['integrations'])->firstWhere('key', 'ollama')['connected'])->toBeFalse()
        ->and(collect($props['connections'])->firstWhere('key', 'ai-custom'))->toMatchArray(['name' => 'OpenAI-compatible']);
});

test('people without the permission a setting needs see the page read-only', function () {
    $workspace = Workspace::factory()->onPlan(Plan::Business)->create();
    $workspace->forceFill(['alert_telegram_chat' => '-100999', 'alert_telegram_token' => '123456:'.str_repeat('a', 35)])->save();
    $workspace->containerRegistries()->create(['host' => 'docker.io', 'username' => 'acme', 'password' => 'secret']);
    WorkspaceAiKey::query()->create(['workspace_id' => $workspace->id, 'provider' => 'anthropic', 'key' => 'sk-ant-xxxxxxxxxxxxxxxxxxxx', 'hint' => '…xxxx']);
    $viewer = integrationsMember($workspace, 'viewer');

    $this->actingAs($viewer)->get(integrationsUrl($workspace))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canConnect', false)->where('alerts', null));

    $props = integrationsProps($workspace, $viewer);
    $connections = collect($props['connections'])->keyBy('key');
    expect(collect($props['integrations'])->pluck('action')->filter()->all())->toBe([])
        ->and($connections['telegram']['detail'])->toBe('Alerts')
        ->and($connections['telegram']['target'])->toBeNull()
        ->and($connections['docker-hub']['detail'])->toBe('Private images')
        ->and($connections['anthropic']['target'])->toBeNull();

    $member = integrationsMember($workspace, 'member');
    $items = integrationsByKey($workspace, $member);
    expect($items['telegram']['action'])->toBe(['label' => 'Manage', 'alerts' => true])
        ->and($items['anthropic']['action'])->toBeNull()
        ->and($items['gitlab']['action'])->toBe(['label' => 'Connect', 'connect' => 'gitlab']);

    $settingsOnly = $workspace->customRoles()->create(['name' => 'Settings', 'permissions' => ['manage-settings']]);
    $keeper = integrationsMember($workspace, 'viewer', $settingsOnly->id);
    $items = integrationsByKey($workspace, $keeper);
    expect($items['anthropic']['action'])->toBe(['label' => 'Manage', 'href' => '/settings/mcp'])
        ->and($items['telegram']['action'])->toBeNull()
        ->and($items['docker-hub']['action'])->toBeNull();
});

test('the Integrations card shows on Home once for people who can connect, until they dismiss it or open the page', function () {
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;
    $owner->forceFill(['integrations_promo_seen_at' => null])->save();
    $viewer = integrationsMember($workspace, 'viewer');
    $home = fn (User $user) => $this->actingAs($user)->get($workspace->url().'/')->assertOk();

    $home($owner)->assertInertia(fn (Assert $page) => $page->where('integrationsPromo', true));
    $home($viewer)->assertInertia(fn (Assert $page) => $page->where('integrationsPromo', false));

    $this->actingAs($owner)->post(integrationsUrl($workspace, '/integrations/promo/dismiss'))->assertRedirect();
    expect($owner->fresh()->integrations_promo_seen_at)->not->toBeNull();
    $home($owner)->assertInertia(fn (Assert $page) => $page->where('integrationsPromo', false));

    $member = integrationsMember($workspace, 'member');
    $home($member)->assertInertia(fn (Assert $page) => $page->where('integrationsPromo', true));
    $this->actingAs($member)->get(integrationsUrl($workspace))->assertOk();
    expect($member->fresh()->integrations_promo_seen_at)->not->toBeNull();
    $home($member)->assertInertia(fn (Assert $page) => $page->where('integrationsPromo', false));
});

test('Integrations sits in the main sidebar under Activity, and stays lit on an account page', function () {
    $nav = (string) file_get_contents(resource_path('js/components/workspace/workspaceNav.ts'));

    expect($nav)->toMatch("/key: 'activity',.*?key: 'integrations',\s+label: trans\('Integrations'\),\s+icon: 'package-fill',\s+href: url\(onWorkspace\(integrations\(slug\)\)\),\s+section: 'menu',\s+current: is\(\s*component,\s*'workspace\/Integrations',\s*'workspace\/IntegrationShow',\s*\)/s")
        ->and(route('workspace.integrations', ['workspace' => 'acme'], false))->toEndWith('/acme/integrations');
});

test('a self-hosted panel says what works today in the not available dialog', function () {
    config()->set('serveros.edition', 'self-hosted');
    $workspace = Workspace::factory()->create();
    $member = integrationsMember($workspace, 'member');

    $items = integrationsByKey($workspace, $member);

    expect($items['cloudflare']['action'])->toBe(['label' => 'Connect', 'connect' => 'cloudflare'])
        ->and($items['github']['action']['hint'])->toMatchArray(['href' => null, 'label' => null]);
});
