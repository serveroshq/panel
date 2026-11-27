<script setup lang="ts">
import { X } from '@lucide/vue';
import { useMediaQuery } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, watch } from 'vue';
import type {
    AppDeploy,
    BuildMethod,
    BuildSettings,
    SettingsKey,
} from '@/components/apps/appTypes';
import {
    fieldErrors,
    methodLabel,
    sendJson,
    shortSha,
} from '@/components/apps/appTypes';
import DashButton from '@/components/dash/DashButton.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import DashTray from '@/components/dash/DashTray.vue';
import FieldSelect from '@/components/machines/settings/FieldSelect.vue';
import type { FieldOption } from '@/components/machines/settings/FieldSelect.vue';
import SettingsRow from '@/components/machines/settings/SettingsRow.vue';
import { describe, errorMessage } from '@/lib/errors';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import appsRoutes from '@/routes/workspace/apps';

const props = defineProps<{
    deploy: AppDeploy;
    workspaceSlug: string;
    machineId: number;
    machineName: string;
    canManage: boolean;
    online: boolean;
    /** Set by the failure banner: the setting to bring into view. */
    focus: { key: SettingsKey | null; at: number } | null;
}>();

const emit = defineEmits<{ deployed: [jobId: string, deploy: AppDeploy] }>();

// On a phone, a row's control goes under its label.
const narrow = useMediaQuery('(max-width: 639px)');

type Draft = {
    method: BuildMethod;
    dockerfile: string;
    target: string;
    composeFile: string;
    image: string;
    installCommand: string;
    buildCommand: string;
    startCommand: string;
    baseDir: string;
    port: string;
    healthEnabled: boolean;
    healthPath: string;
    healthAny: boolean;
    healthStatus: string;
    startPeriod: string;
    volumes: { name: string; path: string }[];
    releaseCommand: string;
    postDeployCommand: string;
    submodules: boolean;
    lfs: boolean;
};

function draftOf(build: BuildSettings): Draft {
    return {
        method: build.method,
        dockerfile: build.dockerfile ?? '',
        target: build.target ?? '',
        composeFile: build.composeFile ?? '',
        image: build.image ?? '',
        installCommand: build.installCommand ?? '',
        buildCommand: build.buildCommand ?? '',
        startCommand: build.startCommand ?? '',
        baseDir: build.baseDir ?? '',
        port: build.port === null ? '' : String(build.port),
        healthEnabled: build.health.enabled,
        healthPath: build.health.path,
        healthAny: build.health.expectedStatus === 0,
        healthStatus:
            build.health.expectedStatus === 0
                ? '200'
                : String(build.health.expectedStatus),
        startPeriod: String(build.health.startPeriodSecs),
        volumes: build.volumes.map((volume) => ({ ...volume })),
        releaseCommand: build.releaseCommand ?? '',
        postDeployCommand: build.postDeployCommand ?? '',
        submodules: build.submodules,
        lfs: build.lfs,
    };
}

const draft = ref<Draft>(draftOf(props.deploy.build));
let baseline = JSON.stringify(draftOf(props.deploy.build));
const errors = ref<Record<string, string>>({});
const saving = ref(false);
const highlight = ref<string | null>(null);

// Keep the form on what the app has now, unless someone is editing it.
watch(
    () => props.deploy.build,
    (build) => {
        const next = draftOf(build);
        if (JSON.stringify(draft.value) === baseline) {
            draft.value = next;
        }
        baseline = JSON.stringify(next);
    },
    { deep: true },
);

function text(value: string): string | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : trimmed;
}

function number(value: string): number | null {
    const trimmed = value.trim();

    return trimmed === '' ? null : Number(trimmed);
}

