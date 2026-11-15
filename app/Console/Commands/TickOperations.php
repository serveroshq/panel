<?php

namespace App\Console\Commands;

use App\Support\Operations\Operations;
use Illuminate\Console\Command;

class TickOperations extends Command
{
    protected $signature = 'operations:tick';

    protected $description = 'Move operations along, batch by batch';

    public function handle(Operations $operations): int
    {
        $count = $operations->tick();
        if ($count > 0) {
            $this->info("{$count} operation(s) looked at.");
        }

        return self::SUCCESS;
    }
}
