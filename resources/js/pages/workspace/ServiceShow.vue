<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { Ellipsis, Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import AppAutoDeploy from '@/components/apps/AppAutoDeploy.vue';
import AppBuildSettings from '@/components/apps/AppBuildSettings.vue';
import AppDeployHistory from '@/components/apps/AppDeployHistory.vue';
import AppDeployMenu from '@/components/apps/AppDeployMenu.vue';
import AppDeployStatus from '@/components/apps/AppDeployStatus.vue';
import AppEnvironment from '@/components/apps/AppEnvironment.vue';
import AppJobLog from '@/components/apps/AppJobLog.vue';
import AppSchedules from '@/components/apps/AppSchedules.vue';
import type {
    AppDeploy,
    DeployJob,
    DeployRow,
    SettingsKey,
} from '@/components/apps/appTypes';
import { isFailed, shortSha } from '@/components/apps/appTypes';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTab from '@/components/dash/DashTab.vue';
import DashTabs from '@/components/dash/DashTabs.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import InputError from '@/components/InputError.vue';
import AppLimitsDialog from '@/components/machines/AppLimitsDialog.vue';
import AppSiteDialog from '@/components/machines/AppSiteDialog.vue';
import AppVariablesDialog from '@/components/machines/AppVariablesDialog.vue';
import CloneServiceDialog from '@/components/machines/CloneServiceDialog.vue';
import DatabaseBrowser from '@/components/machines/DatabaseBrowser.vue';
import ExistingSitesTraffic from '@/components/machines/ExistingSitesTraffic.vue';
import type { ExistingSitesTrafficProps } from '@/components/machines/ExistingSitesTraffic.vue';
import FileBrowser from '@/components/machines/FileBrowser.vue';
import type { FilePlace } from '@/components/machines/FileBrowser.vue';
import type { Machine } from '@/components/machines/machines';
import type { Service, ServiceStat } from '@/components/machines/serviceFormat';
import SiteTraffic from '@/components/machines/SiteTraffic.vue';
import DeploySettingsDialog from '@/components/service/DeploySettingsDialog.vue';
import ServiceCharts from '@/components/service/ServiceCharts.vue';
import ServiceConsole from '@/components/service/ServiceConsole.vue';
import ServiceDeploys from '@/components/service/ServiceDeploys.vue';
import ServiceDetails from '@/components/service/ServiceDetails.vue';
import ServiceDomain from '@/components/service/ServiceDomain.vue';
import ServiceLogs from '@/components/service/ServiceLogs.vue';
import ServiceRail from '@/components/service/ServiceRail.vue';
import ServiceUsage from '@/components/service/ServiceUsage.vue';
import ServiceVariables from '@/components/service/ServiceVariables.vue';
import type {
    AppVersion,
    LinkedDatabase,
    LogSummary,
    ServiceApp,
    ServiceStack,
    ServiceUsage as Usage,
    UsagePoint,
} from '@/components/service/serviceTypes';
import { statusOf } from '@/components/service/serviceTypes';
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
import { Input } from '@/components/ui/input';
import AppButton from '@/components/workspace/AppButton.vue';
import ContainerConfig from '@/components/workspace/ContainerConfig.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import ImageUploadField from '@/components/workspace/ImageUploadField.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import { usePathTab } from '@/composables/usePathTab';
import { useZipUpload } from '@/composables/useZipUpload';
import { describe, errorMessage, jobReason, reasonIn } from '@/lib/errors';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import appsRoutes from '@/routes/workspace/apps';
import fixesRoutes from '@/routes/workspace/fixes';
import logsRoutes from '@/routes/workspace/logs';
import machinesRoutes from '@/routes/workspace/machines';
import servicesRoutes from '@/routes/workspace/services';
import type { Workspace, WorkspaceAccess } from '@/types';

type ServiceJob = {
    id: string;
    kind: string;
    action: string | null;
    command: string | null;
    state: string;
    log: string[];
    result: Record<string, unknown> | null;
    error: { message?: string; next_step?: string } | null;
    createdAt: string | null;
};

const props = defineProps<{
    workspace: Workspace;
    machine: Machine;
    service: Service & {
        managed: boolean;
        container: boolean;
        missing?: boolean;
    };
    brand: { slug: string; name: string } | null;
    stack: ServiceStack;
    linked: LinkedDatabase;
    usage: Usage;
    logs: LogSummary;
    messages?: Record<string, string>;
    stats: ServiceStat | null;
    removable: boolean;
    inspect: { available: boolean } | null;
    database: { engine: 'postgres' | 'mysql' } | null;
    clone: { unavailable: string | null; name: string; port: number } | null;
    links: string[];
    range: string;
    history: UsagePoint[];
    jobs: ServiceJob[];
    commandsEnabled: boolean;
    files: { places: FilePlace[]; maxWrite: number; maxRead: number };
    consoleLock: {
        twoFactorEnabled: boolean;
        unlockedUntil: string | null;
        minutes: number;
    };
    app: ServiceApp | null;
    deploy?: AppDeploy | null;
    deployHistory?: DeployRow[];
    siteTraffic: ExistingSitesTrafficProps | null;
}>();

// The app's deploy setup, replaced straight away by what a save answers.
const deployState = ref<AppDeploy | null>(props.deploy ?? null);
watch(
    () => props.deploy,
    (next) => (deployState.value = next ?? null),
);
const missing = computed(() => props.service.missing === true);

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);
const adoptedNow = ref(false);
const managed = computed(() => props.service.managed || adoptedNow.value);
const online = computed(
    () =>
        props.machine.connection === 'online' ||
        props.machine.connection === 'degraded',
);
const hasPlan = computed(
    () =>
        props.workspace.billing.plan !== null ||
        props.workspace.billing.selfHosted === true,
);
const canControl = computed(
    () =>
        canManage.value &&
        props.commandsEnabled &&
        online.value &&
        managed.value,
);
const machineArgs = computed(() => ({
    workspace: props.workspace.slug,
    machine: props.machine.id!,
}));
const routeKey = computed(() =>
    props.service.app ? `app:${props.service.app}` : props.service.key,
);
const serviceArgs = computed(() => ({
    ...machineArgs.value,
    service: routeKey.value,
}));
const appArgs = computed(() => ({
    ...machineArgs.value,
    service: props.app?.service ?? '',
}));
const machineUrl = computed(
    () => onWorkspace(machinesRoutes.show(machineArgs.value)).url,
);
const servicesUrl = computed(
    () => onWorkspace(servicesRoutes.index(props.workspace.slug)).url,
);
const serviceUrl = computed(
    () => onWorkspace(machinesRoutes.services.show(serviceArgs.value)).url,
);
const serviceIconUrl = computed(
    () =>
        onWorkspace(machinesRoutes.services.icon.update(serviceArgs.value)).url,
);
const actionUrl = computed(
    () => onWorkspace(machinesRoutes.services.action(serviceArgs.value)).url,
);

function jobUrl(job: string): string {
    return onWorkspace(
        machinesRoutes.show(machineArgs.value, { query: { job } }),
    ).url;
}

const displayName = computed(
    () =>
        props.service.label ??
        props.service.app ??
        props.service.found ??
        props.service.name,
);

const logo = computed(() =>
    props.brand?.slug
        ? props.brand.slug
        : `${props.service.name} ${props.service.details?.image ?? ''}`,
);

