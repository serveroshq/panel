<script setup lang="ts">
import { CircleX } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type {
    AppDeploy,
    DeployJob,
    DeployRow,
    SettingsKey,
} from '@/components/apps/appTypes';
import {
    isFinished,
    redeployApp,
    sendJson,
    shortSha,
} from '@/components/apps/appTypes';
import DashButton from '@/components/dash/DashButton.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import { describe, errorMessage } from '@/lib/errors';
import { jobStatus } from '@/lib/jobStatus';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import appsRoutes from '@/routes/workspace/apps';
import machinesRoutes from '@/routes/workspace/machines';

const props = defineProps<{
    deploy: AppDeploy;
    /** A deploy this page just started, followed even before the app says so. */
    started?: string | null;
    workspaceSlug: string;
    machineId: number;
    machineName: string;
    missing: boolean;
    canManage: boolean;
    online: boolean;
}>();

const emit = defineEmits<{
    settings: [key: SettingsKey | null];
    log: [jobId: string];
    deployed: [jobId: string, deploy: AppDeploy];
    finished: [job: DeployJob];
}>();

const args = computed(() => ({
    workspace: props.workspaceSlug,
    machine: props.machineId,
    service: props.deploy.service,
}));

/* Following the deploy the machine is working on, every 2 seconds. */
const followed = ref<string | null>(null);
const live = ref<DeployJob | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

async function poll(id: string): Promise<void> {
    clearTimeout(timer);
    try {
        const answer = await sendJson(
            onWorkspace(appsRoutes.job({ ...args.value, job: id })).url,
            'GET',
        );
        if (followed.value !== id) return;
        if (answer.ok) {
            live.value = answer.body as unknown as DeployJob;
            if (isFinished(live.value.state)) {
                emit('finished', live.value);

                return;
            }
        }
    } catch {
        // Try again on the next tick.
    }
    if (followed.value === id) timer = setTimeout(() => poll(id), 2000);
}

function follow(id: string): void {
    if (followed.value === id) return;
    followed.value = id;
    live.value = null;
    void poll(id);
}

watch(
    () => [props.deploy.deploying?.id, props.started] as const,
    ([deploying, started], before) => {
        if (started && started !== before?.[1]) follow(started);
        else if (deploying) follow(deploying);
    },
    { immediate: true },
);

onBeforeUnmount(() => clearTimeout(timer));

const running = computed<DeployRow | DeployJob | null>(() => {
    if (live.value && !isFinished(live.value.state)) return live.value;
    if (
        props.deploy.deploying &&
        (!live.value || live.value.id !== props.deploy.deploying.id)
    ) {
        return props.deploy.deploying;
    }

    return null;
});

const runningLabel = computed(() => {
    const row = running.value;
    if (!row) return '';
    if (row.state === 'stale') return trans('No answer');

    return jobStatus(row.state, row.phase, row.progress).label;
});

const runningTitle = computed(() => {
    const row = running.value;
    if (!row) return '';
    const sha = shortSha(row.commit);
    if (row.kind === 'rollback') {
        return sha
            ? trans('Rolling back to :sha', { sha })
            : trans('Rolling back');
    }
    if (row.image) return trans('Pulling :image', { image: row.image });
    if (sha && props.deploy.branch && row.trigger !== 'dashboard') {
        return trans('Deploying :sha from :branch', {
            sha,
            branch: props.deploy.branch,
        });
    }

    return sha ? trans('Deploying :sha', { sha }) : trans('Deploying');
});

const runningMeta = computed(() => {
    const row = running.value;
    if (!row) return '';

    return [
        row.by ? trans('by :name', { name: row.by }) : null,
        row.createdAt ? timeAgo(row.createdAt) : null,
        row.noCache ? trans('without cache') : null,
    ]
        .filter(Boolean)
        .join(' · ');
});

const tail = computed(() => (live.value?.log ?? []).slice(-8));

/* The last deploy failed. */
const failure = computed(() =>
    running.value ? null : props.deploy.lastFailure,
);

const settingsLabels: Record<string, string> = {
    health: trans('Change the health check'),
    port: trans('Change the port'),
    image: trans('Change the image'),
    start_command: trans('Change the start command'),
    release_command: trans('Change the release command'),
};

const failureTitle = computed(() => {
    if (failure.value?.kind === 'rollback') {
        return trans('The last rollback didn’t work');
    }

    return props.missing
        ? trans('The first deploy didn’t work')
        : trans('The last deploy didn’t work');
});

const cause = computed(
    () =>
        failure.value?.explanation?.cause ??
        failure.value?.message ??
        trans('The machine didn’t say why.'),
);
const fix = computed(
    () => failure.value?.explanation?.fix ?? failure.value?.nextStep ?? null,
);
const keyLine = computed(() => {
    const line = failure.value?.keyLine;
    if (!line || line === cause.value || line === failure.value?.message) {
        return null;
    }

    return line;
});

