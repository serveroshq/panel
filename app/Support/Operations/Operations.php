<?php

namespace App\Support\Operations;

use App\Api\ApiRunner;
use App\Enums\Limit;
use App\Http\Controllers\MaintenanceController;
use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\ToolError;
use App\Mcp\ToolRegistry;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\McpToken;
use App\Models\Monitor;
use App\Models\Operation;
use App\Models\OperationMachine;
use App\Support\Deploys\AppReleases;
use App\Support\Deploys\AppVersions;
use App\Support\Machines\MachineSelector;
use App\Support\Webhooks\Webhooks;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Runs one piece of work across many machines, in batches, through the same
 * single-machine tools the API and MCP use, so roles, scopes, plan checks and
 * daemon version checks stay in one place. Built on what package rollouts do:
 * machines are frozen when it starts, a batch waits for every machine in it,
 * offline machines are skipped, and too many failures pause it for a person.
 */
final class Operations
{
    public const int MAX_SECONDS = 3600;

    public const array SERVICE_ACTIONS = ['start', 'stop', 'restart', 'reload'];

    public const array MAINTENANCE_ACTIONS = ['start', 'end'];

    public function __construct(private readonly ApiRunner $runner, private readonly ToolRegistry $tools) {}

    /**
     * Checks the request and picks its machines, without starting anything.
     *
     * @param  array<string, mixed>  $input
     * @return array{kind: string, params: array<string, mixed>, machines: Collection<int, Machine>, batch: ?string, batch_size: int, stop_on_failure: bool, max_failures: int, wait_healthy_seconds: int, pause_between_seconds: int}
     */
    public function plan(McpContext $context, array $input): array
    {
        $kind = $input['kind'] ?? null;
        if (! is_string($kind) || ! in_array($kind, Operation::KINDS, true)) {
            throw new ToolError('Invalid arguments: kind is one of '.implode(', ', Operation::KINDS).'.');
        }
        $params = $this->params($kind, $input[$kind] ?? null);

        // The person (or key) needs to be allowed to run the single-machine tools themselves.
        foreach (self::toolsFor($kind, $params) as $name) {
            $tool = $this->tools->find($name);
            if ($tool !== null && ($denied = $this->tools->denial($tool, $context)) !== null) {
                throw new ToolError($denied);
            }
        }
        if ($kind === 'command' && ! $context->user->hasEnabledTwoFactorAuthentication()) {
            throw new ToolError('Running commands needs two-factor authentication on your account, as the console does: turn it on under Settings › Security.');
        }
        if ($kind === 'rollback' && ($params['confirm'] ?? null) !== $params['app']) {
            throw new ToolError("This rolls {$params['app']} back on every machine picked. Ask the person first, then pass rollback.confirm set to “{$params['app']}” exactly.");
        }

        $strategy = Validator::make(is_array($input['strategy'] ?? null) ? $input['strategy'] : [], [
            'batch_size' => ['sometimes', 'nullable', function (string $attribute, mixed $value, \Closure $fail) {
                $ok = (is_int($value) && $value >= 1 && $value <= 1000)
                    || (is_string($value) && preg_match('/^(100|[1-9]\d?)%$/', $value) === 1)
                    || (is_string($value) && ctype_digit($value) && (int) $value >= 1 && (int) $value <= 1000);
                if (! $ok) {
                    $fail('batch_size is a number of machines (1 to 1000) or a percentage, like "25%".');
                }
            }],
            'stop_on_failure' => ['sometimes', 'boolean'],
            'max_failures' => ['sometimes', 'integer', 'min:0', 'max:1000'],
            'wait_healthy_seconds' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_SECONDS],
            'pause_between_seconds' => ['sometimes', 'integer', 'min:0', 'max:'.self::MAX_SECONDS],
        ]);
        if ($strategy->fails()) {
            throw new ToolError('Invalid arguments: '.implode(' ', Arr::flatten($strategy->errors()->toArray())));
        }
        $strategy = $strategy->validated();

