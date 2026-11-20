<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\Traffic\SiteTraffic;
use Illuminate\Validation\Rule;

final class GetSiteTraffic extends Tool
{
    public function name(): string
    {
        return 'get_site_traffic';
    }

    public function title(): string
    {
        return 'Get a site’s traffic';
    }

    public function description(): string
    {
        return 'Shows the traffic to an app’s site, counted by the machine’s daemon from the reverse proxy’s access logs, as its Traffic tab does: '
            .'summary (requests, responses by status class, errorRate as the share of 5xx responses, clientErrorRate for 4xx, bytes sent, p50 and p95 response times in ms weighted by requests, and visitors, which are unique per day and added up over the days), '
            .'series (a point every 15 minutes for 24h, every hour for 7d, every 6 hours for 30d; null before the first visit), and the top paths (without query strings) and referrers (hosts only), each with its share of requests. '
            .'collecting says whether the machine counts traffic at all (it’s off by default and turned on in the machine’s settings), blocked says why it can’t when it can’t. No IP addresses, user agents, countries or devices are kept. '
            .'app is the name it was deployed as (list_deploys shows them), app:<name>, or one of its services.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'app' => Schema::string('The app: the name it was deployed as (like shop), app:<name>, or one of its services’ keys or names.', maxLength: 300),
            'range' => Schema::string('How far back: 24h (the default), 7d or 30d.', array_keys(SiteTraffic::RANGES)),
        ], ['machine', 'app']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['required', Deploys::reference()],
            'app' => ['required', Deploys::reference()],
            'range' => ['nullable', 'string', Rule::in(array_keys(SiteTraffic::RANGES))],
        ]);
        $machine = $context->machine($data['machine']);
        $app = Deploys::appName($context, $machine, $data['app']);
        $state = SiteTraffic::state($machine, $app, SiteTraffic::domains($machine, $app));
        $report = SiteTraffic::report($machine, $app, $data['range'] ?? '24h');

        return [
            'app' => $app,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            ...$state,
            ...$report,
            'pageUrl' => $context->url("/machines/{$machine->id}/services/app:{$app}/traffic"),
            'note' => match (true) {
                $state['blocked'] !== null => $state['blocked'],
                ! $state['collecting'] => 'The machine doesn’t count traffic. Turn it on in the machine’s settings, under Traffic.',
                $state['domain'] === null => 'The app has no domain, so the proxy doesn’t serve it and there’s no traffic to count.',
                ! $report['hasData'] => 'No visits counted in this range yet. They show up within a couple of minutes of the first one.',
                default => null,
            },
        ];
    }
}
