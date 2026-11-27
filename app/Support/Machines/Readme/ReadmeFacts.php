<?php

namespace App\Support\Machines\Readme;

use App\Models\AgentFix;
use App\Models\Backup;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\MachineSshAccess;
use App\Models\MachineVulnerability;
use App\Models\Monitor;
use App\Models\ProjectLink;
use App\Models\ProjectMachine;
use App\Models\ProjectNode;
use App\Models\Vulnerability;
use App\Support\Code\WorkspaceExport;
use App\Support\Deploys\DeployOnPush;
use App\Support\Deploys\ServiceLabels;
use App\Support\Firewall;
use App\Support\Health\WorkspaceHealth;
use App\Support\Machines\RegionPlaces;
use App\Support\Projects\Placement;
use App\Support\Projects\ProjectFacts;
use App\Support\Traffic\ExistingSites;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * @phpstan-import-type Site from ExistingSites
 *
 * @phpstan-type Service array{id: string, key: string|null, name: string, kind: string, how: string, ports: list<int>, status: string, logo: string|null, iconUrl: string|null, image: string|null, app: string|null, framework: string|null, repo: string|null, branch: string|null, deploysOnPush: bool, domains: list<string>, project: string|null, roots: list<string>}
 * @phpstan-type Chip array{id: string, name: string, logo: string|null, port: int|null, note: string|null}
 * @phpstan-type Entry array{id: string, name: string, logo: string|null, port: int|null, note: string|null, state: string}
 * @phpstan-type App array{id: string, name: string, logo: string|null, port: int|null, note: string|null, routed: bool}
 * @phpstan-type Dep array{id: string, name: string, logo: string|null, port: int|null, note: string|null, machine: array{id: int, name: string}|null, from: list<string>, private: bool}
 * @phpstan-type Wiring array{entry: Entry|null, apps: list<App>, deps: list<Dep>}
 * @phpstan-type DataRow array{id: string, what: string, where: string, size: int|null, tone: string, backup: string}
 * @phpstan-type Risk array{id: string, severity: string, title: string, detail: string, action: string|null, href: string|null, kind: string, ask: string|null}
 * @phpstan-type Good array{id: string, text: string}
 * @phpstan-type About array{id: int, name: string, provider: string|null, plan: string|null, region: string|null, city: string|null, country: string|null, os: string|null, cpus: int|null, memory: int|null, environment: string, bootedAt: string|null, upDays: int|null, proxy: string|null, tunnel: bool}
 * @phpstan-type Facts array{machine: About, sites: list<string>, services: list<Service>, wiring: Wiring, open: list<int>|null, data: list<DataRow>, risks: list<Risk>, good: list<Good>}
 */
final class ReadmeFacts
{
    private const array HOW = [
        'systemd' => 'system', 'docker' => 'docker', 'compose' => 'docker', 'pm2' => 'pm2',
        'supervisor' => 'supervisor', 'screen' => 'screen', 'cron' => 'cron', 'manual' => 'manual',
    ];

    private const array WEB_KINDS = ['proxy', 'web_server'];

    private const int MAX_RISKS_PER_SOURCE = 3;

    public function __construct(private readonly WorkspaceHealth $health, private readonly ProjectFacts $projects) {}

    /** @return Facts */
    public function build(Machine $machine): array
    {
        $machine->loadMissing(['workspace', 'backupDestination']);
        $deploys = $this->deploys($machine);
        $sites = ExistingSites::all($machine);
        $services = $this->services($machine, $deploys, $sites);
        $wiring = $this->wiring($machine, $services, $sites, $deploys);

        $order = array_flip(array_values(array_unique(array_merge(
            $wiring['entry'] === null ? [] : [$wiring['entry']['id']],
            array_column(array_filter($wiring['apps'], fn (array $a) => $a['routed']), 'id'),
            array_column($wiring['apps'], 'id'),
            array_column($wiring['deps'], 'id'),
        ))));
        usort($services, fn (array $a, array $b) => [$order[$a['id']] ?? PHP_INT_MAX, $a['kind'] === 'site' ? 1 : 0, strtolower($a['name'])]
            <=> [$order[$b['id']] ?? PHP_INT_MAX, $b['kind'] === 'site' ? 1 : 0, strtolower($b['name'])]);

        return [
            'machine' => $this->about($machine),
            'sites' => $this->siteNames($deploys, $sites),
            'services' => $services,
            'wiring' => $wiring,
            'open' => $this->open($machine),
            'data' => $this->data($machine, $services, $wiring),
            'risks' => $this->risks($machine),
            'good' => $this->good($machine),
        ];
    }

