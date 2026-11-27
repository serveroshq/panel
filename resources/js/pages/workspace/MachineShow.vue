<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Ellipsis, Plus } from '@lucide/vue';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTab from '@/components/dash/DashTab.vue';
import DashTabs from '@/components/dash/DashTabs.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import type { MachineCloudServer } from '@/components/integrations/integrations';
import FileBrowser from '@/components/machines/FileBrowser.vue';
import type { FilePlace } from '@/components/machines/FileBrowser.vue';
import FirewallPanel from '@/components/machines/FirewallPanel.vue';
import JobLogDialog from '@/components/machines/JobLogDialog.vue';
import MachineAccessTab from '@/components/machines/access/MachineAccessTab.vue';
import type { MachineAccess } from '@/components/machines/access/access';
import type { DaemonJob } from '@/components/machines/JobLogDialog.vue';
import MachineBackupsTab from '@/components/machines/MachineBackupsTab.vue';
import MachineConsole from '@/components/machines/MachineConsole.vue';
import MachineIssues from '@/components/machines/MachineIssues.vue';
import MachineOverview from '@/components/machines/MachineOverview.vue';
import type {
    MachineLive,
    MachineOverviewData,
    MachineTraffic,
    UsagePoint,
} from '@/components/machines/MachineOverview.vue';
import MachineServicesTab from '@/components/machines/MachineServicesTab.vue';
import type { ScheduledTask } from '@/components/machines/MachineServicesTab.vue';
import MachineUpdatesTab from '@/components/machines/MachineUpdatesTab.vue';
import MachineReadme from '@/components/machines/readme/MachineReadme.vue';
import type { MachineReadmeData } from '@/components/machines/readme/readme';
import SecurityScan from '@/components/machines/SecurityScan.vue';
import type {
    ServiceGroup,
    ServiceStat,
} from '@/components/machines/ServicesTable.vue';
import UpdatesDialog from '@/components/machines/UpdatesDialog.vue';
import { buildingServices as findBuilding } from '@/components/machines/buildingServices';
import type { BuildingService } from '@/components/machines/buildingServices';
import { systemName } from '@/components/machines/machineFormat';
import { environmentLabels } from '@/components/machines/machines';
import type { Machine } from '@/components/machines/machines';
import MaintenanceBanner from '@/components/maintenance/MaintenanceBanner.vue';
import MachineServerActions from '@/components/machines/server/MachineServerActions.vue';
import type { ServerAction } from '@/components/machines/server/MachineServerActions.vue';
import MaintenanceDialog from '@/components/maintenance/MaintenanceDialog.vue';
import PlannedMaintenance from '@/components/maintenance/PlannedMaintenance.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import type { ServiceTemplate } from '@/components/workspace/DeployServiceDialog.vue';
import { usePathTab } from '@/composables/usePathTab';
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { useWhenVisible } from '@/composables/useWhenVisible';
import { describe, errorMessage } from '@/lib/errors';
import type { HealthIssue } from '@/lib/healthIssues';
import { toast } from '@/lib/toast';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import type { Workspace, WorkspaceAccess } from '@/types';

const DeployServiceDialog = defineAsyncComponent(
    () => import('@/components/workspace/DeployServiceDialog.vue'),
);

const props = defineProps<{
    workspace: Workspace;
    machine: Machine & { serviceStats?: Record<string, ServiceStat> };
    jobs: DaemonJob[];
    deployEnabled: boolean;
    deployWorkflows: { available: boolean; count: number };
    templates: ServiceTemplate[];
    serviceGroups: ServiceGroup[];
    scheduled: ScheduledTask[];
    recent: UsagePoint[];
    day: UsagePoint[];
    live: MachineLive;
    traffic: MachineTraffic | null;
    overview: MachineOverviewData;
    range: string;
    history?: UsagePoint[];
    files: { places: FilePlace[]; maxWrite: number; maxRead: number };
    consoleLock: {
        twoFactorEnabled: boolean;
        unlockedUntil: string | null;
        minutes: number;
    };
    issues?: HealthIssue[];
    access?: MachineAccess;
    defaultTab?: 'overview' | 'readme';
    readme?: MachineReadmeData;
    cloudServer?: MachineCloudServer | null;
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const serverActions =
    useTemplateRef<InstanceType<typeof MachineServerActions>>('serverActions');

function serverAction(action: ServerAction): void {
    serverActions.value?.open(action);
}
const upgrade = useUpgradeLink();

const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);
const online = computed(
    () =>
        props.machine.connection === 'online' ||
        props.machine.connection === 'degraded',
);

