<?php

namespace App\Support\Integrations\Servers;

final readonly class Snapshot
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public ?float $sizeGb,
        public ?int $diskGb,
        public ?string $createdAt,
    ) {}

    /** @return array{id: string, name: string, status: string, sizeGb: float|null, diskGb: int|null, createdAt: string|null} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'status' => $this->status, 'sizeGb' => $this->sizeGb, 'diskGb' => $this->diskGb, 'createdAt' => $this->createdAt];
    }
}
