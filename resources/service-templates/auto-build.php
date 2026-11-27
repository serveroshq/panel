<?php

return [
    'name' => 'Build it for me',
    'category' => 'Apps',
    'description' => 'No Dockerfile? Railpack reads your code (Node, Python, Go, PHP, Ruby, Rust, Java and more) and works out how to build and start it.',
    'icon' => 'code',
    'kind' => 'app',
    'build' => 'railpack',
    'port' => 3000,
    'web' => true,
    'port_editable' => true,
    'health' => '/',
    'notes' => 'Listen on the PORT setting (3000 unless you change it) and on 0.0.0.0, not localhost. To keep files between deploys, add a volume in the app’s build settings.',
    'env' => [],
];
