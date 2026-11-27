<?php

namespace App\Support\Integrations\Servers;

use App\Models\Integration;
use App\Support\Integrations\IntegrationFailed;

/**
 * A provider whose accounts hold servers: Hetzner Cloud, DigitalOcean,
 * Vultr. Every method talks to the provider's API and throws
 * IntegrationFailed with a message a person can act on.
 *
 * Methods that start something at the provider return the id of the
 * provider's action to wait on, or null when the provider has no action to
 * follow. ServerOperations then watches the server itself: its status after
 * a start, its type after a resize.
 *
 * @throws IntegrationFailed
 */
interface ManagesServers
{
    /** @return list<Server> */
    public function servers(Integration $integration): array;

    public function server(Integration $integration, string $id): ?Server;

    public function plans(Integration $integration): ServerPlans;

    public function create(Integration $integration, NewServer $server, string $userData): StartedServer;

    /** @return list<ServerType> */
    public function resizeTypes(Integration $integration, Server $server): array;

    public function resize(Integration $integration, string $id, string $type, bool $growDisk): ?string;

    public function rules(): ServerRules;

    public function shutdown(Integration $integration, string $id): ?string;

    public function powerOff(Integration $integration, string $id): ?string;

    public function powerOn(Integration $integration, string $id): ?string;

    public function reboot(Integration $integration, string $id): ?string;

    public function powerCycle(Integration $integration, string $id): ?string;

    public function action(Integration $integration, string $actionId): ActionState;

    /** @return list<Snapshot> */
    public function snapshots(Integration $integration, string $serverId): array;

    public function snapshot(Integration $integration, string $serverId, string $name): Snapshot;

    public function deleteSnapshot(Integration $integration, string $snapshotId): void;

    public function delete(Integration $integration, string $id): void;
}
