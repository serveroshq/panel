<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import type {
    MachineCloudServer,
    Snapshot,
} from '@/components/integrations/integrations';
import { sendJson } from '@/components/integrations/integrations';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import serverRoutes from '@/routes/workspace/machines/server';
import type { Workspace } from '@/types';

const { machine, cloud } = defineProps<{
    machine: { id: number; name: string };
    cloud: MachineCloudServer;
}>();

const open = defineModel<boolean>('open', { default: false });
const page = usePage<{ workspace: Workspace }>();
const args = computed(() => ({
    workspace: page.props.workspace.slug,
    machine: machine.id,
}));

const snapshots = ref<Snapshot[] | null>(null);
const loadError = ref('');
const name = ref('');
const taking = ref(false);
const error = ref('');
const confirming = ref<string | null>(null);
const deleting = ref<string | null>(null);

function defaultName(): string {
    return `${machine.name} ${new Date().toISOString().slice(0, 10)}`;
}

async function load(): Promise<void> {
    loadError.value = '';
    const result = await sendJson<{ snapshots: Snapshot[] }>(
        'GET',
        onWorkspace(serverRoutes.snapshots(args.value)).url,
    );
    if (!result.ok) {
        loadError.value =
            result.message || trans('Snapshots couldn’t be loaded. Try again.');

        return;
    }
    snapshots.value = result.data.snapshots;
}

watch(open, (shown) => {
    if (!shown) return;
    snapshots.value = null;
    error.value = '';
    confirming.value = null;
    name.value = defaultName();
    void load();
});

async function take(): Promise<void> {
    if (!name.value.trim()) return;
    taking.value = true;
    error.value = '';
    const result = await sendJson<{ snapshot: Snapshot }>(
        'POST',
        onWorkspace(serverRoutes.snapshots.store(args.value)).url,
        { name: name.value.trim() },
    );
    taking.value = false;
    if (!result.ok) {
        error.value = result.message || trans('That didn’t work. Try again.');

        return;
    }
    snapshots.value = [result.data.snapshot, ...(snapshots.value ?? [])];
    name.value = defaultName();
    toast.success(
        trans('Taking a snapshot of :name. It’s ready in a few minutes.', {
            name: machine.name,
        }),
    );
}

async function remove(snapshot: Snapshot): Promise<void> {
    if (confirming.value !== snapshot.id) {
        confirming.value = snapshot.id;

        return;
    }
    deleting.value = snapshot.id;
    const result = await sendJson<{ deleted: string }>(
        'DELETE',
        onWorkspace(
            serverRoutes.snapshots.destroy({
                ...args.value,
                snapshot: snapshot.id,
            }),
        ).url,
    );
    deleting.value = null;
    confirming.value = null;
    if (!result.ok) {
        error.value = result.message || trans('That didn’t work. Try again.');

        return;
    }
    snapshots.value = (snapshots.value ?? []).filter(
        (s) => s.id !== snapshot.id,
    );
}

function when(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleString([], {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
              hour: '2-digit',
              minute: '2-digit',
          })
        : '–';
}

const fieldClass =
    'h-8 min-w-0 flex-1 rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field outline-none placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal flex max-h-[calc(100svh-2rem)] flex-col gap-0 overflow-hidden !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[520px]"
            :show-close-button="false"
        >
            <div class="flex items-start gap-3 pt-[18px] pr-4 pb-4 pl-5">
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <DialogTitle
                        class="text-[16px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                    >
                        {{ $t('Snapshots of :name', { name: machine.name }) }}
                    </DialogTitle>
                    <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                        {{
                            $t(
                                'A copy of its whole disk, kept at :provider. You can make a new server from one there. :provider charges for the space.',
                                { provider: cloud.integration.name },
                            )
                        }}
                        <template v-if="!cloud.rules.snapshotsPerServer">{{
                            ' ' +
                            $t(
                                ':provider keeps snapshots for the whole account, so this lists all of them.',
                                { provider: cloud.integration.name },
                            )
                        }}</template>
                    </DialogDescription>
                </div>
                <kbd
                    class="flex h-5 items-center rounded-[5px] border-[0.5px] border-edge-strong px-1.5 font-ui text-[11px]/[14px] text-fg-muted"
                    >Esc</kbd
                >
            </div>

            <div
                class="flex min-h-0 flex-1 flex-col gap-3 overflow-y-auto px-5 pb-[18px]"
            >
                <form class="flex items-center gap-2" @submit.prevent="take">
                    <input
                        v-model="name"
                        type="text"
                        maxlength="100"
                        required
                        :aria-label="$t('Snapshot name')"
                        :class="fieldClass"
                    />
                    <DashButton
                        type="submit"
                        variant="primary"
                        :label="$t('Take snapshot')"
                        :loading="taking"
                    />
                </form>
                <p
                    v-if="error"
                    class="text-[12.5px]/[18px] text-status-down"
                    role="alert"
                >
                    {{ error }}
                </p>

                <div
                    class="flex flex-col rounded-[10px] border-[0.5px] border-edge-card bg-surface-card"
                >
                    <p
                        v-if="loadError"
                        class="px-3.5 py-3 text-[13px]/5 text-status-down"
                        role="alert"
                    >
                        {{ loadError }}
                    </p>
                    <p
                        v-else-if="snapshots === null"
                        class="px-3.5 py-3 text-[13px]/5 text-fg-muted"
                        role="status"
                    >
                        {{ $t('Loading snapshots…') }}
                    </p>
                    <p
                        v-else-if="snapshots.length === 0"
                        class="px-3.5 py-3 text-[13px]/5 text-fg-muted"
                    >
                        {{ $t('No snapshots yet.') }}
                    </p>
                    <div
                        v-for="snapshot in snapshots ?? []"
                        :key="snapshot.id"
                        class="flex items-center gap-3 border-t-[0.5px] border-edge px-3 py-2 first:border-t-0"
                    >
                        <span class="flex min-w-0 flex-1 flex-col">
                            <span
                                class="truncate text-[13px]/5 font-medium text-fg-primary"
                                >{{ snapshot.name || $t('Snapshot') }}</span
                            >
                            <span class="text-[12px]/4 text-fg-muted"
                                >{{ when(snapshot.createdAt) }}
                                <template v-if="snapshot.status === 'creating'">
                                    · {{ $t('Being taken') }}</template
                                >
                                <template v-else-if="snapshot.sizeGb !== null">
                                    ·
                                    {{
                                        $t(':size GB', {
                                            size: snapshot.sizeGb.toFixed(1),
                                        })
                                    }}</template
                                ></span
                            >
                        </span>
                        <DashButton
                            v-if="snapshot.status !== 'creating'"
                            size="sm"
                            :class="
                                confirming === snapshot.id
                                    ? 'text-status-down!'
                                    : ''
                            "
                            :label="
                                confirming === snapshot.id
                                    ? $t('Delete for good')
                                    : $t('Delete')
                            "
                            :loading="deleting === snapshot.id"
                            :aria-label="
                                $t('Delete :name', { name: snapshot.name })
                            "
                            @click="remove(snapshot)"
                        />
                    </div>
                </div>
            </div>
        </DialogContent>
    </Dialog>
</template>
