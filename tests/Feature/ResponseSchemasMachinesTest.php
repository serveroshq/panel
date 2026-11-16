<?php

use App\Models\Machine;
use App\Models\Workspace;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

test('open_port answers as it documents', function () {
    config()->set('daemon.commands_enabled', true);
    config()->set('daemon.gateway_internal_url', 'http://127.0.0.1:8090');
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    Http::fake(['127.0.0.1:8090/*' => Http::response(['queued' => true], 202)]);

    $workspace = Workspace::factory()->create();
    Machine::factory()->for($workspace)->create(['name' => 'web-1'])->forceFill(['uid' => (string) Str::uuid(), 'last_seen_at' => now(), 'inventory' => [
        'scanned_at' => now()->getTimestamp(),
        'services' => [],
        'listeners' => [],
        'firewall' => ['backend' => 'ufw', 'installed' => true, 'active' => true, 'default_incoming' => 'deny', 'rules' => [], 'ports' => [], 'unreadable' => 0, 'notes' => []],
    ]])->save();

    $opened = mcpCall($workspace, mcpKey($workspace), 'open_port', ['machine' => 'web-1', 'port' => 8443]);

    expect($opened['error'])->toBeFalse()->and($opened['data']['port'])->toBe('8443/tcp');
});
