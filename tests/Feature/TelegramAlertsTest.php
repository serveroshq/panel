<?php

use App\Enums\Plan;
use App\Mcp\Tools\MonitorTools;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Monitors\MonitorAlerts;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;

const TELEGRAM_TOKEN = '123456789:AAHdqTcvCH1vGWJxfSeofSAs0K5PALDsawQ';

function telegramWorkspace(array $attributes = []): Workspace
{
    return Workspace::factory()->onPlan(Plan::Pro)->for(User::factory()->create(), 'owner')->create(['name' => 'Acme', ...$attributes]);
}

test('a Telegram chat is saved from the alerts dialog with its token encrypted and never sent back', function () {
    $this->withoutVite();
    $workspace = telegramWorkspace();
    $url = 'https://'.$workspace->host().'/monitor-alerts';

    $this->actingAs($workspace->owner)->put($url, ['email' => true, 'webhook' => '', 'telegram_chat' => '-1001234567890', 'telegram_token' => TELEGRAM_TOKEN])->assertRedirect();

    $workspace->refresh();
    expect($workspace->alert_telegram_chat)->toBe('-1001234567890')
        ->and($workspace->alert_telegram_token)->toBe(TELEGRAM_TOKEN)
        ->and(DB::table('workspaces')->where('id', $workspace->id)->value('alert_telegram_token'))->not->toContain('AAHdqTcv')
        ->and(json_encode($workspace->toArray()))->not->toContain('AAHdqTcv');

    $page = $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/monitors');
    $page->assertInertia(fn (AssertableInertia $p) => $p->where('alerts.telegram', ['chat' => '-1001234567890', 'set' => true]));
    expect($page->getContent())->not->toContain('AAHdqTcv');

    $settings = $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/settings/general');
    $settings->assertInertia(fn (AssertableInertia $p) => $p->where('alerts.telegram.set', true));
    expect($settings->getContent())->not->toContain('AAHdqTcv');
});

test('the token can be left out to keep it, a bad chat id is turned down, and an empty chat removes Telegram', function () {
    $workspace = telegramWorkspace();
    $url = 'https://'.$workspace->host().'/monitor-alerts';
    $owner = $workspace->owner;

    $this->actingAs($owner)->put($url, ['email' => true, 'telegram_chat' => 'my group'])->assertSessionHasErrors('telegram_chat');
    $this->actingAs($owner)->put($url, ['email' => true, 'telegram_chat' => '@acme_alerts', 'telegram_token' => 'nope'])->assertSessionHasErrors('telegram_token');
    $this->actingAs($owner)->put($url, ['email' => true, 'telegram_chat' => '@acme_alerts'])->assertSessionHasErrors(['telegram_token' => 'Add the bot’s token from @BotFather too.']);
    expect($workspace->fresh()->alert_telegram_chat)->toBeNull();

    $this->actingAs($owner)->put($url, ['email' => true, 'telegram_chat' => '@acme_alerts', 'telegram_token' => TELEGRAM_TOKEN])->assertSessionHasNoErrors();
    $this->actingAs($owner)->put($url, ['email' => true, 'telegram_chat' => '-42', 'telegram_token' => ''])->assertSessionHasNoErrors();
    expect($workspace->fresh())->alert_telegram_chat->toBe('-42')->alert_telegram_token->toBe(TELEGRAM_TOKEN);

    // An older dialog that doesn't know about Telegram leaves it alone.
    $this->actingAs($owner)->put($url, ['email' => false, 'webhook' => ''])->assertSessionHasNoErrors();
    expect($workspace->fresh()->alert_telegram_chat)->toBe('-42');

    $this->actingAs($owner)->put($url, ['email' => true, 'telegram_chat' => ''])->assertSessionHasNoErrors();
    expect($workspace->fresh())->alert_telegram_chat->toBeNull()->alert_telegram_token->toBeNull();
});

test('alerts go to the Telegram chat, except kinds switched off', function () {
    Notification::fake();
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $workspace = telegramWorkspace(['alert_telegram_chat' => '-1001234567890', 'alert_telegram_token' => TELEGRAM_TOKEN, 'alert_muted' => ['services']]);
    $alerts = app(MonitorAlerts::class);

    $alerts->notify($workspace, 'service.down', 'web stopped', ['A line.'], 'https://acme.test', true, 'Open it');
    Http::assertNothingSent();

    $alerts->notify($workspace, 'backup.failed', 'db couldn’t be backed up', ['Disk full.'], 'https://acme.test/backups', true, 'Open it');
    Http::assertSentCount(1);
    Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://api.telegram.org/bot'.TELEGRAM_TOKEN.'/sendMessage'
        && $r['chat_id'] === '-1001234567890'
        && str_starts_with($r['text'], 'db couldn’t be backed up: Disk full. https://acme.test/backups'));
});

