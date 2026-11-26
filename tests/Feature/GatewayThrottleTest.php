<?php

use Illuminate\Support\Str;

test('one busy machine does not use up the gateway limit for every other machine', function () {
    config()->set('daemon.gateway_secret', 'test-gateway-secret');
    $busy = (string) Str::uuid();
    $quiet = (string) Str::uuid();
    $ingest = fn (string $uid) => $this->postJson('/api/gateway/machines/'.$uid.'/ingest', ['envelopes' => []], ['X-Gateway-Secret' => 'test-gateway-secret']);

    for ($i = 0; $i < 600; $i++) {
        expect($ingest($busy)->status())->not->toBe(429);
    }

    $ingest($busy)->assertStatus(429);
    expect($ingest($quiet)->status())->not->toBe(429);
    expect($this->postJson('/api/gateway/machines/'.$quiet.'/closed', [], ['X-Gateway-Secret' => 'test-gateway-secret'])->status())->not->toBe(429);
});
