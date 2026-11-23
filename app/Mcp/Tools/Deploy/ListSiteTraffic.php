<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\Traffic\ExistingSites;
use App\Support\Traffic\SiteTraffic;
use App\Support\Traffic\TrafficSwitch;

final class ListSiteTraffic extends Tool
{
    public function name(): string
    {
        return 'list_site_traffic';
    }

    public function title(): string
    {
        return 'List the sites a machine counts traffic for';
    }

    public function description(): string
    {
        return 'Lists every site a machine counts traffic for, with its last 24 hours: requests, errors (5xx responses), clientErrors (4xx) and visitors (unique that day). '
            .'kind is app for the apps ServerOS deployed (counted from its proxy’s access logs), or existing for the sites the machine’s own web servers serve: server is nginx, Apache, Caddy or Traefik (container names the container it runs in, when it does), or Log file for a file set by hand for an app that writes its own. '
            .'Those are counted read-only from the access logs their config already writes (a file, or the container’s output). '
            .'timed says whether response times are recorded, shared that several sites write one log without saying which, so they’re counted together as one, catchAll that it answers every host no other site does. '
            .'unlogged is off when the site’s config writes no access log (Caddy’s default) and elsewhere when it goes somewhere ServerOS can’t read (syslog, the journal); those have no numbers, and change_site_logging can start a log for one. '
            .'get_site_traffic shows one site in full: pass app for an app, or site with the key here for the others.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
        ], ['machine']);
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, ['machine' => ['required', Deploys::reference()]]);
        $machine = $context->machine($data['machine']);
        $collecting = TrafficSwitch::on($machine);
        $blocked = TrafficSwitch::blocked($machine);

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'collecting' => $collecting,
            'blocked' => $blocked,
            'countsWebServerSites' => ExistingSites::available($machine),
            'sites' => array_map(fn (array $row) => [
                ...$row,
                'url' => $row['url'] === null ? null : $context->url($row['url']),
            ], SiteTraffic::sites($machine)),
            'note' => match (true) {
                $blocked !== null => $blocked,
                ! $collecting => 'The machine doesn’t count traffic. Turn it on in the machine’s settings, under Traffic.',
                default => null,
            },
        ];
    }
}
