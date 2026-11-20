import { trans } from 'laravel-vue-i18n';

const NETWORK =
    /failed to fetch|networkerror|load failed|network request failed|network error/i;

const BUILT_IN = [
    TypeError,
    SyntaxError,
    RangeError,
    ReferenceError,
    EvalError,
    URIError,
];

const LARAVEL_DEFAULTS = new Set([
    'Server Error',
    'Internal Server Error',
    'Unauthenticated.',
    'Unauthorized.',
    'Unauthorized',
    'CSRF token mismatch.',
    'This action is unauthorized.',
    'Too Many Attempts.',
    'Too Many Requests',
    'Not Found',
    'Page Expired',
    'Forbidden',
    'Bad Request',
    'Method Not Allowed',
    'Payload Too Large',
    'Service Unavailable',
]);

const LARAVEL_INTERNALS =
    /^No query results for model|^The route .* could not be found|method is not supported for route/i;

function sentence(message: string): string {
    const trimmed = message.trimEnd();
    return /[.!?…]$/.test(trimmed) ? trimmed : `${trimmed}.`;
}

function text(value: unknown): string | null {
    if (typeof value === 'string') {
        const trimmed = value.trim();
        return trimmed === '' ? null : trimmed;
    }
    if (Array.isArray(value)) {
        for (const item of value) {
            const found = text(item);
            if (found) return found;
        }
    }
    return null;
}

function parse(body: unknown): unknown {
    if (typeof body !== 'string') return body;
    try {
        return JSON.parse(body);
    } catch {
        return null;
    }
}

function withNextStep(message: string, next: unknown): string {
    const step = text(next);
    if (!step || message.includes(step)) return message;
    return `${sentence(message)} ${step}`;
}

export function reasonIn(raw: unknown): string | null {
    const body = parse(raw);
    if (!body || typeof body !== 'object') return null;
    const b = body as Record<string, unknown>;

    if (b.errors && typeof b.errors === 'object') {
        const field = text(Object.values(b.errors as object));
        if (field) return field;
    }
    if (b.error && typeof b.error === 'object') {
        const e = b.error as Record<string, unknown>;
        const message = text(e.message);
        if (message) return withNextStep(message, e.next_step ?? e.nextStep);
    }
    const error = text(b.error);
    if (error) return withNextStep(error, b.next_step ?? b.nextStep);

    const message = text(b.message);
    if (
        message &&
        !LARAVEL_DEFAULTS.has(message) &&
        !LARAVEL_INTERNALS.test(message)
    ) {
        return withNextStep(message, b.next_step ?? b.nextStep);
    }
    return null;
}

const TELLING =
    /^E: |\b(error|errors|fail|failed|failure|fatal|denied|not found|unable|cannot|can['’]t|couldn['’]?t|invalid|refused|no such|missing|timed out|killed)\b/i;

const ANSI = new RegExp(`${String.fromCharCode(27)}\\[[0-9;]*[A-Za-z]`, 'g');

export function keyLine(lines: unknown): string | null {
    if (!Array.isArray(lines)) return null;
    const clean = lines
        .filter((line): line is string => typeof line === 'string')
        .map((line) => line.replace(ANSI, '').trim())
        .filter((line) => line !== '' && !/^(W: |warning\b)/i.test(line));
    const line =
        [...clean].reverse().find((l) => TELLING.test(l)) ?? clean.at(-1);
    if (!line) return null;
    return line.length > 300 ? `${line.slice(0, 299)}…` : line;
}

export function jobReason(
    error: unknown,
    fallback: string,
    withOutput = true,
): string {
    if (!error || typeof error !== 'object') return fallback;
    const e = error as Record<string, unknown>;
    const message = text(e.message);
    const next = e.next_step ?? e.nextStep;
    const base = message
        ? withNextStep(message, next)
        : (text(next) ?? fallback);
    const line = withOutput ? keyLine(e.output_tail ?? e.outputTail) : null;
    if (!line || base.includes(line)) return base;
    return `${sentence(base)} ${trans('The machine said: :line', { line })}`;
}

export function statusMessage(status: number): string {
    if (status < 400) return trans('That didn’t work. Try again.');
    if (status === 401)
        return trans(
            'You’ve been signed out. Reload the page and sign in again.',
        );
    if (status === 419)
        return trans('This page was open too long. Reload it and try again.');
    if (status === 403)
        return trans('You don’t have permission to do that in this workspace.');
    if (status === 404)
        return trans(
            'That isn’t there any more. Reload the page and try again.',
        );
    if (status === 413) return trans('That’s too big to send.');
    if (status === 429)
        return trans('Too many tries. Wait a minute, then try again.');
    if (status === 502 || status === 503 || status === 504)
        return trans(
            'The panel didn’t answer. It may be restarting; try again in a moment.',
        );
    if (status >= 500)
        return trans(
            'The panel hit an error and logged it. Try again in a moment.',
        );
    return trans('That didn’t work (error :status). Try again.', {
        status: String(status),
    });
}

export function describe(status: number, body: unknown): string {
    return reasonIn(body) ?? statusMessage(status);
}

export async function responseError(response: Response): Promise<string> {
    const body = await response
        .clone()
        .json()
        .catch(() => null);
    return describe(response.status, body);
}

export class RequestError extends Error {
    status: number;

    constructor(message: string, status: number) {
        super(message);
        this.name = 'RequestError';
        this.status = status;
    }
}

export async function failIfBad(response: Response): Promise<Response> {
    if (!response.ok)
        throw new RequestError(await responseError(response), response.status);
    return response;
}

export function networkMessage(): string {
    if (typeof navigator !== 'undefined' && navigator.onLine === false)
        return trans('You’re offline. Check your connection and try again.');
    if (typeof window !== 'undefined' && window.isSecureContext === false)
        return trans(
            'Couldn’t reach the panel. This page isn’t on HTTPS, which often causes this. Open the panel at its https:// address, or set up HTTPS for it.',
        );
    return trans(
        'Couldn’t reach the panel. Check your connection and try again.',
    );
}

export function isNetworkError(cause: unknown): boolean {
    if (!(cause instanceof Error)) return false;
    if ((cause as { code?: unknown }).code === 'ERR_NETWORK') return true;
    return cause instanceof TypeError && NETWORK.test(cause.message);
}

function isCancelled(cause: unknown): boolean {
    return (
        cause instanceof Error &&
        (cause.name === 'AbortError' ||
            (cause as { code?: unknown }).code === 'ERR_CANCELLED')
    );
}

export function errorMessage(cause: unknown, fallback?: string): string {
    if (isNetworkError(cause)) return networkMessage();
    if (isCancelled(cause))
        return fallback ?? trans('That was cancelled. Try again.');

    const response = (
        cause as { response?: { status?: unknown; data?: unknown } }
    )?.response;
    if (response && typeof response.status === 'number')
        return describe(response.status, response.data);

    if (
        cause instanceof Error &&
        !BUILT_IN.some((kind) => cause instanceof kind) &&
        cause.message.trim() !== ''
    )
        return cause.message;

    return (
        fallback ??
        trans('Something went wrong on this page. Reload it and try again.')
    );
}
