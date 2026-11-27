<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTray from '@/components/dash/DashTray.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { CloudflareAccount } from '@/components/integrations/cloudflare';
import CloudflareZones from '@/components/integrations/CloudflareZones.vue';
import CreateServerDialog from '@/components/integrations/CreateServerDialog.vue';
import IncidentRouting from '@/components/integrations/IncidentRouting.vue';
import type {
    IntegrationEventRow,
    LinearRouting,
    LinkedApp,
    OpenIncident,
    PagerDutyRouting,
} from '@/components/integrations/incidents';
import IntegrationEventList from '@/components/integrations/IntegrationEventList.vue';
import OpenIncidents from '@/components/integrations/OpenIncidents.vue';
import SentryApps from '@/components/integrations/SentryApps.vue';
import GitLabProjects from '@/components/integrations/GitLabProjects.vue';
import type { GitLabProject } from '@/components/integrations/GitLabProjects.vue';
import ImportServerDialog from '@/components/integrations/ImportServerDialog.vue';
import IntegrationLogo from '@/components/integrations/IntegrationLogo.vue';
import type {
    CloudServer,
    Operation,
    ProviderInfo,
} from '@/components/integrations/integrations';
import { sizeLine } from '@/components/integrations/integrations';
import OperationSteps from '@/components/integrations/OperationSteps.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { timeAgo } from '@/lib/timeAgo';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import workspaceRoutes from '@/routes/workspace';
import integrationRoutes from '@/routes/workspace/integrations';
import type { Workspace } from '@/types';

type ServerRow = CloudServer & {
    machine: { id: number; name: string; href: string } | null;
    joining: boolean;
};

const props = defineProps<{
    workspace: Workspace;
    integration: {
        id: number;
        provider: string;
        providerName: string;
        logo: string;
        label: string;
        status: string;
        summary: string;
        facts: { label: string; value: string }[];
        lastCheckedAt: string | null;
        lastError: string | null;
        createdAt: string | null;
        createdBy: string | null;
    };
    provider: ProviderInfo;
    usage: string[];
    dependents: string[];
    managesServers: boolean;
    canMakeServers: boolean;
    canImport: boolean;
    servers?: { list: ServerRow[]; error: string | null } | null;
    cloudflare?: CloudflareAccount | null;
    projects?: GitLabProject[] | null;
    operations: Operation[];
    routing?: PagerDutyRouting | LinearRouting | null;
    events?: IntegrationEventRow[] | null;
    incidents?: OpenIncident[] | null;
    linkedApps?: LinkedApp[] | null;
}>();

const args = computed(() => ({
    workspace: props.workspace.slug,
    integration: props.integration.id,
}));
const integrationsUrl = computed(
    () => onWorkspace(workspaceRoutes.integrations(props.workspace.slug)).url,
);
const failing = computed(() => props.integration.status === 'failing');
const hasHooks = computed(
    () =>
        props.projects?.some((project) => project.hook.state !== 'none') ??
        false,
);

const linked = computed(
    () => props.servers?.list.filter((server) => server.machine).length ?? 0,
);
const serversMeta = computed(() => {
    if (!props.servers) return null;
    const total = props.servers.list.length;

    return total === 1
        ? trans('1 server, :linked linked', {
              linked: String(linked.value),
          })
        : trans(':count servers, :linked linked', {
              count: String(total),
              linked: String(linked.value),
          });
});

const statusTone: Record<CloudServer['status'], string> = {
    running: 'bg-status-live',
    starting: 'bg-status-deploying',
    stopping: 'bg-status-pending',
    off: 'bg-status-stopped',
    deleting: 'bg-status-down',
    other: 'bg-status-stopped',
};
const statusLabel = computed<Record<CloudServer['status'], string>>(() => ({
    running: trans('Running'),
    starting: trans('Starting'),
    stopping: trans('Stopping'),
    off: trans('Off'),
    deleting: trans('Being deleted'),
    other: trans('Busy'),
}));

const checking = ref(false);

function check(): void {
    router.post(
        onWorkspace(integrationRoutes.check(args.value)).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (checking.value = true),
            onFinish: () => (checking.value = false),
        },
    );
}

