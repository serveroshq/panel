<?php

namespace App\Console\Commands;

use App\Support\Agents\AgentTasks;
use Illuminate\Console\Command;

class DispatchAgents extends Command
{
    protected $signature = 'agents:dispatch';

    protected $description = 'Start agents waiting for room on their machine, or for a quiet hour';

    public function handle(AgentTasks $tasks): int
    {
        if (! config('serveros.agents')) {
            return self::SUCCESS;
        }
        $started = $tasks->dispatch();
        if ($started > 0) {
            $this->info("Started {$started} waiting agents.");
        }

        return self::SUCCESS;
    }
}
