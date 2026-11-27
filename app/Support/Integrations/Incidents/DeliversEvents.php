<?php

namespace App\Support\Integrations\Incidents;

use App\Models\Integration;
use App\Models\IntegrationEvent;
use App\Support\Integrations\IntegrationFailed;

/** A provider ServerOS sends things to, one IntegrationEvent at a time. */
interface DeliversEvents
{
    /**
     * Sends the event. Returns what's worth keeping about it, with `skipped`
     * set when there was nothing to do.
     *
     * @return array<string, mixed>
     *
     * @throws IntegrationFailed
     */
    public function deliver(Integration $integration, IntegrationEvent $event): array;

    /** What the account page calls the event, like "Triggered" or "Closed the issue". */
    public function eventLabel(IntegrationEvent $event): string;
}
