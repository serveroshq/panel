<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { SelectMenu } from '@/components/ui/select';
import type { SelectMenuOption } from '@/components/ui/select';
import {
    SolarCircleAlert as CircleAlert,
    SolarCircleCheck as CircleCheck,
} from '@/lib/solarIcons';
import type { BuildMethod, RailpackHints } from './buildForm';
import { repositoryLabel } from './catalog';
import { linkButton } from './styles';
import TemplateLogo from './TemplateLogo.vue';

export type ScanDetection = {
    detected: boolean;
    template: string | null;
    framework: string | null;
    type: string | null;
    repo: string;
    branch: string | null;
    commit: string | null;
    baseDir?: string | null;
    reasons: string[];
    php?: string | null;
    composeFile?: string | null;
    composePort?: number | null;
    port?: number | null;
    railpack?: RailpackHints | null;
    envExample?: {
        file: string;
        variables: { name: string; value: string }[];
    } | null;
    needsUpdate?: string | null;
    truncated?: boolean;
    upload?: { filename: string; files: number; expiresAt: string };
};

const importExample = defineModel<boolean>('importExample', {
    default: false,
});

const {
    detection,
    method,
    setupName,
    icon = null,
    port,
    canDeployBuild,
    branchOptions = [],
    busy = false,
    composeFile = null,
} = defineProps<{
    detection: ScanDetection;
    method: BuildMethod;
    /** The chosen setup's name, like Laravel. */
    setupName: string;
    icon?: string | null;
    port: string | number;
    canDeployBuild: boolean;
    /** A GitHub repository's branches; empty for other hosts. */
    branchOptions?: SelectMenuOption<string>[];
    busy?: boolean;
    composeFile?: string | null;
}>();

const emit = defineEmits<{
    rescan: [change: { branch?: string; folder?: string }];
    changeBuild: [];
}>();

const what = computed(() => {
    const framework = detection.framework ?? setupName;
    if (detection.template === 'custom-dockerfile') {
        return trans('An app with a Dockerfile');
    }
    if (detection.template === 'compose-file') {
        return trans('An app with a Compose file');
    }
    if (!detection.detected) return trans('Code ServerOS doesn’t know yet');
    if (detection.type === 'API')
        return trans(':name API', { name: framework });
    if (detection.type === 'Static site') {
        return framework === 'Static site'
            ? trans('A static site')
            : trans(':name site', { name: framework });
    }

    return trans(':name app', { name: framework });
});

const railpackLine = computed(() => {
    const hints = detection.railpack;
    if (!hints) return null;
    const language = [hints.language, hints.version].filter(Boolean).join(' ');

    return [language, hints.packageManager].filter(Boolean).join(', ') || null;
});

const buildLine = computed(() => {
    switch (method) {
        case 'railpack':
            return railpackLine.value
                ? trans('Builds with Railpack: :hints', {
                      hints: railpackLine.value,
                  })
                : trans(
                      'Builds with Railpack, which works it out from the code',
                  );
        case 'dockerfile':
            return trans('Builds its Dockerfile');
        case 'compose':
            return trans('Runs its Compose file, :file', {
                file: composeFile ?? 'compose.yaml',
            });
        case 'image':
            return trans('Runs a prebuilt image');
        default:
            return trans('Builds with ServerOS’s :name setup', {
                name: setupName,
            });
    }
});

const portLine = computed(() => {
    const value = port === '' || port === null ? null : Number(port);
    if (method === 'compose') {
        return value
            ? trans('Port :port, published by its Compose file', {
                  port: String(value),
              })
            : trans('Its Compose file publishes no port, so it has no address');
    }
    if (method === 'dockerfile') {
        if (value && detection.port === value) {
            return trans('Port :port, from its Dockerfile', {
                port: String(value),
            });
        }

        return value
            ? trans('Port :port', { port: String(value) })
            : canDeployBuild
              ? trans('The port its Dockerfile exposes, or 80')
              : trans('Port 80');
    }
    if (method === 'railpack') {
        return trans('Port :port, given to it as PORT', {
            port: String(value ?? 3000),
        });
    }

    return trans('Port :port, set by the :name setup', {
        port: String(value ?? ''),
        name: setupName,
    });
});

const exampleCount = computed(
    () => detection.envExample?.variables.length ?? 0,
);

const commit = computed(() => detection.commit?.slice(0, 7) ?? '');

