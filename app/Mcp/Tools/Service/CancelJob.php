<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\DaemonJob;
use App\Support\Deploys\DeployOnPush;

final class CancelJob extends Tool
{
    public function name(): string
    {
        return 'cancel_job';
    }

    public function title(): string
    {
        return 'Stop waiting on a job';
    }

    public function description(): string
    {
        return 'Marks a queued or running job as cancelled in ServerOS, for a job the machine never reported back on (stale in get_job), like “Stop waiting” on the machine page. It does not reach the machine: work already under way there is not stopped. Use it for stuck jobs, not to undo something; a cancelled deploy can then be retried with retry_job.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'job' => Schema::string('The job’s id.', maxLength: 64),
        ], ['job']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['job' => ['required', 'string', 'max:64']]);
        $job = ServiceWork::job($context, $data['job']);

        if (! $job->isActive()) {
            throw new ToolError('This job has already finished.');
        }
        $wasStale = $job->isStale();
        self::stopWaiting($job);
        $context->record('job.abandon', $job->service ?? $job->machine->name, $job->machine, ['kind' => $job->kind]);

        return [
            'job' => ServiceView::job($context, $job, 10),
            'wasStale' => $wasStale,
            'note' => 'ServerOS stopped waiting on it. Nothing was sent to the machine.',
        ];
    }

    public static function stopWaiting(DaemonJob $job): void
    {
        $held = $job->held;
        $job->forceFill([
            'state' => 'cancelled',
            'held' => false,
            'error' => ['phase' => 'panel', 'message' => $held ? 'Cancelled before it was sent to the machine.' : 'Stopped waiting: the daemon never reported back on this job.'],
            'finished_at' => now(),
        ])->save();
        if (! $held && in_array($job->kind, ['deploy', 'rollback'], true) && is_string($job->service)) {
            app(DeployOnPush::class)->release($job->machine, $job->service);
        }
    }
}
