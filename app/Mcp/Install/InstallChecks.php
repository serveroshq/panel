<?php

namespace App\Mcp\Install;

use App\Http\Middleware\TrustCloudflare;
use Closure;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\IpUtils;
use Throwable;

/**
 * The install MCP's checks, run from serveros.com: what a server reported
 * about itself (install.sh --check), its DNS, whether the internet can
 * reach its ports, and whether the finished panel answers.
 *
 * The network checks only look at public addresses, on the panel's own
 * ports, and connect to the address they resolved, so they can't be
 * pointed at anything private.
 */
class InstallChecks
{
    public const PORTS = [80, 443, 8443];

    /** @var Closure(string): list<string> */
    private Closure $resolver;

    /** @var Closure(string, int): ('open'|'closed'|'blocked') */
    private Closure $connector;

    /** @var Closure(string, string): array{status: int|null, error: string|null} */
    private Closure $fetcher;

    /**
     * @param  (Closure(string): list<string>)|null  $resolver
     * @param  (Closure(string, int): ('open'|'closed'|'blocked'))|null  $connector
     * @param  (Closure(string, string): array{status: int|null, error: string|null})|null  $fetcher
     */
    public function __construct(?Closure $resolver = null, ?Closure $connector = null, ?Closure $fetcher = null)
    {
        $this->resolver = $resolver ?? self::lookUp(...);
        $this->connector = $connector ?? self::connect(...);
        $this->fetcher = $fetcher ?? self::fetchUp(...);
    }

    /**
     * Reads install.sh --check's report (key=value lines).
     *
     * @return array<string, string>
     */
    public static function parseReport(string $report): array
    {
        $facts = [];
        foreach (preg_split('/\R/', $report) ?: [] as $line) {
            if (preg_match('/^([a-z0-9_]+)=(.*)$/', trim($line), $m)) {
                $facts[$m[1]] = trim($m[2]);
            }
        }

        return $facts;
    }

