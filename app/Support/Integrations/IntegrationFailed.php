<?php

namespace App\Support\Integrations;

use RuntimeException;

/**
 * A provider call that didn't work, with a message a person can act on.
 * The message is shown as it is, so it never carries a secret.
 */
final class IntegrationFailed extends RuntimeException
{
    public const string CREDENTIALS = 'credentials';

    public const string PERMISSION = 'permission';

    public const string NOT_FOUND = 'not_found';

    public const string RATE_LIMITED = 'rate_limited';

    public const string UNAVAILABLE = 'unavailable';

    public const string INVALID = 'invalid';

    public const string CONFLICT = 'conflict';

    public function __construct(string $message, public readonly string $reason = self::INVALID, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }

    public function refusedCredentials(): bool
    {
        return $this->reason === self::CREDENTIALS;
    }
}
