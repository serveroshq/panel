<?php

namespace App\Support\Security;

use App\Models\Machine;

final readonly class SecurityFacts
{
    public function __construct(
        public ?bool $passwordAuthentication = null,
        public ?string $permitRootLogin = null,
        public bool $keyboardInteractive = false,
        public bool $usePam = false,
        public ?int $failedSignIns = null,
        public int $failedSources = 0,
        public int $failedAsRoot = 0,
        public int $windowMinutes = 60,
        public ?bool $autoUpdates = null,
    ) {}

    public static function of(Machine $machine): self
    {
        $security = $machine->facts['security'] ?? null;
        if (! is_array($security)) {
            return new self;
        }
        $ssh = is_array($security['ssh'] ?? null) ? $security['ssh'] : [];
        $logins = is_array($security['logins'] ?? null) ? $security['logins'] : [];
        $root = $ssh['permit_root_login'] ?? null;

        return new self(
            passwordAuthentication: self::bool($ssh['password_authentication'] ?? null),
            permitRootLogin: is_string($root) && $root !== '' ? strtolower($root) : null,
            keyboardInteractive: ($ssh['kbd_interactive_authentication'] ?? null) === true,
            usePam: ($ssh['use_pam'] ?? null) === true,
            failedSignIns: self::int($logins['failed'] ?? null),
            failedSources: self::int($logins['sources'] ?? null) ?? 0,
            failedAsRoot: self::int($logins['root'] ?? null) ?? 0,
            windowMinutes: max(1, intdiv(self::int($logins['window_secs'] ?? null) ?? 3600, 60)),
            autoUpdates: self::bool($security['auto_updates'] ?? null),
        );
    }

    /**
     * Whether SSH takes a password: PasswordAuthentication, or
     * keyboard-interactive through PAM, which asks for the same password.
     */
    public function passwordSignIn(): ?bool
    {
        if ($this->keyboardInteractive && $this->usePam) {
            return true;
        }

        return $this->passwordAuthentication;
    }

    private static function bool(mixed $value): ?bool
    {
        return is_bool($value) ? $value : null;
    }

    private static function int(mixed $value): ?int
    {
        return is_int($value) && $value >= 0 ? $value : null;
    }
}
