<?php

namespace App\Support\Integrations\Servers;

final readonly class NewServer
{
    /** @param  list<string>  $keys SSH key ids */
    public function __construct(
        public string $name,
        public string $location,
        public string $type,
        public string $image,
        public array $keys = [],
    ) {}
}
