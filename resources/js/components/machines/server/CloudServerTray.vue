<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import type { MachineCloudServer } from '@/components/integrations/integrations';
import { sizeLine } from '@/components/integrations/integrations';
import OperationSteps from '@/components/integrations/OperationSteps.vue';
import { operationActive } from '@/components/integrations/useOperation';
import type { ServerAction } from '@/components/machines/server/MachineServerActions.vue';
import SettingsRow from '@/components/machines/settings/SettingsRow.vue';
import { onWorkspace } from '@/lib/workspaceRoute';
import integrationRoutes from '@/routes/workspace/integrations';
import type { Workspace } from '@/types';

const { cloud } = defineProps<{ cloud: MachineCloudServer }>();
const page = usePage<{ workspace: Workspace }>();
const accountUrl = computed(
    () =>
        onWorkspace(
            integrationRoutes.show({
                workspace: page.props.workspace.slug,
                integration: cloud.integration.id,
            }),
        ).url,
);

defineEmits<{ action: [action: ServerAction] }>();

const busy = computed(() => operationActive(cloud.operation));
const place = computed(() =>
    [cloud.server.city, cloud.server.location].filter(Boolean).join(' · '),
);
const statusText = computed(
    () =>
        ({
            running: trans('Running'),
            starting: trans('Starting'),
            stopping: trans('Stopping'),
            off: trans('Off'),
            deleting: trans('Being deleted'),
            other: trans('Busy'),
        })[cloud.server.status],
);
</script>

<template>
    <DashTray
        panel-class="gap-1 px-[18px] pt-3 pb-1"
        icon="machines-fill"
        :title="$t(':provider server', { provider: cloud.integration.name })"
        :meta="cloud.integration.label"
    >
        <template #actions>
            <Link
                :href="accountUrl"
                class="flex items-center gap-1 text-[12.5px]/4 text-fg-muted hover:text-fg-primary"
                >{{ $t('Account') }}</Link
            >
        </template>
        <div
            v-if="busy && cloud.operation"
            class="border-b-[0.5px] border-edge-tabs py-3"
        >
            <OperationSteps :operation="cloud.operation" />
        </div>
        <SettingsRow
            :label="$t('Server')"
            :hint="`${cloud.server.name} · ${statusText}`"
        />
        <SettingsRow
            :label="$t('Type')"
            :hint="`${cloud.server.typeLabel} · ${sizeLine(cloud.server)}`"
        >
            <DashButton
                v-if="cloud.can.includes('resize')"
                size="sm"
                :label="$t('Resize')"
                :disabled="busy"
                @click="$emit('action', 'resize')"
            />
        </SettingsRow>
        <SettingsRow :label="$t('Location')" :hint="place" />
        <SettingsRow
            v-if="cloud.can.includes('snapshots')"
            :label="$t('Snapshots')"
            :hint="
                $t('Copies of its disk, kept at :provider', {
                    provider: cloud.integration.name,
                })
            "
        >
            <DashButton
                size="sm"
                :label="$t('Snapshots')"
                @click="$emit('action', 'snapshots')"
            />
        </SettingsRow>
        <SettingsRow
            v-if="cloud.can.includes('power')"
            :label="$t('Power')"
            :hint="$t('Works even when the daemon is offline')"
        >
            <DashButton
                size="sm"
                :label="$t('Restart')"
                :disabled="busy"
                @click="$emit('action', 'reboot')"
            />
            <DashButton
                size="sm"
                :label="$t('Power cycle')"
                :disabled="busy"
                @click="$emit('action', 'power_cycle')"
            />
        </SettingsRow>
        <SettingsRow
            v-if="cloud.can.includes('delete_servers')"
            :label="$t('Delete the server')"
            :hint="
                $t('Deletes it and its disk at :provider for good', {
                    provider: cloud.integration.name,
                })
            "
        >
            <DashButton
                size="sm"
                class="text-status-down!"
                :label="$t('Delete server')"
                :disabled="busy"
                @click="$emit('action', 'delete')"
            />
        </SettingsRow>
    </DashTray>
</template>
