<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\ConsoleLock;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\AppCommits;
use App\Support\Deploys\AppDeploys;
use App\Support\Deploys\AppEdits;
use App\Support\Deploys\AppPage;
use App\Support\Deploys\AppSchedules;
use App\Support\Deploys\BuildSettings;
use App\Support\Deploys\DeployFailure;
use App\Support\Deploys\DeployHooks;
use App\Support\Deploys\EnvFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * The app's page: how it's built and run, its settings, deploying it again
 * (the latest commit, a given one, or from scratch), its deploys, its
 * deploy hook and its scheduled commands. Everything here works once the
 * app has any deploy, a failed one too, so a first deploy that didn't
 * work can be fixed.
 */
class AppDeployController extends Controller
{
    #[Audited]
    public function build(Request $request, Workspace $workspace, Machine $machine, string $service, DaemonJobQueue $queue): JsonResponse
    {
        $spec = $this->spec($workspace, $machine, $service);
        $data = $request->validate(BuildSettings::rules(), BuildSettings::messages());

        $edit = AppEdits::build($machine, $service, $spec, $data);
        if ($edit['changed'] === []) {
            AuditContext::nothingChanged();

            return response()->json(['changed' => [], 'jobId' => null, 'deploy' => AppPage::props($machine, $service)]);
        }

        return $this->deploy($request, $workspace, $machine, $service, $queue, $edit['spec'], ['action' => 'app.build', 'meta' => ['changed' => $edit['changed']]], extra: ['changed' => $edit['changed']]);
    }

    /**
     * Changes the app's settings and deploys it again with them: set and
     * remove by name, a pasted .env file (replace: true also removes what
     * it leaves out), and env_flags for where each one goes.
     */
    #[Audited]
    public function env(Request $request, Workspace $workspace, Machine $machine, string $service, DaemonJobQueue $queue): JsonResponse
    {
        $spec = $this->spec($workspace, $machine, $service);
        $data = $request->validate([
            'set' => ['sometimes', 'array', 'max:'.EnvFile::MAX_VARIABLES],
            'set.*' => ['nullable', 'string', 'max:'.EnvFile::MAX_VALUE_BYTES],
            'remove' => ['sometimes', 'array', 'max:'.EnvFile::MAX_VARIABLES],
            'remove.*' => ['string', 'max:128'],
            'dotenv' => ['sometimes', 'nullable', 'string', 'max:'.EnvFile::MAX_BYTES],
            'replace' => ['sometimes', 'boolean'],
            ...BuildSettings::flagRules(),
        ], ['set.*.max' => 'A setting can be at most 64 KB.', 'dotenv.max' => 'That’s more than 512 KB of settings. Paste fewer at a time.']);

        $set = array_map(fn ($value) => (string) $value, (array) ($data['set'] ?? []));
        $remove = array_values(array_unique(array_map('strval', (array) ($data['remove'] ?? []))));
        $pasted = filled($data['dotenv'] ?? null);
        if ($pasted) {
            $parsed = EnvFile::parse((string) $data['dotenv']);
            if ($parsed['errors'] !== []) {
                throw ValidationException::withMessages(['dotenv' => (string) reset($parsed['errors'])]);
            }
            $set = [...$set, ...$parsed['variables']];
        }

        $edit = AppEdits::env($machine, $service, $spec, $set, $remove, (array) ($data['env_flags'] ?? []), $pasted && (bool) ($data['replace'] ?? false));
        if ($edit['changed'] === []) {
            AuditContext::nothingChanged();

            return response()->json(['changed' => [], 'jobId' => null, 'deploy' => AppPage::props($machine, $service)]);
        }

        return $this->deploy($request, $workspace, $machine, $service, $queue, $edit['spec'], ['action' => 'app.variables', 'meta' => ['names' => $edit['changed']]], extra: ['changed' => $edit['changed']]);
    }

