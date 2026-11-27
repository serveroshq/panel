<?php

namespace App\Support\Integrations\Incidents;

use App\Models\Integration;
use App\Support\Integrations\IntegrationFailed;

/** A provider that opens something when a problem starts and closes it when it ends. */
interface RoutesIncidents extends DeliversEvents
{
    /** Whether this account should hear about the problem now. */
    public function wants(Integration $integration, Problem $problem): bool;

    /**
     * @return array<string, list<mixed>>
     */
    public function settingsRules(Integration $integration): array;

    /**
     * Turns the validated choices into the settings kept on the account.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws IntegrationFailed
     */
    public function settings(Integration $integration, array $data): array;

    /** @return array<string, mixed> the choices as the account page shows them */
    public function settingsProps(Integration $integration): array;
}
