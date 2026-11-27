<script setup lang="ts">
import { Check } from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import JobStatusBadge from '@/components/machines/JobStatusBadge.vue';
import { describe, errorMessage } from '@/lib/errors';
import { SolarCopy as Copy } from '@/lib/solarIcons';
import { onWorkspace } from '@/lib/workspaceRoute';
import appsRoutes from '@/routes/workspace/apps';
import machinesRoutes from '@/routes/workspace/machines';
import DockerInstall from './DockerInstall.vue';
import { iconButton } from './styles';

export type Explanation = {
    cause: string;
    fix: string;
    command?: string;
    alternative?: string;
    docker?: true;
    cleanup?: true;
    settings?:
        | 'health'
        | 'port'
        | 'image'
        | 'start_command'
        | 'release_command';
};

export type FollowedJob = {
    id: string;
    state: string;
    phase: string | null;
    progress: number | null;
    commit: string | null;
    image: string | null;
    message: string | null;
    retryable: boolean;
    createdAt: string | null;
    finishedAt: string | null;
    durationSecs: number | null;
    log: string[];
    error: { message?: string; next_step?: string } | null;
    explanation: Explanation | null;
};

const FINISHED = ['succeeded', 'failed', 'timed_out', 'cancelled', 'refused'];

const {
    machineId,
    machineName,
    service,
    jobId,
    url = null,
} = defineProps<{
    machineId: number;
    machineName: string;
    service: string;
    jobId: string;
    /** Where it answers once it's live. */
    url?: string | null;
}>();

const emit = defineEmits<{
    update: [job: FollowedJob];
    retried: [jobId: string];
}>();

const page = usePage<{ workspace: { slug: string } }>();
const job = ref<FollowedJob | null>(null);
const loadError = ref<string | null>(null);
const following = ref(jobId);
let timer: ReturnType<typeof setTimeout> | undefined;
let alive = true;

const finished = computed(() =>
    job.value ? FINISHED.includes(job.value.state) : false,
);
const failed = computed(
    () => finished.value && job.value?.state !== 'succeeded',
);

async function load(): Promise<void> {
    const id = following.value;
    try {
        const response = await fetch(
            onWorkspace(
                appsRoutes.job({
                    workspace: page.props.workspace.slug,
                    machine: machineId,
                    service,
                    job: id,
                }),
            ).url,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            },
        );
        if (!alive || id !== following.value) return;
        const body = await response.json().catch(() => ({}));
        if (response.ok) {
            job.value = body as FollowedJob;
            loadError.value = null;
            emit('update', job.value);
        } else {
            loadError.value = describe(response.status, body);
        }
    } catch (cause) {
        if (alive) loadError.value = errorMessage(cause);
    }
    if (alive && id === following.value && !finished.value) {
        timer = setTimeout(load, 2000);
    }
}

watch(
    () => jobId,
    (next) => {
        clearTimeout(timer);
        following.value = next;
        job.value = null;
        void load();
    },
    { immediate: true },
);

onBeforeUnmount(() => {
    alive = false;
    clearTimeout(timer);
});

const logBox = useTemplateRef<HTMLElement>('logBox');
let stuck = true;

function onLogScroll(): void {
    const box = logBox.value;
    if (box) stuck = box.scrollHeight - box.scrollTop - box.clientHeight < 24;
}

watch(
    () => job.value?.log.length,
    () => {
        if (!stuck) return;
        void nextTick(() => {
            if (logBox.value)
                logBox.value.scrollTop = logBox.value.scrollHeight;
        });
    },
);

const settingNames: Record<NonNullable<Explanation['settings']>, string> = {
    health: trans('health check'),
    port: trans('port'),
    image: trans('image'),
    start_command: trans('start command'),
    release_command: trans('command before it goes live'),
};

const copied = ref(false);

async function copyCommand(command: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(command);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1600);
    } catch {}
}

const retrying = ref(false);
const retryError = ref<string | null>(null);

async function retry(): Promise<void> {
    if (!job.value || retrying.value) return;
    retrying.value = true;
    retryError.value = null;
    try {
        const response = await fetch(
            onWorkspace(
                machinesRoutes.jobs.retry({
                    workspace: page.props.workspace.slug,
                    machine: machineId,
                    job: job.value.id,
                }),
            ).url,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content ?? '',
                },
            },
        );
        const body = await response.json().catch(() => ({}));
        if (response.ok && body.jobId) {
            emit('retried', body.jobId);
        } else {
            retryError.value = describe(response.status, body);
        }
    } catch (cause) {
        retryError.value = errorMessage(cause);
    } finally {
        retrying.value = false;
    }
}

function took(seconds: number): string {
    const minutes = Math.floor(seconds / 60);

    return minutes > 0 ? `${minutes}m ${seconds % 60}s` : `${seconds}s`;
}

defineExpose({ finished, failed });
</script>

