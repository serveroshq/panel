<script setup lang="ts">
import { ref } from 'vue';
import type { MachineCloudServer } from '@/components/integrations/integrations';
import DeleteServerDialog from '@/components/machines/server/DeleteServerDialog.vue';
import PowerDialog from '@/components/machines/server/PowerDialog.vue';
import ResizeDialog from '@/components/machines/server/ResizeDialog.vue';
import SnapshotsDialog from '@/components/machines/server/SnapshotsDialog.vue';

export type ServerAction =
    | 'resize'
    | 'snapshots'
    | 'reboot'
    | 'power_cycle'
    | 'delete';

const { machine, cloud } = defineProps<{
    machine: { id: number; name: string };
    cloud: MachineCloudServer;
}>();

const resizeOpen = ref(false);
const snapshotsOpen = ref(false);
const powerOpen = ref(false);
const deleteOpen = ref(false);
const powerKind = ref<'reboot' | 'power_cycle'>('reboot');

function open(action: ServerAction): void {
    if (action === 'resize') resizeOpen.value = true;
    if (action === 'snapshots') snapshotsOpen.value = true;
    if (action === 'delete') deleteOpen.value = true;
    if (action === 'reboot' || action === 'power_cycle') {
        powerKind.value = action;
        powerOpen.value = true;
    }
}

defineExpose({ open });
</script>

<template>
    <ResizeDialog
        v-if="cloud.can.includes('resize')"
        v-model:open="resizeOpen"
        :machine="machine"
        :cloud="cloud"
    />
    <SnapshotsDialog
        v-if="cloud.can.includes('snapshots')"
        v-model:open="snapshotsOpen"
        :machine="machine"
        :cloud="cloud"
    />
    <PowerDialog
        v-if="cloud.can.includes('power')"
        v-model:open="powerOpen"
        :machine="machine"
        :cloud="cloud"
        :kind="powerKind"
    />
    <DeleteServerDialog
        v-if="cloud.can.includes('delete_servers')"
        v-model:open="deleteOpen"
        :machine="machine"
        :cloud="cloud"
    />
</template>
