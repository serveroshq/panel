<?php

namespace App\Mcp\Install;

/**
 * What the install MCP's plan_install answers: the questions still open,
 * then, once they're answered, every step with the exact command to run.
 * The AI app runs the commands itself; this only writes them.
 */
class InstallPlan
{
    public const TARGETS = ['server', 'this_computer', 'local'];

    /**
     * @param  array<string, mixed>  $answers
     * @param  array{version: string, tag: string, published_at: string|null, url: string, notes: string}|null  $release
     * @return array<string, mixed>
     */
    public static function make(array $answers, ?array $release): array
    {
        $target = self::pick($answers['target'] ?? null, self::TARGETS);
        $ssh = self::ssh($answers['ssh'] ?? null);
        $domain = self::domain($answers['domain'] ?? null);
        $email = self::email($answers['email'] ?? null);
        $tunnel = ($answers['tunnel'] ?? false) === true;
        $gateway = self::domain($answers['gateway_host'] ?? null);
        $version = self::version($answers['version'] ?? null) ?? $release['version'] ?? null;

        $problems = [];
        if (is_string($answers['ssh'] ?? null) && $answers['ssh'] !== '' && $ssh === null) {
            $problems[] = 'ssh: give it as user@host, user@host:port, or a Host name from ~/.ssh/config.';
        }
        if (is_string($answers['domain'] ?? null) && $answers['domain'] !== '' && $domain === null) {
            $problems[] = 'domain: give just the name, like panel.example.com (no https://, no path).';
        }
        if ($target === 'local' && $domain !== null && ! self::localName($domain)) {
            $problems[] = 'domain: a local panel answers on a .localhost name, like serveros.localhost. Leave domain out for the default.';
            $domain = null;
        }
        if (is_string($answers['email'] ?? null) && $answers['email'] !== '' && $email === null) {
            $problems[] = 'email: that isn’t an email address.';
        }

        $questions = self::questions($target, $ssh, $domain, $email, $tunnel, $gateway);
        $plan = [
            'release' => $release === null
                ? ['version' => 'latest', 'note' => 'GitHub didn’t answer, so this installs the newest image.']
                : $release,
            'answers' => array_filter([
                'target' => $target,
                'ssh' => $ssh === null ? null : $ssh['display'],
                'domain' => $target === 'local' ? ($domain ?? 'serveros.localhost') : $domain,
                'email' => $email,
                'tunnel' => $tunnel ?: null,
                'gateway_host' => $gateway,
            ], fn ($value) => $value !== null),
        ];
        if ($problems !== []) {
            $plan['problems'] = $problems;
        }

        if ($questions !== []) {
            return $plan + [
                'ready' => false,
                'questions' => $questions,
                'next' => 'Ask the person these, all at once if your app can show questions with choices, then call plan_install again with every answer so far.',
            ];
        }

        return $plan + ['ready' => true] + self::steps((string) $target, $ssh, $domain, $email, $tunnel ? $gateway : null, $version);
    }

