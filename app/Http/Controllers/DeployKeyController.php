<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\DaemonJobQueue;
use App\Support\Deploys\DeployRequest;
use App\Support\Deploys\GitRemote;
use App\Support\ServiceTemplates\RepositoryDetector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * A private repository on any git host: the machine makes a deploy key for
 * the app, the person adds it to the repository, and the machine asks the
 * repository which commit a branch is at, so the deploy is pinned to it.
 */
class DeployKeyController extends Controller
{
    #[Audited]
    public function key(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        $this->authorizeMachine($workspace, $machine);
        $data = $request->validate([
            'service' => ['required', 'string', 'max:63', 'regex:'.DeployRequest::SERVICE_PATTERN],
        ]);

        $response = $this->run($request, $machine, $queue, 'deploy_key', ['type' => 'deploy_key', 'service' => $data['service']], $data['service']);
        if ($response->getStatusCode() === 200) {
            Activity::record($workspace, 'app.deploy_key', $data['service'], $machine);
        } else {
            AuditContext::nothingChanged();
        }

        return $response;
    }

    #[Audited]
    public function resolve(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        $this->authorizeMachine($workspace, $machine);
        $data = $request->validate([
            'service' => ['required', 'string', 'max:63', 'regex:'.DeployRequest::SERVICE_PATTERN],
            'repo' => ['required', 'string', 'max:500', 'regex:'.GitRemote::SSH_PATTERN, 'not_regex:/\.\./'],
            'ref' => ['nullable', 'string', 'max:200', 'regex:'.GitRemote::REF_PATTERN],
        ], ['repo.regex' => 'Use the repository’s SSH address, like git@gitlab.com:you/app.git.', 'ref.regex' => 'That isn’t a branch or tag name.']);

        if (! DaemonJobQueue::canResolveRef($machine)) {
            return response()->json(['errors' => ['repo' => [$machine->name.'’s daemon is too old to deploy from other git hosts. It updates itself, or run “sudo serverosd update” on the server.']]], 422);
        }

        // Only asks the repository which commit a branch is at.
        AuditContext::nothingChanged();
        $job = array_filter(['type' => 'resolve_ref', 'service' => $data['service'], 'repo' => $data['repo'], 'ref' => $data['ref'] ?? null], fn ($v) => $v !== null && $v !== '');

        return $this->run($request, $machine, $queue, 'resolve_ref', $job, $data['service']);
    }

    /**
     * What a repository on any git host is: the machine fetches it (an SSH
     * address with the app's deploy key) and lists its files, and the panel
     * reads them as it reads a GitHub repository. Needs a newer daemon.
     */
    #[Audited]
    public function inspect(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        $this->authorizeMachine($workspace, $machine);
        $data = $request->validate([
            'repository' => ['required', 'string', 'max:500'],
            'ref' => ['nullable', 'string', 'max:200', 'regex:'.GitRemote::REF_PATTERN],
            'base_dir' => ['nullable', 'string', 'max:200', 'regex:'.RepositoryDetector::BASE_DIR_PATTERN],
            'service' => ['nullable', 'string', 'max:63', 'regex:'.DeployRequest::SERVICE_PATTERN],
        ], ['ref.regex' => 'That isn’t a branch or tag name.', 'base_dir.regex' => 'Give a folder in the repository, like apps/web.']);

        if (! DaemonJobQueue::canDeployBuild($machine)) {
            return response()->json(['message' => 'Update the daemon.', 'errors' => ['repository' => [$machine->name.'’s daemon is too old to read repositories on other hosts. It updates itself, or run “sudo serverosd update” on the server.']]], 422);
        }
        [$repo, $ref, $baseDir] = GitRemote::link(trim($data['repository']));
        $ref = $data['ref'] ?? $ref;
        $baseDir = isset($data['base_dir']) ? trim((string) $data['base_dir'], '/') : $baseDir;
        if (GitRemote::isSsh($repo)) {
            if (blank($data['service'] ?? null)) {
                return response()->json(['message' => 'Name the app first.', 'errors' => ['service' => ['Name the app first: an SSH repository is read with its deploy key.']]], 422);
            }
        } else {
            DeployRequest::assertPublicRepo($repo);
        }

        // Only reads the repository.
        AuditContext::nothingChanged();
        $job = array_filter([
            'type' => 'repo_inspect',
            'service' => $data['service'] ?? null,
            'repo' => $repo,
            'ref' => $ref,
            'base_dir' => $baseDir,
        ], fn ($v) => $v !== null && $v !== '');

        return $this->run($request, $machine, $queue, 'repo_inspect', $job, $data['service'] ?? null, 180);
    }

