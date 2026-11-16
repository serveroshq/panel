<?php

namespace App\Mcp\Tools\StatusPage;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\Machine;
use App\Models\StatusPage;
use App\Support\Edition;
use App\Support\StatusPages\StatusReport;

final class ListStatusPages extends Tool
{
    public function name(): string
    {
        return 'list_status_pages';
    }

    public function title(): string
    {
        return 'List status pages';
    }

    public function description(): string
    {
        return 'Lists the workspace’s status pages: for each machine (by name), whether it has a page (`exists`), whether it’s switched on (`enabled`) and actually served (`live`: on, on a plan that has status pages), public or members-only, its address (slug, URL, badge and JSON URLs), title, how many services and monitors it shows and its headline right now; '
            .'and the workspace’s fleet page, one page with a section per machine (Business). Machines without a page are listed with exists false. '
            .'Use get_status_page for one machine’s page in full and get_fleet_status_page for the fleet page.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'only_existing' => Schema::boolean('Leave out machines that have no status page.'),
            'limit' => Schema::integer('At most this many machines (default 100).', 1, 500),
        ]);
    }

    public function responseSchema(): array
    {
        $string = ['type' => 'string'];
        $bool = ['type' => 'boolean'];
        $addresses = ['url' => $string, 'badgeUrl' => $string, 'jsonUrl' => $string, 'feedUrl' => $string];

        return ResponseSchemas::object([
            'planAllows' => ResponseSchemas::object(['statusPages' => $bool, 'fleetStatusPage' => $bool], ['statusPages', 'fleetStatusPage']),
            'machines' => ['type' => 'array', 'items' => ResponseSchemas::object([
                'machine' => ResponseSchemas::object([
                    'id' => ['type' => 'integer'],
                    'name' => $string,
                    'status' => ['type' => 'string', 'enum' => ['online', 'offline', 'connecting', 'demo']],
                ], ['id', 'name', 'status']),
                'exists' => ['type' => 'boolean', 'description' => 'It has a page. The keys below settingsUrl come only with one.'],
                'enabled' => $bool,
                'live' => ['type' => 'boolean', 'description' => 'Switched on, on a plan that serves it.'],
                'settingsUrl' => $string,
                'public' => $bool,
                'slug' => $string,
                'title' => $string,
                ...$addresses,
                'shows' => ResponseSchemas::object(
                    ['services' => ['type' => 'integer'], 'monitors' => ['type' => 'integer'], 'sections' => ['type' => 'integer']],
                    ['services', 'monitors', 'sections'],
                ),
                'overall' => ResponseSchemas::object([
                    'status' => ['type' => 'string', 'enum' => [StatusReport::OPERATIONAL, StatusReport::DEGRADED, StatusReport::DOWN, StatusReport::UNKNOWN]],
                    'label' => $string,
                ], ['status', 'label'], 'The headline right now.'),
                'notice' => [...ResponseSchemas::object([
                    'text' => $string,
                    'kind' => ['type' => 'string', 'enum' => StatusPage::NOTICE_KINDS],
                ], ['text', 'kind']), 'type' => ['object', 'null']],
                'updatedAt' => ['type' => ['string', 'null'], 'format' => 'date-time'],
            ], ['machine', 'exists', 'enabled', 'live', 'settingsUrl'])],
            'total' => ['type' => 'integer'],
            'truncated' => ['type' => 'boolean', 'description' => 'More machines than limit.'],
            'fleet' => ResponseSchemas::object([
                'exists' => $bool,
                'available' => ['type' => 'boolean', 'description' => 'The plan includes the fleet page.'],
                'note' => ['type' => 'string', 'description' => 'When there’s no fleet page.'],
                'settingsUrl' => $string,
                'enabled' => $bool,
                'live' => $bool,
                'public' => $bool,
                'slug' => $string,
                'title' => $string,
                ...$addresses,
                'everyMachine' => $bool,
                'machines' => ['type' => ['array', 'null'], 'items' => ['type' => 'integer'], 'description' => 'The machine ids it covers; null with everyMachine.'],
            ], ['exists', 'available', 'settingsUrl'], 'The workspace’s fleet page. The keys after settingsUrl come only when it exists.'),
        ], ['planAllows', 'machines', 'total', 'truncated', 'fleet']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'only_existing' => ['sometimes', 'boolean'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:500'],
        ]);
        $workspace = $context->workspace;

        $query = $workspace->machines()
            ->when($data['only_existing'] ?? false, fn ($q) => $q->whereHas('statusPage'));
        $total = (clone $query)->count();
        $machines = $query->with(['statusPage', 'serviceGroups'])->orderBy('name')->limit($data['limit'] ?? 100)->get();

        $rows = $machines->map(function (Machine $machine) use ($context) {
            $machine->setRelation('workspace', $context->workspace);
            $page = $machine->statusPage;
            $row = [
                'machine' => ['id' => $machine->id, 'name' => $machine->name, 'status' => Present::connection($machine)],
                'exists' => $page !== null,
                'enabled' => (bool) $page?->enabled,
                'live' => (bool) $page?->live(),
            ];
            if ($page === null) {
                return [...$row, 'settingsUrl' => $context->url("/machines/{$machine->id}/settings/status")];
            }

            $page->setRelation('machine', $machine);
            $report = new StatusReport($page);
            $services = $report->services();
            $monitors = $report->monitors();

            return [
                ...$row,
                'public' => (bool) $page->public,
                'slug' => $page->slug,
                'title' => $page->title ?: $machine->name,
                ...StatusPages::addresses($page->slug),
                'shows' => ['services' => count($services), 'monitors' => count($monitors), 'sections' => count(array_unique(array_filter(array_column($services, 'group'))))],
                'overall' => $report->overall(array_column($services, 'status'), array_column($monitors, 'status')),
                'notice' => $page->notice ? ['text' => $page->notice, 'kind' => $page->notice_kind ?? 'info'] : null,
                'updatedAt' => $page->updated_at?->toIso8601String(),
                'settingsUrl' => $context->url("/machines/{$machine->id}/settings/status"),
            ];
        })->values()->all();

        $fleet = $workspace->fleetStatusPage()->first();
        $fleet?->setRelation('workspace', $workspace);

        return [
            'planAllows' => [
                'statusPages' => $workspace->hasStatusPages(),
                'fleetStatusPage' => $workspace->hasFleetStatusPage(),
            ],
            'machines' => $rows,
            'total' => $total,
            'truncated' => $total > count($rows),
            'fleet' => $fleet === null
                ? [
                    'exists' => false,
                    'available' => $workspace->hasFleetStatusPage(),
                    'note' => $workspace->hasFleetStatusPage()
                        ? 'No fleet page yet: update_fleet_status_page makes one.'
                        : (Edition::selfHosted() ? 'One status page for every machine needs a ServerOS license (Settings › License).' : 'One status page for every machine comes with Business.'),
                    'settingsUrl' => $context->url('/settings/status-page'),
                ]
                : [
                    'exists' => true,
                    'available' => $workspace->hasFleetStatusPage(),
                    'enabled' => (bool) $fleet->enabled,
                    'live' => $fleet->live(),
                    'public' => (bool) $fleet->public,
                    'slug' => $fleet->slug,
                    'title' => $fleet->title ?: $workspace->name,
                    ...StatusPages::addresses($fleet->slug),
                    'everyMachine' => $fleet->machines === null,
                    'machines' => $fleet->machines,
                    'settingsUrl' => $context->url('/settings/status-page'),
                ],
        ];
    }
}