    /** @return About */
    private function about(Machine $machine): array
    {
        $facts = is_array($machine->facts) ? $machine->facts : [];
        $hosting = is_array($facts['hosting'] ?? null) ? $facts['hosting'] : [];
        $region = $machine->region ?: (is_string($hosting['region'] ?? null) ? $hosting['region'] : null);
        $place = RegionPlaces::of($region, is_string($hosting['zone'] ?? null) ? $hosting['zone'] : null);
        $booted = is_numeric($facts['boot_ts'] ?? null) && (int) $facts['boot_ts'] > 0 ? CarbonImmutable::createFromTimestamp((int) $facts['boot_ts']) : null;
        $provider = $machine->provider !== '' && $machine->provider !== 'Other' ? $machine->provider : null;

        return [
            'id' => $machine->id,
            'name' => $machine->name,
            'provider' => $provider,
            'plan' => $machine->tier ?: (is_string($hosting['instance_type'] ?? null) && $hosting['instance_type'] !== '' ? $hosting['instance_type'] : null),
            'region' => $region,
            'city' => $place['city'] ?? null,
            'country' => $place['country'] ?? null,
            'os' => self::system($machine),
            'cpus' => is_int($facts['cpu_cores'] ?? null) ? $facts['cpu_cores'] : null,
            'memory' => is_int($facts['memory_bytes'] ?? null) ? $facts['memory_bytes'] : null,
            'environment' => $machine->environment->value,
            'bootedAt' => $booted?->toIso8601String(),
            'upDays' => $booted === null || $booted->isFuture() ? null : (int) floor($booted->diffInDays(now())),
            'proxy' => $machine->proxy,
            'tunnel' => ($machine->tunnel['state'] ?? null) === 'running',
        ];
    }

    public static function system(Machine $machine): ?string
    {
        $release = $machine->os_release ?? [];
        $facts = is_array($machine->facts) ? $machine->facts : [];
        $name = is_string($facts['os'] ?? null) && $facts['os'] !== '' ? $facts['os'] : null;
        $version = is_string($release['version_id'] ?? null) && $release['version_id'] !== '' ? $release['version_id']
            : (is_string($facts['os_version'] ?? null) ? (string) preg_replace('/^(\d+(?:\.\d+)?).*$/', '$1', $facts['os_version']) : null);
        if ($name === null) {
            return is_string($release['pretty_name'] ?? null) && $release['pretty_name'] !== '' ? $release['pretty_name'] : null;
        }

        return trim($name.' '.($version ?? ''));
    }

    /** @return array<string, DaemonJob> */
    private function deploys(Machine $machine): array
    {
        $found = [];
        $jobs = DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'deploy')
            ->where('state', 'succeeded')
            ->latest()
            ->limit(200)
            ->get(['id', 'service', 'command', 'created_at']);
        foreach ($jobs as $job) {
            if (is_string($job->service) && ! isset($found[$job->service])) {
                $found[$job->service] = $job;
            }
        }