const copied = ref(false);
function copy(text: string): void {
    void navigator.clipboard.writeText(text).then(() => {
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 1500);
    });
}

const redeploying = ref(false);

async function deployAgain(): Promise<void> {
    if (redeploying.value) return;
    redeploying.value = true;
    const commit = props.deploy.commit;
    const body =
        props.deploy.source === 'git' && commit && /^[0-9a-f]{40}$/.test(commit)
            ? { commit }
            : {};
    try {
        const answer = await redeployApp(
            onWorkspace(appsRoutes.redeploy(args.value)).url,
            body,
        );
        if (answer.ok) {
            emit('deployed', answer.jobId, answer.deploy);
        } else {
            toast.error(describe(answer.status, answer.body));
        }
    } catch (cause) {
        toast.error(errorMessage(cause));
    } finally {
        redeploying.value = false;
    }
}

const stopping = ref(false);
async function stopWaiting(): Promise<void> {
    const row = running.value;
    if (!row || stopping.value) return;
    stopping.value = true;
    try {
        const answer = await sendJson(
            onWorkspace(
                machinesRoutes.jobs.abandon({
                    workspace: props.workspaceSlug,
                    machine: props.machineId,
                    job: row.id,
                }),
            ).url,
            'POST',
        );
        if (!answer.ok) toast.error(describe(answer.status, answer.body));
        else void poll(row.id);
    } catch (cause) {
        toast.error(errorMessage(cause));
    } finally {
        stopping.value = false;
    }
}

/* Docker missing on a newer daemon: install it from here. */
const dockerAsk = ref(false);
const dockerJob = ref<{ id: string; finished: boolean } | null>(null);
let dockerTimer: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => clearTimeout(dockerTimer));

async function installDocker(): Promise<void> {
    dockerAsk.value = false;
    const machineArgs = {
        workspace: props.workspaceSlug,
        machine: props.machineId,
    };
    try {
        const answer = await sendJson(
            onWorkspace(machinesRoutes.docker.install(machineArgs)).url,
            'POST',
            { confirm: true },
        );
        if (!answer.ok || typeof answer.body.jobId !== 'string') {
            toast.error(describe(answer.status, answer.body));

            return;
        }
        const id = answer.body.jobId;
        dockerJob.value = { id, finished: false };
        const toastId = toast.loading(
            trans('Installing Docker on :machine…', {
                machine: props.machineName,
            }),
        );
        const check = async (): Promise<void> => {
            const state = await sendJson(
                onWorkspace(
                    machinesRoutes.docker.show({ ...machineArgs, job: id }),
                ).url,
                'GET',
            ).catch(() => null);
            if (state?.ok && state.body.finished === true) {
                dockerJob.value = { id, finished: true };
                if (state.body.installed === true) {
                    toast.success(trans('Docker is installed. Deploy again.'), {
                        id: toastId,
                    });
                } else {
                    toast.error(
                        typeof state.body.error === 'string'
                            ? state.body.error
                            : trans('Docker couldn’t be installed.'),
                        { id: toastId },
                    );
                }

                return;
            }
            dockerTimer = setTimeout(check, 3000);
        };
        dockerTimer = setTimeout(check, 3000);
    } catch (cause) {
        toast.error(errorMessage(cause));
    }
}

const machineUrl = computed(
    () =>
        onWorkspace(
            machinesRoutes.show({
                workspace: props.workspaceSlug,
                machine: props.machineId,
            }),
        ).url,
);
</script>