/** What changed, as the build endpoint takes it (snake_case, only changed keys). */
const changes = computed<Record<string, unknown>>(() => {
    const now = draft.value;
    const was = draftOf(props.deploy.build);
    const out: Record<string, unknown> = {};
    const plain: [keyof Draft, string][] = [
        ['dockerfile', 'dockerfile'],
        ['target', 'target'],
        ['composeFile', 'compose_file'],
        ['image', 'image'],
        ['installCommand', 'install_command'],
        ['buildCommand', 'build_command'],
        ['startCommand', 'start_command'],
        ['baseDir', 'base_dir'],
        ['releaseCommand', 'release_command'],
        ['postDeployCommand', 'post_deploy_command'],
    ];
    if (now.method !== was.method) out.method = now.method;
    for (const [key, field] of plain) {
        const value = text(now[key] as string);
        if (value !== text(was[key] as string)) out[field] = value;
    }
    if (number(now.port) !== number(was.port)) out.port = number(now.port);
    const health = {
        enabled: now.healthEnabled,
        path: now.healthPath.trim() || '/',
        expected_status: now.healthAny ? 0 : number(now.healthStatus),
        start_period_secs: number(now.startPeriod) ?? 0,
    };
    const healthBefore = {
        enabled: was.healthEnabled,
        path: was.healthPath.trim() || '/',
        expected_status: was.healthAny ? 0 : number(was.healthStatus),
        start_period_secs: number(was.startPeriod) ?? 0,
    };
    if (JSON.stringify(health) !== JSON.stringify(healthBefore)) {
        out.health = now.healthEnabled ? health : { enabled: false };
    }
    const volumes = now.volumes
        .map((volume) => ({
            name: volume.name.trim(),
            path: volume.path.trim(),
        }))
        .filter((volume) => volume.name !== '' || volume.path !== '');
    if (JSON.stringify(volumes) !== JSON.stringify(was.volumes)) {
        out.volumes = volumes;
    }
    if (now.submodules !== was.submodules) out.submodules = now.submodules;
    if (now.lfs !== was.lfs) out.lfs = now.lfs;

    return out;
});

const dirty = computed(() => Object.keys(changes.value).length > 0);

function discard(): void {
    draft.value = draftOf(props.deploy.build);
    errors.value = {};
}

const method = computed(() => draft.value.method);
const isImage = computed(() => props.deploy.source === 'image');
const fromGit = computed(() => props.deploy.source === 'git');
const old = computed(() => !props.deploy.canDeployBuild);
const editable = computed(() => props.canManage && !saving.value);
const oldTitle = computed(() =>
    old.value ? (props.deploy.update ?? undefined) : undefined,
);

const show = computed(() => ({
    dockerfile: method.value === 'dockerfile',
    target: method.value === 'dockerfile',
    composeFile: method.value === 'compose',
    image: method.value === 'image',
    railpack: method.value === 'railpack',
    baseDir: ['railpack', 'dockerfile', 'template'].includes(method.value),
    commands: method.value !== 'compose',
    repository: method.value !== 'image',
}));

const methodOptions = computed<FieldOption<BuildMethod>[]>(() => {
    if (isImage.value) {
        return [{ value: 'image', label: methodLabel('image') }];
    }
    const options: FieldOption<BuildMethod>[] = [
        {
            value: 'railpack',
            label: methodLabel('railpack'),
            hint: old.value
                ? trans('Needs a newer daemon')
                : trans('Works out how to build and start it from its files'),
            disabled: old.value,
        },
        {
            value: 'dockerfile',
            label: methodLabel('dockerfile'),
            hint: trans('Builds the Dockerfile in the repository'),
        },
        {
            value: 'compose',
            label: methodLabel('compose'),
            hint: trans('Runs the Compose file in the repository'),
        },
    ];
    if (props.deploy.build.method === 'template') {
        options.unshift({
            value: 'template',
            label: methodLabel('template'),
            hint: trans('Made for the framework ServerOS found'),
        });
    }

    return options;
});

const healthOptions = computed<FieldOption<string>[]>(() => [
    {
        value: 'any',
        label: trans('Any answer'),
        hint: old.value
            ? trans('Needs a newer daemon')
            : trans('Anything below 500, a redirect or a login page too'),
        disabled: old.value,
    },
    {
        value: 'status',
        label: trans('A status code'),
        hint: trans('Only this HTTP status counts as healthy'),
    },
]);

