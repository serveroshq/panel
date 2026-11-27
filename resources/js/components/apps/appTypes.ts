import { trans } from 'laravel-vue-i18n';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';

export type JobState =
    | 'queued'
    | 'running'
    | 'succeeded'
    | 'failed'
    | 'timed_out'
    | 'cancelled'
    | 'refused'
    | 'stale';

export type SettingsKey =
    | 'health'
    | 'port'
    | 'image'
    | 'start_command'
    | 'release_command'
    | 'method';

export type Explanation = {
    cause: string;
    fix: string;
    command?: string;
    alternative?: string;
    install?: { label: string; packages: string[] };
    docker?: true;
    cleanup?: true;
    settings?: Exclude<SettingsKey, 'method'>;
};

export type DeployRow = {
    id: string;
    kind: 'deploy' | 'rollback';
    state: JobState;
    phase: string | null;
    progress: number | null;
    trigger: string;
    by: string | null;
    commit: string | null;
    image: string | null;
    noCache: boolean;
    message: string | null;
    explanation: Explanation | null;
    retryable: boolean;
    createdAt: string | null;
    finishedAt: string | null;
    durationSecs: number | null;
};

export type DeployJob = DeployRow & {
    log: string[];
    error: {
        message?: string;
        next_step?: string;
        output_tail?: string[];
    } | null;
    result: Record<string, unknown> | null;
};

export type LastFailure = {
    jobId: string;
    kind: 'deploy' | 'rollback';
    state: string;
    phase: string | null;
    message: string | null;
    nextStep: string | null;
    keyLine: string | null;
    explanation: Explanation | null;
    at: string | null;
};

export type Hook = {
    exists: boolean;
    hint: string | null;
    createdAt: string | null;
    lastUsedAt: string | null;
    lastTrigger: string | null;
    by: string | null;
};

export type Schedule = {
    id: string;
    name: string | null;
    command: string;
    cron: string;
    enabled: boolean;
    timeout: number;
    by: string | null;
    nextRunAt: string | null;
    lastRun: {
        jobId: string;
        state: JobState;
        exitCode: number | null;
        at: string | null;
        finishedAt: string | null;
    } | null;
};

export type BuildMethod =
    | 'railpack'
    | 'dockerfile'
    | 'compose'
    | 'image'
    | 'template';

export type Health = {
    enabled: boolean;
    path: string;
    expectedStatus: number;
    timeoutSecs: number;
    retries: number;
    startPeriodSecs: number;
    intervalSecs: number | null;
};

export type Volume = { name: string; path: string };

export type AppVariableFlags = {
    name: string;
    build: boolean;
    runtime: boolean;
    from: string | null;
    auto: boolean;
};

export type BuildSettings = {
    method: BuildMethod;
    dockerfile: string | null;
    target: string | null;
    composeFile: string | null;
    image: string | null;
    installCommand: string | null;
    buildCommand: string | null;
    startCommand: string | null;
    baseDir: string | null;
    port: number | null;
    health: Health;
    volumes: Volume[];
    releaseCommand: string | null;
    postDeployCommand: string | null;
    submodules: boolean;
    lfs: boolean;
    network: string | null;
    variables: AppVariableFlags[];
};

export type AppDeploy = {
    service: string;
    source: 'git' | 'upload' | 'image';
    repo: string | null;
    upload: { filename: string; usable: boolean } | null;
    branch: string | null;
    commit: string | null;
    liveCommit: string | null;
    domains: string[];
    detectedPort: number | null;
    canDeployBuild: boolean;
    update: string | null;
    build: BuildSettings;
    deploying: DeployRow | null;
    lastFailure: LastFailure | null;
    hook: Hook | null;
    schedules: Schedule[];
    schedulesUnavailable: string | null;
    pushToDeploy: { on: boolean; branch: string; onlyNewest: boolean } | null;
    can: {
        deployLatest: boolean;
        deployCommit: boolean;
        rebuild: boolean;
        redeploy: boolean;
        hook: boolean;
    };
};

/** A deploy request's answer: the job it queued and the app as it is now. */
export type Deployed = { jobId: string | null; deploy: AppDeploy };

export const FINISHED: readonly string[] = [
    'succeeded',
    'failed',
    'timed_out',
    'cancelled',
    'refused',
];

export function isFinished(state: string): boolean {
    return FINISHED.includes(state);
}

export function isFailed(state: string): boolean {
    return ['failed', 'timed_out', 'refused'].includes(state);
}

export function shortSha(commit: string | null | undefined): string | null {
    return commit ? commit.slice(0, 7) : null;
}

