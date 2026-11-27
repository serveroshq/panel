export type CloudflareRecord = {
    hostname: string;
    zone: string;
    type: string | null;
    content: string | null;
    proxied: boolean;
    forced: boolean;
    status: 'pending' | 'active' | 'failed';
    error: string | null;
    warning: string | null;
    created: boolean;
    here: boolean;
    app: string;
    machine: string | null;
    machineId: number;
    syncedAt: string | null;
};

export type CloudflareCheck = {
    account: string;
    zone: string;
    canPoint: boolean;
    forced: boolean;
    record: CloudflareRecord | null;
};

export type CloudflareDomain = {
    hostname: string;
    account: string | null;
    zone: string | null;
    record: CloudflareRecord | null;
};

export type CloudflarePurge = {
    hosts: string[];
    error: string | null;
    at: string;
    auto: boolean;
};

export type CloudflareApp = {
    domains: CloudflareDomain[];
    canPurge: boolean;
    purgeAfterDeploy: boolean;
    lastPurge: CloudflarePurge | null;
    canPoint: boolean;
    forced: boolean;
};

export type CloudflareZone = {
    id: string;
    name: string;
    status: string;
    managed: number;
};

export type CloudflareAccount = {
    zones: CloudflareZone[];
    records: CloudflareRecord[];
    error: string | null;
};

export type JsonAnswer<T = Record<string, unknown>> = {
    ok: boolean;
    status: number;
    data: T & {
        error?: string | null;
        message?: string;
        errors?: Record<string, string[]>;
    };
};

export function managedHere(record: CloudflareRecord | null): boolean {
    return !!record && record.here && record.status === 'active';
}

export async function sendJson<T = Record<string, unknown>>(
    method: 'POST' | 'PUT' | 'PATCH',
    url: string,
    body: unknown = {},
): Promise<JsonAnswer<T>> {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content ?? '',
        },
        body: JSON.stringify(body),
    });
    const data = (await response
        .json()
        .catch(() => ({}))) as JsonAnswer<T>['data'];

    return { ok: response.ok, status: response.status, data };
}

export function answerError(answer: JsonAnswer, fallback: string): string {
    const first = answer.data.errors
        ? Object.values(answer.data.errors)[0]?.[0]
        : undefined;

    return answer.data.error || first || answer.data.message || fallback;
}
