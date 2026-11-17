import { trans } from 'laravel-vue-i18n';

export type TaskStatus =
    | 'new'
    | 'queued'
    | 'running'
    | 'review'
    | 'merged'
    | 'done'
    | 'failed'
    | 'stopped';

export type AgentBrief = {
    run: number;
    state: string;
    phase: string | null;
    message: string | null;
    pr: string | null;
    prState: 'open' | 'closed' | 'merged' | null;
    startedAt: string;
    seconds: number;
};

export type Step = { k: string; t: string; d: string | null };

export type AgentDetail = AgentBrief & {
    summary: string;
    model: string | null;
    stat: string | null;
    error: string | null;
    notes: string | null;
    followsOn: boolean;
    after: number | null;
    turn: string;
    by: string | null;
    base: string;
    prBranch: string | null;
    merged: { sha: string; by: string | null; at: string | null } | null;
    deploy: {
        id: string;
        state: string;
        service: string | null;
        machine: { id: number; name: string };
    } | null;
    canDeploy: { service: string | null; machine: string } | null;
    preview: {
        state: 'off' | 'none' | 'live' | 'closing';
        url: string | null;
        project: { id: number; name: string } | null;
    } | null;
    machineJob: string | null;
    cost: number | null;
    log: string[];
    steps: Step[];
    plan: { t: string; s: 'pending' | 'in_progress' | 'completed' }[] | null;
    events: { t: number; level: string; message: string }[];
};

export type TaskRow = {
    id: number;
    title: string;
    agent: string;
    agentName: string;
    model: string | null;
    repo: string | null;
    branch: string;
    pullRequest: boolean;
    context: { kind: string; label: string } | null;
    machine: { id: number; name: string };
    user: string | null;
    createdAt: string | null;
    updatedAt: string | null;
    status: TaskStatus;
    agents: AgentBrief[];
    waiting: number;
};

export type Waiting = {
    turn: string;
    notes: string | null;
    after: number | null;
    tonight: boolean;
    at: string | null;
};

export type TaskDetail = Omit<TaskRow, 'agents' | 'waiting' | 'context'> & {
    prompt: string;
    context: { kind: string; label: string; text: string } | null;
    service: string;
    agents: AgentDetail[];
    waiting: Waiting[];
    capacity: { slots: number; running: number };
};

export type StartContext = {
    ref: string;
    kind: string;
    label: string;
    preview: string;
    lines: number;
    prompt: string;
    repo: string | null;
    branch: string | null;
    machineId: number | null;
};

export type Tone = 'blue' | 'green' | 'red' | 'amber' | 'neutral';

export const toneDot: Record<Tone, string> = {
    blue: 'bg-(--ds-blue)',
    green: 'bg-(--ds-green)',
    red: 'bg-(--ds-red)',
    amber: 'bg-(--ds-amber)',
    neutral: 'bg-(--ds-text-faint)',
};

export const toneText: Record<Tone, string> = {
    blue: 'text-(--ds-blue)',
    green: 'text-(--ds-green)',
    red: 'text-(--ds-red)',
    amber: 'text-(--ds-amber)',
    neutral: 'text-(--ds-text-muted)',
};

export function taskStatus(status: TaskStatus): { label: string; tone: Tone } {
    return {
        new: { label: trans('Not started'), tone: 'neutral' as Tone },
        queued: { label: trans('Waiting'), tone: 'amber' as Tone },
        running: { label: trans('Working'), tone: 'blue' as Tone },
        review: { label: trans('Ready for review'), tone: 'green' as Tone },
        merged: { label: trans('Merged'), tone: 'blue' as Tone },
        done: { label: trans('Finished'), tone: 'neutral' as Tone },
        failed: { label: trans('Failed'), tone: 'red' as Tone },
        stopped: { label: trans('Stopped'), tone: 'neutral' as Tone },
    }[status];
}

export function agentState(agent: AgentBrief): { label: string; tone: Tone } {
    if (agent.state === 'running') {
        return agent.phase === 'pull_request'
            ? { label: trans('Opening a pull request'), tone: 'blue' }
            : { label: trans('Working'), tone: 'blue' };
    }
    if ('prState' in agent && agent.prState === 'merged') {
        return { label: trans('Merged'), tone: 'blue' };
    }
    if (agent.state === 'succeeded') {
        return agent.pr
            ? { label: trans('Pull request open'), tone: 'green' }
            : { label: trans('Finished'), tone: 'neutral' };
    }
    if (agent.state === 'failed') {
        return { label: trans('Failed'), tone: 'red' };
    }

    return { label: trans('Stopped'), tone: 'neutral' };
}

export function prNumber(url: string | null): string | null {
    const match = url?.match(/\/pull\/(\d+)/);

    return match ? `#${match[1]}` : null;
}

export function ago(iso: string | null): string {
    if (!iso) return '';
    const s = Math.max(
        0,
        Math.round((Date.now() - new Date(iso).getTime()) / 1000),
    );
    if (s < 60) return trans('just now');
    if (s < 3600)
        return trans(':floor min ago', { floor: String(Math.floor(s / 60)) });
    if (s < 86400)
        return trans(':count h ago', { count: String(Math.floor(s / 3600)) });

    return trans(':count d ago', { count: String(Math.floor(s / 86400)) });
}

export function duration(seconds: number): string {
    if (seconds < 60) return `${seconds}s`;
    const m = Math.floor(seconds / 60);
    if (m < 60) return `${m}m ${String(seconds % 60).padStart(2, '0')}s`;

    return `${Math.floor(m / 60)}h ${String(m % 60).padStart(2, '0')}m`;
}
