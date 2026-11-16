<?php

namespace App\Http\Middleware;

use App\Api\ApiRunner;
use App\Mcp\McpContext;
use App\Models\ApiIdempotencyKey;
use Closure;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Makes a write under /api/v1 safe to send again: the same Idempotency-Key
 * from the same API key within 24 hours answers what the first request
 * answered instead of doing the work twice. The same key with a different
 * request is a 409. Runs after AuthenticateApiKey.
 */
class IdempotentApiRequests
{
    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $context = $request->attributes->get('api.context');
        if ($key === null || $request->isMethodSafe() || ! $context instanceof McpContext || $context->token === null) {
            return $next($request);
        }
        if ($key === '' || strlen($key) > 255 || preg_match('/^[\x21-\x7e]+$/', $key) !== 1) {
            return self::fail(422, 'invalid_arguments', 'An Idempotency-Key is 1 to 255 visible ASCII characters, like a UUID.');
        }

        $keyHash = hash('sha256', $key);
        $requestHash = hash('sha256', $request->method()."\n".$request->path()."\n".$request->getContent());
        $tokenId = $context->token->id;

        // An expired key is free again, and so is one whose request never answered
        // (its process died): no request runs for 5 minutes.
        ApiIdempotencyKey::query()->where('mcp_token_id', $tokenId)->where('key_hash', $keyHash)
            ->where(fn ($q) => $q->where('created_at', '<', now()->subHours(ApiIdempotencyKey::HOURS))
                ->orWhere(fn ($q) => $q->whereNull('status')->where('created_at', '<', now()->subMinutes(5))))
            ->delete();

        try {
            $record = ApiIdempotencyKey::query()->create([
                'mcp_token_id' => $tokenId,
                'key_hash' => $keyHash,
                'request_hash' => $requestHash,
                'created_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $first = ApiIdempotencyKey::query()->where('mcp_token_id', $tokenId)->where('key_hash', $keyHash)->first();

            return self::honoured($key, match (true) {
                $first === null => self::fail(409, 'conflict', 'A request with this Idempotency-Key is still running. Try again in a moment.'),
                $first->request_hash !== $requestHash => self::fail(409, 'conflict', 'This Idempotency-Key was already used for a different request in the last 24 hours. Use a new key for a new request.'),
                $first->status === null => self::fail(409, 'conflict', 'A request with this Idempotency-Key is still running. Try again in a moment.'),
                default => response((string) $first->body, $first->status, ['Content-Type' => 'application/json', 'Idempotent-Replayed' => 'true']),
            });
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            $record->delete();
            throw $e;
        }

        // A server error may not have done the work, so let the same key try again.
        if ($response->getStatusCode() >= 500) {
            $record->delete();
        } else {
            $record->forceFill(['status' => $response->getStatusCode(), 'body' => (string) $response->getContent()])->save();
        }

        return self::honoured($key, $response);
    }

    /** Sends the key back, so a client knows this panel honours it and a retried write is done once. */
    private static function honoured(string $key, Response $response): Response
    {
        $response->headers->set('Idempotency-Key', $key);

        return $response;
    }

    private static function fail(int $status, string $type, string $message): JsonResponse
    {
        return response()->json(ApiRunner::error($status, $type, $message)[1], $status);
    }
}
