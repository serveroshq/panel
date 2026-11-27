import { trans } from 'laravel-vue-i18n';
export type MonitorStatus = 'pending' | 'up' | 'down' | 'paused';

export type Monitor = {
    id: number;
    name: string;
    type: 'http' | 'tcp';
    url: string | null;
    host: string | null;
    port: number | null;
    target: string;
    machineId: number | null;
    interval: number;
    timeout: number;
    expectedStatus: number | null;
    keyword: string | null;
    paused: boolean;
    alerts: boolean;
    status: MonitorStatus;
    since: string | null;
    lastCheckedAt: string | null;
    responseMs: number | null;
    error: string | null;
    sslExpiresAt: string | null;
    mutedUntil: string | null;
    uptime30: number | null;
    days: (number | null)[];
};

export type MachineOption = { id: number; name: string; ip: string | null };

export const statusLook: Record<
    MonitorStatus,
    { dot: string; label: string; text: string }
> = {
    up: {
        dot: 'bg-(--ds-green)',
        label: trans('Up'),
        text: 'text-(--ds-green)',
    },
    down: {
        dot: 'bg-(--ds-red)',
        label: trans('Down'),
        text: 'text-(--ds-red)',
    },
    pending: {
        dot: 'bg-(--ds-text-faint)',
        label: trans('Checking'),
        text: 'text-(--ds-text-muted)',
    },
    paused: {
        dot: 'bg-(--ds-text-faint)',
        label: trans('Paused'),
        text: 'text-(--ds-text-muted)',
    },
};

export const intervals = [30, 60, 180, 300, 600, 1800] as const;

export function every(seconds: number): string {
    return seconds < 60 ? `${seconds} s` : `${seconds / 60} min`;
}

export function percent(value: number | null | undefined): string {
    if (value === null || value === undefined) return '–';
    if (value >= 99.995) return '100%';

    return `${value.toFixed(value >= 99 ? 2 : 1).replace(/\.?0+$/, '')}%`;
}

export function ms(value: number | null | undefined): string {
    if (value === null || value === undefined) return '–';

    return value >= 1000
        ? `${(value / 1000).toFixed(1).replace(/\.0$/, '')} s`
        : `${value} ms`;
}

export function dayTone(value: number | null): string {
    if (value === null) return 'bg-(--ds-active)';
    if (value >= 99.9) return 'bg-(--ds-green)';
    if (value >= 95) return 'bg-(--ds-amber)';

    return 'bg-(--ds-red)';
}

export function span(fromIso: string | null, now = Date.now()): string {
    if (!fromIso) return '';
    const seconds = Math.max(
        0,
        Math.round((now - new Date(fromIso).getTime()) / 1000),
    );

    return duration(seconds);
}

export function duration(seconds: number): string {
    if (seconds < 60) return `${seconds} s`;
    if (seconds < 3600) return `${Math.floor(seconds / 60)} min`;
    if (seconds < 86400) {
        const m = Math.floor((seconds % 3600) / 60);

        return `${Math.floor(seconds / 3600)} h${m ? ` ${m} min` : ''}`;
    }
    const h = Math.floor((seconds % 86400) / 3600);

    return `${Math.floor(seconds / 86400)} d${h ? ` ${h} h` : ''}`;
}

export function ago(iso: string | null, now = Date.now()): string {
    if (!iso) return trans('Not yet');

    return `${span(iso, now)} ago`;
}

export function daysUntil(iso: string | null, now = Date.now()): number | null {
    if (!iso) return null;

    return Math.floor((new Date(iso).getTime() - now) / 86_400_000);
}

export type MonitorRow = Monitor & {
    machine: { id: number; name: string; provider: string | null } | null;
    service: { name: string; image: string | null; icon: string | null } | null;
    pausedBy: { name: string | null; at: string } | null;
};

export function dayBar(value: number | null): string {
    if (value === null) return 'bg-surface-tile';
    if (value >= 99.9) return 'bg-[rgb(62_196_109/0.85)]';
    if (value >= 95) return 'bg-[rgb(240_148_60/0.9)]';

    return 'bg-[rgb(240_105_90/0.95)]';
}

export function uptimeTone(value: number | null): string {
    if (value === null || value >= 99.5) return 'text-fg-secondary';

    return value >= 95 ? 'text-status-pending' : 'text-status-down';
}

