<?php

namespace App\Support\Integrations;

use App\Models\BackupDestination;
use App\Models\ContainerRegistry;
use App\Models\GitHubInstallation;
use App\Models\Integration;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workflow;
use App\Models\Workspace;
use App\Models\WorkspaceAiKey;
use App\Support\Ai\AiProviders;
use App\Support\Edition;
use App\Support\GitHub\GitHubApp;
use App\Support\Machines\CloudflareTunnel;
use App\Support\Monitors\TelegramAlerts;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * @phpstan-type Target array{href?: string, alerts?: bool}
 * @phpstan-type Connection array{key: string, name: string, logo: string|null, detail: string, target: Target|null, failing?: bool}
 * @phpstan-type Account array{id: int, label: string, href: string, detail: string, failing: bool}
 * @phpstan-type State array{connected: bool, detail: string|null, manage: Target|null, setup: Target|null, ability: string, hint?: array{text: string, href: string|null, label: string|null}, connect?: string, accounts?: list<Account>}
 */
final class WorkspaceIntegrations
{
    public const string MACHINES = 'manageMachines';

    public const string SETTINGS = 'manageSettings';

    private const array S3_PROVIDERS = [
        ['/r2\.cloudflarestorage\.com/', 'r2', 'Cloudflare R2', 'cloudflare'],
        ['/backblazeb2\.com/', 'backblaze', 'Backblaze B2', 'backblaze'],
        ['/amazonaws\.com/', null, 'Amazon S3', 'aws'],
        ['/digitaloceanspaces\.com/', null, 'DigitalOcean Spaces', 'digitalocean'],
        ['/hetzner|your-objectstorage\.com/', null, 'Hetzner Object Storage', 'hetzner'],
        ['/wasabisys\.com/', null, 'Wasabi', 'wasabi'],
        ['/vultrobjects\.com/', null, 'Vultr Object Storage', 'vultr'],
        ['/linodeobjects\.com/', null, 'Linode Object Storage', 'linode'],
    ];

    private Workspace $workspace;

    private User $user;

    private bool $machines;

    /** @var list<Connection> */
    private array $extras = [];

    /**
     * @return array{integrations: list<array<string, mixed>>, connections: list<Connection>, canConnect: bool, providers: array<string, array<string, mixed>>}
     */
    public function props(Workspace $workspace, User $user): array
    {
        $this->workspace = $workspace;
        $this->user = $user;
        $this->machines = Gate::forUser($user)->allows(self::MACHINES, $workspace);
        $this->extras = [];

        $states = [
            'github' => $this->github(),
            'cloudflare' => $this->cloudflare(),
            'docker-hub' => $this->registries(),
            ...$this->chat(),
            'pagerduty' => $this->pagerDuty(),
            ...$this->storage(),
            ...$this->ai(),
        ];
        foreach ($this->accounts() as $key => $state) {
            $states[$key] = isset($states[$key]) ? $this->besidePanelState($key, $states[$key], $state) : $state;
        }

        $canConnect = $this->machines || Gate::forUser($user)->allows(self::SETTINGS, $workspace);
        $integrations = [];
        $connections = [];

        foreach (IntegrationCatalogue::all() as $entry) {
            $state = $states[$entry['key']] ?? null;
            $allowed = $state !== null && Gate::forUser($user)->allows($state['ability'], $workspace);
            $connected = $state !== null && $state['connected'];

            $integrations[] = [
                ...$entry,
                'connected' => $connected,
                'action' => $this->action($state, $connected, $allowed, $canConnect),
            ];

            if ($connected && isset($state['accounts'])) {
                foreach ($state['accounts'] as $account) {
                    $connections[] = [
                        'key' => 'integration-'.$account['id'],
                        'name' => $account['label'],
                        'logo' => $entry['logo'],
                        'detail' => $account['detail'],
                        'target' => $allowed ? ['href' => $account['href']] : null,
                        'failing' => $account['failing'],
                    ];
                }
            } elseif ($connected) {
                $connections[] = [
                    'key' => $entry['key'],
                    'name' => $entry['name'],
                    'logo' => $entry['logo'],
                    'detail' => (string) $state['detail'],
                    'target' => $allowed ? $state['manage'] : null,
                ];
            }
        }

        $providers = [];
        if ($this->machines) {
            foreach (app(IntegrationProviders::class)->all() as $key => $provider) {
                $providers[$key] = $provider->toArray();
            }
        }

        return [
            'integrations' => $integrations,
            'connections' => [...$connections, ...$this->extras],
            'canConnect' => $canConnect,
            'providers' => $providers,
        ];
    }

