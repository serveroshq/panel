<?php

use App\Support\DaemonFacts;

test('Docker\'s bridge is docker0\'s IPv4, or the usual 172.17.0.1', function () {
    expect(DaemonFacts::dockerBridge(['interfaces' => [['name' => 'docker0', 'addresses' => ['fe80::42:1', '172.18.0.1/16']]]]))->toBe('172.18.0.1')
        ->and(DaemonFacts::dockerBridge(['interfaces' => [['name' => 'br-1a2b', 'addresses' => ['172.19.0.1']]]]))->toBe('172.17.0.1')
        ->and(DaemonFacts::dockerBridge([]))->toBe('172.17.0.1');
});

test('a machine shows its public IPv4 even when the daemon dials in over IPv6', function () {
    $facts = ['interfaces' => [
        ['name' => 'lo', 'addresses' => ['127.0.0.1', '::1']],
        ['name' => 'docker0', 'addresses' => ['172.17.0.1']],
        ['name' => 'eth0', 'addresses' => ['2604:a00:50:1eb:216:3eff:fe33:637a', '10.0.0.5', '203.0.113.10']],
    ]];

    expect(DaemonFacts::address($facts, '2604:a00:50:1eb:216:3eff:fe33:637a'))->toBe('203.0.113.10')
        ->and(DaemonFacts::address(['interfaces' => []], '2604:a00::1'))->toBe('2604:a00::1')
        ->and(DaemonFacts::address($facts, '127.0.0.1'))->toBe('127.0.0.1');
});

test('addresses the internet can\'t reach are never a machine\'s public address, whatever the interface is called', function (string $address) {
    $facts = ['interfaces' => [['name' => 'eth0', 'addresses' => ['10.0.0.5/24']], ['name' => 'anything0', 'addresses' => [$address]]]];

    expect(DaemonFacts::publicAddress($facts))->toBeNull()
        ->and(DaemonFacts::address($facts, '198.51.100.20'))->toBe('198.51.100.20');
})->with([
    'netbird' => '100.92.14.7/16',
    'carrier NAT, lowest' => '100.64.0.1',
    'carrier NAT, highest' => '100.127.255.254',
    'IETF protocol assignments' => '192.0.0.8',
    'benchmarking' => '198.19.0.1',
    'private' => '192.168.1.10/24',
    'link-local' => '169.254.10.1',
]);

test('a public address next to an overlay address is still found', function () {
    $facts = ['interfaces' => [
        ['name' => 'wt0', 'addresses' => ['100.92.14.7/16']],
        ['name' => 'eth0', 'addresses' => ['10.0.0.5/24', '203.0.113.10/24']],
    ]];

    expect(DaemonFacts::publicAddress($facts))->toBe('203.0.113.10')
        ->and(DaemonFacts::publicAddress(['interfaces' => [['name' => 'eth0', 'addresses' => ['100.128.0.1/24']]]]))->toBe('100.128.0.1');
});
