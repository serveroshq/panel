<?php

namespace App\Support\Images;

use App\Models\ContainerRegistry;
use App\Support\Monitors\HostResolver;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Asks a registry which image a tag points at, the way Docker does before
 * a pull: a HEAD for the tag's manifest (an index for multi-platform
 * images), signing in with a token when the registry asks. HEADs don't
 * count against Docker Hub's pull limits. Answers are kept for most of an
 * hour, so a tag used on many machines is asked about once.
 */
class RegistryClient
{
    private const array ACCEPT = [
        'application/vnd.oci.image.index.v1+json',
        'application/vnd.docker.distribution.manifest.list.v2+json',
        'application/vnd.docker.distribution.manifest.v2+json',
        'application/vnd.oci.image.manifest.v1+json',
    ];

    public const int CACHE_MINUTES = 50;

    public function __construct(private readonly HostResolver $hosts) {}

    /**
     * The digest the tag points at now (`sha256:…`).
     *
     * @throws RuntimeException when the registry can't be asked or won't say
     */
    public function digest(ImageReference $image, ?ContainerRegistry $login = null): string
    {
        $key = 'images:digest:'.sha1($image->display().'|'.($login !== null ? $login->id.':'.$login->updated_at?->timestamp : ''));

        $cached = Cache::get($key);
        if (is_string($cached)) {
            return $cached;
        }

        $digest = $this->ask($image, $login);
        Cache::put($key, $digest, now()->addMinutes(self::CACHE_MINUTES));

        return $digest;
    }

    private function ask(ImageReference $image, ?ContainerRegistry $login): string
    {
        $url = 'https://'.$image->apiHost().'/v2/'.$image->repository.'/manifests/'.rawurlencode($image->tag);

        try {
            $response = $this->http($url)->head($url);
            if ($response->status() === 401) {
                $token = $this->token($response, $image, $login);
                $response = $this->http($url)
                    ->when($token !== null, fn (PendingRequest $r) => $r->withToken((string) $token))
                    ->when($token === null && $login !== null, fn (PendingRequest $r) => $r->withBasicAuth($login->username, $login->password))
                    ->head($url);
            }
        } catch (ConnectionException) {
            throw new RuntimeException(__('Couldn’t reach :host.', ['host' => $image->registry]));
        }

        if (in_array($response->status(), [401, 403], true)) {
            throw new RuntimeException($login === null
                ? __('The registry wants a login. Add one in Settings › Registries to check this image.')
                : __('The registry turned down the saved login.'));
        }
        if ($response->status() === 404) {
            throw new RuntimeException(__('The registry has no :tag tag for this image any more.', ['tag' => $image->tag]));
        }
        if ($response->status() === 429) {
            throw new RuntimeException(__('The registry asked us to slow down. It’s checked again later.'));
        }

        $digest = strtolower(trim((string) $response->header('Docker-Content-Digest')));
        if (! $response->successful() || ! preg_match('/^sha256:[a-f0-9]{64}$/', $digest)) {
            throw new RuntimeException(__('The registry didn’t say which image the tag points at (HTTP :status).', ['status' => $response->status()]));
        }

        return $digest;
    }

    /** A pull token from the realm a 401 names, signed in when there's a login. */
    private function token(Response $challenge, ImageReference $image, ?ContainerRegistry $login): ?string
    {
        $header = (string) $challenge->header('WWW-Authenticate');
        if (! str_starts_with(strtolower($header), 'bearer ')) {
            return null;
        }
        preg_match_all('/(\w+)="([^"]*)"/', $header, $pairs, PREG_SET_ORDER);
        $params = [];
        foreach ($pairs as $pair) {
            $params[strtolower($pair[1])] = $pair[2];
        }
        $realm = $params['realm'] ?? '';
        if (! str_starts_with($realm, 'https://')) {
            return null;
        }

        $query = array_filter([
            'service' => $params['service'] ?? null,
            'scope' => $params['scope'] ?? 'repository:'.$image->repository.':pull',
        ]);
        $response = $this->http($realm)
            ->when($login !== null, fn (PendingRequest $r) => $r->withBasicAuth((string) $login?->username, (string) $login?->password))
            ->get($realm, $query);
        if (! $response->successful()) {
            return null;
        }
        $token = $response->json('token') ?? $response->json('access_token');

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * A request to one https URL, pinned to an address that isn't on a
     * private network (registry names come from machines, so they're
     * never trusted to point somewhere internal), with no redirects.
     *
     * @throws RuntimeException
     */
    private function http(string $url): PendingRequest
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $port = (int) (parse_url($url, PHP_URL_PORT) ?? 443);
        $addresses = $host === '' ? [] : $this->hosts->resolveOnLan($host);
        if ($addresses === []) {
            throw new RuntimeException(__('Couldn’t find :host, or it’s on a private network.', ['host' => $host]));
        }
        $ip = str_contains($addresses[0], ':') ? '['.$addresses[0].']' : $addresses[0];

        return Http::timeout(15)
            ->connectTimeout(5)
            ->withHeaders(['Accept' => implode(', ', self::ACCEPT), 'User-Agent' => 'ServerOS image check'])
            ->withOptions([
                'allow_redirects' => false,
                'curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]],
            ]);
    }
}