const slug = computed(() => props.workspace.slug);
const machineBase = computed(
    () =>
        onWorkspace(
            machinesRoutes.show({
                workspace: slug.value,
                machine: props.machine.id!,
            }),
        ).url,
);
const machinesUrl = computed(
    () => onWorkspace(machinesRoutes.index(slug.value)).url,
);
const settingsUrl = computed(
    () =>
        onWorkspace(
            machinesRoutes.settings({
                workspace: slug.value,
                machine: props.machine.id!,
            }),
        ).url,
);

type TabKey =
    | 'overview'
    | 'readme'
    | 'services'
    | 'system-updates'
    | 'backups'
    | 'firewall'
    | 'access'
    | 'terminal';

const ALIASES: Record<string, TabKey> = {
    scheduled: 'services',
    deployments: 'services',
    usage: 'overview',
    specs: 'overview',
    ports: 'firewall',
    files: 'terminal',
};

const tab = usePathTab(
    machineBase.value,
    [
        'overview',
        'readme',
        'services',
        'system-updates',
        'backups',
        'firewall',
        'access',
        'terminal',
        'scheduled',
        'deployments',
        'usage',
        'specs',
        'ports',
        'files',
    ] as const,
    props.defaultTab ?? 'overview',
);

const current = computed<TabKey>(
    () => ALIASES[tab.value] ?? (tab.value as TabKey),
);

const tabs = computed<{ key: TabKey; label: string; icon: FilledIconName }[]>(
    () => [
        { key: 'overview', label: trans('Overview'), icon: 'monitors-fill' },
        { key: 'readme', label: trans('Readme'), icon: 'docs-fill' },
        { key: 'services', label: trans('Services'), icon: 'layers-fill' },
        {
            key: 'system-updates',
            label: trans('Updates'),
            icon: 'update-fill',
        },
        { key: 'backups', label: trans('Backups'), icon: 'activity-fill' },
        { key: 'firewall', label: trans('Firewall'), icon: 'settings-fill' },
        { key: 'access', label: trans('Access'), icon: 'key-fill' },
        { key: 'terminal', label: trans('Terminal'), icon: 'terminal-fill' },
    ],
);

function go(key: TabKey | 'updates'): void {
    tab.value = key === 'updates' ? 'system-updates' : key;
    document.querySelector('main [data-machine-tabs]')?.scrollIntoView({
        block: 'nearest',
    });
}

watch(
    tab,
    (value) => {
        const target = ALIASES[value];
        if (!target) return;
        const anchor = value === 'deployments' || value === 'scheduled';
        tab.value = target;
        if (anchor && typeof document !== 'undefined') {
            requestAnimationFrame(() =>
                document
                    .getElementById(value)
                    ?.scrollIntoView({ block: 'start' }),
            );
        }
    },
    { immediate: true },
);

const status = computed<{ status: DashStatus; label: string }>(() => {
    if (props.machine.maintenance)
        return { status: 'pending', label: trans('In maintenance') };
    if (props.machine.setup)
        return {
            status: 'deploying',
            label: {
                connecting: trans('Connecting'),
                discovering: trans('Discovering'),
                securing: trans('Securing'),
            }[props.machine.setup],
        };
    switch (props.machine.connection) {
        case 'online':
        case 'demo':
            return { status: 'live', label: trans('Live') };
        case 'degraded':
            return { status: 'pending', label: trans('Delayed') };
        case 'connecting':
            return { status: 'deploying', label: trans('Connecting') };
        default:
            return { status: 'down', label: trans('Offline') };
    }
});