        return $found;
    }

    /**
     * @param  array<string, DaemonJob>  $deploys
     * @param  list<Site>  $sites
     * @return list<Service>
     */
    private function services(Machine $machine, array $deploys, array $sites): array
    {
        $projects = ProjectNode::query()->where('machine_id', $machine->id)->with('project:id,name')->get()->keyBy('name');
        $rows = [];
        foreach (WorkspaceExport::services($machine) as $service) {
            $details = is_array($service['details'] ?? null) ? $service['details'] : [];
            $app = is_string($service['app'] ?? null) ? $service['app'] : null;
            $compose = is_string($details['compose_project'] ?? null) ? $details['compose_project'] : null;
            $node = $app !== null ? $projects->get($app) : ($compose !== null && str_starts_with($compose, 'serveros-') ? $projects->get(substr($compose, 9)) : null);
            $deploy = $app !== null ? ($deploys[$app] ?? null) : null;
            $job = is_array($deploy?->command['job'] ?? null) ? $deploy->command['job'] : [];
            $ports = self::ports($service['ports'] ?? []);
            if ($ports === [] && is_numeric($job['port'] ?? null)) {
                $ports = [(int) $job['port']];
            }
            if ($ports === [] && $node !== null && ! $node->isApp() && ($port = Placement::latestDeploy($machine, $node->name)?->command['job']['port'] ?? null) !== null && is_numeric($port)) {
                $ports = [(int) $port];
            }
            $push = $app !== null ? DeployOnPush::setting($machine, $app) : null;
            $framework = $deploy !== null ? ($this->projects->kindOf($deploy)['slug'] ?? null) : null;
            $image = is_string($details['image'] ?? null) && $details['image'] !== '' ? $details['image'] : null;
            $roots = [];
            foreach ($sites as $site) {
                if (ExistingSites::ties($service, $site)) {
                    array_push($roots, ...$site['roots']);
                }
            }

            $rows[] = [
                'id' => ServiceLabels::identity($service),
                'key' => (string) $service['key'],
                'name' => ServiceLabels::for($machine, $service) ?? ($node !== null && $app === null ? $node->name : ServiceLabels::display($machine, $service)),
                'kind' => $node->kind ?? ($app !== null ? 'app' : (string) ($service['kind'] ?? 'other')),
                'how' => self::HOW[(string) ($service['manager'] ?? '')] ?? 'other',
                'ports' => $ports,
                'status' => (string) ($service['status'] ?? 'unknown'),
                'logo' => $framework ?? $node->template ?? $image ?? ServiceLabels::display($machine, $service),
                'iconUrl' => is_string($service['icon'] ?? null) ? $service['icon'] : null,
                'image' => $image,
                'app' => $app,
                'framework' => $framework,
                'repo' => DeployOnPush::repoName(is_string($job['repo'] ?? null) ? $job['repo'] : null),
                'branch' => $push !== null && $push['on'] ? $push['branch'] : null,
                'deploysOnPush' => $push !== null && $push['on'],
                'domains' => self::domains($job['domains'] ?? []),
                'project' => $node?->project?->name,
                'roots' => array_values(array_unique($roots)),
            ];
        }

        foreach ($sites as $site) {
            if ($site['server'] === 'log' || $site['roots'] === [] || $site['ports'] !== [] || $site['apps'] !== [] || $site['sockets'] !== [] || $site['catchAll']) {
                continue;
            }
            $served = collect(WorkspaceExport::services($machine))->contains(fn (array $service) => ExistingSites::ties($service, $site));
            if ($served) {
                continue;
            }
            $rows[] = [
                'id' => 'site:'.$site['site'],
                'key' => null,
                'name' => $site['names'][0] ?? $site['site'],
                'kind' => 'site',
                'how' => 'site:'.$site['server'],
                'ports' => [],
                'status' => 'running',
                'logo' => null,
                'iconUrl' => null,
                'image' => null,
                'app' => null,
                'framework' => null,
                'repo' => null,
                'branch' => null,
                'deploysOnPush' => false,
                'domains' => array_values(array_filter($site['names'], fn (string $name) => self::isDomain($name))),
                'project' => null,
                'roots' => $site['roots'],
            ];
        }

        return $rows;
    }

    /**
     * @param  list<Service>  $services
     * @param  list<Site>  $sites
     * @param  array<string, DaemonJob>  $deploys
     * @return Wiring
     */
    private function wiring(Machine $machine, array $services, array $sites, array $deploys): array
    {
        $byId = collect($services)->keyBy('id');
        $raw = collect(WorkspaceExport::services($machine))->keyBy(fn (array $service) => ServiceLabels::identity($service));
        $web = collect($services)->filter(fn (array $s) => $s['kind'] !== 'site' && (in_array($s['kind'], self::WEB_KINDS, true) || ExistingSites::serverOf($raw->get($s['id']) ?? []) !== null));
        $entry = $web
            ->filter(fn (array $s) => array_intersect($s['ports'], [80, 443]) !== [] || collect($sites)->contains(fn (array $site) => ExistingSites::serves($raw->get($s['id']) ?? [], $site)))
            ->sortBy(fn (array $s) => [
                $machine->proxy !== null && str_contains(strtolower($s['name'].' '.$s['key']), $machine->proxy) ? 0 : 1,
                in_array(443, $s['ports'], true) ? 0 : 1,
                $s['status'] === 'running' ? 0 : 1,
            ])
            ->first();

        $apps = [];
        $routed = [];
        if ($entry !== null) {
            foreach ($services as $service) {
                if ($service['id'] === $entry['id'] || $service['kind'] === 'site' && ! str_starts_with($service['how'], 'site:')) {
                    continue;
                }
                $tied = $service['kind'] === 'site'
                    ? true
                    : ($service['app'] !== null && $service['domains'] !== []) || collect($sites)->contains(fn (array $site) => ExistingSites::serves($raw->get($entry['id']) ?? [], $site) && ExistingSites::ties($raw->get($service['id']) ?? [], $site));
                if ($tied) {
                    $routed[$service['id']] = true;
                }
            }
        }

        $links = $this->links($machine);
        $linkedApps = collect($links)->pluck('app')->unique()->all();
        foreach ($services as $service) {
            if ($entry !== null && $service['id'] === $entry['id']) {
                continue;
            }
            $isRouted = isset($routed[$service['id']]);
            $linked = in_array($service['id'], $linkedApps, true);
            if (! $isRouted && ! $linked) {
                continue;
            }
            $apps[] = [
                'id' => $service['id'],
                'name' => $service['name'],
                'logo' => $service['kind'] === 'site' ? null : $service['logo'],
                'port' => $service['kind'] === 'site' ? null : ($service['ports'][0] ?? null),
                'note' => $service['kind'] === 'site' ? 'static' : null,
                'routed' => $isRouted,
            ];
        }

        $appIds = array_column($apps, 'id');
        $deps = [];
        foreach ($links as $link) {
            if (! in_array($link['app'], $appIds, true) || in_array($link['id'], $appIds, true)) {
                continue;
            }
            if (isset($deps[$link['id']])) {
                $deps[$link['id']]['from'][] = $link['app'];

                continue;
            }
            $local = $link['machine'] === null ? $byId->get($link['id']) : null;
            $deps[$link['id']] = [
                'id' => $link['id'],
                'name' => $local['name'] ?? $link['name'],
                'logo' => $local['logo'] ?? $link['logo'],
                'port' => $local['ports'][0] ?? $link['port'],
                'note' => null,
                'machine' => $link['machine'],
                'from' => [$link['app']],
                'private' => $link['private'],
            ];
        }
        uasort($deps, fn (array $a, array $b) => [$a['machine'] === null ? 0 : 1, $a['name']] <=> [$b['machine'] === null ? 0 : 1, $b['name']]);

        $state = 'live';
        if ($entry !== null && $entry['status'] !== 'running') {
            $state = 'down';
        }

        return [
            'entry' => $entry === null ? null : [
                'id' => $entry['id'],
                'name' => $entry['name'],
                'logo' => $entry['logo'],
                'port' => in_array(443, $entry['ports'], true) ? 443 : ($entry['ports'][0] ?? null),
                'note' => null,
                'state' => $state,
            ],
            'apps' => $apps,
            'deps' => array_values(array_map(fn (array $dep) => [...$dep, 'from' => array_values(array_unique($dep['from']))], $deps)),
        ];
    }

    /** @return list<array{app: string, id: string, name: string, logo: string|null, port: int|null, machine: array{id: int, name: string}|null, private: bool}> */
    private function links(Machine $machine): array
    {
        $apps = ProjectNode::query()->where('machine_id', $machine->id)->where('kind', 'app')->get();
        if ($apps->isEmpty()) {
            return [];
        }
        $links = ProjectLink::query()->whereIn('app_node_id', $apps->modelKeys())->with(['resource.machine', 'app'])->get();
        $networks = ProjectMachine::query()->whereIn('project_id', $links->pluck('project_id')->unique())->where('state', 'up')->get(['project_id', 'machine_id']);

        $found = [];
        foreach ($links as $link) {
            $resource = $link->resource;
            if ($resource->machine->workspace_id !== $machine->workspace_id) {
                continue;
            }
            $appId = $this->nodeService($machine, $link->app);
            if ($appId === null) {
                continue;
            }
            $here = $resource->machine_id === $machine->id;
            $id = $here ? ($this->nodeService($machine, $resource) ?? 'node:'.$resource->id) : 'node:'.$resource->id;
            $port = Placement::latestDeploy($resource->machine, $resource->name)?->command['job']['port'] ?? null;
            $found[] = [
                'app' => $appId,
                'id' => $id,
                'name' => $resource->name,
                'logo' => $resource->template ?? $resource->kind,
                'port' => is_numeric($port) ? (int) $port : null,
                'machine' => $here ? null : ['id' => $resource->machine_id, 'name' => $resource->machine->name],
                'private' => ! $here
                    && $networks->contains(fn (ProjectMachine $m) => $m->project_id === $link->project_id && $m->machine_id === $machine->id)
                    && $networks->contains(fn (ProjectMachine $m) => $m->project_id === $link->project_id && $m->machine_id === $resource->machine_id),
            ];
        }

        return $found;
    }

    private function nodeService(Machine $machine, ProjectNode $node): ?string
    {
        foreach (WorkspaceExport::services($machine) as $service) {
            if (($service['app'] ?? null) === $node->name) {
                return ServiceLabels::identity($service);
            }
        }
        $containers = Placement::containers($machine, $node->name);

        return $containers === [] ? null : ServiceLabels::identity($containers[0]);
    }

    /**
     * @param  array<string, DaemonJob>  $deploys
     * @param  list<Site>  $sites
     * @return list<string>
     */
    private function siteNames(array $deploys, array $sites): array
    {
        $names = [];
        foreach ($deploys as $job) {
            array_push($names, ...self::domains($job->command['job']['domains'] ?? []));
        }
        foreach ($sites as $site) {
            if (! $site['catchAll'] && $site['server'] !== 'log') {
                array_push($names, ...array_filter($site['names'], fn (string $name) => self::isDomain($name)));
            }
        }

        return array_values(array_unique(array_map('strtolower', $names)));
    }

    /** @return list<int>|null */
    private function open(Machine $machine): ?array
    {
        $view = Firewall::view($machine);
        if ($view === null || ($machine->inventory['listeners'] ?? []) === []) {
            return null;
        }
        $open = [];
        foreach ($view['ports'] as $port) {
            if (in_array($port['exposure'], Firewall::REACHABLE, true)) {
                $open[] = $port['port'];
            }
        }
        sort($open);

        return array_values(array_unique($open));
    }

    /**
     * @param  list<Service>  $services
     * @param  Wiring  $wiring
     * @return list<DataRow>
     */
    private function data(Machine $machine, array $services, array $wiring): array
    {
        $rows = [];
        $raw = collect(WorkspaceExport::services($machine))->keyBy(fn (array $service) => ServiceLabels::identity($service));
        $backups = Backup::query()
            ->where('machine_id', $machine->id)
            ->whereNotIn('trigger', ['pre-restore', 'pre-deploy'])
            ->whereIn('state', ['succeeded', 'failed'])
            ->latest('id')
            ->limit(500)
            ->get(['id', 'service', 'state', 'bytes', 'created_at', 'finished_at'])
            ->groupBy('service');
        $scheduled = $machine->backup_schedule !== 'off' && $machine->backup_destination_id !== null;
        $destination = $machine->backupDestination?->name;
        $covered = [];

        $volumes = collect($machine->volumes())->reject(fn (array $volume) => $volume['mount'] === '/')->keyBy('mount');
        foreach ($services as $service) {
            $source = $raw->get($service['id']);
            if ($source === null) {
                continue;
            }
            $details = is_array($source['details'] ?? null) ? $source['details'] : [];
            $mounts = self::mounts((string) ($details['mounts'] ?? ''));
            $dataDir = is_string($source['data_dir'] ?? null) && $source['data_dir'] !== '' ? $source['data_dir'] : null;
            if ($mounts === [] && $dataDir === null && ! in_array($service['kind'], ['database', 'cache', 'storage'], true)) {
                continue;
            }
            $last = $backups->get($service['key'])?->first();
            $backable = ($details['managed'] ?? null) === 'true' && ($details['backup'] ?? null) !== 'none';
            [$tone, $text] = match (true) {
                $last?->state === 'failed' => ['bad', self::t('The last backup failed')],
                $last !== null && $scheduled && $backable => ['good', $this->scheduleText($machine, $destination, $last)],
                $last !== null => ['good', self::t('Copied :time', ['time' => ($last->finished_at ?? $last->created_at)?->diffForHumans() ?? ''])],
                $service['kind'] === 'cache' => ['none', self::t('Not backed up, it’s a cache')],
                $scheduled && $backable => ['warn', self::t('Waiting for its first backup')],
                default => ['warn', self::t('Not backed up')],
            };
            $paths = $mounts !== [] ? $mounts : [$dataDir];
            foreach ($paths as $i => $path) {
                $volume = $path === null ? null : $volumes->first(fn (array $v) => $path === $v['mount'] || str_starts_with($path, rtrim($v['mount'], '/').'/'));
                if ($path !== null) {
                    $covered[] = $path;
                }
                $rows[] = [
                    'id' => $service['id'].($i > 0 ? '#'.$i : ''),
                    'what' => $volume !== null && $path === $volume['mount'] ? ucfirst(str_replace(['-', '_'], ' ', basename($volume['mount']))) : $service['name'],
                    'where' => $path ?? ($service['how'] === 'docker' ? self::t('Inside its container') : '–'),
                    'size' => $volume !== null && $path === $volume['mount'] ? $volume['used'] : (is_numeric($details['data_bytes'] ?? null) ? (int) $details['data_bytes'] : ($last?->state === 'succeeded' ? $last->bytes : null)),
                    'tone' => $tone,
                    'backup' => $text,
                ];
            }
        }

        foreach ($machine->volumes() as $volume) {
            if ($volume['mount'] === '/' || collect($covered)->contains(fn (string $path) => $path === $volume['mount'] || str_starts_with($path, rtrim($volume['mount'], '/').'/'))) {
                continue;
            }
            $rows[] = [
                'id' => 'volume:'.$volume['mount'],
                'what' => ucfirst(str_replace(['-', '_'], ' ', basename($volume['mount']))),
                'where' => $volume['mount'],
                'size' => $volume['used'],
                'tone' => 'warn',
                'backup' => self::t('Not in any backup'),
            ];
        }

        $machines = Machine::query()->whereIn('id', collect($wiring['deps'])->pluck('machine.id')->filter()->unique())->get()->keyBy('id');
        foreach ($wiring['deps'] as $dep) {
            $other = $dep['machine'] === null ? null : $machines->get($dep['machine']['id']);
            if ($other === null) {
                continue;
            }
            $backedUp = $other->backup_schedule !== 'off' && $other->backup_destination_id !== null;
            $rows[] = [
                'id' => $dep['id'],
                'what' => $dep['name'],
                'where' => self::t(':name on :machine', ['name' => $dep['name'], 'machine' => $other->name]),
                'size' => null,
                'tone' => $backedUp ? 'good' : 'warn',
                'backup' => $backedUp
                    ? self::t('With :machine’s backups', ['machine' => $other->name])
                    : self::t(':machine isn’t backed up', ['machine' => $other->name]),
            ];
        }

        foreach ($services as $service) {
            if ($service['repo'] === null || collect($rows)->contains('id', $service['id'])) {
                continue;
            }
            $rows[] = [
                'id' => $service['id'],
                'what' => $service['name'],
                'where' => $service['roots'][0] ?? $service['repo'],
                'size' => null,
                'tone' => 'good',
                'backup' => self::t('In git, :repo', ['repo' => $service['repo']]),
            ];
        }

        return $rows;
    }

    private function scheduleText(Machine $machine, ?string $destination, Backup $last): string
    {
        $when = ($last->finished_at ?? $last->created_at)?->diffForHumans() ?? '';

        return match ($machine->backup_schedule) {
            'weekly' => $destination === null ? self::t('Every week, last :time', ['time' => $when]) : self::t('Every week to :destination, last :time', ['destination' => $destination, 'time' => $when]),
            default => $destination === null ? self::t('Every day, last :time', ['time' => $when]) : self::t('Every day to :destination, last :time', ['destination' => $destination, 'time' => $when]),
        };
    }

    /** @return list<Risk> */
    private function risks(Machine $machine): array
    {
        $workspace = $machine->workspace;
        $base = $workspace->url("/machines/{$machine->id}");
        $risks = [];

        $findings = MachineVulnerability::query()->where('machine_id', $machine->id)->whereNull('resolved_at')->with('vulnerability')->get();
        $packages = $findings->groupBy('source')
            ->map(function (Collection $group, string $source) {
                /** @var Collection<int, Vulnerability> $found */
                $found = $group->pluck('vulnerability')->filter()->unique('id');
                $order = array_flip(Vulnerability::SEVERITIES);
                $level = (int) $found->min(fn (Vulnerability $v) => $order[$v->severity] ?? 9);

                return [
                    'package' => $source,
                    'rank' => (int) $found->min(fn (Vulnerability $v) => $v->rank()),
                    'severity' => Vulnerability::SEVERITIES[$level] ?? 'unknown',
                    'count' => $found->filter(fn (Vulnerability $v) => ($order[$v->severity] ?? 9) === $level)->count(),
                    'exploited' => $found->where('exploited', true)->count(),
                    'fixed' => $group->contains(fn (MachineVulnerability $f) => filled($f->fixed_by)),
                ];
            })
            ->filter(fn (array $p) => $p['exploited'] > 0 || in_array($p['severity'], Vulnerability::ALERTING, true))
            ->sortBy(fn (array $p) => [$p['rank'], -$p['count'], $p['package']])
            ->take(self::MAX_RISKS_PER_SOURCE);
        foreach ($packages as $p) {
            $bugs = $p['count'] === 1
                ? self::t(':package has 1 :severity bug', ['package' => $p['package'], 'severity' => self::t($p['severity'])])
                : self::t(':package has :count :severity bugs', ['package' => $p['package'], 'count' => $p['count'], 'severity' => self::t($p['severity'])]);
            $risks[] = [
                'id' => 'security-'.$p['package'],
                'severity' => $p['exploited'] > 0 ? 'urgent' : 'fix',
                'title' => match (true) {
                    $p['exploited'] === 1 => self::t(':bugs, and one is being exploited.', ['bugs' => $bugs]),
                    $p['exploited'] > 1 => self::t(':bugs, and :count are being exploited.', ['bugs' => $bugs, 'count' => $p['exploited']]),
                    default => $bugs.'.',
                },
                'detail' => match (true) {
                    $p['fixed'] && $machine->security_updates_count > 0 => $machine->security_updates_count === 1
                        ? self::t('The fix is in the security update waiting for :machine.', ['machine' => $machine->name])
                        : self::t('The fix is in the :count security updates waiting for :machine.', ['count' => $machine->security_updates_count, 'machine' => $machine->name]),
                    $p['fixed'] => self::t('An update that fixes it is waiting for :machine.', ['machine' => $machine->name]),
                    default => self::t('There’s no fix from :os yet. ServerOS checks again every 15 minutes.', ['os' => self::system($machine) ?? self::t('the system')]),
                },
                'action' => $p['fixed'] ? self::t('Install updates') : self::t('See updates'),
                'href' => $base.'/system-updates',
                'kind' => $p['fixed'] ? 'updates' : 'link',
                'ask' => null,
            ];
        }

        foreach ($this->health->aboutMachine($machine) as $issue) {
            $risks[] = [
                'id' => $issue['id'],
                'severity' => match ($issue['severity']) {
                    WorkspaceHealth::CRITICAL => 'urgent',
                    WorkspaceHealth::WARNING => 'fix',
                    default => 'notice',
                },
                'title' => rtrim($issue['title'], '.').'.',
                'detail' => preg_split('/(?<=[.!?])\s+/u', $issue['detail'], 2)[0] ?? $issue['detail'],
                'action' => $issue['action'],
                'href' => $workspace->url($issue['href']),
                'kind' => 'link',
                'ask' => $issue['ask'],
            ];
        }

        $fixes = AgentFix::query()->where('machine_id', $machine->id)->where('state', 'needs_approval')->latest()->limit(self::MAX_RISKS_PER_SOURCE)->get(['id', 'title']);
        foreach ($fixes as $fix) {
            $risks[] = [
                'id' => 'fix-'.$fix->id,
                'severity' => 'fix',
                'title' => self::t('A fix is waiting for your approval.'),
                'detail' => $fix->title ?? self::t('An agent found a fix and is waiting for you to approve it.'),
                'action' => self::t('Review'),
                'href' => route('workspace.fixes.index', ['workspace' => $workspace->slug, 'fix' => $fix->id]),
                'kind' => 'link',
                'ask' => null,
            ];
        }

        $untested = $this->neverRestored($machine);
        if ($untested !== []) {
            $names = count($untested) <= 3 ? self::list($untested) : self::t(':first, :second and :count more', ['first' => $untested[0], 'second' => $untested[1], 'count' => count($untested) - 2]);
            $risks[] = [
                'id' => "restore-{$machine->id}-never",
                'severity' => 'fix',
                'title' => self::t('The backups of :names have never been restored.', ['names' => $names]),
                'detail' => self::t('They’re copied, but a test restore is what proves a copy works.'),
                'action' => self::t('Test a restore'),
                'href' => $base.'/backups',
                'kind' => 'link',
                'ask' => null,
            ];
        }

        $rank = ['urgent' => 0, 'fix' => 1, 'notice' => 2];
        usort($risks, fn (array $a, array $b) => $rank[$a['severity']] <=> $rank[$b['severity']]);

        return $risks;
    }

    /** @return list<string> */
    private function neverRestored(Machine $machine): array
    {
        $copied = Backup::query()->where('machine_id', $machine->id)->where('state', 'succeeded')->whereNotIn('trigger', ['pre-restore', 'pre-deploy'])->distinct()->pluck('service_name', 'service');
        if ($copied->isEmpty()) {
            return [];
        }
        $restored = Backup::query()->where('machine_id', $machine->id)->where('restore_state', 'succeeded')->distinct()->pluck('service')->all();
        $names = [];
        $services = collect(WorkspaceExport::services($machine))->keyBy('key');
        foreach ($copied as $key => $name) {
            if (! in_array($key, $restored, true) && ($service = $services->get($key)) !== null) {
                $names[] = ServiceLabels::display($machine, $service);
            }
        }

        return $names;
    }

    /** @return list<Good> */
    private function good(Machine $machine): array
    {
        $good = [];
        $open = $this->open($machine);
        $view = Firewall::view($machine);
        if ($open !== null && $open !== [] && $view !== null && $view['supported'] && $view['active'] && Firewall::exposedDatabases($machine, $view) === [] && count($open) <= 4) {
            $good[] = ['id' => 'ports', 'text' => count($open) === 1
                ? self::t('Only port :ports is open to the internet.', ['ports' => (string) $open[0]])
                : self::t('Only ports :ports are open to the internet.', ['ports' => self::list(array_map('strval', $open))])];
        }

        $ssh = is_array($machine->facts['security']['ssh'] ?? null) ? $machine->facts['security']['ssh'] : null;
        if ($ssh !== null && ($ssh['password_authentication'] ?? null) === false && ! (($ssh['kbd_interactive_authentication'] ?? null) === true && ($ssh['use_pam'] ?? null) === true)) {
            $good[] = ['id' => 'ssh-keys', 'text' => self::t('SSH only takes keys, not passwords.')];
        }
        $people = MachineSshAccess::query()->where('machine_id', $machine->id)->where('state', MachineSshAccess::ACTIVE)->count();
        if ($people > 0) {
            $good[] = ['id' => 'ssh-access', 'text' => $people === 1
                ? self::t('SSH access is given and taken back in ServerOS. 1 person has it now.')
                : self::t('SSH access is given and taken back in ServerOS. :count people have it now.', ['count' => $people])];
        }
        if (($machine->facts['security']['auto_updates'] ?? null) === true) {
            $good[] = ['id' => 'auto-updates', 'text' => self::t('Security updates install on their own.')];
        }

        $last = Backup::query()->where('machine_id', $machine->id)->where('trigger', 'scheduled')->whereIn('state', ['succeeded', 'failed'])->latest('id')->first();
        if ($machine->backup_schedule !== 'off' && $last?->state === 'succeeded' && ($last->finished_at ?? $last->created_at)?->gt(now()->subHours($machine->backup_schedule === 'weekly' ? 24 * 8 : 36))) {
            $good[] = ['id' => 'backups', 'text' => $machine->backup_schedule === 'weekly'
                ? self::t('Backed up every week. The last copy finished :time.', ['time' => ($last->finished_at ?? $last->created_at)->diffForHumans()])
                : self::t('Backed up every day. The last copy finished :time.', ['time' => ($last->finished_at ?? $last->created_at)->diffForHumans()])];
        }
        $restore = Backup::query()->where('machine_id', $machine->id)->where('restore_state', 'succeeded')->whereNotNull('restored_at')->latest('restored_at')->first();
        if ($restore?->restored_at !== null) {
            $service = collect(WorkspaceExport::services($machine))->firstWhere('key', $restore->service);
            $good[] = ['id' => 'restore', 'text' => self::t('A restore of :name worked :time.', ['name' => $service !== null ? ServiceLabels::display($machine, $service) : $restore->service_name, 'time' => $restore->restored_at->diffForHumans()])];
        }

        $certificates = collect(is_array($machine->inventory['certificates'] ?? null) ? $machine->inventory['certificates'] : [])
            ->filter(fn ($c) => is_array($c) && is_numeric($c['not_after'] ?? null) && (int) $c['not_after'] > now()->getTimestamp());
        $renewing = $certificates->filter(fn (array $c) => filled($c['renewal'] ?? null));
        if ($renewing->isNotEmpty() && $renewing->count() === $certificates->count()) {
            $days = (int) round(now()->diffInDays(CarbonImmutable::createFromTimestamp((int) $renewing->min('not_after'))));
            $good[] = ['id' => 'certificates', 'text' => self::t('HTTPS certificates renew on their own. The next one is due in :days days.', ['days' => $days])];
        } else {
            $soonest = Monitor::query()->where('machine_id', $machine->id)->where('paused', false)->whereNotNull('ssl_expires_at')->min('ssl_expires_at');
            if ($soonest !== null && ($expires = CarbonImmutable::parse($soonest))->gt(now()->addDays(Monitor::SSL_WARN_DAYS))) {
                $good[] = ['id' => 'certificates', 'text' => self::t('Its HTTPS certificates are good for :days more days.', ['days' => (int) floor(now()->diffInDays($expires))])];
            }
        }

        $monitors = Monitor::query()->where('machine_id', $machine->id)->where('paused', false)->get(['id', 'status']);
        if ($monitors->isNotEmpty() && $monitors->every(fn (Monitor $m) => $m->status === 'up')) {
            $good[] = ['id' => 'monitors', 'text' => $monitors->count() === 1
                ? self::t('Its monitor is up.')
                : self::t('All :count of its monitors are up.', ['count' => $monitors->count()])];
        }

        $about = $this->about($machine);
        if ($about['upDays'] !== null && $about['upDays'] >= 1 && $machine->isOnline()) {
            $good[] = ['id' => 'uptime', 'text' => $about['upDays'] === 1
                ? self::t('Up for a day without a reboot.')
                : self::t('Up for :days days without a reboot.', ['days' => $about['upDays']])];
        }

        return $good;
    }

    /** @param  list<string>  $items */
    public static function list(array $items): string
    {
        if (count($items) <= 1) {
            return $items[0] ?? '';
        }
        $last = array_pop($items);

        return self::t(':items and :last', ['items' => implode(', ', $items), 'last' => $last]);
    }

    /** @return list<int> */
    private static function ports(mixed $ports): array
    {
        return is_array($ports) ? array_values(array_unique(array_filter($ports, fn ($p) => is_int($p) && $p > 0 && $p < 65536))) : [];
    }

    /** @return list<string> */
    private static function domains(mixed $domains): array
    {
        return is_array($domains) ? array_values(array_filter(array_map(fn ($d) => is_string($d) ? strtolower(ltrim($d, '*.')) : '', $domains), fn (string $d) => self::isDomain($d))) : [];
    }

    private static function isDomain(string $name): bool
    {
        return preg_match('/^(?=.{4,253}$)([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/i', $name) === 1 && ! str_ends_with(strtolower($name), '.local');
    }

    /** @return list<string> */
    private static function mounts(string $mounts): array
    {
        $paths = [];
        foreach (array_filter(explode(', ', $mounts)) as $pair) {
            $source = explode(':', $pair)[0];
            if ($source !== '' && ! str_starts_with($source, '/var/run/') && ! str_starts_with($source, '/run/') && ! in_array($source, ['/etc/localtime', '/etc/timezone'], true)) {
                $paths[] = $source;
            }
        }

        return array_values(array_unique($paths));
    }

    /** @param  array<string, string|int>  $replace */
    public static function t(string $text, array $replace = []): string
    {
        return is_string($line = __($text, $replace)) ? $line : $text;
    }
}