    /**
     * Deploys the app again: by default the latest commit of its branch, or
     * a given branch, tag or commit, or what it runs now (latest: false).
     * no_cache builds it from scratch. An image is pulled again.
     */
    #[Audited]
    public function redeploy(Request $request, Workspace $workspace, Machine $machine, string $service, DaemonJobQueue $queue, AppCommits $commits): JsonResponse
    {
        $spec = $this->spec($workspace, $machine, $service);
        $data = $request->validate([
            'commit' => ['sometimes', 'nullable', 'string', 'max:255'],
            'latest' => ['sometimes', 'boolean'],
            'no_cache' => ['sometimes', 'boolean'],
        ]);
        $settings = BuildSettings::fromSpec($spec);
        $noCache = (bool) ($data['no_cache'] ?? false);
        if ($noCache && (! DaemonJobQueue::canDeployBuild($machine) || ! in_array($settings->method, [BuildSettings::RAILPACK, BuildSettings::DOCKERFILE, BuildSettings::TEMPLATE], true))) {
            throw ValidationException::withMessages(['no_cache' => DaemonJobQueue::canDeployBuild($machine)
                ? 'Only an app ServerOS builds can be built from scratch.'
                : $machine->name.'’s daemon is too old to build from scratch. It updates itself, or run “sudo serverosd update” on the server.']);
        }

        $repo = (string) ($spec['repo'] ?? '');
        $fromGit = $repo !== '' && ! str_contains($repo, '/api/deploy-sources/');
        $asked = is_string($data['commit'] ?? null) && trim($data['commit']) !== '' ? trim($data['commit']) : null;
        if (! $fromGit && $asked !== null) {
            throw ValidationException::withMessages(['commit' => AppDeploys::isImage($spec) ? 'An image has no commits: it’s pulled again as it is.' : 'Uploaded code has no branches or commits. Upload a new version instead.']);
        }

        $ref = null;
        if ($fromGit && ($asked !== null || ($data['latest'] ?? true))) {
            $want = $asked ?? AppDeploys::branch($machine, $service);
            if (($reason = $queue->unavailable($machine, 'deploy')) !== null) {
                return response()->json(['error' => $reason], 409);
            }
            try {
                $resolved = $commits->resolve($machine, $request->user(), $service, $spec, $want);
            } catch (RuntimeException $e) {
                throw ValidationException::withMessages(['commit' => $e->getMessage()]);
            }
            $spec['commit'] = $resolved['commit'];
            $ref = $resolved['ref'];
        } elseif ($fromGit) {
            $spec['commit'] = AppDeploys::liveCommit($machine, $service, $spec);
        }

        return $this->deploy($request, $workspace, $machine, $service, $queue, $spec, noCache: $noCache, extra: ['commit' => $fromGit ? $spec['commit'] : null, 'ref' => $ref]);
    }

    #[NotAudited('Lists an app’s deploys; nothing changes')]
    public function history(Request $request, Workspace $workspace, Machine $machine, string $service): JsonResponse
    {
        $this->spec($workspace, $machine, $service);
        $data = $request->validate(['before' => ['sometimes', 'nullable', 'date'], 'limit' => ['sometimes', 'integer', 'between:1,100']]);
        $limit = (int) ($data['limit'] ?? 30);
        $jobs = AppDeploys::history($machine, $service, $limit + 1, isset($data['before']) ? now()->parse($data['before'])->format('Y-m-d H:i:s.u') : null);

        return response()->json([
            'deploys' => $jobs->take($limit)->map(fn (DaemonJob $job) => AppDeploys::row($job))->values()->all(),
            'more' => $jobs->count() > $limit,
        ]);
    }

    #[NotAudited('Shows one of an app’s deploys or scheduled runs; nothing changes')]
    public function job(Workspace $workspace, Machine $machine, string $service, string $job): JsonResponse
    {
        $this->spec($workspace, $machine, $service);
        $record = DaemonJob::query()->where('machine_id', $machine->id)->whereKey($job)->first();
        $schedules = array_map(fn (array $s) => AppSchedules::TRIGGER.$s['id'], AppSchedules::of($machine, $service));
        $ours = $record !== null && (
            (in_array($record->kind, DaemonJob::LONG_KINDS, true) && $record->service === $service)
            || ($record->kind === 'service_exec' && in_array($record->trigger, $schedules, true))
        );
        abort_unless($ours, 404);

        return response()->json([
            ...AppDeploys::row($record),
            'log' => array_values($record->log ?? []),
            'error' => $record->error,
            'result' => $record->kind === 'service_exec' ? $record->result : null,
            'explanation' => DeployFailure::explain($record),
        ]);
    }

