<?php

namespace App\Support\Images;

use App\Models\ContainerRegistry;

/**
 * An image reference as Docker reads one: `postgres:16` is
 * docker.io/library/postgres, tag 16; `ghcr.io/acme/app` is ghcr.io,
 * acme/app, tag latest. A reference pinned to a digest has no newer image.
 */
final readonly class ImageReference
{
    private function __construct(
        public string $registry,
        public string $repository,
        public string $tag,
    ) {}

    public static function parse(string $reference): ?self
    {
        $reference = trim($reference);
        if ($reference === '' || strlen($reference) > 512 || str_contains($reference, '@') || str_starts_with($reference, 'sha256:')
            || ! preg_match('#^[a-z0-9._/:-]+$#i', $reference)) {
            return null;
        }

        $slash = strrpos($reference, '/');
        $last = $slash === false ? $reference : substr($reference, $slash + 1);
        $colon = strrpos($last, ':');
        $tag = $colon === false ? 'latest' : substr($last, $colon + 1);
        $name = $colon === false ? $reference : substr($reference, 0, strlen($reference) - strlen($last) + $colon);

        $registry = ContainerRegistry::DOCKER_HUB;
        $first = explode('/', $name, 2);
        if (count($first) === 2 && (str_contains($first[0], '.') || str_contains($first[0], ':') || $first[0] === 'localhost')) {
            $registry = strtolower($first[0]);
            $name = $first[1];
        }
        $registry = (string) ContainerRegistry::normalizeHost($registry);
        if ($registry === ContainerRegistry::DOCKER_HUB && ! str_contains($name, '/')) {
            $name = 'library/'.$name;
        }
        if ($tag === '' || $name === '' || ! preg_match('/^[\w][\w.-]{0,127}$/', $tag)) {
            return null;
        }

        return new self($registry, strtolower($name), $tag);
    }

    /** Where the registry's API is: Docker Hub's isn't at docker.io. */
    public function apiHost(): string
    {
        return $this->registry === ContainerRegistry::DOCKER_HUB ? 'registry-1.docker.io' : $this->registry;
    }

    /** The name as people write it: postgres:16, ghcr.io/acme/app:1. */
    public function display(): string
    {
        $name = $this->registry === ContainerRegistry::DOCKER_HUB
            ? preg_replace('#^library/#', '', $this->repository)
            : $this->registry.'/'.$this->repository;

        return $name.':'.$this->tag;
    }

    /**
     * Whether a registry digest Docker keeps for an image
     * (`postgres@sha256:…`, `docker.io/library/postgres@sha256:…`) is
     * this repository's.
     */
    public function owns(string $repoDigest): bool
    {
        $at = strrpos($repoDigest, '@');
        if ($at === false) {
            return false;
        }
        $other = self::parse(substr($repoDigest, 0, $at).':x');

        return $other !== null && $other->registry === $this->registry && $other->repository === $this->repository;
    }

    /**
     * The sha256 digests among Docker's registry digests for an image that
     * belong to this repository.
     *
     * @param  list<string>  $repoDigests
     * @return list<string>
     */
    public function digestsIn(array $repoDigests): array
    {
        $out = [];
        foreach ($repoDigests as $digest) {
            if ($this->owns($digest) && preg_match('/@(sha256:[a-f0-9]{64})$/', $digest, $m)) {
                $out[] = $m[1];
            }
        }

        return array_values(array_unique($out));
    }
}
