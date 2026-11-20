<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router } from '@inertiajs/vue3';
import { Check, Loader2, Search } from '@lucide/vue';
import {
    computed,
    defineAsyncComponent,
    onBeforeUnmount,
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
import ServersIcon from '@/components/icons/ServersIcon.vue';
import { buyAgentTime } from './agentTasks';
import type { Hosting } from './agentTasks';

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
    /** ServerOS's own machines, offered above the workspace's. */
    hosting?: Hosting | null;
    hosted?: boolean;
    /** The workspace's own machines need Pro for agents; ServerOS's don't. */
    ownLocked?: boolean;
}>();

const emit = defineEmits<{
    pick: [machineId: number, service: string];
    pickHosted: [];
}>();

// With ServerOS offered, the picker is wide: its card on the left, the
// workspace's own machines on the right.
const wide = computed(() => !!props.hosting && !props.machineOnly);

const buying = ref(false);
const packs = ref(1);
const choosingPack = ref(false);
const chosenPack = computed(
    () =>
        props.hosting?.packs.find((p) => p.packs === packs.value) ??
        props.hosting?.packs[0] ??
        null,
);
// Out of time, the packs show straight away; with time, behind Add time.
const showPacks = computed(
    () => !!props.hosting && (!props.hosting.hasTime || choosingPack.value),
);

async function buy(): Promise<void> {
    if (!props.hosting?.buyUrl || buying.value) return;
    buying.value = true;
    try {
        await buyAgentTime(props.hosting.buyUrl, packs.value);
    } catch (e) {
        toast.error(e instanceof Error ? e.message : String(e));
        buying.value = false;
    }
}

// What a task on ServerOS's machines gets, like a plan's list on /pricing.
const features = computed(() =>
    props.hosting
        ? [
              trans(':count agents at once', {
                  count: String(props.hosting.concurrent),
              }),
              trans('Its own machine, deleted when it’s done'),
              trans('A browser to check its work, with screenshots'),
              trans('By the minute, :min minutes minimum per agent', {
                  min: String(props.hosting.minimum),
              }),
          ]
        : [],
);

const blueButton =
    'inline-flex h-9 cursor-pointer items-center justify-center gap-1.5 rounded-[9px] bg-[linear-gradient(180deg,rgb(255_255_255/.09)_0%,rgb(255_255_255/.05)_49%,transparent_51%),linear-gradient(180deg,#3D5EF0_0%,#1621D1_100%)] px-4 text-[14px]/4 font-medium text-white shadow-[inset_0_0.5px_0_rgb(255_255_255/.32),inset_0_0_0_0.5px_rgb(255_255_255/.1),0_1px_2px_rgb(2_3_14/.3)] transition-[filter,scale] duration-150 hover:brightness-110 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) active:scale-[0.97] disabled:opacity-60 motion-reduce:active:scale-100';
const greyButton = appButtonClasses(
    'secondary',
    'md',
    'h-9 px-3.5 text-[14px]/4',
);

function chooseHosted(): void {
    emit('pickHosted');
    open.value = false;
}
const open = defineModel<boolean>('open', { required: true });

watch(open, (isOpen) => {
    if (isOpen) choosingPack.value = false;
});

