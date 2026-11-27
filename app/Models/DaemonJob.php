<?php

namespace App\Models;

use App\Models\Concerns\CreditsWhoStartedIt;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property int $machine_id
 * @property int|null $user_id
 * @property int|null $impersonation_session_id
 * @property string $kind
 * @property array<string, mixed>|null $command
 * @property string|null $service
 * @property string $state
 * @property string|null $phase
 * @property int|null $progress
 * @property array<int, string>|null $log
 * @property array<string, mixed>|null $result
 * @property array<string, mixed>|null $error
 * @property CarbonImmutable|null $finished_at
 * @property int|null $workflow_run_id
 * @property string|null $mcp_call_id
 * @property array{kind: string, job: array<string, mixed>, service: string|null}|null $then
 * @property string|null $workflow_node
 * @property int|null $agent_fix_id
 * @property string|null $trigger
 * @property bool $held
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Machine $machine
 * @property-read User|null $user
 */
class DaemonJob extends Model
{
    use CreditsWhoStartedIt;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $dateFormat = 'Y-m-d H:i:s.u';

    protected $guarded = [];

    // Holds deploy secrets: never send it to the browser.
    protected $hidden = ['command'];

    public const STALE_AFTER_MINUTES = 10;

    // Kinds that build or switch an app: quiet for a long while is normal
    // there, so they're only stale once their own time is up.
    public const array LONG_KINDS = ['deploy', 'rollback'];

    public const string STOPPED_WAITING = 'Stopped waiting: the daemon never reported back on this job.';

    public const string STOPPED = 'Stopped: ServerOS asked the machine to cancel it.';

    protected function casts(): array
    {
        return [
            'command' => 'encrypted:array',
            'log' => 'array',
            'result' => 'array',
            'error' => 'array',
            'then' => 'array',
            'finished_at' => 'datetime',
            'held' => 'boolean',
        ];
    }

    public function isActive(): bool
    {
        return in_array($this->state, ['queued', 'running'], true);
    }

    // A held job is waiting in the panel on purpose, so it's never stale. A
    // deploy can go quiet for a long build step, so it's stale only once no
    // word has come for longer than its whole timeout.
    public function isStale(): bool
    {
        if (! $this->isActive() || $this->held || $this->updated_at === null) {
            return false;
        }
        $quiet = in_array($this->kind, self::LONG_KINDS, true)
            ? max(self::STALE_AFTER_MINUTES * 60, $this->timeoutSecs() + 120)
            : self::STALE_AFTER_MINUTES * 60;

        return $this->updated_at->lt(now()->subSeconds($quiet));
    }

    public function timeoutSecs(): int
    {
        $timeout = $this->command['timeout_secs'] ?? null;

        return is_int($timeout) && $timeout > 0 ? $timeout : 1800;
    }

    /**
     * Whether someone stopped waiting on it in the panel while the machine
     * may still have been working: its last word then is still recorded.
     */
    public function stoppedWaiting(): bool
    {
        $error = is_array($this->error) ? $this->error : [];

        return $this->state === 'cancelled'
            && ($error['phase'] ?? null) === 'panel'
            && (($error['stopped'] ?? false) === true || in_array($error['message'] ?? null, [self::STOPPED_WAITING, self::STOPPED], true));
    }

    public function isRetryable(): bool
    {
        return $this->kind === 'deploy' && $this->command !== null;
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
