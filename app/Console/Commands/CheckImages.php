<?php

namespace App\Console\Commands;

use App\Models\Machine;
use App\Support\Images\ImageScanner;
use App\Support\Images\ImageUpdates;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Every 15 minutes: checks containers against their registries for newer
 * images (each machine about once an hour), then looks up the packages of
 * images machines have read since the last run, and asks for the next ones.
 */
class CheckImages extends Command
{
    protected $signature = 'images:check
        {--machine= : Check this machine (its id) now, whether it’s due or not}';

    protected $description = 'Check containers for newer images and known vulnerabilities in their images';

    public function handle(ImageUpdates $updates, ImageScanner $scanner): int
    {
        $machines = $this->option('machine')
            ? Machine::query()->whereKey((int) $this->option('machine'))->get()
            : ImageUpdates::due()->filter(function (Machine $machine) {
                $oldest = $machine->containerImages()->min('checked_at');

                return $oldest === null || Carbon::parse($oldest)->lt(now()->subMinutes(55))
                    || $machine->containerImages()->count() < count(ImageUpdates::containers($machine));
            });

        $newer = 0;
        foreach ($machines as $machine) {
            try {
                $newer += $updates->check($machine);
            } catch (Throwable $e) {
                report($e);
                $this->warn("{$machine->name}: {$e->getMessage()}");
            }
        }
        $this->line("checked {$machines->count()} machines, {$newer} containers with a newer image");

        try {
            $this->line('findings: '.$scanner->process());
            $this->line('asked for packages: '.$scanner->queue());
        } catch (Throwable $e) {
            report($e);
            $this->warn('scan failed: '.$e->getMessage());
        }

        return self::SUCCESS;
    }
}