const regionCode = computed(
    () => props.machine.region ?? props.machine.facts?.hosting?.region ?? null,
);
const chips = computed(() =>
    [
        { key: 'env', label: environmentLabels[props.machine.environment] },
        {
            key: 'provider',
            label: [
                props.machine.provider === 'Other'
                    ? trans('Other')
                    : props.machine.provider,
                regionCode.value,
            ]
                .filter(Boolean)
                .join(' · '),
            logo: props.machine.provider,
        },
        { key: 'os', label: systemName(props.machine, true) },
    ].filter((chip): chip is { key: string; label: string; logo?: string } =>
        Boolean(chip.label),
    ),
);

const consoleContainers = computed(() =>
    (props.machine.inventory?.services ?? [])
        .filter(
            (service) => service.key.startsWith('docker:') && !service.hidden,
        )
        .map((service) => ({
            key: service.key,
            name: service.label ?? service.app ?? service.found ?? service.name,
        })),
);

const removingKeys = computed(
    () =>
        new Set(
            props.jobs
                .filter(
                    (job) =>
                        job.kind === 'service_remove' &&
                        !!job.service &&
                        ['queued', 'accepted', 'running', 'succeeded'].includes(
                            job.state,
                        ) &&
                        Date.now() - new Date(job.createdAt ?? 0).getTime() <
                            10 * 60_000,
                )
                .map((job) => job.service as string),
        ),
);
const listedServices = computed(() =>
    (props.machine.inventory?.services ?? []).filter(
        (service) => !removingKeys.value.has(service.key),
    ),
);
const buildingServices = computed<BuildingService[]>(() =>
    findBuilding(
        props.jobs,
        props.machine.inventory?.services ?? [],
        props.machine.inventory?.scanned_at,
    ),
);
const visibleServiceCount = computed(
    () => listedServices.value.filter((service) => !service.hidden).length,
);

const hasPlan = computed(
    () =>
        props.workspace.billing.plan !== null ||
        props.workspace.billing.selfHosted === true,
);
const canDeploy = computed(
    () =>
        canManage.value && props.deployEnabled && online.value && hasPlan.value,
);
const deployBlocked = computed<string | null>(() => {
    if (!hasPlan.value) return 'plan';
    if (!canManage.value)
        return trans('You need permission to manage machines to deploy here.');
    if (!props.deployEnabled)
        return trans('Deploys aren’t set up on this panel yet.');
    if (!online.value) return trans('The machine is offline.');

    return null;
});

const showDeploy = ref(false);
const deployTemplate = ref<string | null>(null);
const scanning = ref(false);
const updatesOpen = ref(false);
const maintenanceOpen = ref(false);
const metric = ref<'cpu' | 'mem' | 'disk'>('cpu');

const logJobId = ref<string | null>(null);
const logOpen = ref(false);
const logJob = computed(
    () => props.jobs.find((job) => job.id === logJobId.value) ?? null,
);

function openLog(jobId: string): void {
    logJobId.value = jobId;
    logOpen.value = true;
}

function onRetried(jobId: string): void {
    logJobId.value = jobId;
    refresh();
}

function openLibraryFromLog(): void {
    logOpen.value = false;
    showDeploy.value = true;
}

function onDeployed(): void {
    tab.value = 'services';
    refresh();
}

const loadingHistory = ref(false);

