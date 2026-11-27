<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Support\Deploys\AppDeploys;
use App\Support\Deploys\AppEdits;
use App\Support\Deploys\AppLimits;
use App\Support\Deploys\BuildSettings;
use App\Support\Deploys\DeployOnPush;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

final class UpdateAppSettings extends Tool
{
    public function __construct(private readonly Deploys $deploys) {}

    public function name(): string
    {
        return 'update_app_settings';
    }

    public function title(): string
    {
        return 'Change an app’s settings';
    }

    public function description(): string
    {
        return 'Changes an app’s settings (its environment variables) or how it’s built and run, and deploys it again with them: the same code and domain, with only what’s named here changed. It works on an app whose last deploy failed too, so a wrong setting can be fixed. set adds or replaces settings by name ({"API_URL": "https://..."}); remove takes names to drop; env_flags says where each goes (build, runtime or both, the default). The rest stay as they are, and the answer only ever names settings, never their values. '
            .'build changes how it’s built and run (get_app shows it as build): the method (railpack, dockerfile, compose, image), the Dockerfile or stage, Railpack’s commands, the port, the health check (turn it off, any answer below 500 with expected_status 0, a start period), volumes, a release command (migrations, say) and a post-deploy command. Most of that needs an up-to-date daemon. '
            .'The commit that’s live is deployed again: an up-to-date daemon starts it beside the running container and switches over once it’s healthy, with no downtime, and rebuilds only when something the build reads changed (a frontend’s NEXT_PUBLIC_ settings, say); its data stays, since volumes and databases aren’t touched. '
            .'limits sets the most CPU and memory its container may use ({"cpus": 0.5, "memory_mb": 512}; null takes one off, and one left out stays), applied as it’s deployed again; get_app shows them, both as set and as the machine reports them applied. They work for apps that run as one container, not Compose stacks, and need an up-to-date daemon. '
            .'only_newest_push changes how deploying each push works for it (get_app’s deployOnPush.onlyNewest, on by default): on, a push that comes in while the app is deploying waits, and a newer push replaces it, so only the newest commit is built; off, every push deploys straight away. It’s saved without deploying anything. '
            .'Check with the person first: say the app, the machine and which settings change. app is the name it was deployed as (list_deploys or get_app shows it). Returns the queued job; follow it with get_job or list_deploys.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'app' => Schema::string('The app: the name it was deployed as (like shop), or app:<name>.', maxLength: 300),
            'set' => ['type' => 'object', 'description' => 'Settings to add or replace, by name, like {"API_URL": "https://api.example.com"}. Values are text.', 'additionalProperties' => ['type' => 'string']],
            'remove' => Schema::array(Schema::string('A setting’s name.', maxLength: 128), 'Names of settings to remove.', maxItems: 50),
            'limits' => [...Schema::object([
                'cpus' => ['type' => ['number', 'null'], 'description' => 'The most CPUs it may use, like 0.5 or 2. Null for no limit.', 'minimum' => AppLimits::MIN_CPUS],
                'memory_mb' => ['type' => ['integer', 'null'], 'description' => 'The most memory it may use, in MB, like 512 or 2048. Null for no limit.', 'minimum' => AppLimits::MIN_MEMORY_MB],
            ]), 'description' => 'CPU and memory limits for its container.'],
            'only_newest_push' => Schema::boolean('With deploying each push on: only build the newest of the pushes that wait while it deploys (on), or deploy every push straight away (off).'),
            'build' => Deploys::buildSchema('How it’s built and run: only the fields given change.'),
            'env_flags' => Deploys::envFlagsSchema(),
            'wait' => Deploys::waitSchema(),
        ], ['machine', 'app']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $args = $this->validate($arguments, [
            'machine' => ['required', Deploys::reference()],
            'app' => ['required', Deploys::reference()],
            'set' => ['sometimes', 'array', 'max:50'],
            'set.*' => ['nullable', 'string', 'max:10000'],
            'remove' => ['sometimes', 'array', 'max:50'],
            'remove.*' => ['string', 'max:128'],
            'only_newest_push' => ['sometimes', 'boolean'],
            'limits' => ['sometimes', 'array'],
            ...Deploys::buildRules(),
            'wait' => Deploys::waitRules(),
        ], BuildSettings::messages('build.'));

