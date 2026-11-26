<?php

namespace App\Console\Commands;

use App\Support\Machines\MachineMoves;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('moves:tick')]
#[Description('Move machine moves on: jobs whose finish was missed, data waiting for the destination, keys to take back')]
class MovesTick extends Command
{
    public function handle(MachineMoves $moves): int
    {
        $moves->tick(CarbonImmutable::now());

        return self::SUCCESS;
    }
}