    /**
     * @return array{status: 'ok'|'warn'|'fail', can_install: bool, needs_person: bool, server_ip: string|null, already_installed: array{domain: string, image: string}|null, lines: list<array{status: 'ok'|'warn'|'fail', text: string}>}
     */
    public function server(string $report, bool $local): array
    {
        $f = self::parseReport($report);
        if (! isset($f['os'])) {
            return [
                'status' => 'fail', 'can_install' => false, 'needs_person' => false, 'server_ip' => null, 'already_installed' => null,
                'lines' => [['status' => 'fail', 'text' => 'That isn’t a report from install.sh --check. Run the check command from plan_install and pass everything it printed.']],
            ];
        }

        $lines = [];
        $installed = ($f['installed'] ?? '') === 'yes';
        $mac = $f['os'] === 'Darwin';

        if ($f['os'] !== 'Linux' && ! ($local && $mac)) {
            $lines[] = ['fail', $mac
                ? 'This is a Mac. A real install needs a Linux server; on a Mac, install it locally to try it out.'
                : "ServerOS runs on Linux, and this is {$f['os']}."];
        } else {
            $lines[] = ['ok', trim(($f['distro'] ?? $f['os']).' on '.($f['arch'] ?? 'an unknown CPU')).'.'];
        }

        $arch = $f['arch'] ?? '';
        if ($arch !== '' && ! in_array($arch, ['x86_64', 'amd64', 'aarch64', 'arm64'], true)) {
            $lines[] = ['fail', "The ServerOS image runs on x86_64 and ARM64 (aarch64), not {$arch}."];
        }

        $cpus = (int) ($f['cpus'] ?? 0);
        $memory = (int) ($f['memory_mb'] ?? 0);
        $disk = (int) ($f['disk_free_mb'] ?? 0);
        $lines[] = match (true) {
            $memory >= 3500 && $cpus >= 2 => ['ok', "{$cpus} CPUs and ".self::gb($memory).' of memory.'],
            $memory >= 1800 => ['warn', "{$cpus} ".($cpus === 1 ? 'CPU' : 'CPUs').' and '.self::gb($memory).' of memory. It runs, but 2 CPUs and 4 GB is the comfortable start, and the first build is slow below that.'],
            default => ['fail', 'Only '.self::gb($memory).' of memory. ServerOS needs at least 2 GB, and 4 GB to be comfortable.'],
        };
        $lines[] = match (true) {
            $disk >= 20000 => ['ok', self::gb($disk).' of free disk.'],
            $disk >= 8000 => ['warn', self::gb($disk).' of free disk. Enough to start; backups and logs grow, so 20 GB is better.'],
            default => ['fail', 'Only '.self::gb($disk).' of free disk. ServerOS needs about 8 GB to install, 20 GB to be comfortable.'],
        };

        $docker = $f['docker'] ?? 'none';
        $root = ($f['root'] ?? '') === 'yes';
        $sudo = ($f['sudo'] ?? '') === 'yes';
        $lines[] = match (true) {
            $docker === 'none' && $mac => ['fail', 'Docker isn’t installed. Install Docker Desktop (docker.com/products/docker-desktop), start it, then check again.'],
            $docker === 'none' => ['ok', 'Docker isn’t installed yet. The install adds it.'],
            $docker === 'not-running' => ['fail', 'Docker is installed but isn’t running. Start it ('.($mac ? 'open Docker Desktop' : 'sudo systemctl start docker').') and check again.'],
            ($f['compose'] ?? 'none') === 'none' => ['fail', "Docker {$docker} is here, without Compose v2. Install the docker-compose-plugin package."],
            default => ['ok', "Docker {$docker} with Compose {$f['compose']}."],
        };
        if (($f['docker_snap'] ?? '') === 'yes') {
            $lines[] = ['warn', 'Docker came from a snap, which can’t read /opt/serveros. Install Docker from get.docker.com instead, or add --dir=/home/you/serveros to the install command.'];
        }

        $needsPerson = false;
        if (! $local) {
            if ($root || $sudo) {
                $lines[] = ['ok', $root ? 'Signed in as root.' : 'sudo works without a password.'];
            } else {
                $needsPerson = true;
                $lines[] = ['warn', 'sudo asks for a password here. Give the person the install command to run themselves in their own terminal, so the password never goes through the chat.'];
            }
        }

        if ($installed) {
            $lines[] = ['ok', 'ServerOS is already installed here'.(($f['installed_domain'] ?? '') !== '' ? " for {$f['installed_domain']}" : '').(($f['installed_image'] ?? '') !== '' ? " ({$f['installed_image']})" : '').'. Running the install again brings it up to date and keeps its settings and data.'];
        }

        foreach (self::PORTS as $port) {
            $state = $f["port_{$port}"] ?? 'free';
            if (str_starts_with($state, 'busy') && ! $installed) {
                $owner = Str::after($state, 'busy:');
                $what = $owner !== '' && $owner !== $state ? " ({$owner})" : '';
                $lines[] = ['fail', match ($port) {
                    8443 => "Port 8443 is in use{$what}. ServerOS’s gateway, where machines connect, needs it.",
                    default => "Port {$port} is in use{$what}. ServerOS’s web server needs ports 80 and 443 for itself: stop the other web server, or use a server without one.",
                }];
            }
        }

        $ip = isset($f['public_ipv4']) && filter_var($f['public_ipv4'], FILTER_VALIDATE_IP) ? $f['public_ipv4'] : null;
        if (! $local) {
            $lines[] = $ip !== null
                ? ['ok', "Its public address is {$ip}."]
                : ['warn', 'It couldn’t find its public address (no IPv4 to the internet?). Look it up in your provider’s dashboard for the DNS records.'];
        }

        $result = self::result($lines);

        return [
            'status' => $result['status'],
            'can_install' => $result['status'] !== 'fail',
            'needs_person' => $needsPerson,
            'server_ip' => $ip,
            'already_installed' => $installed ? ['domain' => $f['installed_domain'] ?? '', 'image' => $f['installed_image'] ?? ''] : null,
            'lines' => $result['lines'],
        ];
    }