export function address(
    monitor: Pick<Monitor, 'type' | 'url' | 'target'>,
): string {
    if (monitor.type === 'tcp') return monitor.target;

    return (monitor.url ?? '').replace(/^https?:\/\//, '').replace(/\/$/, '');
}

export function host(monitor: Pick<Monitor, 'type' | 'url' | 'host'>): string {
    if (monitor.type === 'tcp') return monitor.host ?? '';
    try {
        return new URL(monitor.url ?? '').host;
    } catch {
        return monitor.url ?? '';
    }
}

export function shortError(error: string | null): string | null {
    if (!error) return null;
    const code = /^Returned (\d{3})/.exec(error);
    if (code) return code[1];

    return error.replace(/\.$/, '');
}

export function lasting(fromIso: string | null, now = Date.now()): string {
    if (!fromIso) return '';
    const seconds = Math.max(
        0,
        Math.round((now - new Date(fromIso).getTime()) / 1000),
    );
    if (seconds < 60) return trans(':count s', { count: String(seconds) });
    if (seconds < 3600)
        return trans(':count min', { count: String(Math.floor(seconds / 60)) });
    if (seconds < 86400)
        return trans(':count h', { count: String(Math.floor(seconds / 3600)) });
    const days = Math.floor(seconds / 86400);

    return trans(days === 1 ? '1 day' : ':count days', { count: String(days) });
}

export function relative(iso: string | null, now = Date.now()): string {
    if (!iso) return trans('Not yet');
    const seconds = Math.round((now - new Date(iso).getTime()) / 1000);
    if (seconds < 60) return trans('just now');

    return trans(':time ago', { time: lasting(iso, now) });
}

const ORDER: Record<MonitorStatus, number> = {
    down: 0,
    pending: 1,
    up: 2,
    paused: 3,
};

export function byUrgency<T extends Pick<Monitor, 'status' | 'name'>>(
    a: T,
    b: T,
): number {
    return ORDER[a.status] - ORDER[b.status] || a.name.localeCompare(b.name);
}

export type OutageTone = 'none' | 'up' | 'short' | 'long' | 'ongoing';

export type MonitorDay = {
    day: string;
    percent: number | null;
    down: number;
    tone: OutageTone;
};

export type MonitorBar = { at: string; ms: number | null; failed: number };

export type MonitorChart = {
    step: number;
    bars: MonitorBar[];
    averageMs: number | null;
};

export type MonitorIncidentRow = {
    id: number;
    startedAt: string;
    resolvedAt: string | null;
    cause: string;
    seconds: number;
    tone: OutageTone;
    issues?: { key: string; url: string }[];
};

export type MonitorCheckRow = {
    id: number;
    at: string;
    ok: boolean;
    ms: number | null;
    code: number | null;
    label: string;
    error: string | null;
};

export function cadence(seconds: number): string {
    if (seconds < 60) {
        return trans('every :count seconds', { count: String(seconds) });
    }
    if (seconds === 60) return trans('every minute');
    if (seconds < 3600) {
        return trans('every :count minutes', {
            count: String(Math.round(seconds / 60)),
        });
    }
    if (seconds === 3600) return trans('every hour');

    return trans('every :count hours', {
        count: String(Math.round(seconds / 3600)),
    });
}

function unit(count: number, one: string, many: string): string {
    return trans(count === 1 ? one : many, { count: String(count) });
}

export function spelled(seconds: number): string {
    const s = Math.max(0, Math.round(seconds));
    if (s < 60) return unit(s, '1 second', ':count seconds');
    const minutes = Math.floor(s / 60);
    if (minutes < 60) return unit(minutes, '1 minute', ':count minutes');
    const hours = Math.floor(minutes / 60);
    if (hours < 24) {
        const rest = minutes % 60;
        const h = unit(hours, '1 hour', ':count hours');

        return rest ? `${h} ${unit(rest, '1 minute', ':count minutes')}` : h;
    }
    const days = Math.floor(hours / 24);
    const rest = hours % 24;
    const d = unit(days, '1 day', ':count days');

    return rest ? `${d} ${unit(rest, '1 hour', ':count hours')}` : d;
}

export function clock(iso: string): string {
    return new Date(iso).toLocaleTimeString(undefined, {
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });
}

export function shortDay(iso: string): string {
    return new Date(iso).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
    });
}

export function dayAndTime(iso: string, now = Date.now()): string {
    const date = new Date(iso);
    const today = new Date(now);
    const yesterday = new Date(now - 86_400_000);
    if (date.toDateString() === today.toDateString()) {
        return trans('Today, :time', { time: clock(iso) });
    }
    if (date.toDateString() === yesterday.toDateString()) {
        return trans('Yesterday, :time', { time: clock(iso) });
    }

    return `${shortDay(iso)}, ${clock(iso)}`;
}

export function roughly(fromIso: string | null, now = Date.now()): string {
    if (!fromIso) return '';
    const s = Math.max(
        0,
        Math.round((now - new Date(fromIso).getTime()) / 1000),
    );
    if (s < 60) return unit(s, '1 second', ':count seconds');
    if (s < 3600) return unit(Math.floor(s / 60), '1 minute', ':count minutes');
    if (s < 86400) return unit(Math.floor(s / 3600), '1 hour', ':count hours');

    return unit(Math.floor(s / 86400), '1 day', ':count days');
}
