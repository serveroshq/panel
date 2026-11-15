<?php

namespace App\Jobs;

use App\Models\WebhookDelivery;
use App\Support\Webhooks\Webhooks;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** Sends one webhook delivery. Retries are planned on the delivery itself, not by the queue. */
class DeliverWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public int $deliveryId) {}

    public function handle(Webhooks $webhooks): void
    {
        $delivery = WebhookDelivery::query()->with('endpoint.workspace')->find($this->deliveryId);
        if ($delivery !== null) {
            $webhooks->attempt($delivery);
        }
    }
}
