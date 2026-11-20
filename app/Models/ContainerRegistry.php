<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A workspace's login for a container registry, for deploys that pull
 * private images from it. The password goes to a machine only with a
 * deploy, is never stored in the job, and is never shown again.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $host
 * @property string $username
 * @property string $password
 * @property int|null $user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
#[Fillable(['host', 'username', 'password', 'user_id'])]
#[Hidden(['password'])]
class ContainerRegistry extends Model
{
    public const string DOCKER_HUB = 'docker.io';

    // A host name, or an IP address, with an optional port: no scheme, no path.
    public const string HOST_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*(?::\d{1,5})?$/';

    private const array HUB_ALIASES = ['docker.io', 'index.docker.io', 'registry-1.docker.io', 'hub.docker.com', 'registry.hub.docker.com'];

    protected function casts(): array
    {
        return ['password' => 'encrypted'];
    }

    /** "ghcr.io" from "https://ghcr.io/acme/app", and every Docker Hub name as "docker.io". */
    public static function normalizeHost(mixed $host): mixed
    {
        if (! is_string($host)) {
            return $host;
        }
        $host = strtolower(trim($host));
        $host = (string) preg_replace(['#^[a-z]+://#', '#/.*$#', '#\.$#'], '', $host);

        return in_array($host, self::HUB_ALIASES, true) ? self::DOCKER_HUB : $host;
    }

    public function label(): string
    {
        return match ($this->host) {
            self::DOCKER_HUB => 'Docker Hub',
            'ghcr.io' => 'GitHub Container Registry',
            default => $this->host,
        };
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
