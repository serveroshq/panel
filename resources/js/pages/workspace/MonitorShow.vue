<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Ellipsis } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import MonitorDialog from '@/components/monitors/MonitorDialog.vue';
import ResponseChart from '@/components/monitors/ResponseChart.vue';
import UptimeDays from '@/components/monitors/UptimeDays.vue';
import {
    address,
    cadence,
    clock,
    dayAndTime,
    daysUntil,
    ms,
    percent,
    roughly,
    spelled,
} from '@/components/monitors/monitors';
import type {
    MachineOption,
    Monitor,
    MonitorChart,
    MonitorCheckRow,
    MonitorDay,
    MonitorIncidentRow,
    OutageTone,
} from '@/components/monitors/monitors';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { onWorkspace } from '@/lib/workspaceRoute';
import agentsRoutes from '@/routes/workspace/agents';
import machinesRoutes from '@/routes/workspace/machines';
import monitorsRoutes from '@/routes/workspace/monitors';
import type { Workspace, WorkspaceAccess } from '@/types';

type Range = '24h' | '7d';

const props = defineProps<{
    workspace: Workspace;
    monitor: Monitor;
    machines: MachineOption[];
    minInterval: number;
    range: Range;
    figures: {
        uptime30: number | null;
        uptime90: number | null;
        averageMs: number | null;
        lastIncident: { startedAt: string; resolvedAt: string | null } | null;
    };
    chart: MonitorChart;
    days: MonitorDay[];
    incidents: { count: number; list: MonitorIncidentRow[] };
    checks: MonitorCheckRow[];
}>();

const page = usePage<{
    workspaceAccess: WorkspaceAccess | null;
    agentsPreview?: boolean;
}>();
const agentsPreview = computed(() => page.props.agentsPreview === true);
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const now = ref(Date.now());
const busy = ref<'check' | 'pause' | null>(null);
const editing = ref(false);
const removing = ref(false);
const allIncidents = ref(false);
const range = ref<Range>(props.range);

let tick: ReturnType<typeof setInterval> | undefined;
let reload: ReturnType<typeof setInterval> | undefined;

onMounted(() => {
    tick = setInterval(() => (now.value = Date.now()), 5000);
    reload = setInterval(() => {
        if (document.visibilityState === 'visible' && !editing.value) {
            router.reload({ except: ['machines', 'workspace'] });
        }
    }, 20000);
});

onBeforeUnmount(() => {
    clearInterval(tick);
    clearInterval(reload);
});

const route = computed(() => ({
    workspace: props.workspace.slug,
    monitor: props.monitor.id,
}));

const monitorsUrl = computed(
    () => onWorkspace(monitorsRoutes.index(props.workspace.slug)).url,
);

const machine = computed(
    () => props.machines.find((m) => m.id === props.monitor.machineId) ?? null,
);

const machineUrl = computed(() =>
    machine.value
        ? onWorkspace(
              machinesRoutes.show({
                  workspace: props.workspace.slug,
                  machine: machine.value.id,
              }),
          ).url
        : null,
);

const agentUrl = computed(
    () =>
        onWorkspace(
            agentsRoutes.index(props.workspace.slug, {
                query: { from: `monitor:${props.monitor.id}` },
            }),
        ).url,
);

const dot = computed(
    () =>
        ({
            up: 'bg-status-live',
            down: 'bg-status-down',
            pending: 'bg-fg-faint',
            paused: 'bg-fg-faint',
        })[props.monitor.status],
);

const status = computed(() => {
    const m = props.monitor;
    if (m.status === 'paused') {
        return { text: trans('Paused'), tone: 'text-fg-muted' };
    }
    if (m.status === 'pending') {
        return m.error
            ? {
                  text: trans('Failing: :error', { error: String(m.error) }),
                  tone: 'text-status-pending',
              }
            : {
                  text: trans('Waiting for the first check'),
                  tone: 'text-fg-muted',
              };
    }
    const up = m.status === 'up';
    const tone = up ? 'text-status-live' : 'text-status-down';
    if (!m.since) return { text: up ? trans('Up') : trans('Down'), tone };
    const seconds = (now.value - new Date(m.since).getTime()) / 1000;

    return {
        text: up
            ? trans('Up for :time', { time: roughly(m.since, now.value) })
            : trans('Down for :time', { time: spelled(seconds) }),
        tone,
    };
});

const mutedUntil = computed(() =>
    props.monitor.mutedUntil
        ? dayAndTime(props.monitor.mutedUntil, now.value)
        : null,
);

type Figure = { value: string; label: string; tone?: string };

