<?php

namespace App\Support\ServiceTemplates;

use App\Models\Workspace;
use App\Support\Deploys\EnvFile;
use App\Support\GitHub\GitHubApp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class RepositoryDetector
{
    public const TYPE_WEB = 'Web app';

    public const TYPE_API = 'API';

    public const TYPE_STATIC = 'Static site';

    public const TYPE_APP = 'App';

    public const COMPOSE_FILES = ['compose.yaml', 'compose.yml', 'docker-compose.yaml', 'docker-compose.yml'];

    public const MANIFESTS = ['package.json', 'composer.json', 'requirements.txt', 'pyproject.toml', 'Gemfile', 'Cargo.toml', 'go.mod', 'mix.exs', ...self::COMPOSE_FILES];

    // Read too when they're there: they say which port, versions and start command.
    public const HINTS = ['Dockerfile', 'Procfile', 'Pipfile', 'deno.json', 'deno.jsonc', '.nvmrc', '.node-version', '.python-version', 'runtime.txt', '.tool-versions', 'railpack.json', 'pom.xml', 'build.gradle', 'build.gradle.kts'];

    // Example settings a repository keeps, offered to fill in before the first deploy.
    public const ENV_EXAMPLES = ['.env.example', '.env.sample', '.env.template', '.env.dist'];

    private ?string $token = null;

    // A folder inside the repository: plain names only, so never outside it.
    public const BASE_DIR_PATTERN = '#^(?!.*(?:^|/)\.{1,2}(?:/|$))[A-Za-z0-9._@+-]+(?:/[A-Za-z0-9._@+-]+){0,15}$#';

    /**
     * @return array{detected: bool, template: string|null, framework: string|null, type: string|null, repo: string, branch: string, commit: string, baseDir: string|null, reasons: list<string>, php: string|null, healthPath: string|null, composeFile: string|null, composePort: int|null}
     */
    public function detect(string $url, ?Workspace $workspace = null, bool $autoBuild = true): array
    {
        [$owner, $name, $tree] = $this->parse($url);
        $this->token = $workspace === null ? null : app(GitHubApp::class)->tokenFor($workspace, $owner, $name);

        $repo = $this->api("repos/{$owner}/{$name}");
        if ($repo === null || ($this->token === null && ! self::isPublic($repo))) {
            throw ValidationException::withMessages(['repository' => self::notFound($workspace, $owner)]);
        }

        [$branch, $baseDir, $commit] = $this->branchAndFolder($owner, $name, $tree, (string) $repo['default_branch']);
        $sha = (string) $commit['sha'];

        if ($baseDir === null) {
            $listing = $this->api("repos/{$owner}/{$name}/git/trees/{$sha}") ?? ['tree' => []];
            $files = array_column($listing['tree'] ?? [], 'path');
        } else {
            $listing = $this->api("repos/{$owner}/{$name}/contents/".implode('/', array_map('rawurlencode', explode('/', $baseDir))).'?ref='.$sha);
            if (! is_array($listing) || ! array_is_list($listing)) {
                throw ValidationException::withMessages(['repository' => "Couldn’t find the folder {$baseDir} on the {$branch} branch."]);
            }
            $files = array_values(array_filter(array_map(fn ($entry) => is_array($entry) ? ($entry['name'] ?? null) : null, $listing), 'is_string'));
        }

        $contents = [];
        foreach ([...self::MANIFESTS, ...self::HINTS, ...self::ENV_EXAMPLES] as $manifest) {
            if (in_array($manifest, $files, true)) {
                $contents[$manifest] = $this->raw($owner, $name, $sha, $baseDir === null ? $manifest : "{$baseDir}/{$manifest}");
            }
        }

        return [
            'repo' => "https://github.com/{$owner}/{$name}.git",
            'branch' => $branch,
            'commit' => $sha,
            'baseDir' => $baseDir,
            ...$this->describe($files, $contents, $baseDir, $autoBuild),
        ];
    }

    /**
     * What a repository is, from its files at the root (or the app's folder)
     * and the contents of the ones that say how it's built: the template
     * that builds it, the port it serves on, example settings to fill in,
     * and what Railpack would find. Nothing recognised falls back to
     * Railpack (auto-build) when the machine's daemon can build with it.
     *
     * @param  list<string>  $files
     * @param  array<string, string|null>  $contents
     * @return array{detected: bool, template: string|null, framework: string|null, type: string|null, reasons: list<string>, php: string|null, healthPath: string|null, composeFile: string|null, composePort: int|null, port: int|null, railpack: array{language: string|null, framework: string|null, packageManager: string|null, version: string|null, startCommand: string|null}|null, envExample: array{file: string, variables: list<array{name: string, value: string}>}|null, needsUpdate: string|null}
     */
    public function describe(array $files, array $contents, ?string $baseDir = null, bool $autoBuild = true): array
    {
        $match = $this->match($files, $contents);
        $railpack = self::railpackHints($files, $contents);
        $needsUpdate = null;

        if ($match === null && $autoBuild) {
            $label = $railpack['framework'] ?? $railpack['language'] ?? null;
            $match = $this->found('auto-build', $label ?? 'Your own code', self::TYPE_APP, $label === null
                ? 'ServerOS doesn’t recognise it by name, so Railpack works out how to build and start it from its files.'
                : "It’s {$label}, and Railpack builds it without a Dockerfile.");
        }
        if (($match['template'] ?? null) === 'auto-build' && ! $autoBuild) {
            $match = null;
            $needsUpdate = 'Update the ServerOS daemon on this machine to build apps without a Dockerfile.';
        } elseif ($match === null) {
            $needsUpdate = 'Update the ServerOS daemon on this machine to build apps without a Dockerfile.';
        }
        if (($match['template'] ?? null) === 'auto-build' && is_string($railpack['startCommand'] ?? null)) {
            $match['reasons'][] = "Its Procfile starts it with {$railpack['startCommand']}.";
        }

        $port = null;
        if (($match['template'] ?? null) === 'custom-dockerfile' && ($port = self::exposedPort((string) ($contents['Dockerfile'] ?? ''))) !== null) {
            $match['reasons'][] = "Its Dockerfile exposes port {$port}, so that’s the one served.";
        }
        if (($match['template'] ?? null) === 'compose-file') {
            $port = $match['composePort'] ?? null;
        }
        // The daemon runs a Compose file from the repository's root, so one in the folder is named by its full path.
        if ($baseDir !== null && isset($match['composeFile'])) {
            $match['composeFile'] = "{$baseDir}/{$match['composeFile']}";
        }

        return [
            'detected' => $match !== null,
            'template' => $match['template'] ?? null,
            'framework' => $match['framework'] ?? null,
            'type' => $match['type'] ?? null,
            'reasons' => $match['reasons'] ?? [],
            'php' => $match['php'] ?? null,
            'healthPath' => $match['healthPath'] ?? null,
            'composeFile' => $match['composeFile'] ?? null,
            'composePort' => $match['composePort'] ?? null,
            'port' => $port,
            'railpack' => $railpack,
            'envExample' => self::envExample($contents),
            'needsUpdate' => $match === null ? $needsUpdate : null,
        ];
    }

    /**
     * What a repository on any git host is, from the machine's repo_inspect
     * job: the same answer detect() gives for GitHub.
     *
     * @param  array<string, mixed>  $result  the job's result: commit, ref, default_branch, files, contents, truncated
     * @return array<string, mixed>
     */
    public function fromInspect(array $result, string $repo, ?string $baseDir, bool $autoBuild = true): array
    {
        $files = array_values(array_filter(is_array($result['files'] ?? null) ? $result['files'] : [], 'is_string'));
        $contents = [];
        foreach (is_array($result['contents'] ?? null) ? $result['contents'] : [] as $path => $body) {
            if (is_string($body)) {
                $contents[(string) $path] = $body;
            }
        }
        $commit = is_string($result['commit'] ?? null) && preg_match('/^[0-9a-f]{40}$/i', $result['commit']) === 1 ? strtolower($result['commit']) : null;
        $branch = is_string($result['ref'] ?? null) && $result['ref'] !== '' ? $result['ref'] : (is_string($result['default_branch'] ?? null) ? $result['default_branch'] : null);

        return [
            'repo' => $repo,
            'branch' => $branch,
            'commit' => $commit,
            'baseDir' => $baseDir,
            ...$this->describe($files, $contents, $baseDir, $autoBuild),
            'truncated' => (bool) ($result['truncated'] ?? false),
        ];
    }

    /** The first port a Dockerfile EXPOSEs, like EXPOSE 8080 or EXPOSE 8080/tcp. */
    public static function exposedPort(string $dockerfile): ?int
    {
        if (preg_match('/^\s*EXPOSE\s+(\d{1,5})(?:\/tcp)?\b/mi', $dockerfile, $m) !== 1) {
            return null;
        }
        $port = (int) $m[1];

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    /**
     * The settings an example file in the repository lists, with their
     * example values, for the dialog to offer.
     *
     * @param  array<string, string|null>  $contents
     * @return array{file: string, variables: list<array{name: string, value: string}>}|null
     */
    public static function envExample(array $contents): ?array
    {
        foreach (self::ENV_EXAMPLES as $file) {
            if (! is_string($contents[$file] ?? null)) {
                continue;
            }
            $parsed = EnvFile::parse($contents[$file]);
            if ($parsed['variables'] === []) {
                continue;
            }

            return ['file' => $file, 'variables' => array_map(
                fn (string $name, string $value) => ['name' => $name, 'value' => $value],
                array_keys($parsed['variables']),
                $parsed['variables'],
            )];
        }

        return null;
    }

    /**
     * What Railpack would find: the language, framework, package manager and
     * version it builds with, and the start command a Procfile gives.
     *
     * @param  list<string>  $files
     * @param  array<string, string|null>  $contents
     * @return array{language: string|null, framework: string|null, packageManager: string|null, version: string|null, startCommand: string|null}|null
     */
    public static function railpackHints(array $files, array $contents): ?array
    {
        $has = fn (string $file) => in_array($file, $files, true);
        $json = fn (?string $body): array => is_array($data = json_decode((string) $body, true)) ? $data : [];
        $first = function (array $candidates) use ($contents): ?string {
            foreach ($candidates as $file) {
                $line = trim(strtok((string) ($contents[$file] ?? ''), "\n") ?: '');
                if ($line !== '') {
                    return $line;
                }
            }

            return null;
        };
        $start = null;
        if (preg_match('/^web:\s*(.+)$/m', (string) ($contents['Procfile'] ?? ''), $m) === 1) {
            $start = trim($m[1]);
        }
        $hint = fn (string $language, ?string $framework, ?string $manager, ?string $version = null) => [
            'language' => $language, 'framework' => $framework, 'packageManager' => $manager,
            'version' => $version === null ? null : mb_substr($version, 0, 40), 'startCommand' => $start === null ? null : mb_substr($start, 0, 300),
        ];

        if ($has('package.json')) {
            $package = $json($contents['package.json'] ?? null);
            $deps = array_merge((array) ($package['dependencies'] ?? []), (array) ($package['devDependencies'] ?? []));
            $framework = null;
            foreach (['next' => 'Next.js', 'nuxt' => 'Nuxt', '@sveltejs/kit' => 'SvelteKit', 'astro' => 'Astro', '@remix-run/node' => 'Remix', '@nestjs/core' => 'NestJS', 'express' => 'Express', 'fastify' => 'Fastify', 'hono' => 'Hono', 'vite' => 'Vite'] as $dep => $label) {
                if (isset($deps[$dep])) {
                    $framework = $label;
                    break;
                }
            }
            $manager = match (true) {
                $has('bun.lock') || $has('bun.lockb') => 'bun',
                $has('pnpm-lock.yaml') => 'pnpm',
                $has('yarn.lock') => 'yarn',
                default => 'npm',
            };
            $version = $first(['.nvmrc', '.node-version']) ?? (is_string($package['engines']['node'] ?? null) ? $package['engines']['node'] : null);

            return $hint($manager === 'bun' ? 'Bun' : 'Node.js', $framework, $manager, $version);
        }
        if ($has('deno.json') || $has('deno.jsonc')) {
            return $hint('Deno', null, 'deno');
        }
        $python = strtolower((string) ($contents['requirements.txt'] ?? '').(string) ($contents['pyproject.toml'] ?? '').(string) ($contents['Pipfile'] ?? ''));
        if ($has('requirements.txt') || $has('pyproject.toml') || $has('Pipfile')) {
            $framework = match (true) {
                str_contains($python, 'django') => 'Django',
                str_contains($python, 'fastapi') => 'FastAPI',
                str_contains($python, 'flask') => 'Flask',
                default => null,
            };
            $manager = match (true) {
                $has('uv.lock') => 'uv',
                $has('poetry.lock') => 'poetry',
                $has('Pipfile') => 'pipenv',
                default => 'pip',
            };
            $version = $first(['.python-version']) ?? (preg_match('/python-([\d.]+)/', (string) ($contents['runtime.txt'] ?? ''), $m) === 1 ? $m[1] : null);

            return $hint('Python', $framework, $manager, $version);
        }
        if ($has('composer.json')) {
            $require = (array) ($json($contents['composer.json'] ?? null)['require'] ?? []);

            return $hint('PHP', isset($require['laravel/framework']) ? 'Laravel' : (isset($require['symfony/framework-bundle']) ? 'Symfony' : null), 'composer', is_string($require['php'] ?? null) ? $require['php'] : null);
        }
        if ($has('Gemfile')) {
            $gems = strtolower((string) ($contents['Gemfile'] ?? ''));

            return $hint('Ruby', str_contains($gems, 'rails') ? 'Rails' : (str_contains($gems, 'sinatra') ? 'Sinatra' : null), 'bundler');
        }
        if ($has('go.mod')) {
            return $hint('Go', null, 'go', preg_match('/^go\s+([\d.]+)/m', (string) ($contents['go.mod'] ?? ''), $m) === 1 ? $m[1] : null);
        }
        if ($has('Cargo.toml')) {
            return $hint('Rust', null, 'cargo');
        }
        if ($has('pom.xml') || $has('build.gradle') || $has('build.gradle.kts')) {
            $build = (string) ($contents['pom.xml'] ?? '').(string) ($contents['build.gradle'] ?? '').(string) ($contents['build.gradle.kts'] ?? '');

            return $hint('Java', str_contains($build, 'spring-boot') ? 'Spring Boot' : null, $has('pom.xml') ? 'maven' : 'gradle');
        }
        if ($has('mix.exs')) {
            return $hint('Elixir', str_contains((string) ($contents['mix.exs'] ?? ''), ':phoenix') ? 'Phoenix' : null, 'mix');
        }
        if ($has('index.html')) {
            return $hint('Static site', null, null);
        }
        if ($start !== null) {
            return $hint('Shell', null, null);
        }

        return null;
    }

    /**
     * @param  list<string>  $files
     * @param  array<string, string|null>  $contents
     * @return array{template: string, framework: string, type: string, reasons: list<string>, php?: string, healthPath?: string, composeFile?: string, composePort?: int}|null
     */
    public function match(array $files, array $contents): ?array
    {
        $compose = null;
        foreach (self::COMPOSE_FILES as $file) {
            if (in_array($file, $files, true)) {
                $compose = ['file' => $file, 'body' => (string) ($contents[$file] ?? '')];
                break;
            }
        }
        // Laravel Sail's file only works on a laptop, so it's never the way to deploy.
        if ($compose !== null && str_contains($compose['body'], 'laravel/sail')) {
            $compose = null;
        }

        // A Compose file that builds this code is how it's meant to run. One that only
        // starts images (a database next to the app) is usually for development, so the
        // framework wins, unless nothing else is recognised.
        if ($compose !== null && preg_match('/^\s*build:/m', $compose['body']) === 1) {
            return $this->composeFound($compose['file'], $compose['body'], 'It builds this code, so it runs the whole stack.');
        }
        $match = $this->framework($files, $contents);
        if ($compose === null) {
            return $match;
        }
        if ($match === null) {
            return $this->composeFound($compose['file'], $compose['body'], 'It runs as a stack, like docker compose up.');
        }
        $match['reasons'][] = "There’s a {$compose['file']} too. To run that instead, choose Your own Docker Compose file.";
        $match['composeFile'] = $compose['file'];
        if (($port = self::publishedPort($compose['body'])) !== null) {
            $match['composePort'] = $port;
        }

        return $match;
    }

    /** @return array{template: string, framework: string, type: string, reasons: list<string>, composeFile: string, composePort?: int} */
    private function composeFound(string $file, string $body, string $why): array
    {
        $found = [...$this->found('compose-file', 'Docker Compose', self::TYPE_APP, "Has a {$file}. {$why}"), 'composeFile' => $file];
        $port = self::publishedPort($body);
        if ($port !== null) {
            $found['composePort'] = $port;
            $found['reasons'][] = "It publishes port {$port}, so that’s the one checked and served.";
        } else {
            $found['reasons'][] = 'It doesn’t publish a port. Publish the web one (like "127.0.0.1:8080:80") to give it a domain.';
        }

        return $found;
    }

    // Databases, caches and mail answer on these, not HTTP, so they're never the one to check.
    private const NOT_WEB_PORTS = [22, 25, 465, 587, 1433, 1521, 3306, 5432, 5672, 6379, 11211, 27017];

    /** The first web port a Compose file publishes on the machine, from "8080:80", "127.0.0.1:8080:80", "${PORT:-8080}:80" or published: 8080. */
    public static function publishedPort(string $compose): ?int
    {
        $host = '(?:(?:\d{1,3}\.){3}\d{1,3}:|\[[0-9a-fA-F:]+\]:)?';
        $port = '(?:(\d{1,5})|\$\{[A-Za-z_][A-Za-z0-9_]*:?-(\d{1,5})\})';
        $found = [];
        preg_match_all('/^\s*-\s*["\']?'.$host.$port.':\d{1,5}(?:\/(?:tcp|udp))?["\']?\s*(?:#.*)?$/m', $compose, $short, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($short as $m) {
            $found[$m[0][1]] = (int) (($m[1][0] ?? '') !== '' ? $m[1][0] : ($m[2][0] ?? ''));
        }
        preg_match_all('/^\s*published:\s*["\']?(\d{1,5})["\']?\s*(?:#.*)?$/m', $compose, $long, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($long as $m) {
            $found[$m[0][1]] = (int) $m[1][0];
        }
        ksort($found);
        foreach ($found as $candidate) {
            if ($candidate >= 1 && $candidate <= 65535 && ! in_array($candidate, self::NOT_WEB_PORTS, true)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $files
     * @param  array<string, string|null>  $contents
     * @return array{template: string, framework: string, type: string, reasons: list<string>, php?: string, healthPath?: string}|null
     */
    private function framework(array $files, array $contents): ?array
    {
        $has = fn (string $file) => in_array($file, $files, true);
        $hasAny = fn (string $pattern) => preg_grep($pattern, $files) !== [];

        if ($has('Dockerfile')) {
            return $this->found('custom-dockerfile', 'Dockerfile', self::TYPE_APP, 'Has a Dockerfile at the root, so it builds as is.');
        }

        if ($has('hugo.toml') || $has('hugo.yaml') || $has('hugo.json')) {
            return $this->found('hugo', 'Hugo', self::TYPE_STATIC, 'Has a Hugo config at the root.');
        }

        if (($composer = $this->json($contents['composer.json'] ?? null)) !== null) {
            $require = array_merge($composer['require'] ?? [], $composer['require-dev'] ?? []);

            $found = match (true) {
                isset($require['laravel/framework']) => $this->found('laravel', 'Laravel', self::TYPE_WEB, 'composer.json requires laravel/framework.'),
                isset($require['symfony/framework-bundle']) => $this->found('symfony', 'Symfony', self::TYPE_WEB, 'composer.json requires symfony/framework-bundle.'),
                default => $this->found('php', 'PHP', self::TYPE_WEB, 'Has a composer.json.'),
            };
            [$php, $why] = self::phpFor($composer);
            $found['php'] = $php;
            $found['reasons'][] = $why;
            $laravel = self::major($composer['require']['laravel/framework'] ?? null);
            if ($laravel !== null && $laravel < 11) {
                $found['healthPath'] = '/';
                $found['reasons'][] = "Laravel {$laravel} has no /up route, so check / for health.";
            }

            return $found;
        }

        if (($package = $this->json($contents['package.json'] ?? null)) !== null) {
            $deps = array_merge($package['dependencies'] ?? [], $package['devDependencies'] ?? []);
            $bun = $has('bun.lockb') || $has('bun.lock');
            $api = ['@nestjs/core' => 'NestJS', 'express' => 'Express', 'fastify' => 'Fastify', 'hono' => 'Hono', 'koa' => 'Koa'];

            foreach (['next' => ['nextjs', 'Next.js'], 'nuxt' => ['nuxt', 'Nuxt'], '@sveltejs/kit' => ['sveltekit', 'SvelteKit']] as $dep => [$template, $label]) {
                if (isset($deps[$dep])) {
                    return $this->found($template, $label, self::TYPE_WEB, "package.json depends on {$dep}.");
                }
            }
            if (isset($deps['astro'])) {
                return $this->found('astro', 'Astro', self::TYPE_STATIC, 'package.json depends on astro.');
            }
            if (isset($deps['@remix-run/node']) || isset($deps['@remix-run/react'])) {
                return $this->found('remix', 'Remix', self::TYPE_WEB, 'package.json depends on Remix.');
            }
            if (isset($deps['@angular/core'])) {
                return $this->found('angular', 'Angular', self::TYPE_STATIC, 'package.json depends on @angular/core.');
            }
            if (isset($deps['@nestjs/core']) && ! $bun) {
                return $this->found('nestjs', 'NestJS', self::TYPE_API, 'package.json depends on @nestjs/core.');
            }
            foreach ($api as $dep => $label) {
                if (isset($deps[$dep])) {
                    return $this->found($bun ? 'bun' : 'node', $label, self::TYPE_API, "package.json depends on {$dep}.");
                }
            }
            if (isset($deps['vite']) && ! isset($package['scripts']['start'])) {
                return $this->found('vite-spa', 'Vite', self::TYPE_STATIC, 'Builds with Vite and has no server to start.');
            }

            return $bun
                ? $this->found('bun', 'Bun', self::TYPE_APP, 'Has a Bun lockfile.')
                : $this->found('node', 'Node.js', self::TYPE_APP, 'Has a package.json.');
        }

        if ($has('deno.json') || $has('deno.jsonc')) {
            return $this->found('deno', 'Deno', self::TYPE_APP, 'Has a deno.json.');
        }

        if (str_contains($contents['mix.exs'] ?? '', ':phoenix')) {
            return $this->found('phoenix', 'Phoenix', self::TYPE_WEB, 'mix.exs depends on phoenix.');
        }

        $python = strtolower(($contents['requirements.txt'] ?? '')."\n".($contents['pyproject.toml'] ?? ''));
        if (trim($python) !== '') {
            return match (true) {
                str_contains($python, 'django') => $this->found('django', 'Django', self::TYPE_WEB, 'Python dependencies include Django.'),
                str_contains($python, 'fastapi') => $this->found('fastapi', 'FastAPI', self::TYPE_API, 'Python dependencies include FastAPI.'),
                str_contains($python, 'flask') => $this->found('flask', 'Flask', self::TYPE_WEB, 'Python dependencies include Flask.'),
                default => $this->found('auto-build', 'Python', self::TYPE_APP, 'Has Python dependencies but no web framework ServerOS knows by name, so Railpack builds it.'),
            };
        }
        if ($has('Pipfile')) {
            return $this->found('auto-build', 'Python', self::TYPE_APP, 'Has a Pipfile, so Railpack builds it with pipenv.');
        }

        if (str_contains(strtolower($contents['Gemfile'] ?? ''), 'rails')) {
            return $this->found('rails', 'Ruby on Rails', self::TYPE_WEB, 'The Gemfile includes rails.');
        }
        if ($has('Gemfile')) {
            return $this->found('auto-build', 'Ruby', self::TYPE_APP, 'Has a Gemfile, so Railpack builds it with Bundler.');
        }

        if ($has('go.mod')) {
            $go = $contents['go.mod'] ?? '';
            $api = preg_match('#gin-gonic/gin|labstack/echo|gofiber/fiber|go-chi/chi#', $go) === 1;

            return $this->found('go', 'Go', $api ? self::TYPE_API : self::TYPE_APP, 'Has a go.mod.');
        }

        if ($has('Cargo.toml')) {
            $api = preg_match('/^\s*(axum|actix-web|rocket|warp)\s*=/m', $contents['Cargo.toml'] ?? '') === 1;

            return $this->found('rust', 'Rust', $api ? self::TYPE_API : self::TYPE_APP, 'Has a Cargo.toml.');
        }

        if ($has('pom.xml') || $has('build.gradle') || $has('build.gradle.kts')) {
            return $this->found('spring-boot', 'Spring Boot', self::TYPE_WEB, 'Has a Maven or Gradle build.');
        }

        if ($hasAny('/\.(csproj|sln)$/')) {
            return $this->found('dotnet', '.NET', self::TYPE_WEB, 'Has a .NET project file.');
        }

        if ($has('mix.exs')) {
            return $this->found('auto-build', 'Elixir', self::TYPE_APP, 'Has a mix.exs, so Railpack builds it.');
        }

        if ($has('index.html')) {
            return $this->found('static-site', 'Static site', self::TYPE_STATIC, 'Has an index.html and nothing to build.');
        }

        if ($has('Procfile')) {
            return $this->found('auto-build', 'Procfile', self::TYPE_APP, 'Has a Procfile, so Railpack starts it the way that says.');
        }

        return null;
    }

    public const PHP_VERSIONS = ['7.4', '8.0', '8.1', '8.2', '8.3', '8.4'];

    /**
     * @param  array<string, mixed>  $composer
     * @return array{0: string, 1: string}
     */
    public static function phpFor(array $composer): array
    {
        $laravel = self::major($composer['require']['laravel/framework'] ?? null);
        if ($laravel !== null && $laravel < 11) {
            $php = match (true) {
                $laravel <= 6 => '7.4',
                $laravel <= 8 => '8.1',
                $laravel === 9 => '8.2',
                default => '8.3',
            };

            return [$php, "Laravel {$laravel} runs on PHP {$php} at the newest."];
        }

        $constraint = (string) ($composer['require']['php'] ?? '');
        if ($constraint !== '') {
            foreach (array_reverse(self::PHP_VERSIONS) as $version) {
                if (self::allows($constraint, $version)) {
                    return [$version, "composer.json asks for PHP {$constraint}."];
                }
            }
        }

        return ['8.4', 'No PHP version is pinned, so the newest.'];
    }

    private static function major(mixed $constraint): ?int
    {
        return is_string($constraint) && preg_match('/(\d+)/', $constraint, $m) === 1 ? (int) $m[1] : null;
    }

    private static function allows(string $constraint, string $version): bool
    {
        foreach (preg_split('/\s*\|\|?\s*/', trim($constraint)) ?: [] as $alternative) {
            $alternative = trim($alternative);
            if ($alternative === '') {
                continue;
            }
            if (preg_match('/^v?([\d.]+)\s+-\s+v?([\d.]+)$/', $alternative, $range) === 1) {
                $alternative = ">={$range[1]} <=".self::upTo($range[2]);
            }
            $bounds = preg_split('/[\s,]+/', $alternative) ?: [];
            foreach (["{$version}.0", "{$version}.99"] as $candidate) {
                if (collect($bounds)->every(fn (string $bound) => self::meets($candidate, $bound))) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function meets(string $candidate, string $bound): bool
    {
        if (preg_match('/^(\^|~|>=|<=|>|<|!=|==|=)?\s*v?(\d+)(?:\.(\d+|\*))?(?:\.(\d+|\*))?/', $bound, $m) !== 1) {
            return true;
        }
        [$op, $major, $minor, $patch] = [$m[1], (int) $m[2], $m[3] ?? null, $m[4] ?? null];
        [$cMajor, $cMinor] = array_map('intval', explode('.', $candidate));

        if ($minor === '*' || $patch === '*') {
            return $cMajor === $major && ($minor === '*' || $cMinor === (int) $minor);
        }
        $low = $major.'.'.(int) ($minor ?? 0).'.'.(int) ($patch ?? 0);

        return match ($op) {
            '^' => version_compare($candidate, $low, '>=') && $cMajor === $major,
            '~' => version_compare($candidate, $low, '>=') && ($patch !== null ? $cMajor === $major && $cMinor === (int) $minor : $cMajor === $major),
            '>=' => version_compare($candidate, $low, '>='),
            '>' => version_compare($candidate, self::upTo($low), '>'),
            '<' => version_compare($candidate, $low, '<'),
            '<=' => version_compare($candidate, self::upTo($low), '<='),
            '!=' => true,
            default => $cMajor === $major && ($minor === null || $cMinor === (int) $minor),
        };
    }

    private static function upTo(string $version): string
    {
        $parts = explode('.', $version);

        return count(array_filter($parts, fn ($p) => $p !== '')) >= 3 ? $version : $parts[0].'.'.($parts[1] ?? '0').'.99';
    }

    /**
     * @return array{template: string, framework: string, type: string, reasons: list<string>, php?: string, healthPath?: string}
     */
    private function found(string $template, string $framework, string $type, string $reason): array
    {
        return ['template' => $template, 'framework' => $framework, 'type' => $type, 'reasons' => [$reason]];
    }

    /**
     * The branch and folder a /tree/ link points at. Branch names can have
     * slashes too, so the shortest prefix GitHub knows as a branch (or tag
     * or commit) wins, and the rest is the folder.
     *
     * @return array{0: string, 1: string|null, 2: array<string, mixed>}
     */
    private function branchAndFolder(string $owner, string $name, ?string $tree, string $default): array
    {
        $parts = $tree === null ? [] : array_values(array_filter(explode('/', $tree), fn (string $p) => $p !== ''));
        if ($parts === []) {
            $commit = $this->api("repos/{$owner}/{$name}/commits/".rawurlencode($default));
            if ($commit === null) {
                throw ValidationException::withMessages(['repository' => "Couldn’t find the {$default} branch."]);
            }

            return [$default, null, $commit];
        }

        for ($i = 1; $i <= min(count($parts), 4); $i++) {
            $branch = implode('/', array_slice($parts, 0, $i));
            $commit = $this->api("repos/{$owner}/{$name}/commits/".rawurlencode($branch));
            if ($commit === null) {
                continue;
            }
            $folder = implode('/', array_slice($parts, $i));
            if ($folder !== '' && preg_match(self::BASE_DIR_PATTERN, $folder) !== 1) {
                throw ValidationException::withMessages(['repository' => "{$folder} isn’t a folder ServerOS can build from."]);
            }

            return [$branch, $folder === '' ? null : $folder, $commit];
        }

        throw ValidationException::withMessages(['repository' => 'Couldn’t find the '.$parts[0].' branch.']);
    }

    private const GITHUB_PATTERN = '#^(?:https?://)?(?:www\.)?github\.com/([A-Za-z0-9-]+)/([A-Za-z0-9._-]+?)(?:\.git)?(?:/tree/([^?\#]+))?/?$#';

    /** Whether the panel reads it through GitHub: a github.com link, or owner/name. */
    public static function onGitHub(string $url): bool
    {
        $url = trim($url);

        return preg_match('#^([A-Za-z0-9-]+)/([A-Za-z0-9._-]+)$#', $url) === 1 || preg_match(self::GITHUB_PATTERN, $url) === 1;
    }

    /** @return array{0: string, 1: string, 2: string|null} */
    private function parse(string $url): array
    {
        $url = trim($url);
        $pattern = self::GITHUB_PATTERN;

        if (preg_match('#^([A-Za-z0-9-]+)/([A-Za-z0-9._-]+)$#', $url, $m)) {
            return [$m[1], $m[2], null];
        }
        if (preg_match($pattern, $url, $m)) {
            return [$m[1], $m[2], isset($m[3]) ? rtrim($m[3], '/') : null];
        }

        throw ValidationException::withMessages(['repository' => 'Paste a GitHub link, like https://github.com/you/app.']);
    }

    /**
     * @return array<array-key, mixed>|null an object, or a list for a folder's contents
     */
    private function api(string $path): ?array
    {
        try {
            $response = $this->client()->get("https://api.github.com/{$path}");
        } catch (ConnectionException) {
            throw ValidationException::withMessages(['repository' => 'Couldn’t reach GitHub. Try again in a moment.']);
        }

        if ($response->status() === 403 || $response->status() === 429) {
            throw ValidationException::withMessages(['repository' => 'GitHub is rate limiting us. Try again in a few minutes.']);
        }

        return $response->successful() ? $response->json() : null;
    }

    private function raw(string $owner, string $name, string $sha, string $file): ?string
    {
        try {
            $response = $this->client()->get("https://raw.githubusercontent.com/{$owner}/{$name}/{$sha}/{$file}");
        } catch (ConnectionException) {
            return null;
        }

        return $response->successful() ? $response->body() : null;
    }

    public static function notFound(?Workspace $workspace, string $owner): string
    {
        return match (true) {
            ! GitHubApp::enabled() || $workspace === null => 'That repository doesn’t exist, or it’s private. Only public GitHub repositories work for now.',
            GitHubApp::connected($workspace, $owner) => "That repository doesn’t exist, or isn’t one {$owner} shared with ServerOS. Add it to the ServerOS app’s repositories on GitHub, then try again.",
            default => 'That repository doesn’t exist, or it’s private. To deploy private repositories, connect GitHub in Settings → General.',
        };
    }

    /** @param  array<string, mixed>  $repo */
    public static function isPublic(array $repo): bool
    {
        return ($repo['private'] ?? false) === false && ($repo['visibility'] ?? 'public') === 'public';
    }

    private function client(): PendingRequest
    {
        $token = $this->token ?? config('services.github.token');

        return Http::timeout(8)
            ->withHeaders(['Accept' => 'application/vnd.github+json', 'User-Agent' => 'ServerOS'])
            ->when(is_string($token) && $token !== '', fn (PendingRequest $http) => $http->withToken((string) $token));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function json(?string $body): ?array
    {
        if ($body === null) {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }
}
