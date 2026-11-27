<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import type { MachineCloudServer } from '@/components/integrations/integrations';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { onWorkspace } from '@/lib/workspaceRoute';
import serverRoutes from '@/routes/workspace/machines/server';
import type { Workspace } from '@/types';

const { machine, cloud } = defineProps<{
    machine: { id: number; name: string };
    cloud: MachineCloudServer;
}>();

const open = defineModel<boolean>('open', { default: false });
const page = usePage<{ workspace: Workspace }>();
const form = useForm({ confirm: '', keep: false });

watch(open, (shown) => {
    if (shown) {
        form.reset();
        form.clearErrors();
    }
});

const ready = computed(() => form.confirm.trim() === machine.name);

function submit(): void {
    if (!ready.value) return;
    form.delete(
        onWorkspace(
            serverRoutes.destroy({
                workspace: page.props.workspace.slug,
                machine: machine.id,
            }),
        ).url,
        {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        },
    );
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('Delete the server of :name?', { name: machine.name })
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            ':provider deletes :server and its disk for good. Everything on it is gone. Its snapshots stay.',
                            {
                                provider: cloud.integration.name,
                                server: cloud.server.name,
                            },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="submit">
                <label
                    class="flex cursor-pointer items-start gap-2.5 text-[13px]/5"
                >
                    <span class="pt-[3px]">
                        <DashCheckbox
                            v-model="form.keep"
                            :label="$t('Keep it in ServerOS')"
                        />
                    </span>
                    <span class="flex flex-col">
                        <span>{{ $t('Keep it in ServerOS') }}</span>
                        <span class="text-[12px]/4 text-muted-foreground">{{
                            $t(
                                'Its history, deploys and status page stay, shown as offline. Otherwise it’s removed too.',
                            )
                        }}</span>
                    </span>
                </label>
                <div class="grid gap-2">
                    <label for="delete-server" class="text-[13px]/5">
                        {{ ' ' + $t('Type') }}
                        <span class="font-ui-mono text-fg-primary">{{
                            machine.name
                        }}</span>
                        {{ $t('to confirm.') + ' ' }}
                    </label>
                    <div class="dash-field font-ui-mono">
                        <input
                            id="delete-server"
                            v-model="form.confirm"
                            autocomplete="off"
                            spellcheck="false"
                        />
                    </div>
                    <span
                        v-if="form.errors.confirm"
                        class="text-[12px]/4 text-status-down"
                        role="alert"
                        >{{ form.errors.confirm }}</span
                    >
                </div>
                <div class="flex justify-end gap-2">
                    <DashButton :label="$t('Cancel')" @click="open = false" />
                    <DashButton
                        type="submit"
                        variant="danger"
                        :label="$t('Delete server')"
                        :loading="form.processing"
                        :disabled="!ready"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