    /**
     * @param  State|null  $state
     * @return array<string, mixed>|null
     */
    private function action(?array $state, bool $connected, bool $allowed, bool $canConnect): ?array
    {
        if (isset($state['connect'])) {
            $accounts = $state['accounts'] ?? [];

            return match (true) {
                ! $allowed => null,
                $accounts === [] => ['label' => self::t('Connect'), 'connect' => $state['connect']],
                count($accounts) === 1 => ['label' => self::t('Manage'), 'href' => $accounts[0]['href']],
                default => ['label' => self::t('Manage'), 'connect' => $state['connect'], 'accounts' => array_map(fn (array $a) => ['id' => $a['id'], 'label' => $a['label'], 'href' => $a['href']], $accounts)],
            };
        }
        if ($state === null || ($state['setup'] === null && ! $connected)) {
            return $canConnect ? ['label' => self::t('Connect'), 'unavailable' => true, ...(isset($state['hint']) ? ['hint' => $state['hint']] : [])] : null;
        }
        if (! $allowed) {
            return null;
        }
        $target = $connected ? $state['manage'] : $state['setup'];

        return $target === null ? null : ['label' => $connected ? self::t('Manage') : self::t('Connect'), ...$target];
    }

    /**
     * A built provider whose catalogue key the panel already uses another
     * way (Cloudflare tunnels): its accounts take the row, and what the
     * panel does stays in the Connected list beside them.
     *
     * @param  State  $panel
     * @param  State  $accounts
     * @return State
     */
    private function besidePanelState(string $key, array $panel, array $accounts): array
    {
        if ($panel['connected']) {
            $entry = collect(IntegrationCatalogue::all())->firstWhere('key', $key);
            $this->extras[] = [
                'key' => $key,
                'name' => $entry['name'] ?? $key,
                'logo' => $entry['logo'] ?? null,
                'detail' => (string) $panel['detail'],
                'target' => Gate::forUser($this->user)->allows($panel['ability'], $this->workspace) ? $panel['manage'] : null,
            ];
        }

        return [...$accounts, 'connected' => $accounts['connected'] || $panel['connected']];
    }

    /**
     * The accounts connected through a built provider (Hetzner Cloud…).
     *
     * @return array<string, State>
     */
    private function accounts(): array
    {
        $connected = $this->workspace->integrations()->orderBy('created_at')->orderBy('id')->get()->groupBy('provider');
        $states = [];

        foreach (app(IntegrationProviders::class)->all() as $key => $provider) {
            $accounts = array_values($connected->get($key, collect())->map(fn (Integration $integration) => [
                'id' => $integration->id,
                'label' => $integration->label,
                'href' => '/integrations/'.$integration->id,
                'detail' => $integration->status === Integration::FAILING
                    ? self::t('Needs a new token')
                    : $provider->summary($integration),
                'failing' => $integration->status === Integration::FAILING,
            ])->all());
            $first = $accounts[0] ?? null;

            $states[$key] = [
                'connected' => $first !== null,
                'detail' => $first['detail'] ?? null,
                'manage' => $first === null ? null : ['href' => $first['href']],
                'setup' => null,
                'ability' => self::MACHINES,
                'connect' => $key,
                'accounts' => $accounts,
            ];
        }

        return $states;
    }

