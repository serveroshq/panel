<?php

namespace App\Console\Commands;

use App\Support\Webhooks\Webhooks;
use Illuminate\Console\Command;

class RetryWebhooks extends Command
{
    protected $signature = 'webhooks:retry';

    protected $description = 'Send webhook deliveries that are due to be tried again';

    public function handle(Webhooks $webhooks): int
    {
        $count = $webhooks->retryDue();
        if ($count > 0) {
            $this->info("{$count} webhook deliveries queued.");
        }

        return self::SUCCESS;
    }
}
