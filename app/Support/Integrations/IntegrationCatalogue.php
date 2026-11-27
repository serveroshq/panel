<?php

namespace App\Support\Integrations;

/**
 * @phpstan-type Category array{key: string, tab: string, title: string, subtitle: string, icon: string}
 * @phpstan-type Entry array{key: string, name: string, category: string, logo: string|null, initials: string|null, description: string, tags: list<string>}
 */
final class IntegrationCatalogue
{
    /** @return list<Category> */
    public static function categories(): array
    {
        return [
            ['key' => 'developer', 'tab' => self::t('Developer'), 'title' => self::t('Developer'), 'subtitle' => self::t('Code, images and errors'), 'icon' => 'code-fill'],
            ['key' => 'communication', 'tab' => self::t('Communication'), 'title' => self::t('Communication and incidents'), 'subtitle' => self::t('Alerts, approvals and on-call'), 'icon' => 'user-fill'],
            ['key' => 'cloud', 'tab' => self::t('Cloud'), 'title' => self::t('Cloud providers'), 'subtitle' => self::t('Make, import and manage servers without leaving ServerOS'), 'icon' => 'machines-fill'],
            ['key' => 'ai', 'tab' => self::t('AI and automation'), 'title' => self::t('AI and automation'), 'subtitle' => self::t('Your own models, automations and dashboards'), 'icon' => 'sparkle-fill'],
        ];
    }

    /** @return list<Entry> */
    public static function all(): array
    {
        $entry = fn (string $key, string $name, string $category, ?string $logo, string $description, array $tags, ?string $initials = null): array => [
            'key' => $key,
            'name' => $name,
            'category' => $category,
            'logo' => $logo,
            'initials' => $initials,
            'description' => $description,
            'tags' => array_values(array_map(fn (string $tag) => self::t($tag), $tags)),
        ];

        return [
            $entry('github', 'GitHub', 'developer', 'github', self::t('Deploy repositories, preview environments, deploy checks and automatic redeploys.'), ['Deploys', 'Previews', 'Checks']),
            $entry('gitlab', 'GitLab', 'developer', 'gitlab', self::t('Deploy projects from gitlab.com or your own GitLab, private ones too, and redeploy on every push.'), ['Deploys', 'Webhooks']),
            $entry('cloudflare', 'Cloudflare', 'developer', 'cloudflare', self::t('Points your apps’ domains at their machines and purges the cache after each deploy.'), ['DNS', 'Cache']),
            $entry('docker-hub', 'Docker Hub', 'developer', 'docker', self::t('Connect private registries and deploy new images automatically.'), ['Deploys', 'Images']),
            $entry('sentry', 'Sentry', 'developer', 'sentry', self::t('A release for each deploy with its commit, rollbacks marked, and new issues on the Deploys card.'), ['Releases', 'Deploys']),

            $entry('slack', 'Slack', 'communication', 'slack', self::t('Deploy notices and incident alerts, right in Slack.'), ['Alerts', 'Workflows']),
            $entry('discord', 'Discord', 'communication', 'discord', self::t('Server alerts and deploy updates for your machines.'), ['Alerts', 'Workflows']),
            $entry('telegram', 'Telegram', 'communication', 'telegram', self::t('Monitor alerts in a Telegram chat, with a test message to check it works.'), ['Alerts']),
            $entry('linear', 'Linear', 'communication', 'linear', self::t('Opens an issue when something stays broken, comments as it changes and closes it when it’s fixed.'), ['Issues', 'Incidents']),
            $entry('pagerduty', 'PagerDuty', 'communication', 'pagerduty', self::t('Pages whoever is on call when a monitor or machine goes down, and resolves it when it’s back.'), ['Incidents', 'On call']),
            $entry('teams', 'Microsoft Teams', 'communication', null, self::t('Alerts and approvals in Teams, for MSPs and larger teams.'), ['Alerts', 'Approvals'], 'MT'),

            $entry('hetzner', 'Hetzner', 'cloud', 'hetzner', self::t('Make, import and resize servers, restart them and take snapshots.'), ['Machines', 'Snapshots']),
            $entry('digitalocean', 'DigitalOcean', 'cloud', 'digitalocean', self::t('Make, import and resize droplets, restart them and take snapshots.'), ['Machines', 'Snapshots']),
            $entry('aws', 'AWS', 'cloud', 'aws', self::t('Find and make EC2 instances.'), ['Machines']),
            $entry('synteq', 'Synteq', 'cloud', 'synteq', self::t('Make servers and dedicated environments for agents.'), ['Machines', 'Agents']),
            $entry('ovhcloud', 'OVHcloud', 'cloud', 'ovhcloud', self::t('Manage VPS and dedicated servers.'), ['Machines']),
            $entry('vultr', 'Vultr', 'cloud', 'vultr', self::t('Make, import and upgrade servers, restart them and take snapshots.'), ['Machines', 'Snapshots']),
            $entry('proxmox', 'Proxmox', 'cloud', 'proxmox', self::t('Create and manage VMs and containers.'), ['Machines']),
            $entry('tailscale', 'Tailscale', 'cloud', 'tailscale', self::t('Reach machines on your private network.'), ['Network']),
            $entry('backblaze', 'Backblaze B2', 'cloud', 'backblaze', self::t('Off-site storage for your backups.'), ['Backups']),
            $entry('r2', 'Cloudflare R2', 'cloud', 'cloudflare', self::t('S3-compatible storage for your backups.'), ['Backups']),

            $entry('anthropic', 'Anthropic', 'ai', 'anthropic', self::t('Bring your own API key for agents.'), ['Agents']),
            $entry('openai', 'OpenAI', 'ai', 'openai', self::t('Use your own key for troubleshooting and automation.'), ['Agents', 'Workflows']),
            $entry('openrouter', 'OpenRouter', 'ai', null, self::t('Pick from many AI models with one key.'), ['Agents'], 'OR'),
            $entry('ollama', 'Ollama', 'ai', 'ollama', self::t('Run agents on models you host yourself.'), ['Agents']),
            $entry('n8n', 'n8n', 'ai', 'n8n', self::t('Start n8n workflows from ServerOS.'), ['Workflows']),
            $entry('grafana', 'Grafana', 'ai', 'grafana', self::t('Send machine metrics to your Grafana dashboards.'), ['Metrics']),
        ];
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