const chips = computed(() => {
    const list: {
        key: string;
        label: string;
        logo?: string;
        icon?: FilledIconName;
        href?: string;
    }[] = [];
    if (props.stack) {
        list.push(
            props.stack.kind === 'compose'
                ? {
                      key: 'stack',
                      label: trans(':name stack', { name: props.stack.name }),
                      logo: 'docker',
                  }
                : {
                      key: 'stack',
                      label: trans(':name project', {
                          name: props.stack.name,
                      }),
                      icon: 'projects-fill',
                      href: props.stack.url ?? undefined,
                  },
        );
    }
    list.push({
        key: 'machine',
        label: [
            props.machine.name,
            props.machine.provider === 'Other' ? null : props.machine.provider,
        ]
            .filter(Boolean)
            .join(' · '),
        logo: props.machine.provider,
        href: machineUrl.value,
    });
    if (props.service.details?.image) {
        list.push({ key: 'image', label: props.service.details.image });
    }

    return list;
});

const renaming = ref(false);
const newLabel = ref('');
const renameBusy = ref(false);
const renameError = ref<string | null>(null);

function openRename(): void {
    newLabel.value = props.service.label ?? '';
    renameError.value = null;
    renaming.value = true;
}

function saveLabel(label: string | null): void {
    renameBusy.value = true;
    router.put(
        onWorkspace(machinesRoutes.services.rename(serviceArgs.value)).url,
        { label },
        {
            preserveScroll: true,
            onSuccess: () => {
                renaming.value = false;
                toast.success(
                    label
                        ? trans('Renamed to :label.', { label: String(label) })
                        : trans('Back to its original name.'),
                );
            },
            onError: (errors) =>
                (renameError.value =
                    errors.label ?? trans('Couldn’t rename it.')),
            onFinish: () => (renameBusy.value = false),
        },
    );
}

const TAB_KEYS = [
    'overview',
    'deploys',
    'settings',
    'traffic',
    'logs',
    'console',
    'files',
    'database',
    'details',
] as const;
type TabKey = (typeof TAB_KEYS)[number];

const tab = usePathTab(
    serviceUrl.value,
    TAB_KEYS,
    props.service.missing ? 'deploys' : 'overview',
);

type TabItem = { key: TabKey; label: string; icon: FilledIconName };

const appTabs = computed<TabItem[]>(() =>
    deployState.value
        ? [
              { key: 'deploys', label: trans('Deploys'), icon: 'history' },
              {
                  key: 'settings',
                  label: trans('Settings'),
                  icon: 'settings-fill',
              },
          ]
        : [],
);

const tabs = computed(() => {
    const list: TabItem[] = missing.value
        ? appTabs.value
        : [
              {
                  key: 'overview',
                  label: trans('Overview'),
                  icon: 'monitors-fill',
              },
              ...appTabs.value,
              ...(props.app || props.siteTraffic
                  ? [
                        {
                            key: 'traffic' as const,
                            label: trans('Traffic'),
                            icon: 'globe-fill' as const,
                        },
                    ]
                  : []),
              { key: 'logs', label: trans('Logs'), icon: 'file-fill' },
              { key: 'files', label: trans('Files'), icon: 'folder-fill' },
              ...(props.database
                  ? [
                        {
                            key: 'database' as const,
                            label: trans('Database'),
                            icon: 'database-fill' as const,
                        },
                    ]
                  : []),
              { key: 'details', label: trans('Details'), icon: 'info-fill' },
          ];

    return list.map((item) => ({ ...item, current: tab.value === item.key }));
});

// A tab this service doesn't have (an app with no container yet, say)
// falls back to the first one it does.
watch(
    tabs,
    (list) => {
        if (
            list.length &&
            tab.value !== 'console' &&
            !list.some((item) => item.key === tab.value)
        ) {
            tab.value = list[0].key;
        }
    },
    { immediate: true },
);

const activeJob = computed(() =>
    props.jobs.some((job) => ['queued', 'running'].includes(job.state)),
);
let timer: ReturnType<typeof setTimeout> | undefined;

const expected = ref<{ status: string; until: number } | null>(null);
const awaitingScan = computed(
    () =>
        !!expected.value &&
        props.service.status !== expected.value.status &&
        Date.now() < expected.value.until,
);

const running = computed(() =>
    awaitingScan.value && expected.value
        ? expected.value.status === 'running'
        : props.service.status === 'running',
);

watch(
    () => props.service.status,
    (status) => {
        if (expected.value && status === expected.value.status) {
            expected.value = null;
        }
    },
);

function poll(): void {
    clearTimeout(timer);
    timer = setTimeout(
        () =>
            router.reload({
                only: [
                    'service',
                    'stats',
                    'history',
                    'jobs',
                    'machine',
                    'app',
                    'usage',
                    ...(deployState.value ? ['deploy'] : []),
                ],
                onFinish: poll,
            }),
        activeJob.value || awaitingScan.value ? 1500 : 5000,
    );
}

const busy = ref<string | null>(null);

async function act(
    action: string,
    extra: Record<string, unknown> = {},
): Promise<{ ok: boolean; body: Record<string, unknown> }> {
    busy.value = action;

    try {
        const response = await fetch(actionUrl.value, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ action, ...extra }),
        });
        const body = await response.json().catch(() => ({}));

        if (!response.ok && response.status !== 422) {
            toast.error(describe(response.status, body));
        }

        if (response.ok) {
            router.reload({ only: ['jobs'], onFinish: poll });
        }

        return { ok: response.ok, body };
    } catch (cause) {
        toast.error(errorMessage(cause));

        return { ok: false, body: {} };
    } finally {
        busy.value = null;
    }
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

const lifecycleLabels: Record<string, string> = {
    start: trans('Starting'),
    stop: trans('Stopping'),
    restart: trans('Restarting'),
    reload: trans('Reloading'),
    rollback: trans('Rolling back'),
};

const doneLabels: Record<string, string> = {
    start: trans('Started'),
    stop: trans('Stopped'),
    restart: trans('Restarted'),
    reload: trans('Reloaded'),
    adopt: trans('Adopted'),
    repair: trans('Repaired'),
    rollback: trans('Rolled back'),
    clone: trans('Cloned'),
};

const pending = ref<{
    id: string;
    action: string;
    toast: string | number;
} | null>(null);
const pendingJob = computed(() =>
    pending.value
        ? (props.jobs.find((job) => job.id === pending.value?.id) ?? null)
        : null,
);
const working = computed(() => busy.value ?? pending.value?.action ?? null);

const status = computed(() => {
    if (pending.value && pending.value.action !== 'adopt') {
        return {
            status: 'deploying' as const,
            label: `${lifecycleLabels[pending.value.action] ?? trans('Working')}…`,
        };
    }
    if (missing.value) {
        if (deployState.value?.deploying) {
            return { status: 'deploying' as const, label: trans('Deploying') };
        }

        return deployState.value?.lastFailure
            ? { status: 'down' as const, label: trans('Deploy failed') }
            : { status: 'stopped' as const, label: trans('Not deployed yet') };
    }

    return statusOf(
        awaitingScan.value && expected.value
            ? expected.value.status
            : props.service.status,
    );
});

async function track(action: string, verb: string): Promise<void> {
    const { ok, body } = await act(action);
    if (!ok) return;

    const toastId = toast.loading(
        trans(':verb :name…', { verb, name: displayName.value }),
    );
    if (typeof body.jobId === 'string') {
        pending.value = { id: body.jobId, action, toast: toastId };
    } else {
        toast.success(
            trans(':verb :name.', {
                verb: doneLabels[action],
                name: displayName.value,
            }),
            { id: toastId },
        );
    }
}

const cloning = ref(false);
function cloneStarted(jobId: string, name: string): void {
    const toastId = toast.loading(
        trans('Cloning :name as :as…', { name: displayName.value, as: name }),
    );
    pending.value = { id: jobId, action: 'clone', toast: toastId };
    router.reload({ only: ['jobs'], onFinish: poll });
}

