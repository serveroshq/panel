<?php

namespace App\Support\Projects;

use App\Models\DaemonJob;
use App\Models\ProjectNode;
use App\Support\Deploys\AppDeploys;
use App\Support\Deploys\DeployRequest;
use App\Support\ServiceTemplates\ServiceTemplate;
use App\Support\ServiceTemplates\ServiceTemplateCatalog;
use Illuminate\Support\Collection;

/**
 * @phpstan-type NodeFacts array{state: string, running: string|null, failed: bool, kind: array{slug: string, name: string}|null, image: string|null, domain: string|null, port: int|null, by: array{name: string, avatar: string|null}|null, at: string|null}
 */
final class ProjectFacts
{
    public const array ORDER = ['down', 'deploying', 'pending', 'stopped', 'live'];

    /** @var array<string, string>|null */
    private ?array $serviceCommits = null;

    /** @var array<string, list<ServiceTemplate>>|null */
    private ?array $appDockerfiles = null;

    public function __construct(private readonly ServiceTemplateCatalog $catalog) {}

    /**
     * @param  Collection<int, ProjectNode>  $nodes
     * @return array<int, NodeFacts>
     */
    public function nodes(Collection $nodes): array
    {
        $jobs = $this->deploys($nodes);
        $facts = [];
        foreach ($nodes as $node) {
            $facts[$node->id] = $this->node($node, collect($jobs[$node->machine_id.'/'.$node->name] ?? []));
        }

        return $facts;
    }

    /**
     * @param  Collection<int, ProjectNode>  $nodes
     * @param  array<int, NodeFacts>  $facts
     * @return array{state: string|null, app: int|null, kind: array{slug: string, name: string}|null, image: string|null, machine: array{id: int, name: string, provider: string|null}|null, domain: string|null, by: array{name: string, avatar: string|null}|null, at: string|null, stack: list<array{name: string, kind: string, template: string|null, label: string|null}>}
     */
    public function summary(Collection $nodes, array $facts): array
    {
        $main = $nodes->sortBy(fn (ProjectNode $node) => [$node->isApp() ? 0 : 1, $node->id])->first();
        $rest = $main === null ? [] : $nodes->reject(fn (ProjectNode $node) => $node->id === $main->id)
            ->sortBy(fn (ProjectNode $node) => [$node->isApp() ? 1 : 0, $node->id])
            ->values()->all();

        $states = [];
        $latest = null;
        foreach ($main === null ? [] : [$main, ...$rest] as $node) {
            $fact = $facts[$node->id] ?? null;
            $states[] = $fact['state'] ?? 'pending';
            if ($fact !== null && $fact['at'] !== null && ($latest === null || $fact['at'] > $latest['at'])) {
                $latest = $fact;
            }
        }
        $fact = $main === null ? null : ($facts[$main->id] ?? null);
        $stack = [];
        foreach ($rest as $node) {
            $stack[] = [
                'name' => $node->name,
                'kind' => $node->kind,
                'template' => $node->template ?? ($facts[$node->id]['kind']['slug'] ?? null),
                'label' => $facts[$node->id]['kind']['name'] ?? null,
            ];
        }

        return [
            'state' => $states === [] ? null : collect(self::ORDER)->first(fn (string $state) => in_array($state, $states, true)),
            'app' => $main?->id,
            'kind' => $fact['kind'] ?? null,
            'image' => $fact['image'] ?? null,
            'machine' => $main === null ? null : ['id' => $main->machine_id, 'name' => $main->machine->name, 'provider' => $main->machine->provider ?: null],
            'domain' => $fact['domain'] ?? null,
            'by' => $latest['by'] ?? null,
            'at' => $latest['at'] ?? null,
            'stack' => $stack,
        ];
    }

    /** @return array{slug: string, name: string}|null */
    public function kindOf(?DaemonJob $deploy): ?array
    {
        $job = $deploy?->command['job'] ?? null;
        if (! is_array($job)) {
            return null;
        }

        $slug = $this->serviceCommits()[(string) ($job['commit'] ?? '')] ?? null;
        if ($slug !== null) {
            $template = $this->catalog->find($slug);

            return $template === null ? null : ['slug' => $template->slug, 'name' => $template->name];
        }

        $dockerfile = $job['files']['.serveros/Dockerfile'] ?? null;
        if (! is_string($dockerfile)) {
            return null;
        }
        $env = array_keys((array) ($job['env'] ?? []));
        $best = null;
        $score = -1;
        foreach ($this->appDockerfiles()[sha1(self::normalise($dockerfile))] ?? [] as $template) {
            $keys = array_column($template->env, 'key');
            if (array_diff($keys, $env) !== []) {
                continue;
            }
            if (count($keys) > $score || (count($keys) === $score && $template->slug === 'node')) {
                [$best, $score] = [$template, count($keys)];
            }
        }

        return $best === null ? null : ['slug' => $best->slug, 'name' => $best->name];
    }

