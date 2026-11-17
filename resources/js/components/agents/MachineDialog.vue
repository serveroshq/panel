<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router } from '@inertiajs/vue3';
import { Check, ChevronRight, Loader2, Plus, Search } from '@lucide/vue';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
    reactive,
    ref,
    watch,
} from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import type { ServiceTemplate } from '@/components/workspace/DeployServiceDialog.vue';
import { appButtonClasses } from '@/lib/appButton';
import { toast } from '@/lib/toast';

const DeployServiceDialog = defineAsyncComponent(
    () => import('@/components/workspace/DeployServiceDialog.vue'),
);

export type Box = {
    id: number;
    name: string;
    online: boolean;
    slots: number;
    running: number;
    containers: { key: string; name: string; agent: boolean }[];
};

const props = defineProps<{
    boxes: Box[];
    machineId: number;
    service: string;
    canDeploy: boolean;
    /** For the server agent: pick a machine, not a container. */
    machineOnly?: boolean;
}>();

const emit = defineEmits<{ pick: [machineId: number, service: string] }>();
const open = defineModel<boolean>('open', { required: true });

const search = ref('');
const others = reactive<Record<number, boolean>>({});

watch(open, (isOpen) => {
    if (isOpen) search.value = '';
});

const duplicate = computed(() => {
    const seen: Record<string, number> = {};
    for (const box of props.boxes) seen[box.name] = (seen[box.name] ?? 0) + 1;

    return (box: Box) => (seen[box.name] ?? 0) > 1;
});

const agentsOn = (box: Box) => box.containers.filter((c) => c.agent);

const q = computed(() => search.value.trim().toLowerCase());
const matches = (text: string) =>
    !q.value || text.toLowerCase().includes(q.value);

// A machine shows when its name matches, or one of its containers does.
const shown = computed(() => {
    const rank = (box: Box) =>
        (agentsOn(box).length ? 0 : 2) + (box.online ? 0 : 1);

    return props.boxes
        .map((box) => ({
            box,
            agents: agentsOn(box).filter(
                (c) => matches(box.name) || matches(c.name),
            ),
            others: box.containers.filter(
                (c) => !c.agent && (matches(box.name) || matches(c.name)),
            ),
        }))
        .filter(
            ({ box, agents, others }) =>
                matches(box.name) || agents.length || others.length,
        )
        .sort(
            (a, b) =>
                rank(a.box) - rank(b.box) ||
                a.box.name.localeCompare(b.box.name),
        );
});

function pick(box: Box, key: string): void {
    emit('pick', box.id, key);
    open.value = false;
}

// Setting up a Coding agent: the deploy dialog opens on its template, and
// once it's queued the new container is picked as soon as the machine
// reports it.
const deploying = ref<{ machine: Box; templates: ServiceTemplate[] } | null>(
    null,
);
const deployOpen = ref(false);
const loadingTemplates = ref<number | null>(null);
const waiting = ref<{ machineId: number; known: string[] } | null>(null);