        $set = array_map(fn ($value) => (string) $value, (array) ($args['set'] ?? []));
        $remove = array_values(array_unique(array_map('strval', (array) ($args['remove'] ?? []))));
        $pushSetting = array_key_exists('only_newest_push', $args);
        $limitsGiven = array_key_exists('limits', $args);
        $build = is_array($args['build'] ?? null) ? $args['build'] : [];
        $flags = (array) ($args['env_flags'] ?? []);
        if ($set === [] && $remove === [] && ! $pushSetting && ! $limitsGiven && $build === [] && $flags === []) {
            throw new ToolError('Say what to change: set some settings, remove some, or give env_flags, build, limits or only_newest_push.');
        }
        foreach ([...array_keys($set), ...$remove] as $name) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name)) {
                throw new ToolError("{$name} isn’t a setting name: use letters, digits and underscores, not starting with a digit.");
            }
        }
        if (($both = array_intersect(array_keys($set), $remove)) !== []) {
            throw new ToolError(implode(', ', $both).' can’t be both set and removed.');
        }

        $machine = $context->machine($args['machine']);
        $app = Deploys::appName($context, $machine, $args['app']);
        $spec = AppDeploys::spec($machine, $app);
        if ($spec === null) {
            throw new ToolError("{$app} was never deployed as an app on {$machine->name}. list_deploys shows its deploys.");
        }
        $last = Deploys::lastDeploy($machine, $app);
        if ($limitsGiven && $last === null) {
            throw new ToolError("{$app} has no successful deploy on {$machine->name} to limit yet. Fix what failed first; get_app shows why.");
        }

        $limits = $limitsGiven ? $this->limits($machine, $app, $last, (array) $args['limits']) : null;
        $before = AppLimits::setting($machine, $app);
        $limitsChanged = $limits !== null && (($before['cpus'] ?? null) !== $limits['cpus'] || ($before['memoryMb'] ?? null) !== $limits['memory_mb']);

        if ($pushSetting) {
            $this->savePushSetting($context, $machine, $app, (bool) $args['only_newest_push']);
            if ($set === [] && $remove === [] && ! $limitsGiven && $build === [] && $flags === []) {
                return [
                    'machine' => ['id' => $machine->id, 'name' => $machine->name],
                    'app' => $app,
                    'deployOnPush' => DeployOnPush::setting($machine, $app),
                    'note' => 'Saved. Nothing was deployed.',
                ];
            }
        }

        $repo = (string) ($spec['repo'] ?? '');
        if (Deploys::fromUpload($repo) && ! (DeploySource::fromRepoUrl($repo)?->usable() ?? false)) {
            throw new ToolError('The code uploaded for the live version has been cleared, so it can’t be deployed again. Upload it again on the app’s page in the dashboard, then change its settings.');
        }

        $env = BuildSettings::valuesOf($spec);
        if (($missing = array_values(array_diff($remove, array_keys($env)))) !== []) {
            throw new ToolError(implode(', ', $missing).' isn’t one of '.$app.'’s settings. get_app lists them.');
        }
        $builds = Deploys::check(fn () => AppEdits::build($machine, $app, $spec, $build, 'build.'));
        $edit = Deploys::check(fn () => AppEdits::env($machine, $app, $builds['spec'], $set, $remove, $flags));
        $changed = array_values(array_filter(array_keys($set), fn ($name) => ($env[$name] ?? null) !== $set[$name]));
        if ($edit['changed'] === [] && $builds['changed'] === [] && ! $limitsChanged) {
            throw new ToolError($set === [] && $flags === [] && $build === [] ? 'Those limits are already set: nothing to change.' : 'That’s how it’s set already: nothing to change.');
        }
        if (($busy = AppDeploys::busy($machine, $app)) !== null) {
            throw new ToolError("{$app} is deploying now (job {$busy->id}). Wait for it to finish, then change its settings.");
        }

        $this->deploys->requireAvailable($machine);
        $spec = $edit['spec'];
        if (! AppDeploys::isImage($spec)) {
            $spec['commit'] = AppDeploys::liveCommit($machine, $app, $spec);
        }

        $old = $machine->app_settings;
        if ($limitsChanged) {
            AppLimits::store($machine, $app, $limits);
        }
        try {
            $job = $this->deploys->deploy($context, $machine, $spec);
        } catch (ToolError $e) {
            $machine->forceFill(['app_settings' => $old])->save();

            throw $e;
        }
        if ($limitsChanged) {
            $context->record('app.limits', $app, $machine, array_filter($limits, fn ($value) => $value !== null));
        }
        if ($builds['changed'] !== []) {
            $context->record('app.build', $app, $machine, ['changed' => $builds['changed']]);
        }
        $job = $context->waitForJob($job, (int) ($args['wait'] ?? 0));

        return [
            'jobId' => $job->id,
            'state' => $job->state,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'app' => $app,
            'commit' => filled($repo) && ! Deploys::fromUpload($repo) ? ($spec['commit'] ?? null) : null,
            'added' => array_values(array_filter($changed, fn ($name) => ! array_key_exists($name, $env))),
            'replaced' => array_values(array_filter($changed, fn ($name) => array_key_exists($name, $env))),
            'removed' => $remove,
            'settings' => array_map('strval', array_keys(BuildSettings::valuesOf($spec))),
            'buildChanged' => $builds['changed'],
            'job' => Present::job($job, 20),
            'deployOnPush' => $pushSetting ? DeployOnPush::setting($machine, $app) : null,
            'limits' => $limitsGiven ? AppLimits::setting($machine, $app) : null,
            'deploymentsUrl' => $context->url("/machines/{$machine->id}?job={$job->id}"),
            'next' => 'It’s deployed again with the new settings, and takes over once it passes its health check. Follow it with get_job (id '.$job->id.').',
        ];
    }

    /**
     * @param  array<string, mixed>  $given
     * @return array{cpus: float|null, memory_mb: int|null}
     */
    private function limits(Machine $machine, string $app, DaemonJob $live, array $given): array
    {
        if (($reason = AppLimits::unavailable($machine, $live)) !== null) {
            throw new ToolError($reason);
        }
        $current = AppLimits::setting($machine, $app);

        try {
            return AppLimits::validate($machine, [
                'cpus' => array_key_exists('cpus', $given) ? $given['cpus'] : $current['cpus'] ?? null,
                'memory_mb' => array_key_exists('memory_mb', $given) ? $given['memory_mb'] : $current['memoryMb'] ?? null,
            ]);
        } catch (ValidationException $e) {
            throw new ToolError(implode(' ', Arr::flatten($e->errors())));
        }
    }

    private function savePushSetting(McpContext $context, Machine $machine, string $app, bool $onlyNewest): void
    {
        $settings = $machine->app_settings ?? [];
        $before = DeployOnPush::setting($machine, $app)['onlyNewest'];
        $settings[$app] = [...($settings[$app] ?? []), 'push_only_newest' => $onlyNewest];
        $machine->forceFill(['app_settings' => $settings])->save();

        if ($before !== $onlyNewest) {
            $context->record('app.settings', $app, $machine, ['push_only_newest' => $onlyNewest]);
        }
    }
}
