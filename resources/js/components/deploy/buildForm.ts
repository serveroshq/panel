// How an app is built and run, as the deploy dialog's Advanced section
// edits it, and what of it a deploy sends (only what differs from the
// defaults the panel gives a new app, so those stay the panel's to set).

export type BuildMethod =
    | 'railpack'
    | 'dockerfile'
    | 'compose'
    | 'image'
    | 'template';

export type HealthForm = {
    enabled: boolean;
    path: string;
    /** Any HTTP answer below 500 passes, not one status. */
    any: boolean;
    status: number | '';
    /** Seconds a new version gets to boot before failed checks count. */
    startPeriod: number | '';
};

export type VolumeRow = { id: number; name: string; path: string };

export type BuildForm = {
    dockerfile: string;
    target: string;
    image: string;
    install: string;
    build: string;
    start: string;
    health: HealthForm;
    volumes: VolumeRow[];
    release: string;
    postDeploy: string;
    submodules: boolean;
    lfs: boolean;
};

export type RailpackHints = {
    language: string | null;
    framework: string | null;
    packageManager: string | null;
    version: string | null;
    startCommand: string | null;
};

type TemplateLike = {
    kind: 'compose' | 'app' | 'image';
    build?: string | null;
    compose?: string | null;
    web: boolean;
    health: string | null;
};

export function methodOf(template: TemplateLike): BuildMethod {
    if (template.kind === 'image') return 'image';
    if (template.build === 'railpack') return 'railpack';
    if (template.compose) return 'compose';
    if (template.build === 'template') return 'template';

    return 'dockerfile';
}

/** The health check a new app gets, as the panel works it out. */
export function defaultHealth(
    template: TemplateLike,
    path: string | null,
    canDeployBuild: boolean,
): HealthForm {
    const chosen = path || template.health || '/';
    const checks = template.web && template.health !== null;
    // ServerOS's setup for a framework with a known route (Laravel's /up) wants a 200.
    const known = methodOf(template) === 'template' && chosen !== '/';

    return {
        enabled: checks,
        path: chosen,
        any: canDeployBuild && !known,
        status: 200,
        startPeriod: canDeployBuild ? 60 : 0,
    };
}

export function emptyBuild(health: HealthForm): BuildForm {
    return {
        dockerfile: 'Dockerfile',
        target: '',
        image: '',
        install: '',
        build: '',
        start: '',
        health,
        volumes: [],
        release: '',
        postDeploy: '',
        submodules: true,
        lfs: true,
    };
}

let nextVolume = 1;

export function volumeRow(): VolumeRow {
    return { id: nextVolume++, name: '', path: '' };
}

function text(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
}

/**
 * The `build` object of a deploy request: what the person changed. Null
 * when there's nothing to send, and always on an older daemon, which
 * reads none of it.
 */
export function buildPayload(
    form: BuildForm,
    method: BuildMethod,
    defaults: HealthForm,
    options: { canDeployBuild: boolean; upload: boolean; web: boolean },
): Record<string, unknown> | null {
    if (!options.canDeployBuild) return null;
    const out: Record<string, unknown> = {};

    if (method === 'image') out.image = text(form.image);
    if (method === 'dockerfile') {
        const dockerfile = text(form.dockerfile);
        if (dockerfile && dockerfile !== 'Dockerfile') {
            out.dockerfile = dockerfile;
        }
        if (text(form.target)) out.target = text(form.target);
    }
    if (method === 'railpack') {
        if (text(form.install)) out.install_command = text(form.install);
        if (text(form.build)) out.build_command = text(form.build);
        if (text(form.start)) out.start_command = text(form.start);
    }
    if (method !== 'compose') {
        const volumes = form.volumes
            .filter((v) => v.name.trim() !== '' || v.path.trim() !== '')
            .map((v) => ({ name: v.name.trim(), path: v.path.trim() }));
        if (volumes.length) out.volumes = volumes;
        if (text(form.release)) out.release_command = text(form.release);
        if (text(form.postDeploy)) {
            out.post_deploy_command = text(form.postDeploy);
        }
        if (method !== 'image' && !options.upload) {
            if (!form.submodules) out.submodules = false;
            if (!form.lfs) out.lfs = false;
        }
    }

    const health = form.health;
    if (options.web && !health.enabled && defaults.enabled) {
        out.health = { enabled: false };
    } else if (
        options.web &&
        health.enabled &&
        (!defaults.enabled ||
            health.any !== defaults.any ||
            (!health.any && Number(health.status) !== defaults.status) ||
            Number(health.startPeriod || 0) !== defaults.startPeriod)
    ) {
        out.health = {
            enabled: true,
            path: health.path || '/',
            expected_status: health.any ? 0 : Number(health.status) || 200,
            start_period_secs: Number(health.startPeriod || 0),
        };
    }

    return Object.keys(out).length ? out : null;
}

/** How many settings in Advanced differ from the defaults, for its summary. */
export function changedCount(
    form: BuildForm,
    method: BuildMethod,
    defaults: HealthForm,
    options: { canDeployBuild: boolean; upload: boolean; web: boolean },
): number {
    const payload = buildPayload(form, method, defaults, options) ?? {};

    return Object.keys(payload).filter((key) => key !== 'image').length;
}