/** How a deploy or rollback ended up, in the dashboard's badge. */
export function rowStatus(row: { state: string; kind?: string }): {
    status: DashStatus;
    label: string;
} {
    switch (row.state) {
        case 'queued':
            return { status: 'deploying', label: trans('Queued') };
        case 'running':
            return {
                status: 'deploying',
                label:
                    row.kind === 'rollback'
                        ? trans('Rolling back')
                        : trans('Deploying'),
            };
        case 'succeeded':
            return {
                status: 'live',
                label:
                    row.kind === 'rollback'
                        ? trans('Rolled back')
                        : trans('Deployed'),
            };
        case 'failed':
            return { status: 'down', label: trans('Failed') };
        case 'timed_out':
            return { status: 'down', label: trans('Timed out') };
        case 'refused':
            return { status: 'down', label: trans('Refused') };
        case 'cancelled':
            return { status: 'stopped', label: trans('Stopped') };
        case 'stale':
            return { status: 'pending', label: trans('No answer') };
        default:
            return { status: 'stopped', label: row.state };
    }
}

export function triggerLabel(trigger: string, kind?: string): string {
    if (kind === 'rollback') return trans('Rollback');
    switch (trigger) {
        case 'dashboard':
            return trans('Dashboard');
        case 'api':
            return trans('API');
        case 'mcp':
            return trans('MCP');
        case 'workflow':
            return trans('Workflow');
        case 'assistant':
            return trans('Assistant');
        case 'push':
            return trans('Push');
        case 'hook':
            return trans('Deploy hook');
        case 'hook:github':
            return trans('GitHub hook');
        case 'hook:gitlab':
            return trans('GitLab hook');
        case 'hook:gitea':
            return trans('Gitea hook');
        case 'hook:bitbucket':
            return trans('Bitbucket hook');
        default:
            return trigger;
    }
}

export function seconds(total: number | null | undefined): string {
    if (total === null || total === undefined) return '–';
    if (total < 60) return `${total}s`;
    const minutes = Math.floor(total / 60);
    if (minutes < 60) {
        return total % 60 ? `${minutes}m ${total % 60}s` : `${minutes}m`;
    }

    return `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}

export function methodLabel(method: BuildMethod): string {
    return {
        railpack: trans('Railpack'),
        dockerfile: trans('Dockerfile'),
        compose: trans('Compose file'),
        image: trans('Prebuilt image'),
        template: trans('ServerOS’s Dockerfile'),
    }[method];
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

export type JsonAnswer = {
    ok: boolean;
    status: number;
    body: Record<string, unknown>;
};

/** A JSON request to the panel. Throws only when the panel can't be reached. */
export async function sendJson(
    url: string,
    method: 'GET' | 'POST' | 'PUT' | 'DELETE',
    body?: unknown,
): Promise<JsonAnswer> {
    const init: RequestInit = {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
    };
    if (body !== undefined) init.body = JSON.stringify(body);
    const response = await fetch(url, init);
    const parsed = await response.json().catch(() => ({}));

    return {
        ok: response.ok,
        status: response.status,
        body: (parsed ?? {}) as Record<string, unknown>,
    };
}

export type RedeployBody = {
    commit?: string | null;
    latest?: boolean;
    no_cache?: boolean;
};

/**
 * Deploys the app again. Gives the queued job and the app as it is now,
 * or what went wrong: field errors (422) or one message.
 */
export async function redeployApp(
    url: string,
    body: RedeployBody,
): Promise<
    | { ok: true; jobId: string; deploy: AppDeploy }
    | {
          ok: false;
          status: number;
          errors: Record<string, string>;
          body: Record<string, unknown>;
      }
> {
    const answer = await sendJson(url, 'POST', body);
    if (answer.ok && typeof answer.body.jobId === 'string') {
        return {
            ok: true,
            jobId: answer.body.jobId,
            deploy: answer.body.deploy as AppDeploy,
        };
    }

    return {
        ok: false,
        status: answer.status,
        errors: fieldErrors(answer.body),
        body: answer.body,
    };
}

/** Laravel's validation errors, first message per key. */
export function fieldErrors(
    body: Record<string, unknown>,
): Record<string, string> {
    const errors = body.errors;
    if (!errors || typeof errors !== 'object') return {};

    return Object.fromEntries(
        Object.entries(errors as Record<string, unknown>).map(
            ([key, value]) => [
                key,
                Array.isArray(value) ? String(value[0] ?? '') : String(value),
            ],
        ),
    );
}

/** The commit message for a commit, from the page's messages (keyed by full or short sha). */
export function messageFor(
    messages: Record<string, string> | undefined,
    commit: string | null,
): string | null {
    if (!messages || !commit) return null;
    if (messages[commit]) return messages[commit];
    for (const [key, message] of Object.entries(messages)) {
        if (key.startsWith(commit) || commit.startsWith(key)) return message;
    }

    return null;
}
