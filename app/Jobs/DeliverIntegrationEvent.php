<?php

namespace App\Jobs;

use App\Models\IntegrationEvent;
use App\Support\Integrations\Incidents\IntegrationEvents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

class DeliverIntegrationEvent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 90;

    public function __construct(public int $eventId) {}

    public function handle(IntegrationEvents $events): void
    {
        $event = IntegrationEvent::query()->find($this->eventId);
        if ($event !== null) {
            $events->deliver($event);
        }
    }
}
