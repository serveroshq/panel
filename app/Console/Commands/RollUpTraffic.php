<?php

namespace App\Console\Commands;

use App\Models\TrafficRollup;
use Illuminate\Console\Command;

class RollUpTraffic extends Command
{
    protected $signature = 'traffic:roll-up';

    protected $description = 'Count the sites\' traffic minutes that came in since the last run into their hours, so the 7 and 30 day views stay current';

    public function handle(): int
    {
        $hours = TrafficRollup::rollUp();
        $this->info("Wrote {$hours} traffic hours.");

        return self::SUCCESS;
    }
}
