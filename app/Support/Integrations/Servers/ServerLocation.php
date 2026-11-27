<?php

namespace App\Support\Integrations\Servers;

final readonly class ServerLocation
{
    public function __construct(
        public string $name,
        public string $city,
        public string $country,
        public ?string $zone = null,
    ) {}

    /** @return array{name: string, city: string, country: string, zone: string|null} */
    public function toArray(): array
    {
        return ['name' => $this->name, 'city' => $this->city, 'country' => $this->country, 'zone' => $this->zone];
    }
}