    #[NotAudited('Reads how a key, ref or inspect job went; nothing changes')]
    public function show(Workspace $workspace, Machine $machine, DaemonJob $job): JsonResponse
    {
        $this->authorizeMachine($workspace, $machine);
        abort_unless($job->machine_id === $machine->id && in_array($job->kind, ['deploy_key', 'resolve_ref', 'repo_inspect'], true), 404);

        return response()->json(self::answer($job));
    }

    /** @param  array<string, mixed>  $job */
    private function run(Request $request, Machine $machine, DaemonJobQueue $queue, string $kind, array $job, ?string $service, int $timeout = 60): JsonResponse
    {
        if (($reason = $queue->unavailable($machine, 'deploy')) !== null) {
            return response()->json(['error' => $reason], 409);
        }

        try {
            $record = $queue->queue($machine, $request->user(), $kind, $job, $service, $timeout);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        // Long enough for ssh-keygen or one ls-remote; the dialog asks again after.
        $deadline = microtime(true) + (int) config('daemon.short_job_wait_secs', 20);
        while (in_array($record->state, ['queued', 'running'], true) && microtime(true) < $deadline) {
            usleep(500_000);
            $record->refresh();
        }

        return response()->json(self::answer($record));
    }

    /** @return array{jobId: string, state: string, publicKey?: string, commit?: string, ref?: string, detection?: array<string, mixed>, error?: string, nextStep?: string} */
    private static function answer(DaemonJob $job): array
    {
        $answer = ['jobId' => $job->id, 'state' => $job->isStale() ? 'stale' : $job->state];
        $result = is_array($job->result) ? $job->result : [];
        if ($job->state === 'succeeded' && $job->kind === 'repo_inspect') {
            $answer['detection'] = self::detection($job, $result);
        } elseif ($job->state === 'succeeded') {
            if (is_string($result['public_key'] ?? null)) {
                $answer['publicKey'] = $result['public_key'];
            }
            if (is_string($result['commit'] ?? null) && preg_match(DeployRequest::COMMIT_PATTERN, $result['commit']) === 1) {
                $answer['commit'] = strtolower($result['commit']);
                $answer['ref'] = is_string($result['ref'] ?? null) ? $result['ref'] : null;
            }
        } elseif (! in_array($job->state, ['queued', 'running'], true)) {
            $error = is_array($job->error) ? $job->error : [];
            $answer['error'] = is_string($error['message'] ?? null) ? $error['message'] : 'The machine couldn’t do it.';
            if (is_string($error['next_step'] ?? null)) {
                $answer['nextStep'] = $error['next_step'];
            }
        }

        return $answer;
    }

    /**
     * The detection, worked out once from the files the machine sent and kept
     * instead of them, so the job doesn't hold the repository's contents.
     *
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private static function detection(DaemonJob $job, array $result): array
    {
        if (is_array($result['detection'] ?? null)) {
            return $result['detection'];
        }
        $spec = (array) ($job->command['job'] ?? []);
        $detection = app(RepositoryDetector::class)->fromInspect($result, (string) ($spec['repo'] ?? ''), is_string($spec['base_dir'] ?? null) ? $spec['base_dir'] : null);
        $job->forceFill(['result' => ['commit' => $detection['commit'], 'detection' => $detection]])->saveQuietly();

        return $detection;
    }

    private function authorizeMachine(Workspace $workspace, Machine $machine): void
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
    }
}
