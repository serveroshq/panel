<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\DaemonJobQueue;
use App\Support\Traffic\ExistingSites;
use App\Support\Traffic\SiteTraffic;
use App\Support\Traffic\TrafficSwitch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Traffic counted from the access logs: the machine's switch, its list of
 * sites, each app's numbers for its Traffic tab, and the sites of the
 * machine's own web servers, with response times for one, a log of
 * ServerOS's own for one that writes none (and taking it out again), and
 * log files set by hand, on request.
 */
class MachineTrafficController extends Controller
{
    #[Audited]
    public function update(Request $request, Workspace $workspace, Machine $machine, TrafficSwitch $switch): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $enabled = (bool) $request->validate(['enabled' => ['required', 'boolean']])['enabled'];

        try {
            $record = $switch->set($machine, $request->user(), $enabled);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }

        return response()->json(['jobId' => $record->id, 'traffic' => TrafficSwitch::props($machine->fresh() ?? $machine)], 202);
    }

    public function show(Workspace $workspace, Machine $machine, DaemonJob $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id && $job->kind === TrafficSwitch::KIND, 404);

        return response()->json([
            'state' => $job->isStale() ? 'stale' : $job->state,
            'error' => $job->error,
            'traffic' => TrafficSwitch::props($machine->fresh() ?? $machine),
        ]);
    }

    public function report(Request $request, Workspace $workspace, Machine $machine, string $service): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        abort_if(AppVersionController::lastDeployOf($machine, $service) === null, 404);

        return response()->json([
            ...SiteTraffic::state($machine, $service, SiteTraffic::domains($machine, $service)),
            ...SiteTraffic::report($machine, $service, (string) $request->query('range', '24h')),
        ]);
    }

    /** Every site the machine counts, with its last 24 hours. */
    public function sites(Workspace $workspace, Machine $machine): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);

        return response()->json([
            'collecting' => TrafficSwitch::on($machine),
            'blocked' => TrafficSwitch::blocked($machine),
            'existing' => ExistingSites::available($machine),
            'sites' => SiteTraffic::sites($machine),
        ]);
    }

    /** One site of the machine's own web server: the same report as an app's. */
    public function site(Request $request, Workspace $workspace, Machine $machine, string $site): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        $found = ExistingSites::available($machine) ? ExistingSites::find($machine, $site) : null;
        abort_if($found === null, 404);

        return response()->json([
            ...ExistingSites::state($machine, $found),
            ...SiteTraffic::reportSite($machine, $found['site'], (string) $request->query('range', '24h')),
        ]);
    }

    /**
     * Response times for one site of the machine's own web server. Without
     * `apply` the machine only says which lines it would add, and where;
     * with it, it adds them.
     */
    #[Audited]
    public function timing(Request $request, Workspace $workspace, Machine $machine, string $site, DaemonJobQueue $queue): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $apply = (bool) $request->validate(['apply' => ['required', 'boolean']])['apply'];
        $found = ExistingSites::available($machine) ? ExistingSites::find($machine, $site) : null;
        abort_if($found === null, 404);
        if ($found['copy'] || $found['timed']) {
            return response()->json(['error' => __('This site’s log already has response times.')], 409);
        }

        try {
            $record = $queue->queue($machine, $request->user(), 'traffic_timing', ['type' => 'traffic_timing', 'site' => $found['site'], 'apply' => $apply], null, 180);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
        if ($apply) {
            Activity::record($machine->workspace_id, 'machine.traffic_timing', $machine->name, $machine, $request->user(), ['site' => ExistingSites::label($found)]);
        } else {
            // Only asks the machine what it would add.
            AuditContext::nothingChanged();
        }

        return response()->json(['jobId' => $record->id], 202);
    }

    /**
     * Starts (`add`) or stops (`remove`) ServerOS's own log for one site of
     * the machine's own web servers. Without `apply` the machine only says
     * which lines it would add or take out, and where; with it, it does.
     */
    #[Audited]
    public function logging(Request $request, Workspace $workspace, Machine $machine, string $site, DaemonJobQueue $queue): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'action' => ['required', 'in:add,remove'],
            'apply' => ['required', 'boolean'],
        ]);
        $found = ExistingSites::available($machine) ? ExistingSites::find($machine, $site) : null;
        abort_if($found === null, 404);
        if (! DaemonJobQueue::canCountAnySite($machine)) {
            return response()->json(['error' => ExistingSites::updateNote($machine)], 409);
        }
        if (! $found['editable']) {
            return response()->json(['error' => $found['container'] !== null
                ? __('This site’s server runs in the container :name, so ServerOS won’t change its config. Turn on its access log in the container’s config instead.', ['name' => $found['container']])
                : __('This site’s config isn’t a file ServerOS can change.')], 409);
        }
        $add = $data['action'] === 'add';
        if ($add && $found['copy']) {
            return response()->json(['error' => __('ServerOS already logs this site.')], 409);
        }
        if (! $add && ! $found['copy']) {
            return response()->json(['error' => __('ServerOS hasn’t added anything to this site’s config.')], 409);
        }
        $apply = (bool) $data['apply'];

        try {
            $record = $queue->queue($machine, $request->user(), 'traffic_logging', ['type' => 'traffic_logging', 'site' => $found['site'], 'action' => $data['action'], 'apply' => $apply], null, 180);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
        if ($apply) {
            Activity::record($machine->workspace_id, 'machine.traffic_logging', $machine->name, $machine, $request->user(), ['site' => ExistingSites::label($found), 'action' => $data['action']]);
        } else {
            AuditContext::nothingChanged();
        }

        return response()->json(['jobId' => $record->id], 202);
    }

    public function loggingJob(Workspace $workspace, Machine $machine, string $site, DaemonJob $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id && $job->kind === 'traffic_logging' && ($job->command['job']['site'] ?? null) === $site, 404);

        return response()->json([
            'state' => $job->isStale() ? 'stale' : $job->state,
            'result' => $job->result,
            'error' => $job->error,
        ]);
    }

    /**
     * Counts a log file an app writes itself, for an app with no web
     * server in front. Read-only, like the web servers' own logs.
     */
    #[Audited]
    public function addFile(Request $request, Workspace $workspace, Machine $machine, DaemonJobQueue $queue): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9][A-Za-z0-9 ._-]*$/'],
            'path' => ['required', 'string', 'max:500', 'regex:/^\/[^\0]*$/', 'not_regex:/^\/(proc|sys|dev)(\/|$)/'],
        ]);
        if (! DaemonJobQueue::canCountAnySite($machine)) {
            return response()->json(['error' => ExistingSites::updateNote($machine)], 409);
        }

        try {
            $record = $queue->queue($machine, $request->user(), 'traffic_source', ['type' => 'traffic_source', 'action' => 'add', 'name' => $data['name'], 'path' => $data['path']], null, 60);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
        Activity::record($machine->workspace_id, 'machine.traffic_file', $machine->name, $machine, $request->user(), ['name' => $data['name'], 'action' => 'add']);

        return response()->json(['jobId' => $record->id], 202);
    }

    #[Audited]
    public function removeFile(Request $request, Workspace $workspace, Machine $machine, string $site, DaemonJobQueue $queue): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $found = ExistingSites::find($machine, $site);
        abort_if($found === null || ! $found['manual'], 404);

        try {
            $record = $queue->queue($machine, $request->user(), 'traffic_source', ['type' => 'traffic_source', 'action' => 'remove', 'name' => $found['site']], null, 60);
        } catch (RuntimeException $e) {
            return response()->json(['error' => $e->getMessage()], 409);
        }
        Activity::record($machine->workspace_id, 'machine.traffic_file', $machine->name, $machine, $request->user(), ['name' => ExistingSites::label($found), 'action' => 'remove']);

        return response()->json(['jobId' => $record->id], 202);
    }

    public function fileJob(Workspace $workspace, Machine $machine, DaemonJob $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id && $job->kind === 'traffic_source', 404);

        return response()->json([
            'state' => $job->isStale() ? 'stale' : $job->state,
            'result' => $job->result,
            'error' => $job->error,
        ]);
    }

    public function timingJob(Workspace $workspace, Machine $machine, string $site, DaemonJob $job): JsonResponse
    {
        abort_unless($machine->workspace_id === $workspace->id && $job->machine_id === $machine->id && $job->kind === 'traffic_timing' && ($job->command['job']['site'] ?? null) === $site, 404);

        return response()->json([
            'state' => $job->isStale() ? 'stale' : $job->state,
            'result' => $job->result,
            'error' => $job->error,
        ]);
    }
}
