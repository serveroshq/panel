import type { HealthIssue } from '@/lib/healthIssues';
import { workspacePath } from '@/lib/workspaceRoute';

export type HomeIssue = HealthIssue & {
    brief: string;
    dismissible: boolean;
    linked?: { key: string; url: string } | null;
};

export type HomeOverview = {
    machines: number;
    online: number;
    offline: string[];
    connecting: number;
    services: number;
    running: number;
    serviceProblems: number;
    monitors: number;
    monitorsDown: number;
    uptime: number | null;
    averageCpu: number | null;
    reporting: number;
};

export type HomeBusy = {
    id: number;
    name: string;
    cpu: number;
};

export type HomeSecurity = {
    package: string | null;
    severity: 'critical' | 'high' | 'medium' | 'low' | 'unknown' | null;
    cves: number;
    exploited: number;
    machines: { id: number; name: string }[];
    affected: number;
    critical: number;
    high: number;
    updates: number;
    checkedAt: string | null;
};

export type HomeBackups = {
    machines: number;
    covered: number;
    failed: number;
    schedule: 'daily' | 'weekly' | null;
    lastRunAt: string | null;
    sources: { id: number; name: string; database: boolean; failed: boolean }[];
    destinations: { id: number; name: string; logo: string | null }[];
};

export const BUSY_CPU = 80;
export const WARM_CPU = 60;

export function issueHref(href: string, workspaceUrl: string): string {
    const base = workspaceUrl.replace(/\/$/, '');
    if (
        href !== base &&
        !href.startsWith(`${base}/`) &&
        !href.startsWith(`${base}?`)
    ) {
        return href;
    }

    return workspacePath(href.slice(base.length) || '/');
}