const portHint = computed(() => {
    const detected = props.deploy.detectedPort;
    if (draft.value.port.trim() === '' && detected) {
        return trans(
            'Empty uses the port the image exposes. That was :port last time.',
            { port: String(detected) },
        );
    }
    if (method.value === 'compose') {
        return trans('The port the Compose file publishes, for the domain.');
    }

    if (draft.value.port.trim() === '' && method.value !== 'railpack') {
        return trans(
            'Empty uses the port the image exposes, or 80. ServerOS sets PORT to it.',
        );
    }

    return trans('The port the app listens on. ServerOS sets PORT to it.');
});

const portPlaceholder = computed(() =>
    props.deploy.detectedPort
        ? String(props.deploy.detectedPort)
        : trans('Auto'),
);

const networkAddress = computed(() => {
    if (!props.deploy.build.network || method.value === 'compose') return null;
    const port = props.deploy.build.port ?? props.deploy.detectedPort;

    return port ? `${props.deploy.service}:${port}` : props.deploy.service;
});

const copied = ref(false);
function copy(value: string): void {
    void navigator.clipboard.writeText(value).then(() => {
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 1500);
    });
}

/* Volumes */
const newVolume = ref({ name: '', path: '' });

function addVolume(): void {
    const name = newVolume.value.name.trim();
    const path = newVolume.value.path.trim();
    if (!name || !path) return;
    draft.value.volumes.push({ name, path });
    newVolume.value = { name: '', path: '' };
}

function volumeError(index: number): string | null {
    return (
        errors.value[`volumes.${index}.name`] ??
        errors.value[`volumes.${index}.path`] ??
        null
    );
}

/* Saving deploys again. */
const what = computed(() => {
    const live = shortSha(props.deploy.liveCommit);
    const app = props.deploy.service;
    if (isImage.value) {
        return trans(
            'Saving pulls :image again and starts :app with these settings. The live version keeps serving until the new one is healthy.',
            { image: props.deploy.build.image ?? '', app },
        );
    }
    if (live) {
        return trans(
            'Saving deploys :app again with these settings, on :sha, the version that’s live. It keeps serving until the new one is healthy.',
            { app, sha: live },
        );
    }

    return trans('Saving deploys :app again with these settings.', { app });
});

const errorSummary = computed(() => {
    const first = Object.values(errors.value)[0];

    return first ?? null;
});

async function save(): Promise<void> {
    if (!dirty.value || saving.value) return;
    saving.value = true;
    errors.value = {};
    try {
        const answer = await sendJson(
            onWorkspace(
                appsRoutes.build({
                    workspace: props.workspaceSlug,
                    machine: props.machineId,
                    service: props.deploy.service,
                }),
            ).url,
            'PUT',
            changes.value,
        );
        if (answer.status === 422) {
            errors.value = fieldErrors(answer.body);

            return;
        }
        if (!answer.ok) {
            toast.error(describe(answer.status, answer.body));

            return;
        }
        const deploy = answer.body.deploy as AppDeploy;
        draft.value = draftOf(deploy.build);
        baseline = JSON.stringify(draft.value);
        if (typeof answer.body.jobId === 'string') {
            emit('deployed', answer.body.jobId, deploy);
        } else {
            toast.info(trans('Nothing changed.'));
        }
    } catch (cause) {
        toast.error(errorMessage(cause));
    } finally {
        saving.value = false;
    }
}

/* The failure banner asks for a setting. */
const targets: Record<string, string> = {
    health: 'build-health',
    port: 'build-port',
    image: 'build-image',
    start_command: 'build-start',
    release_command: 'build-release',
    method: 'build-method',
};

watch(
    () => props.focus?.at,
    async () => {
        if (!props.focus) return;
        const key = props.focus?.key ?? 'method';
        let id = targets[key] ?? 'build-method';
        if (key === 'start_command' && method.value !== 'railpack') {
            id = 'build-method';
        }
        if (key === 'release_command' && !show.value.commands) {
            id = 'build-method';
        }
        await nextTick();
        const element = document.getElementById(id);
        if (!element) return;
        element.scrollIntoView({ block: 'center', behavior: 'smooth' });
        highlight.value = id;
        window.setTimeout(() => {
            if (highlight.value === id) highlight.value = null;
        }, 2400);
        const input =
            element instanceof HTMLInputElement
                ? element
                : element.querySelector<HTMLElement>('input, button');
        input?.focus({ preventScroll: true });
    },
    { immediate: true },
);

