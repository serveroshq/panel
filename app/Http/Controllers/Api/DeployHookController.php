<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Deploys\DeployHooks;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * An app's deploy hook: anyone with its secret address can deploy the app,
 * so a CI job or a git host's webhook can.
 */
class DeployHookController extends Controller
{
    public function __invoke(Request $request, string $token, DeployHooks $hooks): JsonResponse
    {
        $hook = DeployHooks::find($token);
        if ($hook === null) {
            return response()->json(['error' => 'Unknown deploy hook.'], 404);
        }

        $key = 'deploy-hook:'.$hook->id;
        if (RateLimiter::tooManyAttempts($key, DeployHooks::PER_MINUTE)) {
            return response()->json(['error' => 'Too many deploys from this hook. Try again in a minute.'], 429, ['Retry-After' => (string) RateLimiter::availableIn($key)]);
        }
        RateLimiter::hit($key, 60);

        $answer = $hooks->handle($hook, $request);

        return response()->json($answer['body'], $answer['status']);
    }
}
