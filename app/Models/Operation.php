<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One piece of work across many machines, started from the API or MCP:
 * which machines (frozen when it was created), what to run on each, and
 * how to roll it out. App\Support\Operations\Operations moves it along.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $created_by
 * @property int|null $mcp_token_id
 * @property string $via
 * @property list<string>|null $scopes
 * @property string $kind
 * @property array<string, mixed> $params
 * @property array<string, mixed>|null $selector
 * @property string|null $batch
 * @property int $batch_size
 * @property bool $stop_on_failure
 * @property int $max_failures
 * @property int $failures_before
 * @property int $wait_healthy_seconds
 * @property int $pause_between_seconds
 * @property string $state
 * @property string|null $paused_reason
 * @property CarbonImmutable|null $started_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $creator
 * @property-read McpToken|null $token
 */
class Operation extends Model
{
    public const array KINDS = ['deploy', 'deploy_version', 'rollback', 'service_action', 'command', 'maintenance', 'update_machines'];

    public const array OPEN = ['running', 'paused'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'scopes' => 'array',
            'params' => 'array',
            'selector' => 'array',
            'stop_on_failure' => 'boolean',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** @return BelongsTo<McpToken, $this> */
    public function token(): BelongsTo
    {
        return $this->belongsTo(McpToken::class, 'mcp_token_id');
    }

    /** @return HasMany<OperationMachine, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(OperationMachine::class)->orderBy('position');
    }
}