    /** @return State */
    private function github(): array
    {
        $installations = $this->workspace->githubInstallations()->orderBy('account_login')->get();
        $first = $installations->first();
        $detail = null;

        if ($first instanceof GitHubInstallation) {
            $repositories = $first->repository_ids === null ? null : count($first->repository_ids);
            $reach = match ($repositories) {
                null => self::t('all repositories'),
                1 => self::t('1 repository'),
                default => self::t(':count repositories', ['count' => $repositories]),
            };
            $others = $installations->count() - 1;
            $detail = match ($others) {
                0 => "{$first->account_login} · {$reach}",
                1 => self::t(':name and 1 more account', ['name' => $first->account_login]),
                default => self::t(':name and :count more accounts', ['name' => $first->account_login, 'count' => $others]),
            };
        }

        $setup = match (true) {
            GitHubApp::enabled() => ['href' => '/settings/github/connect'],
            Edition::selfHosted() && Edition::installAdmin($this->user, $this->workspace) => ['href' => '/settings/github/app'],
            default => null,
        };

        return [
            'connected' => $first !== null,
            'detail' => $detail,
            'manage' => ['href' => '/settings/general#github'],
            'setup' => $setup,
            'ability' => self::MACHINES,
            ...($setup === null && Edition::selfHosted() ? ['hint' => [
                'text' => self::t('Whoever runs this ServerOS install can set up the GitHub App first.'),
                'href' => null,
                'label' => null,
            ]] : []),
        ];
    }

    /** @return State */
    private function cloudflare(): array
    {
        $tunnelled = $this->workspace->machines()->whereNotNull('tunnel')->orderBy('name')->get(['id', 'name', 'tunnel'])
            ->filter(fn (Machine $machine) => ($machine->tunnel['provider'] ?? 'cloudflare') === 'cloudflare')
            ->values();
        $first = $tunnelled->first();

        return [
            'connected' => $first !== null,
            'detail' => $first === null ? null : ($tunnelled->count() === 1
                ? self::t('Tunnel on :machine', ['machine' => $first->name])
                : self::t('Tunnels on :count machines', ['count' => $tunnelled->count()])),
            'manage' => ['href' => $tunnelled->count() === 1 && $first !== null ? "/machines/{$first->id}/settings" : '/machines'],
            'setup' => null,
            'ability' => self::MACHINES,
            ...(CloudflareTunnel::offered() ? ['hint' => [
                'text' => self::t('You can already serve a machine’s apps through a Cloudflare Tunnel from its settings.'),
                'href' => '/machines',
                'label' => self::t('Open machines'),
            ]] : []),
        ];
    }

    /** @return State */
    private function registries(): array
    {
        $registries = $this->workspace->containerRegistries()->orderBy('host')->get();
        $hub = $registries->firstWhere('host', ContainerRegistry::DOCKER_HUB);
        $signedIn = fn (ContainerRegistry $registry): string => $this->machines
            ? self::t('Signed in as :name', ['name' => $registry->username])
            : self::t('Private images');

        foreach ($registries as $registry) {
            if ($registry->host !== ContainerRegistry::DOCKER_HUB) {
                $this->extras[] = [
                    'key' => 'registry-'.$registry->id,
                    'name' => $registry->label(),
                    'logo' => $registry->host === 'ghcr.io' ? 'github' : 'docker',
                    'detail' => $signedIn($registry),
                    'target' => $this->machines ? ['href' => '/settings/general#registries'] : null,
                ];
            }
        }

        return [
            'connected' => $hub instanceof ContainerRegistry,
            'detail' => $hub instanceof ContainerRegistry ? $signedIn($hub) : null,
            'manage' => ['href' => '/settings/general#registries'],
            'setup' => ['href' => '/settings/general#registries'],
            'ability' => self::MACHINES,
        ];
    }

    /** @return array{slack: State, discord: State, telegram: State} */
    private function chat(): array
    {
        $url = is_string($this->workspace->alert_webhook_url) && $this->workspace->alert_webhook_url !== '' ? $this->workspace->alert_webhook_url : null;
        $host = $url === null ? null : strtolower((string) parse_url($url, PHP_URL_HOST));
        $brand = $host === null ? null : self::chatBrand($url);
        $workflows = $this->workflowsCalling(fn (string $url) => self::chatBrand($url));
        $alerts = ['alerts' => true];

        $state = function (string $brand, ?string $webhookBrand) use ($workflows, $alerts): array {
            $using = $workflows->get($brand, collect());
            $viaAlerts = $webhookBrand === $brand;

            return [
                'connected' => $viaAlerts || $using->isNotEmpty(),
                'detail' => $this->usedBy($viaAlerts ? self::t('Alerts to your channel') : null, $using),
                'manage' => $viaAlerts || $using->isEmpty() ? $alerts : $this->workflowTarget($using),
                'setup' => $alerts,
                'ability' => self::MACHINES,
            ];
        };

        if ($url !== null && $brand === null) {
            $this->extras[] = [
                'key' => 'webhook',
                'name' => self::t('Webhook'),
                'logo' => null,
                'detail' => $this->machines ? self::t('Alerts to :host', ['host' => $host]) : self::t('Alerts'),
                'target' => $this->machines ? $alerts : null,
            ];
        }

        $telegram = TelegramAlerts::configured($this->workspace);

        return [
            'slack' => $state('slack', $brand),
            'discord' => $state('discord', $brand),
            'telegram' => [
                'connected' => $telegram,
                'detail' => $telegram ? ($this->machines ? self::t('Alerts to :chat', ['chat' => (string) $this->workspace->alert_telegram_chat]) : self::t('Alerts')) : null,
                'manage' => $alerts,
                'setup' => $alerts,
                'ability' => self::MACHINES,
            ],
        ];
    }

