<?php

namespace App\Mcp\Install;

use App\Mcp\McpServer;
use Illuminate\Support\Facades\RateLimiter;

/**
 * serveros.com/mcp: installs ServerOS on someone's own server (or their
 * computer, to try it) through their AI app, with no account. It never runs
 * anything itself: it asks the questions, writes the exact commands for the
 * AI app to run, and checks the results from the internet.
 */
final class InstallMcpServer
{
    public const NAME = 'serveros';

    public const TITLE = 'ServerOS';

    /** Network checks (DNS, ports, the finished panel) per address, a minute. */
    private const NETWORK_CHECKS_PER_MINUTE = 20;

    public function __construct(
        private readonly PanelReleases $releases,
        private readonly InstallChecks $checks,
    ) {}

    /**
     * @return array<string, mixed>|list<array<string, mixed>>|null
     */
    public function handle(mixed $payload, string $ip): ?array
    {
        if (is_array($payload) && array_is_list($payload) && $payload !== []) {
            $answers = array_values(array_filter(array_map(fn ($message) => $this->message($message, $ip), $payload)));

            return $answers === [] ? null : $answers;
        }

        return $this->message($payload, $ip);
    }

    public static function instructions(): string
    {
        return <<<'TEXT'
            ServerOS is an open source panel for the servers people already rent: deploys from Git, backups, monitors, logs and updates, with an AI that finds what broke. This MCP server installs it, on the person's own Linux server (over their SSH) or on their computer to try it. It doesn't run anything itself: you run the commands with your own shell, and it tells you exactly what to run and checks the results from the internet.

            How to install:
            1. Call plan_install with whatever the person already told you (none of it is required). It returns the questions still open. Ask them all at once if your app can show questions with choices (use the options it gives), then call plan_install again with every answer so far, until it says ready.
            2. Follow its steps in order. A step with "run" is a command for you to run; one with "tool" is a check for you to call here. Read every check's lines and fix what fails before going on.
            3. Before the install command itself, show it to the person and wait for a yes. The checks before it change nothing.
            4. Never ask for, accept or pass on passwords, private keys or tokens. Use the person's existing SSH access. When something needs a secret (a sudo password, a Cloudflare Tunnel token), give them the command to run themselves in their own terminal.
            5. The install takes a few minutes: run it with a long timeout. When it's done, give the person the panel's address and the setup code from its last lines.
            If someone only asks what ServerOS is or what it needs, answer from get_release and plan_install without installing anything.
            TEXT;
    }

    /** @return list<array<string, mixed>> */
    public static function tools(): array
    {
        $readOnly = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => false];
        $network = ['readOnlyHint' => true, 'destructiveHint' => false, 'idempotentHint' => true, 'openWorldHint' => true];