watch(
    () => pendingJob.value?.state,
    (state) => {
        const current = pending.value;
        if (!current || !state || ['queued', 'running'].includes(state)) {
            return;
        }
        if (state === 'succeeded') {
            toast.success(
                trans(':verb :name.', {
                    verb: doneLabels[current.action],
                    name: displayName.value,
                }),
                { id: current.toast },
            );
            if (current.action === 'adopt') {
                adoptedNow.value = true;
            }
            const repairedKey = pendingJob.value?.result?.service;
            if (
                current.action === 'repair' &&
                typeof repairedKey === 'string'
            ) {
                pending.value = null;
                router.visit(
                    onWorkspace(
                        machinesRoutes.services.show({
                            ...machineArgs.value,
                            service: encodeURIComponent(repairedKey),
                        }),
                    ).url,
                );
                return;
            }
            if (['start', 'restart', 'stop'].includes(current.action)) {
                expected.value = {
                    status: current.action === 'stop' ? 'stopped' : 'running',
                    until: Date.now() + 60_000,
                };
            }
        } else {
            toast.error(
                jobReason(
                    pendingJob.value?.error,
                    trans('Couldn’t :action :displayName.', {
                        action: String(current.action),
                        displayName: String(displayName.value),
                    }),
                ),
                { id: current.toast },
            );
        }
        pending.value = null;
        poll();
    },
);

watch(
    () => pending.value?.id,
    (id) => {
        if (!id) return;
        setTimeout(() => {
            if (pending.value?.id === id) {
                toast.error(
                    trans(
                        'No answer from the machine yet. It may still finish; check back in a minute.',
                    ),
                    { id: pending.value.toast },
                );
                pending.value = null;
            }
        }, 90_000);
    },
);

function lifecycle(action: 'start' | 'stop' | 'restart' | 'reload'): void {
    void track(action, lifecycleLabels[action]);
}

function adopt(): void {
    void track('adopt', trans('Adopting'));
}

const liveVersion = computed(
    () => props.app?.versions.find((version) => version.live) ?? null,
);
const olderVersion = computed(
    () => props.app?.versions.find((version) => !version.live) ?? null,
);

const rollingBack = ref<AppVersion | null>(null);
const rollbackOpen = ref(false);
const rollbackData = ref(false);
const rollbackConfirm = ref('');
const rollbackBusy = ref(false);
const rollbackError = ref<string | null>(null);

function askRollback(version: AppVersion): void {
    rollingBack.value = version;
    rollbackData.value = false;
    rollbackConfirm.value = '';
    rollbackError.value = null;
    rollbackOpen.value = true;
}

async function rollback(): Promise<void> {
    const version = rollingBack.value;
    if (!props.app || !version) return;
    rollbackBusy.value = true;
    rollbackError.value = null;

    try {
        const response = await fetch(
            onWorkspace(appsRoutes.rollback(appArgs.value)).url,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    commit: version.commit,
                    with_data: rollbackData.value,
                    confirm: rollbackConfirm.value,
                }),
            },
        );
        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            rollbackError.value =
                body.errors?.confirm?.[0] ??
                body.error ??
                trans('The rollback could not start.');

            return;
        }

        rollbackOpen.value = false;
        pending.value = {
            id: body.jobId,
            action: 'rollback',
            toast: toast.loading(
                trans('Rolling :displayName back to :slice…', {
                    displayName: String(displayName.value),
                    slice: String(version.commit.slice(0, 7)),
                }),
            ),
        };
        router.reload({ only: ['jobs'], onFinish: poll });
    } finally {
        rollbackBusy.value = false;
    }
}

const redeploying = ref(false);

async function redeploy(): Promise<void> {
    const version = liveVersion.value;
    if (!version || redeploying.value) return;
    redeploying.value = true;
    try {
        const response = await fetch(
            onWorkspace(
                machinesRoutes.jobs.retry({
                    ...machineArgs.value,
                    job: version.id,
                }),
            ).url,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
            },
        );
        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            toast.error(describe(response.status, body));

            return;
        }
        toast.success(
            trans('Deploying :commit again…', {
                commit: version.commit.slice(0, 7),
            }),
        );
        router.visit(jobUrl(body.jobId));
    } catch (cause) {
        toast.error(errorMessage(cause));
    } finally {
        redeploying.value = false;
    }
}

function toggleHidden(): void {
    const hidden = !props.service.hidden;
    router.put(
        onWorkspace(machinesRoutes.services.hide(machineArgs.value)).url,
        { services: [props.service.key], hidden },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    hidden
                        ? trans(
                              ':displayName is hidden from the lists. It still runs as before.',
                              { displayName: String(displayName.value) },
                          )
                        : trans(':displayName shows in the lists again.', {
                              displayName: String(displayName.value),
                          }),
                ),
        },
    );
}

function toggleLogs(): void {
    const muted = !props.service.logsMuted;
    router.put(
        onWorkspace(
            machinesRoutes.services.logs({
                ...machineArgs.value,
                service: encodeURIComponent(props.service.key),
            }),
        ).url,
        { muted },
        {
            preserveScroll: true,
            onSuccess: () =>
                toast.success(
                    muted
                        ? trans(
                              'Logs from :displayName are no longer kept. It still runs as before.',
                              { displayName: String(displayName.value) },
                          )
                        : trans('Logs from :displayName are kept again.', {
                              displayName: String(displayName.value),
                          }),
                ),
        },
    );
}

const needsRepair = computed(
    () => props.service.details?.needs_repair === 'wings',
);
const repairing = ref(false);

function repair(): void {
    repairing.value = false;
    void track('repair', trans('Repairing'));
}

const variablesOpen = ref(false);
const siteOpen = ref(false);
const limitsOpen = ref(false);
const deploySettingsOpen = ref(false);

const siteApplying = computed(() => {
    const state = props.app?.site.job?.state;

    return state === 'queued' || state === 'running';
});
watch(siteApplying, (now, before) => {
    if (before && !now) router.reload({ only: ['app'] });
});
let sitePoll: ReturnType<typeof setInterval> | null = null;
watch(
    siteApplying,
    (now) => {
        if (sitePoll) clearInterval(sitePoll);
        sitePoll = now
            ? setInterval(() => router.reload({ only: ['app'] }), 3000)
            : null;
    },
    { immediate: true },
);

function variablesSaved(jobId: string | null): void {
    if (jobId && props.machine.id) {
        router.visit(jobUrl(jobId));
    }
}

const zip = useZipUpload();
const zipInput = useTemplateRef<HTMLInputElement>('zipInput');
const shipping = ref(false);

async function shipVersion(file: File | undefined): Promise<void> {
    if (!props.app || !props.machine.id || shipping.value) return;
    const source = await zip.upload(props.machine.id, file);
    if (!source) return;

    shipping.value = true;
    try {
        const json = await zip.post(
            onWorkspace(appsRoutes.versions.store(appArgs.value)).url,
            JSON.stringify({
                source: source.repo
                    .split('/')
                    .pop()
                    ?.replace(/\.git$/, ''),
            }),
            'application/json',
        );
        toast.success(trans('Building the new version…'));
        router.visit(jobUrl(json.jobId));
    } catch (cause) {
        zip.error.value = errorMessage(
            cause,
            trans('Couldn’t start the deploy.'),
        );
    } finally {
        shipping.value = false;
        if (zipInput.value) zipInput.value.value = '';
    }
}

watch(zip.error, (message) => {
    if (message) toast.error(message);
});

const deployLabel = computed(() => {
    if (zip.uploading.value) {
        return zip.progress.value >= 1
            ? trans('Unpacking…')
            : trans('Uploading :round%', {
                  round: String(Math.round(zip.progress.value * 100)),
              });
    }

    return shipping.value ? trans('Starting…') : trans('Deploy');
});

type DomainCheck = {
    status: 'ready' | 'missing' | 'proxied' | 'elsewhere';
    record: { type: string; name: string; value: string } | null;
    wildcard?: Omit<DomainCheck, 'wildcard'>;
};

