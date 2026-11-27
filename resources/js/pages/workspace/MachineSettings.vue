<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import type { MachineCloudServer } from '@/components/integrations/integrations';
import type { Machine } from '@/components/machines/machines';
import CloudServerTray from '@/components/machines/server/CloudServerTray.vue';
import MachineServerActions from '@/components/machines/server/MachineServerActions.vue';
import type { ServerAction } from '@/components/machines/server/MachineServerActions.vue';
import LogRulesCard from '@/components/machines/settings/LogRulesCard.vue';
import type {
    LogSkipProps,
    SkippedLogs,
} from '@/components/machines/settings/LogRulesCard.vue';
import MachineDetails from '@/components/machines/settings/MachineDetails.vue';
import MachineHead from '@/components/machines/settings/MachineHead.vue';
import SettingsRail from '@/components/machines/settings/SettingsRail.vue';
import type {
    CollectedData,
    PolicyProps,
} from '@/components/machines/settings/SettingsRail.vue';
import StatusPageSettings from '@/components/machines/settings/StatusPageSettings.vue';
import type { StatusPageProps } from '@/components/machines/settings/StatusPageSettings.vue';
import TrafficSettings from '@/components/machines/TrafficSettings.vue';
import type { TrafficSettingsProps } from '@/components/machines/TrafficSettings.vue';
import TunnelSettings from '@/components/machines/TunnelSettings.vue';
import type { TunnelSettingsProps } from '@/components/machines/TunnelSettings.vue';
import { usePathTab } from '@/composables/usePathTab';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import type { Workspace } from '@/types';

const props = defineProps<{
    workspace: Workspace;
    machine: Machine;
    canManage: boolean;
    deployEnabled: boolean;
    detected: {
        provider: string | null;
        region: string | null;
        tier: string | null;
    };
    providers: string[];
    updatesWaiting: number;
    statusPage: StatusPageProps;
    policy: PolicyProps;
    logSkip: LogSkipProps;
    tunnel: TunnelSettingsProps | null;
    traffic: TrafficSettingsProps;
    planAllows: boolean;
    environments: string[];
    collectedData?: CollectedData;
    skippedLogs?: SkippedLogs;
    cloudServer?: MachineCloudServer | null;
}>();

const serverActions =
    useTemplateRef<InstanceType<typeof MachineServerActions>>('serverActions');

function serverAction(action: ServerAction): void {
    serverActions.value?.open(action);
}

const args = computed(() => ({
    workspace: props.workspace.slug,
    machine: props.machine.id!,
}));
const settingsBase = computed(
    () => onWorkspace(machinesRoutes.settings(args.value)).url,
);
const machineUrl = computed(
    () => onWorkspace(machinesRoutes.show(args.value)).url,
);
const machinesUrl = computed(
    () => onWorkspace(machinesRoutes.index(props.workspace.slug)).url,
);

const tab = usePathTab(settingsBase.value, ['general', 'status'] as const);

function show(view: 'general' | 'status'): void {
    tab.value = view;
    document.querySelector('main [data-machine-tabs]')?.scrollIntoView({
        block: 'nearest',
    });
}

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
const deployBlocked = computed<string | null>(() => {
    if (!hasPlan.value)
        return trans(
            'Your plan has ended, so deploys are paused. Everything already running keeps running.',
        );
    if (!props.canManage)
        return trans('You need permission to manage machines to deploy here.');
    if (!props.deployEnabled)
        return trans('Deploys aren’t set up on this panel yet.');
    if (!online.value) return trans('The machine is offline.');

    return null;
});

const statusSummary = computed(() => ({
    live: props.statusPage.exists && props.statusPage.enabled,
    public: props.statusPage.public,
}));

