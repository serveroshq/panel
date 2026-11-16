<?php

namespace App\Mcp\Tools\Workspace;

use App\Api\ResponseSchemas;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\Health\WorkspaceHealth;

final class ListIssues extends Tool
{
    public const DEFAULT_LIMIT = 50;

    public const MAX_LIMIT = 200;

    public function __construct(private readonly Issues $issues) {}

    public function name(): string
    {
        return 'list_issues';
    }

    public function title(): string
    {
        return 'List what needs attention';
    }

    public function description(): string
    {
        return 'What the workspace Home lists under “Needs attention”, worst first: machines that stopped reporting, never connected, or are low on disk or memory; services that stopped unexpectedly or keep restarting; monitors that are down or whose TLS certificate expires soon; and backups that failed, are overdue or aren’t set up for production machines. Items someone marked as read stay hidden until they happen again. '
            .'Each item has its id (for dismiss_issues), severity (critical, warning, or notice: expected or less urgent, such as during maintenance or on a muted monitor), kind (machine.offline, machine.never_connected, machine.disk, machine.memory, machine.maintenance, service.down, service.crash_loop, service.needs_repair, monitor.down, monitor.certificate, backup.failed, backup.overdue, backup.off, or other for anything else, like a database open to the internet), title and plain-language detail as the dashboard says them, a short `fix` with the next step, when it started (`since`, ISO 8601, when known), the machine, service (key) and monitor (id) it’s about, and the dashboard link to fix it. '
            .'`needsAttention` counts the critical and warning items, as the Home’s badge does, and `counts` gives each severity: both are for the whole workspace whatever the filters; `matching` is how many the filters let through. Use it to answer “is anything wrong?” and to decide what to look at first.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'severity' => Schema::string('Only items at least this serious: critical, warning (critical and warning), or notice (everything, the default).', Issues::SEVERITIES),
            'machine' => Schema::machine('Only items about this machine (its id or name), including its monitors.'),
            'limit' => Schema::integer('Most items to return (default 50).', 1, self::MAX_LIMIT),
        ]);
    }

    public function responseSchema(): array
    {
        $named = ['id' => ['type' => 'integer'], 'name' => ['type' => 'string']];

        return ResponseSchemas::object([
            'needsAttention' => ['type' => 'integer', 'description' => 'Critical and warning items in the whole workspace, whatever the filters.'],
            'counts' => ResponseSchemas::object(
                array_fill_keys(Issues::SEVERITIES, ['type' => 'integer']),
                Issues::SEVERITIES,
                'Items of each severity in the whole workspace, whatever the filters.',
            ),
            'matching' => ['type' => 'integer', 'description' => 'How many the filters let through.'],
            'issues' => ['type' => 'array', 'items' => ResponseSchemas::object([
                'id' => ['type' => 'string', 'description' => 'For dismiss_issues.'],
                'severity' => ['type' => 'string', 'enum' => Issues::SEVERITIES],
                'kind' => ['type' => 'string', 'enum' => [
                    'machine.offline', 'machine.never_connected', 'machine.disk', 'machine.memory', 'machine.maintenance',
                    'service.down', 'service.crash_loop', 'service.needs_repair', 'monitor.down', 'monitor.certificate',
                    'backup.failed', 'backup.overdue', 'backup.off', 'other',
                ]],
                'title' => ['type' => 'string'],
                'detail' => ['type' => 'string'],
                'fix' => ['type' => ['string', 'null'], 'description' => 'The next step to fix it.'],
                'since' => ['type' => ['string', 'null'], 'format' => 'date-time', 'description' => 'When it started, when known.'],
                'machine' => ['type' => ['object', 'null'], 'required' => ['id', 'name'], 'properties' => $named],
                'service' => ['type' => ['object', 'null'], 'required' => ['key', 'name', 'url'], 'properties' => [
                    'key' => ['type' => 'string'],
                    'name' => ['type' => 'string'],
                    'url' => ['type' => 'string'],
                ]],
                'monitor' => ['type' => ['object', 'null'], 'required' => ['id', 'name', 'target'], 'properties' => [...$named, 'target' => ['type' => 'string']]],
                'action' => ['type' => 'string', 'description' => 'The dashboard button’s label.'],
                'url' => ['type' => 'string', 'description' => 'Where to fix it in the dashboard.'],
            ], ['id', 'severity', 'kind', 'title', 'detail', 'fix', 'since', 'machine', 'service', 'monitor', 'action', 'url'])],
            'truncated' => ['type' => 'boolean', 'description' => 'Whether some matching items were left out by the limit.'],
            'url' => ['type' => 'string', 'description' => 'The workspace Home in the dashboard.'],
        ], ['needsAttention', 'counts', 'matching', 'issues', 'truncated', 'url']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate(Rules::given($arguments), [
            'severity' => ['nullable', 'string', 'in:'.implode(',', Issues::SEVERITIES)],
            'machine' => ['nullable', 'max:120', Rules::reference(...)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.self::MAX_LIMIT],
        ]);

        $machine = ($data['machine'] ?? null) !== null ? $context->machine($data['machine']) : null;
        $limit = (int) ($data['limit'] ?? self::DEFAULT_LIMIT);

        $all = $this->issues->current($context);
        $counts = array_count_values(array_column($all, 'severity'));

        $shown = $all;
        if (isset($data['severity'])) {
            $worst = array_slice(Issues::SEVERITIES, 0, array_search($data['severity'], Issues::SEVERITIES, true) + 1);
            $shown = array_values(array_filter($shown, fn (array $issue) => in_array($issue['severity'], $worst, true)));
        }

        if ($machine !== null) {
            $presented = array_values(array_filter(
                $this->issues->present($context, $shown),
                fn (array $issue) => ($issue['machine']['id'] ?? null) === $machine->id,
            ));
            $matching = count($presented);
            $presented = array_slice($presented, 0, $limit);
        } else {
            $matching = count($shown);
            $presented = $this->issues->present($context, array_slice($shown, 0, $limit));
        }

        return [
            'needsAttention' => ($counts[WorkspaceHealth::CRITICAL] ?? 0) + ($counts[WorkspaceHealth::WARNING] ?? 0),
            'counts' => [
                'critical' => $counts[WorkspaceHealth::CRITICAL] ?? 0,
                'warning' => $counts[WorkspaceHealth::WARNING] ?? 0,
                'notice' => $counts[WorkspaceHealth::NOTICE] ?? 0,
            ],
            'matching' => $matching,
            'issues' => $presented,
            'truncated' => $matching > count($presented),
            'url' => $context->url('/'),
        ];
    }
}
