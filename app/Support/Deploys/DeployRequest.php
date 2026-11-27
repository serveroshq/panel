<?php

namespace App\Support\Deploys;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use App\Support\Projects\Placement;
use App\Support\ServiceTemplates\RepositoryDetector;
use App\Support\ServiceTemplates\ServiceTemplate;
use App\Support\ServiceTemplates\TemplateDeployment;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\IpUtils;

final class DeployRequest
{
    public const DOMAIN_PATTERN = '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i';

    public const SERVICE_PATTERN = '/^[a-z][a-z0-9-]*$/';

    public const COMMIT_PATTERN = '/^[a-fA-F0-9]{40}$/';

    // A Compose file inside the repository: no leading slash, and no part starting with a dot, so never outside it.
    public const COMPOSE_FILE_PATTERN = '#^[A-Za-z0-9_][A-Za-z0-9._-]*(?:/[A-Za-z0-9_][A-Za-z0-9._-]*)*\.ya?ml$#';

    public static function bareDomain(mixed $domain): mixed
    {
        return is_string($domain)
            ? preg_replace(['#^[a-z]+://#i', '#[/?\#].*$#', '#:\d+$#'], '', strtolower(trim($domain)))
            : $domain;
    }

    /** @return array<string, list<string|\Closure|\Stringable>> */
    public static function gitRules(): array
    {
        return [
            'service' => ['required', 'string', 'max:63', 'regex:'.self::SERVICE_PATTERN],
            'repo' => ['required', 'string', 'max:500', self::repoRule()],
            'commit' => ['required', 'string', 'regex:'.self::COMMIT_PATTERN],
            'port' => ['required', 'integer', 'between:1,65535'],
            'health_path' => ['required', 'string', 'max:200', 'regex:/^\/[\x21-\x7e]*$/'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:'.self::DOMAIN_PATTERN],
            'wildcard' => ['sometimes', 'boolean'],
            'proxy' => ['nullable', 'string', Rule::in(array_keys(Machine::PROXIES))],
            'https' => ['sometimes', 'nullable', 'boolean'],
            'base_dir' => ['nullable', 'string', 'max:200', 'regex:'.RepositoryDetector::BASE_DIR_PATTERN],
        ];
    }

    /** @return array<string, list<string|\Closure|\Stringable>> */
    public static function templateRules(ServiceTemplate $template): array
    {
        return [
            'service' => ['required', 'string', 'max:63', 'regex:'.self::SERVICE_PATTERN],
            'port' => ['nullable', 'integer', 'between:1,65535'],
            'repo' => [$template->isApp() ? 'required' : 'nullable', 'string', 'max:500', self::repoRule()],
            'commit' => [$template->isApp() ? 'required' : 'nullable', 'string', 'regex:'.self::COMMIT_PATTERN],
            'health_path' => ['nullable', 'string', 'max:200', 'regex:/^\/[\x21-\x7e]*$/'],
            'domain' => ['nullable', 'string', 'max:253', 'regex:'.self::DOMAIN_PATTERN],
            'wildcard' => ['sometimes', 'boolean'],
            'proxy' => ['nullable', 'string', Rule::in(array_keys(Machine::PROXIES))],
            'https' => ['sometimes', 'nullable', 'boolean'],
            'env' => ['nullable', 'array'],
            'env.*' => ['nullable', 'string', 'max:'.EnvFile::MAX_VALUE_BYTES],
            'extra_env' => ['nullable', 'string', 'max:'.EnvFile::MAX_BYTES],
            'build' => ['sometimes', 'nullable', 'array'],
            ...BuildSettings::rules('build.'),
            ...BuildSettings::flagRules(),
            'branch' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:'.DeployOnPush::BRANCH_PATTERN],
            'free_address' => ['sometimes', 'boolean'],
            'php' => ['nullable', 'string', Rule::in(RepositoryDetector::PHP_VERSIONS)],
            // Nullable either way: the dialog sends null for templates that don't
            // use it, and prohibited still turns down an actual path.
            'compose_file' => ['nullable', ...($template->composeFromRepo() ? [] : ['prohibited']), 'string', 'max:200', 'regex:'.self::COMPOSE_FILE_PATTERN],
            // A Compose file is named by its path instead, so only builds take a folder.
            'base_dir' => ['nullable', ...($template->isApp() && ! $template->composeFromRepo() ? [] : ['prohibited']), 'string', 'max:200', 'regex:'.RepositoryDetector::BASE_DIR_PATTERN],
        ];
    }

    /** @return array<string, string> */
    public static function templateMessages(): array
    {
        return [
            ...BuildSettings::messages('build.'),
            'branch.regex' => 'That isn’t a branch name.',
            'env.*.max' => 'A setting can be at most 64 KB.',
            'extra_env.max' => 'That’s more than 512 KB of settings. Paste fewer at a time.',
        ];
    }

    /**
     * A template that needs a newer daemon (Railpack, a prebuilt image) is
     * turned down on an older one, saying what to do.
     */
    public static function assertTemplateSupported(Machine $machine, ServiceTemplate $template): void
    {
        if ($template->needsDeployBuild() && ! DaemonJobQueue::canDeployBuild($machine)) {
            throw ValidationException::withMessages(['template' => $machine->name.'’s daemon is too old to '.($template->isImage() ? 'run a prebuilt image' : 'build an app without a Dockerfile').'. It updates itself, or run “sudo serverosd update” on the server, then deploy again.']);
        }
    }

    /**
     * A web app with no domain gets a free address on a newer daemon, when
     * the machine has a public IPv4 address: <name>.<ip>.sslip.io.
     *
     * @param  array<string, mixed>  $data
     */
    public static function freeDomain(Machine $machine, ServiceTemplate $template, array $data): ?string
    {
        if (filled($data['domain'] ?? null) || ($data['free_address'] ?? true) === false || ! $template->web
            || ! ($template->isApp() || $template->isImage()) || ! DaemonJobQueue::canDeployBuild($machine)) {
            return null;
        }
        $base = self::freeAddressBase($machine);

        return $base === null || ! is_string($data['service'] ?? null) ? null : $data['service'].'.'.$base;
    }

    /**
     * Two apps on one machine can't share a domain: the second would take
     * the first one's site over. Redeploying the same app is fine.
     *
     * @throws ValidationException
     */
    public static function assertDomainFree(Machine $machine, ?string $domain, string $service, string $field = 'domain'): void
    {
        if (blank($domain)) {
            return;
        }
        $owner = self::domainOwner($machine, (string) $domain, $service);
        if ($owner !== null) {
            throw ValidationException::withMessages([$field => "{$domain} is already {$owner}’s domain on {$machine->name}. Give this one another, or move {$owner} first."]);
        }
    }

    /** The other app on the machine that serves a domain now, if any. */
    public static function domainOwner(Machine $machine, string $domain, string $except): ?string
    {
        $domain = strtolower($domain);
        $jobs = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')
            ->where('service', '!=', $except)->whereNotNull('service')
            ->whereIn('state', ['queued', 'running', 'succeeded'])
            ->latest()->get();
        foreach ($jobs->groupBy('service') as $app => $deploys) {
            $app = (string) $app;
            $latest = $deploys->first();
            $live = $latest !== null && ($latest->isActive() || Placement::containers($machine, $app) !== []);
            if (! $live) {
                continue;
            }
            $domains = array_map('strtolower', array_filter((array) ($latest->command['job']['domains'] ?? []), 'is_string'));
            if (in_array($domain, $domains, true)) {
                return $app;
            }
        }

        return null;
    }

    public static function assertBaseDir(Machine $machine, ?string $baseDir): void
    {
        if (filled($baseDir) && ! DaemonJobQueue::canBuildFromFolder($machine)) {
            throw ValidationException::withMessages(['base_dir' => $machine->name.'’s daemon is too old to build an app from a folder of its repository. It updates itself, or run “sudo serverosd update” on the server, then deploy again.']);
        }
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    public static function withBaseDir(array $job, ?string $baseDir): array
    {
        return filled($baseDir) ? [...$job, 'base_dir' => $baseDir] : $job;
    }

    public static function assertComposeFromRepo(Machine $machine, ServiceTemplate $template): void
    {
        if ($template->composeFromRepo() && ! DaemonJobQueue::canComposeFromRepo($machine)) {
            throw ValidationException::withMessages(['form' => $machine->name.'’s daemon is too old to run a Compose file from a repository. It updates itself, or run “sudo serverosd update” on the server, then deploy again.']);
        }
    }

    /**
     * A free address for a machine with a public IPv4 address, from sslip.io:
     * any name under 203-0-113-5.sslip.io resolves to 203.0.113.5, so an app
     * can have a working address (and a certificate) before it has a domain.
     */
    public static function freeAddressBase(Machine $machine): ?string
    {
        $ip = (string) $machine->ip_address;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
            || IpUtils::checkIp($ip, ['100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15'])) {
            return null;
        }

        return str_replace('.', '-', $ip).'.sslip.io';
    }

    /** @return list<string> */
    public static function domains(?string $domain, bool $wildcard = false): array
    {
        if (blank($domain)) {
            return [];
        }
        $domain = strtolower($domain);

        return $wildcard ? [$domain, '*.'.$domain] : [$domain];
    }

    /** @param  array<int, mixed>  $domains */
    public static function isWildcard(array $domains): bool
    {
        $first = $domains[0] ?? null;

        return is_string($first) && in_array('*.'.$first, $domains, true);
    }

    /**
     * @param  array<int, mixed>  $domains
     * @return list<string>
     */
    public static function plainDomains(array $domains): array
    {
        return array_values(array_filter($domains, fn ($domain) => is_string($domain) && ! str_starts_with($domain, '*.')));
    }

    public static function assertWildcard(Machine $machine, ?string $domain, bool $wildcard, string $field = 'wildcard'): void
    {
        if (! $wildcard) {
            return;
        }
        if (blank($domain)) {
            throw ValidationException::withMessages([$field => 'Add a domain first: wildcard subdomains sit under it.']);
        }
        if (! DaemonJobQueue::canWildcard($machine)) {
            throw ValidationException::withMessages([$field => 'This machine’s daemon is too old for wildcard subdomains. It updates itself, or run “sudo serverosd update” on the server.']);
        }
    }

    /**
     * The proxy a deploy asks for, when it has a domain for one to serve
     * and isn't the one the machine already uses. Null keeps it as it is.
     */
    public static function proxy(Machine $machine, ?string $domain, ?string $proxy): ?string
    {
        if (blank($domain) || blank($proxy) || $proxy === ($machine->proxy ?? 'caddy')) {
            return null;
        }
        if (! DaemonJobQueue::canChooseProxy($machine)) {
            throw ValidationException::withMessages(['proxy' => $machine->name.'’s daemon is too old to switch reverse proxy. It updates itself, or run “sudo serverosd update” on the server.']);
        }

        return $proxy;
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    public static function withProxy(array $job, ?string $proxy): array
    {
        return $proxy === null ? $job : [...$job, 'proxy' => $proxy];
    }

    /**
     * Whether a deploy with a domain serves it over HTTPS, when it says.
     * Null leaves it to the daemon: what the app had, HTTPS for a new one.
     */
    public static function https(Machine $machine, ?string $domain, mixed $https): ?bool
    {
        if (blank($domain) || $https === null) {
            return null;
        }
        if (! DaemonJobQueue::canChooseHttps($machine)) {
            if (! $https) {
                throw ValidationException::withMessages(['https' => $machine->name.'’s daemon is too old to serve a domain without HTTPS. It updates itself, or run “sudo serverosd update” on the server.']);
            }

            return null;
        }

        return (bool) $https;
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    public static function withHttps(array $job, ?bool $https): array
    {
        return $https === null ? $job : [...$job, 'https' => $https];
    }

    // An HTTPS address, or an SSH one fetched with the app's deploy key.
    private static function repoRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if (! GitRemote::isSsh($value) && filter_var($value, FILTER_VALIDATE_URL) === false) {
                $fail('Use the repository’s HTTPS address, or its SSH one for a private repository with a deploy key.');
            }
        };
    }

    /**
     * An SSH repository is fetched with the app's deploy key, and its commit
     * comes from the machine (the panel can't read it), so it must be given.
     */
    public static function assertRepo(string $repo, ?string $commit = null): void
    {
        if (! GitRemote::isSsh($repo)) {
            self::assertPublicRepo($repo);

            return;
        }
        if ($commit === null || preg_match(self::COMMIT_PATTERN, $commit) !== 1) {
            throw ValidationException::withMessages(['commit' => 'An SSH repository deploys a commit the machine has looked up with its deploy key: add the key, then look up the branch first.']);
        }
    }

    public static function assertPublicRepo(string $repo): void
    {
        $parts = parse_url($repo);
        if (! is_array($parts) || ($parts['scheme'] ?? null) !== 'https' || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
            throw ValidationException::withMessages(['repo' => 'Use an HTTPS Git repository address, without a password or token in it.']);
        }
    }

    public static function assertDocker(Machine $machine, ?User $user = null): void
    {
        if ($machine->hasDocker() !== false) {
            return;
        }

        $rescanning = $user !== null && app(DaemonJobQueue::class)->rescan($machine, $user) !== null;

        // A newer daemon installs Docker itself, from the dashboard.
        if (DaemonJobQueue::canDeployBuild($machine)) {
            throw ValidationException::withMessages(['docker' => $rescanning
                ? 'Docker isn’t showing on '.$machine->name.' yet, so ServerOS is checking the machine again now. If you’ve just installed it, deploy again in a few seconds. If not, install it here first.'
                : 'Docker isn’t installed on '.$machine->name.'. Install it here first (it takes a few minutes), then deploy again.']);
        }

        throw ValidationException::withMessages(['form' => $rescanning
            ? 'Docker isn’t showing on '.$machine->name.' yet, so ServerOS is checking the machine again now. If you’ve just installed it, deploy again in a few seconds. If not, run `curl -fsSL https://get.docker.com | sh` on it as root first.'
            : 'Docker isn’t installed on '.$machine->name.'. Run `curl -fsSL https://get.docker.com | sh` on it as root, then deploy again.']);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function gitJob(array $data): array
    {
        return [
            'type' => 'deploy',
            'service' => $data['service'],
            'repo' => $data['repo'],
            'commit' => strtolower($data['commit']),
            'dockerfile' => 'Dockerfile',
            'env' => (object) [],
            'health' => ['path' => $data['health_path'], 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => 5],
            'domains' => self::domains($data['domain'] ?? null, (bool) ($data['wildcard'] ?? false)),
            'port' => (int) $data['port'],
        ];
    }

    /**
     * A repository's own Dockerfile, deployed with build settings: on a
     * daemon that reads them, a custom app's defaults (a health check that
     * passes on any answer below 500 after a minute to boot, the shared
     * network) with what the request's `build` changes. An older daemon
     * gets the plain job, and is told what it can't do.
     *
     * @param  array<string, mixed>  $job  from gitJob()
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function gitBuild(Machine $machine, array $job, array $data): array
    {
        $build = is_array($data['build'] ?? null) ? $data['build'] : [];
        $flags = (array) ($data['env_flags'] ?? []);
        $new = DaemonJobQueue::canDeployBuild($machine);
        $job['env'] = (array) $job['env'];

        $settings = BuildSettings::fromSpec($job);
        if ($new) {
            $settings->health = [...BuildSettings::CUSTOM_HEALTH, 'path' => (string) ($job['health']['path'] ?? '/')];
            $settings->network = BuildSettings::NETWORK;
        } elseif ($build === [] && $flags === []) {
            $job['env'] = (object) $job['env'];

            return $job;
        }
        $settings = $settings->with($build)->withFlags($flags, []);
        $settings->check($machine, 'build.');
        $job = $settings->applyValues($settings->apply($job, $new), [], $new, $new);
        $job['env'] = (object) $job['env'];
        $job['files'] = (object) $job['files'];

        return $job;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{template: string, generated: list<array{key: string, label: string, value: string}>, kept: list<string>, url: string|null, localUrl: string|null, tunnel: string|null, port: int|null, notes: string|null, domain: string|null}
     */
    public static function templateResult(TemplateDeployment $deployment, array $data): array
    {
        $template = $deployment->template;
        $machine = $deployment->machine;
        $port = $deployment->port;

        return [
            'template' => $template->name,
            'generated' => $deployment->generated,
            'kept' => $deployment->kept,
            'url' => $template->web && (filled($data['domain'] ?? null) || ($port !== null && $machine->localAddress($port) !== null)) ? $deployment->url : null,
            'localUrl' => $template->web && ! $template->isApp() && $port !== null ? "http://127.0.0.1:{$port}" : null,
            'tunnel' => $template->web && ! $template->isApp() && $port !== null && filled($machine->ip_address) && $machine->localAddress($port) === null
                ? "ssh -N -L {$port}:127.0.0.1:{$port} root@{$machine->ip_address}"
                : null,
            'port' => $port,
            'notes' => $template->notesFor($machine),
            'domain' => $template->web && filled($data['domain'] ?? null) ? (string) $data['domain'] : null,
        ];
    }
}
