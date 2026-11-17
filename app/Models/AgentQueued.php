<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An agent waiting to start: its machine is full, or it was asked to run
 * tonight while the machine is quiet.
 *
 * @property int $id
 * @property int $agent_task_id
 * @property int|null $user_id
 * @property string $turn
 * @property string|null $notes
 * @property int|null $after_run_id
 * @property CarbonImmutable|null $not_before
 * @property bool $quiet
 * @property CarbonImmutable|null $created_at
 * @property-read AgentTask $task
 * @property-read User|null $user
 */
class AgentQueued extends Model
{
    protected $table = 'agent_task_queue';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'not_before' => 'datetime',
            'quiet' => 'boolean',
        ];
    }

    /** @return BelongsTo<AgentTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(AgentTask::class, 'agent_task_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
