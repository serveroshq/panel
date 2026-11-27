<?php

namespace App\Support\Integrations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * The HTTP side of a provider: short timeouts, a few tries when the provider
 * is busy or down, and every failure turned into an IntegrationFailed whose
 * message can be shown as it is.
 */
abstract class ProviderClient
{
    protected const int TIMEOUT = 15;

    protected const int CONNECT_TIMEOUT = 5;

    protected const int TRIES = 3;

    protected const int LONGEST_WAIT = 8;

    abstract protected function baseUrl(): string;

    abstract protected function providerName(): string;

    /** @return array<string, string> */
    abstract protected function headers(): array;

    /** The provider's own words for a failed response, when its body has them. */
    protected function providerMessage(Response $response): ?string
    {
        return null;
    }

    /** A provider-specific failure, before the generic ones by status. */
    protected function failure(Response $response): ?IntegrationFailed
    {
        return null;
    }

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>
     */
    public function get(string $path, array $query = []): array
    {
        return $this->send('GET', $path, $query === [] ? [] : ['query' => $query]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function post(string $path, array $body = []): array
    {
        return $this->send('POST', $path, $body === [] ? [] : ['json' => $body]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function put(string $path, array $body = []): array
    {
        return $this->send('PUT', $path, ['json' => $body]);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    public function patch(string $path, array $body = []): array
    {
        return $this->send('PATCH', $path, ['json' => $body]);
    }

    /** @return array<string, mixed> */
    public function delete(string $path): array
    {
        return $this->send('DELETE', $path, []);
    }

    /**
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     *
     * @throws IntegrationFailed
     */
    protected function send(string $method, string $path, array $options): array
    {
        try {
            $response = $this->request()->send($method, ltrim($path, '/'), $options);
        } catch (ConnectionException) {
            throw new IntegrationFailed($this->t(':provider couldn’t be reached. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE);
        }

        if ($response->failed()) {
            throw $this->failure($response) ?? $this->byStatus($response);
        }

        $json = $response->json();

        return is_array($json) ? $json : [];
    }

    protected function request(): PendingRequest
    {
        return Http::baseUrl(rtrim($this->baseUrl(), '/').'/')
            ->withHeaders($this->headers())
            ->acceptJson()
            ->asJson()
            ->timeout(static::TIMEOUT)
            ->connectTimeout(static::CONNECT_TIMEOUT)
            ->retry(
                static::TRIES,
                fn (int $attempt, Throwable $e): int => $this->backoff($attempt, $e),
                fn (Throwable $e, PendingRequest $request, ?string $method = null): bool => $this->retryable($e, $method),
                throw: false,
            );
    }

    // A POST is tried again only when the provider turned it away unread, so nothing is made twice.
    protected function retryable(Throwable $e, ?string $method): bool
    {
        $idempotent = in_array(strtoupper((string) $method), ['GET', 'HEAD', 'PUT', 'DELETE'], true);
        if (! $e instanceof RequestException) {
            return $idempotent && $e instanceof ConnectionException;
        }
        $status = $e->response->status();
        if ($status === 429) {
            return $this->retryAfter($e->response) <= static::LONGEST_WAIT;
        }

        return $idempotent && $status >= 500;
    }

    protected function backoff(int $attempt, Throwable $e): int
    {
        if ($e instanceof RequestException && $e->response->status() === 429) {
            return max(1, $this->retryAfter($e->response)) * 1000;
        }

        return 500 * 3 ** ($attempt - 1);
    }

    protected function retryAfter(Response $response): int
    {
        $after = $response->header('Retry-After');
        if (is_numeric($after)) {
            return (int) $after;
        }
        $reset = $response->header('RateLimit-Reset');
        if (is_numeric($reset)) {
            return max(0, (int) $reset - time());
        }

        return 1;
    }

    private function byStatus(Response $response): IntegrationFailed
    {
        $status = $response->status();
        $said = $this->providerMessage($response);
        $said = $said === null || trim($said) === '' ? null : self::sentence($said);

        return match (true) {
            $status === 401 => new IntegrationFailed($this->t(':provider turned the token down. Check it’s copied in full and hasn’t been deleted.'), IntegrationFailed::CREDENTIALS, $status),
            $status === 403 => new IntegrationFailed($said ?? $this->t(':provider says this token isn’t allowed to do that. Check its permissions.'), IntegrationFailed::PERMISSION, $status),
            $status === 404 => new IntegrationFailed($this->t(':provider can’t find it. It may have been deleted there.'), IntegrationFailed::NOT_FOUND, $status),
            $status === 409 || $status === 423 => new IntegrationFailed($said ?? $this->t(':provider is busy with something else on it. Try again in a minute.'), IntegrationFailed::CONFLICT, $status),
            $status === 429 => new IntegrationFailed($this->t(':provider is limiting requests from this account right now. Try again in a few minutes.'), IntegrationFailed::RATE_LIMITED, $status),
            $status >= 500 => new IntegrationFailed($this->t(':provider isn’t answering properly right now. Try again in a few minutes.'), IntegrationFailed::UNAVAILABLE, $status),
            default => new IntegrationFailed($said ?? $this->t(':provider turned the request down.'), IntegrationFailed::INVALID, $status),
        };
    }

    /** @param  array<string, string|int>  $replace */
    protected function t(string $text, array $replace = []): string
    {
        $replace = ['provider' => $this->providerName(), ...$replace];

        return is_string($line = __($text, $replace)) ? $line : strtr($text, array_combine(array_map(fn (string $k) => ':'.$k, array_keys($replace)), array_map('strval', $replace)));
    }

    private static function sentence(string $text): string
    {
        $text = trim($text);
        $text = mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);

        return preg_match('/[.!?]$/u', $text) === 1 ? $text : $text.'.';
    }
}
