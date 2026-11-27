<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import type { DeployJob } from '@/components/apps/appTypes';
import {
    isFailed,
    isFinished,
    rowStatus,
    seconds,
    sendJson,
    shortSha,
    triggerLabel,
} from '@/components/apps/appTypes';
import DashButton from '@/components/dash/DashButton.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { describe, errorMessage } from '@/lib/errors';
import { jobStatus } from '@/lib/jobStatus';
import { onWorkspace } from '@/lib/workspaceRoute';
import appsRoutes from '@/routes/workspace/apps';
import machinesRoutes from '@/routes/workspace/machines';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    workspaceSlug: string;
    machineId: number;
    service: string;
    jobId: string | null;
    canManage: boolean;
    /** Shown before the job loads, and for scheduled runs: the command. */
    title?: string;
}>();

const emit = defineEmits<{ finished: [job: DeployJob] }>();

const job = ref<DeployJob | null>(null);
const error = ref<string | null>(null);
const stopping = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let asked: string | null = null;
const logBox = useTemplateRef<HTMLElement>('logBox');
let following = true;

const jobUrl = (id: string): string =>
    onWorkspace(
        appsRoutes.job({
            workspace: props.workspaceSlug,
            machine: props.machineId,
            service: props.service,
            job: id,
        }),
    ).url;

async function load(id: string): Promise<void> {
    clearTimeout(timer);
    try {
        const answer = await sendJson(jobUrl(id), 'GET');
        if (asked !== id) return;
        if (!answer.ok) {
            error.value = describe(answer.status, answer.body);

            return;
        }
        const before = job.value?.id === id ? job.value.state : null;
        job.value = answer.body as unknown as DeployJob;
        error.value = null;
        if (!isFinished(job.value.state)) {
            timer = setTimeout(() => load(id), 2000);
        } else if (before !== null && !isFinished(before)) {
            emit('finished', job.value);
        }
    } catch (cause) {
        if (asked !== id) return;
        error.value = errorMessage(cause);
        timer = setTimeout(() => load(id), 4000);
    }
}

