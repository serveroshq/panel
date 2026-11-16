<?php

namespace App\Mcp\Tools\Machine;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\Firewall;

final class GetFirewall extends Tool
{
    public function name(): string
    {
        return 'get_firewall';
    }

    public function title(): string
    {
        return 'See a machine’s firewall and open ports';
    }

    public function description(): string
    {
        return 'Shows the machine’s firewall (ufw, nftables or iptables), whether it is on and blocks by default, its rules, and each port listening beyond the machine itself with its exposure: open (anyone on the internet can connect), restricted (only some addresses), blocked, docker (published by a Docker container, which the firewall doesn’t filter) or unknown (rules ServerOS can’t read may decide). what names a database or cache that usually shouldn’t face the internet; ssh marks SSH, which close_port refuses. unavailable says why ports can’t be closed on it, if they can’t. From the last scan (scannedAt): rescan_machine refreshes it. Older daemons don’t report the firewall (supported is false): every port bound beyond loopback then has exposure null and may be reachable.';
    }

    public function inputSchema(): array
    {
        return Schema::object(['machine' => Schema::machine()], ['machine']);
    }

    public function responseSchema(): array
    {
        return ResponseSchemas::object([
            'machine' => ResponseSchemas::ref('MachineRef'),
            'scannedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            'firewall' => ['type' => ['object', 'null'], 'description' => 'Null until the machine has reported a scan.', 'required' => ['supported', 'backend', 'installed', 'active', 'defaultIncoming', 'unavailable', 'ports', 'rules', 'unreadable', 'notes'], 'properties' => [
                'supported' => ['type' => 'boolean', 'description' => 'The daemon reports its firewall.'],
                'backend' => ['type' => ['string', 'null']],
                'installed' => ['type' => 'boolean'],
                'active' => ['type' => 'boolean'],
                'defaultIncoming' => ['type' => ['string', 'null']],
                'unavailable' => ['type' => ['string', 'null'], 'description' => 'Why ports can’t be opened or closed from here, when they can’t.'],
                'ports' => ['type' => 'array', 'description' => 'Ports listening on a public address.', 'items' => ResponseSchemas::object([
                    'port' => ['type' => 'integer'],
                    'proto' => ['type' => 'string'],
                    'process' => ['type' => ['string', 'null']],
                    'exposure' => ['type' => ['string', 'null']],
                    'because' => ['type' => ['string', 'null']],
                    'what' => ['type' => ['string', 'null'], 'description' => 'What it is, when it shouldn’t usually be open to the internet.'],
                    'ssh' => ['type' => 'boolean'],
                ], ['port', 'proto', 'process', 'exposure', 'because', 'what', 'ssh'])],
                'rules' => ['type' => 'array', 'items' => ResponseSchemas::object([
                    'verdict' => ['type' => 'string'],
                    'ports' => ['type' => 'string'],
                    'proto' => ['type' => ['string', 'null']],
                    'source' => ['type' => ['string', 'null']],
                    'ipv6' => ['type' => 'boolean'],
                    'managed' => ['type' => 'boolean'],
                    'opaque' => ['type' => 'boolean'],
                    'text' => ['type' => 'string'],
                ], ['verdict', 'ports', 'proto', 'source', 'ipv6', 'managed', 'opaque', 'text'])],
                'unreadable' => ['type' => 'integer', 'description' => 'Rules it couldn’t read.'],
                'notes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ]],
            'note' => ['type' => ['string', 'null']],
        ], ['machine', 'scannedAt', 'firewall', 'note']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['machine' => ['required', ServerWork::reference()]]);
        $machine = $context->machine($data['machine']);
        $view = Firewall::view($machine);

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'scannedAt' => $machine->inventory_scanned_at?->toIso8601String(),
            'firewall' => $view === null ? null : collect($view)->except('presets')->all(),
            'note' => $view === null ? 'The machine hasn’t reported a scan yet.' : null,
        ];
    }
}
