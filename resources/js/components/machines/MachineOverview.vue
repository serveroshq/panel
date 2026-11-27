<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Link, router } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { computed } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashTray from '@/components/dash/DashTray.vue';
import GaugeDial from '@/components/dash/GaugeDial.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import DiskUsagePanel from '@/components/machines/DiskUsagePanel.vue';
import DockerPanel from '@/components/machines/DockerPanel.vue';
import DotRidge from '@/components/machines/DotRidge.vue';
import MachineGlobe from '@/components/machines/MachineGlobe.vue';
import UsageRing from '@/components/machines/UsageRing.vue';
import VolumeBars from '@/components/machines/VolumeBars.vue';
import {
    bytesLabel,
    memoryOf,
    privateAddress,
    rateParts,
    sizeName,
    sizePair,
    systemName,
    uptimeLabel,
} from '@/components/machines/machineFormat';
import type { Machine } from '@/components/machines/machines';
import { placeOf } from '@/components/machines/regions';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import type { MachineCloudServer } from '@/components/integrations/integrations';
import OperationSteps from '@/components/integrations/OperationSteps.vue';
import { operationActive } from '@/components/integrations/useOperation';
import type { ServerAction } from '@/components/machines/server/MachineServerActions.vue';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import workspaceRoutes from '@/routes/workspace';
import integrationRoutes from '@/routes/workspace/integrations';
import projectsRoutes from '@/routes/workspace/projects';

export type UsagePoint = {
    ts: number;
    cpu: number | null;
    cpuMax: number | null;
    mem: number | null;
    memMax: number | null;
    disk: number | null;
};

export type BackupRun = {
    id: number;
    serviceName: string;
    state: string;
    bytes: number | null;
    destination: string | null;
    trigger: string;
    error: string | null;
    createdAt: string | null;
    finishedAt: string | null;
};

export type MachineOverviewData = {
    projects: number;
    updates: {
        count: number;
        security: number;
        checkedAt: string | null;
        rebootRequired: boolean;
        packages: {
            name: string;
            from: string | null;
            to: string | null;
            security: boolean;
        }[];
    };
    backup: { schedule: string; destination: string | null };
    backups: BackupRun[];
};

export type MachineLive = {
    cpu: number | null;
    memoryMb: number | null;
    load: [number, number, number] | null;
    processes: number | null;
    networkIn: number | null;
    networkOut: number | null;
    at: string | null;
    last: { at: string; cpu: number | null; memoryMb: number | null } | null;
};

export type MachineTraffic = {
    received: number;
    sent: number;
    since: string | null;
};

type Metric = 'cpu' | 'mem' | 'disk';

const props = defineProps<{
    machine: Machine;
    workspaceSlug: string;
    live: MachineLive | null;
    traffic: MachineTraffic | null;
    day: UsagePoint[];
    history?: UsagePoint[];
    range: string;
    loadingRange: boolean;
    overview: MachineOverviewData;
    services: number;
    canManage: boolean;
    online: boolean;
    cloudServer?: MachineCloudServer | null;
}>();

const metric = defineModel<Metric>('metric', { default: 'cpu' });

const emit = defineEmits<{
    tab: [tab: 'services' | 'updates' | 'backups'];
    range: [range: string];
    server: [action: ServerAction];
}>();

const regionCode = computed(
    () => props.machine.region ?? props.machine.facts?.hosting?.region ?? null,
);
const place = computed(() =>
    placeOf(regionCode.value, props.machine.facts?.hosting?.zone),
);

const plan = computed(
    () => props.machine.facts?.hosting?.instance_type ?? props.machine.tier,
);

const providerName = computed(() =>
    props.machine.provider === 'Other'
        ? trans('Other')
        : props.machine.provider,
);

const upFor = computed(() => {
    const booted = props.machine.facts?.boot_ts;
    if (!props.online || !booted) return '–';

    return uptimeLabel(Date.now() / 1000 - booted);
});

const summary = computed<
    { label: string; value: string; mono?: boolean; logo?: boolean }[]
>(() => [
    {
        label: trans('Provider'),
        value: plan.value
            ? `${providerName.value} · ${plan.value}`
            : providerName.value,
        logo: true,
    },
    {
        label: trans('Region'),
        value: place.value
            ? `${place.value.city} (${regionCode.value})`
            : (regionCode.value ?? '–'),
    },
    { label: trans('Public IP'), value: props.machine.ip, mono: true },
    {
        label: trans('Private IP'),
        value: privateAddress(props.machine) ?? '–',
        mono: true,
    },
    { label: trans('System'), value: systemName(props.machine) ?? '–' },
    { label: trans('Size'), value: sizeName(props.machine) ?? '–' },
    {
        label: trans('Agent'),
        value: props.machine.daemonVersion
            ? `serverosd ${props.machine.daemonVersion}`
            : '–',
    },
    { label: trans('Up for'), value: upFor.value },
]);

