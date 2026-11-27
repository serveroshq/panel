<?php

namespace App\Support\Integrations\Servers;

/**
 * What a new server can be: where, how big, which system, which SSH keys.
 */
final readonly class ServerPlans
{
    /**
     * @param  list<ServerLocation>  $locations
     * @param  list<ServerType>  $types
     * @param  list<ServerImage>  $images
     * @param  list<SshKey>  $keys
     */
    public function __construct(
        public array $locations,
        public array $types,
        public array $images,
        public array $keys,
        public string $currency,
    ) {}

    public function type(string $name): ?ServerType
    {
        foreach ($this->types as $type) {
            if ($type->name === $name) {
                return $type;
            }
        }

        return null;
    }

    public function image(string $id, ?string $architecture = null): ?ServerImage
    {
        foreach ($this->images as $image) {
            if ($image->id === $id && ($architecture === null || $image->architecture === $architecture)) {
                return $image;
            }
        }

        return null;
    }

    public function location(string $name): ?ServerLocation
    {
        foreach ($this->locations as $location) {
            if ($location->name === $name) {
                return $location;
            }
        }

        return null;
    }

    /** @param  array<string, mixed>  $data */
    public static function fromArray(array $data): self
    {
        $list = fn (string $key): array => array_values(array_filter((array) ($data[$key] ?? []), 'is_array'));

        return new self(
            array_map(fn (array $l) => new ServerLocation((string) ($l['name'] ?? ''), (string) ($l['city'] ?? ''), (string) ($l['country'] ?? ''), isset($l['zone']) ? (string) $l['zone'] : null), $list('locations')),
            array_map(fn (array $t) => new ServerType(
                (string) ($t['name'] ?? ''),
                (string) ($t['label'] ?? ''),
                (int) ($t['cores'] ?? 0),
                (float) ($t['memoryGb'] ?? 0),
                (int) ($t['diskGb'] ?? 0),
                (string) ($t['cpu'] ?? 'shared'),
                (string) ($t['architecture'] ?? 'x86'),
                (string) ($t['storage'] ?? 'local'),
                array_values(array_map('strval', (array) ($t['locations'] ?? []))),
                array_map(fn ($p) => ['monthly' => (float) ($p['monthly'] ?? 0), 'hourly' => (float) ($p['hourly'] ?? 0)], (array) ($t['prices'] ?? [])),
            ), $list('types')),
            array_map(fn (array $i) => new ServerImage((string) ($i['id'] ?? ''), (string) ($i['label'] ?? ''), (string) ($i['os'] ?? ''), isset($i['version']) ? (string) $i['version'] : null, (string) ($i['architecture'] ?? 'x86'), (bool) ($i['recommended'] ?? false)), $list('images')),
            array_map(fn (array $k) => new SshKey((string) ($k['id'] ?? ''), (string) ($k['name'] ?? ''), isset($k['fingerprint']) ? (string) $k['fingerprint'] : null), $list('keys')),
            (string) ($data['currency'] ?? 'EUR'),
        );
    }

    /** @return array{locations: list<array<string, mixed>>, types: list<array<string, mixed>>, images: list<array<string, mixed>>, keys: list<array<string, mixed>>, currency: string} */
    public function toArray(): array
    {
        return [
            'locations' => array_map(fn (ServerLocation $l) => $l->toArray(), $this->locations),
            'types' => array_map(fn (ServerType $t) => $t->toArray(), $this->types),
            'images' => array_map(fn (ServerImage $i) => $i->toArray(), $this->images),
            'keys' => array_map(fn (SshKey $k) => $k->toArray(), $this->keys),
            'currency' => $this->currency,
        ];
    }
}