        return [
            [
                'name' => 'plan_install',
                'title' => 'Plan the install',
                'description' => 'Start here. Pass what you know so far; it returns the questions still open (ask the person, then call it again with the answers), and once nothing is open, every step with the exact commands for this install. Changes nothing.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'target' => ['type' => 'string', 'enum' => InstallPlan::TARGETS, 'description' => 'server: a Linux server you reach over SSH. this_computer: the Linux server this AI app runs on. local: this computer, in Docker, just to try it.'],
                        'ssh' => ['type' => 'string', 'description' => 'For target server: how to SSH in, like root@203.0.113.10, deploy@host:2222, or a Host from ~/.ssh/config.'],
                        'domain' => ['type' => 'string', 'description' => 'The panel’s address, like panel.example.com. Workspaces open on its subdomains.'],
                        'email' => ['type' => 'string', 'description' => 'For Let’s Encrypt’s certificate notices.'],
                        'tunnel' => ['type' => 'boolean', 'description' => 'True when the server is behind a Cloudflare Tunnel, with ports 80 and 443 closed.'],
                        'gateway_host' => ['type' => 'string', 'description' => 'Behind a tunnel: the name machines connect to, pointing straight at the server, like gateway.example.com.'],
                        'version' => ['type' => 'string', 'description' => 'A version to install, like 1.9.0. Leave out for the latest release.'],
                    ],
                ],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'check_server',
                'title' => 'Check the server',
                'description' => 'Reads what the check command from plan_install printed (install.sh --check: system, CPUs, memory, disk, Docker, ports, public address) and says whether ServerOS can be installed there, what to fix first, and the server’s public address for the DNS records.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'report' => ['type' => 'string', 'description' => 'Everything the check command printed.'],
                        'local' => ['type' => 'boolean', 'description' => 'True for a local install, on this computer to try it.'],
                    ],
                    'required' => ['report'],
                ],
                'annotations' => $readOnly,
            ],
            [
                'name' => 'check_dns',
                'title' => 'Check DNS',
                'description' => 'Looks up the panel’s domain and a subdomain of it from serveros.com: whether they point at the server, whether Cloudflare’s proxy is in the way, and exactly which records to add.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'domain' => ['type' => 'string', 'description' => 'The panel’s address, like panel.example.com.'],
                        'server_ip' => ['type' => 'string', 'description' => 'The server’s public address, from check_server.'],
                        'gateway_host' => ['type' => 'string', 'description' => 'Behind a Cloudflare Tunnel only: the name machines connect to.'],
                    ],
                    'required' => ['domain'],
                ],
                'annotations' => $network,
            ],
            [
                'name' => 'check_ports',
                'title' => 'Check the firewall',
                'description' => 'Tries the server’s ports 80, 443 and 8443 from serveros.com, to see whether the internet can reach them. Before the install, "gets through the firewall" is what you want; "no answer" means a firewall blocks it.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'host' => ['type' => 'string', 'description' => 'The server’s public address or domain.'],
                        'installed' => ['type' => 'boolean', 'description' => 'True once ServerOS is running there, so something should answer.'],
                    ],
                    'required' => ['host'],
                ],
                'annotations' => $network,
            ],
            [
                'name' => 'check_panel',
                'title' => 'Check the panel',
                'description' => 'After the install: opens the panel over HTTPS from serveros.com and tries the gateway machines connect to, and says what’s wrong if either doesn’t answer.',
                'inputSchema' => [
                    'type' => 'object',
                    'properties' => [
                        'domain' => ['type' => 'string', 'description' => 'The panel’s address, like panel.example.com.'],
                    ],
                    'required' => ['domain'],
                ],
                'annotations' => $network,
            ],
            [
                'name' => 'get_release',
                'title' => 'The latest release',
                'description' => 'The newest ServerOS release on GitHub: its version, date, notes, and where the source and the install script are.',
                'inputSchema' => ['type' => 'object', 'properties' => new \stdClass],
                'annotations' => $network,
            ],
        ];
    }

    /** @return list<array<string, mixed>> */
    public static function prompts(): array
    {
        return [[
            'name' => 'install',
            'title' => 'Install ServerOS',
            'description' => 'Install ServerOS on your own server, or on this computer to try it.',
            'arguments' => [
                ['name' => 'where', 'description' => 'Optional: an SSH target (root@203.0.113.10), "this computer", or "local".', 'required' => false],
            ],
        ]];
    }

    /** @return array<string, mixed>|null */
    private function message(mixed $message, string $ip): ?array
    {
        if (! is_array($message) || ($message['jsonrpc'] ?? null) !== '2.0' || ! is_string($message['method'] ?? null)) {
            return is_array($message) && (isset($message['result']) || isset($message['error']))
                ? null
                : McpServer::error($message['id'] ?? null, -32600, 'Invalid request.');
        }
        if (! array_key_exists('id', $message)) {
            return null;
        }

        $id = $message['id'];
        $params = is_array($message['params'] ?? null) ? $message['params'] : [];

        return match ($message['method']) {
            'initialize' => McpServer::result($id, $this->initialize($params)),
            'ping' => McpServer::result($id, new \stdClass),
            'tools/list' => McpServer::result($id, ['tools' => self::tools()]),
            'tools/call' => $this->call($id, $params, $ip),
            'prompts/list' => McpServer::result($id, ['prompts' => self::prompts()]),
            'prompts/get' => $this->prompt($id, $params),
            'resources/list' => McpServer::result($id, ['resources' => []]),
            'resources/templates/list' => McpServer::result($id, ['resourceTemplates' => []]),
            default => McpServer::error($id, -32601, "Method not found: {$message['method']}"),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function initialize(array $params): array
    {
        $asked = is_string($params['protocolVersion'] ?? null) ? $params['protocolVersion'] : null;

        return [
            'protocolVersion' => in_array($asked, McpServer::VERSIONS, true) ? $asked : McpServer::VERSIONS[0],
            'capabilities' => ['tools' => ['listChanged' => false], 'prompts' => ['listChanged' => false]],
            'serverInfo' => ['name' => self::NAME, 'title' => self::TITLE, 'version' => McpServer::VERSION],
            'instructions' => self::instructions(),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function prompt(mixed $id, array $params): array
    {
        if (($params['name'] ?? null) !== 'install') {
            return McpServer::error($id, -32602, 'Unknown prompt.');
        }
        $where = is_string($params['arguments']['where'] ?? null) ? mb_substr(trim($params['arguments']['where']), 0, 200) : '';

        return McpServer::result($id, [
            'description' => 'Install ServerOS',
            'messages' => [[
                'role' => 'user',
                'content' => [
                    'type' => 'text',
                    'text' => 'Install ServerOS for me'.($where !== '' ? " ({$where})" : '').'. Use the serveros MCP server: start with plan_install, ask me what it needs, check everything before you install, and show me the install command before you run it.',
                ],
            ]],
        ]);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function call(mixed $id, array $params, string $ip): array
    {
        $name = is_string($params['name'] ?? null) ? $params['name'] : '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
        $string = fn (string $key, int $max = 253): ?string => is_string($arguments[$key] ?? null) && trim($arguments[$key]) !== ''
            ? mb_substr(trim($arguments[$key]), 0, $max)
            : null;

        if (in_array($name, ['check_dns', 'check_ports', 'check_panel'], true)) {
            $key = 'mcp-install-network:'.$ip;
            if (RateLimiter::tooManyAttempts($key, self::NETWORK_CHECKS_PER_MINUTE)) {
                return McpServer::result($id, McpServer::content(['error' => 'That’s a lot of checks in a minute. Wait '.RateLimiter::availableIn($key).' seconds and try again.'], true));
            }
            RateLimiter::hit($key, 60);
        }

        $answer = match ($name) {
            'plan_install' => McpServer::content(InstallPlan::make($arguments, $this->releases->latest()), false),
            'check_server' => ($report = $string('report', 8000)) === null
                ? McpServer::content(['error' => 'Pass everything the check command printed in report.'], true)
                : McpServer::content($this->checks->server($report, ($arguments['local'] ?? false) === true), false),
            'check_dns' => ($domain = InstallPlan::domain($string('domain'))) === null
                ? McpServer::content(['error' => 'Give the panel’s domain, like panel.example.com.'], true)
                : McpServer::content($this->checks->dns(
                    $domain,
                    ($serverIp = $string('server_ip', 45)) !== null && filter_var($serverIp, FILTER_VALIDATE_IP) !== false ? $serverIp : null,
                    InstallPlan::domain($string('gateway_host')),
                ), false),
            'check_ports' => ($host = $this->host($string('host'))) === null
                ? McpServer::content(['error' => 'Give the server’s public address or domain in host.'], true)
                : McpServer::content($this->checks->ports($host, ($arguments['installed'] ?? false) === true), false),
            'check_panel' => ($domain = InstallPlan::domain($string('domain'))) === null
                ? McpServer::content(['error' => 'Give the panel’s domain, like panel.example.com.'], true)
                : McpServer::content($this->checks->panel($domain), false),
            'get_release' => McpServer::content($this->release(), false),
            default => null,
        };

        return $answer === null
            ? McpServer::error($id, -32602, "Unknown tool: {$name}")
            : McpServer::result($id, $answer);
    }

    private function host(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return filter_var(trim($value, '[]'), FILTER_VALIDATE_IP) !== false ? trim($value, '[]') : InstallPlan::domain($value);
    }

    /** @return array<string, mixed> */
    private function release(): array
    {
        $release = $this->releases->latest();

        return [
            'release' => $release ?? ['version' => 'latest', 'note' => 'GitHub didn’t answer just now; the install takes the newest image anyway.'],
            'source' => 'https://github.com/'.PanelReleases::REPO,
            'license' => 'AGPL-3.0',
            'image' => PanelReleases::IMAGE,
            'install_script' => PanelReleases::SCRIPT,
            'needs' => 'A Linux server with 2 CPUs, 4 GB of memory and 20 GB of disk, a domain you can point at it (and its subdomains), and ports 80, 443 and 8443 open. Or a computer with Docker, to try it locally.',
            'docs' => 'https://github.com/'.PanelReleases::REPO.'/blob/main/docs/self-hosting.md',
        ];
    }
}