    /** @return State */
    private function pagerDuty(): array
    {
        $using = $this->workflowsCalling(
            fn (string $url, string $body) => strtolower((string) parse_url($url, PHP_URL_HOST)) === 'events.pagerduty.com' && ! str_contains($body, 'YOUR_ROUTING_KEY') ? 'pagerduty' : null,
        )->get('pagerduty', collect());

        return [
            'connected' => $using->isNotEmpty(),
            'detail' => $this->usedBy(null, $using),
            'manage' => $this->workflowTarget($using),
            'setup' => ['href' => '/workflows?new'],
            'ability' => self::MACHINES,
        ];
    }

    /** @return array{backblaze: State, r2: State} */
    private function storage(): array
    {
        $destinations = $this->workspace->backupDestinations()
            ->with(['machines' => fn ($query) => $query->orderBy('name'), 'machine:id,name,provider'])
            ->orderBy('name')
            ->get();
        $known = ['backblaze' => collect(), 'r2' => collect()];

        foreach ($destinations as $destination) {
            [$catalogue, $label, $logo] = $this->storageKind($destination);
            if ($catalogue !== null) {
                $known[$catalogue]->push($destination);

                continue;
            }
            $this->extras[] = [
                'key' => 'backups-'.$destination->id,
                'name' => $label,
                'logo' => $logo,
                'detail' => $this->backupsOf($destination->machines),
                'target' => $this->machines ? ['href' => '/backups/destinations'] : null,
            ];
        }

        $state = fn (Collection $using): array => [
            'connected' => $using->isNotEmpty(),
            'detail' => $using->isEmpty() ? null : $this->backupsOf($using->flatMap(fn (BackupDestination $d) => $d->machines)->unique('id')->values()),
            'manage' => ['href' => '/backups/destinations'],
            'setup' => ['href' => '/backups/destinations'],
            'ability' => self::MACHINES,
        ];

        return ['backblaze' => $state($known['backblaze']), 'r2' => $state($known['r2'])];
    }

    /** @return array{anthropic: State, openai: State, openrouter: State, ollama: State} */
    private function ai(): array
    {
        $keys = WorkspaceAiKey::query()->where('workspace_id', $this->workspace->id)->get()->keyBy('provider');
        $agents = collect(AiProviders::PREFERENCE)->first(fn (string $provider) => $keys->has($provider));
        $settings = ['href' => '/settings/mcp'];
        $detail = fn (string $provider): string => $agents === $provider ? self::t('Agents use your key') : self::t('Used when you pick its models');
        $custom = $keys->get('custom');
        $customKind = $custom instanceof WorkspaceAiKey ? self::compatibleKind((string) $custom->base_url) : null;

        if ($custom instanceof WorkspaceAiKey && $customKind === null) {
            $this->extras[] = [
                'key' => 'ai-custom',
                'name' => self::t('OpenAI-compatible'),
                'logo' => null,
                'detail' => $detail('custom'),
                'target' => Gate::forUser($this->user)->allows(self::SETTINGS, $this->workspace) ? $settings : null,
            ];
        }

        $state = fn (bool $connected, string $provider): array => [
            'connected' => $connected,
            'detail' => $connected ? $detail($provider) : null,
            'manage' => $settings,
            'setup' => $settings,
            'ability' => self::SETTINGS,
        ];

        return [
            'anthropic' => $state($keys->has('anthropic'), 'anthropic'),
            'openai' => $state($keys->has('openai'), 'openai'),
            'openrouter' => $state($customKind === 'openrouter', 'custom'),
            'ollama' => $state($customKind === 'ollama', 'custom'),
        ];
    }

