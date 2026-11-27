<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { CloudflareAccount } from '@/components/integrations/cloudflare';
import { workspacePath } from '@/lib/workspaceRoute';

const props = defineProps<{ account?: CloudflareAccount }>();

const zonesMeta = computed(() => {
    if (!props.account) return null;
    const total = props.account.zones.length;

    return total === 1
        ? trans('1 zone')
        : trans(':count zones', { count: String(total) });
});
const recordsMeta = computed(() =>
    props.account ? String(props.account.records.length) : null,
);
const zoneStatus = computed<Record<string, string>>(() => ({
    pending: trans('Waiting for nameservers'),
    initializing: trans('Setting up'),
    moved: trans('Moved away'),
    deactivated: trans('Turned off'),
    read_only: trans('Read only'),
}));
</script>

<template>
    <DashTray
        icon="globe-fill"
        :title="$t('Zones')"
        :meta="zonesMeta"
        panel-class="py-0.5"
    >
        <div
            v-if="!account"
            class="flex flex-col"
            role="status"
            :aria-label="$t('Loading zones')"
        >
            <div
                v-for="n in 3"
                :key="n"
                class="flex items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
            >
                <span
                    class="h-3 w-32 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
                <span
                    class="ml-auto h-3 w-24 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
            </div>
        </div>
        <template v-else>
            <p
                v-if="account.error"
                class="border-b-[0.5px] border-edge-tray-panel px-3.5 py-2.5 text-[12.5px]/[18px] text-status-down"
                role="alert"
            >
                {{ account.error }}
            </p>
            <ul v-if="account.zones.length" class="flex flex-col">
                <li
                    v-for="zone in account.zones"
                    :key="zone.id"
                    class="flex min-w-0 items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
                >
                    <span
                        class="size-1.5 shrink-0 rounded-full"
                        :class="
                            zone.status === 'active'
                                ? 'bg-status-live'
                                : 'bg-status-stopped'
                        "
                    />
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span
                            class="truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                            >{{ zone.name }}</span
                        >
                        <span
                            v-if="zone.status !== 'active'"
                            class="truncate text-[12px]/4 text-fg-muted"
                            >{{ zoneStatus[zone.status] ?? zone.status }}</span
                        >
                    </span>
                    <span
                        class="shrink-0 text-[12px]/4 tabular-nums"
                        :class="
                            zone.managed ? 'text-fg-secondary' : 'text-fg-muted'
                        "
                        >{{
                            zone.managed === 0
                                ? $t('No records from ServerOS')
                                : zone.managed === 1
                                  ? $t('1 record from ServerOS')
                                  : $t(':count records from ServerOS', {
                                        count: String(zone.managed),
                                    })
                        }}</span
                    >
                </li>
            </ul>
            <p
                v-else-if="!account.error"
                class="px-3.5 py-3 text-[13px]/5 text-fg-muted"
            >
                {{
                    $t(
                        'This token can’t see any zones. Give it Zone Read for the zones ServerOS may change.',
                    )
                }}
            </p>
        </template>
    </DashTray>

    <DashTray
        v-if="account"
        icon="layers-fill"
        :title="$t('Records ServerOS manages')"
        :meta="recordsMeta"
        panel-class="py-0.5"
    >
        <ul v-if="account.records.length" class="flex flex-col">
            <li
                v-for="record in account.records"
                :key="record.hostname"
                class="flex min-w-0 items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
            >
                <span class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate font-ui-mono text-[12.5px]/5 text-fg-primary"
                        >{{ record.hostname }}</span
                    >
                    <span
                        v-if="record.status === 'failed'"
                        class="truncate text-[12px]/4 text-status-pending"
                        :title="record.error ?? undefined"
                        >{{ record.error }}</span
                    >
                    <span v-else class="truncate text-[12px]/4 text-fg-muted"
                        >{{ record.type }} →
                        <span class="font-ui-mono">{{ record.content }}</span>
                        <template v-if="!record.created">
                            · {{ $t('was there before ServerOS') }}</template
                        ></span
                    >
                </span>
                <span
                    v-if="record.status !== 'failed'"
                    class="flex h-[22px] shrink-0 items-center rounded-full border-[0.5px] px-2 text-[11.5px]/4 font-medium whitespace-nowrap"
                    :class="
                        record.proxied
                            ? 'border-[rgba(240,148,60,0.3)] bg-[rgba(240,148,60,0.1)] text-status-pending'
                            : 'border-edge-tray-panel bg-surface-badge text-fg-tertiary'
                    "
                    >{{ record.proxied ? $t('Proxied') : $t('DNS only') }}</span
                >
                <Link
                    :href="
                        workspacePath(
                            `/machines/${record.machineId}/services/app:${record.app}`,
                        )
                    "
                    class="flex w-[170px] shrink-0 items-center justify-end gap-1.5 rounded-[6px] px-1.5 py-0.5 text-[13px]/5 text-fg-secondary hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) max-sm:w-auto"
                    :title="
                        $t(':app on :machine', {
                            app: record.app,
                            machine: record.machine ?? '',
                        })
                    "
                >
                    <span class="truncate"
                        >{{ record.app
                        }}<span class="text-fg-muted">
                            · {{ record.machine }}</span
                        ></span
                    >
                    <FilledIcon
                        name="chevron-right"
                        :size="13"
                        class="shrink-0 text-fg-muted"
                    />
                </Link>
            </li>
        </ul>
        <p v-else class="px-3.5 py-3 text-[13px]/5 text-fg-muted">
            {{
                $t(
                    'None yet. Deploy an app with a domain in one of these zones and ServerOS points it at the machine.',
                )
            }}
        </p>
    </DashTray>
</template>
