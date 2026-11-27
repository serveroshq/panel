<?php

namespace App\Support\Integrations\Servers;

/**
 * How a provider's servers behave, where providers differ: whether a resize
 * needs the server off, what happens to the disk, whether it can go smaller,
 * whether a restart is gentle, and whether snapshots belong to one server
 * or to the whole account.
 */
final readonly class ServerRules
{
    /** The disk can grow with the new type, or stay as it is so the server can move to a smaller type later. */
    public const string DISK_OPTIONAL = 'optional';

    /** The disk always moves to the new type's size. */
    public const string DISK_ALWAYS = 'always';

    public function __construct(
        public bool $resizeNeedsPowerOff,
        public string $resizeDisk = self::DISK_OPTIONAL,
        public bool $resizeDown = true,
        public bool $gracefulRestart = true,
        public bool $snapshotsPerServer = true,
    ) {}

    /** @return array{powerOff: bool, disk: string, down: bool, gracefulRestart: bool, snapshotsPerServer: bool} */
    public function toArray(): array
    {
        return [
            'powerOff' => $this->resizeNeedsPowerOff,
            'disk' => $this->resizeDisk,
            'down' => $this->resizeDown,
            'gracefulRestart' => $this->gracefulRestart,
            'snapshotsPerServer' => $this->snapshotsPerServer,
        ];
    }
}