        $machines = MachineSelector::pick($context, $input);
        $most = $context->workspace->limitOf(Limit::OperationMachines);
        if ($most !== null && $machines->count() > $most) {
            throw new ToolError("That’s {$machines->count()} machines; one operation in {$context->workspace->name} can touch up to {$most}. Split it into smaller ones.");
        }

        $batch = isset($strategy['batch_size']) ? (string) $strategy['batch_size'] : null;
        $size = match (true) {
            $batch === null => $machines->count(),
            str_ends_with($batch, '%') => (int) max(1, ceil($machines->count() * (int) $batch / 100)),
            default => min((int) $batch, $machines->count()),
        };

        return [
            'kind' => $kind,
            'params' => $params,
            'machines' => $machines,
            'batch' => $batch,
            'batch_size' => max(1, $size),
            'stop_on_failure' => (bool) ($strategy['stop_on_failure'] ?? true),
            'max_failures' => (int) ($strategy['max_failures'] ?? 0),
            'wait_healthy_seconds' => (int) ($strategy['wait_healthy_seconds'] ?? 0),
            'pause_between_seconds' => (int) ($strategy['pause_between_seconds'] ?? 0),
        ];
    }

    /**
     * @param  array{kind: string, params: array<string, mixed>, machines: Collection<int, Machine>, batch: ?string, batch_size: int, stop_on_failure: bool, max_failures: int, wait_healthy_seconds: int, pause_between_seconds: int}  $plan
     * @param  array<string, mixed>|null  $selector
     */
    public function create(McpContext $context, array $plan, ?array $selector): Operation
    {
        $workspace = $context->workspace;
        $most = $workspace->limitOf(Limit::OperationsRunning);
        if ($most !== null && Operation::query()->where('workspace_id', $workspace->id)->whereIn('state', Operation::OPEN)->count() >= $most) {
            throw new ToolError("{$workspace->name} already has {$most} operations running or paused, which is as many as it can have at once. Wait for one to finish, or cancel one.");
        }

        $operation = DB::transaction(function () use ($context, $plan, $selector) {
            $operation = Operation::query()->create([
                'workspace_id' => $context->workspace->id,
                'created_by' => $context->user->id,
                'mcp_token_id' => $context->token?->id,
                'via' => $context->via,
                'scopes' => $context->token === null ? $context->scopes() : null,
                'kind' => $plan['kind'],
                'params' => $plan['params'],
                'selector' => $selector,
                'batch' => $plan['batch'],
                'batch_size' => $plan['batch_size'],
                'stop_on_failure' => $plan['stop_on_failure'],
                'max_failures' => $plan['max_failures'],
                'wait_healthy_seconds' => $plan['wait_healthy_seconds'],
                'pause_between_seconds' => $plan['pause_between_seconds'],
                'state' => 'running',
                'started_at' => now(),
            ]);
            foreach ($plan['machines']->values() as $position => $machine) {
                OperationMachine::query()->create([
                    'operation_id' => $operation->id,
                    'machine_id' => $machine->id,
                    'machine_name' => $machine->name,
                    'position' => $position,
                    'state' => 'pending',
                ]);
            }

            return $operation;
        });

        self::event($operation, 'operation.started');
        $this->advance($operation);

        // Recorded after the first batch: each machine's tool call starts its own audit record.
        $count = $plan['machines']->count();
        $context->record('operation.start', self::subject($operation), null, array_filter([
            'operation' => $operation->id,
            'kind' => $operation->kind,
            'action' => $operation->params['action'] ?? null,
            'ref' => $operation->params['ref'] ?? null,
            'machines' => $count,
            'batch' => $operation->batch_size < $count ? $operation->batch_size : null,
        ], fn ($v) => $v !== null));

        return $operation->fresh() ?? $operation;
    }

    public function tick(): int
    {
        $count = 0;
        Operation::query()->where('state', 'running')->orderBy('id')->each(function (Operation $operation) use (&$count) {
            $this->advance($operation);
            $count++;
        });

        // A cancelled or paused operation lets the jobs it started finish, and records how they went.
        Operation::query()->whereIn('state', ['cancelled', 'paused'])
            ->whereHas('targets', fn ($q) => $q->whereIn('state', ['running', 'checking']))
            ->each(function (Operation $operation) {
                $context = $this->context($operation);
                foreach ($operation->targets()->whereIn('state', ['running', 'checking'])->with('machine')->get() as $target) {
                    $this->progress($target, $operation, $context);
                }
            });

        return $count;
    }

    public function advance(Operation $operation): void
    {
        // The scheduler and a request (create, resume) can get here at once; only one may start machines.
        $lock = Cache::lock("operations:{$operation->id}", 300);
        if (! $lock->get()) {
            return;
        }
        try {
            $operation->refresh();
            $this->step($operation);
        } finally {
            $lock->release();
        }
    }

    private function step(Operation $operation): void
    {
        if ($operation->state !== 'running') {
            return;
        }
        $context = $this->context($operation);
        $targets = $operation->targets()->with('machine')->get();

        foreach ($targets->whereIn('state', ['running', 'checking']) as $target) {
            $this->progress($target, $operation, $context);
        }
        if ($targets->whereIn('state', ['running', 'checking'])->isNotEmpty()) {
            return;
        }

        while (true) {
            $failed = $targets->where('state', 'failed');
            if ($operation->stop_on_failure && $failed->count() - $operation->failures_before > $operation->max_failures) {
                $operation->forceFill([
                    'state' => 'paused',
                    'paused_reason' => 'Paused after '.self::names(array_values($failed->skip($operation->failures_before)->map(fn (OperationMachine $t) => $t->machine_name)->all())).' failed. Resume to carry on, or cancel.',
                ])->save();
                self::event($operation, 'operation.paused');

                return;
            }

            $pending = $targets->where('state', 'pending')->values();
            if ($pending->isEmpty()) {
                $operation->forceFill(['state' => 'finished', 'finished_at' => now()])->save();
                self::event($operation, 'operation.finished');

                return;
            }

            $last = $targets->whereIn('state', OperationMachine::SETTLED)->max('finished_at');
            if ($operation->pause_between_seconds > 0 && $last !== null && $last->addSeconds($operation->pause_between_seconds)->isFuture()) {
                return;
            }

            $busy = false;
            foreach ($pending->take($operation->batch_size) as $target) {
                $busy = in_array($this->start($target, $operation, $context), ['running', 'checking'], true) || $busy;
            }
            if ($busy) {
                return;
            }
        }
    }

    public function pause(Operation $operation, McpContext $context): void
    {
        if ($operation->state !== 'running') {
            throw new ToolError("Operation #{$operation->id} is {$operation->state}, so it can’t be paused.");
        }
        $operation->forceFill(['state' => 'paused', 'paused_reason' => 'Paused by '.$context->user->name.'.'])->save();
        self::event($operation, 'operation.paused');
        $context->record('operation.pause', null, null, ['operation' => $operation->id, 'kind' => $operation->kind]);
    }

    public function resume(Operation $operation, McpContext $context): void
    {
        if ($operation->state !== 'paused') {
            throw new ToolError("Operation #{$operation->id} is {$operation->state}, so there’s nothing to resume.");
        }
        $operation->forceFill([
            'state' => 'running',
            'paused_reason' => null,
            // Failures so far were looked at; only new ones count towards max_failures.
            'failures_before' => $operation->targets()->where('state', 'failed')->count(),
        ])->save();
        $this->advance($operation);
        $context->record('operation.resume', null, null, ['operation' => $operation->id, 'kind' => $operation->kind]);
    }

    public function cancel(Operation $operation, McpContext $context): void
    {
        if (! in_array($operation->state, Operation::OPEN, true)) {
            throw new ToolError("Operation #{$operation->id} is {$operation->state} already.");
        }
        DB::transaction(function () use ($operation) {
            $operation->forceFill(['state' => 'cancelled', 'finished_at' => now()])->save();
            $operation->targets()->where('state', 'pending')->update(['state' => 'skipped', 'outcome' => 'Cancelled before its turn.', 'finished_at' => now()]);
        });
        self::event($operation, 'operation.finished');
        $context->record('operation.cancel', null, null, ['operation' => $operation->id, 'kind' => $operation->kind]);
    }

    /** @return array<string, mixed> */
    public static function view(Operation $operation, bool $machines = true): array
    {
        $targets = $operation->targets()->get();
        $counts = $targets->countBy('state');

        return [
            'id' => $operation->id,
            'kind' => $operation->kind,
            $operation->kind => (object) $operation->params,
            'state' => $operation->state,
            'pausedReason' => $operation->paused_reason,
            'selector' => $operation->selector === null ? null : (object) $operation->selector,
            'strategy' => [
                'batchSize' => $operation->batch,
                'machinesPerBatch' => $operation->batch_size,
                'stopOnFailure' => $operation->stop_on_failure,
                'maxFailures' => $operation->max_failures,
                'waitHealthySeconds' => $operation->wait_healthy_seconds,
                'pauseBetweenSeconds' => $operation->pause_between_seconds,
            ],
            'progress' => [
                'total' => $targets->count(),
                ...array_map(fn (string $state) => (int) ($counts[$state] ?? 0), array_combine(OperationMachine::STATES, OperationMachine::STATES)),
            ],
            'startedBy' => $operation->creator?->name,
            'via' => $operation->via,
            'createdAt' => $operation->created_at?->toIso8601String(),
            'finishedAt' => $operation->finished_at?->toIso8601String(),
            ...($machines ? ['machines' => $targets->map(fn (OperationMachine $t) => [
                'machine' => ['id' => $t->machine_id, 'name' => $t->machine_name],
                'batch' => intdiv($t->position, max(1, $operation->batch_size)) + 1,
                'state' => $t->state,
                'jobId' => $t->job_id,
                'outcome' => $t->outcome,
                'startedAt' => $t->started_at?->toIso8601String(),
                'finishedAt' => $t->finished_at?->toIso8601String(),
            ])->all()] : []),
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     */
    public static function tool(string $kind, array $params): string
    {
        return self::toolsFor($kind, $params)[0];
    }

    /**
     * The single-machine tools a kind runs on each machine, in order.
     *
     * @param  array<string, mixed>  $params
     * @return non-empty-list<string>
     */
    public static function toolsFor(string $kind, array $params): array
    {
        return match ($kind) {
            'deploy' => [isset($params['template']) ? 'deploy_template' : 'deploy_from_git'],
            'deploy_version' => ['deploy_new_version'],
            'rollback' => ['rollback_app'],
            'service_action' => [$params['action'].'_service'],
            'command' => ['run_command'],
            'maintenance' => [$params['action'].'_maintenance'],
            'update_machines' => match (true) {
                isset($params['group']) && array_diff(array_keys($params), ['group']) !== [] => ['update_machine', 'update_machine_group'],
                isset($params['group']) => ['update_machine_group'],
                default => ['update_machine'],
            },
            default => throw new ToolError('Invalid arguments: kind is one of '.implode(', ', Operation::KINDS).'.'),
        };
    }

    /** @return array<string, mixed> */
    private function params(string $kind, mixed $given): array
    {
        if (! is_array($given) || ($given !== [] && array_is_list($given))) {
            throw new ToolError("Invalid arguments: say what to do under {$kind}, like ".match ($kind) {
                'deploy' => '{"deploy": {"template": "redis", "name": "cache"}}',
                'deploy_version' => '{"deploy_version": {"app": "shop", "ref": "v2.4.1"}}',
                'rollback' => '{"rollback": {"app": "shop", "confirm": "shop"}}',
                'service_action' => '{"service_action": {"service": "shop", "action": "restart"}}',
                'command' => '{"command": {"service": "shop", "command": "php artisan migrate --force"}}',
                'update_machines' => '{"update_machines": {"labels": {"env": "prod"}, "tier": "web"}}',
                default => '{"maintenance": {"action": "start", "minutes": 60}}',
            }.'.');
        }
        $rules = match ($kind) {
            'deploy' => [
                'template' => ['required_without:repo', 'nullable', 'string', 'max:120'],
                'repo' => ['required_without:template', 'nullable', 'string', 'max:500'],
                'name' => ['required', 'string', 'max:63', 'regex:/^[a-z][a-z0-9-]*$/'],
                'ref' => ['sometimes', 'nullable', 'string', 'max:255'],
                'port' => ['required_without:template', 'nullable', 'integer', 'min:1', 'max:65535'],
                'health_path' => ['sometimes', 'nullable', 'string', 'max:255'],
                'env' => ['sometimes', 'nullable', 'array'],
                'replace' => ['sometimes', 'boolean'],
            ],
            'rollback' => [
                'app' => ['required', 'string', 'max:120'],
                'commit' => ['sometimes', 'nullable', 'string', 'min:4', 'max:64'],
                'with_data' => ['sometimes', 'boolean'],
                'confirm' => ['sometimes', 'nullable', 'string', 'max:120'],
            ],
            'command' => [
                'service' => ['required', 'string', 'max:255'],
                'command' => ['required', 'string', 'max:2000'],
            ],
            'update_machines' => [
                'labels' => ['sometimes', 'array', 'max:20'],
                'tier' => ['sometimes', 'nullable', 'string', 'max:60'],
                'environment' => ['sometimes', 'in:production,staging,development'],
                'group' => ['sometimes', 'string', 'max:120'],
            ],
            'deploy_version' => [
                'app' => ['required', 'string', 'max:120'],
                'ref' => ['sometimes', 'nullable', 'string', 'max:255'],
                'repo' => ['sometimes', 'nullable', 'string', 'max:500'],
            ],
            'service_action' => [
                'service' => ['required', 'string', 'max:255'],
                'action' => ['required', 'in:'.implode(',', self::SERVICE_ACTIONS)],
            ],
            'maintenance' => [
                'action' => ['required', 'in:'.implode(',', self::MAINTENANCE_ACTIONS)],
                'minutes' => ['sometimes', 'integer', 'in:'.implode(',', MaintenanceController::DURATIONS)],
                'reason' => ['sometimes', 'nullable', 'string', 'max:200'],
                'public' => ['sometimes', 'boolean'],
            ],
            default => throw new ToolError('Invalid arguments: kind is one of '.implode(', ', Operation::KINDS).'.'),
        };
        $validator = Validator::make($given, $rules);
        if ($validator->fails()) {
            throw new ToolError("Invalid arguments: {$kind}: ".implode(' ', Arr::flatten($validator->errors()->toArray())));
        }
        if (($unknown = array_diff(array_keys($given), array_keys($rules))) !== []) {
            throw new ToolError("Invalid arguments: {$kind} doesn’t take ".implode(', ', $unknown).'. It takes '.implode(', ', array_keys($rules)).'.');
        }
        $params = array_filter($validator->validated(), fn ($v) => $v !== null);
        if ($kind === 'update_machines' && $params === []) {
            throw new ToolError('Invalid arguments: update_machines needs labels, tier, environment or group.');
        }

        return $params;
    }

    /** The context each machine's tool runs in: the person who started it, through the same key. */
    private function context(Operation $operation): ?McpContext
    {
        $user = $operation->creator;
        if ($user === null || $operation->workspace->roleOf($user) === null) {
            return null;
        }
        $token = null;
        if ($operation->mcp_token_id !== null) {
            $token = McpToken::query()->active()->find($operation->mcp_token_id);
            if ($token === null || ($token->expires_at !== null && $token->expires_at->isPast())) {
                return null;
            }
        }
        $context = new McpContext($operation->workspace, $user, $token, $operation->via, $operation->scopes);
        $context->operation = $operation->id;

        return $context;
    }

    private function start(OperationMachine $target, Operation $operation, ?McpContext $context): string
    {
        $machine = $target->machine;
        if ($context === null) {
            return $this->settle($target, 'skipped', 'Not started: whoever started the operation has left the workspace, or the key it was started with was revoked.');
        }
        if ($machine === null || $machine->workspace_id !== $operation->workspace_id) {
            return $this->settle($target, 'skipped', 'Not started: the machine was removed or moved to another workspace.');
        }
        if (Present::connection($machine) !== 'online') {
            return $this->settle($target, 'skipped', 'Not started: the machine was offline at its turn.');
        }

        $tool = $this->tools->find(self::tool($operation->kind, $operation->params));
        if ($tool === null) {
            return $this->settle($target, 'failed', 'Not started: this panel can’t do that any more.');
        }
        $params = $operation->params;
        $id = (string) $machine->id;
        if ($operation->kind === 'rollback' && ! isset($params['commit'])) {
            $params['commit'] = self::previousVersion($machine, (string) $params['app']);
            if ($params['commit'] === null) {
                return $this->settle($target, 'failed', "Not started: {$params['app']} has no earlier version kept here to go back to.");
            }
        }
        // Jobs are followed here, so never hold the call open waiting for one.
        $args = ['machine' => $id, ...match ($operation->kind) {
            'maintenance' => $params['action'] === 'end' ? [] : Arr::except($params, ['action']),
            'service_action' => ['service' => $params['service'], 'wait' => 0],
            'rollback' => [...Arr::except($params, ['confirm']), 'confirm' => $params['app'], 'wait' => 0],
            'update_machines' => Arr::except($params, ['group']),
            default => [...$params, 'wait' => 0],
        }];

        if ($tool->name() !== 'update_machine_group') {
            [$status, $body] = $this->runner->run($context, $tool, $args);
            if ($status >= 400) {
                return $this->settle($target, 'failed', (string) ($body['error']['message'] ?? "It answered {$status}."));
            }
        }
        if ($operation->kind === 'update_machines' && isset($params['group'])) {
            $group = $this->tools->find('update_machine_group');
            [$status, $groupBody] = $group === null ? [404, []] : $this->runner->run($context, $group, ['group' => $params['group'], 'add' => [$id]]);
            if ($status >= 400) {
                return $this->settle($target, 'failed', (string) ($groupBody['error']['message'] ?? "It answered {$status}."));
            }
            $body ??= $groupBody;
        }

        $data = is_array($body['data'] ?? null) ? $body['data'] : [];
        $job = $data['jobId'] ?? $data['job']['id'] ?? null;
        $target->forceFill(['started_at' => now()])->save();
        if (is_string($job)) {
            $target->forceFill(['state' => 'running', 'job_id' => $job])->save();

            return 'running';
        }

        return $this->done($target, $operation);
    }

    private function progress(OperationMachine $target, Operation $operation, ?McpContext $context): void
    {
        if ($target->state === 'running') {
            $job = $target->job_id === null ? null : DaemonJob::query()->find($target->job_id);
            match (true) {
                $job === null => $this->settle($target, 'failed', 'Its job went missing.'),
                $job->isStale() => $this->settle($target, 'failed', 'The machine stopped answering while it ran.'),
                in_array($job->state, ['failed', 'timed_out', 'refused', 'cancelled'], true) => $this->settle($target, 'failed', 'It didn’t finish: '.(self::error($job) ?: str_replace('_', ' ', $job->state)).'.'),
                $job->state === 'succeeded' && $operation->kind === 'command' && is_numeric($job->result['exit_code'] ?? null) && (int) $job->result['exit_code'] !== 0 => $this->settle($target, 'failed', 'The command exited with code '.(int) $job->result['exit_code'].'.'),
                $job->state === 'succeeded' => $this->done($target, $operation),
                default => null,
            };

            return;
        }

        if ($target->state !== 'checking') {
            return;
        }
        $machine = $target->machine?->fresh();
        $service = self::healthService($operation);
        if ($machine === null || $context === null || $service === null) {
            $this->settle($target, 'succeeded', 'Done.');

            return;
        }
        $status = self::healthStatus($operation, $machine, $context);
        $monitor = $target->healthy_at === null ? null : Monitor::query()->where('machine_id', $machine->id)->where('paused', false)->where('status', 'down')
            ->where('status_changed_at', '>=', $target->healthy_at->subSeconds($operation->wait_healthy_seconds))->value('name');
        if ($status !== 'running') {
            $this->settle($target, 'failed', "Done, but {$service} stopped running before {$operation->wait_healthy_seconds} seconds were up ".($status === null ? '(it’s gone).' : "(it’s {$status})."));
        } elseif ($monitor !== null) {
            $this->settle($target, 'failed', "Done, but the monitor {$monitor} on this machine went down before {$operation->wait_healthy_seconds} seconds were up.");
        } elseif ($target->healthy_at !== null && ! $target->healthy_at->isFuture()) {
            $this->settle($target, 'succeeded', "Done, and running for {$operation->wait_healthy_seconds} seconds.");
        }
    }

    /** The job is done: wait for it to stay healthy if asked, or call it a success. */
    private function done(OperationMachine $target, Operation $operation): string
    {
        if ($operation->wait_healthy_seconds > 0 && self::healthService($operation) !== null) {
            $target->forceFill(['state' => 'checking', 'healthy_at' => now()->addSeconds($operation->wait_healthy_seconds)])->save();

            return 'checking';
        }

        return $this->settle($target, 'succeeded', 'Done.');
    }

    /** The service or app that must stay running after this kind of work, if any. */
    private static function healthService(Operation $operation): ?string
    {
        return match (true) {
            $operation->kind === 'deploy_version', $operation->kind === 'rollback' => (string) $operation->params['app'],
            $operation->kind === 'deploy' => (string) $operation->params['name'],
            $operation->kind === 'service_action' && $operation->params['action'] !== 'stop' => (string) $operation->params['service'],
            default => null,
        };
    }

    /**
     * How it's doing now, from the machine's last scan: a service's status; an
     * app's live release; or, for a stack, running only if every container in it is.
     * Null when it's gone.
     */
    private static function healthStatus(Operation $operation, Machine $machine, McpContext $context): ?string
    {
        $name = (string) self::healthService($operation);
        if ($operation->kind === 'service_action') {
            try {
                return $context->service($machine, $name)['status'] ?? null;
            } catch (ToolError) {
                return null;
            }
        }
        if (($release = AppReleases::find($machine, $name)) !== null) {
            return is_string($release['status'] ?? null) ? $release['status'] : null;
        }
        $found = false;
        foreach ((array) ($machine->inventory['services'] ?? []) as $service) {
            if (! is_array($service) || ($service['details']['compose_project'] ?? null) !== "serveros-{$name}") {
                continue;
            }
            $found = true;
            if (($service['status'] ?? null) !== 'running') {
                return is_string($service['status'] ?? null) ? $service['status'] : 'unknown';
            }
        }

        return $found ? 'running' : null;
    }

    private function settle(OperationMachine $target, string $state, string $outcome): string
    {
        $target->forceFill(['state' => $state, 'outcome' => mb_substr($outcome, 0, 1000), 'finished_at' => now()])->save();

        return $state;
    }

    private static function subject(Operation $operation): ?string
    {
        return match ($operation->kind) {
            'deploy_version', 'rollback' => (string) $operation->params['app'],
            'deploy' => (string) $operation->params['name'],
            'service_action', 'command' => (string) $operation->params['service'],
            default => null,
        };
    }

    /** The version kept just before the live one, for a rollback without a commit. */
    private static function previousVersion(Machine $machine, string $app): ?string
    {
        $versions = AppVersions::for($machine, $app);
        $live = array_search(true, array_column($versions, 'live'), true);

        return $live === false ? null : ($versions[$live + 1]['commit'] ?? null);
    }

    private static function error(DaemonJob $job): string
    {
        return is_array($job->error) ? rtrim(trim((string) ($job->error['message'] ?? '')), '.') : '';
    }

    private static function event(Operation $operation, string $event): void
    {
        $counts = $operation->targets()->get()->countBy('state');
        Webhooks::dispatch($operation->workspace_id, $event, [
            'operation_id' => $operation->id,
            'kind' => $operation->kind,
            'state' => $operation->state,
            'progress' => ['total' => $counts->sum(), ...array_map(fn (string $state) => (int) ($counts[$state] ?? 0), array_combine(OperationMachine::STATES, OperationMachine::STATES))],
        ]);
    }

    /** @param  list<string>  $names */
    private static function names(array $names): string
    {
        return count($names) <= 3 ? implode(', ', $names) : implode(', ', array_slice($names, 0, 3)).' and '.(count($names) - 3).' more';
    }
}
