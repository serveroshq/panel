<?php

namespace App\Support\Integrations\Cloudflare;

use App\Models\Integration;
use App\Models\IntegrationDnsRecord;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;
use Illuminate\Support\Facades\Cache;

/**
 * @phpstan-type Zone array{id: string, name: string, status: string, account: string|null}
 */
final class CloudflareProvider extends Provider
{
    private const int ZONES_MINUTES = 10;

    public function key(): string
    {
        return 'cloudflare';
    }

    public function name(): string
    {
        return 'Cloudflare';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'token',
                label: self::t('API token'),
                help: self::t('In Cloudflare, open My Profile › API Tokens and create a custom token with Zone › Zone › Read, Zone › DNS › Edit and Zone › Cache Purge › Purge, for the zones ServerOS may change.'),
                link: 'https://dash.cloudflare.com/profile/api-tokens',
                linkLabel: self::t('Open Cloudflare API tokens'),
                placeholder: self::t('Paste the token'),
                rules: ['required', 'string', 'min:20', 'max:200', 'regex:/^\S+$/'],
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::Dns, Capability::PurgeCache];
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $client = new CloudflareClient($credentials['token'] ?? '');
        $token = (array) ($client->get('user/tokens/verify')['result'] ?? []);
        $status = $token['status'] ?? 'active';
        if ($status !== 'active') {
            throw new IntegrationFailed($status === 'expired'
                ? self::t('This token has expired. Make a new one in Cloudflare and connect it.')
                : self::t('This token is turned off in Cloudflare. Turn it on there, or make a new one.'), IntegrationFailed::CREDENTIALS);
        }

        $zones = self::zonesFrom($client);
        Cache::put(self::zonesKey($credentials), $zones, now()->addMinutes(self::ZONES_MINUTES));
        $account = collect($zones)->pluck('account')->filter()->first() ?? self::accountName($client);

        return new ConnectedAccount($account ?? 'Cloudflare', ['account' => $account, 'zones' => count($zones)]);
    }

    public function summary(Integration $integration): string
    {
        $count = (int) ($integration->meta['zones'] ?? 0);

        return $count === 1 ? self::t('1 zone') : self::t(':count zones', ['count' => $count]);
    }

    public function facts(Integration $integration): array
    {
        $facts = [];
        if (is_string($integration->meta['account'] ?? null)) {
            $facts[] = ['label' => self::t('Account'), 'value' => $integration->meta['account']];
        }
        $facts[] = ['label' => self::t('Zones'), 'value' => (string) (int) ($integration->meta['zones'] ?? 0)];
        $facts[] = ['label' => self::t('Records ServerOS manages'), 'value' => (string) IntegrationDnsRecord::query()->where('integration_id', $integration->id)->count()];

        return $facts;
    }

    /**
     * The account's zones, from a short cache.
     *
     * @return list<Zone>
     *
     * @throws IntegrationFailed
     */
    public function zones(Integration $integration, bool $fresh = false): array
    {
        $key = self::zonesKey($integration->credentials);
        if ($fresh) {
            Cache::forget($key);
        }

        /** @var list<Zone> */
        return Cache::remember($key, now()->addMinutes(self::ZONES_MINUTES), fn () => self::zonesFrom($this->client($integration)));
    }

    public function client(Integration $integration): CloudflareClient
    {
        return new CloudflareClient($integration->credential('token'));
    }

    /** @param  array<string, string>  $credentials */
    private static function zonesKey(array $credentials): string
    {
        return 'integrations:cloudflare:zones:'.Integration::fingerprintOf('cloudflare', $credentials);
    }

    /** @return list<Zone> */
    private static function zonesFrom(CloudflareClient $client): array
    {
        $zones = [];
        foreach ($client->all('zones') as $zone) {
            if (is_string($zone['id'] ?? null) && is_string($zone['name'] ?? null)) {
                $zones[] = [
                    'id' => $zone['id'],
                    'name' => strtolower($zone['name']),
                    'status' => (string) ($zone['status'] ?? 'active'),
                    'account' => is_string($zone['account']['name'] ?? null) ? $zone['account']['name'] : null,
                ];
            }
        }
        usort($zones, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        return $zones;
    }

    private static function accountName(CloudflareClient $client): ?string
    {
        try {
            $name = $client->get('accounts', ['per_page' => 1])['result'][0]['name'] ?? null;
        } catch (IntegrationFailed) {
            return null;
        }

        return is_string($name) && $name !== '' ? $name : null;
    }
}
