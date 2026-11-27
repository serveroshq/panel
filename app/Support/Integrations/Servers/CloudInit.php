<?php

namespace App\Support\Integrations\Servers;

/**
 * The cloud-init a server made from ServerOS starts with: Docker, then the
 * panel's install command, so the machine joins on its own.
 */
final class CloudInit
{
    public static function userData(string $hostname, string $install): string
    {
        $script = implode("\n", array_map(fn (string $line) => '      '.$line, [
            '#!/bin/bash',
            'set -e',
            'curl -fsSL https://get.docker.com | sh',
            $install,
            'rm -f /root/serveros-install.sh',
        ]));

        return <<<YAML
        #cloud-config
        hostname: {$hostname}
        write_files:
          - path: /root/serveros-install.sh
            permissions: '0700'
            content: |
        {$script}
        runcmd:
          - [ bash, /root/serveros-install.sh ]
        YAML;
    }

    public static function hostname(string $name): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)), '-');

        return substr($slug === '' ? 'server' : $slug, 0, 63);
    }
}