const moving = ref(false);
const newDomain = ref('');
const moveSettings = ref(true);
const moveWildcard = ref(false);
const moveBusy = ref(false);
const moveError = ref<string | null>(null);
const moveDns = ref<DomainCheck | null>(null);
let moveDnsTimer: ReturnType<typeof setTimeout> | undefined;

function bareDomain(value: string): string {
    return value
        .trim()
        .toLowerCase()
        .replace(/^[a-z]+:\/\//, '')
        .replace(/[/?#].*$/, '')
        .replace(/:\d+$/, '');
}

const servesWildcard = computed(() => {
    const domains = props.app?.domains ?? [];

    return domains.length > 0 && domains.includes(`*.${domains[0]}`);
});

const movingAway = computed(
    () =>
        !!props.app?.domains.length &&
        bareDomain(newDomain.value) !== props.app.domains[0],
);

function openMove(): void {
    newDomain.value = props.app?.domains[0] ?? '';
    moveError.value = null;
    moveDns.value = null;
    moveSettings.value = true;
    moveWildcard.value = servesWildcard.value;
    moving.value = true;
}

watch([newDomain, moveWildcard], ([value]) => {
    clearTimeout(moveDnsTimer);
    moveDns.value = null;
    const domain = bareDomain(value);
    if (!/^([a-z0-9]([a-z0-9-]*[a-z0-9])?\.)+[a-z]{2,}$/.test(domain)) return;

    moveDnsTimer = setTimeout(async () => {
        try {
            const check = (await zip.post(
                onWorkspace(machinesRoutes.domainCheck(machineArgs.value)).url,
                JSON.stringify({ domain, wildcard: moveWildcard.value }),
                'application/json',
            )) as DomainCheck;
            if (bareDomain(newDomain.value) === domain) moveDns.value = check;
        } catch {
            moveDns.value = null;
        }
    }, 500);
});

async function moveDomain(): Promise<void> {
    if (!props.app || moveBusy.value) return;
    moveBusy.value = true;
    moveError.value = null;

    try {
        const json = await zip.post(
            onWorkspace(appsRoutes.domain(appArgs.value)).url,
            JSON.stringify({
                domain: newDomain.value,
                wildcard: moveWildcard.value,
                move_settings: moveSettings.value && movingAway.value,
            }),
            'application/json',
        );
        moving.value = false;
        toast.success(
            trans('Moving to :bareDomain…', {
                bareDomain: String(bareDomain(newDomain.value)),
            }),
        );
        router.visit(jobUrl(json.jobId));
    } catch (cause) {
        moveError.value = errorMessage(
            cause,
            trans('Couldn’t change the domain.'),
        );
    } finally {
        moveBusy.value = false;
    }
}

const historyUrl = computed(
    () =>
        onWorkspace(
            logsRoutes.index(props.workspace.slug, {
                query: {
                    machine: String(props.machine.id),
                    service: props.service.name,
                },
            }),
        ).url,
);
const fixesUrl = computed(
    () =>
        onWorkspace(
            fixesRoutes.index(props.workspace.slug, {
                query: {
                    machine: props.machine.id,
                    service: props.service.key,
                },
            }),
        ).url,
);

const logsJob = computed(
    () => props.jobs.find((job) => job.kind === 'service_logs') ?? null,
);

function fetchLogs(lines: number): void {
    if (canControl.value) {
        void act('logs', { lines });
    }
}

const inspectJob = computed(
    () => props.jobs.find((job) => job.kind === 'service_inspect') ?? null,
);
const canInspect = computed(
    () =>
        props.inspect?.available === true &&
        canManage.value &&
        props.commandsEnabled &&
        online.value,
);

function loadInspect(): void {
    if (canInspect.value) {
        void act('inspect');
    }
}

watch(
    tab,
    (next) => {
        if (next === 'details' && !inspectJob.value) loadInspect();
    },
    { immediate: true },
);

const consoleSheet = ref(false);

watch(
    tab,
    (next) => {
        if (next === 'console') {
            tab.value = 'overview';
            consoleSheet.value = true;
        }
    },
    { immediate: true },
);

function setRange(range: string): void {
    router.get(
        window.location.pathname,
        { range },
        {
            only: ['history', 'range'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

const memLimit = computed(() => {
    const applied = props.app?.limits?.applied?.memoryMb;
    if (applied) return applied * 1024 * 1024;
    const reported = props.stats?.memLimit ?? null;
    const total = props.machine.facts?.memory_bytes ?? null;

    return reported && reported < 2 ** 50 && (!total || reported < total)
        ? reported
        : null;
});

const removing = ref(false);
const removeConfirm = ref('');
const removeData = ref(false);
const removeDataSure = ref(false);
const removeAdopted = computed(() => props.service.origin !== 'created');
const canRemove = computed(
    () => canManage.value && props.commandsEnabled && online.value,
);
const removeError = ref<string | null>(null);
const removeBusy = ref(false);

async function remove(): Promise<void> {
    removeBusy.value = true;
    removeError.value = null;

    const { ok, body } = await act('remove', {
        confirm: removeConfirm.value,
        delete_data: removeData.value,
        delete_adopted_volumes: removeAdopted.value && removeDataSure.value,
    });

    removeBusy.value = false;

    if (ok) {
        removing.value = false;
        toast.success(
            trans('Removing :displayName…', {
                displayName: String(displayName.value),
            }),
        );
        router.visit(machineUrl.value);
    } else {
        removeError.value = reasonIn(body) ?? trans('Couldn’t remove it.');
    }
}

watch(removing, (open) => {
    if (open) {
        removeConfirm.value = '';
        removeData.value = false;
        removeDataSure.value = false;
        removeError.value = null;
    }
});

watch(removeData, (on) => {
    if (!on) {
        removeDataSure.value = false;
    }
});

/* Deploying the app again, following it, and fixing what failed. */
const deployMenu =
    useTemplateRef<InstanceType<typeof AppDeployMenu>>('deployMenu');
const startedJob = ref<string | null>(null);
const logJob = ref<{ id: string; title?: string } | null>(null);
const logOpen = ref(false);
const settingsFocus = ref<{ key: SettingsKey | null; at: number } | null>(null);

function openLog(id: string, title?: string): void {
    logJob.value = { id, title };
    logOpen.value = true;
}

function openSettings(key: SettingsKey | null): void {
    tab.value = 'settings';
    settingsFocus.value = { key, at: Date.now() };
}

function deployed(jobId: string, next: AppDeploy | null): void {
    startedJob.value = jobId;
    if (next) deployState.value = next;
    router.reload({
        only: next
            ? ['deployHistory', 'jobs']
            : ['deploy', 'deployHistory', 'jobs'],
    });
    toast(trans('Deploying :app…', { app: String(displayName.value) }));
}

function deployFinished(job: DeployJob): void {
    const sha = shortSha(job.commit);
    if (job.state === 'succeeded') {
        toast.success(
            job.kind === 'rollback'
                ? trans(':app is rolled back.', {
                      app: String(displayName.value),
                  })
                : sha
                  ? trans(':app is live with :sha.', {
                        app: String(displayName.value),
                        sha,
                    })
                  : trans(':app is live.', { app: String(displayName.value) }),
        );
    } else if (isFailed(job.state) || job.state === 'stale') {
        toast.error(
            trans(
                'The deploy of :app didn’t work. Why is at the top of the page.',
                {
                    app: String(displayName.value),
                },
            ),
        );
    }
    router.reload();
}

watch(
    () => deployState.value?.deploying?.id,
    (id, before) => {
        if (id && id !== before) router.reload({ only: ['deployHistory'] });
    },
);

function dropVersion(file: File | undefined): void {
    if (deployMenu.value) void deployMenu.value.upload(file);
    else void shipVersion(file);
}

function editVariables(): void {
    if (deployState.value) tab.value = 'settings';
    else variablesOpen.value = true;
}

onMounted(() => {
    poll();
});

onBeforeUnmount(() => {
    clearTimeout(timer);
    if (sitePoll) clearInterval(sitePoll);
});
</script>

<template>
    <Head :title="displayName" />

    <DashHeader
        :crumbs="[
            { label: $t('Services'), href: servicesUrl },
            { label: displayName },
        ]"
    >
        <template #actions>
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
                    <DropdownMenuItem
                        v-if="needsRepair && managed && canManage"
                        :disabled="!canControl || working !== null"
                        @select="repairing = true"
                    >
                        <FilledIcon name="settings-fill" />
                        {{ $t('Repair') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="canManage && clone"
                        :disabled="
                            clone.unavailable !== null || working !== null
                        "
                        :title="clone.unavailable ?? undefined"
                        @select="cloning = true"
                    >
                        <FilledIcon name="copy" />
                        {{ $t('Clone') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem
                        v-if="canManage && app && olderVersion"
                        :disabled="!online || working !== null || !hasPlan"
                        @select="askRollback(olderVersion)"
                    >
                        <FilledIcon name="history" />
                        {{ $t('Roll back') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="canManage" @select="openRename">
                        <FilledIcon name="sliders" />
                        {{ $t('Edit name and icon') }}
                    </DropdownMenuItem>
                    <DropdownMenuItem v-if="canManage" @select="toggleHidden">
                        <FilledIcon name="layers-fill" />
                        {{
                            service.hidden
                                ? $t('Show in lists again')
                                : $t('Hide from lists')
                        }}
                    </DropdownMenuItem>
                    <template v-if="managed && canManage && !missing">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            v-if="running"
                            :disabled="!canControl || working !== null"
                            @select="lifecycle('stop')"
                        >
                            <FilledIcon name="activity" />
                            {{ $t('Stop') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="!canControl || working !== null"
                            @select="lifecycle('reload')"
                        >
                            <FilledIcon name="update-fill" />
                            {{ $t('Reload config') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="service.container"
                            @select="consoleSheet = true"
                        >
                            <FilledIcon name="terminal-fill" />
                            {{ $t('Console') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem as-child>
                            <Link :href="fixesUrl">
                                <FilledIcon name="sparkle-fill" />
                                {{ $t('Look into it') }}
                            </Link>
                        </DropdownMenuItem>
                    </template>
                    <template v-if="canManage && app">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            v-if="app.domains.length"
                            :disabled="
                                !online || !app.site.supported || siteApplying
                            "
                            @select="siteOpen = true"
                        >
                            <FilledIcon name="globe-fill" />
                            {{ $t('Site settings') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem @select="deploySettingsOpen = true">
                            <FilledIcon name="branch-fill" />
                            {{ $t('Deploy settings') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            :disabled="
                                !online || app.limits.unavailable !== null
                            "
                            @select="limitsOpen = true"
                        >
                            <FilledIcon name="sliders" />
                            {{ $t('CPU and memory limits') }}
                        </DropdownMenuItem>
                    </template>
                    <template v-if="canManage">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem @select="toggleLogs">
                            <FilledIcon name="file-fill" />
                            {{
                                service.logsMuted
                                    ? $t('Keep its logs again')
                                    : $t('Stop keeping its logs')
                            }}
                        </DropdownMenuItem>
                    </template>
                    <template v-if="removable && canManage">
                        <DropdownMenuSeparator />
                        <DropdownMenuItem
                            class="text-status-down focus:bg-status-down/10 focus:text-status-down"
                            :disabled="!canRemove"
                            @select="removing = true"
                        >
                            <FilledIcon name="archive" />
                            {{ $t('Remove service') }}
                        </DropdownMenuItem>
                    </template>
                </DropdownMenuContent>
            </DropdownMenu>

            <template v-if="canManage">
                <template v-if="!missing">
                    <DashButton
                        v-if="!managed"
                        variant="primary"
                        :label="
                            working === 'adopt'
                                ? $t('Adopting…')
                                : $t('Adopt service')
                        "
                        :disabled="
                            !commandsEnabled || !online || working !== null
                        "
                        @click="adopt"
                    />
                    <DashButton
                        v-else-if="running"
                        :label="$t('Restart')"
                        :loading="working === 'restart'"
                        :disabled="!canControl || working !== null"
                        @click="lifecycle('restart')"
                    />
                    <DashButton
                        v-else
                        :variant="app || deployState ? 'secondary' : 'primary'"
                        :label="$t('Start')"
                        :loading="working === 'start'"
                        :disabled="!canControl || working !== null"
                        @click="lifecycle('start')"
                    />
                </template>
                <AppDeployMenu
                    v-if="deployState && managed"
                    ref="deployMenu"
                    :deploy="deployState"
                    :workspace-slug="workspace.slug"
                    :machine-id="machine.id!"
                    :online="online"
                    :has-plan="hasPlan"
                    :has-settings="!!app"
                    @deployed="deployed"
                    @settings="deploySettingsOpen = true"
                />
                <template v-else-if="app && managed">
                    <input
                        ref="zipInput"
                        type="file"
                        accept=".zip,application/zip"
                        class="hidden"
                        @change="
                            shipVersion(
                                ($event.target as HTMLInputElement).files?.[0],
                            )
                        "
                    />
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <DashButton
                                variant="primary"
                                :icon="Plus"
                                :label="deployLabel"
                                :loading="
                                    zip.uploading.value ||
                                    shipping ||
                                    redeploying
                                "
                                :disabled="!online || !hasPlan"
                            />
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="ds-modal min-w-56 font-ui"
                            align="end"
                        >
                            <DropdownMenuItem
                                v-if="liveVersion"
                                @select="redeploy"
                            >
                                <FilledIcon name="update-fill" />
                                {{
                                    $t('Deploy :commit again', {
                                        commit: liveVersion.commit.slice(0, 7),
                                    })
                                }}
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="zipInput?.click()">
                                <FilledIcon name="upload-fill" />
                                {{ $t('Upload a new version (.zip)') }}
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                @select="deploySettingsOpen = true"
                            >
                                <FilledIcon name="branch-fill" />
                                {{ $t('Deploy settings') }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>
            </template>
        </template>
    </DashHeader>

    <div class="flex min-h-full flex-col">
        <div class="flex flex-wrap items-center gap-3 px-6 pt-5 pb-4">
            <BrandLogo :name="logo" :src="service.icon" :size="22" />
            <h1
                class="min-w-0 truncate text-[20px]/7 font-medium tracking-[-0.02em] text-fg-primary"
            >
                {{ displayName }}
            </h1>
            <StatusBadge :status="status.status" :label="status.label" />
            <span class="grow" />
            <component
                :is="chip.href ? Link : 'span'"
                v-for="chip in chips"
                :key="chip.key"
                :href="chip.href"
                class="flex h-[26px] max-w-64 min-w-0 items-center gap-1.5 rounded-full border-[0.5px] border-[#dcdcdc] bg-[#f4f4f4] px-2.5 text-[12px]/4 font-medium tracking-[-0.005em] whitespace-nowrap text-fg-secondary dark:border-[#303030] dark:bg-[#202020]"
                :class="chip.href && 'hover:text-fg-primary'"
            >
                <BrandLogo v-if="chip.logo" :name="chip.logo" :size="12" />
                <FilledIcon
                    v-else-if="chip.icon"
                    :name="chip.icon"
                    :size="12"
                    class="text-fg-muted"
                />
                <span class="truncate">{{ chip.label }}</span>
            </component>
        </div>

        <DashTabs>
            <DashTab
                v-for="item in tabs"
                :key="item.key"
                :label="item.label"
                :icon="item.icon"
                :active="item.current"
                @click="tab = item.key"
            />
        </DashTabs>

        <div
            v-if="
                deployState &&
                (deployState.deploying || deployState.lastFailure || startedJob)
            "
            class="flex flex-col gap-3 px-6 pt-4 empty:hidden"
        >
            <AppDeployStatus
                :deploy="deployState"
                :started="startedJob"
                :workspace-slug="workspace.slug"
                :machine-id="machine.id!"
                :machine-name="machine.name"
                :missing="missing"
                :can-manage="canManage"
                :online="online"
                @settings="openSettings"
                @log="openLog"
                @deployed="deployed"
                @finished="deployFinished"
            />
        </div>

        <div
            v-if="!managed || needsRepair"
            class="flex flex-col gap-3 px-6 pt-4"
        >
            <div
                v-if="!managed"
                class="dash-card flex flex-wrap items-center gap-4 border-edge-strong! px-[18px] py-3.5"
            >
                <div class="flex min-w-0 grow flex-col gap-0.5">
                    <p class="text-[13px]/5 font-medium text-fg-primary">
                        {{
                            $t(
                                'ServerOS found this service but doesn’t control it yet',
                            )
                        }}
                    </p>
                    <p class="text-[12px]/4 text-fg-muted">
                        {{
                            $t(
                                'Adopt it to start, stop and restart it, read its logs and run commands. Adopting changes nothing about the service itself.',
                            )
                        }}
                    </p>
                </div>
            </div>
            <div
                v-if="needsRepair"
                class="dash-card flex flex-wrap items-center gap-4 border-edge-strong! px-[18px] py-3.5"
            >
                <div class="flex min-w-0 grow flex-col gap-0.5">
                    <p class="text-[13px]/5 font-medium text-fg-primary">
                        {{
                            $t(
                                'This container needs repairing before it can start',
                            )
                        }}
                    </p>
                    <p class="text-[12px]/4 text-fg-muted">
                        {{
                            $t(
                                'Pterodactyl set it up with files Wings creates each time it starts a server. Wings isn’t running any more, so Docker won’t start it. :value',
                                {
                                    value: managed
                                        ? $t(
                                              'ServerOS can recreate it without those files and keep its data.',
                                          )
                                        : $t(
                                              'Adopt it first, then ServerOS can repair it.',
                                          ),
                                },
                            )
                        }}
                    </p>
                </div>
                <DashButton
                    v-if="canManage && managed"
                    size="sm"
                    variant="primary"
                    :label="
                        working === 'repair' ? $t('Repairing…') : $t('Repair')
                    "
                    :disabled="!commandsEnabled || !online || working !== null"
                    @click="repairing = true"
                />
            </div>
        </div>

        <div v-if="tab === 'overview'" class="flex grow flex-col xl:flex-row">
            <div class="flex min-w-0 flex-1 flex-col gap-3 px-6 py-4">
                <ServiceUsage
                    :stats="stats"
                    :usage="usage"
                    :traffic="!!app || !!siteTraffic"
                    :mem-limit="memLimit"
                    :cpu-limit="app?.limits?.applied?.cpus ?? null"
                    :machine-memory="machine.facts?.memory_bytes ?? null"
                />
                <ServiceDomain
                    :app="app"
                    :sites="siteTraffic?.sites"
                    :serves="siteTraffic?.role === 'server'"
                    :ports="service.ports"
                    :workspace-slug="workspace.slug"
                    :machine-id="machine.id!"
                    :can-manage="canManage"
                    :online="online"
                    @change="openMove"
                    @site="siteOpen = true"
                />
                <ServiceDeploys
                    :app="app"
                    :workspace-slug="workspace.slug"
                    :machine-id="machine.id!"
                    :can-manage="canManage"
                    :can-roll-back="online && working === null && hasPlan"
                    :messages="messages"
                    @rollback="askRollback"
                    @settings="deploySettingsOpen = true"
                    @drop="dropVersion"
                />
                <ServiceVariables
                    :variables="app?.variables ?? null"
                    :linked="linked"
                    :can-manage="canManage"
                    :online="online"
                    :container="!!inspect"
                    @edit="editVariables"
                    @config="tab = 'details'"
                />
            </div>
            <ServiceRail
                :service="{ ...service, managed }"
                :stats="stats"
                :stack="stack"
                :linked="linked"
                :limits="app?.limits ?? null"
                :mem-limit="memLimit"
                :machine-name="machine.name"
                :links="links"
                :can-manage="canManage"
                @limits="limitsOpen = true"
            />
        </div>

        <div
            v-else-if="tab === 'deploys' && deployState"
            class="flex flex-col gap-3 px-6 py-4"
        >
            <AppDeployHistory
                :rows="deployHistory"
                :messages="messages"
                :workspace-slug="workspace.slug"
                :machine-id="machine.id!"
                :service="deployState.service"
                :live-commit="deployState.liveCommit"
                :source="deployState.source"
                @log="openLog"
            />
            <div class="grid items-start gap-3 lg:grid-cols-2">
                <AppAutoDeploy
                    :deploy="deployState"
                    :workspace-slug="workspace.slug"
                    :machine-id="machine.id!"
                    :can-manage="canManage"
                    @changed="router.reload({ only: ['deploy', 'app'] })"
                />
            </div>
        </div>

        <div
            v-else-if="tab === 'settings' && deployState"
            class="flex flex-col gap-3 px-6 py-4"
        >
            <AppBuildSettings
                :deploy="deployState"
                :workspace-slug="workspace.slug"
                :machine-id="machine.id!"
                :machine-name="machine.name"
                :can-manage="canManage"
                :online="online"
                :focus="settingsFocus"
                @deployed="deployed"
            />
            <AppEnvironment
                :deploy="deployState"
                :linked="linked"
                :workspace-slug="workspace.slug"
                :machine-id="machine.id!"
                :can-manage="canManage"
                :online="online"
                @deployed="deployed"
            />
            <AppSchedules
                :deploy="deployState"
                :workspace-slug="workspace.slug"
                :machine-id="machine.id!"
                :can-manage="canManage"
                :online="online"
                :running="!missing && running"
                :console-lock="consoleLock"
                @log="openLog"
                @saved="router.reload({ only: ['deploy'] })"
                @unlocked="router.reload({ only: ['consoleLock'] })"
            />
        </div>

        <div v-else-if="tab === 'traffic'" class="px-6 py-4">
            <SiteTraffic
                v-if="app"
                :machine-id="machine.id ?? 0"
                :machine-name="machine.name"
                :app="app.service"
                :state="app.traffic"
                :can-manage="canManage"
            />
            <ExistingSitesTraffic
                v-else-if="siteTraffic"
                :machine-id="machine.id ?? 0"
                :machine-name="machine.name"
                :service-url="serviceUrl"
                :service-name="service.name"
                :traffic="siteTraffic"
                :can-manage="canManage"
            />
        </div>

        <ServiceLogs
            v-else-if="tab === 'logs'"
            :workspace-slug="workspace.slug"
            :machine-id="machine.id!"
            :service-key="routeKey"
            :summary="logs"
            :managed="managed"
            :can-fetch="canControl"
            :machine-job="logsJob"
            :history-url="historyUrl"
            :plan-label="workspace.billing.planLabel"
            @fetch="fetchLogs"
        />

        <div v-else-if="tab === 'files'" class="px-6 py-4">
            <FileBrowser
                :machine-id="machine.id ?? 0"
                :places="files.places"
                :max-write="files.maxWrite"
                :max-read="files.maxRead"
                :console-lock="consoleLock"
                :available="canManage && commandsEnabled && online"
                :empty-hint="
                    managed
                        ? $t(
                              'ServerOS didn’t find a folder for this service when it adopted it, so there’s nowhere it may look.',
                          )
                        : $t(
                              'ServerOS can open the files of services it deployed or adopted. Adopt this service to browse its files here.',
                          )
                "
            />
        </div>

        <div v-else-if="tab === 'database' && database" class="px-6 py-4">
            <DatabaseBrowser
                :machine-id="machine.id ?? 0"
                :service-key="routeKey"
                :engine="database.engine"
                :console-lock="consoleLock"
                :available="canManage && commandsEnabled && online"
            />
        </div>

        <div v-else class="flex flex-col gap-3 px-6 py-4">
            <ServiceDetails :service="{ ...service, managed }" :stats="stats" />
            <ContainerConfig
                v-if="inspect"
                :job="inspectJob"
                :can-load="canInspect"
                :unavailable="
                    inspect.available
                        ? null
                        : $t(
                              'This machine’s daemon is too old to show container config. Update it with “sudo serverosd update” on the server.',
                          )
                "
                :loading="busy === 'inspect'"
                @load="loadInspect"
            />
            <ServiceCharts
                :history="history"
                :range="range"
                :network="service.container"
                @range="setRange"
            />
        </div>
    </div>

    <AppJobLog
        v-if="deployState"
        v-model:open="logOpen"
        :workspace-slug="workspace.slug"
        :machine-id="machine.id!"
        :service="deployState.service"
        :job-id="logJob?.id ?? null"
        :title="logJob?.title"
        :can-manage="canManage"
        @finished="router.reload({ only: ['deploy', 'deployHistory'] })"
    />
    <AppVariablesDialog
        v-if="canManage && app"
        v-model:open="variablesOpen"
        :machine-id="machine.id!"
        :app="app.service"
        @saved="variablesSaved"
    />
    <AppSiteDialog
        v-if="canManage && app && app.domains.length"
        v-model:open="siteOpen"
        :machine-id="machine.id!"
        :app="app.service"
        :site="app.site"
        @saved="router.reload({ only: ['app'] })"
    />
    <AppLimitsDialog
        v-if="canManage && app"
        v-model:open="limitsOpen"
        :machine-id="machine.id!"
        :app="app.service"
        :limits="app.limits"
        @saved="variablesSaved"
    />
    <DeploySettingsDialog
        v-if="canManage && app"
        v-model:open="deploySettingsOpen"
        :app="app"
        :workspace-slug="workspace.slug"
        :machine-id="machine.id!"
    />
    <CloneServiceDialog
        v-if="clone"
        v-model:open="cloning"
        :machine-id="machine.id!"
        :service-key="service.key"
        :display-name="String(displayName)"
        :defaults="{ name: clone.name, port: clone.port }"
        @started="cloneStarted"
    />
    <ServiceConsole
        v-if="service.container && canManage"
        v-model:open="consoleSheet"
        :workspace-slug="workspace.slug"
        :action-url="actionUrl"
        :display-name="String(displayName)"
        :machine-name="machine.name"
        :container="service.container"
        :has-plan="hasPlan"
        :can-run="canControl"
        :jobs="jobs"
        :console-lock="consoleLock"
        @ran="router.reload({ only: ['jobs'], onFinish: poll })"
    />

    <Dialog v-if="app" v-model:open="moving">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    app.domains.length
                        ? $t('Change domain')
                        : $t('Add a domain')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'The new version starts beside the current one, and traffic moves once it’s healthy.',
                        )
                    }}
                    <template v-if="movingAway">{{
                        $t(':domains stops working after that.', {
                            domains: app.domains[0],
                        })
                    }}</template>
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="moveDomain">
                <div class="grid gap-2">
                    <label for="new-domain" class="text-[13px]/5">{{
                        $t('Domain')
                    }}</label>
                    <Input
                        id="new-domain"
                        v-model="newDomain"
                        :placeholder="$t('app.example.com')"
                        autocomplete="off"
                        spellcheck="false"
                        class="font-ui-mono"
                    />
                    <p
                        v-if="moveDns?.status === 'ready'"
                        class="text-xs text-status-live"
                    >
                        {{ $t('Points at this machine.') }}
                    </p>
                    <p
                        v-else-if="moveDns?.record"
                        class="text-xs text-fg-muted"
                    >
                        {{
                            moveDns.status === 'proxied'
                                ? $t(
                                      'It’s behind Cloudflare’s proxy. Set it to DNS only until HTTPS is issued, with',
                                  )
                                : $t('Add a DNS record:')
                        }}
                        <span class="font-ui-mono text-fg-primary"
                            >{{ moveDns.record.type }}
                            {{ moveDns.record.name }} →
                            {{ moveDns.record.value }}</span
                        >{{ $t('. HTTPS starts once it points here.') }}
                    </p>
                </div>
                <label
                    class="flex cursor-pointer items-start gap-2.5 text-[13px]/5 text-fg-secondary select-none"
                >
                    <TickBox v-model="moveWildcard" class="mt-0.5" />
                    <span class="flex flex-col gap-0.5">
                        <span class="text-fg-primary">{{
                            $t('Allow wildcard subdomains')
                        }}</span>
                        <span class="text-xs/4 text-fg-faint">{{
                            $t(
                                'Every subdomain, like tenant.:domain, is served by this app and gets its own certificate the first time it’s visited. No DNS provider needed.',
                                {
                                    domain:
                                        bareDomain(newDomain) || 'example.com',
                                },
                            )
                        }}</span>
                        <span
                            v-if="
                                moveWildcard &&
                                moveDns?.wildcard &&
                                moveDns.wildcard.status !== 'ready' &&
                                moveDns.wildcard.record
                            "
                            class="text-xs/4 text-fg-muted"
                            >{{ $t('Subdomains need a record too:') }}
                            <span class="font-ui-mono text-fg-primary"
                                >{{ moveDns.wildcard.record.type }}
                                {{ moveDns.wildcard.record.name }} →
                                {{ moveDns.wildcard.record.value }}</span
                            ></span
                        >
                    </span>
                </label>
                <label
                    v-if="app.mentions.length && movingAway"
                    class="flex cursor-pointer items-start gap-2.5 text-[13px]/5 text-fg-secondary select-none"
                >
                    <TickBox v-model="moveSettings" class="mt-0.5" />
                    <span
                        >{{ $t('Also update') }}
                        <span class="font-ui-mono text-fg-primary">{{
                            app.mentions.join(', ')
                        }}</span>
                        {{ $t('to the new domain.') }}</span
                    >
                </label>
                <InputError :message="moveError ?? undefined" />
                <div class="flex justify-end gap-2">
                    <AppButton size="sm" @click="moving = false">
                        {{ $t('Cancel') }}
                    </AppButton>
                    <SubmitButton
                        :label="
                            !app.domains.length
                                ? $t('Add domain')
                                : movingAway
                                  ? $t('Change domain')
                                  : $t('Save')
                        "
                        busy-label="Starting…"
                        :processing="moveBusy"
                        :disabled="!newDomain.trim()"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="renaming">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('Edit service') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'A name and icon to show in ServerOS, so it’s clear what this is. Nothing changes on the machine. It was found as',
                        )
                    }}
                    <span class="font-ui-mono text-fg-primary">{{
                        service.app ?? service.name
                    }}</span
                    >.
                </DialogDescription>
            </DialogHeader>
            <form
                class="flex min-w-0 flex-col gap-4"
                @submit.prevent="saveLabel(newLabel.trim() || null)"
            >
                <div class="grid gap-2">
                    <label for="service-label" class="text-[13px]/5">{{
                        $t('Name')
                    }}</label>
                    <Input
                        id="service-label"
                        v-model="newLabel"
                        maxlength="60"
                        autocomplete="off"
                        :placeholder="service.app ?? service.name"
                    />
                    <InputError :message="renameError ?? undefined" />
                </div>
                <ImageUploadField
                    field="icon"
                    max-size="2 MB"
                    :label="$t('Icon')"
                    :image-url="service.icon ?? null"
                    :fallback="displayName.charAt(0).toUpperCase()"
                    :upload-url="serviceIconUrl"
                    :remove-url="serviceIconUrl"
                />
                <div class="flex items-center justify-between gap-2">
                    <AppButton
                        v-if="service.label"
                        size="sm"
                        variant="quiet"
                        :disabled="renameBusy"
                        @click="saveLabel(null)"
                    >
                        {{ $t('Use original name') }}
                    </AppButton>
                    <span v-else />
                    <div class="flex gap-2">
                        <AppButton size="sm" @click="renaming = false">
                            {{ $t('Cancel') }}
                        </AppButton>
                        <SubmitButton
                            :label="$t('Save')"
                            busy-label="Saving…"
                            :processing="renameBusy"
                        />
                    </div>
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="rollbackOpen">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>
                    {{
                        $t('Roll :service back to :value?', {
                            service: String(app?.service ?? ''),
                            value: String(
                                rollingBack?.commit.slice(0, 7) ?? '',
                            ),
                        })
                    }}
                </DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'The version from :value starts again and takes over its address. The newer one stops, and you can roll forward to it later.',
                            {
                                value: rollingBack?.deployedAt
                                    ? new Date(
                                          rollingBack.deployedAt,
                                      ).toLocaleString()
                                    : 'before',
                            },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="rollback">
                <div
                    class="ds-segments self-start"
                    role="group"
                    :aria-label="$t('What to roll back')"
                >
                    <button
                        type="button"
                        class="cursor-pointer"
                        :aria-pressed="!rollbackData"
                        @click="rollbackData = false"
                    >
                        {{ $t('Code only') }}
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer disabled:cursor-not-allowed disabled:opacity-50"
                        :aria-pressed="rollbackData"
                        :disabled="!rollingBack?.data"
                        @click="rollbackData = true"
                    >
                        {{ $t('Code and data') }}
                    </button>
                </div>
                <p class="text-[13px]/5 text-fg-secondary">
                    <template v-if="rollbackData && rollingBack?.dataAt">
                        {{
                            $t(
                                'Its data goes back to the snapshot from :value, just before the next deploy. Anything written since is replaced. A snapshot is taken first, so you can undo it from Backups.',
                                {
                                    value: new Date(
                                        rollingBack.dataAt,
                                    ).toLocaleString(),
                                },
                            )
                        }}
                    </template>
                    <template v-else-if="rollingBack?.data">
                        {{
                            $t(
                                'Its data stays as it is now. Choose Code and data if the newer version changed it, with a migration say.',
                            )
                        }}
                    </template>
                    <template v-else>
                        {{
                            $t(
                                'There’s no snapshot of its data from then, so only the code goes back. Restore a backup for the data.',
                            )
                        }}
                    </template>
                </p>
                <div class="grid gap-2">
                    <label for="rollback-confirm" class="text-[13px]/5">
                        {{ $t('Type') }}
                        <span class="font-ui-mono text-fg-primary">{{
                            app?.service
                        }}</span>
                        {{ $t('to confirm.') }}
                    </label>
                    <Input
                        id="rollback-confirm"
                        v-model="rollbackConfirm"
                        autocomplete="off"
                        spellcheck="false"
                        class="font-ui-mono"
                    />
                </div>
                <InputError :message="rollbackError ?? undefined" />
                <div class="flex justify-end gap-2">
                    <AppButton size="sm" @click="rollbackOpen = false">
                        {{ $t('Cancel') }}
                    </AppButton>
                    <DangerButton
                        :label="$t('Roll back')"
                        busy-label="Starting…"
                        :processing="rollbackBusy"
                        :disabled="
                            !rollbackBusy && rollbackConfirm !== app?.service
                        "
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="repairing">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('Repair :name?', { name: service.name })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'ServerOS recreates the container with the same name, image, settings, ports and data, without the Wings files, and starts it. It will also come back after a reboot. If it won’t start, the old one is put back.',
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <div class="flex justify-end gap-2">
                <AppButton size="sm" @click="repairing = false">
                    {{ $t('Cancel') }}
                </AppButton>
                <AppButton variant="primary" size="sm" @click="repair">
                    {{ $t('Repair') }}
                </AppButton>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="removing">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('Remove :name?', { name: service.name })
                }}</DialogTitle>
                <DialogDescription v-if="!service.container">
                    {{
                        $t(
                            'It stops, won’t start again at boot, and its unit file is deleted if it was made on this machine. A service installed by a package keeps its files: uninstall the package to remove it completely. Files and data it uses are never touched.',
                        )
                    }}
                </DialogDescription>
                <DialogDescription v-else-if="removeAdopted">
                    {{
                        $t(
                            'You adopted this one, so only its container stops and is deleted. :value Its volumes are kept unless you choose otherwise, and folders it uses on the machine are never touched.',
                            {
                                value: service.details?.compose_project
                                    ? $t(
                                          'Nothing else in the :compose_project project is touched.',
                                          {
                                              compose_project: String(
                                                  service.details
                                                      .compose_project,
                                              ),
                                          },
                                      )
                                    : '',
                            },
                        )
                    }}
                </DialogDescription>
                <DialogDescription v-else>
                    {{
                        $t(
                            'Its containers stop and are deleted. :value Data in volumes is kept unless you choose otherwise.',
                            {
                                value: service.details?.compose_project
                                    ? $t(
                                          'Everything in the :compose_project project goes with it.',
                                          {
                                              compose_project: String(
                                                  service.details
                                                      .compose_project,
                                              ),
                                          },
                                      )
                                    : '',
                            },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="remove">
                <div class="grid gap-2">
                    <label for="remove-confirm" class="text-[13px]/5">
                        {{ $t('Type') }}
                        <span class="font-ui-mono text-fg-primary">{{
                            service.name
                        }}</span>
                        {{ $t('to confirm.') }}
                    </label>
                    <Input
                        id="remove-confirm"
                        v-model="removeConfirm"
                        autocomplete="off"
                        spellcheck="false"
                        class="font-ui-mono"
                    />
                </div>
                <p v-if="!managed" class="text-[13px]/5 text-fg-muted">
                    {{
                        $t(
                            'ServerOS doesn’t control it yet, so it adopts it first, then removes it.',
                        )
                    }}
                </p>
                <label
                    v-if="service.container"
                    class="flex cursor-pointer items-start gap-2.5 text-[13px]/5 text-fg-secondary select-none"
                >
                    <TickBox v-model="removeData" class="mt-0.5" />
                    <span>{{
                        $t(
                            'Also delete its data (volumes). This can’t be undone.',
                        )
                    }}</span>
                </label>
                <label
                    v-if="removeAdopted && removeData"
                    class="flex cursor-pointer items-start gap-2.5 text-[13px]/5 text-fg-secondary select-none"
                >
                    <TickBox v-model="removeDataSure" class="mt-0.5" />
                    <span>{{
                        $t(
                            'ServerOS didn’t create this service. Delete its Docker volumes anyway.',
                        )
                    }}</span>
                </label>
                <InputError :message="removeError ?? undefined" />
                <div class="flex justify-end gap-2">
                    <AppButton size="sm" @click="removing = false">
                        {{ $t('Cancel') }}
                    </AppButton>
                    <DangerButton
                        :label="$t('Remove service')"
                        busy-label="Removing…"
                        :processing="removeBusy"
                        :disabled="
                            !removeBusy &&
                            (removeConfirm !== service.name ||
                                (removeAdopted &&
                                    removeData &&
                                    !removeDataSure))
                        "
                        :error="removeError"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