const serviceNames = computed(() =>
    [
        ...new Set(
            (props.machine.inventory?.services ?? [])
                .filter((service) => !service.hidden)
                .map((service) => service.name.replace(/^\//, '')),
        ),
    ].sort(),
);

const saved = ref(false);
let savedTimer: ReturnType<typeof setTimeout> | undefined;

function markSaved(): void {
    saved.value = true;
    clearTimeout(savedTimer);
    savedTimer = setTimeout(() => (saved.value = false), 2500);
}

const clearing = computed(() =>
    ['queued', 'running'].includes(props.collectedData?.status?.state ?? ''),
);
let collectedTimer: ReturnType<typeof setTimeout> | undefined;

function loadCollected(): void {
    if (!props.canManage || !props.machine.id) return;
    clearTimeout(collectedTimer);
    router.reload({
        only: ['collectedData', 'skippedLogs'],
        onFinish: () => {
            if (clearing.value) {
                collectedTimer = setTimeout(loadCollected, 3000);
            }
        },
    });
}

function onLogRulesChanged(): void {
    markSaved();
    loadCollected();
}

watch(
    () => props.collectedData === undefined || props.skippedLogs === undefined,
    (missing) => {
        if (missing) loadCollected();
    },
);

onMounted(loadCollected);
onBeforeUnmount(() => {
    clearTimeout(collectedTimer);
    clearTimeout(savedTimer);
});
</script>

<template>
    <Head :title="$t(':name settings', { name: machine.name })" />

    <DashHeader
        :crumbs="[
            { label: $t('Machines'), href: machinesUrl },
            {
                label: machine.name,
                logo: machine.iconUrl ? undefined : machine.provider,
                image: machine.iconUrl,
                href: machineUrl,
            },
            tab === 'status'
                ? { label: $t('Settings'), href: settingsBase }
                : { label: $t('Settings') },
            ...(tab === 'status' ? [{ label: $t('Status page') }] : []),
        ]"
    >
        <template #meta>
            <span
                v-if="saved"
                class="text-[12.5px] text-fg-faint"
                role="status"
                >{{ $t('Saved') }}</span
            >
        </template>
        <template #actions>
            <DashButton
                v-if="canManage"
                icon="terminal-fill"
                :label="$t('Terminal')"
                :href="`${machineUrl}/terminal`"
            />
            <span class="inline-flex" :title="deployBlocked ?? undefined">
                <DashButton
                    variant="primary"
                    :icon="Plus"
                    :label="$t('Deploy service')"
                    :href="deployBlocked ? undefined : `${machineUrl}?deploy`"
                    :disabled="deployBlocked !== null"
                />
            </span>
        </template>
    </DashHeader>

    <div class="flex min-h-full flex-col">
        <MachineHead
            :machine="machine"
            :workspace-slug="workspace.slug"
            :settings-url="settingsBase"
            :updates-waiting="updatesWaiting"
        />

        <div class="flex grow flex-col xl:flex-row">
            <template v-if="tab === 'general'">
                <div class="flex min-w-0 flex-1 flex-col gap-3 px-6 py-4">
                    <MachineDetails
                        v-if="machine.id"
                        :machine="machine"
                        :workspace-slug="workspace.slug"
                        :environments="environments"
                        :detected="detected"
                        :providers="providers"
                        :can-manage="canManage"
                        @saved="markSaved"
                    />
                    <CloudServerTray
                        v-if="cloudServer && canManage"
                        :cloud="cloudServer"
                        @action="serverAction"
                    />
                    <LogRulesCard
                        v-if="machine.id"
                        :machine-id="machine.id"
                        :machine-name="machine.name"
                        :workspace-slug="workspace.slug"
                        :log-skip="logSkip"
                        :skipped="skippedLogs"
                        :service-names="serviceNames"
                        :can-manage="canManage"
                        @changed="onLogRulesChanged"
                    />
                    <TrafficSettings
                        v-if="machine.id"
                        :machine-id="machine.id"
                        :machine-name="machine.name"
                        :traffic="traffic"
                        :can-manage="canManage"
                    />
                    <TunnelSettings
                        v-if="tunnel && machine.id"
                        :machine-id="machine.id"
                        :machine-name="machine.name"
                        :tunnel="tunnel"
                        :can-manage="canManage"
                    />
                </div>
                <SettingsRail
                    :machine="machine"
                    :workspace-slug="workspace.slug"
                    :workspace-name="workspace.name"
                    :policy="policy"
                    :status-page="statusSummary"
                    :plan-allows="planAllows"
                    :collected-data="collectedData"
                    :can-manage="canManage"
                    @status="show('status')"
                    @cleared="loadCollected"
                />
            </template>
            <StatusPageSettings
                v-else-if="machine.id"
                :machine="machine"
                :workspace-slug="workspace.slug"
                :status-page="statusPage"
                :plan-allows="planAllows"
                :can-manage="canManage"
                @back="show('general')"
                @saved="markSaved"
            />
        </div>
    </div>
    <MachineServerActions
        v-if="machine.id && canManage && cloudServer"
        ref="serverActions"
        :machine="{ id: machine.id, name: machine.name }"
        :cloud="cloudServer"
    />
</template>
