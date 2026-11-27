<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashHeader from '@/components/dash/DashHeader.vue';
import DashTab from '@/components/dash/DashTab.vue';
import DashTabs from '@/components/dash/DashTabs.vue';
import DashTray from '@/components/dash/DashTray.vue';
import SearchWell from '@/components/dash/SearchWell.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import ConnectDialog from '@/components/integrations/ConnectDialog.vue';
import IntegrationLogo from '@/components/integrations/IntegrationLogo.vue';
import IntegrationRow from '@/components/integrations/IntegrationRow.vue';
import type {
    Integration,
    IntegrationCategory,
    IntegrationConnection,
    ProviderInfo,
} from '@/components/integrations/integrations';
import UnavailableDialog from '@/components/integrations/UnavailableDialog.vue';
import AlertsDialog from '@/components/monitors/AlertsDialog.vue';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import settingsRoutes from '@/routes/workspace/settings';
import type { Workspace } from '@/types';

type Props = {
    workspace: Workspace;
    categories: IntegrationCategory[];
    integrations: Integration[];
    connections: IntegrationConnection[];
    canConnect: boolean;
    providers: Record<string, ProviderInfo>;
    alerts: {
        email: boolean;
        webhook: string | null;
        muted: string[];
        telegram: { chat: string | null; set: boolean };
    } | null;
};

const props = defineProps<Props>();
const page = usePage<{ feedbackUrl: string | null }>();

const requestUrl = computed(() =>
    page.props.feedbackUrl
        ? `${page.props.feedbackUrl.replace(/\/$/, '')}/`
        : null,
);

const tab = ref('all');
const search = ref('');
const searchWell =
    useTemplateRef<InstanceType<typeof SearchWell>>('searchWell');

const connectedCount = computed(
    () => props.integrations.filter((item) => item.connected).length,
);

const tabs = computed<
    { value: string; label: string; icon: FilledIconName; count: number }[]
>(() => [
    {
        value: 'all',
        label: trans('All'),
        icon: 'table-fill',
        count: props.integrations.length,
    },
    {
        value: 'connected',
        label: trans('Connected'),
        icon: 'package-fill',
        count: connectedCount.value,
    },
    ...props.categories.map((category) => ({
        value: category.key,
        label: category.tab,
        icon: category.icon,
        count: props.integrations.filter(
            (item) => item.category === category.key,
        ).length,
    })),
]);

function matches(item: Integration, query: string): boolean {
    if (query === '') {
        return true;
    }

    return [item.name, item.description, ...item.tags].some((text) =>
        text.toLowerCase().includes(query),
    );
}

const groups = computed(() => {
    const query = search.value.trim().toLowerCase();
    const shown = props.integrations.filter(
        (item) =>
            (tab.value === 'all' ||
                (tab.value === 'connected'
                    ? item.connected
                    : item.category === tab.value)) &&
            matches(item, query),
    );

    return props.categories
        .map((category) => ({
            ...category,
            items: shown.filter((item) => item.category === category.key),
        }))
        .filter((group) => group.items.length > 0);
});

const alertsOpen = ref(false);
const unavailable = ref<Integration | null>(null);
const unavailableOpen = ref(false);
const connecting = ref<Integration | null>(null);
const connectOpen = ref(false);
const connectProvider = computed<ProviderInfo | null>(() =>
    connecting.value?.action?.connect
        ? (props.providers[connecting.value.action.connect] ?? null)
        : null,
);

function act(integration: Integration): void {
    if (
        integration.action?.connect &&
        props.providers[integration.action.connect]
    ) {
        connecting.value = integration;
        connectOpen.value = true;

        return;
    }

    if (integration.action?.unavailable) {
        unavailable.value = integration;
        unavailableOpen.value = true;

        return;
    }

    if (integration.action?.alerts && props.alerts) {
        alertsOpen.value = true;
    }
}

const apiHref = computed(
    () => onWorkspace(settingsRoutes.api(props.workspace.slug)).url,
);
const mcpHref = computed(
    () =>
        `${onWorkspace(settingsRoutes.mcp(props.workspace.slug)).url}#connect`,
);

function onKeydown(event: KeyboardEvent): void {
    const target = event.target as HTMLElement | null;
    const typing =
        target?.isContentEditable ||
        ['INPUT', 'TEXTAREA', 'SELECT'].includes(target?.tagName ?? '');

    if (event.key === '/' && !typing && !event.metaKey && !event.ctrlKey) {
        event.preventDefault();
        searchWell.value?.focus();
    }
}

onMounted(() => window.addEventListener('keydown', onKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onKeydown));
</script>