// Another branch or folder: looks at the repository again.
const editing = ref<'branch' | 'folder' | null>(null);
const branchInput = ref('');
const folderInput = ref('');
const editInput = ref<HTMLInputElement | null>(null);

watch(
    () => [detection.branch, detection.baseDir],
    () => (editing.value = null),
);

function edit(which: 'branch' | 'folder'): void {
    editing.value = which;
    branchInput.value = detection.branch ?? '';
    folderInput.value = detection.baseDir ?? '';
    void nextTick(() => editInput.value?.focus());
}

function apply(): void {
    if (editing.value === 'branch') {
        const branch = branchInput.value.trim();
        if (branch && branch !== detection.branch) emit('rescan', { branch });
        else editing.value = null;
    } else if (editing.value === 'folder') {
        const folder = folderInput.value.trim().replace(/^\/+|\/+$/g, '');
        if (folder !== (detection.baseDir ?? '')) emit('rescan', { folder });
        else editing.value = null;
    }
}

const row =
    'flex min-h-11 items-center gap-2.5 border-t-[0.5px] border-[#ececec] px-3.5 py-2 first:border-t-0 dark:border-[#262626]';
const text = 'min-w-0 flex-1 text-[13px]/5 tracking-[-0.005em]';
const inline =
    'h-7 w-40 min-w-0 rounded-[6px] border-[0.5px] border-edge-field bg-surface-field px-2 font-ui-mono text-[12px]/5 text-fg-primary outline-none focus:border-edge-checkbox-hover';
</script>

