<?php

namespace App\Support\ServiceTemplates;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\DaemonFacts;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\BuildSettings;
use App\Support\Deploys\DeployRequest;
use App\Support\Deploys\EnvFile;
use App\Support\Projects\Placement;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class TemplateDeployment
{
    /** @var array<string, string> */
    public array $env = [];

    /** @var list<array{key: string, label: string, value: string}> */
    public array $generated = [];

    // Reused on redeploy: a database takes its password only once, and a new APP_KEY breaks encrypted data.
    /** @var list<string> */
    public array $kept = [];

    /** @var array<string, string>|null */
    private ?array $previousEnv = null;

    public string $url;

    // Null on a newer daemon for an app it reads the port of itself: an
    // image's or Dockerfile's EXPOSE, or what a Compose file publishes.
    public ?int $port;

    // Whether the machine's daemon reads build settings, so the spec gets them.
    public readonly bool $new;

    private ?BuildSettings $settings = null;

    /** @param  array<string, mixed>  $input */
    public function __construct(
        public readonly ServiceTemplate $template,
        public readonly Machine $machine,
        private readonly array $input,
    ) {
        $this->new = DaemonJobQueue::canDeployBuild($machine) && ($template->isApp() || $template->isImage());
        $build = is_array($input['build'] ?? null) ? $input['build'] : [];
        $given = isset($input['port']) ? (int) $input['port'] : null;
        if (array_key_exists('port', $build)) {
            $given = $build['port'] === null || $build['port'] === '' ? null : (int) $build['port'];
        }
        $this->port = match (true) {
            $template->portEditable && $given !== null => $given,
            $this->new && $template->portEditable && self::readsPort($template) => null,
            default => $template->port,
        };

        $domain = $this->domain();
        $this->url = match (true) {
            $domain !== null => (($input['https'] ?? true) ? 'https://' : 'http://').$domain,
            $this->port !== null => $machine->localAddress($this->port) ?? 'http://'.($machine->ip_address ?: 'localhost').':'.$this->port,
            default => 'http://'.($machine->ip_address ?: 'localhost'),
        };

        $this->resolveEnv();
    }

    /** Templates whose port the daemon can read itself, so leaving it empty works. */
    public static function readsPort(ServiceTemplate $template): bool
    {
        return $template->isImage() || $template->composeFromRepo() || ($template->isApp() && $template->dockerfile === 'Dockerfile');
    }

    /** @return array<string, mixed> */
    public function job(): array
    {
        $template = $this->template;

        $spec = [
            'type' => 'deploy',
            'service' => $this->input['service'],
            'repo' => $template->isApp() ? $this->input['repo'] : '',
            'commit' => $this->commit(),
            'files' => (object) [...$this->files(), ...$this->buildEnvFile()],
            'compose_file' => $this->composeFile(),
            'dockerfile' => $template->isApp() && ! $template->composeFromRepo() ? $template->dockerfile : null,
            'env' => (object) $this->env,
            'health' => $this->health(),
            'domains' => DeployRequest::domains($this->domain(), (bool) ($this->input['wildcard'] ?? false)),
            'port' => $this->port ?? $template->port,
        ];
        $build = is_array($this->input['build'] ?? null) ? $this->input['build'] : [];
        if (! $this->new && ($build === [] || ! ($template->isApp() || $template->isImage()))) {
            return $spec;
        }
        // An older daemon gets only what it reads (port, health, the Dockerfile).
        $settings = $this->new ? $this->settings() : BuildSettings::fromSpec([...$spec, 'env' => $this->env])->with($build);
        $spec = $settings->applyValues($settings->apply([...$spec, 'files' => $this->files(), 'env' => $this->env], $this->new), $this->env, $this->new);
        foreach (['env', 'files'] as $map) {
            $spec[$map] = (object) $spec[$map];
        }

        return $spec;
    }

    /**
     * How it's built and run, on a daemon that reads build settings: the
     * template's defaults, with what the request's `build` and `env_flags`
     * change. Custom apps get a health check that passes on any answer
     * below 500 after a minute to boot; a framework's known health route
     * (Laravel's /up) still has to answer 200.
     */
    public function settings(): BuildSettings
    {
        if ($this->settings !== null) {
            return $this->settings;
        }
        $template = $this->template;
        $method = match (true) {
            $template->isImage() => BuildSettings::IMAGE,
            $template->railpack() => BuildSettings::RAILPACK,
            $template->composeFromRepo() => BuildSettings::COMPOSE,
            $template->dockerfile !== null && $template->dockerfile !== 'Dockerfile' => BuildSettings::TEMPLATE,
            default => BuildSettings::DOCKERFILE,
        };
        $path = filled($this->input['health_path'] ?? null) ? (string) $this->input['health_path'] : ($template->health ?? '/');
        $known = $method === BuildSettings::TEMPLATE && $path !== '/';
        $health = match (true) {
            ! $template->web || $template->health === null => [...BuildSettings::CUSTOM_HEALTH, 'enabled' => false],
            $known => ['enabled' => true, 'path' => $path, 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => $template->healthRetries ?? 5, 'start_period_secs' => 60, 'interval_secs' => null],
            default => [...BuildSettings::CUSTOM_HEALTH, 'path' => $path, 'retries' => max(BuildSettings::CUSTOM_HEALTH['retries'], $template->healthRetries ?? 0)],
        };
        $build = is_array($this->input['build'] ?? null) ? $this->input['build'] : [];

        $settings = (new BuildSettings(
            method: $method,
            dockerfile: in_array($method, [BuildSettings::DOCKERFILE, BuildSettings::TEMPLATE], true) ? ($template->dockerfile ?? 'Dockerfile') : null,
            composeFile: $method === BuildSettings::COMPOSE ? $this->composeFile() : null,
            baseDir: filled($this->input['base_dir'] ?? null) ? (string) $this->input['base_dir'] : null,
            port: $this->port,
            health: $health,
            network: BuildSettings::NETWORK,
        ))->with(array_diff_key($build, ['port' => true]));
        $settings->port = $this->port;

        return $this->settings = $settings->withFlags((array) ($this->input['env_flags'] ?? []), array_map('strval', array_keys($this->env)));
    }

    /**
     * Turns down build settings this machine's daemon can't do, or that
     * don't fit together, keyed under build.
     *
     * @throws ValidationException
     */
    public function check(): void
    {
        if ($this->new) {
            $this->settings()->check($this->machine, 'build.', ! $this->template->isImage());

            return;
        }
        $build = is_array($this->input['build'] ?? null) ? $this->input['build'] : [];
        $flags = (array) ($this->input['env_flags'] ?? []);
        if ($build === [] && $flags === []) {
            return;
        }
        $spec = $this->job();
        $spec['env'] = (array) $spec['env'];
        BuildSettings::fromSpec($spec)->with($build)->withFlags($flags, array_map('strval', array_keys($this->env)))
            ->check($this->machine, 'build.', ! $this->template->isImage());
    }

    public function composeFile(): ?string
    {
        if ($this->template->isImage()) {
            return null;
        }
        if (! $this->template->isApp()) {
            return 'compose.yaml';
        }
        if (! $this->template->composeFromRepo()) {
            return null;
        }
        $given = $this->input['compose_file'] ?? null;

        return is_string($given) && $given !== '' ? $given : $this->template->compose;
    }

    /** @return array<string, string> */
    private function files(): array
    {
        $files = $this->template->files;
        $php = $this->input['php'] ?? null;
        if (is_string($php) && in_array($php, RepositoryDetector::PHP_VERSIONS, true)) {
            $files = array_map(fn (string $file) => (string) preg_replace('#serversideup/php:\d+\.\d+-#', "serversideup/php:{$php}-", $file), $files);
        }

        return $this->withoutStalwartPublicUrl($files);
    }

    /**
     * @param  array<string, string>  $files
     * @return array<string, string>
     */
    private function withoutStalwartPublicUrl(array $files): array
    {
        if ($this->template->slug !== 'stalwart' || isset($this->env['STALWART_PUBLIC_URL']) || ! isset($files['compose.yaml'])) {
            return $files;
        }

        $files['compose.yaml'] = str_replace(
            "    environment:\n      STALWART_PUBLIC_URL: \${STALWART_PUBLIC_URL}\n",
            '',
            $files['compose.yaml'],
        );

        return $files;
    }

    public static function usesPhp(ServiceTemplate $template): bool
    {
        return collect($template->files)->contains(fn (string $file) => str_contains($file, 'serversideup/php:'));
    }

    private const PUBLIC_ENV = '/^(NEXT_PUBLIC_|VITE_|NUXT_PUBLIC_|PUBLIC_|REACT_APP_|EXPO_PUBLIC_)/';

    /** @return array<string, string> */
    private function buildEnvFile(): array
    {
        if (! $this->template->isApp() || $this->template->composeFromRepo()) {
            return [];
        }

        $lines = [];
        foreach ($this->env as $key => $value) {
            if (preg_match(self::PUBLIC_ENV, (string) $key)) {
                $escaped = str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', ''], (string) $value);
                $lines[] = "{$key}=\"{$escaped}\"";
            }
        }

        return $lines === [] ? [] : ['.env.production.local' => implode("\n", $lines)."\n"];
    }

    public function commit(): string
    {
        if ($this->template->isApp()) {
            return strtolower((string) $this->input['commit']);
        }
        if ($this->template->isImage()) {
            return BuildSettings::imageCommit($this->image());
        }

        return sha1($this->template->slug."\n".json_encode($this->template->files));
    }

    public function image(): string
    {
        $build = is_array($this->input['build'] ?? null) ? $this->input['build'] : [];

        return trim((string) ($build['image'] ?? $this->input['image'] ?? ''));
    }

    private function domain(): ?string
    {
        $domain = $this->input['domain'] ?? null;

        return $this->template->web && filled($domain) ? strtolower((string) $domain) : null;
    }

    /**
     * @return array{path: string, expected_status: int, timeout_secs: int, retries: int}|null
     */
    private function health(): ?array
    {
        if (! $this->template->web) {
            return null;
        }

        $path = $this->template->isApp() && filled($this->input['health_path'] ?? null)
            ? (string) $this->input['health_path']
            : $this->template->health;

        return $path === null ? null : [
            'path' => $path,
            'expected_status' => 200,
            'timeout_secs' => 30,
            'retries' => $this->template->healthRetries ?? ($this->template->isApp() ? 5 : 8),
        ];
    }

    /** @return array<string, string> */
    private function previousEnv(): array
    {
        if ($this->previousEnv !== null) {
            return $this->previousEnv;
        }
        $service = $this->input['service'] ?? null;
        $last = ! is_string($service) || $service === '' || Placement::containers($this->machine, $service) === [] ? null : DaemonJob::query()
            ->where('machine_id', $this->machine->id)
            ->where('kind', 'deploy')
            ->where('service', $service)
            ->whereNotIn('state', ['refused', 'cancelled'])
            ->latest()
            ->first();

        return $this->previousEnv = array_map('strval', (array) ($last?->command['job']['env'] ?? []));
    }

    private function resolveEnv(): void
    {
        $given = $this->input['env'] ?? [];
        $errors = [];

        foreach ($this->template->env as $field) {
            $key = $field['key'];
            $value = isset($given[$key]) ? trim((string) $given[$key]) : '';

            if ($field['accept'] ?? false) {
                if (! in_array(strtolower($value), ['1', 'true', 'on', 'yes'], true)) {
                    $errors["env.{$key}"] = "You need to accept this to deploy {$this->template->name}.";
                }
                $this->env[$key] = 'TRUE';

                continue;
            }

            if ($value === '') {
                $default = $field['default'] ?? '';
                $value = $this->generate($default);

                if ($value !== $default && isset($this->previousEnv()[$key]) && $this->previousEnv()[$key] !== '') {
                    $value = $this->previousEnv()[$key];
                    $this->kept[] = $key;
                } elseif ($value !== $default && ($field['secret'] ?? false)) {
                    $this->generated[] = ['key' => $key, 'label' => $field['label'], 'value' => $value];
                }
            }

            if ($value === '' && ($field['required'] ?? false)) {
                $errors["env.{$key}"] = "{$field['label']} is required.";
            }

            $this->env[$key] = $value;
        }

        foreach ($this->parseExtraEnv($errors) as $key => $value) {
            $this->env[$key] = $value;
        }

        if ($this->port !== null) {
            $this->env['PORT'] = (string) $this->port;
        } else {
            unset($this->env['PORT']);
        }
        $this->env['DOCKER_BRIDGE'] = DaemonFacts::dockerBridge($this->machine->facts ?? []);
        $this->applyStalwartPublicUrl();

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * Stalwart publishes its sign-in URLs from this variable. The browser
     * stays on https when a tunnel or the machine proxy terminates TLS, so
     * a domain always becomes an https URL unless one was typed. With no
     * domain the variable is left unset, and the Compose file drops it, so
     * an empty value is not passed into the container.
     */
    private function applyStalwartPublicUrl(): void
    {
        if ($this->template->slug !== 'stalwart') {
            return;
        }

        $typed = trim((string) ($this->input['env']['STALWART_PUBLIC_URL'] ?? ''));
        if ($typed !== '') {
            $this->env['STALWART_PUBLIC_URL'] = $typed;

            return;
        }

        $domain = $this->domain();
        if ($domain !== null) {
            $this->env['STALWART_PUBLIC_URL'] = 'https://'.$domain;

            return;
        }

        unset($this->env['STALWART_PUBLIC_URL']);
    }

    private function generate(string $default): string
    {
        if (preg_match('/^\{(random|base64):([1-9]\d*)\}$/', $default, $m)) {
            $length = max(1, min(256, (int) $m[2]));

            return $m[1] === 'random'
                ? Str::random($length)
                : 'base64:'.base64_encode(random_bytes($length));
        }
        if ($default === '{url}') {
            return $this->url;
        }
        // {host}: the same address without the scheme, for apps that want a host name.
        if ($default === '{host}') {
            return (string) preg_replace('#^https?://#', '', $this->url);
        }
        // {jwt:role:KEY}: a long-lived HS256 token for role, signed with the value of the earlier field KEY.
        if (preg_match('/^\{jwt:([a-z_]+):([A-Z0-9_]+)\}$/', $default, $m) && ($this->env[$m[2]] ?? '') !== '') {
            $encode = fn (array $part): string => rtrim(strtr(base64_encode((string) json_encode($part)), '+/', '-_'), '=');
            $unsigned = $encode(['alg' => 'HS256', 'typ' => 'JWT']).'.'.$encode(['role' => $m[1], 'iss' => 'supabase', 'iat' => now()->timestamp, 'exp' => now()->addYears(10)->timestamp]);

            return $unsigned.'.'.rtrim(strtr(base64_encode(hash_hmac('sha256', $unsigned, $this->env[$m[2]], true)), '+/', '-_'), '=');
        }

        return $default;
    }

    /**
     * Settings pasted as a .env file: export, quotes, values over several
     * lines and comments all read as dotenv tools read them.
     *
     * @param  array<string, string>  $errors
     * @return array<string, string>
     */
    private function parseExtraEnv(array &$errors): array
    {
        $parsed = EnvFile::parse((string) ($this->input['extra_env'] ?? ''));
        if ($parsed['errors'] !== []) {
            $errors['extra_env'] = (string) reset($parsed['errors']);
        }

        return $parsed['variables'];
    }
}