<template>
    <Head :title="$t('Integrations')" />

    <DashHeader :crumbs="[{ label: $t('Integrations') }]">
        <template v-if="requestUrl" #actions>
            <DashButton
                :href="requestUrl"
                external
                :label="$t('Request an integration')"
            />
        </template>
    </DashHeader>

    <div class="@container flex min-h-full min-w-0 flex-col">
        <DashTabs>
            <DashTab
                v-for="item in tabs"
                :key="item.value"
                :label="item.label"
                :icon="item.icon"
                :active="tab === item.value"
                @click="tab = item.value"
            >
                <template #suffix>
                    <span class="-ml-[3.5px] text-fg-muted tabular-nums">{{
                        item.count
                    }}</span>
                </template>
            </DashTab>
        </DashTabs>

        <div class="flex min-h-14 flex-wrap items-center gap-2 px-5 py-3">
            <SearchWell
                ref="searchWell"
                v-model="search"
                :placeholder="$t('Search integrations')"
                :width="260"
                shortcut="/"
                class="max-sm:w-full!"
            />
            <span class="grow" />
            <span class="text-[12px]/4 text-fg-muted tabular-nums">{{
                $t(':count of :total connected', {
                    count: String(connectedCount),
                    total: String(integrations.length),
                })
            }}</span>
        </div>

        <div
            class="flex min-w-0 flex-col gap-5 px-5 pt-1 pb-12 @5xl:flex-row @5xl:items-start"
        >
            <div class="flex min-w-0 flex-1 flex-col gap-4">
                <DashTray
                    v-for="group in groups"
                    :key="group.key"
                    :icon="group.icon"
                    :title="group.title"
                    :meta="group.subtitle"
                >
                    <ul class="flex flex-col">
                        <IntegrationRow
                            v-for="item in group.items"
                            :key="item.key"
                            :integration="item"
                            @act="act"
                        />
                    </ul>
                </DashTray>

                <div
                    v-if="groups.length === 0"
                    class="flex flex-col items-center gap-1 rounded-[14px] border-[0.5px] border-dashed border-edge-row px-6 py-14 text-center"
                >
                    <p class="text-[13px]/5 font-medium text-fg-primary">
                        {{
                            tab === 'connected' && search.trim() === ''
                                ? $t('Nothing connected yet')
                                : $t('No integrations match')
                        }}
                    </p>
                    <p class="text-[13px]/5 text-fg-muted">
                        {{
                            tab === 'connected' && search.trim() === ''
                                ? $t(
                                      'Connect one from the list and it shows up here.',
                                  )
                                : $t('Try another name, or look in All.')
                        }}
                    </p>
                </div>
            </div>

            <aside
                class="flex min-w-0 flex-col gap-4 @5xl:w-[300px] @5xl:shrink-0"
            >
                <DashTray
                    icon="package-fill"
                    :title="$t('Connected')"
                    :meta="String(connections.length)"
                    panel-class="py-1"
                >
                    <ul v-if="connections.length" class="flex flex-col">
                        <li
                            v-for="connection in connections"
                            :key="connection.key"
                            class="border-t-[0.5px] border-edge-tray-panel first:border-t-0"
                        >
                            <component
                                :is="
                                    connection.target?.href
                                        ? Link
                                        : connection.target?.alerts && alerts
                                          ? 'button'
                                          : 'div'
                                "
                                :href="
                                    connection.target?.href
                                        ? workspacePath(connection.target.href)
                                        : undefined
                                "
                                :type="
                                    connection.target?.alerts && alerts
                                        ? 'button'
                                        : undefined
                                "
                                class="flex w-full min-w-0 items-center gap-2.5 px-3 py-[9px] text-left"
                                :class="
                                    connection.target
                                        ? 'cursor-pointer transition-colors hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)'
                                        : ''
                                "
                                @click="
                                    connection.target?.alerts && alerts
                                        ? (alertsOpen = true)
                                        : undefined
                                "
                            >
                                <IntegrationLogo
                                    :logo="connection.logo"
                                    small
                                />
                                <span
                                    class="flex min-w-0 flex-1 flex-col gap-px"
                                >
                                    <span
                                        class="truncate text-[13px]/[18px] font-medium text-fg-primary"
                                        >{{ connection.name }}</span
                                    >
                                    <span
                                        class="truncate text-[12px]/4 text-fg-muted"
                                        >{{ connection.detail }}</span
                                    >
                                </span>
                                <span
                                    class="size-1.5 shrink-0 rounded-full"
                                    :class="
                                        connection.failing
                                            ? 'bg-status-down'
                                            : 'bg-status-live'
                                    "
                                    :aria-label="
                                        connection.failing
                                            ? $t('Needs attention')
                                            : $t('Connected')
                                    "
                                />
                            </component>
                        </li>
                    </ul>
                    <p v-else class="px-3.5 py-2 text-[13px]/5 text-fg-muted">
                        {{ $t('Nothing connected yet.') }}
                    </p>
                </DashTray>

                <DashTray icon="code-fill" :title="$t('Build your own')">
                    <div class="flex flex-col items-start gap-2.5 px-3.5 py-3">
                        <p class="text-[13px]/[19px] text-fg-secondary">
                            {{
                                $t(
                                    'Anything with an API can work with ServerOS through our API and MCP server.',
                                )
                            }}
                        </p>
                        <Link
                            :href="apiHref"
                            class="flex items-center gap-1.5 text-[13px]/[18px] text-fg-primary hover:underline"
                        >
                            {{ $t('Using the API') }}
                            <FilledIcon
                                name="chevron-right"
                                :size="13"
                                class="text-fg-muted"
                            />
                        </Link>
                        <Link
                            :href="mcpHref"
                            class="flex items-center gap-1.5 text-[13px]/[18px] text-fg-primary hover:underline"
                        >
                            {{ $t('Connect an AI app') }}
                            <FilledIcon
                                name="chevron-right"
                                :size="13"
                                class="text-fg-muted"
                            />
                        </Link>
                    </div>
                </DashTray>
            </aside>
        </div>
    </div>

    <AlertsDialog v-if="alerts" v-model:open="alertsOpen" :alerts="alerts" />
    <ConnectDialog
        v-model:open="connectOpen"
        :provider="connectProvider"
        :logo="connecting?.logo"
    />
    <UnavailableDialog
        v-model:open="unavailableOpen"
        :integration="unavailable"
        :request-url="requestUrl"
    />
</template>
