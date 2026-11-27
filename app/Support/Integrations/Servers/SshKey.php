<?php

namespace App\Support\Integrations\Servers;

final readonly class SshKey
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $fingerprint = null,
    ) {}

    /** @return array{id: string, name: string, fingerprint: string|null} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'fingerprint' => $this->fingerprint];
    }
}
