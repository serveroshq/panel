<?php

namespace App\Support\Traffic;

use App\Models\Machine;
use App\Support\DaemonJobQueue;

/**
 * The sites the machine's own web servers serve (ones ServerOS didn't
 * write): nginx, Apache, Caddy and Traefik, on the machine or in containers,
 * and log files set by hand for apps that write their own. The daemon counts
 * their traffic read-only from the access logs they already write and
 * reports the whole list with telemetry while traffic is on; it's kept on
 * the machine as {at, sites}. Sites whose config writes no access log are
 * in the list too, with `unlogged` saying why.
 *
 * A site's key always has a dot (`nginx.shop.example.com`,
 * `traefik-coolify-proxy.shop.example.com` for one in a container,
 * `caddy.any` for a catch-all, `log.api` for a file set by hand), which the
 * keys of the apps ServerOS deploys never have, so the two can't collide in
 * traffic_rollups. A log several sites write without saying which is one
 * site, `shared`, keyed by its file.
 *
 * @phpstan-type Site array{site: string, server: string, names: list<string>, logs: list<string>, timed: bool, shared: bool, copy: bool, configs: list<string>, ports: list<int>, container: string|null, apps: list<string>, catchAll: bool, roots: list<string>, sockets: list<string>, unlogged: string|null, editable: bool, manual: bool}
 */
final class ExistingSites
{
    /** @var array<string, string> */
    public const SERVERS = ['nginx' => 'nginx', 'apache' => 'Apache', 'caddy' => 'Caddy', 'traefik' => 'Traefik', 'log' => 'Log file'];

    private const MAX_SITES = 300;

    private const MAX_LIST = 50;

    /** Folders too broad to tie an app to a site by: everything's under them. */
    private const BROAD = ['', '/', '/root', '/home', '/var', '/var/www', '/srv', '/opt', '/usr', '/usr/local', '/tmp', '/app', '/data'];

    /** Whether the machine's daemon counts these sites at all. */
    public static function available(Machine $machine): bool
    {
        return DaemonJobQueue::canCountExistingSites($machine);
    }

    /**
     * A reported list, cleaned up for keeping on the machine.
     *
     * @param  array<mixed>  $reported
     * @return array{at: int, sites: list<Site>}
     */
    public static function stored(array $reported): array
    {
        $sites = [];
        foreach (array_slice(array_values($reported), 0, self::MAX_SITES) as $site) {
            if (($clean = self::clean($site)) !== null) {
                $sites[$clean['site']] = $clean;
            }
        }

        return ['at' => now()->getTimestamp(), 'sites' => array_values($sites)];
    }

    /** @return Site|null */
    private static function clean(mixed $site): ?array
    {
        if (! is_array($site) || ! is_string($site['site'] ?? null) || ! is_string($site['server'] ?? null)) {
            return null;
        }
        $key = $site['site'];
        $server = $site['server'];
        $pattern = $server === 'log'
            ? '/^log\.[A-Za-z0-9_.-]+$/'
            : '/^(nginx|apache|caddy|traefik)(-[a-z0-9-]+)?\.[A-Za-z0-9_.-]+$/';
        if (! array_key_exists($server, self::SERVERS) || strlen($key) > 120 || ! preg_match($pattern, $key) || ($server !== 'log' && ! str_starts_with($key, $server))) {
            return null;
        }
        /** @return list<string> */
        $strings = fn (mixed $list, int $length) => is_array($list)
            ? array_values(array_map(fn (string $s) => mb_strcut($s, 0, $length), array_filter(array_slice(array_values($list), 0, self::MAX_LIST), fn ($s) => is_string($s) && $s !== '')))
            : [];
        $container = is_string($site['container'] ?? null) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/', $site['container']) ? $site['container'] : null;
        $unlogged = in_array($site['unlogged'] ?? null, ['off', 'elsewhere'], true) ? $site['unlogged'] : null;

        return [
            'site' => $key,
            'server' => $server,
            'names' => $strings($site['names'] ?? null, 253),
            'logs' => $strings($site['logs'] ?? null, 500),
            'timed' => ($site['timed'] ?? false) === true,
            'shared' => ($site['shared'] ?? false) === true,
            'copy' => ($site['copy'] ?? false) === true,
            'configs' => $strings($site['configs'] ?? null, 500),
            'ports' => is_array($site['upstream_ports'] ?? null)
                ? array_values(array_filter(array_slice($site['upstream_ports'], 0, self::MAX_LIST), fn ($p) => is_int($p) && $p > 0 && $p < 65536))
                : [],
            'container' => $container,
            'apps' => $strings($site['upstream_containers'] ?? null, 128),
            'catchAll' => ($site['catch_all'] ?? false) === true,
            'roots' => $strings($site['roots'] ?? null, 500),
            'sockets' => $strings($site['sockets'] ?? null, 500),
            'unlogged' => $unlogged,
            // Daemons before these sites said nothing, and changed only host nginx and Apache.
            'editable' => array_key_exists('editable', $site) || $unlogged !== null || $container !== null
                ? ($site['editable'] ?? false) === true
                : in_array($server, ['nginx', 'apache'], true),
            'manual' => ($site['manual'] ?? false) === true,
        ];
    }

