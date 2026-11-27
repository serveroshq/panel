<?php

namespace App\Mcp\Tools\Deploy;

use App\Enums\WorkspacePermission;
use App\Mcp\McpContext;
use App\Mcp\Schema;
use App\Mcp\Tool;
use App\Support\Deploys\DeployRequest;
use App\Support\Domains\DomainCheck;
use App\Support\Integrations\Cloudflare\CloudflareDns;

final class CheckDomain extends Tool
{
    public function __construct(private readonly DomainCheck $check, private readonly CloudflareDns $dns) {}

    public function name(): string
    {
        return 'check_domain';
    }

    public function title(): string
    {
        return 'Check a domain points at a machine';
    }

    public function description(): string
    {
        return 'Looks up a domain’s DNS (A and AAAA records) and says whether it points at the machine, as the deploy dialog does while a domain is typed. status is "ready" (it points here: HTTPS is set up as it deploys), "missing" (no record yet), "elsewhere" (it points at other addresses, listed in found) or "proxied" (behind Cloudflare’s proxy, which blocks the certificate: set the record to DNS only, the grey cloud). '
            .'record is the DNS record to add or change where the person manages the domain. cloudflare is set when the domain’s zone is in a connected Cloudflare account: ServerOS then adds or updates the record itself as the app deploys, so nobody edits DNS by hand. A deploy can go ahead before DNS is ready: HTTPS starts once the record points at the machine, which can take a few minutes. Only reads DNS; changes nothing.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'domain' => Schema::string('The domain, like app.example.com (a pasted URL works too).', maxLength: 300),
            'wildcard' => Schema::boolean('Also check that every subdomain points at the machine, for an app serving *.domain: a *.domain record is needed.'),
        ], ['machine', 'domain']);
    }

    public function permission(): WorkspacePermission
    {
        return WorkspacePermission::ManageMachines;
    }

    public function openWorld(): bool
    {
        return true;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $args = $this->validate($arguments, [
            'machine' => ['required', Deploys::reference()],
            'domain' => ['required', 'string', 'max:300'],
            'wildcard' => ['sometimes', 'boolean'],
        ]);
        $machine = $context->machine($args['machine']);

        $data = $this->validate(['domain' => DeployRequest::bareDomain($args['domain'])], [
            'domain' => ['required', 'string', 'max:253', 'regex:'.DeployRequest::DOMAIN_PATTERN],
        ], ['domain.regex' => 'Enter a domain like app.example.com.']);

        $result = $this->check->check($machine, $data['domain']);
        $cloudflare = $this->dns->checkInfo($machine, $data['domain']);
        $answer = [
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            ...$result,
            'cloudflare' => $cloudflare,
            'advice' => $cloudflare !== null && $result['status'] !== 'ready'
                ? "{$data['domain']} is in the Cloudflare account {$cloudflare['account']} connected to this workspace: ServerOS points it at {$machine->name} itself as the app deploys."
                : self::advice($result, $machine->name),
        ];

        if ($args['wildcard'] ?? false) {
            $wildcard = $this->check->checkWildcard($machine, $data['domain']);
            $answer['wildcard'] = [...$wildcard, 'advice' => self::advice($wildcard, $machine->name, 'Every subdomain')];
        }

        return $answer;
    }

    /**
     * @param  array{domain: string, status: string, found: list<string>, record: array{type: string, name: string, value: string}|null}  $result
     */
    private static function advice(array $result, string $machine, ?string $subject = null): string
    {
        $record = $result['record'];
        $subject ??= $result['domain'];

        return match ($result['status']) {
            'ready' => ($record['type'] ?? null) === 'CNAME'
                ? "{$subject} is on Cloudflare, in front of {$machine}’s Cloudflare Tunnel, which does HTTPS. The tunnel also needs a route for it (a published application with the service URL http://localhost:80), in Cloudflare Zero Trust."
                : "{$subject} points to {$machine}. HTTPS is set up as it deploys.",
            'missing' => "No DNS record for {$result['domain']} yet.".($record ? " Add {$record['type']} {$record['name']} → {$record['value']} where the domain is managed." : ' The machine has no known IP address to point it at.'),
            'proxied' => 'It’s behind Cloudflare’s proxy, which blocks the certificate. Set the record to DNS only (grey cloud)'.($record ? ": {$record['type']} {$record['name']} → {$record['value']}." : '.'),
            default => 'It points to '.implode(', ', $result['found']).', not this machine.'.($record ? " Change the record to {$record['type']} {$record['name']} → {$record['value']}." : ''),
        };
    }
}
