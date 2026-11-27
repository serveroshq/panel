<?php

namespace App\Support\Deploys;

use App\Models\Machine;
use App\Support\DaemonJobQueue;
use App\Support\Projects\AppSettings;
use Illuminate\Validation\ValidationException;

/**
 * Changes to an app's setup, worked out on its latest spec: its build
 * settings, or its settings and their switches. Each gives the spec to
 * deploy and what changed; the same code runs on the dashboard and over
 * MCP and the API.
 */
final class AppEdits
{
    /**
     * @param  array<string, mixed>  $spec  the app's spec (AppDeploys::spec)
     * @param  array<string, mixed>  $data  validated against BuildSettings::rules()
     * @return array{spec: array<string, mixed>, changed: list<string>}
     *
     * @throws ValidationException
     */
    public static function build(Machine $machine, string $app, array $spec, array $data, string $prefix = ''): array
    {
        $new = DaemonJobQueue::canDeployBuild($machine);
        $current = BuildSettings::fromSpec($spec);
        $next = $current->with($data);
        $image = AppDeploys::isImage($spec);
        if ($image !== ($next->method === BuildSettings::IMAGE)) {
            throw ValidationException::withMessages([$prefix.'method' => $image
                ? 'This app runs a prebuilt image, so there’s no code to build. Deploy the repository as a new app instead.'
                : 'An app built from code can’t switch to a prebuilt image. Deploy the image as a new app instead.']);
        }
        $next->check($machine, $prefix, ! $image);
        if ($next->method === BuildSettings::COMPOSE && $next->port === null && ($spec['domains'] ?? []) !== []) {
            throw ValidationException::withMessages([$prefix.'port' => 'Give the port your Compose file publishes, so the domain has somewhere to send visitors.']);
        }

        $before = $current->toArray();
        $changed = array_keys(array_filter(
            array_diff_key($next->toArray(), ['variables' => true]),
            fn ($value, $key) => $before[$key] !== $value,
            ARRAY_FILTER_USE_BOTH,
        ));
        if ($changed === []) {
            return ['spec' => $spec, 'changed' => []];
        }

        $edited = $next->applyValues($next->apply($spec, $new), self::ownValues($spec, $current), $new, self::hasPort($spec));
        if (! $image) {
            $edited['commit'] = AppDeploys::liveCommit($machine, $app, $spec);
        }

        return ['spec' => $edited, 'changed' => $changed];
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  array<string, string>  $set  settings to add or change, by name
     * @param  list<string>  $remove
     * @param  array<array-key, mixed>  $flags  "build", "runtime" or "both" by name
     * @return array{spec: array<string, mixed>, changed: list<string>}
     *
     * @throws ValidationException
     */
    public static function env(Machine $machine, string $app, array $spec, array $set, array $remove, array $flags = [], bool $replace = false): array
    {
        $current = BuildSettings::fromSpec($spec);
        $values = self::ownValues($spec, $current);
        $managed = AppSettings::managedNames($machine, $app);
        if ($replace) {
            $remove = array_values(array_unique([...$remove, ...array_map('strval', array_keys(array_diff_key($values, $set, $managed, ['PORT' => true, 'DOCKER_BRIDGE' => true])))]));
        }

        foreach ([...array_keys($set), ...$remove] as $name) {
            if (preg_match(EnvFile::NAME_PATTERN, (string) $name) !== 1) {
                throw ValidationException::withMessages(['set' => "{$name} isn’t a setting name: use letters, digits and underscores, not starting with a digit."]);
            }
            if (isset($managed[$name])) {
                throw ValidationException::withMessages(['set' => "{$name} comes from the connection to {$managed[$name]}. Disconnect it to change it yourself."]);
            }
        }
        if (($both = array_intersect(array_map('strval', array_keys($set)), $remove)) !== []) {
            throw ValidationException::withMessages(['remove' => implode(', ', $both).' can’t be both changed and removed.']);
        }

        $next = array_diff_key([...$values, ...$set], array_flip($remove));
        $settings = $current->withFlags($flags, array_map('strval', array_keys($next)));
        $settings->check($machine);

        $changed = array_values(array_unique([
            ...array_map('strval', array_keys(array_filter($set, fn (string $value, string|int $name) => ($values[(string) $name] ?? null) !== $value, ARRAY_FILTER_USE_BOTH))),
            ...array_values(array_intersect($remove, array_map('strval', array_keys($values)))),
            ...array_map('strval', array_keys(array_filter($settings->flags, fn (array $f, string $name) => isset($current->flags[$name]) && $current->flags[$name] !== $f, ARRAY_FILTER_USE_BOTH))),
        ]));
        if ($changed === []) {
            return ['spec' => $spec, 'changed' => []];
        }

        $new = DaemonJobQueue::canDeployBuild($machine);
        $edited = $settings->applyValues($settings->apply($spec, $new), $next, $new, self::hasPort($spec));
        if (! AppDeploys::isImage($spec)) {
            $edited['commit'] = AppDeploys::liveCommit($machine, $app, $spec);
        }

        return ['spec' => $edited, 'changed' => $changed];
    }

    /** @param  array<string, mixed>  $spec */
    private static function hasPort(array $spec): bool
    {
        return array_key_exists('PORT', BuildSettings::valuesOf($spec));
    }

    /**
     * The settings' values a spec holds that are the person's own, not the
     * ones ServerOS adds as it deploys (PORT, and HOST for Railpack).
     *
     * @param  array<string, mixed>  $spec
     * @return array<string, string>
     */
    public static function ownValues(array $spec, BuildSettings $settings): array
    {
        $values = BuildSettings::valuesOf($spec);
        unset($values['PORT']);
        if ($settings->method === BuildSettings::RAILPACK && ($values['HOST'] ?? null) === '0.0.0.0') {
            unset($values['HOST']);
        }

        return $values;
    }
}