    /**
     * The machine's sites, as last reported.
     *
     * @return list<Site>
     */
    public static function all(Machine $machine): array
    {
        $sites = $machine->traffic_sites['sites'] ?? null;

        // Kept as stored() wrote them; read through clean() all the same.
        return is_array($sites) ? array_values(array_filter(array_map(fn (array $s) => self::clean([
            ...$s,
            'upstream_ports' => $s['ports'] ?? [],
            'upstream_containers' => $s['apps'] ?? [],
            'catch_all' => $s['catchAll'] ?? false,
        ]), $sites))) : [];
    }

    /** @return Site|null */
    public static function find(Machine $machine, string $key): ?array
    {
        foreach (self::all($machine) as $site) {
            if ($site['site'] === $key) {
                return $site;
            }
        }

        return null;
    }

    /**
     * Which web server a service is, when it's one ServerOS counts sites
     * for.
     *
     * @param  array<string, mixed>  $service
     */
    public static function serverOf(array $service): ?string
    {
        $details = is_array($service['details'] ?? null) ? $service['details'] : [];
        $text = strtolower(implode(' ', array_filter([$service['key'] ?? null, $service['name'] ?? null, $service['found'] ?? null, $details['image'] ?? null], 'is_string')));

        return match (true) {
            str_contains($text, 'nginx') || str_contains($text, 'openresty') => 'nginx',
            str_contains($text, 'apache') || str_contains($text, 'httpd') => 'apache',
            str_contains($text, 'caddy') => 'caddy',
            str_contains($text, 'traefik') => 'traefik',
            default => null,
        };
    }

    /**
     * The container a service is, by name, when it's one.
     *
     * @param  array<string, mixed>  $service
     */
    public static function containerOf(array $service): ?string
    {
        if (! in_array($service['manager'] ?? null, ['docker', 'compose'], true) && ! str_starts_with((string) ($service['key'] ?? ''), 'docker:')) {
            return null;
        }
        $name = ltrim((string) ($service['name'] ?? ''), '/');

        return $name === '' ? null : $name;
    }

    /**
     * Whether a site is the service's own: the web server that serves it.
     *
     * @param  array<string, mixed>  $service
     * @param  Site  $site
     */
    public static function serves(array $service, array $site): bool
    {
        $container = self::containerOf($service);
        if ($container !== null && $site['container'] === $container) {
            return true;
        }

        return $site['container'] === null && $container === null && self::serverOf($service) === $site['server'];
    }