const renaming = ref(false);
const renameForm = useForm({ label: '' });

watch(renaming, (open) => {
    if (!open) return;
    renameForm.clearErrors();
    renameForm.label = props.integration.label;
});

function rename(): void {
    renameForm.patch(onWorkspace(integrationRoutes.update(args.value)).url, {
        preserveScroll: true,
        onSuccess: () => (renaming.value = false),
    });
}

const disconnecting = ref(false);
const disconnectForm = useForm({ confirm: '' });

watch(disconnecting, (open) => {
    if (open) {
        disconnectForm.reset();
        disconnectForm.clearErrors();
    }
});

const canDisconnect = computed(
    () =>
        props.dependents.length === 0 ||
        disconnectForm.confirm.trim() === props.integration.label,
);

function disconnect(): void {
    if (!canDisconnect.value) return;
    disconnectForm.delete(
        onWorkspace(integrationRoutes.destroy(args.value)).url,
    );
}

const creating = ref(false);
const importing = ref<CloudServer | null>(null);
const importOpen = ref(false);

function startImport(server: CloudServer): void {
    importing.value = server;
    importOpen.value = true;
}

const activeOps = computed(() =>
    props.operations.filter((op) => ['queued', 'running'].includes(op.state)),
);
let opsTimer: ReturnType<typeof setTimeout> | undefined;

function followOperations(): void {
    clearTimeout(opsTimer);
    if (activeOps.value.length === 0) return;
    opsTimer = setTimeout(() => {
        const before = activeOps.value.length;
        router.reload({
            only: ['operations'],
            onSuccess: () => {
                if (activeOps.value.length < before) {
                    router.reload({ only: ['servers', 'usage', 'dependents'] });
                }
            },
            onFinish: followOperations,
        });
    }, 4000);
}

watch(() => activeOps.value.length, followOperations);

function made(): void {
    router.reload({ only: ['operations', 'servers'] });
}

function opTitle(op: Operation): string {
    return op.kind === 'create'
        ? trans('Making :name', { name: op.name || trans('a server') })
        : trans('Resizing :name to :type', {
              name: op.machineName ?? trans('a machine'),
              type: op.type ?? '',
          });
}

onMounted(() => {
    followOperations();
    if (
        props.canMakeServers &&
        new URLSearchParams(window.location.search).has('new')
    ) {
        creating.value = true;
    }
});
onBeforeUnmount(() => clearTimeout(opsTimer));

const sectionTitle =
    'text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary';
const rowLabel =
    'min-w-0 flex-1 text-[13px]/5 tracking-[-0.005em] text-fg-muted';
const fieldClass =
    'h-8 w-full rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field outline-none placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]';
</script>