const search = ref('');

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
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal flex max-h-[min(86vh,680px)] flex-col gap-0 overflow-hidden p-0 font-ui text-foreground"
            :class="wide ? 'sm:max-w-lg md:max-w-[880px]' : 'sm:max-w-lg'"
        >
            <div class="flex shrink-0 flex-col gap-1 px-5 pt-5 pb-4">
                <DialogTitle class="text-base/6 font-semibold">{{
                    machineOnly
                        ? $t('Choose a machine')
                        : $t('Where should your agents run?')
                }}</DialogTitle>
                <DialogDescription
                    class="text-[13px]/5 text-(--ds-text-muted)"
                    :class="{ 'sr-only': wide }"
                >
                    {{
                        machineOnly
                            ? $t(
                                  'The server agent reads the machine’s logs and services, and asks before it changes anything.',
                              )
                            : $t(
                                  'Agents work in a Coding agent container on one of your machines.',
                              )
                    }}
                </DialogDescription>
            </div>

            <div
                class="min-h-0 flex-1 overflow-y-auto"
                :class="{ 'md:flex md:overflow-hidden': wide }"
            >
                <!-- ServerOS's own machines: first, because they need nothing set up. Styled like the featured plan on /pricing. -->
                <section
                    v-if="hosting && !machineOnly"
                    class="mx-5 mb-5 rounded-[17px] bg-[linear-gradient(180deg,#0A1250_0%,#1621D1_60%,#3D5EF0_100%)] p-[5px] shadow-[0_24px_60px_-24px_rgb(2_3_14/.7)] md:mr-0 md:w-[380px] md:shrink-0 md:self-start"
                    :aria-label="$t('ServerOS’s machines')"
                >
                    <div class="flex flex-col overflow-hidden rounded-[12px]">
                        <div class="bg-(--ds-card) p-5">
                            <h4
                                class="flex h-7 items-center gap-2 text-[15px]/5 font-semibold text-(--ds-text-strong)"
                            >
                                ServerOS
                                <span
                                    class="inline-flex h-[22px] items-center rounded-full bg-[#2B45E3]/10 px-2 text-[12px]/4 font-normal text-[#1F3BD6] dark:bg-[#6C88FF]/15 dark:text-[#A9B8FF]"
                                    >{{ $t('New') }}</span
                                >
                            </h4>
                            <p
                                class="mt-2 text-[18px]/6 tracking-[-0.02em] text-balance"
                            >
                                <span class="text-(--ds-text-faint)">{{
                                    $t('Agents, without a server.')
                                }}</span>
                                {{ ' ' }}
                                <span class="text-(--ds-text-strong)">{{
                                    $t('Nothing to set up or pay monthly.')
                                }}</span>
                            </p>
                            <p class="mt-5 whitespace-nowrap">
                                <span
                                    class="text-[40px]/[44px] tracking-[-0.03em] text-(--ds-text-strong) tabular-nums"
                                    >{{ chosenPack?.price }}</span
                                >
                                <span
                                    class="ml-1.5 text-[13px]/5 text-(--ds-text-muted)"
                                    >{{
                                        $t('for :hours agent-hours', {
                                            hours: String(
                                                chosenPack?.hours ?? '',
                                            ),
                                        })
                                    }}</span
                                >
                            </p>
                            <p
                                class="mt-1 text-[12.5px]/5 text-(--ds-text-muted)"
                            >
                                {{
                                    hosting.hasTime
                                        ? $t(':balance of agent time left', {
                                              balance: hosting.balance ?? '',
                                          })
                                        : $t('Paid upfront, no subscription')
                                }}
                            </p>

                            <div
                                v-if="showPacks"
                                class="ds-segments ds-segments--sm mt-4"
                                role="group"
                                :aria-label="$t('How much agent time')"
                            >
                                <button
                                    v-for="p in hosting.packs"
                                    :key="p.packs"
                                    type="button"
                                    class="cursor-pointer"
                                    :aria-pressed="packs === p.packs"
                                    @click="packs = p.packs"
                                >
                                    {{
                                        $t(':hours hours', {
                                            hours: String(p.hours),
                                        })
                                    }}
                                </button>
                            </div>

                            <div class="mt-5 flex items-center gap-2">
                                <template v-if="showPacks">
                                    <button
                                        v-if="hosting.buyUrl"
                                        type="button"
                                        :class="[blueButton, 'flex-1']"
                                        :disabled="buying"
                                        @click="buy"
                                    >
                                        <Loader2
                                            v-if="buying"
                                            class="size-3.5 animate-spin"
                                        />
                                        {{
                                            $t('Buy for :price', {
                                                price: chosenPack?.price ?? '',
                                            })
                                        }}
                                    </button>
                                    <p
                                        v-else
                                        class="flex-1 text-[13px]/5 text-(--ds-text-muted)"
                                    >
                                        {{
                                            $t(
                                                'Someone who manages billing can buy agent time for this workspace.',
                                            )
                                        }}
                                    </p>
                                    <button
                                        v-if="hosting.hasTime"
                                        type="button"
                                        :class="greyButton"
                                        @click="choosingPack = false"
                                    >
                                        {{ $t('Not now') }}
                                    </button>
                                </template>
                                <template v-else>
                                    <button
                                        type="button"
                                        :class="[blueButton, 'flex-1']"
                                        @click="chooseHosted"
                                    >
                                        <Check
                                            v-if="hosted"
                                            class="size-3.5"
                                            :stroke-width="2.5"
                                        />
                                        {{
                                            hosted
                                                ? $t('Running on ServerOS')
                                                : $t('Use ServerOS')
                                        }}
                                    </button>
                                    <button
                                        v-if="hosting.buyUrl"
                                        type="button"
                                        :class="greyButton"
                                        @click="choosingPack = true"
                                    >
                                        {{ $t('Add time') }}
                                    </button>
                                </template>
                            </div>
                        </div>

                        <div
                            class="bg-[radial-gradient(120%_80%_at_85%_0%,rgb(61_94_240/.55),transparent_60%),linear-gradient(180deg,#0A1250_0%,#1621D1_100%)] p-5 text-white"
                        >
                            <p class="text-[13px]/5 text-white/75">
                                {{ $t('Every task gets') }}
                            </p>
                            <ul class="mt-3 grid gap-2.5">
                                <li
                                    v-for="feature in features"
                                    :key="feature"
                                    class="flex items-start gap-2.5 text-[13.5px]/5"
                                >
                                    <Check
                                        class="mt-0.5 size-4 shrink-0 text-white/80"
                                        :stroke-width="2.5"
                                        aria-hidden="true"
                                    />
                                    {{ feature }}
                                </li>
                            </ul>
                        </div>
                    </div>
                </section>

                <div
                    :class="{
                        'md:min-h-0 md:flex-1 md:overflow-y-auto md:pl-3': wide,
                    }"
                >
                    <h3
                        v-if="hosting && !machineOnly && boxes.length"
                        class="px-5 pb-2 text-[12px]/4 font-medium text-(--ds-text-muted)"
                    >
                        {{ $t('Or one of your machines') }}
                    </h3>
                    <p
                        v-if="ownLocked && !machineOnly && boxes.length"
                        class="px-5 pb-2 text-[12.5px]/5 text-(--ds-text-muted)"
                    >
                        {{ $t('Agents on your own machines come with Pro.') }}
                        <a
                            href="/billing"
                            class="font-medium text-(--ds-blue) hover:underline hover:underline-offset-4"
                            >{{ $t('Choose a plan') }}</a
                        >
                    </p>

                    <label
                        v-if="boxes.length > 4"
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
                        class="flex flex-col gap-px px-2.5 pt-1 pb-3"
                        :class="{
                            'border-t border-(--ds-divider) pt-2': machineOnly,
                            'pointer-events-none opacity-45 select-none':
                                ownLocked && !machineOnly,
                        }"
                        :inert="ownLocked && !machineOnly"
                    >
                        <li
                            v-for="{ box, agents, others: rest } in shown"
                            :key="box.id"
                        >
                            <!-- The machine: picked straight away when it has one Coding agent. -->
                            <component
                                :is="
                                    machineOnly || agents.length === 1
                                        ? 'button'
                                        : 'div'
                                "
                                :type="
                                    machineOnly || agents.length === 1
                                        ? 'button'
                                        : undefined
                                "
                                class="flex h-12 w-full items-center gap-3 rounded-[10px] px-3 text-left"
                                :class="[
                                    machineOnly || agents.length === 1
                                        ? 'cursor-pointer transition-colors hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)'
                                        : '',
                                    { 'opacity-60': !box.online },
                                ]"
                                @click="
                                    machineOnly
                                        ? pick(box, '')
                                        : agents.length === 1 &&
                                          pick(box, agents[0].key)
                                "
                            >
                                <ServersIcon
                                    class="size-4 shrink-0"
                                    :class="
                                        box.online
                                            ? 'text-(--ds-text-secondary)'
                                            : 'text-(--ds-text-faint)'
                                    "
                                />
                                <span class="flex min-w-0 flex-1 flex-col">
                                    <span
                                        class="truncate text-[13.5px]/5 font-medium"
                                    >
                                        {{ box.name }}
                                        <span
                                            v-if="duplicate(box)"
                                            class="font-normal text-(--ds-text-faint)"
                                            >#{{ box.id }}</span
                                        >
                                    </span>
                                    <span
                                        class="flex items-center gap-1.5 truncate text-[12px]/4 text-(--ds-text-muted)"
                                    >
                                        <span
                                            class="size-1.5 shrink-0 rounded-full"
                                            :class="
                                                box.online
                                                    ? 'bg-(--ds-green)'
                                                    : 'bg-(--ds-text-faint)'
                                            "
                                        />
                                        {{
                                            !box.online
                                                ? $t('Offline')
                                                : machineOnly
                                                  ? $t('Online')
                                                  : waiting?.machineId ===
                                                      box.id
                                                    ? $t(
                                                          'Setting up a Coding agent',
                                                      )
                                                    : !agents.length
                                                      ? $t(
                                                            'No Coding agent yet',
                                                        )
                                                      : box.running
                                                        ? $t(
                                                              ':running of :slots agents working',
                                                              {
                                                                  running:
                                                                      String(
                                                                          box.running,
                                                                      ),
                                                                  slots: String(
                                                                      box.slots,
                                                                  ),
                                                              },
                                                          )
                                                        : $t(
                                                              'Ready, :slots agents at once',
                                                              {
                                                                  slots: String(
                                                                      box.slots,
                                                                  ),
                                                              },
                                                          )
                                        }}
                                    </span>
                                </span>
                                <Check
                                    v-if="
                                        machineId === box.id &&
                                        (machineOnly ||
                                            (agents.length === 1 &&
                                                service === agents[0].key))
                                    "
                                    class="size-4 shrink-0 text-(--ds-blue)"
                                    :stroke-width="2.25"
                                />
                                <Loader2
                                    v-else-if="waiting?.machineId === box.id"
                                    class="size-4 shrink-0 animate-spin text-(--ds-text-muted) motion-reduce:animate-none"
                                />
                                <button
                                    v-else-if="
                                        !machineOnly &&
                                        canDeploy &&
                                        box.online &&
                                        !agents.length
                                    "
                                    type="button"
                                    :class="appButtonClasses('secondary', 'sm')"
                                    :disabled="loadingTemplates !== null"
                                    @click="deployOn(box)"
                                >
                                    <Loader2
                                        v-if="loadingTemplates === box.id"
                                        class="size-3 animate-spin"
                                    />
                                    {{ $t('Set up') }}
                                </button>
                            </component>

                            <!-- More than one Coding agent, or a search that matched other containers: pick which. -->
                            <template v-if="!machineOnly">
                                <button
                                    v-for="c in [
                                        ...(agents.length > 1 ? agents : []),
                                        ...(search ? rest : []),
                                    ]"
                                    :key="c.key"
                                    type="button"
                                    class="flex h-9 w-full cursor-pointer items-center gap-2 rounded-[9px] pr-3 pl-10 text-left transition-colors hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
                                    :title="
                                        c.agent
                                            ? undefined
                                            : $t(
                                                  'It needs git and Claude Code or Codex installed.',
                                              )
                                    "
                                    @click="pick(box, c.key)"
                                >
                                    <span
                                        class="truncate font-ui-mono text-[12.5px] text-(--ds-text-secondary)"
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
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <DeployServiceDialog
        v-if="deploying"
        v-model:open="deployOpen"
        :templates="deploying.templates"
        :machine-id="deploying.machine.id"
        start-with="coding-agent"
        back-closes
        @deployed="onDeployed"
    />
</template>
