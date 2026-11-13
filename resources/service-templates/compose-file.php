<?php

return [
    'name' => 'Your own Docker Compose file',
    'category' => 'Apps',
    'description' => 'Runs the Compose file in your repository as one stack, like docker compose up.',
    'icon' => 'docker',
    'kind' => 'app',
    'port' => 80,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'compose' => 'compose.yaml',
    'notes' => 'The port is the one your Compose file publishes on this machine. Publish it on 127.0.0.1 (like "127.0.0.1:8080:80") so it’s only reachable through the domain. Settings you add here fill in ${NAME} in the Compose file. Named volumes are kept between deploys.',
    'env' => [],
];