<template>
    <div class="flex flex-col gap-3 text-[13px]/5">
        <div class="flex flex-wrap items-center gap-2.5">
            <JobStatusBadge
                v-if="job"
                :state="job.state"
                :phase="job.phase"
                :progress="job.progress"
            />
            <span v-else class="text-fg-muted">{{
                $t('Asking :machine…', { machine: machineName })
            }}</span>
            <span
                v-if="job?.commit"
                class="font-ui-mono text-[12px] text-fg-muted"
                >{{ job.commit.slice(0, 7) }}</span
            >
            <span
                v-else-if="job?.image"
                class="truncate font-ui-mono text-[12px] text-fg-muted"
                >{{ job.image }}</span
            >
            <span
                v-if="
                    job?.durationSecs !== null &&
                    job?.durationSecs !== undefined
                "
                class="ml-auto text-[12px] text-fg-faint tabular-nums"
                >{{ took(job.durationSecs) }}</span
            >
        </div>

        <div
            v-if="job && !finished && job.progress !== null"
            class="h-1 overflow-hidden rounded-full bg-black/6 dark:bg-white/8"
            role="progressbar"
            :aria-valuenow="job.progress"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <div
                class="h-full rounded-full bg-dash-blue transition-[width] duration-500 ease-out"
                :style="{ width: `${job.progress}%` }"
            />
        </div>

        <p v-if="job?.state === 'stale'" class="text-fg-secondary">
            {{
                $t(
                    'No news from :machine for a while. It may have restarted, or never got this deploy. Its page has the latest.',
                    { machine: machineName },
                )
            }}
        </p>

        <!-- Live -->
        <div
            v-if="job?.state === 'succeeded'"
            class="flex flex-col gap-1 rounded-[10px] border-[0.5px] border-status-live/30 bg-status-live/8 px-3.5 py-3"
        >
            <p class="font-medium text-fg-primary">
                {{ $t(':service is live.', { service }) }}
            </p>
            <p v-if="url" class="text-fg-secondary">
                {{ $t('It answers at') + ' ' }}
                <a
                    :href="url"
                    target="_blank"
                    rel="noopener"
                    class="font-ui-mono text-[12.5px] text-dash-blue hover:underline hover:underline-offset-4"
                    >{{ url.replace(/^https?:\/\//, '') }}</a
                >
            </p>
        </div>

        <!-- Failed: what went wrong, in plain words -->
        <div
            v-if="failed && job"
            class="flex flex-col gap-2 rounded-[10px] border-[0.5px] border-status-down/30 bg-status-down/6 px-3.5 py-3"
        >
            <p class="font-medium text-status-down">
                {{
                    job.explanation?.cause ??
                    job.message ??
                    (job.state === 'cancelled'
                        ? $t('The deploy was stopped.')
                        : $t('The deploy didn’t finish.'))
                }}
            </p>
            <template v-if="job.explanation">
                <p class="text-fg-secondary">{{ job.explanation.fix }}</p>
                <div
                    v-if="job.explanation.command"
                    class="dash-field h-auto! py-1 pr-1"
                >
                    <code
                        class="min-w-0 flex-1 truncate font-ui-mono text-[12px]/5 text-fg-primary select-all"
                        >{{ job.explanation.command }}</code
                    >
                    <button
                        type="button"
                        :class="iconButton"
                        :aria-label="$t('Copy the command')"
                        @click="copyCommand(job.explanation.command)"
                    >
                        <Check
                            v-if="copied"
                            class="mi-pop size-3.5 text-status-live"
                            :stroke-width="2.25"
                        />
                        <Copy
                            v-else
                            class="mi-copy size-3.5"
                            :stroke-width="1.75"
                        />
                    </button>
                </div>
                <p
                    v-if="job.explanation.alternative"
                    class="text-[12px]/4 text-fg-muted"
                >
                    {{ job.explanation.alternative }}
                </p>
                <p
                    v-if="job.explanation.settings"
                    class="text-[12px]/4 text-fg-muted"
                >
                    {{
                        $t(
                            'Change its :setting on its page, then deploy again.',
                            { setting: settingNames[job.explanation.settings] },
                        )
                    }}
                </p>
                <p
                    v-if="job.message && job.message !== job.explanation.cause"
                    class="font-ui-mono text-[11.5px]/4 break-words text-fg-muted"
                >
                    {{ job.message }}
                </p>
            </template>
            <p v-else-if="job.error?.next_step" class="text-fg-secondary">
                {{ job.error.next_step }}
            </p>
            <DockerInstall
                v-if="job.explanation?.docker"
                :machine-id="machineId"
                :machine-name="machineName"
                @installed="retry"
            />
            <div
                v-if="job.retryable && !job.explanation?.docker"
                class="flex items-center gap-2"
            >
                <DashButton
                    size="sm"
                    :loading="retrying"
                    :label="retrying ? $t('Queuing…') : $t('Try again')"
                    @click="retry"
                />
            </div>
            <p v-if="retryError" class="text-[12px]/4 text-status-down">
                {{ retryError }}
            </p>
        </div>

        <p v-if="loadError" class="text-[12px]/4 text-status-down" role="alert">
            {{ loadError }}
        </p>

        <div
            ref="logBox"
            class="max-h-[300px] min-h-28 overflow-y-auto rounded-[10px] border-[0.5px] border-[#e2e2e2] bg-[#fafafa] py-2.5 font-ui-mono text-[11.5px]/5 dark:border-[#2a2a2a] dark:bg-[#101010]"
            role="log"
            :aria-label="$t('Deploy log')"
            aria-live="polite"
            @scroll="onLogScroll"
        >
            <p v-if="!job || job.log.length === 0" class="px-3.5 text-fg-faint">
                {{
                    finished
                        ? $t('This deploy didn’t log anything.')
                        : $t('Waiting for :machine to start…', {
                              machine: machineName,
                          })
                }}
            </p>
            <div
                v-for="(line, index) in job?.log ?? []"
                :key="index"
                class="flex gap-3 px-3.5 hover:bg-black/3 dark:hover:bg-white/3"
            >
                <span
                    class="w-7 shrink-0 text-right text-fg-faint select-none"
                    >{{ index + 1 }}</span
                >
                <span
                    class="min-w-0 break-words whitespace-pre-wrap text-fg-secondary"
                    >{{ line }}</span
                >
            </div>
        </div>
    </div>
</template>