    /**
     * @param  Collection<int, DaemonJob>  $jobs  newest first
     * @return NodeFacts
     */
    private function node(ProjectNode $node, Collection $jobs): array
    {
        $containers = Placement::containers($node->machine, $node->name);
        $running = $containers === [] ? null
            : (collect($containers)->contains(fn (array $c) => ($c['status'] ?? null) === 'running') ? 'running' : 'stopped');
        $latest = $jobs->first();
        $spec = $jobs->whereIn('state', ['queued', 'running', 'succeeded'])
            ->first(fn (DaemonJob $job) => AppDeploys::isApp($job->command['job'] ?? null))
            ?? $jobs->firstWhere('state', 'succeeded')
            ?? $jobs->first();
        $failed = $running === null && $latest?->state === 'failed';
        $job = $spec?->command['job'] ?? [];
        $port = $job['port'] ?? $job['env']['PORT'] ?? null;
        $image = collect($containers)->map(fn (array $c) => $c['details']['image'] ?? null)->first(fn ($image) => is_string($image) && $image !== '');
        $domains = DeployRequest::plainDomains(array_values((array) ($job['domains'] ?? [])));

        return [
            'state' => match (true) {
                in_array($latest?->state, ['queued', 'running'], true) => 'deploying',
                $running === 'running' => 'live',
                $running === 'stopped' => 'stopped',
                $failed => 'down',
                default => 'pending',
            },
            'running' => $running,
            'failed' => $failed,
            'kind' => $node->template !== null && ($template = $this->catalog->find($node->template)) !== null
                ? ['slug' => $template->slug, 'name' => $template->name]
                : $this->kindOf($spec),
            'image' => is_string($image) ? $image : null,
            'domain' => is_string($domains[0] ?? null) ? $domains[0] : null,
            'port' => is_numeric($port) ? (int) $port : null,
            'by' => $latest?->user === null ? null : ['name' => $latest->user->name, 'avatar' => $latest->user->avatar],
            'at' => $latest?->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, ProjectNode>  $nodes
     * @return array<string, list<DaemonJob>>
     */
    private function deploys(Collection $nodes): array
    {
        if ($nodes->isEmpty()) {
            return [];
        }

        $found = [];
        $jobs = DaemonJob::query()
            ->with('user')
            ->where('kind', 'deploy')
            ->whereIn('machine_id', $nodes->pluck('machine_id')->unique()->values())
            ->whereIn('service', $nodes->pluck('name')->unique()->values())
            ->whereNotIn('state', ['refused', 'cancelled'])
            ->latest()
            ->limit(1000)
            ->get();
        foreach ($jobs as $job) {
            $key = $job->machine_id.'/'.$job->service;
            if (count($found[$key] ?? []) < 20) {
                $found[$key][] = $job;
            }
        }

        return $found;
    }

    /** @return array<string, string> */
    private function serviceCommits(): array
    {
        return $this->serviceCommits ??= collect($this->catalog->all())
            ->reject(fn (ServiceTemplate $template) => $template->isApp())
            ->mapWithKeys(fn (ServiceTemplate $template) => [sha1($template->slug."\n".json_encode($template->files)) => $template->slug])
            ->all();
    }

    /** @return array<string, list<ServiceTemplate>> */
    private function appDockerfiles(): array
    {
        if ($this->appDockerfiles !== null) {
            return $this->appDockerfiles;
        }
        $found = [];
        foreach ($this->catalog->all() as $template) {
            $file = $template->files['.serveros/Dockerfile'] ?? null;
            if ($template->isApp() && is_string($file)) {
                $found[sha1(self::normalise($file))][] = $template;
            }
        }

        return $this->appDockerfiles = $found;
    }

    private static function normalise(string $file): string
    {
        return (string) preg_replace('#serversideup/php:\d+\.\d+-#', 'serversideup/php:-', $file);
    }
}
