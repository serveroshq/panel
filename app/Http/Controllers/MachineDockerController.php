<?php

namespace App\Http\Controllers;

use App\Mcp\McpContext;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\DaemonJobQueue;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Docker's images, volumes and build cache on a machine: what each takes
 * and what uses it, and removing what nothing uses.
 */
class MachineDockerController extends Controller
{
    private const array KINDS = ['docker_resources', 'docker_prune'];

    #[NotAudited('A read-only listing of images and volumes')]
    public function store(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);

        if (($reason = self::unavailable($machine, $queue)) !== null) {
            return response()->json(['error' => $reason], 409);
        }

        try {
            $job = $queue->queue($machine, $request->user(), 'docker_resources', ['type' => 'docker_resources'], null, 180);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }

        return response()->json(self::present($job), 202);
    }

    #[Audited]
    public function prune(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'images' => ['array', 'max:500'],
            'images.*' => ['string', 'regex:/^sha256:[a-f0-9]{64}$/'],
            'volumes' => ['array', 'max:500'],
            'volumes.*' => ['string', 'max:255', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'build_cache' => ['boolean'],
        ]);
        $images = array_values(array_unique($data['images'] ?? []));
        $volumes = array_values(array_unique($data['volumes'] ?? []));
        $cache = (bool) ($data['build_cache'] ?? false);
        if ($images === [] && $volumes === [] && ! $cache) {
            return response()->json(['error' => __('Pick something to remove first.')], 422);
        }
        if (($reason = self::unavailable($machine, $queue)) !== null) {
            return response()->json(['error' => $reason], 409);
        }

        try {
            $job = $queue->queue($machine, $request->user(), 'docker_prune', ['type' => 'docker_prune', 'images' => $images, 'volumes' => $volumes, 'build_cache' => $cache], null, 600);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 503);
        }
        Activity::record($workspace, 'machine.docker_prune', $machine->name, $machine, $request->user(), [
            'images' => count($images), 'volumes' => count($volumes), 'build_cache' => $cache,
        ]);

        return response()->json(self::present($job), 202);
    }

    public function show(Workspace $workspace, Machine $machine, DaemonJob $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id && in_array($job->kind, self::KINDS, true), 404);
        Gate::authorize('manageMachines', $workspace);

        return response()->json(self::present($job));
    }

    private static function unavailable(Machine $machine, DaemonJobQueue $queue): ?string
    {
        if (! DaemonJobQueue::canManageImages($machine)) {
            return __('Its ServerOS daemon is too old to list images and volumes. Update it from the machine’s page first.');
        }

        return $queue->unavailable($machine);
    }

    /** @return array<string, mixed> */
    public static function present(DaemonJob $job): array
    {
        $result = is_array($job->result) ? $job->result : [];
        $list = fn (string $key) => array_values(array_filter(is_array($result[$key] ?? null) ? $result[$key] : [], 'is_array'));

        $out = [
            'jobId' => $job->id,
            'kind' => $job->kind,
            'state' => $job->isStale() ? 'stale' : $job->state,
            'finished' => in_array($job->state, McpContext::FINISHED, true),
            'error' => $job->error['message'] ?? null,
            'at' => $job->finished_at?->toIso8601String(),
        ];
        if ($job->kind === 'docker_prune') {
            return $out + [
                'removedImages' => count($list('images')),
                'removedVolumes' => count(array_filter(is_array($result['volumes'] ?? null) ? $result['volumes'] : [], 'is_string')),
                'reclaimed' => array_sum(array_map(fn (array $i) => (int) ($i['size'] ?? 0), $list('images'))) + (int) ($result['build_cache'] ?? 0),
                'skipped' => array_map(fn (array $s) => ['name' => (string) ($s['image'] ?? $s['volume'] ?? ''), 'reason' => (string) ($s['reason'] ?? '')], $list('skipped')),
            ];
        }

        $strings = fn (mixed $value) => array_values(array_filter(is_array($value) ? $value : [], 'is_string'));

        return $out + [
            'images' => array_map(fn (array $image) => [
                'id' => (string) ($image['id'] ?? ''),
                'tags' => $strings($image['tags'] ?? null),
                'size' => (int) ($image['size'] ?? 0),
                'created' => is_int($image['created'] ?? null) && $image['created'] > 0 ? date(DATE_ATOM, $image['created']) : null,
                'containers' => $strings($image['containers'] ?? null),
            ], $list('images')),
            'volumes' => array_map(fn (array $volume) => [
                'name' => (string) ($volume['name'] ?? ''),
                'size' => is_int($volume['size'] ?? null) ? $volume['size'] : null,
                'created' => is_string($volume['created'] ?? null) ? $volume['created'] : null,
                'project' => is_string($volume['project'] ?? null) ? $volume['project'] : null,
                'anonymous' => (bool) ($volume['anonymous'] ?? false),
                'containers' => $strings($volume['containers'] ?? null),
            ], $list('volumes')),
            'buildCache' => [
                'size' => (int) ($result['build_cache']['size'] ?? 0),
                'reclaimable' => (int) ($result['build_cache']['reclaimable'] ?? 0),
            ],
        ];
    }
}