function loadHistory(range: string): void {
    router.get(
        window.location.pathname,
        { range },
        {
            only: ['history', 'range'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (loadingHistory.value = true),
            onFinish: () => (loadingHistory.value = false),
        },
    );
}

function refresh(): void {
    router.reload({ only: ['machine', 'jobs', 'overview'] });
}

const removeInFlight = computed(() =>
    props.jobs.some(
        (job) =>
            job.kind === 'service_remove' &&
            ['queued', 'accepted', 'running'].includes(job.state),
    ),
);
let removePoll: ReturnType<typeof setInterval> | undefined;
watch(
    removeInFlight,
    (active) => {
        clearInterval(removePoll);
        removePoll = active ? setInterval(refresh, 1500) : undefined;
    },
    { immediate: true },
);

const refreshing = ref(false);
let refreshTries = 0;
let refreshPoll: ReturnType<typeof setTimeout> | undefined;

function reloadOnce(): Promise<void> {
    return new Promise((resolve) =>
        router.reload({ only: ['machine', 'jobs'], onFinish: () => resolve() }),
    );
}

async function refreshNow(): Promise<void> {
    if (refreshing.value || !props.machine.id) return;
    refreshing.value = true;
    const before = props.machine.inventory?.scanned_at ?? null;

    let note: string | null = null;
    try {
        const response = await fetch(
            onWorkspace(
                machinesRoutes.refresh({
                    workspace: slug.value,
                    machine: props.machine.id,
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
        const json = await response.json().catch(() => ({}));
        note = response.ok
            ? (json.note ?? null)
            : describe(response.status, json);
    } catch (cause) {
        note = errorMessage(cause);
    }

    if (note) {
        await reloadOnce();
        refreshing.value = false;
        toast.error(note);

        return;
    }

    refreshTries = 0;
    const check = async () => {
        await reloadOnce();
        if ((props.machine.inventory?.scanned_at ?? null) !== before) {
            refreshing.value = false;
            toast.success(trans('Services are up to date.'));
        } else if (++refreshTries >= 22) {
            refreshing.value = false;
            toast.error(
                trans(
                    'The scan is taking a while. It’ll show up here when it’s done.',
                ),
            );
        } else {
            refreshPoll = setTimeout(check, 2000);
        }
    };
    refreshPoll = setTimeout(check, 1500);
}

const jobsMoving = computed(() =>
    props.jobs.some((job) =>
        ['queued', 'accepted', 'running'].includes(job.state),
    ),
);
let ticks = 0;
let refreshTimer: ReturnType<typeof setInterval> | undefined;
const whenVisible = useWhenVisible();

const READING_EVERY = 60_000;
let readingSeenAt = Date.now();
let readingMoved = false;
watch(
    () => props.live?.at,
    () => {
        readingSeenAt = Date.now();
        readingMoved = true;
    },
);
watch(current, (key) => {
    if (key !== 'overview') return;
    readingSeenAt = Date.now();
    readingMoved = false;
});

function readingDue(): boolean {
    const waited = Date.now() - readingSeenAt;
    if (!online.value || waited > 2 * READING_EVERY) return ticks % 6 === 0;

    return !readingMoved || waited >= READING_EVERY - 5_000;
}

function tick(): void {
    ticks += 1;
    const only = new Set<string>();
    if (current.value === 'overview' && readingDue()) {
        only.add('live');
        only.add('traffic');
    }
    if (jobsMoving.value || ticks % 3 === 0) {
        ['machine', 'jobs', 'overview'].forEach((key) => only.add(key));
    }
    if (ticks % 12 === 0 && current.value === 'overview') {
        only.add('day');
        if (props.range !== '24h' && props.history) only.add('history');
    }
    if (only.size && !refreshing.value && !loadingHistory.value) {
        router.reload({ only: [...only] });
    }
}

function catchUp(): void {
    ticks = 0;
    router.reload({
        only: ['machine', 'jobs', 'overview', 'live', 'traffic', 'day'],
    });
}

onMounted(() => {
    refreshTimer = setInterval(() => {
        if (document.visibilityState === 'visible') tick();
        else whenVisible(catchUp);
    }, 5000);

    const query = new URLSearchParams(window.location.search);
    if (query.has('console')) tab.value = 'terminal';
    if (query.has('deploy') && canDeploy.value) {
        deployTemplate.value = query.get('deploy') || null;
        showDeploy.value = true;
    }
    const job = query.get('job');
    if (job) {
        tab.value = 'services';
        openLog(job);
    }
});

onBeforeUnmount(() => {
    clearTimeout(refreshPoll);
    clearInterval(removePoll);
    clearInterval(refreshTimer);
});
</script>

<template>
    <Head :title="machine.name" />

    <DashHeader
        :crumbs="[
            { label: $t('Machines'), href: machinesUrl },
            {
                label: machine.name,
                logo: machine.iconUrl ? undefined : machine.provider,
                image: machine.iconUrl,
                href:
                    current === 'access' || current === 'readme'
                        ? machineBase
                        : undefined,
            },
            ...(current === 'access' ? [{ label: $t('Access') }] : []),
            ...(current === 'readme' ? [{ label: $t('Readme') }] : []),
        ]"
    >
        <template #actions>
            <DropdownMenu v-if="machine.id">
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
                    <DropdownMenuItem @select="refreshNow">
                        <FilledIcon name="history" />
                        {{ $t('Scan services again') }}
                    </DropdownMenuItem>
                    <template v-if="canManage">
                        <DropdownMenuItem @select="scanning = true">
                            <FilledIcon name="lock-fill" />
                            {{ $t('Security scan') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="machine.uid"
                            @select="updatesOpen = true"
                        >
                            <FilledIcon name="update-fill" />
                            {{ $t('System updates') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="maintenanceOpen = true">
                            <FilledIcon name="sliders" />
                            {{ $t('Maintenance') }}
                        </DropdownMenuItem>
                    </template>
                    <template v-if="canManage && cloudServer">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            v-if="cloudServer.can.includes('resize')"
                            @select="serverAction('resize')"
                        >
                            <FilledIcon name="layers" />
                            {{ $t('Resize') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="cloudServer.can.includes('snapshots')"
                            @select="serverAction('snapshots')"
                        >
                            <FilledIcon name="archive" />
                            {{ $t('Snapshots') }}
                        </DropdownMenuItem>
                        <template v-if="cloudServer.can.includes('power')">
                            <DropdownMenuItem @select="serverAction('reboot')">
                                <FilledIcon name="history" />
                                {{
                                    $t('Restart through :provider', {
                                        provider: cloudServer.integration.name,
                                    })
                                }}
                            </DropdownMenuItem>
                            <DropdownMenuItem
                                @select="serverAction('power_cycle')"
                            >
                                <FilledIcon name="activity-fill" />
                                {{ $t('Power cycle') }}
                            </DropdownMenuItem>
                        </template>
                        <DropdownMenuItem
                            v-if="cloudServer.can.includes('delete_servers')"
                            class="text-status-down"
                            @select="serverAction('delete')"
                        >
                            <FilledIcon name="box" />
                            {{
                                $t('Delete :provider server', {
                                    provider: cloudServer.integration.name,
                                })
                            }}
                        </DropdownMenuItem>
                    </template>
                    <DropdownMenuSeparator />
                    <DropdownMenuItem as-child>
                        <Link :href="settingsUrl">
                            <FilledIcon name="settings-fill" />
                            {{ $t('Settings') }}
                        </Link>
                    </DropdownMenuItem>
                </DropdownMenuContent>
            </DropdownMenu>
            <DashButton
                v-if="canManage"
                icon="terminal-fill"
                :label="$t('Terminal')"
                @click="go('terminal')"
            />
            <TooltipProvider :delay-duration="100">
                <Tooltip :disabled="deployBlocked === null">
                    <TooltipTrigger as-child>
                        <span
                            class="inline-flex"
                            :tabindex="deployBlocked === null ? undefined : 0"
                        >
                            <DashButton
                                variant="primary"
                                :icon="Plus"
                                :label="$t('Deploy service')"
                                :disabled="!canDeploy"
                                @click="showDeploy = true"
                            />
                        </span>
                    </TooltipTrigger>
                    <TooltipContent
                        side="bottom"
                        align="end"
                        class="max-w-64 px-3 py-2 text-left text-[13px]/5"
                    >
                        <template v-if="deployBlocked !== 'plan'">{{
                            deployBlocked
                        }}</template>
                        <template v-else>{{
                            $t(
                                'Your plan has ended, so deploys are paused. Everything already running keeps running.',
                            )
                        }}</template>
                        <Link
                            v-if="deployBlocked === 'plan' && upgrade"
                            :href="upgrade.href"
                            class="mt-1 block font-semibold underline underline-offset-2"
                            >{{ $t('Resubscribe in Billing') }}</Link
                        >
                    </TooltipContent>
                </Tooltip>
            </TooltipProvider>
        </template>
    </DashHeader>

    <div class="flex min-h-full flex-col">
        <div class="flex flex-wrap items-center gap-3 px-6 pt-5 pb-4">
            <img
                v-if="machine.iconUrl"
                :src="machine.iconUrl"
                alt=""
                class="size-7 shrink-0 rounded-[7px] border-[0.5px] border-edge-card object-cover"
            />
            <h1
                class="min-w-0 truncate text-[20px]/7 font-medium tracking-[-0.02em] text-fg-primary"
            >
                {{ machine.name }}
            </h1>
            <StatusBadge :status="status.status" :label="status.label" />
            <span class="grow" />
            <span
                v-for="chip in chips"
                :key="chip.key"
                class="flex h-[26px] items-center gap-1.5 rounded-full border-[0.5px] border-[#dcdcdc] bg-[#f4f4f4] px-2.5 text-[12px]/4 font-medium tracking-[-0.005em] whitespace-nowrap text-fg-secondary dark:border-[#303030] dark:bg-[#202020]"
            >
                <BrandLogo v-if="chip.logo" :name="chip.logo" :size="12" />
                {{ chip.label }}
            </span>
        </div>

        <DashTabs data-machine-tabs>
            <span v-for="item in tabs" :key="item.key" class="relative">
                <DashTab
                    :label="item.label"
                    :icon="item.icon"
                    :active="current === item.key"
                    @click="go(item.key)"
                />
                <span
                    v-if="
                        item.key === 'system-updates' && overview.updates.count
                    "
                    class="pointer-events-none absolute top-[11.5px] left-[20.5px] size-[9px] rounded-full border-[1.5px] border-surface-main bg-status-deploying"
                    :aria-label="
                        $t(':count updates waiting', {
                            count: String(overview.updates.count),
                        })
                    "
                />
            </span>
            <DashTab
                v-if="machine.id"
                :label="$t('Settings')"
                icon="settings-fill"
                :href="settingsUrl"
            />
        </DashTabs>

        <MachineOverview
            v-if="current === 'overview'"
            v-model:metric="metric"
            class="grow"
            :machine="machine"
            :workspace-slug="workspace.slug"
            :live="live"
            :traffic="traffic"
            :day="day"
            :history="history"
            :range="range"
            :loading-range="loadingHistory"
            :overview="overview"
            :services="visibleServiceCount"
            :can-manage="canManage"
            :online="online"
            @tab="go"
            :cloud-server="canManage ? cloudServer : null"
            @range="loadHistory"
            @server="serverAction"
        >
            <template #notices>
                <MaintenanceBanner
                    v-if="machine.maintenance && machine.id"
                    :until="machine.maintenance.until"
                    :reason="machine.maintenance.reason"
                    :end-url="
                        workspacePath(`/machines/${machine.id}/maintenance`)
                    "
                    :can-manage="canManage"
                />
                <MachineIssues v-if="issues?.length" :issues="issues" />
                <PlannedMaintenance
                    v-if="machine.id && machine.plannedMaintenance?.length"
                    :machine-id="machine.id"
                    :windows="machine.plannedMaintenance"
                    :can-manage="canManage"
                />
                <p
                    v-if="
                        machine.connection === 'offline' ||
                        machine.connection === 'connecting'
                    "
                    class="dash-card px-[18px] py-3 text-[13px]/5 text-fg-secondary"
                >
                    {{
                        machine.connection === 'offline'
                            ? $t(
                                  'The daemon is offline. This page shows what it last reported; reconnect it to deploy or get fresh readings.',
                              )
                            : $t(
                                  'The daemon is connecting. Readings show up here once it reports in.',
                              )
                    }}
                </p>
            </template>
        </MachineOverview>

        <MachineReadme
            v-else-if="current === 'readme' && machine.id"
            class="grow"
            :readme="readme"
            :machine-id="machine.id"
            :machine-name="machine.name"
            :workspace-slug="workspace.slug"
            :can-manage="canManage"
            @updates="updatesOpen = true"
        />

        <MachineServicesTab
            v-else-if="current === 'services'"
            :machine="machine"
            :services="listedServices"
            :building="buildingServices"
            :jobs="jobs"
            :service-groups="serviceGroups"
            :scheduled="scheduled"
            :deploy-enabled="deployEnabled"
            :deploy-workflows="deployWorkflows"
            :can-manage="canManage"
            :refreshing="refreshing"
            @refresh="refreshNow"
            @open-log="openLog"
        />

        <MachineUpdatesTab
            v-else-if="current === 'system-updates'"
            :updates="overview.updates"
            :can-manage="canManage"
            :connected="!!machine.uid"
            @review="updatesOpen = true"
            @scan="scanning = true"
        />

        <MachineBackupsTab
            v-else-if="current === 'backups' && machine.id"
            :machine-id="machine.id"
            :workspace-slug="workspace.slug"
            :backup="overview.backup"
            :backups="overview.backups"
            :can-manage="canManage"
            @changed="refresh"
        />

        <div v-else-if="current === 'firewall' && machine.id" class="px-6 py-4">
            <FirewallPanel
                :machine-id="machine.id"
                :machine-name="machine.name"
                :firewall="machine.firewall ?? null"
                :warnings="machine.inventory?.warnings ?? []"
                :can-manage="canManage"
                :online="online"
            />
        </div>

        <MachineAccessTab
            v-else-if="current === 'access' && machine.id && access"
            class="grow"
            :access="access"
            :workspace-slug="workspace.slug"
            :workspace-name="workspace.name"
            :machine-id="machine.id"
            :machine-name="machine.name"
            @tab="go"
        />

        <div
            v-else-if="current === 'terminal'"
            class="flex flex-col gap-6 px-6 py-4"
        >
            <MachineConsole
                v-if="canManage"
                inline
                :machine-id="machine.id ?? 0"
                :machine-name="machine.name"
                :containers="consoleContainers"
                :jobs="jobs"
                :console-lock="consoleLock"
            />
            <p
                v-else
                class="dash-card px-[18px] py-3 text-[13px]/5 text-fg-muted"
            >
                {{ $t('The terminal needs permission to manage machines.') }}
            </p>
            <section class="flex flex-col gap-3" :aria-label="$t('Files')">
                <h2
                    class="text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Files') }}
                </h2>
                <FileBrowser
                    :machine-id="machine.id ?? 0"
                    :places="files.places"
                    :max-write="files.maxWrite"
                    :max-read="files.maxRead"
                    :console-lock="consoleLock"
                    :available="canManage && deployEnabled && online"
                    empty-hint="ServerOS can open the folders of services it deployed or adopted, and there aren’t any on this machine yet. Deploy or adopt a service to browse its files here."
                />
            </section>
        </div>
    </div>

    <Dialog v-model:open="scanning">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-xl"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('Security scan') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'What the daemon sees on :name: ports open to the internet, waiting security updates, services and the system.',
                            { name: machine.name },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <SecurityScan
                v-if="scanning && machine.id"
                :machine-id="machine.id"
            />
        </DialogContent>
    </Dialog>
    <UpdatesDialog
        v-if="machine.id && canManage"
        v-model:open="updatesOpen"
        :machine-id="machine.id"
        :machine-name="machine.name"
    />
    <JobLogDialog
        v-if="machine.id"
        v-model:open="logOpen"
        :job="logJob"
        :machine-id="machine.id"
        :can-manage="canManage"
        @retried="onRetried"
        @changed="refresh"
        @open-library="openLibraryFromLog"
    />
    <DeployServiceDialog
        v-if="machine.id"
        v-model:open="showDeploy"
        :templates="templates"
        :machine-id="machine.id"
        :start-with="deployTemplate"
        @deployed="onDeployed"
    />
    <MaintenanceDialog
        v-if="machine.id"
        v-model:open="maintenanceOpen"
        :machine-id="machine.id"
        :machine-name="machine.name"
    />
    <MachineServerActions
        v-if="machine.id && canManage && cloudServer"
        ref="serverActions"
        :machine="{ id: machine.id, name: machine.name }"
        :cloud="cloudServer"
    />
</template>