async function deployOn(box: Box): Promise<void> {
    loadingTemplates.value = box.id;
    try {
        const response = await fetch(`/agents/machines/${box.id}/templates`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (!response.ok) throw new Error();
        deploying.value = {
            machine: box,
            templates: (await response.json()).templates ?? [],
        };
        open.value = false;
        deployOpen.value = true;
    } catch {
        toast.error(trans('Couldn’t load the templates. Try again.'));
    } finally {
        loadingTemplates.value = null;
    }
}

function onDeployed(): void {
    const box = deploying.value?.machine;
    if (!box) return;
    waiting.value = {
        machineId: box.id,
        known: box.containers.map((c) => c.key),
    };
}

// Back to choosing once the deploy dialog closes.
watch(deployOpen, (isOpen) => {
    if (!isOpen && deploying.value) {
        deploying.value = null;
        open.value = true;
    }
});

let poll: ReturnType<typeof setInterval> | undefined;
watch(
    waiting,
    (value) => {
        clearInterval(poll);
        if (!value) return;
        poll = setInterval(() => {
            if (document.visibilityState === 'visible') {
                router.reload({ only: ['boxes'] });
            }
        }, 4000);
    },
    { immediate: true },
);
onBeforeUnmount(() => clearInterval(poll));

watch(
    () => props.boxes,
    (boxes) => {
        const wait = waiting.value;
        if (!wait) return;
        const box = boxes.find((b) => b.id === wait.machineId);
        const fresh = box?.containers.find(
            (c) => c.agent && !wait.known.includes(c.key),
        );
        if (box && fresh) {
            waiting.value = null;
            emit('pick', box.id, fresh.key);
            toast.success(
                trans('The Coding agent is running on :machine.', {
                    machine: box.name,
                }),
            );
        }
    },
);

const row =
    'flex w-full cursor-pointer items-center gap-2.5 rounded-[9px] px-3 text-left text-[13px]/4 transition-colors hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal flex max-h-[min(86vh,680px)] flex-col gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-lg"
        >
            <div class="flex shrink-0 flex-col gap-1 px-5 pt-5 pb-4">
                <DialogTitle class="text-base/6 font-semibold">{{
                    $t('Choose a machine')
                }}</DialogTitle>
                <DialogDescription class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        machineOnly
                            ? $t(
                                  'The server agent reads the machine’s logs and services, and asks before it changes anything.',
                              )
                            : $t(
                                  'Agents work in a Coding agent container on one of your machines. Each run gets a fresh checkout.',
                              )
                    }}
                </DialogDescription>
            </div>

            <label
                class="mx-5 mb-3 flex h-9 shrink-0 items-center gap-2 rounded-[9px] border border-(--ds-border-strong) bg-(--ds-field) px-3 focus-within:outline-2 focus-within:outline-offset-1 focus-within:outline-(--ds-ring)"
            >
                <Search
                    class="size-3.5 shrink-0 text-(--ds-text-muted)"
                    :stroke-width="1.6"
                />
                <span class="sr-only">{{
                    $t('Search machines and containers')
                }}</span>
                <input
                    v-model="search"
                    class="min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint)"
                    :placeholder="$t('Search machines and containers')"
                    autocomplete="off"
                    spellcheck="false"
                />
            </label>

            <ul
                class="min-h-0 flex-1 overflow-y-auto border-t border-(--ds-divider) px-2.5 py-2"
            >
                <li
                    v-for="{ box, agents, others: rest } in shown"
                    :key="box.id"
                    class="border-b border-(--ds-divider) py-2 last:border-b-0"
                >
                    <component
                        :is="machineOnly ? 'button' : 'div'"
                        :type="machineOnly ? 'button' : undefined"
                        class="flex h-8 w-full items-center gap-2.5 px-3 text-left"
                        :class="
                            machineOnly
                                ? 'h-10! cursor-pointer rounded-[9px] hover:bg-(--ds-hover)'
                                : ''
                        "
                        @click="machineOnly && pick(box, '')"
                    >
                        <span
                            class="size-1.5 shrink-0 rounded-full"
                            :class="
                                box.online
                                    ? 'bg-(--ds-green)'
                                    : 'bg-(--ds-text-faint)'
                            "
                        />
                        <span class="truncate text-[13.5px]/5 font-medium">
                            {{ box.name }}
                            <span
                                v-if="duplicate(box)"
                                class="font-normal text-(--ds-text-faint)"
                                >#{{ box.id }}</span
                            >
                        </span>
                        <span
                            class="truncate text-[12px]/4 text-(--ds-text-faint)"
                            >{{
                                !box.online
                                    ? $t('offline')
                                    : machineOnly
                                      ? $t('online')
                                      : box.running
                                        ? $t(
                                              ':running of :slots agents working',
                                              {
                                                  running: String(box.running),
                                                  slots: String(box.slots),
                                              },
                                          )
                                        : $t('fits :slots agents at once', {
                                              slots: String(box.slots),
                                          })
                            }}</span
                        >
                        <Check
                            v-if="machineOnly && machineId === box.id"
                            class="ml-auto size-4 shrink-0 text-(--ds-blue)"
                            :stroke-width="2.25"
                        />
                        <button
                            v-if="!machineOnly && canDeploy && !agents.length"
                            type="button"
                            class="ml-auto"
                            :class="appButtonClasses('secondary', 'xs')"
                            :disabled="loadingTemplates !== null"
                            @click="deployOn(box)"
                        >
                            <Loader2
                                v-if="loadingTemplates === box.id"
                                class="size-3 animate-spin"
                            />
                            <Plus v-else class="size-3" :stroke-width="2" />
                            {{ $t('Deploy a Coding agent') }}
                        </button>
                    </component>
                    <template v-if="!machineOnly">
                        <p
                            v-if="waiting?.machineId === box.id"
                            class="flex h-9 items-center gap-2 px-3 text-[12.5px]/4 text-(--ds-text-muted)"
                        >
                            <Loader2
                                class="size-3.5 animate-spin motion-reduce:animate-none"
                            />
                            {{
                                $t(
                                    'Setting up the Coding agent. It’s picked as soon as it’s running.',
                                )
                            }}
                        </p>

                        <button
                            v-for="c in agents"
                            :key="c.key"
                            type="button"
                            :class="[row, 'h-10']"
                            @click="pick(box, c.key)"
                        >
                            <span
                                class="truncate font-ui-mono text-[12.5px] text-(--ds-text)"
                                >{{ c.name }}</span
                            >
                            <span
                                class="shrink-0 rounded-full border border-(--ds-border-strong) px-2 py-px text-[11px]/4 text-(--ds-text-secondary)"
                                >{{ $t('Coding agent') }}</span
                            >
                            <Check
                                v-if="machineId === box.id && service === c.key"
                                class="ml-auto size-4 shrink-0 text-(--ds-blue)"
                                :stroke-width="2.25"
                            />
                        </button>

                        <template v-if="rest.length">
                            <button
                                type="button"
                                class="flex h-8 w-full cursor-pointer items-center gap-1.5 rounded-[9px] px-3 text-left text-[12px]/4 text-(--ds-text-faint) hover:text-(--ds-text-muted)"
                                :aria-expanded="!!others[box.id] || !!search"
                                @click="others[box.id] = !others[box.id]"
                            >
                                <ChevronRight
                                    class="size-3 transition-transform"
                                    :class="{
                                        'rotate-90': others[box.id] || search,
                                    }"
                                    :stroke-width="2"
                                />
                                {{
                                    rest.length === 1
                                        ? $t('1 other container')
                                        : $t(':count other containers', {
                                              count: String(rest.length),
                                          })
                                }}
                            </button>
                            <template v-if="others[box.id] || search">
                                <button
                                    v-for="c in rest"
                                    :key="c.key"
                                    type="button"
                                    :class="[row, 'h-9 pl-7']"
                                    :title="
                                        $t(
                                            'It needs git and Claude Code or Codex installed.',
                                        )
                                    "
                                    @click="pick(box, c.key)"
                                >
                                    <span
                                        class="truncate font-ui-mono text-[12px] text-(--ds-text-secondary)"
                                        >{{ c.name }}</span
                                    >
                                    <Check
                                        v-if="
                                            machineId === box.id &&
                                            service === c.key
                                        "
                                        class="ml-auto size-4 shrink-0 text-(--ds-blue)"
                                        :stroke-width="2.25"
                                    />
                                </button>
                            </template>
                        </template>

                        <p
                            v-if="
                                !agents.length &&
                                !rest.length &&
                                waiting?.machineId !== box.id
                            "
                            class="px-3 pb-1 text-[12px]/4 text-(--ds-text-faint)"
                        >
                            {{ $t('Nothing running in containers here yet.') }}
                        </p>
                    </template>
                </li>

                <li
                    v-if="!shown.length"
                    class="px-3 py-8 text-center text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{
                        boxes.length
                            ? $t('No machine or container matches.')
                            : $t('Connect a machine first.')
                    }}
                </li>
            </ul>
        </DialogContent>
    </Dialog>

    <DeployServiceDialog
        v-if="deploying"
        v-model:open="deployOpen"
        :templates="deploying.templates"
        :machine-id="deploying.machine.id"
        start-with="coding-agent"
        @deployed="onDeployed"
    />
</template>