    public static function chatBrand(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            $host === 'hooks.slack.com' => 'slack',
            preg_match('/(^|\.)discord(app)?\.com$/', $host) === 1 => 'discord',
            default => null,
        };
    }

    public static function compatibleKind(string $url): ?string
    {
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return match (true) {
            $host === 'openrouter.ai' || str_ends_with($host, '.openrouter.ai') => 'openrouter',
            str_contains($host, 'ollama') || parse_url($url, PHP_URL_PORT) === 11434 => 'ollama',
            default => null,
        };
    }

    /** @return array{0: string|null, 1: string, 2: string|null} */
    private function storageKind(BackupDestination $destination): array
    {
        if ($destination->type === 'machine') {
            return [null, self::t(':machine over SFTP', ['machine' => $destination->machine->name ?? $destination->name]), $destination->machine?->provider];
        }
        $endpoint = strtolower((string) ($destination->settings['endpoint'] ?? ''));

        foreach (self::S3_PROVIDERS as [$pattern, $catalogue, $label, $logo]) {
            if (preg_match($pattern, $endpoint) === 1) {
                return [$catalogue, $label, $logo];
            }
        }

        return [null, $destination->name, null];
    }

    /** @param  Collection<int, Machine>  $machines */
    private function backupsOf(Collection $machines): string
    {
        $first = $machines->first();
        if (! $first instanceof Machine) {
            return self::t('No machine backs up here yet');
        }
        if ($machines->count() > 1) {
            return self::t('Backups of :machine and :count more', ['machine' => $first->name, 'count' => $machines->count() - 1]);
        }

        return match ($first->backup_schedule) {
            'daily' => self::t('Daily backups of :machine', ['machine' => $first->name]),
            'weekly' => self::t('Weekly backups of :machine', ['machine' => $first->name]),
            default => self::t('Backups of :machine', ['machine' => $first->name]),
        };
    }

    /**
     * @param  callable(string, string): (string|null)  $brand
     * @return Collection<string, Collection<int, Workflow>>
     */
    private function workflowsCalling(callable $brand): Collection
    {
        $found = [];

        $workflows = $this->workspace->workflows()->where('internal', false)->where('enabled', true)->orderBy('name')->get(['id', 'name', 'graph']);
        foreach ($workflows as $workflow) {
            foreach ($workflow->graph['nodes'] ?? [] as $node) {
                $rules = (array) ($node['rules'] ?? []);
                if (($node['kind'] ?? null) !== 'http' || ! is_string($rules['url'] ?? null) || $rules['url'] === '') {
                    continue;
                }
                $key = $brand($rules['url'], (string) ($rules['body'] ?? ''));
                if ($key !== null) {
                    $found[$key][$workflow->id] = $workflow;
                }
            }
        }

        return collect($found)->map(fn (array $workflows) => collect(array_values($workflows)));
    }

    /** @param  Collection<int, Workflow>  $workflows */
    private function usedBy(?string $alerts, Collection $workflows): ?string
    {
        $count = $workflows->count();

        return match (true) {
            $alerts !== null && $count === 0 => $alerts,
            $alerts !== null && $count === 1 => self::t('Alerts and 1 workflow'),
            $alerts !== null => self::t('Alerts and :count workflows', ['count' => $count]),
            $count === 1 => self::t('Used by :name', ['name' => (string) $workflows->first()?->name]),
            $count > 1 => self::t('Used by :count workflows', ['count' => $count]),
            default => null,
        };
    }

    /**
     * @param  Collection<int, Workflow>  $workflows
     * @return Target
     */
    private function workflowTarget(Collection $workflows): array
    {
        $first = $workflows->first();

        return ['href' => $workflows->count() === 1 && $first !== null ? "/workflows/{$first->id}" : '/workflows'];
    }

    /** @param  array<string, string|int>  $replace */
    private static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
