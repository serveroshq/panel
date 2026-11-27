<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import type { DeployRow } from '@/components/apps/appTypes';
import {
    messageFor,
    rowStatus,
    seconds,
    sendJson,
    shortSha,
    triggerLabel,
} from '@/components/apps/appTypes';
import DashButton from '@/components/dash/DashButton.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import { describe, errorMessage } from '@/lib/errors';
import { jobStatus } from '@/lib/jobStatus';
import { timeAgo } from '@/lib/timeAgo';
import { onWorkspace } from '@/lib/workspaceRoute';
import appsRoutes from '@/routes/workspace/apps';

const props = defineProps<{
    /** Undefined while the page is still loading it. */
    rows: DeployRow[] | undefined;
    messages?: Record<string, string>;
    workspaceSlug: string;
    machineId: number;
    service: string;
    liveCommit: string | null;
    source: 'git' | 'upload' | 'image';
}>();

const emit = defineEmits<{ log: [jobId: string] }>();

const PAGE = 20;
const older = ref<DeployRow[]>([]);
const more = ref(false);
const loading = ref(false);
const error = ref<string | null>(null);
const filter = ref<'all' | 'failed'>('all');

watch(
    () => props.rows,
    (rows) => {
        if (!rows) return;
        older.value = older.value.filter(
            (row) => !rows.some((fresh) => fresh.id === row.id),
        );
        if (older.value.length === 0) more.value = rows.length >= PAGE;
    },
    { immediate: true },
);

const all = computed(() => [...(props.rows ?? []), ...older.value]);
const failed = (row: DeployRow): boolean =>
    ['failed', 'timed_out', 'refused', 'stale'].includes(row.state);
const shown = computed(() =>
    filter.value === 'failed' ? all.value.filter(failed) : all.value,
);
const pills = computed(() => [
    { value: 'all' as const, label: trans('All'), count: all.value.length },
    {
        value: 'failed' as const,
        label: trans('Failed'),
        count: all.value.filter(failed).length,
    },
]);

// The newest deploy that put the live commit in place.
const liveRow = computed(() => {
    const live = props.liveCommit;
    if (!live) return null;

    return (
        all.value.find(
            (row) =>
                row.state === 'succeeded' &&
                !!row.commit &&
                row.commit.startsWith(live),
        )?.id ?? null
    );
});

async function loadMore(): Promise<void> {
    const last = all.value.at(-1);
    if (!last?.createdAt || loading.value) return;
    loading.value = true;
    error.value = null;
    try {
        const answer = await sendJson(
            onWorkspace(
                appsRoutes.deploys(
                    {
                        workspace: props.workspaceSlug,
                        machine: props.machineId,
                        service: props.service,
                    },
                    { query: { before: last.createdAt, limit: 30 } },
                ),
            ).url,
            'GET',
        );
        if (!answer.ok) {
            error.value = describe(answer.status, answer.body);

            return;
        }
        const rows = (answer.body.deploys ?? []) as DeployRow[];
        older.value = [
            ...older.value,
            ...rows.filter((row) => !all.value.some((r) => r.id === row.id)),
        ];
        more.value = answer.body.more === true;
    } catch (cause) {
        error.value = errorMessage(cause);
    } finally {
        loading.value = false;
    }
}

function badge(row: DeployRow): {
    status: ReturnType<typeof rowStatus>['status'];
    label: string;
} {
    if (row.state === 'running' || row.state === 'queued') {
        return {
            status: 'deploying',
            label: jobStatus(row.state, row.phase, row.progress).label,
        };
    }

    return rowStatus(row);
}

function version(row: DeployRow): { sha: string | null; text: string } {
    if (row.image && !row.commit) return { sha: null, text: row.image };
    const sha = shortSha(row.commit);
    const message = messageFor(props.messages, row.commit);
    if (message) return { sha, text: message };
    if (props.source === 'upload' && row.kind === 'deploy') {
        return { sha, text: trans('Uploaded code') };
    }

    return { sha, text: sha ? '' : '–' };
}

function duration(row: DeployRow): string {
    if (row.durationSecs !== null) return seconds(row.durationSecs);
    if (row.state === 'running' || row.state === 'queued') {
        return trans('Going');
    }

    return '–';
}
</script>