const system = computed(() =>
    [
        [trans('Kernel'), props.machine.facts?.kernel],
        [trans('Architecture'), props.machine.facts?.arch],
        [trans('CPU model'), props.machine.facts?.cpu_model],
        [trans('Zone'), props.machine.facts?.hosting?.zone],
    ].map(([label, value]) => ({ label: label ?? '', value: value || '–' })),
);

const cpuNow = computed(() => props.live?.cpu ?? props.machine.cpu);
const memory = computed(() =>
    memoryOf({
        ...props.machine,
        memoryMb: props.live?.memoryMb ?? props.machine.memoryMb,
    }),
);

const memoryReadout = computed(() =>
    memory.value ? bytesLabel(memory.value.used) : '–',
);

function readingTime(iso: string): { date: string; time: string } {
    const at = new Date(iso);

    return {
        date: at.toLocaleDateString([], {
            day: 'numeric',
            month: 'short',
            year:
                at.getFullYear() === new Date().getFullYear()
                    ? undefined
                    : 'numeric',
        }),
        time: at.toLocaleTimeString([], {
            hour: '2-digit',
            minute: '2-digit',
            hour12: false,
        }),
    };
}

const lastReading = computed(() => {
    const last = props.live?.last;
    if (!last) return null;
    const values = [
        last.cpu === null
            ? null
            : trans('CPU :percent%', { percent: String(Math.round(last.cpu)) }),
        last.memoryMb === null
            ? null
            : trans('memory :size', {
                  size: bytesLabel(last.memoryMb * 1024 ** 2),
              }),
    ].filter((value): value is string => value !== null);

    return values.length
        ? trans('Last reading :date at :time: :values.', {
              ...readingTime(last.at),
              values: values.join(', '),
          })
        : null;
});

function rate(bytesPerSecond: number | null | undefined) {
    return bytesPerSecond === null || bytesPerSecond === undefined
        ? { value: '–', unit: '' }
        : rateParts(bytesPerSecond);
}

const metrics = computed(() => {
    const load = props.live?.load ?? null;
    const processes = props.live?.processes ?? null;

    return [
        [
            { label: trans('Network in'), ...rate(props.live?.networkIn) },
            { label: trans('Network out'), ...rate(props.live?.networkOut) },
        ],
        [
            {
                label: trans('Load'),
                value: load ? load[0].toFixed(2) : '–',
                unit: load ? trans('1 min') : '',
            },
            {
                label: trans('Processes'),
                value: processes === null ? '–' : String(processes),
                unit: '',
            },
        ],
    ];
});

const cadence = computed(() => {
    switch (props.machine.connection) {
        case 'online':
        case 'degraded':
            return trans('every minute');
        case 'demo':
            return '';
        default:
            return props.machine.lastSeen
                ? trans('last seen :ago', { ago: props.machine.lastSeen })
                : trans('not reporting yet');
    }
});

const volumes = computed(() => {
    const devices = new Map(
        (props.machine.facts?.disks ?? []).map((disk) => [
            disk.mount,
            disk.device,
        ]),
    );

    return (props.machine.disks ?? [])
        .filter((disk) => disk.total > 0)
        .toSorted((a, b) =>
            a.mount === '/'
                ? -1
                : b.mount === '/'
                  ? 1
                  : a.mount.localeCompare(b.mount),
        )
        .slice(0, 3)
        .map((disk) => {
            const name = disk.mount.split('/').filter(Boolean).pop() ?? '';

            return {
                key: disk.mount,
                label:
                    disk.mount === '/'
                        ? trans('Root disk')
                        : trans(':name volume', {
                              name:
                                  name.charAt(0).toUpperCase() + name.slice(1),
                          }),
                detail:
                    disk.mount === '/' ? (devices.get('/') ?? '/') : disk.mount,
                size: sizePair(disk.used, disk.total),
                percent: (disk.used / disk.total) * 100,
            };
        });
});

const storage = computed(() => {
    const disks = (props.machine.disks ?? []).filter((disk) => disk.total > 0);
    const total = disks.reduce((sum, disk) => sum + disk.total, 0);
    if (!total) return null;
    const used = disks.reduce((sum, disk) => sum + disk.used, 0);

    return {
        size: sizePair(used, total),
        percent: Math.round((used / total) * 100),
        asOf: props.live?.last
            ? trans('as of :date', {
                  date: readingTime(props.live.last.at).date,
              })
            : null,
    };
});

const rootDisk = computed(() => {
    const disks = (props.machine.disks ?? []).filter((disk) => disk.total > 0);

    return disks.find((disk) => disk.mount === '/') ?? disks[0] ?? null;
});

