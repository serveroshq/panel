<?php

namespace App\Support\Integrations;

use App\Models\Integration;

/**
 * A provider that holds code a workspace deploys, like GitLab. Its account
 * page lists the projects apps are deployed from.
 */
interface HostsRepositories
{
    /** @return list<array<string, mixed>> */
    public function projects(Integration $integration): array;

    /** @return list<string> what the account is used for */
    public function usage(Integration $integration): array;

    /** @return list<string> what stops working when it's disconnected */
    public function dependents(Integration $integration): array;
}
