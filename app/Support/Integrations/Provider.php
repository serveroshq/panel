<?php

namespace App\Support\Integrations;

use App\Models\Integration;
use App\Support\Integrations\Servers\ManagesServers;

/**
 * A service a workspace can connect an account of. See docs/integrations.md.
 */
abstract class Provider
{
    /** The IntegrationCatalogue key this provider makes real, like "hetzner". */
    abstract public function key(): string;

    abstract public function name(): string;

    /** @return list<ConnectField> */
    abstract public function fields(): array;

    /**
     * Checks the credentials against the provider's real API.
     *
     * @param  array<string, string>  $credentials
     *
     * @throws IntegrationFailed
     */
    abstract public function verify(array $credentials): ConnectedAccount;

    /** @return list<Capability> */
    abstract public function capabilities(): array;

    public function multipleAccounts(): bool
    {
        return true;
    }

    /** One line about the account for the Connected list, from its meta. */
    public function summary(Integration $integration): string
    {
        return $integration->label;
    }

    /** @return list<array{label: string, value: string}> */
    public function facts(Integration $integration): array
    {
        return [];
    }

    /**
     * Takes back what ServerOS set up at the provider for this account, just
     * before it's disconnected, and says so (null when there was nothing).
     */
    public function disconnect(Integration $integration): ?string
    {
        return null;
    }

    public function can(Capability $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    /** What a capability does with this provider, when that differs from the usual words. */
    public function describe(Capability $capability): string
    {
        return $capability->description();
    }

    public function managesServers(): bool
    {
        return $this instanceof ManagesServers;
    }

    /** @return array{key: string, name: string, fields: list<array<string, mixed>>, capabilities: list<array{key: string, label: string, description: string}>, multiple: bool} */
    public function toArray(): array
    {
        return [
            'key' => $this->key(),
            'name' => $this->name(),
            'fields' => array_map(fn (ConnectField $field) => $field->toArray(), $this->fields()),
            'capabilities' => array_map(fn (Capability $capability) => [...$capability->toArray(), 'description' => $this->describe($capability)], $this->capabilities()),
            'multiple' => $this->multipleAccounts(),
        ];
    }

    /** @param  array<string, string|int>  $replace */
    protected static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