const points = computed(() =>
    props.range === '24h' ? props.day : (props.history ?? props.day),
);

const series = computed(() =>
    points.value.map((point) =>
        metric.value === 'cpu'
            ? point.cpu
            : metric.value === 'mem'
              ? point.mem
              : point.disk,
    ),
);

const memoryTotal = computed(() => props.machine.facts?.memory_bytes ?? 0);

const scale = computed(() => {
    const peak = Math.max(0, ...series.value.map((v) => v ?? 0));
    const ceiling =
        metric.value === 'mem' ? Math.max(memoryTotal.value, peak) : 100;
    const floor = metric.value === 'mem' ? 256 * 1024 ** 2 : 10;

    return Math.min(ceiling, Math.max(floor, peak * 1.25));
});

function formatValue(value: number): string {
    return metric.value === 'mem' ? bytesLabel(value) : `${Math.round(value)}%`;
}

const stats = computed(() => {
    const known = series.value.filter((v): v is number => v !== null);
    if (!known.length) return null;

    return {
        avg: known.reduce((sum, v) => sum + v, 0) / known.length,
        peak: Math.max(...known),
    };
});

const dayCpu = computed(() => {
    const known = props.day
        .map((point) => point.cpu)
        .filter((v): v is number => v !== null);

    return known.length
        ? Math.round(known.reduce((sum, v) => sum + v, 0) / known.length)
        : null;
});

const cpuAvg = computed(() =>
    props.machine.setup ? null : (dayCpu.value ?? cpuNow.value),
);

const ranges = computed(() => [
    { value: '1h', label: trans('last hour') },
    { value: '6h', label: trans('last 6 hours') },
    { value: '24h', label: trans('last 24 hours') },
    { value: '7d', label: trans('last 7 days') },
    { value: '30d', label: trans('last 30 days') },
]);

const rangeLabel = computed(
    () => ranges.value.find((r) => r.value === props.range)?.label ?? '',
);

const ticks = computed(
    () =>
        ({
            '1h': ['-60M', '-45M', '-30M', '-15M', 'NOW'],
            '6h': ['-6H', '-4H', '-2H', 'NOW'],
            '24h': ['-24H', '-18H', '-12H', '-6H', 'NOW'],
            '7d': ['-7D', '-6D', '-5D', '-4D', '-3D', '-2D', '-1D', 'NOW'],
            '30d': ['-30D', '-20D', '-10D', 'NOW'],
        })[props.range] ?? [],
);

const metricLabels: Record<Metric, string> = {
    cpu: trans('CPU'),
    mem: trans('Memory'),
    disk: trans('Disk'),
};

function sinceLabel(iso: string): string {
    return trans('since :date', {
        date: new Date(iso).toLocaleDateString([], {
            day: 'numeric',
            month: 'short',
        }),
    });
}

const usage = computed(() => {
    const disk = rootDisk.value;
    const traffic = props.traffic;

    return [
        {
            key: 'cpu',
            title: trans('CPU'),
            color: '#3d63ff',
            percent: cpuAvg.value,
            value:
                cpuAvg.value === null
                    ? '–'
                    : trans(':percent% avg', { percent: String(cpuAvg.value) }),
            of:
                cpuAvg.value !== null && props.machine.facts?.cpu_cores
                    ? trans('of :count vCPU', {
                          count: String(props.machine.facts.cpu_cores),
                      })
                    : '',
        },
        {
            key: 'memory',
            title: trans('Memory'),
            color: '#14b8a6',
            percent: memory.value?.percent ?? null,
            value: memory.value ? bytesLabel(memory.value.used) : '–',
            of: memory.value
                ? trans('out of :total', {
                      total: bytesLabel(memory.value.total),
                  })
                : '',
        },
        {
            key: 'disk',
            title: trans('Disk'),
            color: '#a78bfa',
            percent: disk ? (disk.used / disk.total) * 100 : null,
            value: disk ? bytesLabel(disk.used) : '–',
            of: disk
                ? trans('out of :total', { total: bytesLabel(disk.total) })
                : '',
        },
        {
            key: 'traffic',
            title: trans('Traffic'),
            color: '#f0943c',
            percent: null,
            value: traffic ? bytesLabel(traffic.received + traffic.sent) : '–',
            of: traffic
                ? traffic.since
                    ? sinceLabel(traffic.since)
                    : trans('this month')
                : '',
            hint: traffic
                ? trans(':in in, :out out', {
                      in: bytesLabel(traffic.received),
                      out: bytesLabel(traffic.sent),
                  })
                : undefined,
        },
    ];
});

const projectsUrl = computed(
    () => onWorkspace(projectsRoutes.index(props.workspaceSlug)).url,
);

