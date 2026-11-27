<?php

namespace App\Support\Projects;

use App\Mcp\Tools\Deploy\Deploys;
use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Models\ProjectLink;
use App\Models\ProjectNode;
use App\Models\User;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppDeploys;
use App\Support\Deploys\AppEdits;
use App\Support\Deploys\BuildSettings;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class AppSettings
{
    public function __construct(private readonly DaemonJobQueue $queue) {}

    /**
     * @return array{names: list<array{name: string, from: string|null, build: bool}>, deployed: bool}
     */
    public function read(ProjectNode $app): array
    {
        return $this->readApp($app->machine, $app->name);
    }

    /**
     * @return array{names: list<array{name: string, from: string|null, build: bool}>, deployed: bool}
     */
    public function readApp(Machine $machine, string $name): array
    {
        $spec = self::specOf($machine, $name);
        $flags = $spec === null ? [] : BuildSettings::flagsOf($spec);
        $from = $this->managed($machine, $name);

        $names = array_keys($flags);
        sort($names);

        return [
            'names' => array_map(fn (string $name) => [
                'name' => $name,
                'from' => $from[$name] ?? null,
                'build' => $flags[$name]['build'],
            ], $names),
            'deployed' => $spec !== null,
        ];
    }

    /**
     * @param  array<string, string>  $set
     * @param  list<string>  $remove
     * @return list<string>
     */
    public function write(ProjectNode $app, array $set, array $remove, User $user): array
    {
        return $this->writeApp($app->machine, $app->name, $set, $remove, $user);
    }

    /**
     * Changes settings by name and deploys the app again with them, on the
     * commit that's live. A setting a frontend build bakes in (NEXT_PUBLIC_
     * and the like) reaches the new build too.
     *
     * @param  array<string, string>  $set
     * @param  list<string>  $remove
     * @return list<string>
     */
    public function writeApp(Machine $machine, string $name, array $set, array $remove, User $user): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['settings' => $message]);

        $last = self::lastDeploy($machine, $name);
        $spec = self::specOf($machine, $name);
        if ($spec === null || $last === null || ! $last->isRetryable()) {
            $fail("{$name} has no deploy to change yet.");
        }
        $repo = (string) ($spec['repo'] ?? '');
        if (Deploys::fromUpload($repo) && ! (DeploySource::fromRepoUrl($repo)?->usable() ?? false)) {
            $fail("The code uploaded for {$name}’s live version has been cleared. Upload it again first.");
        }

        try {
            $edit = AppEdits::env($machine, $name, $spec, $set, $remove);
        } catch (ValidationException $e) {
            $fail((string) collect($e->errors())->flatten()->first());
        }
        if ($edit['changed'] === []) {
            return [];
        }
        if (($reason = $this->queue->unavailable($machine, 'deploy')) !== null) {
            $fail($reason);
        }
        try {
            $this->queue->deploy($machine, $user, $edit['spec']);
        } catch (RuntimeException $e) {
            $fail($e->getMessage());
        }

        return $edit['changed'];
    }

    /**
     * The spec an app's settings are read from and written to: its latest
     * deploy as an app (failed or not), or a stack's latest deploy.
     *
     * @return array<string, mixed>|null
     */
    private static function specOf(Machine $machine, string $name): ?array
    {
        $spec = AppDeploys::spec($machine, $name);
        if ($spec !== null) {
            return $spec;
        }
        $last = self::lastDeploy($machine, $name);

        return $last?->command === null ? null : (array) ($last->command['job'] ?? []);
    }

    public static function lastDeploy(Machine $machine, string $name): ?DaemonJob
    {
        return Placement::latestDeploy($machine, $name) ?? DaemonJob::query()
            ->where('machine_id', $machine->id)
            ->where('kind', 'deploy')
            ->where('service', $name)
            ->latest()
            ->first();
    }

    /**
     * Settings a connection in a project manages, by name: what each one
     * connects the app to.
     *
     * @return array<string, string>
     */
    public static function managedNames(Machine $machine, string $name): array
    {
        return app(self::class)->managed($machine, $name);
    }

    /** @return array<string, string> */
    private function managed(Machine $machine, string $name): array
    {
        $apps = ProjectNode::query()->where('machine_id', $machine->id)->where('name', $name)->where('kind', 'app')->pluck('id');
        $from = [];
        foreach (ProjectLink::query()->whereIn('app_node_id', $apps)->where('state', 'applied')->with('resource')->get() as $link) {
            foreach ((array) ($link->env ?? []) as $name) {
                $from[(string) $name] = $link->resource->name;
            }
        }

        return $from;
    }
}
