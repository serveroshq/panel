<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\IntegrationOperation;
use App\Models\Workspace;
use App\Support\Audit\Audited;
use App\Support\Integrations\Capability;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Servers\CloudServers;
use App\Support\Integrations\Servers\NewServer;
use App\Support\Integrations\Servers\ServerOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CloudServerController extends Controller
{
    public function options(Workspace $workspace, Integration $integration): JsonResponse
    {
        abort_unless($integration->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $manager = CloudServers::manager($integration) ?? abort(404);

        try {
            return response()->json($manager->plans($integration)->toArray())->header('Cache-Control', 'no-store');
        } catch (IntegrationFailed $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }
    }

    #[Audited]
    public function store(Request $request, Workspace $workspace, Integration $integration, ServerOperations $operations): JsonResponse
    {
        abort_unless($integration->workspace_id === $workspace->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $manager = CloudServers::manager($integration);
        abort_unless($manager !== null && $integration->provider()->can(Capability::CreateServers), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:63', 'regex:/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i'],
            'location' => ['required', 'string', 'max:40'],
            'type' => ['required', 'string', 'max:40'],
            'image' => ['required', 'string', 'max:80'],
            'keys' => ['sometimes', 'array', 'max:20'],
            'keys.*' => ['string', 'max:40'],
        ], ['name.regex' => __('Use letters, numbers and dashes, like web-3.')]);

        try {
            $plans = $manager->plans($integration);
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }
        $type = $plans->type($data['type']);
        $image = $plans->image($data['image'], $type?->architecture);
        $location = $plans->location($data['location']);
        $keys = array_column(array_map(fn ($k) => $k->toArray(), $plans->keys), 'id');
        $errors = array_filter([
            'location' => $location === null ? __('Pick a location from the list.') : null,
            'type' => $type === null || ! in_array($data['location'], $type->locations, true) ? __('That server type can’t be made in this location. Pick another.') : null,
            'image' => $image === null || ($type !== null && $image->architecture !== $type->architecture) ? __('That system doesn’t run on this server type. Pick another.') : null,
            'keys' => array_diff($data['keys'] ?? [], $keys) !== [] ? __('One of the SSH keys isn’t in this project any more.') : null,
        ]);
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        try {
            $operation = $operations->create($integration, $request->user(), new NewServer(
                name: strtolower($data['name']),
                location: $data['location'],
                type: $data['type'],
                image: $data['image'],
                keys: array_values($data['keys'] ?? []),
            ));
        } catch (IntegrationFailed $e) {
            throw ValidationException::withMessages(['form' => $e->getMessage()]);
        }

        Activity::record($workspace, 'machine.server_create', strtolower($data['name']), null, $request->user(), [
            'provider' => $integration->provider()->name(),
            'account' => $integration->label,
            'type' => $type->label,
            'location' => $location->city,
        ]);

        return response()->json(['operation' => ServerOperations::props($operation)], 201);
    }

    public function operation(Workspace $workspace, Integration $integration, IntegrationOperation $operation): JsonResponse
    {
        abort_unless($integration->workspace_id === $workspace->id && $operation->integration_id === $integration->id, 404);
        Gate::authorize('manageMachines', $workspace);
        ServerOperations::expireLost();

        return response()->json(['operation' => ServerOperations::props($operation->refresh())])->header('Cache-Control', 'no-store');
    }
}
