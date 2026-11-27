export type ExternalLink = { key: string | null; url: string };

export type IntegrationEventRow = {
    id: number;
    action: string;
    label: string;
    subject: string;
    state: 'queued' | 'sending' | 'retrying' | 'sent' | 'skipped' | 'failed';
    error: string | null;
    attempts: number;
    nextAttemptAt: string | null;
    createdAt: string | null;
    link: ExternalLink | null;
};

export type OpenIncident = {
    id: number;
    kind: 'monitor' | 'machine' | 'cve';
    title: string;
    openedAt: string;
    link: ExternalLink | null;
};

export type LinkedApp = {
    id: number;
    service: string;
    machine: string;
    href: string;
    project: string | null;
    environment: string | null;
};

export type Picked = { id: string; name: string; key?: string };

export type PagerDutyRouting = {
    monitors: 'all' | 'some' | 'none';
    monitor_ids: number[];
    machines: boolean;
    cves: boolean;
    monitorOptions: { id: number; name: string; status: string }[];
};

export type LinearRouting = {
    team: Picked | null;
    project: Picked | null;
    label: Picked | null;
    done: Picked | null;
    monitors: boolean;
    minutes: number;
    machines: boolean;
    cves: boolean;
};

export type LinearOptions = {
    teams: Picked[];
    team: Picked | null;
    projects: Picked[];
    labels: Picked[];
    done: Picked | null;
};

export type SentryProject = {
    id: string;
    slug: string;
    name: string;
    platform: string | null;
};

export type ServiceSentry = {
    accounts: { id: number; label: string }[];
    link: {
        integrationId: number;
        account: string;
        project: string;
        projectId: string | null;
        projectName: string;
        environment: string;
    } | null;
    releases: Record<string, { url: string; newIssues: number | null }>;
    error: string | null;
};

export async function getJson<T>(url: string): Promise<T> {
    const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
    });
    const body = (await response.json().catch(() => ({}))) as T & {
        error?: string;
        message?: string;
    };
    if (!response.ok) {
        throw new Error(body.error ?? body.message ?? String(response.status));
    }

    return body;
}
