<?php

namespace App\Support;

use Symfony\Component\HttpFoundation\IpUtils;

final class DaemonFacts
{
    /** @param array<string, mixed> $facts */
    public static function provider(array $facts): ?string
    {
        $provider = $facts['hosting']['provider'] ?? null;

        return in_array($provider, ['AWS', 'Google Cloud', 'Azure', 'Hetzner', 'DigitalOcean', 'Linode'], true)
            ? $provider
            : null;
    }

    /** @param  array<string, mixed>  $facts */
    public static function publicAddress(array $facts): ?string
    {
        foreach (self::addresses($facts, skipVirtual: true) as $address) {
            if (self::routable($address)) {
                return $address;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $facts */
    public static function privateAddress(array $facts): ?string
    {
        foreach (self::addresses($facts, skipVirtual: true) as $address) {
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                && filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE) === false) {
                return $address;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<string>
     */
    private static function addresses(array $facts, bool $skipVirtual): array
    {
        $addresses = [];
        foreach ($facts['interfaces'] ?? [] as $interface) {
            if ($skipVirtual && preg_match('/^(lo|docker|br-|veth|virbr|cni|flannel|tailscale|wg|cilium|kube|podman|lxdbr|lxcbr|incusbr|sos|tun|tap|zt|wt)/', (string) ($interface['name'] ?? ''))) {
                continue;
            }
            foreach ($interface['addresses'] ?? [] as $address) {
                $addresses[] = explode('/', (string) $address)[0];
            }
        }

        return $addresses;
    }

    /**
     * IPv4 ranges that PHP's private and reserved range flags let through but
     * the internet can't reach: shared address space for carrier NAT and
     * overlay networks like Netbird and Tailscale (RFC 6598), IETF protocol
     * assignments (RFC 6890) and benchmarking (RFC 2544).
     */
    private const array NOT_ROUTED = ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15'];

    private static function routable(string $address): bool
    {
        return filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! IpUtils::checkIp($address, self::NOT_ROUTED);
    }

    private static function isPublic(?string $address): bool
    {
        return is_string($address) && (self::routable($address)
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false);
    }

    public const DEFAULT_BRIDGE = '172.17.0.1';

    /** @param  array<string, mixed>  $facts */
    public static function dockerBridge(array $facts): string
    {
        foreach ($facts['interfaces'] ?? [] as $interface) {
            if (($interface['name'] ?? null) !== 'docker0') {
                continue;
            }
            foreach ($interface['addresses'] ?? [] as $address) {
                $address = explode('/', (string) $address)[0];
                if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    return $address;
                }
            }
        }

        return self::DEFAULT_BRIDGE;
    }

    /** @param  array<string, mixed>  $facts */
    public static function address(array $facts, ?string $current): ?string
    {
        if (in_array($current, ['127.0.0.1', '::1'], true)) {
            return $current;
        }

        $public = self::publicAddress($facts);
        if ($public !== null) {
            return $public;
        }

        // Keep a public address learned behind NAT; replace a private one the machine no longer has.
        if (self::isPublic($current) || in_array($current, self::addresses($facts, skipVirtual: false), true)) {
            return $current;
        }

        return self::privateAddress($facts) ?? $current;
    }
}