<template>
    <section
        v-if="running"
        class="dash-card flex flex-col gap-3 px-[18px] py-3.5"
        aria-live="polite"
    >
        <div class="flex flex-wrap items-center gap-x-3 gap-y-2">
            <StatusBadge
                :status="running.state === 'stale' ? 'pending' : 'deploying'"
                :label="runningLabel"
            />
            <p class="min-w-0 text-[13px]/5 font-medium text-fg-primary">
                {{ runningTitle }}
            </p>
            <span class="min-w-0 truncate text-[12px]/4 text-fg-muted">{{
                runningMeta
            }}</span>
            <span class="grow" />
            <DashButton
                v-if="canManage && running.state === 'stale'"
                size="sm"
                :label="$t('Stop waiting')"
                :loading="stopping"
                @click="stopWaiting"
            />
            <DashButton
                size="sm"
                :label="$t('Full log')"
                @click="emit('log', running.id)"
            />
        </div>
        <div
            class="rounded-[8px] border-[0.5px] border-edge-tray-panel bg-surface-well px-3 py-2 font-ui-mono text-[11.5px]/[18px] text-fg-secondary"
            role="log"
            :aria-label="$t('Latest log lines')"
        >
            <template v-if="tail.length">
                <div
                    v-for="(line, index) in tail"
                    :key="`${live?.log.length}-${index}`"
                    class="truncate"
                >
                    {{ line }}
                </div>
            </template>
            <p v-else class="text-fg-faint">
                {{
                    running.state === 'queued'
                        ? $t('Waiting for :machine to pick it up…', {
                              machine: machineName,
                          })
                        : $t('Waiting for the first log lines…')
                }}
            </p>
        </div>
    </section>

    <section
        v-else-if="failure"
        class="dash-card flex flex-col gap-3 border-[color-mix(in_srgb,var(--dash-down)_35%,transparent)]! px-[18px] py-3.5"
    >
        <div class="flex items-start gap-3">
            <CircleX
                class="mt-0.5 size-4 shrink-0 text-(--dash-down)"
                :stroke-width="2.2"
                aria-hidden="true"
            />
            <div class="flex min-w-0 flex-1 flex-col gap-1">
                <p class="text-[13px]/5 font-medium text-fg-primary">
                    {{ failureTitle }}
                    <span
                        v-if="failure.at"
                        class="font-normal text-fg-muted"
                        :title="new Date(failure.at).toLocaleString()"
                        >· {{ timeAgo(failure.at) }}</span
                    >
                </p>
                <p class="text-[13px]/5 text-fg-secondary">{{ cause }}</p>
                <p v-if="fix" class="text-[13px]/5 text-fg-secondary">
                    {{ fix }}
                </p>
                <p
                    v-if="keyLine"
                    class="truncate font-ui-mono text-[12px]/5 text-fg-muted"
                    :title="keyLine"
                >
                    {{ keyLine }}
                </p>
                <div
                    v-if="failure.explanation?.command"
                    class="mt-1 flex min-w-0 items-center gap-2"
                >
                    <code
                        class="min-w-0 flex-1 truncate rounded-[6px] bg-surface-well px-2 py-1 font-ui-mono text-[12px]/4 text-fg-primary"
                        >{{ failure.explanation.command }}</code
                    >
                    <DashButton
                        size="sm"
                        :label="copied ? $t('Copied') : $t('Copy')"
                        @click="copy(failure.explanation.command)"
                    />
                </div>
            </div>
        </div>

        <div
            v-if="dockerAsk"
            class="flex flex-wrap items-center gap-2 pl-7 text-[13px]/5 text-fg-secondary"
        >
            <span class="grow">{{
                $t('Install Docker on :machine? It takes a few minutes.', {
                    machine: machineName,
                })
            }}</span>
            <DashButton
                size="sm"
                :label="$t('Cancel')"
                @click="dockerAsk = false"
            />
            <DashButton
                size="sm"
                variant="primary"
                :label="$t('Install Docker')"
                @click="installDocker"
            />
        </div>

        <div v-else class="flex flex-wrap items-center gap-2 pl-7">
            <template v-if="canManage">
                <DashButton
                    v-if="failure.explanation?.settings"
                    size="sm"
                    :label="settingsLabels[failure.explanation.settings]"
                    @click="emit('settings', failure.explanation.settings)"
                />
                <DashButton
                    v-else-if="
                        !failure.explanation?.docker &&
                        !failure.explanation?.cleanup
                    "
                    size="sm"
                    :label="$t('Check the build settings')"
                    @click="emit('settings', null)"
                />
                <DashButton
                    v-if="failure.explanation?.docker"
                    size="sm"
                    :label="
                        dockerJob && !dockerJob.finished
                            ? $t('Installing Docker…')
                            : $t('Install Docker')
                    "
                    :disabled="!online || (!!dockerJob && !dockerJob.finished)"
                    @click="dockerAsk = true"
                />
                <DashButton
                    v-if="failure.explanation?.cleanup"
                    size="sm"
                    :label="$t('Free up disk space')"
                    :href="machineUrl"
                />
            </template>
            <DashButton
                size="sm"
                :label="$t('Full log')"
                @click="emit('log', failure.jobId)"
            />
            <span class="grow" />
            <span v-if="!online" class="text-[12px]/4 text-fg-muted">{{
                $t(':machine is offline.', { machine: machineName })
            }}</span>
            <DashButton
                v-if="canManage && deploy.can.redeploy"
                size="sm"
                variant="primary"
                :label="$t('Deploy again')"
                :loading="redeploying"
                :disabled="!online"
                @click="deployAgain"
            />
        </div>
    </section>

    <section
        v-else-if="deploy.proxyNote"
        class="dash-card flex flex-col gap-1 px-[18px] py-3.5"
    >
        <p class="text-[13px]/5 font-medium text-fg-primary">
            {{ $t('The app is running') }}
        </p>
        <p class="text-[13px]/5 text-fg-secondary">{{ deploy.proxyNote }}</p>
    </section>
</template>