    /**
     * @return array{status: 'ok'|'warn'|'fail', records: list<array{type: string, name: string, value: string, proxy: string}>, lines: list<array{status: 'ok'|'warn'|'fail', text: string}>}
     */
    public function dns(string $domain, ?string $serverIp, ?string $gatewayHost = null): array
    {
        $lines = [];
        $target = $serverIp ?? 'your server’s address';
        $records = [
            ['type' => 'A', 'name' => $domain, 'value' => $target, 'proxy' => $gatewayHost === null ? 'DNS only (grey cloud on Cloudflare)' : 'through your Cloudflare Tunnel'],
            ['type' => 'A', 'name' => '*.'.$domain, 'value' => $target, 'proxy' => $gatewayHost === null ? 'DNS only (grey cloud on Cloudflare)' : 'through your Cloudflare Tunnel'],
        ];

        $found = ($this->resolver)($domain);
        $wildcard = ($this->resolver)('serveros-check-'.Str::lower(Str::random(6)).'.'.$domain);

        if ($gatewayHost !== null) {
            $lines[] = match (true) {
                $found === [] => ['fail', "{$domain} doesn’t point anywhere yet. Add it to the tunnel’s routes in Cloudflare (a published application, service URL http://web:80)."],
                self::onCloudflare($found) => ['ok', "{$domain} goes through Cloudflare."],
                default => ['warn', "{$domain} points at ".implode(', ', $found).', not Cloudflare. Add it to the tunnel’s routes instead.'],
            };
            $lines[] = $wildcard === []
                ? ['fail', "*.{$domain} doesn’t point anywhere yet. Workspaces open on subdomains, so add *.{$domain} to the tunnel too."]
                : ['ok', "*.{$domain} does too."];
            $gateway = ($this->resolver)($gatewayHost);
            $lines[] = match (true) {
                $gateway === [] => ['fail', "{$gatewayHost}, where machines connect, doesn’t point anywhere yet. Add an A record for it with the server’s address, DNS only."],
                self::onCloudflare($gateway) => ['fail', "{$gatewayHost} is behind Cloudflare’s proxy. Machines connect with their own certificates, which the proxy can’t pass on: set it to DNS only (grey cloud)."],
                $serverIp !== null && ! in_array($serverIp, $gateway, true) => ['warn', "{$gatewayHost} points at ".implode(', ', $gateway).", not the server ({$serverIp})."],
                default => ['ok', "Machines will connect to {$gatewayHost} (".$gateway[0].')'],
            };
            $records = [
                ['type' => 'A', 'name' => $gatewayHost, 'value' => $target, 'proxy' => 'DNS only (grey cloud)'],
            ];

            $result = self::result($lines);

            return ['status' => $result['status'], 'records' => $records, 'lines' => $result['lines']];
        }

        $lines[] = match (true) {
            $found === [] => ['fail', "{$domain} doesn’t point anywhere yet. Add an A record for it with {$target} (it can take a few minutes to show up)."],
            self::onCloudflare($found) => ['fail', "{$domain} is behind Cloudflare’s proxy (orange cloud). Let’s Encrypt can’t make the certificate through it, and machines can’t connect to port 8443 through it. Set it to DNS only (grey cloud)."],
            $serverIp === null || in_array($serverIp, $found, true) => ['ok', "{$domain} points at ".implode(', ', $found).'.'],
            default => ['fail', "{$domain} points at ".implode(', ', $found).", not the server ({$serverIp}). Change its A record to {$serverIp}."],
        };
        $lines[] = match (true) {
            $wildcard === [] => ['fail', "*.{$domain} doesn’t point anywhere yet. Workspaces open on subdomains (acme.{$domain}), so add an A record for *.{$domain} with {$target} too."],
            self::onCloudflare($wildcard) => ['fail', "*.{$domain} is behind Cloudflare’s proxy (orange cloud). Set it to DNS only (grey cloud)."],
            $serverIp === null || in_array($serverIp, $wildcard, true) => ['ok', "*.{$domain} does too, so workspaces will open."],
            default => ['fail', "*.{$domain} points at ".implode(', ', $wildcard).", not the server ({$serverIp})."],
        };

        $result = self::result($lines);

        return ['status' => $result['status'], 'records' => $records, 'lines' => $result['lines']];
    }

    /**
     * Whether the internet can reach the server's ports, from serveros.com.
     * Before the install nothing listens on them, so "closed" (refused) is
     * good news: the firewall lets it through. "blocked" means no answer.
     *
     * @return array{status: 'ok'|'warn'|'fail', address: string|null, ports: array<int, 'open'|'closed'|'blocked'>, lines: list<array{status: 'ok'|'warn'|'fail', text: string}>}
     */
    public function ports(string $host, bool $installed = false): array
    {
        $address = $this->publicAddress($host);
        if ($address === null) {
            return ['status' => 'fail', 'address' => null, 'ports' => [], 'lines' => [['status' => 'fail', 'text' => "{$host} doesn’t lead to a public address, so it can’t be checked from the internet."]]];
        }

        $lines = [];
        $states = [];
        foreach (self::PORTS as $port) {
            $state = ($this->connector)($address, $port);
            $states[$port] = $state;
            $what = $port === 8443 ? 'where machines connect' : ($port === 80 ? 'for Let’s Encrypt' : 'for the panel');
            $lines[] = match ($state) {
                'open' => ['ok', "Port {$port} ({$what}) answers."],
                'closed' => $installed
                    ? ['fail', "Port {$port} ({$what}) gets through the firewall, but nothing answers on it. Is ServerOS running? (serveros-panel status)"]
                    : ['ok', "Port {$port} ({$what}) gets through the firewall."],
                'blocked' => ['fail', "Port {$port} ({$what}) gets no answer from the internet. Open it in the server’s firewall (sudo ufw allow {$port}/tcp) and in your provider’s firewall, if it has one."],
            };
        }

        $result = self::result($lines);

        return ['status' => $result['status'], 'address' => $address, 'ports' => $states, 'lines' => $result['lines']];
    }

