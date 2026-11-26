<?php

namespace App\Support\Backups;

use App\Models\BackupDestination;
use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use RuntimeException;

/**
 * Machines that take other machines' backups over SFTP. Each destination on
 * a machine has its own key, and the machine is always told the full list,
 * so a key the panel no longer has (a removed destination, or one rotated
 * after a machine moved) stops working there too.
 */
class BackupReceivers
{
    public function __construct(
        private readonly ReceiverKey $keys,
        private readonly DaemonJobQueue $queue,
    ) {}

    /** @return list<string> the keys every destination on this machine uses */
    public static function keysFor(Machine $machine): array
    {
        $keys = BackupDestination::query()
            ->where('type', 'machine')
            ->where('machine_id', $machine->id)
            ->whereNotNull('public_key')
            ->orderBy('id')
            ->pluck('public_key')
            ->all();

        return array_values(array_unique(array_filter($keys, 'is_string')));
    }

    /**
     * Gives the destination a new key and sets its machine up to take it,
     * dropping the old one. Daemons from before key lists only add the key.
     */
    public function setUp(BackupDestination $destination, User $by): void
    {
        $pair = $this->keys->generate();
        $destination->forceFill([
            'private_key' => $pair['private'],
            'public_key' => $pair['public'],
            'host_key' => null,
            'status' => 'setting_up',
            'status_message' => null,
        ])->save();

        $machine = $destination->machine;
        if ($machine === null) {
            return;
        }

        try {
            $this->queue->queue($machine, $by, 'backup_receiver', [
                'type' => 'backup_receiver',
                'public_key' => $pair['public'],
                'public_keys' => self::keysFor($machine),
            ], null, 120);
        } catch (RuntimeException $e) {
            $destination->forceFill(['status' => 'failed', 'status_message' => $e->getMessage()])->save();
        }
    }

    /**
     * Tells the machine which keys may still send to it, after a destination
     * on it was removed. A machine that's offline keeps the old key until its
     * next set up.
     */
    public function sync(Machine $machine, User $by): void
    {
        try {
            $this->queue->queue($machine, $by, 'backup_receiver', [
                'type' => 'backup_receiver',
                'public_key' => '',
                'public_keys' => self::keysFor($machine),
            ], null, 120);
        } catch (RuntimeException) {
            // Offline or not allowed to take work: nothing more to do here.
        }
    }
}
