<?php

namespace App\Support\Integrations\Servers;

final readonly class ServerType
{
    /**
     * @param  list<string>  $locations  where it can be made now
     * @param  array<string, array{monthly: float, hourly: float}>  $prices  by location
     */
    public function __construct(
        public string $name,
        public string $label,
        public int $cores,
        public float $memoryGb,
        public int $diskGb,
        public string $cpu,
        public string $architecture,
        public string $storage,
        public array $locations,
        public array $prices,
    ) {}

    /** @return array{monthly: float, hourly: float}|null */
    public function priceIn(string $location): ?array
    {
        return $this->prices[$location] ?? null;
    }

    /** @return array{name: string, label: string, cores: int, memoryGb: float, diskGb: int, cpu: string, architecture: string, storage: string, locations: list<string>, prices: array<string, array{monthly: float, hourly: float}>} */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'label' => $this->label,
            'cores' => $this->cores,
            'memoryGb' => $this->memoryGb,
            'diskGb' => $this->diskGb,
            'cpu' => $this->cpu,
            'architecture' => $this->architecture,
            'storage' => $this->storage,
            'locations' => $this->locations,
            'prices' => $this->prices,
        ];
    }
}
