<?php

namespace App\Support\Deploys;

use App\Mcp\ToolError;
use App\Mcp\Tools\Deploy\RefResolver;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use RuntimeException;

/**
 * The exact commit a branch, tag or commit of an app's repository stands
 * for now. GitHub and other HTTPS hosts are asked by the panel; an SSH
 * repository is asked by the machine, with the app's deploy key.
 */
final class AppCommits
{
    public function __construct(private readonly RefResolver $refs, private readonly DaemonJobQueue $queue) {}

    /**
     * @param  array<string, mixed>  $spec  the app's spec
     * @return array{commit: string, ref: string|null}
     *
     * @throws RuntimeException with a message for the person
     */
    public function resolve(Machine $machine, User $user, string $app, array $spec, ?string $ref): array
    {
        $repo = (string) ($spec['repo'] ?? '');
        $ref = $ref === null || trim($ref) === '' ? null : trim($ref);
        if ($ref !== null && preg_match(DeployRequest::COMMIT_PATTERN, $ref) === 1) {
            return ['commit' => strtolower($ref), 'ref' => null];
        }
        if ($repo === '' || str_contains($repo, '/api/deploy-sources/')) {
            throw new RuntimeException('This app has no repository to fetch a commit from.');
        }

        if (! GitRemote::isSsh($repo)) {
            return $this->refs->resolve($repo, $ref, $machine->workspace);
        }

        if (! DaemonJobQueue::canResolveRef($machine)) {
            throw new RuntimeException($machine->name.'’s daemon is too old to look up commits of this repository. It updates itself, or run “sudo serverosd update” on the server.');
        }
        if ($ref !== null && preg_match(GitRemote::REF_PATTERN, $ref) !== 1) {
            throw new ToolError('Give a branch, a tag or a full 40-character commit.');
        }
        if (($reason = $this->queue->unavailable($machine, 'deploy')) !== null) {
            throw new RuntimeException($reason);
        }
        $job = $this->queue->queue($machine, $user, 'resolve_ref', array_filter(['type' => 'resolve_ref', 'service' => $app, 'repo' => $repo, 'ref' => $ref], fn ($v) => $v !== null), $app, 60);

        $deadline = microtime(true) + (int) config('daemon.short_job_wait_secs', 20);
        while (in_array($job->state, ['queued', 'running'], true) && microtime(true) < $deadline) {
            usleep(500_000);
            $job->refresh();
        }

        return self::answer($job);
    }

    /**
     * @return array{commit: string, ref: string|null}
     *
     * @throws RuntimeException
     */
    private static function answer(DaemonJob $job): array
    {
        $commit = is_array($job->result) ? ($job->result['commit'] ?? null) : null;
        if ($job->state === 'succeeded' && is_string($commit) && preg_match(DeployRequest::COMMIT_PATTERN, $commit) === 1) {
            return ['commit' => strtolower($commit), 'ref' => is_string($job->result['ref'] ?? null) ? $job->result['ref'] : null];
        }
        if (in_array($job->state, ['queued', 'running'], true)) {
            throw new RuntimeException('The machine didn’t answer in time. Try again in a moment.');
        }
        $message = is_array($job->error) && is_string($job->error['message'] ?? null) ? $job->error['message'] : 'The machine couldn’t read the repository.';

        throw new RuntimeException($message);
    }
}