const tiles = computed<Figure[]>(() => {
    const m = props.monitor;
    const f = props.figures;
    const list: Figure[] = [
        { value: percent(f.uptime30), label: trans('Uptime, 30 days') },
        { value: ms(f.averageMs), label: trans('Average response, 24 hours') },
    ];

    if (!f.lastIncident) {
        list.push({ value: trans('None'), label: trans('Incidents so far') });
    } else if (!f.lastIncident.resolvedAt) {
        list.push({
            value: roughly(f.lastIncident.startedAt, now.value),
            label: trans('Into the current incident'),
            tone: 'text-status-down',
        });
    } else {
        list.push({
            value: roughly(f.lastIncident.resolvedAt, now.value),
            label: trans('Since the last incident'),
        });
    }

    const https =
        m.type === 'http' && (m.url ?? '').toLowerCase().startsWith('https');
    if (https) {
        const days = daysUntil(m.sslExpiresAt, now.value);
        list.push({
            value:
                days === null
                    ? '–'
                    : days < 0
                      ? trans('Expired')
                      : trans(days === 1 ? '1 day' : ':count days', {
                            count: String(days),
                        }),
            label: trans('Until the certificate expires'),
            tone:
                days === null
                    ? undefined
                    : days < 7
                      ? 'text-status-down'
                      : days < 14
                        ? 'text-status-pending'
                        : undefined,
        });
    } else {
        list.push({
            value: m.lastCheckedAt ? roughly(m.lastCheckedAt, now.value) : '–',
            label: trans('Since the last check'),
        });
    }

    return list;
});

const incidentRows = computed(() =>
    allIncidents.value
        ? props.incidents.list
        : props.incidents.list.slice(0, 5),
);

const incidentDot: Record<OutageTone, string> = {
    none: 'bg-fg-faint',
    up: 'bg-status-live',
    short: 'bg-[#f5a833]',
    long: 'bg-status-down',
    ongoing: 'bg-status-down',
};

function incidentTitle(incident: MonitorIncidentRow): string {
    const time = spelled(incident.seconds);

    return incident.resolvedAt
        ? trans('Down for :time', { time })
        : trans('Down for :time so far', { time });
}

function incidentWhen(iso: string): string {
    return new Date(iso).toLocaleString(undefined, {
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
        hourCycle: 'h23',
    });
}

function setRange(value: Range): void {
    range.value = value;
    router.get(
        onWorkspace(
            monitorsRoutes.show(route.value, {
                query: value === '24h' ? {} : { range: value },
            }),
        ).url,
        {},
        {
            only: ['chart', 'range'],
            preserveScroll: true,
            preserveState: true,
            replace: true,
        },
    );
}

