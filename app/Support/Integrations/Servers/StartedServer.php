<?php

namespace App\Support\Integrations\Servers;

final readonly class StartedServer
{
    public function __construct(
        public Server $server,
        public ?string $actionId,
    ) {}
}
