<?php

namespace App\Support\Integrations;

use App\Support\Integrations\Cloudflare\CloudflareProvider;
use App\Support\Integrations\DigitalOcean\DigitalOceanProvider;
use App\Support\Integrations\GitLab\GitLabProvider;
use App\Support\Integrations\Hetzner\HetznerProvider;
use App\Support\Integrations\Linear\LinearProvider;
use App\Support\Integrations\PagerDuty\PagerDutyProvider;
use App\Support\Integrations\Sentry\SentryProvider;
use App\Support\Integrations\Vultr\VultrProvider;
use Illuminate\Container\Attributes\Singleton;
use InvalidArgumentException;

#[Singleton]
final class IntegrationProviders
{
    /** @var array<string, Provider> */
    private array $providers = [];

    public function __construct()
    {
        $this->register(new HetznerProvider);
        $this->register(new DigitalOceanProvider);
        $this->register(new VultrProvider);
        $this->register(new CloudflareProvider);
        $this->register(new SentryProvider);
        $this->register(new PagerDutyProvider);
        $this->register(new LinearProvider);
        $this->register(new GitLabProvider);
    }

    public function register(Provider $provider): void
    {
        $this->providers[$provider->key()] = $provider;
    }

    public function find(string $key): ?Provider
    {
        return $this->providers[$key] ?? null;
    }

    public function get(string $key): Provider
    {
        return $this->providers[$key] ?? throw new InvalidArgumentException("No integration provider is built for {$key}.");
    }

    /** @return array<string, Provider> */
    public function all(): array
    {
        return $this->providers;
    }
}