function checkNow(): void {
    busy.value = 'check';
    router.post(
        onWorkspace(monitorsRoutes.check(route.value)).url,
        {},
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

function togglePause(): void {
    busy.value = 'pause';
    router.patch(
        onWorkspace(monitorsRoutes.pause(route.value)).url,
        { paused: !props.monitor.paused },
        { preserveScroll: true, onFinish: () => (busy.value = null) },
    );
}

function mute(minutes: number): void {
    router.post(
        onWorkspace(monitorsRoutes.mute(route.value)).url,
        { minutes },
        { preserveScroll: true },
    );
}

function unmute(): void {
    router.delete(onWorkspace(monitorsRoutes.unmute(route.value)).url, {
        preserveScroll: true,
    });
}

function remove(): void {
    router.delete(onWorkspace(monitorsRoutes.destroy(route.value)).url);
}

const muteOptions = [
    { minutes: 60, label: trans('1 hour') },
    { minutes: 240, label: trans('4 hours') },
    { minutes: 1440, label: trans('1 day') },
];

function figureBorder(i: number): string {
    return [
        i < 3 ? 'xl:border-r-[0.5px]' : '',
        i % 2 === 0 ? 'max-xl:border-r-[0.5px]' : '',
        i < 2 ? 'max-xl:border-b-[0.5px]' : '',
    ].join(' ');
}
</script>

<template>
    <Head :title="monitor.name" />

    <DashHeader
        :crumbs="[
            { label: $t('Monitors'), href: monitorsUrl },
            { label: monitor.name },
        ]"
    >
        <template v-if="canManage" #actions>
            <DashButton
                v-if="monitor.status === 'down' && agentsPreview"
                :label="$t('Fix with an agent')"
                :href="agentUrl"
            />
            <DashButton
                :label="monitor.paused ? $t('Resume') : $t('Pause')"
                :loading="busy === 'pause'"
                :disabled="busy !== null"
                @click="togglePause"
            />
            <DashButton
                :label="$t('Check now')"
                :loading="busy === 'check'"
                :disabled="busy !== null || monitor.paused"
                @click="checkNow"
            />
            <DropdownMenu>
                <DropdownMenuTrigger as-child>
                    <DashButton
                        :icon="Ellipsis"
                        :aria-label="$t('More actions')"
                        :title="$t('More actions')"
                    />
                </DropdownMenuTrigger>
                <DropdownMenuContent
                    class="ds-modal min-w-52 font-ui"
                    align="end"
                >
                    <DropdownMenuItem @select="editing = true">
                        {{ $t('Edit monitor') }}
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        v-if="monitor.mutedUntil"
                        @select="unmute"
                    >
                        {{ $t('Unmute alerts') }}
                    </DropdownMenuItem>
                    <template v-else>
                        <DropdownMenuItem
                            v-for="option in muteOptions"
                            :key="option.minutes"
                            @select="mute(option.minutes)"
                        >
                            {{
                                $t('Mute alerts for :label', {
                                    label: option.label,
                                })
                            }}
                        </DropdownMenuItem>
                    </template>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem
                        variant="destructive"
                        class="text-status-down!"
                        @select="removing = true"
                    >
                        {{ $t('Delete monitor') }}
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
        </template>
    </DashHeader>

    <div class="flex flex-col gap-6 px-5 pt-7 pb-8 md:px-8">
        <div class="flex min-w-0 flex-col gap-1">
            <div class="flex min-w-0 items-center gap-2.5">
                <span
                    class="size-2 shrink-0 rounded-full"
                    :class="dot"
                    aria-hidden="true"
                />
                <h1
                    class="truncate text-[20px]/7 font-medium tracking-[-0.015em] text-fg-primary"
                >
                    {{ monitor.name }}
                </h1>
            </div>
            <p
                class="flex flex-wrap items-center text-[13px]/5 tracking-[-0.005em] text-fg-tertiary"
            >
                <span :class="status.tone">{{ status.text }}</span>
                <span class="mx-1.5" aria-hidden="true">·</span>
                <a
                    v-if="monitor.type === 'http' && monitor.url"
                    :href="monitor.url"
                    target="_blank"
                    rel="noopener noreferrer"
                    class="hover:text-fg-secondary hover:underline hover:underline-offset-4"
                    >{{ address(monitor) }}</a
                >
                <span v-else>{{ address(monitor) }}</span>
                <span class="mx-1.5" aria-hidden="true">·</span>
                <span>
                    {{ cadence(monitor.interval)
                    }}<template v-if="machine && machineUrl"
                        >{{ $t(', on') + ' '
                        }}<Link
                            :href="machineUrl"
                            class="hover:text-fg-secondary hover:underline hover:underline-offset-4"
                            >{{ machine.name }}</Link
                        ></template
                    >
                </span>
                <template v-if="mutedUntil">
                    <span class="mx-1.5" aria-hidden="true">·</span>
                    <span class="text-status-pending">{{
                        $t('Alerts muted until :time', { time: mutedUntil })
                    }}</span>
                </template>
                <template v-else-if="!monitor.alerts">
                    <span class="mx-1.5" aria-hidden="true">·</span>
                    <span>{{ $t('Alerts off') }}</span>
                </template>
            </p>
        </div>

        <DashTray icon="house-fill" :title="$t('Overview')">
            <div class="grid grid-cols-2 xl:grid-cols-4">
                <div
                    v-for="(figure, i) in tiles"
                    :key="figure.label"
                    class="flex min-w-0 flex-col gap-0.5 border-edge-tabs px-4 py-3.5"
                    :class="figureBorder(i)"
                >
                    <span
                        class="truncate text-[24px]/8 font-medium tracking-[-0.02em] tabular-nums"
                        :class="figure.tone ?? 'text-fg-primary'"
                        >{{ figure.value }}</span
                    >
                    <span
                        class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-tertiary"
                        >{{ figure.label }}</span
                    >
                </div>
            </div>
        </DashTray>

        <ResponseChart
            :chart="chart"
            :range="range"
            :now="now"
            @update:range="setRange"
        />

        <UptimeDays :days="days" :uptime="figures.uptime90" />

        <div class="grid gap-4 lg:grid-cols-2">
            <DashTray
                icon="info-fill"
                :title="$t('Incidents')"
                :meta="
                    $t(':count in 90 days', {
                        count: String(incidents.count),
                    })
                "
            >
                <ul v-if="incidents.list.length" class="pt-3">
                    <li
                        v-for="incident in incidentRows"
                        :key="incident.id"
                        class="flex items-center gap-3 border-b-[0.5px] border-edge-tabs px-4 py-2.5 last:border-b-0"
                    >
                        <span
                            class="size-2 shrink-0 rounded-full"
                            :class="incidentDot[incident.tone]"
                            aria-hidden="true"
                        />
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                                >{{ incidentTitle(incident) }}</span
                            >
                            <span
                                class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                                :title="incident.cause"
                                >{{ incident.cause }}</span
                            >
                        </span>
                        <a
                            v-for="issue in incident.issues ?? []"
                            :key="issue.url"
                            :href="issue.url"
                            target="_blank"
                            rel="noopener"
                            class="flex shrink-0 items-center gap-1 rounded-[5px] px-1 font-ui-mono text-[12px]/4 text-fg-secondary hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                            :title="$t('Its issue in Linear')"
                            >{{ issue.key
                            }}<FilledIcon
                                name="open-fill"
                                :size="11"
                                class="text-fg-muted"
                        /></a>
                        <time
                            class="shrink-0 text-[12px]/4 tracking-[-0.005em] whitespace-nowrap text-fg-muted"
                            :datetime="incident.startedAt"
                            >{{ incidentWhen(incident.startedAt) }}</time
                        >
                    </li>
                </ul>
                <p
                    v-else
                    class="flex flex-1 items-center justify-center px-4 py-10 text-center text-[13px]/5 text-fg-muted"
                >
                    {{ $t('No downtime in the last 90 days.') }}
                </p>
                <button
                    v-if="incidents.list.length > 5"
                    type="button"
                    class="cursor-pointer border-t-[0.5px] border-edge-tabs px-4 py-2.5 text-left text-[12.5px]/4 font-medium text-fg-tertiary hover:text-fg-primary focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
                    @click="allIncidents = !allIncidents"
                >
                    {{
                        allIncidents
                            ? $t('Show fewer')
                            : $t('Show all :count', {
                                  count: String(incidents.list.length),
                              })
                    }}
                </button>
            </DashTray>

            <DashTray
                icon="activity-fill"
                :title="$t('Recent checks')"
                :meta="cadence(monitor.interval)"
            >
                <ul v-if="checks.length" class="pt-3">
                    <li
                        v-for="check in checks"
                        :key="check.id"
                        class="flex h-9 items-center gap-3 border-b-[0.5px] border-edge-tabs px-4 last:border-b-0"
                    >
                        <time
                            class="w-11 shrink-0 font-ui-mono text-[12px]/4 text-fg-muted"
                            :datetime="check.at"
                            :title="dayAndTime(check.at, now)"
                            >{{ clock(check.at) }}</time
                        >
                        <span
                            class="size-1.5 shrink-0 rounded-full"
                            :class="
                                check.ok ? 'bg-status-live' : 'bg-status-down'
                            "
                            aria-hidden="true"
                        />
                        <span
                            class="min-w-0 flex-1 truncate text-[13px]/5 tracking-[-0.005em] text-fg-secondary"
                            :title="check.error ?? undefined"
                            >{{ check.label }}</span
                        >
                        <span
                            class="shrink-0 text-[13px]/5 tracking-[-0.005em] whitespace-nowrap text-fg-primary tabular-nums"
                            >{{ ms(check.ms) }}</span
                        >
                    </li>
                </ul>
                <p
                    v-else
                    class="flex flex-1 items-center justify-center px-4 py-10 text-center text-[13px]/5 text-fg-muted"
                >
                    {{
                        monitor.paused
                            ? $t('Paused. Resume it to check again.')
                            : monitor.lastCheckedAt
                              ? $t('No checks in the last 7 days.')
                              : $t('The first check runs within a few seconds.')
                    }}
                </p>
            </DashTray>
        </div>
    </div>

    <MonitorDialog
        v-model:open="editing"
        :monitor="monitor"
        :machines="machines"
        :min-interval="minInterval"
    />

    <Dialog v-model:open="removing">
        <DialogContent class="gap-0 p-0 font-ui sm:max-w-md">
            <div class="flex flex-col gap-1 p-5">
                <DialogTitle class="text-[15px]/6 font-medium text-fg-primary">
                    {{ $t('Delete :name?', { name: monitor.name }) }}
                </DialogTitle>
                <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                    {{
                        $t(
                            'Its checks, incidents and uptime history go with it. Pause it instead to keep the history.',
                        )
                    }}
                </DialogDescription>
            </div>
            <div class="dash-card-footer gap-2">
                <DashButton :label="$t('Cancel')" @click="removing = false" />
                <DashButton
                    variant="danger"
                    :label="$t('Delete monitor')"
                    @click="remove"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
