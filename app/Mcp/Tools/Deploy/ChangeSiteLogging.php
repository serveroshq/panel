<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Mcp\Tools\Machine\ServerWork;
use App\Support\Audit\AuditContext;
use App\Support\DaemonJobQueue;
use App\Support\Traffic\ExistingSites;

final class ChangeSiteLogging extends Tool
{
    public function __construct(private readonly ServerWork $work) {}

    public function name(): string
    {
        return 'change_site_logging';
    }

    public function title(): string
    {
        return 'Start or stop ServerOS’s log for a site';
    }

    public function description(): string
    {
        return 'For a site of the machine’s own web servers (a key from list_site_traffic): start adds a log of ServerOS’s own to its config so its traffic can be counted (for a site that writes none: a named log in its Caddy site block, an access log in its nginx server block or Apache VirtualHost, an accessLog section for Traefik on the machine, which then restarts), stop takes out exactly the lines ServerOS added. '
            .'Without confirm it changes nothing: the job’s result lists every line it would add or take out, and where (follow it with get_job). Show those to the person, then call again with confirm set to the site’s key exactly. '
            .'With it, the machine backs each file up, checks the config (caddy validate, nginx -t, apachectl configtest; Traefik must come back up), puts every file back if the check fails, then reloads. '
            .'Refused for servers in containers (their config is the container’s), in read-only mode, and on daemons too old for it.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'site' => Schema::string('The site’s key from list_site_traffic.', maxLength: 120),
            'action' => Schema::string('start or stop.', ['start', 'stop']),
            'confirm' => Schema::string('The site’s key exactly, to make the change. Leave it out to see what would change.', maxLength: 120),
            'wait' => ServerWork::waitSchema(),
        ], ['machine', 'site', 'action']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $data = $this->validate($arguments, [
            'machine' => ['required', Deploys::reference()],
            'site' => ['required', 'string', 'max:120'],
            'action' => ['required', 'in:start,stop'],
            'confirm' => ['nullable', 'string', 'max:120'],
            'wait' => ServerWork::waitRules(),
        ]);
        $machine = $context->machine($data['machine']);
        $site = ExistingSites::available($machine) ? ExistingSites::find($machine, (string) $data['site']) : null;
        if ($site === null) {
            throw new ToolError('There’s no site '.$data['site'].' on '.$machine->name.'. list_site_traffic lists them.');
        }
        if (! DaemonJobQueue::canCountAnySite($machine)) {
            throw new ToolError((string) ExistingSites::updateNote($machine));
        }
        if (! $site['editable']) {
            throw new ToolError($site['container'] !== null
                ? "This site’s server runs in the container {$site['container']}, so ServerOS won’t change its config. Turn on its access log in the container’s config instead. Nothing was changed."
                : 'This site’s config isn’t a file ServerOS can change. Nothing was changed.');
        }
        $add = $data['action'] === 'start';
        if ($add === $site['copy']) {
            throw new ToolError(($add ? 'ServerOS already logs this site.' : 'ServerOS hasn’t added anything to this site’s config.').' Nothing was changed.');
        }
        $apply = filled($data['confirm'] ?? null);
        if ($apply) {
            ServerWork::confirm($data, $site['site'], 'site');
            ServerWork::throttle($context, 'traffic-logging', 10);
        }
        $job = $this->work->queue($context, $machine, 'traffic_logging', ['type' => 'traffic_logging', 'site' => $site['site'], 'action' => $add ? 'add' : 'remove', 'apply' => $apply], 180);
        if ($apply) {
            $context->record('machine.traffic_logging', $machine->name, $machine, ['site' => ExistingSites::label($site), 'action' => $add ? 'add' : 'remove']);
        } else {
            // Only asks the machine what it would change.
            AuditContext::nothingChanged();
        }
        $job = $context->waitForJob($job, (int) ($data['wait'] ?? 0));

        return [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'site' => $site['site'],
            'applied' => $apply,
            'job' => Present::job($job),
            'note' => $apply
                ? 'The machine is making the change. get_job shows how it went; new requests show up in get_site_traffic within a couple of minutes.'
                : 'Nothing was changed. get_job shows the lines it would '.($add ? 'add' : 'take out').'; call again with confirm set to the site’s key to make the change.',
        ];
    }
}
