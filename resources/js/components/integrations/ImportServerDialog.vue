<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import type { CloudServer } from '@/components/integrations/integrations';
import { sendJson } from '@/components/integrations/integrations';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import { status as connectStatus } from '@/routes/workspace/machines/connect';
import type { Workspace } from '@/types';

const { server, providerName } = defineProps<{
    server: CloudServer | null;
    providerName: string;
}>();

const open = defineModel<boolean>('open', { default: false });
const page = usePage<{ workspace: Workspace }>();
const slug = computed(() => page.props.workspace.slug);

const command = ref('');
const error = ref('');
const loading = ref(false);
const enrolment = ref<number | null>(null);
const joined = ref(false);
let timer: ReturnType<typeof setInterval> | undefined;

function stop(): void {
    clearInterval(timer);
    timer = undefined;
}

async function make(): Promise<void> {
    loading.value = true;
    error.value = '';
    command.value = '';
    joined.value = false;
    stop();
    const result = await sendJson<{ command: string; enrolmentId: number }>(
        'POST',
        onWorkspace(machinesRoutes.connect(slug.value)).url,
        {},
    );
    loading.value = false;
    if (!result.ok) {
        error.value =
            result.message || trans('Could not create an install command.');

        return;
    }
    command.value = result.data.command;
    enrolment.value = result.data.enrolmentId;
    timer = setInterval(poll, 4000);
}

async function poll(): Promise<void> {
    if (enrolment.value === null) return;
    const result = await sendJson<{
        state: string;
        machineName: string | null;
    }>(
        'GET',
        onWorkspace(
            connectStatus({
                workspace: slug.value,
                enrolment: enrolment.value,
            }),
        ).url,
    );
    if (!result.ok) return;
    if (result.data.state === 'expired') {
        stop();
        error.value = trans('The command expired. Make a new one.');
    }
    if (['enrolled', 'connected', 'discovered'].includes(result.data.state)) {
        stop();
        joined.value = true;
        toast.success(
            trans(':name joined and is linked to its server.', {
                name: result.data.machineName ?? server?.name ?? '',
            }),
        );
        router.reload({ only: ['servers', 'usage', 'dependents'] });
    }
}

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(command.value);
        toast.success(trans('Install command copied'));
    } catch {
        toast.error(
            trans('Could not copy. Select the command and copy it manually.'),
        );
    }
}

watch(open, (shown) => {
    if (shown) {
        void make();
    } else {
        stop();
    }
});

onBeforeUnmount(stop);
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[560px]"
            :show-close-button="false"
        >
            <div v-if="server" class="flex flex-col">
                <div class="flex items-start gap-3 pt-[18px] pr-4 pb-4 pl-5">
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <DialogTitle
                            class="text-[16px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                        >
                            {{ $t('Import :name', { name: server.name }) }}
                        </DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-fg-tertiary"
                        >
                            {{
                                $t(
                                    'Run this on :name as root. It installs the ServerOS daemon, and the server joins :workspace linked to its :provider server.',
                                    {
                                        name: server.ipv4
                                            ? `${server.name} (${server.ipv4})`
                                            : server.name,
                                        workspace: page.props.workspace.name,
                                        provider: providerName,
                                    },
                                )
                            }}
                        </DialogDescription>
                    </div>
                    <kbd
                        class="flex h-5 items-center rounded-[5px] border-[0.5px] border-edge-strong px-1.5 font-ui text-[11px]/[14px] text-fg-muted"
                        >Esc</kbd
                    >
                </div>
                <div class="flex flex-col gap-3 px-5 pb-[18px]">
                    <pre
                        v-if="command"
                        class="max-h-40 overflow-auto rounded-[8px] border-[0.5px] border-edge-field bg-surface-field px-3 py-2.5 font-ui-mono text-[12px]/[18px] break-all whitespace-pre-wrap text-fg-secondary shadow-field"
                        >{{ command }}</pre>
                    <p
                        v-else-if="loading"
                        class="text-[13px]/5 text-fg-muted"
                        role="status"
                    >
                        {{ $t('Making a join command…') }}
                    </p>
                    <p
                        v-if="error"
                        class="text-[12.5px]/[18px] text-status-down"
                        role="alert"
                    >
                        {{ error }}
                    </p>
                    <p
                        v-else-if="command && !joined"
                        class="flex items-center gap-2 text-[12.5px]/4 text-fg-muted"
                        role="status"
                    >
                        <span
                            class="size-1.5 animate-pulse rounded-full bg-status-deploying motion-reduce:animate-none"
                        />
                        {{
                            $t('Waiting for :name to say hello…', {
                                name: server.name,
                            })
                        }}
                    </p>
                </div>
                <div
                    class="flex items-center justify-end gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
                >
                    <DashButton
                        v-if="error"
                        :label="$t('New command')"
                        @click="make"
                    />
                    <DashButton :label="$t('Close')" @click="open = false" />
                    <DashButton
                        variant="primary"
                        icon="copy"
                        :label="$t('Copy command')"
                        :disabled="!command"
                        @click="copy"
                    />
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
