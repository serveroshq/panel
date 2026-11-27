<?php

namespace App\Support\Integrations;

enum Capability: string
{
    case Servers = 'servers';
    case CreateServers = 'create_servers';
    case Resize = 'resize';
    case Power = 'power';
    case Snapshots = 'snapshots';
    case DeleteServers = 'delete_servers';
    case Repositories = 'repositories';
    case PushDeploys = 'push_deploys';
    case Dns = 'dns';
    case PurgeCache = 'purge_cache';
    case Releases = 'releases';
    case Pages = 'pages';
    case Issues = 'issues';

    public function label(): string
    {
        return self::t(match ($this) {
            self::Servers => 'Link machines',
            self::CreateServers => 'Make servers',
            self::Resize => 'Resize',
            self::Power => 'Restart and power cycle',
            self::Snapshots => 'Snapshots',
            self::DeleteServers => 'Delete servers',
            self::Repositories => 'Deploy its projects',
            self::PushDeploys => 'Deploy each push',
            self::Dns => 'Point domains',
            self::PurgeCache => 'Purge cache',
            self::Releases => 'Releases for each deploy',
            self::Pages => 'Page whoever is on call',
            self::Issues => 'Issues for what breaks',
        });
    }

    public function description(): string
    {
        return self::t(match ($this) {
            self::Servers => 'Matches its servers to your machines by IP address, and lists the ones that aren’t in ServerOS yet.',
            self::CreateServers => 'Makes a server that installs ServerOS as it starts, so it joins on its own.',
            self::Resize => 'Moves a linked machine to a bigger or smaller server type.',
            self::Power => 'Restarts a linked machine, or cuts its power and starts it again, even when its daemon is offline.',
            self::Snapshots => 'Takes, lists and deletes snapshots of a linked machine’s disk.',
            self::DeleteServers => 'Deletes a linked machine’s server for good.',
            self::Repositories => 'Lists its projects when you deploy, clones private ones and shows each deploy’s commit message.',
            self::PushDeploys => 'Adds a webhook to a project when one of its apps deploys each push, and takes it off again when none does.',
            self::Dns => 'Adds or updates the DNS record for each app’s domain as it deploys, and removes the ones it added when the domain goes.',
            self::PurgeCache => 'Purges an app’s hostnames from Cloudflare’s cache after each deploy, or when you ask.',
            self::Releases => 'Makes a Sentry release with the commit each time an app deploys, marks it deployed, and marks the older one deployed again on a roll back. The Deploys card shows new issues since each one.',
            self::Pages => 'Triggers an alert when a monitor goes down, a machine stops reporting or an exploited critical CVE turns up, and resolves it when it’s fixed.',
            self::Issues => 'Opens an issue when a monitor stays down, a machine stays offline or a critical CVE turns up, comments as it changes and closes it when it’s fixed.',
        });
    }

    /** @return array{key: string, label: string, description: string} */
    public function toArray(): array
    {
        return ['key' => $this->value, 'label' => $this->label(), 'description' => $this->description()];
    }

    private static function t(string $text): string
    {
        return is_string($line = __($text)) ? $line : $text;
    }
}
