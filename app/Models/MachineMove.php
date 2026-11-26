<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * @property int $id
 * @property int $workspace_id
 * @property int $from_machine_id
 * @property int $to_machine_id
 * @property int|null $user_id
 * @property string $state
 * @property int $round
 * @property string|null $error
 * @property string|null $private_key
 * @property string|null $public_key
 * @property string|null $host_key
 * @property string|null $receiver_state
 * @property string|null $receiver_job_id
 * @property list<array{key: string, job: string}>|null $stopped
 * @property list<array{rule: string, state: string, job: string|null, error: string|null}>|null $firewall
 * @property array{state: string, job: string|null}|null $tunnel
 * @property CarbonImmutable|null $switched_at
 * @property CarbonImmutable|null $finished_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Workspace $workspace
 * @property-read Machine $from
 * @property-read Machine $to
 * @property-read User|null $user
 * @property-read Collection<int, MachineMoveApp> $apps
 * @property-read Collection<int, MachineMoveCopy> $copies
 */
#[Fillable(['workspace_id', 'from_machine_id', 'to_machine_id', 'user_id', 'state'])]
#[Hidden(['private_key'])]
class MachineMove extends Model
{
    /** States a machine can't start another move from. */
    public const array ACTIVE = ['copying', 'ready', 'switching', 'switched', 'failed'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'private_key' => 'encrypted',
            'stopped' => 'array',
            'firewall' => 'array',
            'tunnel' => 'array',
            'switched_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Workspace, $this> */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /** @return BelongsTo<Machine, $this> */
    public function from(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'from_machine_id');
    }

    /** @return BelongsTo<Machine, $this> */
    public function to(): BelongsTo
    {
        return $this->belongsTo(Machine::class, 'to_machine_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return HasMany<MachineMoveApp, $this> */
    public function apps(): HasMany
    {
        return $this->hasMany(MachineMoveApp::class)->orderBy('name');
    }

    /** @return HasManyThrough<MachineMoveCopy, MachineMoveApp, $this> */
    public function copies(): HasManyThrough
    {
        return $this->hasManyThrough(MachineMoveCopy::class, MachineMoveApp::class);
    }

    public function isActive(): bool
    {
        return in_array($this->state, self::ACTIVE, true);
    }
}
