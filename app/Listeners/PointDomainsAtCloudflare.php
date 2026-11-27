<?php

namespace App\Listeners;

use App\Events\DeployQueued;
use App\Jobs\PointCloudflareDomains;
use App\Support\Integrations\Cloudflare\CloudflareDns;

/**
 * Every deploy with a domain, from any path (the dialog, a new domain, a
 * push, MCP, a project environment, a preview), points the domains whose
 * zone is in a connected Cloudflare account at the machine.
 */
class PointDomainsAtCloudflare
{
    public function __construct(private readonly CloudflareDns $dns) {}

    public function handle(DeployQueued $event): void
    {
        $domains = array_values(array_filter($event->domains, 'is_string'));
        $service = $event->job->service;
        if ($domains === [] || ! is_string($service) || ! $this->dns->connected($event->machine->workspace_id)) {
            return;
        }

        PointCloudflareDomains::dispatch($event->machine->id, $service, $domains);
    }
}
