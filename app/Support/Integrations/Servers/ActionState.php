<?php

namespace App\Support\Integrations\Servers;

final readonly class ActionState
{
    public const string RUNNING = 'running';

    public const string DONE = 'done';

    public const string FAILED = 'failed';

    public function __construct(
        public string $state,
        public ?string $error = null,
        public ?int $progress = null,
    ) {}
}
