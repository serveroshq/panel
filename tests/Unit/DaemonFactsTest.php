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

test('a private address is replaced only once the machine no longer has it', function () {
    $facts = ['interfaces' => [
        ['name' => 'cni0', 'addresses' => ['10.42.0.1']],
        ['name' => 'eth0', 'addresses' => ['192.168.8.21/24']],
        ['name' => 'eth1', 'addresses' => ['10.0.0.5/24']],
    ]];

    expect(DaemonFacts::address($facts, '192.168.1.21'))->toBe('192.168.8.21')
        ->and(DaemonFacts::address($facts, '10.0.0.5'))->toBe('10.0.0.5')
        ->and(DaemonFacts::address($facts, '198.51.100.7'))->toBe('198.51.100.7')
        ->and(DaemonFacts::address($facts, '0.0.0.0'))->toBe('192.168.8.21')
        ->and(DaemonFacts::address(['interfaces' => []], '192.168.1.21'))->toBe('192.168.1.21');
});

test('an address the machine still has on a skipped interface is kept', function () {
    $facts = ['interfaces' => [
        ['name' => 'eth0', 'addresses' => ['192.168.1.20/24']],
        ['name' => 'wg0', 'addresses' => ['10.8.0.5/24']],
        ['name' => 'wt0', 'addresses' => ['100.92.14.7/16']],
    ]];

    expect(DaemonFacts::address($facts, '10.8.0.5'))->toBe('10.8.0.5')
        ->and(DaemonFacts::address($facts, '100.92.14.7'))->toBe('100.92.14.7')
        ->and(DaemonFacts::address($facts, '100.64.0.9'))->toBe('192.168.1.20')
        ->and(DaemonFacts::address($facts, '2604:a00::1'))->toBe('2604:a00::1');
});

test('a new private address never comes from a container or cluster interface', function (string $name) {
    $facts = ['interfaces' => [
        ['name' => $name, 'addresses' => ['10.0.0.81/24']],
        ['name' => 'eno1', 'addresses' => ['192.168.4.135/24']],
    ]];

    expect(DaemonFacts::address($facts, '192.168.1.135'))->toBe('192.168.4.135');
})->with(['cilium_host', 'kube-ipvs0', 'podman0', 'lxdbr0', 'incusbr0', 'docker0', 'wg0']);

test('a machine on wifi gets its wifi address, not a vpn or project network listed before it', function () {
    // The daemon sends interfaces sorted by name, so wlan0 comes last.
    $facts = ['interfaces' => [
        ['name' => 'docker0', 'addresses' => ['172.17.0.1/16']],
        ['name' => 'lxcbr0', 'addresses' => ['10.0.3.1/24']],
        ['name' => 'sosp7k2q9', 'addresses' => ['10.88.3.2/24']],
        ['name' => 'tun0', 'addresses' => ['10.8.0.6/24']],
        ['name' => 'wlan0', 'addresses' => ['192.168.4.20/24']],
        ['name' => 'zt5u4abcde', 'addresses' => ['172.27.1.9/16']],
    ]];

    expect(DaemonFacts::address($facts, '192.168.1.20'))->toBe('192.168.4.20')
        ->and(DaemonFacts::address($facts, '10.88.3.2'))->toBe('10.88.3.2');
});