const links = computed<
    {
        key: string;
        icon: FilledIconName;
        label: string;
        detail: string;
        tab?: 'services' | 'updates';
        href?: string;
    }[]
>(() => [
    {
        key: 'services',
        icon: 'layers-fill',
        label: trans('Services'),
        detail: String(props.services),
        tab: 'services',
    },
    {
        key: 'projects',
        icon: 'projects-fill',
        label: trans('Projects'),
        detail: String(props.overview.projects),
        href: projectsUrl.value,
    },
    {
        key: 'updates',
        icon: 'update-fill',
        label: trans('Updates'),
        detail: props.overview.updates.count
            ? trans(':count waiting', {
                  count: String(props.overview.updates.count),
              })
            : trans('Up to date'),
        tab: 'updates',
    },
]);

const FINISHED = ['succeeded', 'failed', 'cancelled', 'timed_out', 'refused'];

const backupRuns = computed(() => {
    const runs = new Map<
        string,
        { at: string; bytes: number; failed: number; total: number }
    >();
    for (const backup of props.overview.backups) {
        if (!backup.createdAt || !FINISHED.includes(backup.state)) continue;
        const key = backup.createdAt.slice(0, 16);
        const run = runs.get(key) ?? {
            at: backup.createdAt,
            bytes: 0,
            failed: 0,
            total: 0,
        };
        run.bytes += backup.bytes ?? 0;
        run.total += 1;
        if (backup.state !== 'succeeded') run.failed += 1;
        runs.set(key, run);
    }

    return [...runs.values()].slice(0, 4);
});

const backingUp = computed(() =>
    props.overview.backups.some((backup) => !FINISHED.includes(backup.state)),
);

function runLabel(iso: string): string {
    const date = new Date(iso);
    const time = date.toLocaleTimeString([], {
        hour: '2-digit',
        minute: '2-digit',
        hour12: false,
    });
    const today = new Date();
    const yesterday = new Date(Date.now() - 86_400_000);
    const same = (a: Date, b: Date) => a.toDateString() === b.toDateString();
    const day = same(date, today)
        ? trans('Today')
        : same(date, yesterday)
          ? trans('Yesterday')
          : date.toLocaleDateString([], { day: 'numeric', month: 'short' });

    return `${day}, ${time}`;
}

const cloudBusy = computed(() =>
    operationActive(props.cloudServer?.operation ?? null),
);
const cloudAccountUrl = computed(() =>
    props.cloudServer
        ? onWorkspace(
              integrationRoutes.show({
                  workspace: props.workspaceSlug,
                  integration: props.cloudServer.integration.id,
              }),
          ).url
        : null,
);

const cloudButtons = computed<{ key: ServerAction; label: string }[]>(() => [
    { key: 'reboot', label: trans('Restart') },
    { key: 'power_cycle', label: trans('Power cycle') },
    ...(props.cloudServer?.can.includes('snapshots')
        ? [{ key: 'snapshots' as const, label: trans('Snapshots') }]
        : []),
]);

function resize(): void {
    if (props.cloudServer?.can.includes('resize')) {
        emit('server', 'resize');

        return;
    }
    toast.info(
        trans(
            'Connect its cloud account in Integrations to resize it from here.',
        ),
        {
            action: {
                label: trans('Open Integrations'),
                onClick: () =>
                    router.visit(
                        onWorkspace(
                            workspaceRoutes.integrations(props.workspaceSlug),
                        ).url,
                    ),
            },
        },
    );
}
</script>

