<?php

return [
    'name' => 'Stalwart',
    'category' => 'Self-hosted',
    'description' => 'All-in-one mail server with SMTP, IMAP, JMAP and spam filtering.',
    'icon' => 'mail',
    'kind' => 'compose',
    'port' => 8080,
    'web' => true,
    'port_editable' => true,
    'health' => '/healthz/live',
    'notes' => 'The first sign-in only works over plain HTTP on this machine. Open http://127.0.0.1 and the port from the deploy log, at /admin, and sign in as admin. The password is printed in the service logs. Stalwart rejects that first sign-in through a Cloudflare Tunnel, because the tunnel arrives as HTTPS. Finish the wizard, then restart the service. After the restart, sign in at the domain you deployed, over https, at /admin. Mail ports 25 (SMTP), 465 and 587 (submission), 143 and 993 (IMAP) and 4190 (Sieve) are open to the internet. Many VPS hosts block port 25 until you ask, and you will need MX, SPF, DKIM and reverse DNS records set up.',
    'env' => [
        ['key' => 'STALWART_PUBLIC_URL', 'label' => 'Public URL', 'default' => '{url}', 'hint' => 'The https address people open. Filled from the domain. Stalwart uses it after the setup wizard, once the service restarts.'],
    ],
    'files' => [
        'compose.yaml' => <<<'YAML'
services:
  stalwart:
    image: stalwartlabs/stalwart:v1
    init: true
    restart: unless-stopped
    environment:
      STALWART_PUBLIC_URL: ${STALWART_PUBLIC_URL}
    ports:
      - "127.0.0.1:${PORT}:8080"
      - "25:25"
      - "465:465"
      - "587:587"
      - "143:143"
      - "993:993"
      - "4190:4190"
    volumes:
      - stalwart-etc:/etc/stalwart
      - stalwart-data:/var/lib/stalwart
volumes:
  stalwart-etc:
  stalwart-data:
YAML,
    ],
];
