<?php

namespace App\Http\Controllers;

use App\Models\ContainerImage;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Audit\NotAudited;
use App\Support\Images\ImageScanner;
use App\Support\Images\ImageUpdates;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;
use Throwable;

/** Newer images for containers, and the vulnerabilities in the images they run. */
class ContainerImageController extends Controller
{
    /** Updates one container, or every container named, onto its newer image. */
    #[Audited]
    public function update(Request $request, Workspace $workspace, ImageUpdates $updates): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer'],
        ]);
        $images = ContainerImage::query()->whereKey($data['ids'])
            ->whereIn('machine_id', $workspace->machines()->select('id'))
            ->with('machine.workspace')
            ->get();
        abort_if($images->isEmpty(), 404);

        $queued = [];
        $failed = [];
        foreach ($images as $image) {
            if (ImageUpdates::updating($image) !== null) {
                continue;
            }
            try {
                $queued[$image->id] = $updates->update($image, $request->user())->id;
            } catch (RuntimeException $e) {
                $failed[] = ['id' => $image->id, 'container' => $image->container, 'error' => $e->getMessage()];
            }
        }
        if ($queued === []) {
            AuditContext::nothingChanged();
        }
        if ($queued === [] && $failed !== []) {
            return response()->json(['error' => $failed[0]['error'], 'failed' => $failed], 409);
        }

        return response()->json(['queued' => $queued, 'failed' => $failed], 202);
    }

    /** Checks the workspace's containers against their registries now. */
    #[NotAudited('A read-only check against registries')]
    public function check(Workspace $workspace, ImageUpdates $updates): JsonResponse
    {
        Gate::authorize('manageMachines', $workspace);
        $machines = ImageUpdates::due()->filter(fn (Machine $m) => $m->workspace_id === $workspace->id)->take(50);
        foreach ($machines as $machine) {
            try {
                $updates->check($machine);
            } catch (Throwable $e) {
                report($e);
            }
        }

        return response()->json(['checked' => $machines->count()]);
    }

    public function vulnerabilities(Workspace $workspace, ContainerImage $image): JsonResponse
    {
        abort_unless($image->machine->workspace_id === $workspace->id, 404);

        return response()->json([
            'container' => $image->container,
            'image' => $image->image,
            'scannedAt' => $image->scanned_at?->toIso8601String(),
            'findings' => $image->image_id === null ? [] : ImageScanner::findings($image->machine, $image->image_id),
        ]);
    }
}