watch(
    () => [open.value, props.jobId] as const,
    ([isOpen, id]) => {
        clearTimeout(timer);
        if (!isOpen || !id) {
            asked = null;

            return;
        }
        if (asked !== id) {
            job.value = null;
            error.value = null;
            following = true;
        }
        asked = id;
        void load(id);
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(timer));

const exec = computed(() => job.value?.result !== null && !!job.value?.result);
const badge = computed(() => {
    if (!job.value) return null;
    if (['queued', 'running'].includes(job.value.state)) {
        return {
            status: 'deploying' as const,
            label: jobStatus(
                job.value.state,
                job.value.phase,
                job.value.progress,
            ).label,
        };
    }

    return rowStatus(job.value);
});

const heading = computed(() => {
    const current = job.value;
    if (!current) return props.title ?? trans('Deploy');
    if (exec.value) return props.title ?? trans('Scheduled run');
    const sha = shortSha(current.commit);
    if (current.kind === 'rollback') {
        return sha ? trans('Rollback to :sha', { sha }) : trans('Rollback');
    }
    if (current.image)
        return trans('Deploy of :image', { image: current.image });

    return sha ? trans('Deploy of :sha', { sha }) : trans('Deploy');
});

const meta = computed(() => {
    const current = job.value;
    if (!current) return '';

    return [
        exec.value ? null : triggerLabel(current.trigger, current.kind),
        current.by,
        current.createdAt ? new Date(current.createdAt).toLocaleString() : null,
        current.durationSecs !== null ? seconds(current.durationSecs) : null,
        current.noCache ? trans('Built without cache') : null,
    ]
        .filter(Boolean)
        .join(' · ');
});

const output = computed(() => {
    const current = job.value;
    if (!current) return [];
    const lines = [...(current.log ?? [])];
    const result = current.result ?? {};
    for (const key of ['stdout', 'stderr']) {
        const text = result[key];
        if (typeof text === 'string' && text.trim() !== '') {
            lines.push(...text.replace(/\n$/, '').split('\n'));
        }
    }
    const tail = current.error?.output_tail ?? [];
    for (const line of tail) {
        if (!lines.includes(line)) lines.push(line);
    }

    return lines;
});

const exitCode = computed(() => {
    const code = job.value?.result?.exit_code;

    return typeof code === 'number' ? code : null;
});

function onScroll(): void {
    const box = logBox.value;
    if (box) {
        following = box.scrollHeight - box.scrollTop - box.clientHeight < 24;
    }
}

watch(
    () => output.value.length,
    () => {
        if (!following) return;
        void nextTick(() => {
            if (logBox.value)
                logBox.value.scrollTop = logBox.value.scrollHeight;
        });
    },
);

const canStop = computed(
    () =>
        props.canManage &&
        !!job.value &&
        (job.value.state === 'stale' ||
            ['queued', 'running'].includes(job.value.state)),
);

async function stop(): Promise<void> {
    const current = job.value;
    if (!current || stopping.value) return;
    stopping.value = true;
    try {
        const answer = await sendJson(
            onWorkspace(
                machinesRoutes.jobs.abandon({
                    workspace: props.workspaceSlug,
                    machine: props.machineId,
                    job: current.id,
                }),
            ).url,
            'POST',
        );
        if (!answer.ok) {
            error.value = describe(answer.status, answer.body);

            return;
        }
        await load(current.id);
        if (job.value && isFinished(job.value.state)) {
            emit('finished', job.value);
        }
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        stopping.value = false;
    }
}

const copied = ref(false);
function copy(text: string): void {
    void navigator.clipboard.writeText(text).then(() => {
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 1500);
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal flex max-h-[85svh] flex-col font-ui text-foreground sm:max-w-3xl"
        >
            <DialogHeader class="min-w-0 pr-6">
                <DialogTitle class="flex min-w-0 items-center gap-2.5">
                    <span class="truncate">{{ heading }}</span>
                    <StatusBadge
                        v-if="badge"
                        :status="badge.status"
                        :label="badge.label"
                    />
                </DialogTitle>
                <DialogDescription class="truncate">
                    {{ meta || $t('Loading…') }}
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="job?.explanation && isFailed(job.state)"
                class="flex flex-col gap-1 rounded-[10px] border-[0.5px] border-edge-tray-panel bg-surface-tray-panel px-3.5 py-3 text-[13px]/5"
            >
                <p class="text-fg-primary">{{ job.explanation.cause }}</p>
                <p class="text-fg-secondary">{{ job.explanation.fix }}</p>
                <div
                    v-if="job.explanation.command"
                    class="mt-1 flex min-w-0 items-center gap-2"
                >
                    <code
                        class="min-w-0 flex-1 truncate rounded-[6px] bg-surface-well px-2 py-1 font-ui-mono text-[12px]/4 text-fg-primary"
                        >{{ job.explanation.command }}</code
                    >
                    <DashButton
                        size="sm"
                        :label="copied ? $t('Copied') : $t('Copy')"
                        @click="copy(job.explanation.command)"
                    />
                </div>
            </div>
            <p
                v-else-if="job?.message && isFailed(job.state)"
                class="text-[13px]/5 text-fg-secondary"
            >
                {{ job.message }}
            </p>
            <p
                v-else-if="job?.state === 'stale'"
                class="text-[13px]/5 text-fg-secondary"
            >
                {{
                    $t(
                        'The machine hasn’t said anything about this for longer than it’s allowed. Stop waiting to cancel it on the machine.',
                    )
                }}
            </p>

            <div
                ref="logBox"
                class="min-h-40 grow overflow-auto rounded-[10px] border-[0.5px] border-edge-tray-panel bg-surface-well px-3.5 py-3 font-ui-mono text-[12px]/5 whitespace-pre-wrap text-fg-secondary"
                role="log"
                :aria-label="$t('Log')"
                @scroll="onScroll"
            >
                <template v-if="output.length">
                    <div
                        v-for="(line, index) in output"
                        :key="index"
                        class="break-all"
                    >
                        {{ line }}
                    </div>
                </template>
                <p v-else-if="job" class="text-fg-faint">
                    {{
                        isFinished(job.state)
                            ? $t('The machine sent no log for this one.')
                            : $t('Waiting for the machine to start it…')
                    }}
                </p>
                <p v-else-if="!error" class="text-fg-faint">
                    {{ $t('Loading…') }}
                </p>
                <p v-if="exitCode !== null" class="mt-2 text-fg-faint">
                    {{ $t('Exit code :code', { code: String(exitCode) }) }}
                </p>
            </div>

            <p v-if="error" class="text-[13px]/5 text-status-down" role="alert">
                {{ error }}
            </p>

            <div class="flex items-center justify-end gap-2">
                <DashButton
                    v-if="canStop"
                    size="sm"
                    :label="$t('Stop waiting')"
                    :loading="stopping"
                    :title="
                        $t(
                            'ServerOS asks the machine to cancel it, and stops waiting for it.',
                        )
                    "
                    @click="stop"
                />
                <DashButton
                    size="sm"
                    :label="$t('Close')"
                    @click="open = false"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
