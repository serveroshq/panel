<?php

return [
    'name' => 'A Docker image',
    'category' => 'Apps',
    'description' => 'Runs an image that’s already built, from Docker Hub, GitHub’s registry or your own. Nothing is built on the machine.',
    'icon' => 'docker',
    'kind' => 'image',
    'port' => 80,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Leave the port empty to use the one the image exposes. For a private image, add a login for its registry in Settings › General › Container registries. To keep files between deploys, add a volume.',
    'env' => [],
];
