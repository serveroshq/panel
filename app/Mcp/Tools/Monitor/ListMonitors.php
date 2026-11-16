<?php

namespace App\Mcp\Tools\Monitor;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Mcp\Paging;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Models\Monitor;
use Illuminate\Validation\Rule;

final class ListMonitors extends Tool
{
    public const MAX = 200;

    public function name(): string
    {
        return 'list_monitors';
    }

    public function title(): string
    {
        return 'List uptime monitors';
    }

    public function description(): string
    {
        return 'Lists the workspace’s uptime monitors (URLs and ports ServerOS checks from outside your machines), sorted by name like the Monitors page. Each has its id, name, type (http or tcp), target (the URL, or host:port), status (up; down; pending = no result yet or one failed check not yet confirmed, see error; paused), since when, the last check’s time, response time and error, uptime over the last 7 and 30 days (percent, null before any check), check interval, the machine it’s linked to, the HTTPS certificate’s expiry, and alerts: enabled, muted (by mute_monitor or because its machine is in maintenance) and until when. '
            .'Filter by status, type, machine, or query (matches name, URL or host); muted true lists only monitors whose alerts are held. Paged with limit (default 50): pass next_cursor as cursor for the next page. `plan` says how many monitors the plan allows and the shortest interval. Use get_monitor for one monitor’s history and incidents.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'status' => Schema::string('Only monitors with this status.', Monitors::STATUSES),
            'type' => Schema::string('Only http (a URL) or tcp (a port) monitors.', Monitor::TYPES),
            'machine' => Schema::machine('Only monitors linked to this machine: its id or name.'),
            'query' => Schema::string('Text to look for in the name, URL or host (any case).', maxLength: 200),
            'muted' => Schema::boolean('true: only monitors whose alerts are held (muted, or their machine is in maintenance). false: only those that would alert.'),
            'limit' => Schema::integer('How many to return (default 50).', 1, self::MAX),
            ...Paging::schema(),
        ]);
    }

    public function responseSchema(): array
    {
        return ResponseSchemas::page('monitors', ResponseSchemas::ref('Monitor'), [
            'count' => ['type' => 'integer', 'description' => 'How many are on this page.'],
            'plan' => ResponseSchemas::object([
                'monitors' => ['type' => 'integer', 'description' => 'How many the workspace has.'],
                'monitorLimit' => ['type' => 'integer'],
                'minIntervalSeconds' => ['type' => 'integer'],
            ], ['monitors', 'monitorLimit', 'minIntervalSeconds']),
            'url' => ['type' => 'string', 'description' => 'The monitors page in the dashboard.'],
        ]);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'status' => ['nullable', Rule::in(Monitors::STATUSES)],
            'type' => ['nullable', Rule::in(Monitor::TYPES)],
            'machine' => ['nullable', Monitors::reference()],
            'query' => ['nullable', 'string', 'max:200'],
            'muted' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'between:1,'.self::MAX],
            ...Paging::rules(),
        ]);
        $machine = isset($data['machine']) ? $context->machine($data['machine']) : null;
        $limit = (int) ($data['limit'] ?? 50);
        $offset = Paging::offset($data);
        $query = mb_strtolower(trim((string) ($data['query'] ?? '')));

        $monitors = $context->workspace->monitors()->with('machine')->orderBy('name')
            ->when($machine, fn ($q) => $q->where('machine_id', $machine->id))
            ->when(isset($data['type']), fn ($q) => $q->where('type', $data['type']))
            ->when(($data['status'] ?? null) === 'paused', fn ($q) => $q->where('paused', true))
            ->when(isset($data['status']) && $data['status'] !== 'paused', fn ($q) => $q->where('paused', false)->where('status', $data['status']))
            ->get()
            ->filter(fn (Monitor $m) => $query === '' || collect([$m->name, $m->url, $m->host])
                ->contains(fn ($v) => $v !== null && str_contains(mb_strtolower($v), $query)))
            ->filter(fn (Monitor $m) => ! isset($data['muted']) || $m->muted() === (bool) $data['muted'])
            ->values();

        $page = $monitors->slice($offset, $limit)->values();
        $workspace = $context->workspace;

        return [
            'total' => $monitors->count(),
            'count' => $page->count(),
            'offset' => $offset,
            'more' => $offset + $page->count() < $monitors->count(),
            'next_cursor' => Paging::next($offset + $page->count(), $monitors->count()),
            'monitors' => $page->map(fn (Monitor $m) => Monitors::row($m, $context))->all(),
            'plan' => [
                'monitors' => $workspace->monitors()->count(),
                'monitorLimit' => $workspace->monitorLimit(),
                'minIntervalSeconds' => $workspace->minMonitorInterval(),
            ],
            'url' => $context->url('/monitors'),
        ];
    }
}
