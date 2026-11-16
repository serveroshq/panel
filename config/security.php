<?php

return [

    /*
    | The CVE watcher (security:cves, every 15 minutes): known vulnerabilities
    | from free feeds, matched against the updates waiting on every
    | connected machine, emailed to the workspaces they affect, and the
    | notable ones posted to the Discord feed
    | (services.discord.security_webhook).
    */
    'cves' => [
        'enabled' => (bool) env('CVE_WATCH', true),

        // A workspace gets at most one email in this many hours, unless a
        // newly found one is being exploited.
        'email_every_hours' => (int) env('CVE_EMAIL_EVERY_HOURS', 20),

        // Machines matched again at least this often, for newly published
        // CVEs, even when their updates haven't changed.
        'rescan_hours' => (int) env('CVE_RESCAN_HOURS', 12),

        // Software people run on servers: NVD's critical CVEs and CISA's
        // exploited list reach the Discord feed only when they're about one
        // of these (vendor or product as NVD and CISA name them,
        // lowercased, letters and digits only).
        'watchlist' => [
            'linux', 'linuxkernel', 'kernel', 'ubuntu', 'debian', 'almalinux', 'rockylinux',
            'openssh', 'openssl', 'glibc', 'sudo', 'systemd', 'polkit', 'pam', 'linuxpam', 'bash', 'xz', 'xzutils', 'zlib',
            'curl', 'libcurl', 'gnutls', 'libssh', 'libssh2', 'libxml2', 'expat', 'libexpat', 'sqlite', 'git', 'vim', 'rsync', 'cups',
            'nginx', 'httpserver', 'httpd', 'apachehttpserver', 'tomcat', 'caddy', 'traefik', 'haproxy', 'varnish', 'squid', 'envoy',
            'docker', 'dockerengine', 'dockerdesktop', 'moby', 'runc', 'containerd', 'podman', 'buildkit', 'kubernetes', 'etcd', 'crio',
            'postgresql', 'mysql', 'mysqlserver', 'mariadb', 'redis', 'valkey', 'mongodb', 'memcached', 'rabbitmq', 'elasticsearch', 'minio',
            'php', 'nodejs', 'node', 'python', 'cpython', 'ruby', 'go', 'golang', 'openjdk', 'java',
            'exim', 'postfix', 'dovecot', 'bind', 'bind9', 'unbound', 'dnsmasq', 'samba', 'chrony', 'ntp',
            'openvpn', 'wireguard', 'strongswan', 'grafana', 'gitea', 'gitlab', 'jenkins', 'cockpit', 'webmin', 'nextcloud', 'wordpress',
        ],
    ],

];
