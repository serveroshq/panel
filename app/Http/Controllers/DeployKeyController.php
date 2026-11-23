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

    #[NotAudited('Reads how a key or ref job went; nothing changes')]
    public function show(Workspace $workspace, Machine $machine, DaemonJob $job): JsonResponse
    {
        $this->authorizeMachine($workspace, $machine);
        abort_unless($job->machine_id === $machine->id && in_array($job->kind, ['deploy_key', 'resolve_ref'], true), 404);

        return response()->json(self::answer($job));
    }

    /** @param  array<string, mixed>  $job */
    private function run(Request $request, Machine $machine, DaemonJobQueue $queue, string $kind, array $job, string $service): JsonResponse
    {
        if (($reason = $queue->unavailable($machine, 'deploy')) !== null) {
            return response()->json(['error' => $reason], 409);
        }

        try {
            $record = $queue->queue($machine, $request->user(), $kind, $job, $service, 60);
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

    /** @return array{jobId: string, state: string, publicKey?: string, commit?: string, ref?: string, error?: string, nextStep?: string} */
    private static function answer(DaemonJob $job): array
    {
        $answer = ['jobId' => $job->id, 'state' => $job->state];
        $result = is_array($job->result) ? $job->result : [];
        if ($job->state === 'succeeded') {
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

    private function authorizeMachine(Workspace $workspace, Machine $machine): void
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
    }
}
