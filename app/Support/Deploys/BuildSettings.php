<?php

namespace App\Support\Deploys;

use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\ServiceTemplates\RepositoryDetector;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * How an app is built and run: everything in its deploy spec apart from the
 * code (repository and commit), its domains and its settings' values. The
 * same rules check a new deploy and an edit on the app's page, and apply()
 * writes the result into the spec the daemon gets. Daemons from
 * `daemon.deploy_build_since` read the new fields (build, volumes, release
 * and post-deploy commands, network, health start period); older ones are
 * never sent them.
 *
 * Settings each have two switches: runtime (the running app gets it, in
 * `env`) and build (the build can read it, in `build.args`). Both are on
 * unless someone turns one off.
 */
final class BuildSettings
{
    public const string RAILPACK = 'railpack';

    public const string DOCKERFILE = 'dockerfile';

    public const string COMPOSE = 'compose';

    public const string IMAGE = 'image';

    // ServerOS's own Dockerfile for a framework (a template's files).
    public const string TEMPLATE = 'template';

    /** What a person can choose. A template's own Dockerfile is only ever kept. */
    public const array METHODS = [self::RAILPACK, self::DOCKERFILE, self::COMPOSE, self::IMAGE];

    public const string NETWORK = 'serveros';

    public const string PUBLIC_ENV = '/^(NEXT_PUBLIC_|VITE_|NUXT_PUBLIC_|PUBLIC_|REACT_APP_|EXPO_PUBLIC_)/';

    public const string BUILD_ENV_FILE = '.env.production.local';

    public const string VOLUME_NAME_PATTERN = '/^[a-z0-9][a-z0-9_.-]{0,62}$/';

    // An absolute path in the container, never the root itself.
    public const string VOLUME_PATH_PATTERN = '#^(?!.*/\.\.?(?:/|$))/[A-Za-z0-9._@+-]+(?:/[A-Za-z0-9._@+-]+)*/?$#';

    // A file inside the repository: no leading slash, no part starting with a dot.
    public const string FILE_PATTERN = '#^[A-Za-z0-9_][A-Za-z0-9._-]*(?:/[A-Za-z0-9_][A-Za-z0-9._-]*)*$#';

    public const string TARGET_PATTERN = '/^[A-Za-z0-9][A-Za-z0-9_.-]{0,127}$/';

    // registry/name:tag or name@sha256:..., as `docker pull` takes it.
    public const string IMAGE_PATTERN = '#^[a-z0-9]+(?:[._-][a-z0-9]+)*(?::\d{1,5})?(?:/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*(?::[A-Za-z0-9_][A-Za-z0-9_.-]{0,127})?(?:@sha256:[a-f0-9]{64})?$#';

    public const int MAX_COMMAND = 4000;

    public const int MAX_VOLUMES = 20;

    /** @var array{enabled: bool, path: string, expected_status: int, timeout_secs: int, retries: int, start_period_secs: int, interval_secs: int|null} */
    public const array CUSTOM_HEALTH = ['enabled' => true, 'path' => '/', 'expected_status' => 0, 'timeout_secs' => 10, 'retries' => 5, 'start_period_secs' => 60, 'interval_secs' => 3];

    /**
     * @param  array{enabled: bool, path: string, expected_status: int, timeout_secs: int, retries: int, start_period_secs: int, interval_secs: int|null}  $health
     * @param  list<array{name: string, path: string}>  $volumes
     * @param  array<string, array{build: bool, runtime: bool}>  $flags  each setting's switches, by name
     */
    public function __construct(
        public string $method,
        public ?string $dockerfile = null,
        public ?string $target = null,
        public ?string $composeFile = null,
        public ?string $image = null,
        public ?string $installCommand = null,
        public ?string $buildCommand = null,
        public ?string $startCommand = null,
        public ?string $baseDir = null,
        public ?int $port = null,
        public array $health = self::CUSTOM_HEALTH,
        public array $volumes = [],
        public ?string $releaseCommand = null,
        public ?string $postDeployCommand = null,
        public bool $submodules = true,
        public bool $lfs = true,
        public ?string $network = null,
        public array $flags = [],
    ) {}

