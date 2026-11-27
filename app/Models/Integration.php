<?php

namespace App\Models;

use App\Support\Integrations\IntegrationProviders;
use App\Support\Integrations\Provider;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An account at another service that a workspace connected, like a Hetzner
 * Cloud project. Its credentials are encrypted and never leave the server.
 *
 * @property int $id
 * @property int $workspace_id
 * @property string $provider the IntegrationCatalogue key of the provider
 * @property string $label
 * @property array<string, string> $credentials
 * @property string $fingerprint a keyed hash of the credentials, so one account isn't connected twice
 * @property array<string, mixed>|null $meta what the provider said about the account when it was last checked
 * @property array<string, mixed>|null $settings the choices made for it in ServerOS, like what pages it
 * @property string $status connected or failing
 * @property CarbonImmutable|null $last_checked_at
 * @property string|null $last_error
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $creator
 */
#[Hidden(['credentials', 'fingerprint'])]
class Integration extends Model
{
    public const string CONNECTED = 'connected';

    public const string FAILING = 'failing';

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'credentials' => 'encrypted:array',
            'meta' => 'array',
            'settings' => 'array',
            'last_checked_at' => 'datetime',
        ];
    }

    /** @param  array<string, string>  $credentials */
    public static function fingerprintOf(string $provider, array $credentials): string
    {
        ksort($credentials);

        return hash_hmac('sha256', $provider."\n".json_encode($credentials), (string) config('app.key'));
    }

    public function provider(): Provider
    {
        return app(IntegrationProviders::class)->get($this->provider);
    }

    public function credential(string $key): string
    {
        return (string) ($this->credentials[$key] ?? '');
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return HasMany<IntegrationServer, $this> */
    public function servers(): HasMany
    {
        return $this->hasMany(IntegrationServer::class);
    }

    /** @return HasMany<IntegrationOperation, $this> */
    public function operations(): HasMany
    {
        return $this->hasMany(IntegrationOperation::class);
    }

    /** @return HasMany<IntegrationApp, $this> */
    public function apps(): HasMany
    {
        return $this->hasMany(IntegrationApp::class);
    }

    /** @return HasMany<IntegrationIncident, $this> */
    public function incidents(): HasMany
    {
        return $this->hasMany(IntegrationIncident::class);
    }

    /** @return HasMany<IntegrationEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(IntegrationEvent::class);
    }

    public function setting(string $key, mixed $default = null): mixed
    {
        return $this->settings[$key] ?? $default;
    }
}