<template>
    <section
        class="overflow-clip rounded-[10px] border-[0.5px] border-[#e2e2e2] bg-white shadow-[0_1px_2px_rgb(0_0_0/0.05)] dark:border-[#2a2a2a] dark:bg-[#1a1a1a] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.04),0_1px_1.5px_rgb(0_0_0/0.5)]"
        :aria-label="$t('What ServerOS found')"
        :aria-busy="busy || undefined"
    >
        <!-- What it is -->
        <div :class="row">
            <span
                class="flex size-[26px] shrink-0 items-center justify-center rounded-[6px] bg-[#f2f2f2] shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] dark:bg-[#262626] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)]"
            >
                <TemplateLogo :icon="icon ?? 'code'" :size="15" />
            </span>
            <span :class="[text, 'font-medium text-fg-primary']">
                {{ what }}
                <span v-if="detection.php" class="font-normal text-fg-muted">{{
                    ' · PHP ' + detection.php
                }}</span>
            </span>
            <span
                class="hidden max-w-[45%] truncate font-ui-mono text-[12px] text-fg-muted sm:block"
                :title="detection.repo"
                >{{
                    detection.upload
                        ? detection.upload.filename
                        : repositoryLabel(detection.repo)
                }}</span
            >
        </div>

        <!-- How it's built -->
        <div :class="row">
            <CircleCheck
                class="size-4 shrink-0 text-status-live"
                :stroke-width="2"
                aria-hidden="true"
            />
            <span :class="[text, 'text-fg-secondary']">{{ buildLine }}</span>
            <button
                type="button"
                :class="linkButton"
                @click="emit('changeBuild')"
            >
                {{ $t('Change') }}
            </button>
        </div>

        <!-- Port -->
        <div :class="row">
            <CircleCheck
                v-if="method !== 'compose' || port"
                class="size-4 shrink-0 text-status-live"
                :stroke-width="2"
                aria-hidden="true"
            />
            <CircleAlert
                v-else
                class="size-4 shrink-0 text-status-pending"
                :stroke-width="2"
                aria-hidden="true"
            />
            <span :class="[text, 'text-fg-secondary']">{{ portLine }}</span>
        </div>

        <!-- Example settings -->
        <div v-if="exampleCount" :class="row">
            <CircleCheck
                class="size-4 shrink-0 text-status-live"
                :stroke-width="2"
                aria-hidden="true"
            />
            <label
                for="deploy-import-example"
                :class="[text, 'cursor-pointer text-fg-secondary']"
            >
                {{
                    $t(':file has :count settings. Import them?', {
                        file: detection.envExample?.file ?? '.env.example',
                        count: String(exampleCount),
                    })
                }}
            </label>
            <DashSwitch
                id="deploy-import-example"
                v-model="importExample"
                size="sm"
                :label="$t('Import the example settings')"
            />
        </div>

        <!-- Branch and commit -->
        <div :class="row">
            <FilledIcon
                :name="detection.upload ? 'zip-fill' : 'branch-fill'"
                :size="15"
                class="ml-px shrink-0 text-fg-muted"
            />
            <template v-if="detection.upload">
                <span :class="[text, 'text-fg-secondary']">{{
                    $t(':count files, uploaded', {
                        count: String(detection.upload.files),
                    })
                }}</span>
            </template>
            <template v-else-if="editing === 'branch'">
                <span :class="[text, 'text-fg-secondary']">{{
                    $t('Branch or tag')
                }}</span>
                <input
                    ref="editInput"
                    v-model="branchInput"
                    :class="inline"
                    :aria-label="$t('Branch or tag')"
                    autocomplete="off"
                    spellcheck="false"
                    @keydown.enter.prevent="apply"
                    @keydown.esc.stop.prevent="editing = null"
                />
                <DashButton
                    size="sm"
                    :loading="busy"
                    :label="$t('Look again')"
                    @click="apply"
                />
            </template>
            <template v-else>
                <span :class="[text, 'text-fg-secondary']">
                    <span class="font-ui-mono text-[12.5px] text-fg-primary">{{
                        detection.branch ?? $t('default branch')
                    }}</span>
                    <span
                        v-if="commit"
                        class="font-ui-mono text-[12px] text-fg-muted"
                        >{{ ' · ' + commit }}</span
                    >
                </span>
                <SelectMenu
                    v-if="branchOptions.length > 1"
                    :model-value="detection.branch"
                    :options="branchOptions"
                    :disabled="busy"
                    align="end"
                    class="dash-field h-7! w-auto max-w-44 cursor-pointer gap-1.5 pr-2 text-[12.5px] outline-none"
                    :aria-label="$t('Branch')"
                    @update:model-value="(branch) => emit('rescan', { branch })"
                />
                <button
                    v-else
                    type="button"
                    :class="linkButton"
                    :disabled="busy"
                    @click="edit('branch')"
                >
                    {{ $t('Change') }}
                </button>
            </template>
        </div>

        <!-- Folder, for an app in a monorepo -->
        <div v-if="!detection.upload" :class="row">
            <FilledIcon
                name="folder-fill"
                :size="15"
                class="ml-px shrink-0 text-fg-muted"
            />
            <template v-if="editing === 'folder'">
                <span :class="[text, 'text-fg-secondary']">{{
                    $t('Folder (empty for the whole repository)')
                }}</span>
                <input
                    ref="editInput"
                    v-model="folderInput"
                    :class="inline"
                    placeholder="apps/web"
                    :aria-label="$t('Folder')"
                    autocomplete="off"
                    spellcheck="false"
                    @keydown.enter.prevent="apply"
                    @keydown.esc.stop.prevent="editing = null"
                />
                <DashButton
                    size="sm"
                    :loading="busy"
                    :label="$t('Look again')"
                    @click="apply"
                />
            </template>
            <template v-else>
                <span :class="[text, 'text-fg-secondary']">
                    <template v-if="detection.baseDir">
                        {{ $t('Folder') + ' ' }}
                        <span
                            class="font-ui-mono text-[12.5px] text-fg-primary"
                            >{{ detection.baseDir }}</span
                        >
                    </template>
                    <template v-else>{{ $t('The whole repository') }}</template>
                </span>
                <button
                    type="button"
                    :class="linkButton"
                    :disabled="busy"
                    @click="edit('folder')"
                >
                    {{ $t('Change') }}
                </button>
            </template>
        </div>

        <div
            v-if="
                detection.reasons.length ||
                detection.needsUpdate ||
                detection.truncated
            "
            class="flex flex-col gap-1 border-t-[0.5px] border-[#ececec] bg-[#fafafa] px-3.5 py-2.5 text-[12px]/4 tracking-[-0.005em] text-fg-muted dark:border-[#262626] dark:bg-[#161616]"
        >
            <p v-if="detection.needsUpdate" class="text-status-pending">
                {{ detection.needsUpdate }}
            </p>
            <p v-if="detection.reasons.length">
                {{ detection.reasons.join(' ') }}
            </p>
            <p v-if="detection.truncated">
                {{
                    $t(
                        'It’s a big repository, so only its first 2,000 files were read.',
                    )
                }}
            </p>
        </div>
    </section>
</template>