    /**
     * Rules for the settings as a request gives them, under $prefix (like
     * "build." in the deploy dialog, or nothing on the app's page). Every
     * field may be left out: an edit changes only what it names.
     *
     * @return array<string, list<mixed>>
     */
    public static function rules(string $prefix = ''): array
    {
        $command = ['nullable', 'string', 'max:'.self::MAX_COMMAND];

        return [
            $prefix.'method' => ['sometimes', 'string', Rule::in(self::METHODS)],
            $prefix.'dockerfile' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:'.self::FILE_PATTERN],
            $prefix.'target' => ['sometimes', 'nullable', 'string', 'regex:'.self::TARGET_PATTERN],
            $prefix.'compose_file' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:'.DeployRequest::COMPOSE_FILE_PATTERN],
            $prefix.'image' => ['sometimes', 'nullable', 'string', 'max:300', 'regex:'.self::IMAGE_PATTERN],
            $prefix.'install_command' => ['sometimes', ...$command],
            $prefix.'build_command' => ['sometimes', ...$command],
            $prefix.'start_command' => ['sometimes', ...$command],
            $prefix.'base_dir' => ['sometimes', 'nullable', 'string', 'max:200', 'regex:'.RepositoryDetector::BASE_DIR_PATTERN],
            $prefix.'port' => ['sometimes', 'nullable', 'integer', 'between:1,65535'],
            $prefix.'health' => ['sometimes', 'array'],
            $prefix.'health.enabled' => ['sometimes', 'boolean'],
            $prefix.'health.path' => ['sometimes', 'string', 'max:200', 'regex:/^\/[\x21-\x7e]*$/'],
            $prefix.'health.expected_status' => ['sometimes', 'integer', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((int) $value !== 0 && ((int) $value < 100 || (int) $value > 599)) {
                    $fail('Use 0 for any answer below 500, or an HTTP status like 200.');
                }
            }],
            $prefix.'health.timeout_secs' => ['sometimes', 'integer', 'between:1,120'],
            $prefix.'health.retries' => ['sometimes', 'integer', 'between:1,60'],
            $prefix.'health.start_period_secs' => ['sometimes', 'integer', 'between:0,900'],
            $prefix.'health.interval_secs' => ['sometimes', 'nullable', 'integer', 'between:1,300'],
            $prefix.'volumes' => ['sometimes', 'array', 'max:'.self::MAX_VOLUMES],
            $prefix.'volumes.*' => ['array:name,path'],
            $prefix.'volumes.*.name' => ['required', 'string', 'regex:'.self::VOLUME_NAME_PATTERN, 'distinct'],
            $prefix.'volumes.*.path' => ['required', 'string', 'max:500', 'regex:'.self::VOLUME_PATH_PATTERN, 'distinct'],
            $prefix.'release_command' => ['sometimes', ...$command],
            $prefix.'post_deploy_command' => ['sometimes', ...$command],
            $prefix.'submodules' => ['sometimes', 'boolean'],
            $prefix.'lfs' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(string $prefix = ''): array
    {
        return [
            $prefix.'method.in' => 'Choose Railpack, a Dockerfile, a Compose file or an image.',
            $prefix.'dockerfile.regex' => 'Give the Dockerfile’s path in the repository, like Dockerfile or docker/web.Dockerfile.',
            $prefix.'target.regex' => 'That isn’t a build stage name.',
            $prefix.'compose_file.regex' => 'Give the Compose file’s path in the repository, like compose.yaml.',
            $prefix.'image.regex' => 'Give an image like ghcr.io/you/app:1.4 or nginx:alpine.',
            $prefix.'base_dir.regex' => 'Give a folder in the repository, like apps/web.',
            $prefix.'health.path.regex' => 'Give a path starting with /, like /health.',
            $prefix.'volumes.*.name.regex' => 'Name it with lowercase letters, digits, dots, dashes and underscores, like data.',
            $prefix.'volumes.*.name.distinct' => 'Two volumes have that name.',
            $prefix.'volumes.*.path.regex' => 'Give a folder in the container, like /data.',
            $prefix.'volumes.*.path.distinct' => 'Two volumes go to that folder.',
        ];
    }

    /** Settings a framework's build bakes into the page (NEXT_PUBLIC_ and the like). */
    public static function isPublic(string $name): bool
    {
        return preg_match(self::PUBLIC_ENV, $name) === 1;
    }

    /**
     * The settings a deploy spec says, by name and switch. Values are never
     * read out of it, so nothing here can show one.
     *
     * @param  array<string, mixed>  $spec
     */
    public static function fromSpec(array $spec): self
    {
        $build = is_array($spec['build'] ?? null) ? $spec['build'] : null;
        $dockerfile = is_string($spec['dockerfile'] ?? null) && $spec['dockerfile'] !== '' ? $spec['dockerfile'] : null;
        $compose = is_string($spec['compose_file'] ?? null) && $spec['compose_file'] !== '' ? $spec['compose_file'] : null;
        $method = match (true) {
            ($build['method'] ?? null) === self::IMAGE => self::IMAGE,
            ($build['method'] ?? null) === self::RAILPACK => self::RAILPACK,
            $compose !== null => self::COMPOSE,
            $dockerfile !== null && str_starts_with($dockerfile, '.serveros/') => self::TEMPLATE,
            default => self::DOCKERFILE,
        };
        $text = fn (mixed $value): ?string => is_string($value) && $value !== '' ? $value : null;

        $health = is_array($spec['health'] ?? null) ? $spec['health'] : null;

        return new self(
            method: $method,
            dockerfile: $method === self::DOCKERFILE || $method === self::TEMPLATE ? ($dockerfile ?? 'Dockerfile') : null,
            target: $text($build['target'] ?? null),
            composeFile: $method === self::COMPOSE ? $compose : null,
            image: $method === self::IMAGE ? $text($build['image'] ?? null) : null,
            installCommand: $text($build['install_command'] ?? null),
            buildCommand: $text($build['build_command'] ?? null),
            startCommand: $text($build['start_command'] ?? null),
            baseDir: $text($spec['base_dir'] ?? null),
            port: is_int($spec['port'] ?? null) ? $spec['port'] : null,
            health: $health === null ? [...self::CUSTOM_HEALTH, 'enabled' => false] : [
                'enabled' => true,
                'path' => is_string($health['path'] ?? null) ? $health['path'] : '/',
                'expected_status' => (int) ($health['expected_status'] ?? 200),
                'timeout_secs' => (int) ($health['timeout_secs'] ?? 30),
                'retries' => (int) ($health['retries'] ?? 5),
                'start_period_secs' => (int) ($health['start_period_secs'] ?? 0),
                'interval_secs' => isset($health['interval_secs']) ? (int) $health['interval_secs'] : null,
            ],
            volumes: array_values(array_filter(array_map(
                fn ($v) => is_array($v) && is_string($v['name'] ?? null) && is_string($v['path'] ?? null) ? ['name' => $v['name'], 'path' => $v['path']] : null,
                is_array($spec['volumes'] ?? null) ? $spec['volumes'] : [],
            ))),
            releaseCommand: $text($spec['release_command'] ?? null),
            postDeployCommand: $text($spec['post_deploy_command'] ?? null),
            submodules: (bool) ($build['submodules'] ?? true),
            lfs: (bool) ($build['lfs'] ?? true),
            network: $text($spec['network'] ?? null),
            flags: self::flagsOf($spec),
        );
    }

    /**
     * Each setting's switches as a spec has them: in `env` is runtime, in
     * `build.args` is build. A spec from before build settings has no args,
     * so only a framework's public settings reached its build there.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, array{build: bool, runtime: bool}>
     */
    public static function flagsOf(array $spec): array
    {
        $env = array_keys(self::map($spec['env'] ?? null));
        $build = is_array($spec['build'] ?? null) ? $spec['build'] : null;
        $args = $build === null ? null : array_keys(self::map($build['args'] ?? null));
        $buildsCode = blank($spec['compose_file'] ?? null) && ($build['method'] ?? null) !== self::IMAGE;

        $flags = [];
        foreach ($env as $name) {
            $flags[(string) $name] = ['build' => $args === null ? $buildsCode && self::isPublic((string) $name) : in_array($name, $args, true), 'runtime' => true];
        }
        foreach ($args ?? [] as $name) {
            $flags[(string) $name] ??= ['build' => true, 'runtime' => false];
        }
        ksort($flags);

        return $flags;
    }

    /**
     * An edit: what the request names, over the settings the app has now.
     *
     * @param  array<string, mixed>  $data  validated against rules()
     */
    public function with(array $data): self
    {
        $next = clone $this;
        $text = fn (string $key, ?string $current): ?string => array_key_exists($key, $data)
            ? (is_string($data[$key]) && trim($data[$key]) !== '' ? trim($data[$key]) : null)
            : $current;

        if (array_key_exists('method', $data) && is_string($data['method'])) {
            $next->method = $data['method'];
        }
        $next->dockerfile = $text('dockerfile', $this->dockerfile);
        $next->target = $text('target', $this->target);
        $next->composeFile = $text('compose_file', $this->composeFile);
        $next->image = $text('image', $this->image);
        $next->installCommand = $text('install_command', $this->installCommand);
        $next->buildCommand = $text('build_command', $this->buildCommand);
        $next->startCommand = $text('start_command', $this->startCommand);
        $next->baseDir = array_key_exists('base_dir', $data) ? (filled($data['base_dir']) ? trim((string) $data['base_dir'], " /\t") : null) : $this->baseDir;
        $next->releaseCommand = $text('release_command', $this->releaseCommand);
        $next->postDeployCommand = $text('post_deploy_command', $this->postDeployCommand);
        if (array_key_exists('port', $data)) {
            $next->port = $data['port'] === null || $data['port'] === '' ? null : (int) $data['port'];
        }
        if (is_array($data['health'] ?? null)) {
            $health = $data['health'];
            // Setting how it's checked turns the check on, unless it says otherwise.
            $next->health = [
                'enabled' => array_key_exists('enabled', $health) ? (bool) $health['enabled'] : ($this->health['enabled'] || $health !== []),
                'path' => is_string($health['path'] ?? null) ? $health['path'] : $this->health['path'],
                'expected_status' => array_key_exists('expected_status', $health) ? (int) $health['expected_status'] : $this->health['expected_status'],
                'timeout_secs' => array_key_exists('timeout_secs', $health) ? (int) $health['timeout_secs'] : $this->health['timeout_secs'],
                'retries' => array_key_exists('retries', $health) ? (int) $health['retries'] : $this->health['retries'],
                'start_period_secs' => array_key_exists('start_period_secs', $health) ? (int) $health['start_period_secs'] : $this->health['start_period_secs'],
                'interval_secs' => array_key_exists('interval_secs', $health) ? ($health['interval_secs'] === null ? null : (int) $health['interval_secs']) : $this->health['interval_secs'],
            ];
        }
        if (array_key_exists('volumes', $data) && is_array($data['volumes'])) {
            $next->volumes = array_values(array_map(fn (array $v) => ['name' => (string) $v['name'], 'path' => rtrim((string) $v['path'], '/') ?: '/'], $data['volumes']));
        }
        foreach (['submodules', 'lfs'] as $key) {
            if (array_key_exists($key, $data)) {
                $next->{$key} = (bool) $data[$key];
            }
        }

        // Fields that only mean something for one method are dropped with it.
        if ($next->method !== self::RAILPACK) {
            $next->installCommand = $next->buildCommand = $next->startCommand = null;
        }
        if (! in_array($next->method, [self::DOCKERFILE, self::TEMPLATE], true)) {
            $next->target = null;
        }
        if ($next->method === self::DOCKERFILE) {
            $next->dockerfile ??= 'Dockerfile';
        }

        return $next;
    }

    /** @return array<string, list<mixed>> */
    public static function flagRules(): array
    {
        return [
            'env_flags' => ['sometimes', 'nullable', 'array', 'max:'.EnvFile::MAX_VARIABLES],
            'env_flags.*' => ['string', Rule::in(['build', 'runtime', 'both'])],
        ];
    }

    /**
     * Each setting's switches from a request: "build", "runtime" or "both"
     * by name. Names not given keep theirs (both, for a new one).
     *
     * @param  array<array-key, mixed>  $given
     * @param  list<string>  $names  the settings the app will have
     *
     * @throws ValidationException
     */
    public function withFlags(array $given, array $names): self
    {
        $next = clone $this;
        $flags = [];
        foreach ($names as $name) {
            $flags[$name] = $this->flags[$name] ?? ['build' => true, 'runtime' => true];
        }
        foreach ($given as $name => $choice) {
            if (! is_string($name) || preg_match(EnvFile::NAME_PATTERN, $name) !== 1) {
                throw ValidationException::withMessages(['env_flags' => "{$name} isn’t a setting name."]);
            }
            if (! isset($flags[$name])) {
                continue;
            }
            $flags[$name] = ['build' => $choice !== 'runtime', 'runtime' => $choice !== 'build'];
        }
        ksort($flags);
        $next->flags = $flags;

        return $next;
    }

    /**
     * Turns down what this machine's daemon can't do, and what doesn't fit
     * together, keyed under $prefix.
     *
     * @throws ValidationException
     */
    public function check(Machine $machine, string $prefix = '', bool $hasRepo = true): void
    {
        $errors = [];
        $new = DaemonJobQueue::canDeployBuild($machine);
        $old = $machine->name.'’s daemon is too old for this. It updates itself, or run “sudo serverosd update” on the server, then deploy again.';

        if (! $new) {
            $newOnly = [
                'method' => in_array($this->method, [self::RAILPACK, self::IMAGE], true),
                'target' => $this->target !== null,
                'volumes' => $this->volumes !== [],
                'release_command' => $this->releaseCommand !== null,
                'post_deploy_command' => $this->postDeployCommand !== null,
                'submodules' => ! $this->submodules,
                'lfs' => ! $this->lfs,
                'health.expected_status' => $this->health['enabled'] && $this->health['expected_status'] === 0,
                'health.start_period_secs' => $this->health['enabled'] && $this->health['start_period_secs'] > 0,
                'health.interval_secs' => $this->health['enabled'] && $this->health['interval_secs'] !== null,
                'dockerfile' => $this->method === self::DOCKERFILE && $this->dockerfile !== null && $this->dockerfile !== 'Dockerfile',
            ];
            foreach (array_keys(array_filter($newOnly)) as $field) {
                $errors[$prefix.$field] = $old;
            }
            foreach ($this->flags as $flags) {
                if (! $flags['runtime']) {
                    $errors['env_flags'] = $old;
                    break;
                }
            }
        }

        if ($this->method === self::IMAGE && $this->image === null) {
            $errors[$prefix.'image'] = 'Give the image to run, like ghcr.io/you/app:1.4.';
        }
        if (! $hasRepo && $this->method !== self::IMAGE) {
            $errors[$prefix.'method'] = 'This app runs a prebuilt image, so there’s no code to build. Deploy the repository as a new app instead.';
        }
        if (in_array($this->method, [self::COMPOSE, self::IMAGE], true) && $this->baseDir !== null) {
            $errors[$prefix.'base_dir'] = $this->method === self::COMPOSE ? 'A Compose file is named by its path instead: leave the folder empty.' : 'An image has no folder to build from: leave it empty.';
        }
        if ($this->method === self::COMPOSE) {
            foreach (['volumes' => $this->volumes !== [], 'release_command' => $this->releaseCommand !== null, 'post_deploy_command' => $this->postDeployCommand !== null] as $field => $set) {
                if ($set) {
                    $errors[$prefix.$field] = 'A Compose app sets this in its Compose file.';
                }
            }
        }
        if ($this->port === null && ! $new && $this->method !== self::COMPOSE) {
            $errors[$prefix.'port'] = 'Give the port the app listens on.';
        }
        if (filled($this->baseDir) && ! DaemonJobQueue::canBuildFromFolder($machine)) {
            $errors[$prefix.'base_dir'] = $machine->name.'’s daemon is too old to build an app from a folder of its repository. It updates itself, or run “sudo serverosd update” on the server, then deploy again.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Writes these settings into a deploy spec, leaving its code, domains and
     * settings' values as they are. $new is whether the machine's daemon
     * reads the new fields; nothing it can't read is ever written.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, mixed>
     */
    public function apply(array $spec, bool $new): array
    {
        unset($spec['build'], $spec['volumes'], $spec['release_command'], $spec['post_deploy_command'], $spec['network']);
        $files = self::map($spec['files'] ?? null);

        switch ($this->method) {
            case self::COMPOSE:
                $spec['compose_file'] = $this->composeFile ?? 'compose.yaml';
                unset($spec['dockerfile'], $spec['base_dir']);
                $files = self::withoutTemplateFiles($files);
                break;
            case self::IMAGE:
                $spec['repo'] = '';
                $spec['commit'] = self::imageCommit((string) $this->image);
                unset($spec['dockerfile'], $spec['compose_file'], $spec['base_dir']);
                $files = [];
                break;
            case self::RAILPACK:
                unset($spec['dockerfile'], $spec['compose_file']);
                $files = self::withoutTemplateFiles($files);
                break;
            case self::TEMPLATE:
                unset($spec['compose_file']);
                $spec['dockerfile'] ??= '.serveros/Dockerfile';
                break;
            default:
                unset($spec['compose_file']);
                $spec['dockerfile'] = $this->dockerfile ?? 'Dockerfile';
                $files = self::withoutTemplateFiles($files);
        }
        if (! in_array($this->method, [self::COMPOSE, self::IMAGE], true)) {
            if ($this->baseDir !== null) {
                $spec['base_dir'] = $this->baseDir;
            } else {
                unset($spec['base_dir']);
            }
        }
        $spec['files'] = $files;

        $spec['port'] = $this->port ?? ($new ? null : 80);
        $spec['health'] = $this->healthSpec($new);

        if ($new) {
            if ($this->method !== self::COMPOSE) {
                $spec['build'] = array_filter([
                    'method' => match ($this->method) {
                        self::RAILPACK => self::RAILPACK,
                        self::IMAGE => self::IMAGE,
                        default => self::DOCKERFILE,
                    },
                    'image' => $this->method === self::IMAGE ? $this->image : null,
                    'target' => $this->target,
                    'install_command' => $this->installCommand,
                    'build_command' => $this->buildCommand,
                    'start_command' => $this->startCommand,
                    'submodules' => $this->submodules,
                    'lfs' => $this->lfs,
                ], fn ($value) => $value !== null);
                // Only ever set when chosen: an app deployed before keeps Docker's bridge.
                if ($this->network !== null) {
                    $spec['network'] = $this->network;
                }
                if ($this->volumes !== []) {
                    $spec['volumes'] = $this->volumes;
                }
            }
            if ($this->releaseCommand !== null) {
                $spec['release_command'] = $this->releaseCommand;
            }
            if ($this->postDeployCommand !== null) {
                $spec['post_deploy_command'] = $this->postDeployCommand;
            }
        }

        return $spec;
    }

    /**
     * Puts settings' values where their switches say, keeping the rest:
     * runtime ones in `env`, build ones in `build.args` (on daemons that
     * read it), and a framework's public ones in the build's
     * .env.production.local too, so a Next.js or Vite build bakes them in.
     *
     * @param  array<string, mixed>  $spec  after apply()
     * @param  array<string, string>  $values  every setting's value, by name
     * @param  bool  $port  whether PORT is set from the port (always on a newer daemon; an app an older one runs keeps what it had)
     * @return array<string, mixed>
     */
    public function applyValues(array $spec, array $values, bool $new, bool $port = true): array
    {
        // A Compose file and an image take every setting at runtime.
        $split = $new && in_array($this->method, [self::RAILPACK, self::DOCKERFILE, self::TEMPLATE], true);
        $env = [];
        $args = [];
        foreach ($values as $name => $value) {
            $flags = $this->flags[$name] ?? ['build' => true, 'runtime' => true];
            if ($flags['runtime'] || ! $split) {
                $env[$name] = $value;
            }
            if ($flags['build'] && $split) {
                $args[$name] = $value;
            }
        }
        if ($this->port !== null && ($new || $port)) {
            $env['PORT'] = (string) $this->port;
        }
        if ($this->method === self::RAILPACK && ! array_key_exists('HOST', $values)) {
            $env['HOST'] = '0.0.0.0';
        }
        ksort($env);
        ksort($args);
        $spec['env'] = $env;
        if ($new && is_array($spec['build'] ?? null)) {
            if ($args !== []) {
                $spec['build']['args'] = $args;
            } else {
                unset($spec['build']['args']);
            }
        }

        $files = self::map($spec['files'] ?? null);
        unset($files[self::BUILD_ENV_FILE]);
        if (in_array($this->method, [self::DOCKERFILE, self::TEMPLATE], true)) {
            $lines = [];
            foreach ($values as $name => $value) {
                if (self::isPublic($name) && ($this->flags[$name]['build'] ?? true)) {
                    $lines[] = $name.'="'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', ''], $value).'"';
                }
            }
            if ($lines !== []) {
                $files[self::BUILD_ENV_FILE] = implode("\n", $lines)."\n";
            }
        }
        $spec['files'] = $files;

        return $spec;
    }

    /**
     * Every setting's value a spec holds, from `env` and `build.args`,
     * without the ones ServerOS adds itself.
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, string>
     */
    public static function valuesOf(array $spec): array
    {
        $build = is_array($spec['build'] ?? null) ? $spec['build'] : [];
        $values = [...self::map($build['args'] ?? null), ...self::map($spec['env'] ?? null)];

        return array_map('strval', $values);
    }

    /**
     * @return array{path: string, expected_status: int, timeout_secs: int, retries: int, start_period_secs?: int, interval_secs?: int}|null
     */
    private function healthSpec(bool $new): ?array
    {
        if (! $this->health['enabled']) {
            return null;
        }
        $health = [
            'path' => $this->health['path'],
            'expected_status' => $this->health['expected_status'] === 0 && ! $new ? 200 : $this->health['expected_status'],
            'timeout_secs' => $this->health['timeout_secs'],
            'retries' => $this->health['retries'],
        ];
        if ($new) {
            $health['start_period_secs'] = $this->health['start_period_secs'];
            if ($this->health['interval_secs'] !== null) {
                $health['interval_secs'] = $this->health['interval_secs'];
            }
        }

        return $health;
    }

    /** The release name an image deploys as: the same image, the same name. */
    public static function imageCommit(string $image): string
    {
        return sha1("image\n".$image);
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private static function withoutTemplateFiles(array $files): array
    {
        return array_filter($files, fn (string $name) => ! str_starts_with($name, '.serveros/'), ARRAY_FILTER_USE_KEY);
    }

    /** @return array<string, string> */
    private static function map(mixed $value): array
    {
        if (is_object($value)) {
            $value = (array) $value;
        }
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (is_scalar($item) || $item === null) {
                $out[(string) $key] = (string) $item;
            }
        }

        return $out;
    }

    /**
     * For the app's page and the API: the settings, with each setting's
     * name and switches (never its value).
     *
     * @param  array<string, string>  $managed  settings a connection manages, by name: what it connects to
     * @return array<string, mixed>
     */
    public function toArray(array $managed = []): array
    {
        $own = ['PORT' => true, 'DOCKER_BRIDGE' => true];

        return [
            'method' => $this->method,
            'dockerfile' => $this->dockerfile,
            'target' => $this->target,
            'composeFile' => $this->composeFile,
            'image' => $this->image,
            'installCommand' => $this->installCommand,
            'buildCommand' => $this->buildCommand,
            'startCommand' => $this->startCommand,
            'baseDir' => $this->baseDir,
            'port' => $this->port,
            'health' => [
                'enabled' => $this->health['enabled'],
                'path' => $this->health['path'],
                'expectedStatus' => $this->health['expected_status'],
                'timeoutSecs' => $this->health['timeout_secs'],
                'retries' => $this->health['retries'],
                'startPeriodSecs' => $this->health['start_period_secs'],
                'intervalSecs' => $this->health['interval_secs'],
            ],
            'volumes' => $this->volumes,
            'releaseCommand' => $this->releaseCommand,
            'postDeployCommand' => $this->postDeployCommand,
            'submodules' => $this->submodules,
            'lfs' => $this->lfs,
            'network' => $this->network,
            'variables' => array_map(fn (string $name, array $flags) => [
                'name' => $name,
                'build' => $flags['build'],
                'runtime' => $flags['runtime'],
                'from' => $managed[$name] ?? null,
                'auto' => isset($own[$name]) || ($name === 'HOST' && $this->method === self::RAILPACK),
            ], array_keys($this->flags), $this->flags),
        ];
    }
}
