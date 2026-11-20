<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A coding task handed to one or more agents on one of the workspace's
 * machines. Each agent is a run of the task's own one-step workflow, so the
 * workflow engine clones, runs the agent and opens the pull request.
 *
 * @property int $id
 * @property int $workspace_id
 * @property int|null $user_id
 * @property int $workflow_id
 * @property int $machine_id
 * @property string $service
 * @property string $title
 * @property string $prompt
 * @property array{ref: string, kind: string, label: string, text: string}|null $context
 * @property string $agent
 * @property string|null $model
 * @property string|null $repo
 * @property string $branch
 * @property bool $pull_request
 * @property int $agents
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read User|null $user
 * @property-read Workflow $workflow
 * @property-read Machine $machine
 */
class AgentTask extends Model
{
    /** At once, per message: more than a machine fits wait in line for room. */
    public const MAX_AGENTS = 20;

    protected $guarded = ['id', 'workspace_id'];

    protected function casts(): array
    {
        return [
            'pull_request' => 'boolean',
            'agents' => 'integer',
            'context' => 'array',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<Workflow, $this> */
    public function workflow(): BelongsTo
    {
        return $this->belongsTo(Workflow::class);
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return HasMany<AgentQueued, $this> */
    public function queued(): HasMany
    {
        return $this->hasMany(AgentQueued::class)->orderBy('id');
    }

    /** @return HasMany<WorkflowRun, $this> */
    public function runs(): HasMany
    {
        return $this->hasMany(WorkflowRun::class, 'workflow_id', 'workflow_id');
    }
}