function ring(id: string): string {
    return highlight.value === id
        ? 'rounded-[8px] outline-2 outline-offset-2 outline-(--ds-ring)'
        : '';
}

const field = 'dash-field w-[320px] max-w-full';
const mono = 'font-ui-mono text-[12.5px]';
const group =
    'pt-4 pb-1 text-[11px]/3.5 font-medium tracking-[0.08em] text-fg-muted uppercase first:pt-1';
</script>

<template>
    <div class="flex flex-col gap-3">
        <DashTray
            icon="settings-fill"
            :title="$t('Build and run')"
            panel-class="px-[18px] pt-3 pb-1"
        >
            <template #meta>{{ methodLabel(deploy.build.method) }}</template>

            <p
                v-if="deploy.update"
                class="mb-2 rounded-[8px] border-[0.5px] border-edge-tray-panel bg-surface-well px-3 py-2 text-[12.5px]/[18px] text-fg-secondary"
            >
                {{ deploy.update }}
            </p>

            <h3 :class="group">{{ $t('Build') }}</h3>
            <SettingsRow
                :label="$t('Build method')"
                :hint="
                    isImage
                        ? $t(
                              'It runs a prebuilt image. To build code, deploy the repository as a new app.',
                          )
                        : $t(
                              'Switching drops settings only the old method used.',
                          )
                "
                :error="errors.method"
                :stack="narrow"
            >
                <div id="build-method" :class="ring('build-method')">
                    <FieldSelect
                        v-model="draft.method"
                        :options="methodOptions"
                        :label="$t('Build method')"
                        :disabled="!editable || isImage"
                    />
                </div>
            </SettingsRow>
            <SettingsRow
                v-if="show.image"
                :label="$t('Image')"
                :hint="
                    $t(
                        'Like ghcr.io/you/app:1.4. Private ones use the workspace’s registry logins.',
                    )
                "
                label-for="build-image"
                :error="errors.image"
                :stack="narrow"
            >
                <div :class="[field, ring('build-image')]">
                    <input
                        id="build-image"
                        v-model="draft.image"
                        :class="mono"
                        spellcheck="false"
                        autocomplete="off"
                        :disabled="!editable"
                    />
                </div>
            </SettingsRow>
            <SettingsRow
                v-if="show.dockerfile"
                :label="$t('Dockerfile')"
                :hint="$t('Its path in the repository.')"
                label-for="build-dockerfile"
                :error="errors.dockerfile"
                :stack="narrow"
            >
                <div :class="field" :title="oldTitle">
                    <input
                        id="build-dockerfile"
                        v-model="draft.dockerfile"
                        :class="mono"
                        placeholder="Dockerfile"
                        spellcheck="false"
                        autocomplete="off"
                        :disabled="!editable || old"
                    />
                </div>
            </SettingsRow>
            <SettingsRow
                v-if="show.target"
                :label="$t('Build stage')"
                :hint="
                    $t(
                        'For a multi-stage Dockerfile. Empty builds the last stage.',
                    )
                "
                label-for="build-target"
                :error="errors.target"
                :stack="narrow"
            >
                <div :class="field" :title="oldTitle">
                    <input
                        id="build-target"
                        v-model="draft.target"
                        :class="[mono, 'placeholder:font-ui']"
                        :placeholder="$t('The last stage')"
                        spellcheck="false"
                        autocomplete="off"
                        :disabled="!editable || old"
                    />
                </div>
            </SettingsRow>
            <SettingsRow
                v-if="show.composeFile"
                :label="$t('Compose file')"
                :hint="$t('Its path in the repository.')"
                label-for="build-compose"
                :error="errors.compose_file"
                :stack="narrow"
            >
                <div :class="field">
                    <input
                        id="build-compose"
                        v-model="draft.composeFile"
                        :class="mono"
                        placeholder="compose.yaml"
                        spellcheck="false"
                        autocomplete="off"
                        :disabled="!editable"
                    />
                </div>
            </SettingsRow>
            <SettingsRow
                v-if="show.baseDir"
                :label="$t('Folder')"
                :hint="
                    $t('Build from a folder of the repository, for a monorepo.')
                "
                label-for="build-base"
                :error="errors.base_dir"
                :stack="narrow"
            >
                <div :class="field">
                    <input
                        id="build-base"
                        v-model="draft.baseDir"
                        :class="[mono, 'placeholder:font-ui']"
                        :placeholder="$t('The repository’s root')"
                        spellcheck="false"
                        autocomplete="off"
                        :disabled="!editable"
                    />
                </div>
            </SettingsRow>
            <template v-if="show.railpack">
                <SettingsRow
                    :label="$t('Install command')"
                    :hint="$t('Empty lets Railpack work it out.')"
                    label-for="build-install"
                    :error="errors.install_command"
                    :stack="narrow"
                >
                    <div :class="field">
                        <input
                            id="build-install"
                            v-model="draft.installCommand"
                            :class="mono"
                            placeholder="npm ci"
                            spellcheck="false"
                            autocomplete="off"
                            :disabled="!editable"
                        />
                    </div>
                </SettingsRow>
                <SettingsRow
                    :label="$t('Build command')"
                    :hint="$t('Empty lets Railpack work it out.')"
                    label-for="build-build"
                    :error="errors.build_command"
                    :stack="narrow"
                >
                    <div :class="field">
                        <input
                            id="build-build"
                            v-model="draft.buildCommand"
                            :class="mono"
                            placeholder="npm run build"
                            spellcheck="false"
                            autocomplete="off"
                            :disabled="!editable"
                        />
                    </div>
                </SettingsRow>
                <SettingsRow
                    :label="$t('Start command')"
                    :hint="
                        $t(
                            'What runs the app. Empty lets Railpack work it out.',
                        )
                    "
                    label-for="build-start"
                    :error="errors.start_command"
                    :stack="narrow"
                >
                    <div :class="[field, ring('build-start')]">
                        <input
                            id="build-start"
                            v-model="draft.startCommand"
                            :class="mono"
                            placeholder="npm start"
                            spellcheck="false"
                            autocomplete="off"
                            :disabled="!editable"
                        />
                    </div>
                </SettingsRow>
            </template>

            <h3 :class="group">{{ $t('Run') }}</h3>
            <SettingsRow
                :label="$t('Port')"
                :hint="portHint"
                label-for="build-port"
                :error="errors.port"
                :stack="narrow"
            >
                <div :class="['dash-field w-[120px]', ring('build-port')]">
                    <input
                        id="build-port"
                        v-model="draft.port"
                        :class="mono"
                        inputmode="numeric"
                        :placeholder="portPlaceholder"
                        autocomplete="off"
                        :disabled="!editable"
                    />
                </div>
            </SettingsRow>
            <SettingsRow
                v-if="networkAddress"
                :label="$t('Address for other apps')"
                :hint="
                    $t(
                        'Apps on :machine reach it by name on ServerOS’s network, without going through its domain.',
                        { machine: machineName },
                    )
                "
                :stack="narrow"
            >
                <code
                    class="font-ui-mono text-[12.5px]/5 text-fg-primary select-all"
                    >{{ networkAddress }}</code
                >
                <DashButton
                    size="sm"
                    :label="copied ? $t('Copied') : $t('Copy')"
                    @click="copy(networkAddress)"
                />
            </SettingsRow>

            <h3 :class="group">{{ $t('Health check') }}</h3>
            <SettingsRow
                :label="$t('Check it answers before switching over')"
                :hint="
                    draft.healthEnabled
                        ? $t(
                              'A new version only takes over once it answers. Until then the live one keeps serving.',
                          )
                        : $t(
                              'Off: a new version takes over once its container keeps running.',
                          )
                "
                :error="errors['health.enabled'] ?? errors.health"
            >
                <div id="build-health" :class="ring('build-health')">
                    <DashSwitch
                        v-model="draft.healthEnabled"
                        :label="$t('Health check')"
                        :disabled="!editable"
                    />
                </div>
            </SettingsRow>
            <template v-if="draft.healthEnabled">
                <SettingsRow
                    :label="$t('Path')"
                    :hint="$t('ServerOS asks for this page on the app’s port.')"
                    label-for="build-health-path"
                    :error="errors['health.path']"
                    :stack="narrow"
                >
                    <div :class="field">
                        <input
                            id="build-health-path"
                            v-model="draft.healthPath"
                            :class="mono"
                            placeholder="/"
                            spellcheck="false"
                            autocomplete="off"
                            :disabled="!editable"
                        />
                    </div>
                </SettingsRow>
                <SettingsRow
                    :label="$t('Healthy when it gets')"
                    :error="errors['health.expected_status']"
                    :stack="narrow"
                >
                    <FieldSelect
                        :model-value="draft.healthAny ? 'any' : 'status'"
                        :options="healthOptions"
                        :label="$t('Healthy when it gets')"
                        :width="160"
                        :disabled="!editable"
                        @update:model-value="
                            (value) => (draft.healthAny = value === 'any')
                        "
                    />
                    <div v-if="!draft.healthAny" class="dash-field w-[80px]">
                        <input
                            v-model="draft.healthStatus"
                            :class="mono"
                            inputmode="numeric"
                            :aria-label="$t('Status code')"
                            :disabled="!editable"
                        />
                    </div>
                </SettingsRow>
                <SettingsRow
                    :label="$t('Time to start')"
                    :hint="
                        $t(
                            'Seconds a new version gets to boot before failed checks count.',
                        )
                    "
                    label-for="build-health-start"
                    :error="errors['health.start_period_secs']"
                    :stack="narrow"
                >
                    <div class="dash-field w-[120px]" :title="oldTitle">
                        <input
                            id="build-health-start"
                            v-model="draft.startPeriod"
                            :class="mono"
                            inputmode="numeric"
                            :disabled="!editable || old"
                        />
                        <span class="text-[12px] text-fg-muted">{{
                            $t('s')
                        }}</span>
                    </div>
                </SettingsRow>
            </template>

            <template v-if="show.commands">
                <h3 :class="group">{{ $t('Data') }}</h3>
                <SettingsRow
                    :label="$t('Volumes')"
                    :hint="
                        $t(
                            'Folders whose data is kept between deploys, like uploads or a SQLite file.',
                        )
                    "
                    :error="errors.volumes"
                    stack
                >
                    <div class="flex w-full flex-col gap-1.5" :title="oldTitle">
                        <div
                            v-for="(volume, index) in draft.volumes"
                            :key="index"
                            class="flex flex-col gap-1"
                        >
                            <div class="flex items-center gap-2">
                                <div class="dash-field w-[180px]">
                                    <input
                                        v-model="volume.name"
                                        :class="mono"
                                        :aria-label="$t('Volume name')"
                                        spellcheck="false"
                                        autocomplete="off"
                                        :disabled="!editable || old"
                                    />
                                </div>
                                <span class="text-fg-faint" aria-hidden="true"
                                    >→</span
                                >
                                <div class="dash-field min-w-0 flex-1">
                                    <input
                                        v-model="volume.path"
                                        :class="mono"
                                        :aria-label="
                                            $t('Folder in the container')
                                        "
                                        spellcheck="false"
                                        autocomplete="off"
                                        :disabled="!editable || old"
                                    />
                                </div>
                                <button
                                    type="button"
                                    class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-fg-muted hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:cursor-default disabled:opacity-50"
                                    :aria-label="
                                        $t('Remove :name', {
                                            name: volume.name,
                                        })
                                    "
                                    :disabled="!editable"
                                    @click="draft.volumes.splice(index, 1)"
                                >
                                    <X class="size-3.5" :stroke-width="1.75" />
                                </button>
                            </div>
                            <p
                                v-if="volumeError(index)"
                                class="text-[12px]/4 text-status-down"
                            >
                                {{ volumeError(index) }}
                            </p>
                        </div>
                        <form
                            v-if="canManage && draft.volumes.length < 20"
                            class="flex items-center gap-2"
                            @submit.prevent="addVolume"
                        >
                            <div class="dash-field w-[180px]">
                                <input
                                    v-model="newVolume.name"
                                    :class="mono"
                                    placeholder="data"
                                    :aria-label="$t('Volume name')"
                                    spellcheck="false"
                                    autocomplete="off"
                                    :disabled="!editable || old"
                                />
                            </div>
                            <span class="text-fg-faint" aria-hidden="true"
                                >→</span
                            >
                            <div class="dash-field min-w-0 flex-1">
                                <input
                                    v-model="newVolume.path"
                                    :class="mono"
                                    placeholder="/app/storage"
                                    :aria-label="$t('Folder in the container')"
                                    spellcheck="false"
                                    autocomplete="off"
                                    :disabled="!editable || old"
                                />
                            </div>
                            <DashButton
                                size="sm"
                                type="submit"
                                :label="$t('Add')"
                                :disabled="
                                    !editable ||
                                    old ||
                                    !newVolume.name.trim() ||
                                    !newVolume.path.trim()
                                "
                            />
                        </form>
                        <p class="text-[12px]/4 text-fg-muted">
                            {{
                                $t(
                                    'Docker keeps each one as serveros-:app-<name>. Removing one here doesn’t delete its data.',
                                    { app: deploy.service },
                                )
                            }}
                        </p>
                    </div>
                </SettingsRow>

                <h3 :class="group">{{ $t('Commands') }}</h3>
                <SettingsRow
                    :label="$t('Before it serves')"
                    :hint="
                        $t(
                            'Runs once in the new version before it takes over, like migrations. If it fails, the deploy stops and the live version keeps serving.',
                        )
                    "
                    label-for="build-release"
                    :error="errors.release_command"
                    :stack="narrow"
                >
                    <div
                        :class="[field, ring('build-release')]"
                        :title="oldTitle"
                    >
                        <input
                            id="build-release"
                            v-model="draft.releaseCommand"
                            :class="mono"
                            placeholder="php artisan migrate --force"
                            spellcheck="false"
                            autocomplete="off"
                            :disabled="!editable || old"
                        />
                    </div>
                </SettingsRow>
                <SettingsRow
                    :label="$t('After it’s live')"
                    :hint="
                        $t(
                            'Runs in the new container once it serves. If it fails, the log says so and the deploy still counts.',
                        )
                    "
                    label-for="build-post"
                    :error="errors.post_deploy_command"
                    :stack="narrow"
                >
                    <div :class="field" :title="oldTitle">
                        <input
                            id="build-post"
                            v-model="draft.postDeployCommand"
                            :class="mono"
                            placeholder="php artisan optimize"
                            spellcheck="false"
                            autocomplete="off"
                            :disabled="!editable || old"
                        />
                    </div>
                </SettingsRow>
            </template>

            <template v-if="show.repository && fromGit">
                <h3 :class="group">{{ $t('Repository') }}</h3>
                <SettingsRow
                    :label="$t('Submodules')"
                    :hint="$t('Fetch the repository’s submodules too.')"
                    :error="errors.submodules"
                >
                    <DashSwitch
                        v-model="draft.submodules"
                        :label="$t('Submodules')"
                        :disabled="!editable || old"
                    />
                </SettingsRow>
                <SettingsRow
                    :label="$t('Git LFS files')"
                    :hint="$t('Fetch large files kept with Git LFS.')"
                    :error="errors.lfs"
                >
                    <DashSwitch
                        v-model="draft.lfs"
                        :label="$t('Git LFS files')"
                        :disabled="!editable || old"
                    />
                </SettingsRow>
            </template>
        </DashTray>
        <div
            v-if="dirty && canManage"
            class="dash-card sticky bottom-4 z-10 flex flex-wrap items-center gap-x-4 gap-y-2 px-[18px] py-3 shadow-lg"
        >
            <p class="min-w-0 flex-1 text-[12.5px]/[18px] text-fg-secondary">
                <span
                    v-if="errorSummary"
                    class="block text-status-down"
                    role="alert"
                    >{{ errorSummary }}</span
                >
                <template v-else>{{ what }}</template>
            </p>
            <DashButton
                size="sm"
                :label="$t('Discard')"
                :disabled="saving"
                @click="discard"
            />
            <DashButton
                size="sm"
                variant="primary"
                :label="$t('Save and deploy')"
                :loading="saving"
                :disabled="!online || deploy.deploying !== null"
                :title="
                    deploy.deploying
                        ? $t('Wait for the deploy that’s running to finish.')
                        : !online
                          ? $t('The machine is offline.')
                          : undefined
                "
                @click="save"
            />
        </div>
    </div>
</template>