    /**
     * Whether a site passes its requests on to the service: the site's root
     * is in the service's folder (the folder holds it, or is it), the site
     * proxies to a port it listens on or a socket it is (php-fpm), a router
     * sends to its container, or the site is a log file set by hand for it.
     *
     * @param  array<string, mixed>  $service
     * @param  Site  $site
     */
    public static function ties(array $service, array $site): bool
    {
        $ports = array_values(array_filter((array) ($service['ports'] ?? []), 'is_int'));
        if ($ports !== [] && array_intersect($site['ports'], $ports) !== []) {
            return true;
        }
        $container = self::containerOf($service);
        if ($container !== null && in_array($container, $site['apps'], true)) {
            return true;
        }
        $name = (string) ($service['name'] ?? '');
        if ($site['manual'] && $name !== '' && ($site['names'][0] ?? null) === $name) {
            return true;
        }
        $cwd = rtrim((string) ($service['working_dir'] ?? ''), '/');
        if ($site['container'] === null && ! in_array($cwd, self::BROAD, true) && str_starts_with($cwd, '/')) {
            foreach ($site['roots'] as $root) {
                $root = rtrim($root, '/');
                if ($root === $cwd || str_starts_with($root, $cwd.'/')) {
                    return true;
                }
            }
        }
        // php8.4-fpm serves /run/php/php8.4-fpm.sock (and its other pools').
        $unit = (string) preg_replace('/\.service$/', '', (string) preg_replace('/^systemd:/', '', (string) ($service['key'] ?? $name)));
        if ($unit !== '' && strlen($unit) >= 3) {
            foreach ($site['sockets'] as $socket) {
                if (str_starts_with(basename($socket), $unit.'.') || str_starts_with(basename($socket), $unit.'-')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * The sites a service's page shows: a web server's own sites, or the
     * sites that pass requests on to an app.
     *
     * @param  array<string, mixed>  $service
     * @return array{serves: bool, sites: list<Site>}
     */
    public static function forService(Machine $machine, array $service): array
    {
        if (is_string($service['app'] ?? null)) {
            return ['serves' => false, 'sites' => []];
        }
        if (! self::available($machine)) {
            return ['serves' => self::serverOf($service) !== null, 'sites' => []];
        }
        $all = self::all($machine);
        $own = array_values(array_filter($all, fn (array $site) => self::serves($service, $site)));
        if ($own !== []) {
            return ['serves' => true, 'sites' => $own];
        }
        $tied = array_values(array_filter($all, fn (array $site) => self::ties($service, $site)));

        return ['serves' => $tied === [] && self::serverOf($service) !== null, 'sites' => $tied];
    }

    /**
     * What a page needs to show one site's traffic, without the numbers.
     *
     * @param  Site  $site
     * @return array{site: string, domain: string|null, collecting: bool, blocked: string|null, existing: array{server: string, names: list<string>, logs: list<string>, configs: list<string>, timed: bool, shared: bool, copy: bool, container: string|null, apps: list<string>, catchAll: bool, unlogged: string|null, editable: bool, manual: bool, canChange: bool, update: string|null}}
     */
    public static function state(Machine $machine, array $site): array
    {
        $current = DaemonJobQueue::canCountAnySite($machine);

        return [
            'site' => $site['site'],
            'domain' => $site['catchAll'] ? null : ($site['names'][0] ?? null),
            'collecting' => TrafficSwitch::on($machine),
            'blocked' => TrafficSwitch::blocked($machine),
            'existing' => [
                'server' => self::SERVERS[$site['server']],
                'names' => $site['names'],
                'logs' => $site['logs'],
                'configs' => $site['configs'],
                'timed' => $site['timed'],
                'shared' => $site['shared'],
                'copy' => $site['copy'],
                'container' => $site['container'],
                'apps' => $site['apps'],
                'catchAll' => $site['catchAll'],
                'unlogged' => $site['unlogged'],
                'editable' => $site['editable'],
                'manual' => $site['manual'],
                // ServerOS can start, stop or time this site's log.
                'canChange' => $site['editable'] && ($current || in_array($site['server'], ['nginx', 'apache'], true) && $site['unlogged'] === null),
                'update' => $current ? null : self::updateNote($machine),
            ],
        ];
    }

    /** What to do to count every kind of site, when the daemon is too old for it. */
    public static function updateNote(Machine $machine): ?string
    {
        if (DaemonJobQueue::canCountAnySite($machine)) {
            return null;
        }
        $said = __('Update the ServerOS daemon on :name to :version or later to count sites Caddy, Traefik and containers serve, and to start a log for a site that writes none.', ['name' => $machine->name, 'version' => 'v'.config('daemon.any_site_traffic_since')]);

        return is_string($said) ? $said : null;
    }

    /**
     * The key of the machine's service for a web server (in a container,
     * when it runs in one), for links to its page.
     */
    public static function serviceKey(Machine $machine, string $server, ?string $container = null): ?string
    {
        $found = null;
        foreach ((array) ($machine->inventory['services'] ?? []) as $service) {
            if (! is_array($service) || ! is_string($service['key'] ?? null)) {
                continue;
            }
            if ($container !== null) {
                if (self::containerOf($service) === $container) {
                    return $service['key'];
                }

                continue;
            }
            if (self::serverOf($service) !== $server || self::containerOf($service) !== null) {
                continue;
            }
            if (($service['kind'] ?? null) === 'web_server' || $found === null) {
                $found = $service['key'];
            }
        }

        return $found;
    }

    /**
     * The page that shows a site: its web server's Traffic tab, or for a
     * file set by hand, the app's it was set for.
     *
     * @param  Site  $site
     */
    public static function url(Machine $machine, array $site): ?string
    {
        $key = null;
        if ($site['manual']) {
            foreach ((array) ($machine->inventory['services'] ?? []) as $service) {
                if (is_array($service) && is_string($service['key'] ?? null) && self::ties($service, $site)) {
                    $key = $service['key'];
                    break;
                }
            }
        } else {
            $key = self::serviceKey($machine, $site['server'], $site['container']);
        }

        return $key === null ? null : "/machines/{$machine->id}/services/{$key}/traffic?site=".rawurlencode($site['site']);
    }

    /**
     * How a site is named in lists: its first name, or what it is.
     *
     * @param  Site  $site
     */
    public static function label(array $site): string
    {
        $say = fn (string $text, array $replace = []) => is_string($said = __($text, $replace)) ? $said : $text;
        if ($site['shared']) {
            $file = basename($site['logs'][0] ?? 'access.log');

            return $say(':file (:count sites)', ['file' => $file, 'count' => (string) count($site['names'])]);
        }
        if ($site['catchAll']) {
            return $say('All other hosts');
        }

        return $site['names'][0] ?? $say('Default site');
    }

    /**
     * Where a site's web server is, in words: "Caddy", "nginx in web".
     *
     * @param  Site  $site
     */
    public static function where(array $site): string
    {
        $server = self::SERVERS[$site['server']];
        if ($site['container'] === null) {
            return $server;
        }
        $said = __(':server in :container', ['server' => $server, 'container' => $site['container']]);

        return is_string($said) ? $said : $server;
    }
}
