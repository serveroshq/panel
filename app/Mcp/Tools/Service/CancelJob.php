<?php

namespace App\Mcp\Tools\Service;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\DaemonJob;
use App\Support\DaemonJobQueue;

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
        return 'Stops a queued or running job, like “Stop waiting” on the machine page: ServerOS marks it cancelled and asks the machine to cancel it too (a deploy that hasn’t switched over yet stops there, and the live version keeps serving). If the machine finishes it anyway, how it went is still recorded. Use it for stuck or unwanted jobs, not to undo something that finished; a cancelled deploy can then be retried with retry_job.';
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
        app(DaemonJobQueue::class)->stopWaiting($job);
        $context->record('job.abandon', $job->service ?? $job->machine->name, $job->machine, ['kind' => $job->kind]);

        return [
            'job' => ServiceView::job($context, $job, 10),
            'wasStale' => $wasStale,
            'note' => ($job->error['message'] ?? null) === DaemonJob::STOPPED ? 'ServerOS stopped waiting on it and asked the machine to cancel it.' : 'ServerOS stopped waiting on it. The machine couldn’t be reached to cancel it.',
        ];
    }
}
