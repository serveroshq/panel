<?php

namespace App\Console\Commands;

use App\Models\DaemonJob;
use App\Support\Deploys\DeployOnPush;
use Illuminate\Console\Command;

class ReleaseWaitingDeploys extends Command
{
    protected $signature = 'deploys:release-waiting';

    protected $description = 'Send push deploys that waited for an earlier deploy, once it has finished or gone quiet';

    public function handle(DeployOnPush $pushes): int
    {
        $sent = 0;

        DaemonJob::query()
            ->where('held', true)
            ->where('state', 'queued')
            ->with('machine')
            ->get()
            ->unique(fn (DaemonJob $job) => $job->machine_id.'|'.$job->service)
            ->each(function (DaemonJob $job) use ($pushes, &$sent) {
                if (is_string($job->service) && $pushes->release($job->machine, $job->service) !== null) {
                    $sent++;
                }
            });

        $this->info("Sent {$sent} waiting deploy(s).");

        return self::SUCCESS;
    }
}