    /**
     * @param  array{display: string, args: string}|null  $ssh
     * @return list<array<string, mixed>>
     */
    private static function questions(?string $target, ?array $ssh, ?string $domain, ?string $email, bool $tunnel, ?string $gateway): array
    {
        $questions = [];
        if ($target === null) {
            $questions[] = [
                'id' => 'target',
                'question' => 'Where should ServerOS run?',
                'options' => [
                    ['value' => 'server', 'label' => 'On a server I reach over SSH', 'description' => 'A VPS or dedicated server with Linux. The usual choice: your machines connect to it from anywhere.'],
                    ['value' => 'this_computer', 'label' => 'On this computer, which is a server', 'description' => 'You’re running this AI app on the Linux server itself.'],
                    ['value' => 'local', 'label' => 'On this computer, just to try it', 'description' => 'Runs in Docker at serveros.localhost. Nothing to set up, but machines on the internet can’t connect to it.'],
                ],
            ];

            return $questions;
        }

        if ($target === 'server' && $ssh === null) {
            $questions[] = [
                'id' => 'ssh',
                'question' => 'How do you SSH into the server?',
                'example' => 'root@203.0.113.10, deploy@myserver.com:2222, or a Host name from your ~/.ssh/config',
                'why' => 'Commands run on it over your own SSH access. Use a key that already works; never paste a password into the chat.',
            ];
        }

        if ($target === 'local') {
            return $questions;
        }

        if ($domain === null) {
            $questions[] = [
                'id' => 'domain',
                'question' => 'What address should the panel have?',
                'example' => 'panel.example.com',
                'why' => 'The panel answers on it and every workspace on a subdomain of it (acme.panel.example.com), so you’ll point it and *.panel.example.com at the server.',
            ];
        }

        if (! $tunnel && $email === null) {
            $questions[] = [
                'id' => 'email',
                'question' => 'Which email should get certificate notices?',
                'example' => 'you@example.com',
                'why' => 'Let’s Encrypt makes the panel’s HTTPS certificates for free and emails this address only if one is about to run out.',
            ];
            $questions[] = [
                'id' => 'tunnel',
                'optional' => true,
                'default' => false,
                'question' => 'Can ports 80, 443 and 8443 be open on the server?',
                'options' => [
                    ['value' => false, 'label' => 'Yes, open them', 'description' => 'The usual setup: the panel gets free certificates from Let’s Encrypt.'],
                    ['value' => true, 'label' => 'No, it’s behind a Cloudflare Tunnel', 'description' => 'Cloudflare carries the panel’s traffic; machines still connect to port 8443 on a name of its own.'],
                ],
            ];
        }

        if ($tunnel && $gateway === null) {
            $questions[] = [
                'id' => 'gateway_host',
                'question' => 'Which name should machines connect to?',
                'example' => 'gateway.example.com',
                'why' => 'Machines connect with their own certificates, which Cloudflare’s proxy can’t pass on, so they need a name pointing straight at the server (DNS only), on port 8443.',
            ];
        }

        return $questions;
    }

    /**
     * @param  array{display: string, args: string}|null  $ssh
     * @return array<string, mixed>
     */
    private static function steps(string $target, ?array $ssh, ?string $domain, ?string $email, ?string $gateway, ?string $version): array
    {
        $script = PanelReleases::SCRIPT;
        $local = $target === 'local';
        $domain = $local ? ($domain ?? 'serveros.localhost') : (string) $domain;
        $versionFlag = $version === null ? '' : " --version={$version}";

        $on = fn (string $command): string => $target === 'server' && $ssh !== null
            ? 'ssh '.$ssh['args'].' '.escapeshellarg($command)
            : $command;
        $onTty = fn (string $command): string => $target === 'server' && $ssh !== null
            ? 'ssh -t '.$ssh['args'].' '.escapeshellarg($command)
            : $command;

        $checkCommand = $on("curl -fsSL {$script} | bash -s -- --check".($local ? ' --local' : ''));

        if ($local) {
            $install = "curl -fsSL {$script} | bash -s -- --local".($domain !== 'serveros.localhost' ? " --domain={$domain}" : '').$versionFlag;

            return [
                'steps' => [
                    ['step' => 1, 'title' => 'Check this computer', 'run' => $checkCommand, 'then' => 'Call check_server with everything it printed and local: true.'],
                    ['step' => 2, 'title' => 'Install', 'run' => $install, 'takes' => 'A few minutes the first time (it downloads the image). Run it with a long timeout.', 'then' => 'Its last lines have the address and the setup code.'],
                    ['step' => 3, 'title' => 'Open it', 'tell_the_person' => "Open https://{$domain} in Chrome or Firefox (Safari doesn’t open .localhost subdomains), accept the certificate warning (it was made on this computer), enter the setup code and follow the steps."],
                ],
                'notes' => [
                    'Ports 80 and 443 must be free on this computer. If another web server holds them (Herd, Valet, MAMP, nginx), stop it while you try ServerOS.',
                    'A local panel is for trying ServerOS. Machines on the internet can’t connect to it.',
                    'Stop it with: cd ~/serveros && docker compose down. Remove it with: cd ~/serveros && docker compose down -v && rm -rf ~/serveros',
                ],
            ];
        }

        $flags = $gateway !== null
            ? " --domain={$domain} --gateway-host={$gateway} --tunnel-token=\"\$CLOUDFLARE_TUNNEL_TOKEN\"{$versionFlag}"
            : " --domain={$domain} --email={$email}{$versionFlag}";
        $install = $onTty("curl -fsSL {$script} | sudo bash -s --{$flags}");
        $where = $target === 'server' ? 'on the server' : 'here';

        $steps = [
            ['step' => 1, 'title' => 'Check the server', 'run' => $checkCommand, 'changes_nothing' => true, 'then' => 'Call check_server with everything it printed. It says whether the server is ready and gives its public address.'],
            ['step' => 2, 'title' => 'Check DNS', 'tool' => 'check_dns', 'with' => array_filter(['domain' => $domain, 'server_ip' => 'the address check_server found', 'gateway_host' => $gateway]), 'then' => 'If records are missing, tell the person exactly which to add (check_dns lists them) and wait until they say they’re done, then check again. New records can take a few minutes.'],
            ['step' => 3, 'title' => 'Check the firewall', 'tool' => 'check_ports', 'with' => ['host' => 'the address check_server found'], 'then' => 'Any port marked blocked has to be opened in the server’s and the provider’s firewall first.'],
            ['step' => 4, 'title' => 'Install', 'run' => $install, 'confirm_first' => "Show the person this command and what it does (installs Docker if needed, then ServerOS {$where}, in /opt/serveros) and run it only after they say yes.", 'takes' => '3 to 10 minutes. Run it with a long timeout, and keep its output.', 'then' => 'Its last lines show the setup code. If it fails, show the person the error line as it is.'],
            ['step' => 5, 'title' => 'Check it from the internet', 'tool' => 'check_panel', 'with' => ['domain' => $domain]],
            ['step' => 6, 'title' => 'Hand over', 'tell_the_person' => "Open https://{$domain}, enter the setup code, and follow the steps: the database and address are already done; then email (can be skipped), your account and your first workspace. The welcome page has the command to connect the first machine."],
        ];

        $notes = [
            'If check_server says sudo needs a password, don’t run step 4 yourself: give the person the command to run in their own terminal, and carry on from step 5 when they say it finished.',
            'Running the install again later is safe: it keeps the settings and data and brings ServerOS up to date.',
        ];
        if ($gateway !== null) {
            array_unshift($notes, 'The install needs the Cloudflare Tunnel token. It’s a secret: have the person run step 4 themselves, with CLOUDFLARE_TUNNEL_TOKEN set in their own terminal (export CLOUDFLARE_TUNNEL_TOKEN=... on the server), and never paste it into the chat.');
        }

        return [
            'steps' => $steps,
            'notes' => $notes,
            'afterwards' => [
                'status' => $on('serveros-panel status'),
                'logs' => $on('serveros-panel logs web'),
                'setup_code' => $on('sudo serveros-panel setup-code'),
                'upgrade' => $on('sudo serveros-panel upgrade'),
                'backup' => $on('sudo serveros-panel backup'),
            ],
        ];
    }