    /**
     * Whether a finished install answers: its health check over HTTPS, and
     * the gateway's port.
     *
     * @return array{status: 'ok'|'warn'|'fail', url: string, lines: list<array{status: 'ok'|'warn'|'fail', text: string}>}
     */
    public function panel(string $domain): array
    {
        $url = 'https://'.$domain;
        $address = $this->publicAddress($domain);
        if ($address === null) {
            return ['status' => 'fail', 'url' => $url, 'lines' => [['status' => 'fail', 'text' => "{$domain} doesn’t point at a public address yet, so the panel can’t be reached."]]];
        }

        $lines = [];
        $up = ($this->fetcher)($domain, $address);
        $lines[] = match (true) {
            $up['status'] === 200 => ['ok', "The panel answers at {$url}, with a valid certificate."],
            $up['status'] !== null => ['fail', "{$url}/up answered {$up['status']}. See what the panel says: serveros-panel logs web"],
            str_contains((string) $up['error'], 'SSL') || str_contains((string) $up['error'], 'certificate') => ['warn', "{$url} answers, but its certificate isn’t ready. The first visit asks Let’s Encrypt for it, which takes a few seconds: check again in a minute. If it stays like this, port 80 has to be reachable and {$domain} must not be behind Cloudflare’s proxy."],
            default => ['fail', "{$url} doesn’t answer ({$up['error']}). Is it running (serveros-panel status), and are ports 80 and 443 open?"],
        };

        $gateway = ($this->connector)($address, 8443);
        $lines[] = $gateway === 'open'
            ? ['ok', 'The gateway answers on port 8443, so machines can connect.']
            : ['fail', 'Nothing answers on port 8443, where machines connect. Open it in the firewall, and check the gateway: serveros-panel logs gateway'];

        $result = self::result($lines);

        return ['status' => $result['status'], 'url' => $url, 'lines' => $result['lines']];
    }

    public static function publicIp(string $ip): bool
    {
        return filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false
            && ! IpUtils::checkIp($ip, ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15']);
    }

    private function publicAddress(string $host): ?string
    {
        $addresses = filter_var($host, FILTER_VALIDATE_IP) !== false ? [$host] : ($this->resolver)($host);
        foreach ($addresses as $address) {
            if (self::publicIp($address)) {
                return $address;
            }
        }

        return null;
    }

    /** @param  list<string>  $addresses */
    private static function onCloudflare(array $addresses): bool
    {
        return $addresses !== [] && IpUtils::checkIp($addresses[0], TrustCloudflare::RANGES);
    }

    private static function gb(int $mb): string
    {
        return $mb >= 1024 ? rtrim(rtrim(number_format($mb / 1024, 1), '0'), '.').' GB' : "{$mb} MB";
    }

    /** @return list<string> */
    private static function lookUp(string $host): array
    {
        $v4 = @gethostbynamel($host) ?: [];
        $v6 = array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6');

        return array_values(array_unique([...$v4, ...$v6]));
    }

    /** @return 'open'|'closed'|'blocked' */
    private static function connect(string $address, int $port): string
    {
        $target = str_contains($address, ':') ? "[{$address}]:{$port}" : "{$address}:{$port}";
        $socket = @stream_socket_client("tcp://{$target}", $code, $message, 3);
        if ($socket !== false) {
            fclose($socket);

            return 'open';
        }

        return in_array($code, [61, 111], true) || stripos((string) $message, 'refused') !== false ? 'closed' : 'blocked';
    }

    /** @return array{status: int|null, error: string|null} */
    private static function fetchUp(string $domain, string $address): array
    {
        try {
            $response = Http::timeout(8)
                ->withoutRedirecting()
                ->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$domain}:443:".(str_contains($address, ':') ? "[{$address}]" : $address)]]])
                ->get("https://{$domain}/up");

            return ['status' => $response->status(), 'error' => null];
        } catch (Throwable $e) {
            return ['status' => null, 'error' => Str::limit($e->getMessage(), 160)];
        }
    }

    /**
     * @param  list<array{0: 'ok'|'warn'|'fail', 1: string}>  $lines
     * @return array{status: 'ok'|'warn'|'fail', lines: list<array{status: 'ok'|'warn'|'fail', text: string}>}
     */
    private static function result(array $lines): array
    {
        $statuses = array_column($lines, 0);
        $status = in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'ok');

        return ['status' => $status, 'lines' => array_map(fn (array $line) => ['status' => $line[0], 'text' => $line[1]], $lines)];
    }
}
