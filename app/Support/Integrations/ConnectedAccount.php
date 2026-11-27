<?php

namespace App\Support\Integrations;

/**
 * What verify() learned about the account: a name to suggest for it, and
 * facts kept as the integration's meta (never secrets).
 */
final readonly class ConnectedAccount
{
    /** @param  array<string, string|int|float|bool|null>  $meta */
    public function __construct(
        public string $label,
        public array $meta = [],
    ) {}
}