    /** @param  list<string>  $allowed */
    private static function pick(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    public static function domain(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = strtolower(trim($value));
        $value = (string) preg_replace(['#^https?://#', '#/.*$#', '#:\d+$#'], '', $value);

        return preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$/', $value) && strlen($value) <= 253 ? $value : null;
    }

    private static function localName(string $domain): bool
    {
        return $domain === 'localhost' || str_ends_with($domain, '.localhost');
    }

    private static function email(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return filter_var($value, FILTER_VALIDATE_EMAIL) !== false && preg_match('/^[A-Za-z0-9._%+@-]+$/', $value) ? $value : null;
    }

    private static function version(mixed $value): ?string
    {
        return is_string($value) && preg_match('/^v?(\d+\.\d+\.\d+)$/', trim($value), $m) ? $m[1] : null;
    }

    /** @return array{display: string, args: string}|null */
    private static function ssh(mixed $value): ?array
    {
        if (! is_string($value) || ! preg_match('/^(?:ssh\s+)?(?:([a-z_][a-z0-9_.-]{0,31})@)?([A-Za-z0-9][A-Za-z0-9.-]{0,252}|\[[0-9a-fA-F:]+\])(?::(\d{1,5}))?$/', trim($value), $m)) {
            return null;
        }
        $user = $m[1] !== '' ? $m[1].'@' : '';
        $host = trim($m[2], '[]');
        $port = isset($m[3]) ? (int) $m[3] : null;
        if ($port !== null && ($port < 1 || $port > 65535)) {
            return null;
        }

        return [
            'display' => $user.$host.($port === null ? '' : ":{$port}"),
            'args' => ($port === null || $port === 22 ? '' : "-p {$port} ").$user.$host,
        ];
    }
}
