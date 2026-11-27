<?php

namespace App\Support\Integrations\Servers;

final readonly class ServerImage
{
    public function __construct(
        public string $id,
        public string $label,
        public string $os,
        public ?string $version,
        public string $architecture,
        public bool $recommended = false,
    ) {}

    /** @return array{id: string, label: string, os: string, version: string|null, architecture: string, recommended: bool} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'label' => $this->label, 'os' => $this->os, 'version' => $this->version, 'architecture' => $this->architecture, 'recommended' => $this->recommended];
    }
}