<template>
    <div class="flex min-h-full flex-col xl:flex-row">
        <div class="flex min-w-0 flex-1 flex-col gap-2.5 px-6 py-4">
            <slot name="notices" />

            <DashTray
                icon="machines-fill"
                :title="$t('Summary')"
                panel-class="px-[18px] pt-3 pb-3.5"
            >
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-4">
                    <div
                        v-for="item in summary"
                        :key="item.label"
                        class="flex min-w-0 flex-col gap-[3px]"
                    >
                        <dt
                            class="text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                        >
                            {{ item.label }}
                        </dt>
                        <dd class="flex min-w-0 items-center gap-1.5">
                            <BrandLogo
                                v-if="item.logo"
                                :name="machine.provider"
                                :size="14"
                            />
                            <span
                                class="truncate text-fg-primary"
                                :class="
                                    item.mono
                                        ? 'font-ui-mono text-[12.5px]/5'
                                        : 'text-[13px]/5 font-medium tracking-[-0.01em]'
                                "
                                :title="item.value"
                                >{{ item.value }}</span
                            >
                        </dd>
                    </div>
                </dl>
            </DashTray>

            <div class="flex flex-wrap items-stretch gap-4">
                <DashTray
                    icon="monitors-fill"
                    :title="$t('Live')"
                    class="flex-[1_1_440px]"
                    panel-class="justify-center px-[18px] py-3"
                >
                    <template v-if="cadence" #meta>
                        <span class="text-fg-tertiary">{{ cadence }}</span>
                    </template>
                    <div class="flex flex-wrap items-center gap-[18px]">
                        <GaugeDial
                            :value="cpuNow ?? 0"
                            :readout="
                                cpuNow === null ? '–' : `${Math.round(cpuNow)}%`
                            "
                            :label="$t('CPU')"
                            tone="blue"
                        />
                        <GaugeDial
                            :value="memory?.percent ?? 0"
                            :readout="memoryReadout"
                            :label="$t('Memory')"
                            tone="teal"
                        />
                        <div class="flex min-w-[150px] flex-1 flex-col">
                            <div
                                v-for="(row, index) in metrics"
                                :key="index"
                                class="flex"
                                :class="
                                    index === 0
                                        ? 'border-b-[0.5px] border-edge-row pb-3'
                                        : 'pt-3'
                                "
                            >
                                <div
                                    v-for="(cell, column) in row"
                                    :key="cell.label"
                                    class="flex min-w-0 flex-1 flex-col gap-0.5"
                                    :class="
                                        column === 0
                                            ? 'border-r-[0.5px] border-edge-row'
                                            : 'pl-3.5'
                                    "
                                >
                                    <span
                                        class="text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                                        >{{ cell.label }}</span
                                    >
                                    <span class="flex items-baseline gap-1">
                                        <span
                                            class="text-[18px]/6 font-medium tracking-[-0.02em] text-fg-primary tabular-nums"
                                            >{{ cell.value }}</span
                                        >
                                        <span
                                            v-if="cell.unit"
                                            class="text-[12px]/4 text-fg-tertiary"
                                            >{{ cell.unit }}</span
                                        >
                                    </span>
                                </div>
                            </div>
                        </div>
                    </div>
                    <p
                        v-if="lastReading"
                        class="mt-2.5 text-[12px]/4 text-fg-muted"
                    >
                        {{ lastReading }}
                    </p>
                </DashTray>

                <DashTray
                    v-if="place"
                    icon="globe-fill"
                    :title="$t('Location')"
                    class="min-h-[198px] flex-[1_1_262px] sm:max-w-[262px]"
                    panel-class="pt-3 pr-1.5 pl-[18px]"
                >
                    <template #meta>
                        <span class="text-fg-tertiary"
                            >{{ place.city }}, {{ place.country }}</span
                        >
                    </template>
                    <div class="min-h-0 flex-1">
                        <MachineGlobe
                            :lat="place.lat"
                            :lon="place.lon"
                            :label="`${machine.name} · ${regionCode}`"
                        />
                    </div>
                </DashTray>
            </div>

            <DashTray
                v-if="storage"
                icon="database-fill"
                :title="$t('Storage')"
                panel-class="px-[18px] pt-3 pb-3.5"
            >
                <template #meta>
                    <span class="text-fg-tertiary tabular-nums"
                        >{{ storage.size.used }} / {{ storage.size.total
                        }}<template v-if="storage.asOf">
                            · {{ storage.asOf }}</template
                        ></span
                    >
                </template>
                <template #actions>
                    <span class="flex shrink-0 items-center gap-2">
                        <span
                            class="text-[12.5px]/4 font-medium text-fg-secondary tabular-nums"
                            >{{ storage.percent }}%</span
                        >
                        <span
                            class="relative h-1 w-10 overflow-hidden rounded-[2px] bg-[#e6e6e6] dark:bg-[#2a2a2a]"
                            aria-hidden="true"
                        >
                            <span
                                class="absolute inset-y-0 left-0 rounded-[2px] bg-[#6b8cff]"
                                :style="{ width: `${storage.percent}%` }"
                            />
                        </span>
                    </span>
                </template>
                <div class="grid gap-y-4 sm:grid-cols-3">
                    <div
                        v-for="(volume, index) in volumes"
                        :key="volume.key"
                        class="flex min-w-0 flex-col gap-2.5"
                        :class="[
                            index > 0 ? 'sm:pl-4' : '',
                            index < volumes.length - 1
                                ? 'sm:border-r-[0.5px] sm:border-edge-row sm:pr-4'
                                : '',
                        ]"
                    >
                        <span
                            class="flex min-w-0 items-center gap-1.5 text-[12px]/4"
                        >
                            <span class="shrink-0 text-fg-tertiary">{{
                                volume.label
                            }}</span>
                            <span
                                class="truncate font-ui-mono text-[11px] text-fg-faint"
                                >{{ volume.detail }}</span
                            >
                        </span>
                        <span class="flex items-baseline gap-1 text-[13px]/5">
                            <span
                                class="text-[15px] font-medium tracking-[-0.01em] text-fg-primary tabular-nums"
                                >{{ volume.size.used }}</span
                            >
                            <span class="text-fg-tertiary"
                                >/ {{ volume.size.total }}</span
                            >
                        </span>
                        <div class="overflow-hidden">
                            <VolumeBars :percent="volume.percent" />
                        </div>
                    </div>
                </div>
            </DashTray>

            <DashTray
                icon="monitors-fill"
                :aria-label="$t('Usage history')"
                panel-class="gap-1.5 px-[18px] pt-3 pb-2.5"
            >
                <template #meta>
                    <span class="flex items-center gap-[9px]">
                        <span
                            class="flex gap-0.5 rounded-[7px] border-[0.5px] border-edge-row bg-surface-well p-0.5"
                            role="group"
                            :aria-label="$t('Metric')"
                        >
                            <button
                                v-for="key in ['cpu', 'mem', 'disk'] as const"
                                :key="key"
                                type="button"
                                class="flex h-[22px] cursor-pointer items-center rounded-[5px] px-[9px] text-[12px]/4 font-medium tracking-[-0.01em] focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                :class="
                                    metric === key
                                        ? 'border-[0.5px] border-[#dcdcdc] bg-white text-fg-primary shadow-card dark:border-[#363636] dark:bg-[#262626]'
                                        : 'text-fg-tertiary hover:text-fg-secondary'
                                "
                                :aria-pressed="metric === key"
                                @click="metric = key"
                            >
                                {{ metricLabels[key] }}
                            </button>
                        </span>
                        <DropdownMenu>
                            <DropdownMenuTrigger as-child>
                                <button
                                    type="button"
                                    class="flex cursor-pointer items-center gap-1 rounded-md text-[13px]/5 text-fg-tertiary hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                    :aria-busy="loadingRange || undefined"
                                >
                                    {{ rangeLabel }}
                                    <FilledIcon
                                        name="chevron-down"
                                        :size="12"
                                        class="text-fg-muted"
                                    />
                                </button>
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                class="ds-modal min-w-40 font-ui"
                                align="start"
                            >
                                <DropdownMenuItem
                                    v-for="option in ranges"
                                    :key="option.value"
                                    @select="emit('range', option.value)"
                                >
                                    {{ option.label }}
                                    <Check
                                        v-if="range === option.value"
                                        class="ml-auto size-3.5"
                                    />
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                    </span>
                </template>
                <template v-if="stats" #actions>
                    <span
                        class="shrink-0 font-ui-mono text-[10.5px]/3.5 tracking-[0.04em] text-fg-tertiary uppercase max-sm:hidden"
                        >{{ $t('Avg') }} {{ formatValue(stats.avg) }}</span
                    >
                    <span
                        class="shrink-0 font-ui-mono text-[10.5px]/3.5 tracking-[0.04em] text-fg-secondary uppercase"
                        >{{ $t('Peak') }} {{ formatValue(stats.peak) }}</span
                    >
                </template>
                <DotRidge
                    :values="series"
                    :timestamps="points.map((point) => point.ts)"
                    :max="scale"
                    :format="formatValue"
                    :ticks="ticks"
                    :long="range === '7d' || range === '30d'"
                    :label="
                        $t(':metric over the :range', {
                            metric: metricLabels[metric],
                            range: rangeLabel,
                        })
                    "
                />
                <p v-if="!stats" class="text-[12px]/4 text-fg-muted">
                    {{
                        $t(
                            'No readings in this stretch yet. Machines report every minute while they’re connected.',
                        )
                    }}
                </p>
            </DashTray>

            <DashTray
                icon="info-fill"
                :title="$t('System')"
                panel-class="gap-3 px-[18px] pt-3 pb-3.5"
            >
                <dl class="grid grid-cols-2 gap-x-4 gap-y-3 md:grid-cols-4">
                    <div
                        v-for="item in system"
                        :key="item.label"
                        class="flex min-w-0 flex-col gap-[3px]"
                    >
                        <dt class="text-[12px]/4 text-fg-tertiary">
                            {{ item.label }}
                        </dt>
                        <dd
                            class="truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                            :title="item.value"
                        >
                            {{ item.value }}
                        </dd>
                    </div>
                </dl>
                <div
                    v-if="machine.facts?.interfaces?.length"
                    class="flex flex-col"
                >
                    <span class="text-[12px]/4 text-fg-tertiary">{{
                        $t('Network interfaces')
                    }}</span>
                    <span
                        v-for="network in machine.facts.interfaces"
                        :key="network.name"
                        class="flex gap-3 border-b-[0.5px] border-edge-row py-1.5 font-ui-mono text-[12px]/4 last:border-b-0"
                    >
                        <span class="w-20 shrink-0 text-fg-secondary">{{
                            network.name
                        }}</span>
                        <span class="min-w-0 truncate text-fg-tertiary">{{
                            network.addresses.join(', ') || $t('No address')
                        }}</span>
                    </span>
                </div>
            </DashTray>

            <DashTray
                v-if="machine.id && canManage"
                icon="folder-fill"
                :title="$t('Disk space')"
                panel-class="px-[18px] pt-3 pb-3.5"
            >
                <DiskUsagePanel
                    :machine-id="machine.id"
                    :can-manage="canManage"
                    :online="online"
                />
            </DashTray>
            <DashTray
                v-if="
                    machine.id &&
                    canManage &&
                    (machine.inventory?.services ?? []).some((service) =>
                        service.key.startsWith('docker:'),
                    )
                "
                icon="package-fill"
                :title="$t('Docker images and volumes')"
                panel-class="px-[18px] pt-3 pb-3.5"
            >
                <DockerPanel
                    :machine-id="machine.id"
                    :can-manage="canManage"
                    :online="online"
                />
            </DashTray>
        </div>

        <aside
            class="flex w-full shrink-0 flex-col gap-[22px] border-edge-tabs p-5 max-xl:border-t-[0.5px] xl:w-[316px] xl:border-l-[0.5px]"
        >
            <section
                v-if="cloudServer"
                class="flex flex-col gap-2.5"
                :aria-label="
                    $t('Linked to :provider', {
                        provider: cloudServer.integration.name,
                    })
                "
            >
                <div class="flex items-center gap-2">
                    <h2
                        class="min-w-0 flex-1 truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-tertiary"
                    >
                        {{
                            $t('Linked to :provider', {
                                provider: cloudServer.integration.name,
                            })
                        }}
                    </h2>
                    <Link
                        v-if="cloudAccountUrl"
                        :href="cloudAccountUrl"
                        class="flex h-[26px] items-center gap-1 rounded-[7px] border-[0.5px] border-edge-control bg-surface-control pr-[7px] pl-[9px] text-[12px]/4 font-medium text-fg-secondary hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                    >
                        {{ $t('Account') }}
                        <FilledIcon
                            name="chevron-right"
                            class="text-fg-muted"
                        />
                    </Link>
                </div>
                <div class="flex items-center gap-2.5">
                    <BrandLogo :name="cloudServer.integration.provider" />
                    <span class="flex min-w-0 flex-col text-[13px]">
                        <span
                            class="truncate leading-[18px] font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ cloudServer.server.typeLabel }} ·
                            {{
                                cloudServer.server.city ??
                                cloudServer.server.location
                            }}</span
                        >
                        <span class="truncate leading-5 text-fg-tertiary"
                            >{{ cloudServer.server.name }} ·
                            {{ cloudServer.integration.label }}</span
                        >
                    </span>
                </div>
                <OperationSteps
                    v-if="cloudBusy && cloudServer.operation"
                    :operation="cloudServer.operation"
                />
                <div
                    v-if="canManage && cloudServer.can.includes('power')"
                    class="flex flex-wrap gap-1.5"
                >
                    <button
                        v-for="item in cloudButtons"
                        :key="item.key"
                        type="button"
                        class="flex h-[26px] cursor-pointer items-center rounded-[7px] border-[0.5px] border-edge-control bg-surface-control px-[9px] text-[12px]/4 font-medium text-fg-secondary hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:cursor-default disabled:opacity-50"
                        :disabled="cloudBusy && item.key !== 'snapshots'"
                        @click="emit('server', item.key)"
                    >
                        {{ item.label }}
                    </button>
                </div>
            </section>

            <section class="flex flex-col gap-3">
                <div class="flex items-center gap-2">
                    <h2
                        class="flex-1 text-[13px]/5 font-medium tracking-[-0.01em] text-fg-tertiary"
                    >
                        {{ $t('Usage') }}
                    </h2>
                    <button
                        type="button"
                        class="flex h-[26px] cursor-pointer items-center gap-1 rounded-[7px] border-[0.5px] border-edge-control bg-surface-control pr-[7px] pl-[9px] text-[12px]/4 font-medium text-fg-secondary hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        @click="resize"
                    >
                        {{ $t('Resize') }}
                        <FilledIcon
                            name="chevron-right"
                            class="text-fg-muted"
                        />
                    </button>
                </div>
                <div
                    v-for="item in usage"
                    :key="item.key"
                    class="flex items-start gap-3"
                    :title="'hint' in item ? item.hint : undefined"
                >
                    <span class="pt-px">
                        <UsageRing
                            :percent="item.percent"
                            :color="item.color"
                        />
                    </span>
                    <span class="flex min-w-0 flex-col text-[13px]">
                        <span
                            class="leading-[18px] font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ item.title }}</span
                        >
                        <span class="flex gap-1 leading-5">
                            <span class="text-fg-secondary">{{
                                item.value
                            }}</span>
                            <span class="truncate text-fg-tertiary">{{
                                item.of
                            }}</span>
                        </span>
                    </span>
                </div>
            </section>

            <nav
                class="flex flex-col gap-0.5 border-y-[0.5px] border-edge-tabs py-2.5"
                :aria-label="$t('On this machine')"
            >
                <template v-for="link in links" :key="link.key">
                    <Link
                        v-if="link.href"
                        :href="link.href"
                        class="group flex h-[34px] items-center gap-2.5 rounded-[8px] px-2.5 text-[13px]/5 hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-(--ds-ring) dark:hover:bg-[#222]"
                    >
                        <FilledIcon :name="link.icon" class="text-fg-muted" />
                        <span
                            class="font-medium tracking-[-0.01em] text-fg-secondary group-hover:text-fg-primary"
                            >{{ link.label }}</span
                        >
                        <FilledIcon
                            name="chevron-right"
                            class="text-fg-muted"
                        />
                        <span class="text-fg-tertiary">{{ link.detail }}</span>
                    </Link>
                    <button
                        v-else
                        type="button"
                        class="group flex h-[34px] cursor-pointer items-center gap-2.5 rounded-[8px] px-2.5 text-left text-[13px]/5 hover:bg-surface-hover focus-visible:outline-2 focus-visible:outline-(--ds-ring) dark:hover:bg-[#222]"
                        @click="link.tab && emit('tab', link.tab)"
                    >
                        <FilledIcon :name="link.icon" class="text-fg-muted" />
                        <span
                            class="font-medium tracking-[-0.01em] text-fg-secondary group-hover:text-fg-primary"
                            >{{ link.label }}</span
                        >
                        <FilledIcon
                            name="chevron-right"
                            class="text-fg-muted"
                        />
                        <span class="text-fg-tertiary">{{ link.detail }}</span>
                    </button>
                </template>
            </nav>

            <section class="flex flex-col gap-3">
                <div class="flex items-center gap-2">
                    <h2
                        class="flex-1 text-[13px]/5 font-medium tracking-[-0.01em] text-fg-tertiary"
                    >
                        {{ $t('Backups') }}
                    </h2>
                    <button
                        type="button"
                        class="flex h-[26px] cursor-pointer items-center gap-1 rounded-[7px] border-[0.5px] border-edge-control bg-surface-control pr-[7px] pl-[9px] text-[12px]/4 font-medium text-fg-secondary hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        @click="emit('tab', 'backups')"
                    >
                        {{ $t('All backups') }}
                        <FilledIcon
                            name="chevron-right"
                            class="text-fg-muted"
                        />
                    </button>
                </div>
                <div v-if="backingUp" class="flex items-center gap-2.5">
                    <svg
                        viewBox="0 0 16 16"
                        class="size-4 shrink-0 animate-spin motion-reduce:animate-none"
                        aria-hidden="true"
                    >
                        <circle
                            cx="8"
                            cy="8"
                            r="6.88"
                            fill="none"
                            stroke-width="2.24"
                            class="stroke-[#e4e4e4] dark:stroke-[#262626]"
                        />
                        <path
                            d="M8 1.12a6.88 6.88 0 0 1 6.88 6.88"
                            fill="none"
                            stroke="#3d63ff"
                            stroke-width="2.24"
                        />
                    </svg>
                    <span class="text-[13px]/5 text-fg-tertiary">{{
                        $t('Backup in progress…')
                    }}</span>
                </div>
                <div
                    v-for="run in backupRuns"
                    :key="run.at"
                    class="flex items-center gap-2.5"
                >
                    <FilledIcon
                        name="activity-fill"
                        :class="run.failed ? 'text-[#f0695a]' : 'text-fg-muted'"
                    />
                    <span
                        class="min-w-0 flex-1 truncate text-[13px]/5 text-fg-secondary"
                        >{{ runLabel(run.at) }}</span
                    >
                    <span
                        v-if="run.failed === run.total"
                        class="text-[12px]/4 text-[#f0695a]"
                        >{{ $t('Failed') }}</span
                    >
                    <span
                        v-else
                        class="font-ui-mono text-[12px]/4 text-fg-tertiary"
                        >{{ bytesLabel(run.bytes) }}</span
                    >
                </div>
                <p
                    v-if="!backingUp && !backupRuns.length"
                    class="text-[13px]/5 text-fg-muted"
                >
                    {{
                        overview.backup.schedule === 'off'
                            ? $t('No backups yet. Turn them on in Backups.')
                            : $t('No backups yet.')
                    }}
                </p>
            </section>
        </aside>
    </div>
</template>
