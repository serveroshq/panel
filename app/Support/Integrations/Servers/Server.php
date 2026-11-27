<?php

namespace App\Support\Integrations\Servers;

/**
 * A server at a provider, in ServerOS's words. Status is one of running,
 * starting, stopping, off, deleting or other.
 */
final readonly class Server
{
    public function __construct(
        public string $id,
        public string $name,
        public string $status,
        public string $type,
        public string $typeLabel,
        public int $cores,
        public float $memoryGb,
        public int $diskGb,
        public string $location,
        public ?string $city,
        public ?string $country,
        public ?string $ipv4,
        public ?string $ipv6,
        public ?string $architecture = null,
        public ?string $createdAt = null,
    ) {}

    /** @return array{id: string, name: string, status: string, type: string, typeLabel: string, cores: int, memoryGb: float, diskGb: int, location: string, city: string|null, country: string|null, ipv4: string|null, ipv6: string|null, architecture: string|null, createdAt: string|null} */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'type' => $this->type,
            'typeLabel' => $this->typeLabel,
            'cores' => $this->cores,
            'memoryGb' => $this->memoryGb,
            'diskGb' => $this->diskGb,
            'location' => $this->location,
            'city' => $this->city,
            'country' => $this->country,
            'ipv4' => $this->ipv4,
            'ipv6' => $this->ipv6,
            'architecture' => $this->architecture,
            'createdAt' => $this->createdAt,
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        return new self(
            (string) ($data['id'] ?? ''),
            (string) ($data['name'] ?? ''),
            (string) ($data['status'] ?? 'other'),
            (string) ($data['type'] ?? ''),
            (string) ($data['typeLabel'] ?? ''),
            (int) ($data['cores'] ?? 0),
            (float) ($data['memoryGb'] ?? 0),
            (int) ($data['diskGb'] ?? 0),
            (string) ($data['location'] ?? ''),
            isset($data['city']) ? (string) $data['city'] : null,
            isset($data['country']) ? (string) $data['country'] : null,
            isset($data['ipv4']) ? (string) $data['ipv4'] : null,
            isset($data['ipv6']) ? (string) $data['ipv6'] : null,
            isset($data['architecture']) ? (string) $data['architecture'] : null,
            isset($data['createdAt']) ? (string) $data['createdAt'] : null,
        );
    }
}
