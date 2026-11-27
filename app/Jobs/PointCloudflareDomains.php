<?php

namespace App\Jobs;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\Integrations\Cloudflare\CloudflareDns;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Keeps an app's Cloudflare records in step with its deploys: points new
 * domains as a deploy is queued, and follows up once a deploy or removal
 * has finished on the machine.
 */
class PointCloudflareDomains implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    /** @param  list<string>  $domains */
    public function __construct(public int $machineId, public string $service, public array $domains = [], public ?string $finishedJobId = null) {}

    public static function afterJob(DaemonJob $job): void
    {
        if (in_array($job->kind, ['deploy', 'service_remove'], true) && is_string($job->service) && app(CloudflareDns::class)->connected($job->machine->workspace_id)) {
            self::dispatch($job->machine_id, $job->service, [], $job->id);
        }
    }

    public function handle(CloudflareDns $dns): void
    {
        if ($this->finishedJobId !== null) {
            $job = DaemonJob::query()->find($this->finishedJobId);
            if ($job !== null) {
                $dns->jobFinished($job);
            }

            return;
        }

        $machine = Machine::query()->find($this->machineId);
        if ($machine !== null && $dns->connected($machine->workspace_id)) {
            $dns->point($machine, $this->service, $this->domains);
        }
    }
}
