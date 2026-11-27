<?php

namespace App\Console\Commands;

use App\Support\Integrations\Incidents\IncidentRouter;
use Illuminate\Console\Command;

class SweepIntegrationIncidents extends Command
{
    protected $signature = 'integrations:incidents';

    protected $description = 'Open, update and close PagerDuty alerts and Linear issues, and send what failed again';

    public function handle(IncidentRouter $router): int
    {
        $router->sweep();

        return self::SUCCESS;
    }
}
