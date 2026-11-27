<?php

namespace App\Console\Commands;

use App\Support\Deploys\AppSchedules;
use Illuminate\Console\Command;

class RunAppSchedules extends Command
{
    protected $signature = 'apps:run-schedules';

    protected $description = 'Run the scheduled commands of apps that are due this minute, in their live containers';

    public function handle(AppSchedules $schedules): int
    {
        $ran = $schedules->runDue();
        $this->info("Started {$ran['started']} scheduled command(s), skipped {$ran['skipped']}.");

        return self::SUCCESS;
    }
}
