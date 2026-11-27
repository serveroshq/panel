<?php

namespace App\Jobs;

use App\Models\IntegrationOperation;
use App\Support\Integrations\Servers\ServerOperations;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

class AdvanceIntegrationOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $operationId) {}

    public function handle(ServerOperations $operations): void
    {
        $operation = IntegrationOperation::query()->find($this->operationId);
        if ($operation === null || ! $operation->active()) {
            return;
        }

        try {
            $wait = $operations->advance($operation);
        } catch (Throwable $e) {
            report($e);
            $operations->giveUp($operation);

            return;
        }

        if ($wait !== null) {
            self::dispatch($operation->id)->delay(now()->addSeconds($wait));
        }
    }
}