    #[Audited]
    public function storeHook(Request $request, Workspace $workspace, Machine $machine, string $service): JsonResponse
    {
        $spec = $this->spec($workspace, $machine, $service);
        if (str_contains((string) ($spec['repo'] ?? ''), '/api/deploy-sources/')) {
            throw ValidationException::withMessages(['hook' => 'An app uploaded as a .zip has nothing for a hook to fetch. Upload new versions on its page.']);
        }
        $existed = DeployHooks::of($machine, $service) !== null;
        $issued = DeployHooks::issue($machine, $service, $request->user());
        Activity::record($workspace, 'app.hook', $service, $machine, meta: ['made' => ! $existed, 'regenerated' => $existed]);

        return response()->json(['url' => $issued['url'], 'hook' => DeployHooks::props($machine, $service)], 201);
    }

    #[Audited]
    public function destroyHook(Workspace $workspace, Machine $machine, string $service): JsonResponse
    {
        $this->spec($workspace, $machine, $service);
        $hook = DeployHooks::of($machine, $service);
        if ($hook === null) {
            AuditContext::nothingChanged();
        } else {
            $hook->delete();
            Activity::record($workspace, 'app.hook', $service, $machine, meta: ['revoked' => true]);
        }

        return response()->json(['hook' => DeployHooks::props($machine, $service)]);
    }

    /**
     * Saves the app's scheduled commands. They run commands in its
     * container as the person who saves them, so this needs what the
     * console needs: two-factor authentication on, and the console
     * unlocked.
     */
    #[Audited]
    public function schedules(Request $request, Workspace $workspace, Machine $machine, string $service, ConsoleLock $console): JsonResponse
    {
        $spec = $this->spec($workspace, $machine, $service);
        $data = $request->validate(AppSchedules::rules());
        if (($reason = AppSchedules::unavailable($spec)) !== null) {
            throw ValidationException::withMessages(['schedules' => $reason]);
        }
        $user = $request->user();
        if ($data['schedules'] !== [] && $console->requiresSetup($user)) {
            return response()->json(['error' => 'Turn on two-factor authentication to schedule commands.', 'code' => 'two_factor_required'], 403);
        }
        if ($data['schedules'] !== [] && ! $console->isUnlocked($user)) {
            return response()->json(['error' => 'Unlock the console with a two-factor code first.', 'code' => 'console_locked'], 423);
        }

        $counts = AppSchedules::save($machine, $service, array_values($data['schedules']), $user);
        if (array_sum($counts) === 0) {
            AuditContext::nothingChanged();
        } else {
            Activity::record($workspace, 'app.schedules', $service, $machine, meta: array_filter($counts));
        }

        return response()->json(['schedules' => AppSchedules::props($machine, $service)]);
    }

    /**
     * The app's spec, after the usual checks. 404 unless it was deployed as
     * an app (from code or an image) on this machine.
     *
     * @return array<string, mixed>
     */
    private function spec(Workspace $workspace, Machine $machine, string $service): array
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        return AppDeploys::spec($machine, $service) ?? abort(404, 'This app wasn’t deployed by ServerOS.');
    }

    /**
     * Sends the edited spec, unless the app is deploying now.
     *
     * @param  array<string, mixed>  $spec
     * @param  array{action: string, meta: array<string, mixed>}|null  $activity
     * @param  array<string, mixed>  $extra
     */
    private function deploy(Request $request, Workspace $workspace, Machine $machine, string $service, DaemonJobQueue $queue, array $spec, ?array $activity = null, bool $noCache = false, array $extra = []): JsonResponse
    {
        if (($reason = $queue->unavailable($machine, 'deploy')) !== null) {
            return response()->json(['error' => $reason], 409);
        }
        if (($busy = AppDeploys::busy($machine, $service)) !== null) {
            return response()->json(['error' => "{$service} is deploying now. Wait for it to finish, or stop waiting on it first.", 'jobId' => $busy->id], 409);
        }
        $repo = (string) ($spec['repo'] ?? '');
        if (str_contains($repo, '/api/deploy-sources/')) {
            $source = DeploySource::fromRepoUrl($repo);
            if ($source === null || $source->workspace_id !== $workspace->id || ! $source->usable()) {
                throw ValidationException::withMessages(['source' => "The code uploaded for {$service} has been cleared. Upload it again first."]);
            }
        }

        /** @var User $user */
        $user = $request->user();
        try {
            $record = $queue->deploy($machine, $user, $spec, noCache: $noCache);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        if ($activity !== null) {
            Activity::record($workspace, $activity['action'], $service, $machine, meta: $activity['meta']);
        }

        return response()->json([...$extra, 'jobId' => $record->id, 'state' => 'queued', 'deploy' => AppPage::props($machine, $service)], 202);
    }
}
