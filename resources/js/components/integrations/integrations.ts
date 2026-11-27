import type { FilledIconName } from '@/components/icons/filledIcons';

export type IntegrationTarget = {
    href?: string;
    alerts?: boolean;
};

export type IntegrationAccountLink = {
    id: number;
    label: string;
    href: string;
};

export type IntegrationAction = IntegrationTarget & {
    label: string;
    unavailable?: boolean;
    connect?: string;
    accounts?: IntegrationAccountLink[];
    hint?: { text: string; href: string | null; label: string | null };
};

export type Integration = {
    key: string;
    name: string;
    category: string;
    logo: string | null;
    initials: string | null;
    description: string;
    tags: string[];
    connected: boolean;
    action: IntegrationAction | null;
};

export type IntegrationCategory = {
    key: string;
    tab: string;
    title: string;
    subtitle: string;
    icon: FilledIconName;
};

export type IntegrationConnection = {
    key: string;
    name: string;
    logo: string | null;
    detail: string;
    target: IntegrationTarget | null;
    failing?: boolean;
};

export type ConnectField = {
    key: string;
    label: string;
    secret: boolean;
    help: string | null;
    link: string | null;
    linkLabel: string | null;
    placeholder: string | null;
    value?: string | null;
};

export type Capability = { key: string; label: string; description: string };

export type ProviderInfo = {
    key: string;
    name: string;
    fields: ConnectField[];
    capabilities: Capability[];
    multiple: boolean;
};

export type OperationStep = {
    key: string;
    label: string;
    state: 'done' | 'current' | 'failed' | 'todo';
};

export type Operation = {
    id: number;
    integrationId: number;
    kind: 'create' | 'resize';
    state: 'queued' | 'running' | 'done' | 'failed';
    name: string;
    type: string | null;
    from: string | null;
    serverId: string | null;
    machineId: number | null;
    machineName: string | null;
    error: string | null;
    steps: OperationStep[];
    createdAt: string | null;
    finishedAt: string | null;
};

export type CloudServer = {
    id: string;
    name: string;
    status: 'running' | 'starting' | 'stopping' | 'off' | 'deleting' | 'other';
    type: string;
    typeLabel: string;
    cores: number;
    memoryGb: number;
    diskGb: number;
    location: string;
    city: string | null;
    country: string | null;
    ipv4: string | null;
    ipv6: string | null;
    architecture: string | null;
    createdAt: string | null;
};

export type ServerRules = {
    powerOff: boolean;
    disk: 'optional' | 'always';
    down: boolean;
    gracefulRestart: boolean;
    snapshotsPerServer: boolean;
};

export type MachineCloudServer = {
    integration: {
        id: number;
        provider: string;
        name: string;
        label: string;
        status: string;
    };
    server: CloudServer;
    syncedAt: string | null;
    can: string[];
    rules: ServerRules;
    operation: Operation | null;
};

export type ServerTypeOption = {
    name: string;
    label: string;
    cores: number;
    memoryGb: number;
    diskGb: number;
    cpu: string;
    architecture: string;
    storage?: string;
    locations?: string[];
    prices?: Record<string, { monthly: number; hourly: number }>;
    monthly?: number | null;
    hourly?: number | null;
};

export type ServerPlans = {
    locations: {
        name: string;
        city: string;
        country: string;
        zone: string | null;
    }[];
    types: ServerTypeOption[];
    images: {
        id: string;
        label: string;
        os: string;
        version: string | null;
        architecture: string;
        recommended: boolean;
    }[];
    keys: { id: string; name: string; fingerprint: string | null }[];
    currency: string;
};

export type Snapshot = {
    id: string;
    name: string;
    status: string;
    sizeGb: number | null;
    diskGb: number | null;
    createdAt: string | null;
};

export const LOGO_IMAGES: Record<string, string> = {
    synteq: '/images/integrations/synteq.png',
};

export function sizeLine(type: {
    cores: number;
    memoryGb: number;
    diskGb: number;
}): string {
    const memory = Number.isInteger(type.memoryGb)
        ? String(type.memoryGb)
        : type.memoryGb.toFixed(1);

    return `${type.cores} vCPU · ${memory} GB · ${type.diskGb} GB`;
}

export function money(amount: number, currency: string): string {
    try {
        return new Intl.NumberFormat(undefined, {
            style: 'currency',
            currency,
            minimumFractionDigits: 2,
            maximumFractionDigits: 2,
        }).format(amount);
    } catch {
        return `${amount.toFixed(2)} ${currency}`;
    }
}

export type JsonResult<T> =
    | { ok: true; data: T }
    | { ok: false; errors: Record<string, string>; message: string };

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

export async function sendJson<T>(
    method: 'GET' | 'POST' | 'DELETE',
    url: string,
    body?: unknown,
): Promise<JsonResult<T>> {
    const init: RequestInit = {
        method,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            ...(method === 'GET'
                ? {}
                : {
                      'Content-Type': 'application/json',
                      'X-CSRF-TOKEN': csrf(),
                  }),
        },
    };
    if (method !== 'GET' && body !== undefined) {
        init.body = JSON.stringify(body);
    }

    let response: Response;
    try {
        response = await fetch(url, init);
    } catch {
        return { ok: false, errors: {}, message: '' };
    }

    const data = (await response.json().catch(() => null)) as Record<
        string,
        unknown
    > | null;
    if (response.ok) return { ok: true, data: data as T };

    const errors: Record<string, string> = {};
    const raw = (data?.errors ?? {}) as Record<string, string[] | string>;
    for (const [key, value] of Object.entries(raw)) {
        errors[key] = Array.isArray(value) ? (value[0] ?? '') : value;
    }
    const message =
        (typeof data?.error === 'string' ? data.error : null) ??
        errors.form ??
        Object.values(errors)[0] ??
        (typeof data?.message === 'string' ? data.message : '');

    return { ok: false, errors, message };
}