test('a Telegram failure is logged without the token', function () {
    Notification::fake();
    Http::fake(fn () => throw new ConnectionException('cURL error 6 for https://api.telegram.org/bot'.TELEGRAM_TOKEN.'/sendMessage'));
    Log::spy();
    $workspace = telegramWorkspace(['alert_telegram_chat' => '-1', 'alert_telegram_token' => TELEGRAM_TOKEN]);

    app(MonitorAlerts::class)->notify($workspace, 'backup.failed', 'Backup failed', [], 'https://acme.test', true, 'Open it');

    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context) => $message === 'Telegram alert failed'
        && $context['error'] === 'Couldn’t reach Telegram.'
        && ! str_contains(json_encode($context), 'AAHdqTcv'));
});

test('a test message goes to the chat typed in, with the saved token when none is typed', function () {
    Http::fake(fn (HttpRequest $r) => match ($r['chat_id']) {
        '-1' => Http::response(['ok' => true]),
        '-2' => Http::response(['ok' => false, 'error_code' => 400, 'description' => 'Bad Request: chat not found'], 400),
        default => Http::response(['ok' => false, 'description' => 'Unauthorized'], 401),
    });
    $workspace = telegramWorkspace(['alert_telegram_chat' => '-1', 'alert_telegram_token' => TELEGRAM_TOKEN]);
    $url = 'https://'.$workspace->host().'/monitor-alerts/test';
    $owner = $workspace->owner;

    $this->actingAs($owner)->postJson($url, ['channel' => 'telegram', 'telegram_chat' => '-1'])->assertOk()->assertExactJson(['ok' => true, 'error' => null]);
    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), TELEGRAM_TOKEN) && str_contains($r['text'], 'alerts for Acme will arrive here'));

    $this->actingAs($owner)->postJson($url, ['channel' => 'telegram', 'telegram_chat' => '-2'])->assertJson(['ok' => false, 'error' => 'Telegram couldn’t find that chat. Add the bot to the group or channel first, or send the bot a message.']);
    $this->actingAs($owner)->postJson($url, ['channel' => 'telegram', 'telegram_chat' => '-3', 'telegram_token' => '999:'.str_repeat('x', 35)])->assertJson(['ok' => false, 'error' => 'Telegram didn’t accept the bot token.']);
    $this->actingAs($owner)->postJson($url, ['channel' => 'telegram', 'telegram_chat' => 'group'])->assertUnprocessable()->assertJsonValidationErrors('telegram_chat');

    $viewer = User::factory()->create();
    $workspace->members()->attach($viewer, ['role' => 'viewer']);
    $this->actingAs($viewer)->postJson($url, ['channel' => 'telegram', 'telegram_chat' => '-1'])->assertForbidden();
});

test('MCP sets Telegram, never answers with the token, and sends a test to it', function () {
    config()->set('mcp.groups', [MonitorTools::class]);
    Http::fake(['api.telegram.org/*' => Http::response(['ok' => true])]);
    $workspace = telegramWorkspace();
    $key = mcpKey($workspace);

    expect(mcpCall($workspace, $key, 'update_alert_settings', ['telegram_chat' => '-100'])['text'])->toContain('Add the bot’s token from @BotFather too.')
        ->and(mcpCall($workspace, $key, 'update_alert_settings', ['telegram_chat' => 'not a chat', 'telegram_token' => TELEGRAM_TOKEN])['text'])->toContain('A chat id is a number');

    $saved = mcpCall($workspace, $key, 'update_alert_settings', ['telegram_chat' => '-100', 'telegram_token' => TELEGRAM_TOKEN]);
    expect($saved['error'])->toBeFalse()
        ->and($saved['data']['telegram'])->toBe(['set' => true, 'chat' => '-100'])
        ->and($saved['text'])->not->toContain('AAHdqTcv')
        ->and($workspace->fresh()->alert_telegram_token)->toBe(TELEGRAM_TOKEN);

    $read = mcpCall($workspace, $key, 'get_alert_settings');
    expect($read['data']['telegram'])->toBe(['set' => true, 'chat' => '-100'])->and($read['text'])->not->toContain('AAHdqTcv');

    $test = mcpCall($workspace, $key, 'send_test_alert', ['channel' => 'telegram']);
    expect($test['data'])->toMatchArray(['ok' => true, 'channel' => 'telegram', 'chat' => '-100'])->and($test['text'])->not->toContain('AAHdqTcv');
    Http::assertSent(fn (HttpRequest $r) => $r['chat_id'] === '-100');

    expect(DB::table('mcp_tool_calls')->pluck('arguments')->implode(' '))->not->toContain('AAHdqTcv');

    mcpCall($workspace, $key, 'update_alert_settings', ['telegram_chat' => '']);
    expect($workspace->fresh())->alert_telegram_chat->toBeNull()->alert_telegram_token->toBeNull();
});
