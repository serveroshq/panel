<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import type { MachineCloudServer } from '@/components/integrations/integrations';
import { sendJson } from '@/components/integrations/integrations';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import serverRoutes from '@/routes/workspace/machines/server';
import type { Workspace } from '@/types';

const { machine, cloud, kind } = defineProps<{
    machine: { id: number; name: string };
    cloud: MachineCloudServer;
    kind: 'reboot' | 'power_cycle';
}>();

const open = defineModel<boolean>('open', { default: false });
const page = usePage<{ workspace: Workspace }>();
const sending = ref(false);
const error = ref('');

watch(open, (shown) => {
    if (shown) error.value = '';
});

const hard = computed(() => kind === 'power_cycle');

async function confirm(): Promise<void> {
    sending.value = true;
    error.value = '';
    const args = { workspace: page.props.workspace.slug, machine: machine.id };
    const route = hard.value
        ? serverRoutes.powerCycle(args)
        : serverRoutes.reboot(args);
    const result = await sendJson<{ message: string }>(
        'POST',
        onWorkspace(route).url,
    );
    sending.value = false;
    if (!result.ok) {
        error.value = result.message || trans('That didn’t work. Try again.');

        return;
    }
    open.value = false;
    toast.success(result.data.message);
    router.reload({ only: ['machine', 'live'] });
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    hard
                        ? $t('Power cycle :name?', { name: machine.name })
                        : $t('Restart :name?', { name: machine.name })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        hard
                            ? $t(
                                  ':provider cuts its power and starts it again, like pulling the plug. Use it when it’s stuck. Anything not yet written to disk is lost.',
                                  { provider: cloud.integration.name },
                              )
                            : cloud.rules.gracefulRestart
                              ? $t(
                                    ':provider asks its system to restart, like pressing the power button once. It works even when the daemon is offline.',
                                    { provider: cloud.integration.name },
                                )
                              : $t(
                                    ':provider restarts it straight away, like pressing the reset button. It works even when the daemon is offline. Anything not yet written to disk is lost.',
                                    { provider: cloud.integration.name },
                                )
                    }}
                </DialogDescription>
            </DialogHeader>
            <p
                v-if="error"
                class="text-[12.5px]/[18px] text-status-down"
                role="alert"
            >
                {{ error }}
            </p>
            <div class="flex justify-end gap-2">
                <DashButton :label="$t('Cancel')" @click="open = false" />
                <DashButton
                    :variant="hard ? 'danger' : 'primary'"
                    :label="hard ? $t('Power cycle') : $t('Restart')"
                    :loading="sending"
                    @click="confirm"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