<template>
    <Head :title="integration.label" />

    <DashHeader
        :crumbs="[
            { label: $t('Integrations'), href: integrationsUrl },
            { label: integration.label, logo: integration.logo },
        ]"
    >
        <template #actions>
            <DashButton
                icon="history"
                :label="$t('Check again')"
                :loading="checking"
                @click="check"
            />
            <DashButton
                v-if="canMakeServers"
                variant="primary"
                :icon="Plus"
                :label="$t('New server')"
                :disabled="failing"
                @click="creating = true"
            />
        </template>
    </DashHeader>

    <div class="flex min-h-full flex-col">
        <div class="flex flex-wrap items-center gap-3 px-6 pt-5 pb-4">
            <IntegrationLogo :logo="integration.logo" small />
            <h1
                class="min-w-0 truncate text-[20px]/7 font-medium tracking-[-0.02em] text-fg-primary"
            >
                {{ integration.label }}
            </h1>
            <StatusBadge
                :status="failing ? 'down' : 'live'"
                :label="failing ? $t('Needs a new token') : $t('Connected')"
            />
            <span class="grow" />
            <span
                v-for="chip in [integration.providerName, integration.summary]"
                :key="chip"
                class="flex h-[26px] items-center gap-1.5 rounded-full border-[0.5px] border-[#dcdcdc] bg-[#f4f4f4] px-2.5 text-[12px]/4 font-medium tracking-[-0.005em] whitespace-nowrap text-fg-secondary dark:border-[#303030] dark:bg-[#202020]"
                >{{ chip }}</span
            >
        </div>

        <div
            class="flex grow flex-col border-t-[0.5px] border-edge-tabs xl:flex-row"
        >
            <div class="flex min-w-0 flex-1 flex-col gap-2.5 px-6 py-4">
                <div
                    v-if="failing || integration.lastError"
                    class="flex items-start gap-2.5 rounded-[10px] border-[0.5px] px-3.5 py-3 text-[13px]/5"
                    :class="
                        failing
                            ? 'border-[rgba(240,105,90,0.25)] bg-[rgba(240,105,90,0.07)]'
                            : 'border-[rgba(240,148,60,0.25)] bg-[rgba(240,148,60,0.07)]'
                    "
                    role="alert"
                >
                    <FilledIcon
                        name="info-fill"
                        :size="15"
                        class="mt-0.5"
                        :class="
                            failing ? 'text-status-down' : 'text-status-pending'
                        "
                    />
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <span class="font-medium text-fg-primary">{{
                            failing
                                ? $t(':provider stopped taking this token', {
                                      provider: integration.providerName,
                                  })
                                : $t('The last check didn’t work')
                        }}</span>
                        <span class="text-fg-tertiary">{{
                            integration.lastError
                        }}</span>
                        <span v-if="failing" class="text-fg-tertiary">{{
                            $t(
                                'Make a new token, then disconnect this account and connect it again with the new one.',
                            )
                        }}</span>
                    </div>
                </div>

                <DashTray
                    v-if="managesServers"
                    icon="machines-fill"
                    :title="$t('Servers')"
                    :meta="serversMeta"
                    panel-class="py-0.5"
                >
                    <div
                        v-if="servers === undefined"
                        class="flex flex-col"
                        role="status"
                        :aria-label="$t('Loading servers')"
                    >
                        <div
                            v-for="n in 3"
                            :key="n"
                            class="flex items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
                        >
                            <span
                                class="h-3 w-28 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                            />
                            <span
                                class="h-3 w-44 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                            />
                        </div>
                    </div>
                    <template v-else-if="servers">
                        <p
                            v-if="servers.error"
                            class="border-b-[0.5px] border-edge-tray-panel px-3.5 py-2.5 text-[12.5px]/[18px] text-status-down"
                            role="alert"
                        >
                            {{ servers.error }}
                            {{
                                $t(
                                    'This is the list from the last time it worked.',
                                )
                            }}
                        </p>
                        <ul v-if="servers.list.length" class="flex flex-col">
                            <li
                                v-for="server in servers.list"
                                :key="server.id"
                                class="flex min-w-0 items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
                            >
                                <span
                                    class="size-1.5 shrink-0 rounded-full"
                                    :class="statusTone[server.status]"
                                    :title="statusLabel[server.status]"
                                />
                                <span class="sr-only">{{
                                    statusLabel[server.status]
                                }}</span>
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span
                                        class="truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                                        >{{ server.name }}</span
                                    >
                                    <span
                                        class="truncate text-[12px]/4 text-fg-muted"
                                        >{{ server.typeLabel }} ·
                                        {{ sizeLine(server) }} ·
                                        {{ server.city ?? server.location }}
                                    </span>
                                </span>
                                <span
                                    class="w-[132px] shrink-0 truncate font-ui-mono text-[11.5px]/4 text-fg-tertiary max-md:hidden"
                                    >{{
                                        server.ipv4 ?? server.ipv6 ?? '–'
                                    }}</span
                                >
                                <span
                                    class="flex w-[150px] shrink-0 justify-end max-sm:w-auto"
                                >
                                    <Link
                                        v-if="server.machine"
                                        :href="
                                            workspacePath(server.machine.href)
                                        "
                                        class="flex max-w-full items-center gap-1.5 rounded-[6px] px-1.5 py-0.5 text-[13px]/5 text-fg-secondary hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                        :title="
                                            $t('Linked to :machine', {
                                                machine: server.machine.name,
                                            })
                                        "
                                    >
                                        <FilledIcon
                                            name="machines-fill"
                                            :size="14"
                                            class="text-fg-muted"
                                        />
                                        <span class="truncate">{{
                                            server.machine.name
                                        }}</span>
                                        <FilledIcon
                                            name="chevron-right"
                                            :size="13"
                                            class="text-fg-muted"
                                        />
                                    </Link>
                                    <StatusBadge
                                        v-else-if="server.joining"
                                        status="deploying"
                                        :label="$t('Joining')"
                                    />
                                    <DashButton
                                        v-else-if="canImport"
                                        size="sm"
                                        :label="$t('Import')"
                                        :aria-label="
                                            $t('Import :name', {
                                                name: server.name,
                                            })
                                        "
                                        @click="startImport(server)"
                                    />
                                    <span
                                        v-else
                                        class="text-[12px]/4 text-fg-muted"
                                        >{{ $t('Not in ServerOS') }}</span
                                    >
                                </span>
                            </li>
                        </ul>
                        <div
                            v-else
                            class="flex flex-col items-start gap-2 px-3.5 py-3"
                        >
                            <p class="text-[13px]/5 text-fg-muted">
                                {{ $t('No servers here yet.') }}
                            </p>
                            <DashButton
                                v-if="canMakeServers"
                                size="sm"
                                :icon="Plus"
                                :label="$t('New server')"
                                @click="creating = true"
                            />
                        </div>
                    </template>
                </DashTray>

                <CloudflareZones
                    v-if="cloudflare !== null"
                    :account="cloudflare"
                />

                <GitLabProjects v-if="projects !== null" :projects="projects" />

                <IncidentRouting
                    v-if="
                        routing &&
                        (integration.provider === 'pagerduty' ||
                            integration.provider === 'linear')
                    "
                    :provider="integration.provider"
                    :routing="routing"
                    :workspace-slug="workspace.slug"
                    :integration-id="integration.id"
                    :disabled="failing"
                />
                <OpenIncidents
                    v-if="incidents?.length"
                    :incidents="incidents"
                    :title="
                        integration.provider === 'linear'
                            ? $t('Open issues')
                            : $t('Open alerts')
                    "
                />
                <SentryApps v-if="linkedApps" :apps="linkedApps" />
                <IntegrationEventList
                    v-if="events"
                    :events="events"
                    :workspace-slug="workspace.slug"
                    :integration-id="integration.id"
                    :empty="
                        integration.provider === 'sentry'
                            ? $t(
                                  'Nothing sent yet. Each deploy of a linked app makes a release here.',
                              )
                            : $t(
                                  'Nothing sent yet. It shows here as soon as something goes wrong, or when you send a test.',
                              )
                    "
                />

                <DashTray
                    v-if="operations.length"
                    icon="activity-fill"
                    :title="$t('Recent changes')"
                    panel-class="py-0.5"
                >
                    <ul class="flex flex-col">
                        <li
                            v-for="op in operations"
                            :key="op.id"
                            class="flex flex-col gap-2.5 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
                        >
                            <div class="flex items-center gap-2.5">
                                <span
                                    class="min-w-0 flex-1 truncate text-[13px]/5 font-medium text-fg-primary"
                                    >{{ opTitle(op) }}</span
                                >
                                <span class="text-[12px]/4 text-fg-muted">{{
                                    timeAgo(op.createdAt)
                                }}</span>
                                <StatusBadge
                                    :status="
                                        op.state === 'done'
                                            ? 'live'
                                            : op.state === 'failed'
                                              ? 'down'
                                              : 'deploying'
                                    "
                                    :label="
                                        op.state === 'done'
                                            ? $t('Done')
                                            : op.state === 'failed'
                                              ? $t('Failed')
                                              : $t('In progress')
                                    "
                                />
                            </div>
                            <OperationSteps
                                v-if="op.state !== 'done'"
                                :operation="op"
                            />
                        </li>
                    </ul>
                </DashTray>
            </div>

            <aside
                class="flex w-full shrink-0 flex-col gap-[22px] border-edge-tabs p-5 max-xl:border-t-[0.5px] xl:w-[316px] xl:border-l-[0.5px]"
            >
                <section
                    class="flex flex-col gap-2.5"
                    :aria-label="$t('Account')"
                >
                    <div class="flex items-center gap-2">
                        <h2 :class="[sectionTitle, 'flex-1']">
                            {{ $t('Account') }}
                        </h2>
                        <DashButton
                            size="sm"
                            :label="$t('Rename')"
                            @click="renaming = true"
                        />
                    </div>
                    <div class="flex items-center gap-2">
                        <span :class="rowLabel">{{ $t('Provider') }}</span>
                        <span class="text-[13px]/5 text-fg-secondary">{{
                            integration.providerName
                        }}</span>
                    </div>
                    <div
                        v-for="fact in integration.facts"
                        :key="fact.label"
                        class="flex items-center gap-2"
                    >
                        <span :class="rowLabel">{{ fact.label }}</span>
                        <span
                            class="text-[13px]/5 text-fg-secondary tabular-nums"
                            >{{ fact.value }}</span
                        >
                    </div>
                    <div class="flex items-center gap-2">
                        <span :class="rowLabel">{{ $t('Last checked') }}</span>
                        <span class="text-[13px]/5 text-fg-secondary">{{
                            integration.lastCheckedAt
                                ? timeAgo(integration.lastCheckedAt)
                                : '–'
                        }}</span>
                    </div>
                    <div class="flex items-center gap-2">
                        <span :class="rowLabel">{{ $t('Connected') }}</span>
                        <span
                            class="truncate text-[13px]/5 text-fg-secondary"
                            >{{
                                integration.createdBy
                                    ? $t(':when by :name', {
                                          when: timeAgo(integration.createdAt),
                                          name: integration.createdBy,
                                      })
                                    : timeAgo(integration.createdAt)
                            }}</span
                        >
                    </div>
                    <div class="flex items-center gap-2">
                        <span :class="rowLabel">{{ $t('Token') }}</span>
                        <span
                            class="flex items-center gap-1.5 text-[13px]/5 text-fg-secondary"
                            ><FilledIcon
                                name="lock-fill"
                                :size="13"
                                class="text-fg-muted"
                            />{{ $t('Encrypted') }}</span
                        >
                    </div>
                </section>

                <section
                    v-if="usage.length"
                    class="flex flex-col gap-2 border-t-[0.5px] border-edge-tabs pt-[18px]"
                    :aria-label="$t('Used for')"
                >
                    <h2 :class="sectionTitle">{{ $t('Used for') }}</h2>
                    <p
                        v-for="line in usage"
                        :key="line"
                        class="text-[13px]/5 text-fg-tertiary"
                    >
                        {{ line }}
                    </p>
                </section>

                <section
                    class="flex flex-col gap-2.5 border-t-[0.5px] border-edge-tabs pt-[18px]"
                    :aria-label="$t('What it can do')"
                >
                    <h2 :class="sectionTitle">{{ $t('What it can do') }}</h2>
                    <div
                        v-for="capability in provider.capabilities"
                        :key="capability.key"
                        class="flex items-start gap-2"
                    >
                        <FilledIcon
                            name="check"
                            :size="14"
                            class="mt-[3px] text-status-live"
                        />
                        <span class="flex min-w-0 flex-col">
                            <span class="text-[13px]/5 text-fg-secondary">{{
                                capability.label
                            }}</span>
                            <span class="text-[12px]/4 text-fg-muted">{{
                                capability.description
                            }}</span>
                        </span>
                    </div>
                </section>

                <section
                    class="flex flex-col gap-2.5 border-t-[0.5px] border-edge-tabs pt-[18px]"
                    :aria-label="$t('Disconnect')"
                >
                    <h2 :class="sectionTitle">{{ $t('Disconnect') }}</h2>
                    <div class="flex items-center gap-2.5">
                        <p
                            class="min-w-0 flex-1 text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        >
                            {{
                                hasHooks
                                    ? $t(
                                          'ServerOS forgets the token and takes its webhooks off :provider. Nothing else changes there.',
                                          {
                                              provider:
                                                  integration.providerName,
                                          },
                                      )
                                    : $t(
                                          'ServerOS forgets the token. Nothing changes at :provider.',
                                          {
                                              provider:
                                                  integration.providerName,
                                          },
                                      )
                            }}
                        </p>
                        <DashButton
                            size="sm"
                            class="text-status-down!"
                            :label="$t('Disconnect')"
                            @click="disconnecting = true"
                        />
                    </div>
                </section>
            </aside>
        </div>
    </div>

    <CreateServerDialog
        v-if="canMakeServers"
        v-model:open="creating"
        :integration="integration"
        @made="made"
    />
    <ImportServerDialog
        v-model:open="importOpen"
        :server="importing"
        :provider-name="integration.providerName"
    />

    <Dialog v-model:open="renaming">
        <DialogContent
            class="workspace-panel ds-modal gap-0 !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[420px]"
        >
            <form class="flex flex-col" @submit.prevent="rename">
                <div class="flex flex-col gap-0.5 px-5 pt-[18px] pb-4">
                    <DialogTitle
                        class="text-[16px]/6 font-medium tracking-[-0.01em]"
                        >{{ $t('Rename the account') }}</DialogTitle
                    >
                    <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                        {{
                            $t(
                                'Only ServerOS uses this name. It doesn’t change at :provider.',
                                { provider: integration.providerName },
                            )
                        }}
                    </DialogDescription>
                </div>
                <div class="flex flex-col gap-1.5 px-5 pb-[18px]">
                    <input
                        v-model="renameForm.label"
                        type="text"
                        maxlength="80"
                        required
                        autofocus
                        :aria-label="$t('Name')"
                        :class="fieldClass"
                    />
                    <span
                        v-if="renameForm.errors.label"
                        class="text-[12px]/4 text-status-down"
                        role="alert"
                        >{{ renameForm.errors.label }}</span
                    >
                </div>
                <div
                    class="flex justify-end gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
                >
                    <DashButton
                        :label="$t('Cancel')"
                        @click="renaming = false"
                    />
                    <DashButton
                        type="submit"
                        variant="primary"
                        :label="$t('Save')"
                        :loading="renameForm.processing"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="disconnecting">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <form class="flex flex-col gap-4" @submit.prevent="disconnect">
                <div class="flex flex-col gap-1.5">
                    <DialogTitle>{{
                        $t('Disconnect :name?', { name: integration.label })
                    }}</DialogTitle>
                    <DialogDescription>
                        {{
                            $t(
                                'ServerOS forgets the token and stops showing this account. Your servers keep running, and nothing changes at :provider.',
                                { provider: integration.providerName },
                            )
                        }}
                    </DialogDescription>
                </div>
                <ul
                    v-if="dependents.length"
                    class="flex flex-col gap-1 rounded-[8px] border-[0.5px] border-[rgba(240,148,60,0.25)] bg-[rgba(240,148,60,0.07)] px-3 py-2.5 text-[12.5px]/[18px] text-fg-secondary"
                >
                    <li v-for="line in dependents" :key="line">{{ line }}</li>
                </ul>
                <div v-if="dependents.length" class="grid gap-2">
                    <label for="disconnect-integration" class="text-[13px]/5">
                        {{ ' ' + $t('Type') }}
                        <span class="font-ui-mono text-fg-primary">{{
                            integration.label
                        }}</span>
                        {{ $t('to confirm.') + ' ' }}
                    </label>
                    <div class="dash-field font-ui-mono">
                        <input
                            id="disconnect-integration"
                            v-model="disconnectForm.confirm"
                            autocomplete="off"
                            spellcheck="false"
                        />
                    </div>
                    <span
                        v-if="disconnectForm.errors.confirm"
                        class="text-[12px]/4 text-status-down"
                        role="alert"
                        >{{ disconnectForm.errors.confirm }}</span
                    >
                </div>
                <div class="flex justify-end gap-2">
                    <DashButton
                        :label="$t('Cancel')"
                        @click="disconnecting = false"
                    />
                    <DashButton
                        type="submit"
                        variant="danger"
                        :label="$t('Disconnect')"
                        :loading="disconnectForm.processing"
                        :disabled="!canDisconnect"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