<template>
    <section class="dash-card flex flex-col overflow-hidden">
        <div class="flex flex-wrap items-center gap-3 px-[18px] py-3">
            <div class="flex min-w-0 flex-1 flex-col">
                <h2
                    class="text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Deploys') }}
                </h2>
                <p class="text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'Every deploy and rollback of :app. Open one to read its log.',
                            { app: service },
                        )
                    }}
                </p>
            </div>
            <FilterPills
                v-if="rows"
                v-model="filter"
                :options="pills"
                :label="$t('Show')"
            />
        </div>

        <DashTable class="border-t-[0.5px] border-edge-row">
            <template #head>
                <DashTh icon="history" :width="150" first>{{
                    $t('When')
                }}</DashTh>
                <DashTh icon="code-fill" fill>{{ $t('Version') }}</DashTh>
                <DashTh icon="branch-fill" :width="140">{{
                    $t('Trigger')
                }}</DashTh>
                <DashTh icon="user-fill" :width="150">{{ $t('By') }}</DashTh>
                <DashTh icon="activity-fill" :width="160">{{
                    $t('State')
                }}</DashTh>
                <DashTh icon="update-fill" :width="110" class="border-r-0!">{{
                    $t('Took')
                }}</DashTh>
            </template>
            <DashTr
                v-for="row in shown"
                :key="row.id"
                clickable
                tabindex="0"
                :aria-label="$t('Open the log')"
                @click="emit('log', row.id)"
                @keydown.enter="emit('log', row.id)"
            >
                <DashTd first>
                    <span
                        class="text-fg-primary"
                        :title="
                            row.createdAt
                                ? new Date(row.createdAt).toLocaleString()
                                : undefined
                        "
                        >{{ timeAgo(row.createdAt) }}</span
                    >
                </DashTd>
                <DashTd>
                    <span
                        v-if="version(row).sha"
                        class="shrink-0 font-ui-mono text-[12px] text-fg-muted"
                        >{{ version(row).sha }}</span
                    >
                    <span
                        class="max-w-[420px] min-w-0 truncate text-fg-primary"
                        :class="
                            !version(row).sha &&
                            row.image &&
                            'font-ui-mono text-[12px]'
                        "
                        :title="version(row).text"
                        >{{
                            row.kind === 'rollback'
                                ? $t('Rolled back to this')
                                : version(row).text
                        }}</span
                    >
                    <span
                        v-if="row.id === liveRow"
                        class="flex h-[20px] shrink-0 items-center gap-[5px] rounded-[10px] bg-status-live/14 pr-2 pl-[7px] text-[11.5px]/4 font-medium text-status-live"
                    >
                        <span
                            class="size-1.5 rounded-full bg-status-live"
                            aria-hidden="true"
                        />
                        {{ $t('Live') }}
                    </span>
                </DashTd>
                <DashTd>
                    {{ triggerLabel(row.trigger, row.kind) }}
                    <span v-if="row.noCache" class="text-fg-muted">{{
                        $t('· no cache')
                    }}</span>
                </DashTd>
                <DashTd>{{ row.by ?? '–' }}</DashTd>
                <DashTd>
                    <StatusBadge
                        :status="badge(row).status"
                        :label="badge(row).label"
                    />
                </DashTd>
                <DashTd class="border-r-0!">
                    <span class="font-ui-mono text-[12px] text-fg-tertiary">{{
                        duration(row)
                    }}</span>
                </DashTd>
            </DashTr>
            <tr v-if="!rows">
                <td colspan="6" class="px-5 py-4 text-[13px]/5 text-fg-muted">
                    {{ $t('Loading deploys…') }}
                </td>
            </tr>
            <tr v-else-if="shown.length === 0">
                <td colspan="6" class="px-5 py-4 text-[13px]/5 text-fg-muted">
                    {{
                        filter === 'failed'
                            ? $t('None of these failed.')
                            : $t('No deploys yet.')
                    }}
                </td>
            </tr>
        </DashTable>

        <div
            v-if="rows && (more || error)"
            class="flex items-center gap-3 border-t-[0.5px] border-edge-row px-[18px] py-2.5"
        >
            <p v-if="error" class="grow text-[12px]/4 text-status-down">
                {{ error }}
            </p>
            <span v-else class="grow" />
            <DashButton
                v-if="more"
                size="sm"
                :label="$t('Show older')"
                :loading="loading"
                @click="loadMore"
            />
        </div>
    </section>
</template>
