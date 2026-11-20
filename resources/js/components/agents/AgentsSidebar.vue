<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Link, usePage } from '@inertiajs/vue3';
import {
    ChevronDown,
    GitBranch,
    GitPullRequest,
    KeyRound,
    PanelLeft,
    Search,
    SquarePen,
    SquareTerminal,
    X,
} from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import AiBrandIcon from '@/components/workspace/AiBrandIcon.vue';
import AiProviders from '@/components/workspace/settings/AiProviders.vue';
import type { AiProvider } from '@/components/workspace/settings/AiProviders.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import GitHubMark from './GitHubMark.vue';
import AccountMenu from '@/components/workspace/AccountMenu.vue';
import ProductSwitch from '@/components/workspace/ProductSwitch.vue';
import WorkspaceMenu from '@/components/workspace/WorkspaceMenu.vue';
import { since, taskStatus, toneDot } from './agentTasks';
import type { Hosting, TaskRow } from './agentTasks';
import type { AiBrand } from '@/lib/aiBrands';

// The Agents side of ServerOS: its own sidebar, like a chat app's. A new
// task, then every task by when it last moved, then what agents run on.
const {
    collapsed = false,
    collapsible = false,
    closable = false,
} = defineProps<{
    collapsed?: boolean;
    collapsible?: boolean;
    closable?: boolean;
}>();
const emit = defineEmits<{ toggle: []; close: [] }>();

const page = usePage<{
    name: string;
    tasks?: TaskRow[];
    task?: { id: number };
    hosting?: Hosting | null;
    aiKeys?: {
        providers: AiProvider[];
        agentUses: string;
        canManage: boolean;
    };
}>();

const keysOpen = ref(false);

const search = ref('');
const tasks = computed(() => page.props.tasks ?? []);
const current = computed(() => page.props.task?.id ?? null);

// Like a coding app's threads: what's still moving on top as cards (working,
// waiting for room, or a pull request to look at), the rest in History below.
const ACTIVE = ['running', 'queued', 'review'];
const matches = (task: TaskRow): boolean => {
    const q = search.value.trim().toLowerCase();

    return !q || `${task.title} ${task.repo ?? ''}`.toLowerCase().includes(q);
};
const active = computed(() =>
    tasks.value.filter((t) => ACTIVE.includes(t.status) && matches(t)),
);
const settled = computed(() =>
    tasks.value.filter((t) => !ACTIVE.includes(t.status) && matches(t)),
);
const settledOpen = ref(true);
const showAll = ref(false);
const settledShown = computed(() =>
    showAll.value ? settled.value : settled.value.slice(0, 10),
);

// A clock for "Working 4m", between the page's polls.
const now = ref(Date.now());
const clock = setInterval(() => (now.value = Date.now()), 15_000);
onBeforeUnmount(() => clearInterval(clock));

function chip(task: TaskRow): {
    label: string;
    tone: string;
    hint?: string;
} {
    if (task.status === 'running') {
        const started = task.agents
            .filter((a) => a.state === 'running')
            .map((a) => Date.parse(a.startedAt))
            .sort()[0];
        const minutes = started
            ? Math.max(0, Math.floor((now.value - started) / 60_000))
            : 0;

        const took =
            minutes < 60
                ? `${minutes}m`
                : minutes < 1440
                  ? `${Math.floor(minutes / 60)}h`
                  : `${Math.floor(minutes / 1440)}d`;

        // No agent runs longer than an hour: well past that, it's likely stuck.
        const stuck = minutes > 90;

        return {
            label: minutes
                ? trans('Working · :m', { m: took })
                : trans('Working'),
            tone: stuck ? 'text-(--ds-amber)' : 'text-(--ds-blue)',
            hint: stuck
                ? trans(
                      'Running far longer than an agent’s time limit: it may be stuck. Open it to stop it.',
                  )
                : undefined,
        };
    }
    if (task.status === 'queued')
        return { label: trans('Waiting'), tone: 'text-(--ds-amber)' };

    return { label: trans('To review'), tone: 'text-(--ds-green)' };
}

const brand = (task: TaskRow): { id: AiBrand; colour: string } | null =>
    task.agent === 'claude'
        ? { id: 'claude', colour: 'text-[#D97757]' }
        : task.agent === 'codex'
          ? { id: 'codex', colour: 'text-(--ds-text-muted)' }
          : null;

const focusRing =
    'focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)';
const row = `flex h-8 w-full items-center gap-2.5 rounded-[9px] px-2.5 text-[13.5px]/5 transition-colors duration-150 ${focusRing}`;
const iconButton = `flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-[8px] text-(--ds-text-muted) transition-colors duration-150 hover:bg-(--ds-hover) hover:text-(--ds-text) ${focusRing}`;
</script>

<template>
    <div class="flex h-full flex-col font-ui text-(--ds-text)">
        <template v-if="!collapsed">
            <div class="flex h-8 shrink-0 items-center gap-0.5 pr-0.5 pl-3">
                <span class="mr-auto flex min-w-0 items-center gap-2">
                    <img
                        src="/images/landing/serveros-logo.svg"
                        alt=""
                        class="size-[18px] shrink-0 object-contain invert dark:invert-0"
                    />
                    <span
                        class="truncate text-[15px]/5 font-semibold tracking-[-0.02em]"
                        >{{ page.props.name }}</span
                    >
                </span>
                <button
                    v-if="collapsible"
                    type="button"
                    :class="iconButton"
                    :aria-label="$t('Collapse sidebar')"
                    aria-expanded="true"
                    @click="emit('toggle')"
                >
                    <PanelLeft class="size-4" :stroke-width="1.6" />
                </button>
                <button
                    v-if="closable"
                    type="button"
                    :class="iconButton"
                    :aria-label="$t('Close navigation')"
                    @click="emit('close')"
                >
                    <X class="size-4" :stroke-width="1.75" />
                </button>
            </div>

            <ProductSwitch class="mx-2 mt-3" />

            <div class="mt-3 flex flex-col gap-1 px-2">
                <Link
                    href="/agents"
                    :class="[
                        row,
                        'font-medium hover:bg-(--ds-hover)',
                        page.component === 'workspace/Agents'
                            ? 'bg-(--ds-hover)'
                            : '',
                    ]"
                >
                    <SquarePen
                        class="size-4 text-(--ds-text-muted)"
                        :stroke-width="1.75"
                    />
                    {{ $t('New task') }}
                </Link>
                <label
                    v-if="tasks.length > 6"
                    class="flex h-8 items-center gap-2.5 rounded-[9px] px-2.5 text-(--ds-text-muted) focus-within:bg-(--ds-hover)"
                >
                    <Search class="size-4 shrink-0" :stroke-width="1.75" />
                    <span class="sr-only">{{ $t('Search tasks') }}</span>
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="$t('Search tasks')"
                        class="min-w-0 flex-1 bg-transparent text-[13.5px]/5 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                    />
                </label>
            </div>

            <nav
                class="mt-3 min-h-0 grow overflow-y-auto px-2 pb-3"
                :aria-label="$t('Tasks')"
            >
                <!-- Still moving: a card each. -->
                <div v-if="active.length" class="flex flex-col gap-1">
                    <Link
                        v-for="task in active"
                        :key="task.id"
                        :href="`/agents/${task.id}`"
                        class="flex flex-col gap-1 rounded-[11px] px-2.5 py-2.5 transition-colors duration-150"
                        :class="[
                            focusRing,
                            current === task.id
                                ? 'bg-(--ds-hover)'
                                : 'hover:bg-(--ds-hover)',
                        ]"
                        :aria-current="current === task.id ? 'page' : undefined"
                    >
                        <span
                            class="flex items-center gap-1.5 text-[12px]/4 text-(--ds-text-faint)"
                        >
                            <GitHubMark
                                v-if="task.repo"
                                class="size-3 shrink-0"
                            />
                            <span class="min-w-0 truncate">{{
                                task.repo ?? task.machine.name
                            }}</span>
                            <span
                                class="ml-auto flex shrink-0 items-center gap-1.5 font-medium"
                                :class="chip(task).tone"
                                :title="chip(task).hint"
                            >
                                <span
                                    v-if="task.status === 'running'"
                                    class="size-3 animate-spin rounded-full border-[1.5px] border-dashed border-current motion-reduce:animate-none"
                                    aria-hidden="true"
                                />
                                <GitPullRequest
                                    v-else-if="task.status === 'review'"
                                    class="size-3"
                                    :stroke-width="2"
                                />
                                <span
                                    v-else
                                    class="size-1.5 rounded-full bg-current"
                                    aria-hidden="true"
                                />
                                {{ chip(task).label }}
                            </span>
                        </span>
                        <span
                            class="truncate text-[13.5px]/5 font-medium text-(--ds-text)"
                            >{{ task.title }}</span
                        >
                        <span
                            class="flex items-center gap-1.5 text-[12px]/4 text-(--ds-text-faint)"
                        >
                            <template v-if="task.repo">
                                <GitBranch
                                    class="size-3 shrink-0"
                                    :stroke-width="1.75"
                                />
                                <span class="min-w-0 truncate font-ui-mono">{{
                                    task.branch
                                }}</span>
                            </template>
                            <span v-else class="truncate">{{
                                task.agentName
                            }}</span>
                            <AiBrandIcon
                                v-if="brand(task)"
                                :brand="brand(task)!.id"
                                class="ml-auto size-3.5 shrink-0"
                                :class="brand(task)!.colour"
                            />
                            <SquareTerminal
                                v-else
                                class="ml-auto size-3.5 shrink-0"
                                :stroke-width="1.75"
                            />
                        </span>
                    </Link>
                </div>

                <!-- The rest, one line each. -->
                <section
                    v-if="settled.length"
                    :class="{ 'mt-5': active.length }"
                >
                    <button
                        type="button"
                        class="flex h-7 w-full cursor-pointer items-center gap-1 rounded-[8px] px-2.5 text-[12.5px]/4 text-(--ds-text-faint) hover:text-(--ds-text-muted)"
                        :class="focusRing"
                        :aria-expanded="settledOpen"
                        @click="settledOpen = !settledOpen"
                    >
                        {{ $t('History') }}
                        <span class="ml-auto tabular-nums">{{
                            settled.length
                        }}</span>
                        <ChevronDown
                            class="size-3.5 transition-transform"
                            :class="{ '-rotate-90': !settledOpen }"
                            :stroke-width="1.75"
                        />
                    </button>
                    <template v-if="settledOpen">
                        <Link
                            v-for="task in settledShown"
                            :key="task.id"
                            :href="`/agents/${task.id}`"
                            :class="[
                                row,
                                current === task.id
                                    ? 'bg-(--ds-hover) text-(--ds-text)'
                                    : 'text-(--ds-text-secondary) hover:bg-(--ds-hover) hover:text-(--ds-text)',
                            ]"
                            :aria-current="
                                current === task.id ? 'page' : undefined
                            "
                        >
                            <span
                                class="size-1.5 shrink-0 rounded-full"
                                :class="toneDot[taskStatus(task.status).tone]"
                                :title="taskStatus(task.status).label"
                            />
                            <span class="min-w-0 flex-1 truncate">{{
                                task.title
                            }}</span>
                            <span
                                class="shrink-0 text-[12px]/4 text-(--ds-text-faint) tabular-nums"
                                >{{ since(task.updatedAt) }}</span
                            >
                        </Link>
                        <button
                            v-if="!showAll && settled.length > 10"
                            type="button"
                            :class="[
                                row,
                                'cursor-pointer text-(--ds-text-faint) hover:text-(--ds-text-muted)',
                            ]"
                            @click="showAll = true"
                        >
                            <ChevronDown
                                class="size-3.5"
                                :stroke-width="1.75"
                            />
                            {{
                                $t('Show :count more', {
                                    count: String(settled.length - 10),
                                })
                            }}
                        </button>
                    </template>
                </section>

                <p
                    v-if="!active.length && !settled.length"
                    class="px-2.5 text-[12.5px]/5 text-(--ds-text-faint)"
                >
                    {{
                        tasks.length
                            ? $t('No tasks match.')
                            : $t('Your tasks show here.')
                    }}
                </p>
            </nav>

            <div class="flex shrink-0 flex-col gap-1 px-2 pb-1">
                <Link
                    v-if="page.props.hosting"
                    href="/agents"
                    :class="[row, 'h-auto py-2 hover:bg-(--ds-hover)']"
                >
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="text-[12px]/4 text-(--ds-text-faint)">{{
                            $t('Agent time')
                        }}</span>
                        <span class="truncate font-medium tabular-nums">{{
                            page.props.hosting.hasTime
                                ? $t(':balance left', {
                                      balance: page.props.hosting.balance ?? '',
                                  })
                                : $t('None yet')
                        }}</span>
                    </span>
                </Link>
                <button
                    type="button"
                    :class="[
                        row,
                        'cursor-pointer text-(--ds-text-secondary) hover:bg-(--ds-hover) hover:text-(--ds-text)',
                    ]"
                    @click="keysOpen = true"
                >
                    <KeyRound class="size-4" :stroke-width="1.75" />
                    {{ $t('AI keys') }}
                </button>
            </div>
        </template>

        <div v-else class="flex h-8 shrink-0 items-center justify-center">
            <button
                type="button"
                :class="iconButton"
                :aria-label="$t('Expand sidebar')"
                aria-expanded="false"
                @click="emit('toggle')"
            >
                <PanelLeft class="size-4" :stroke-width="1.6" />
            </button>
        </div>
        <Link
            v-if="collapsed"
            href="/agents"
            :class="[iconButton, 'mx-auto mt-3 size-8']"
            :aria-label="$t('New task')"
        >
            <SquarePen class="size-4" :stroke-width="1.75" />
        </Link>

        <div
            class="mt-auto flex shrink-0 items-center gap-1"
            :class="collapsed ? 'flex-col justify-center' : 'pt-1'"
        >
            <WorkspaceMenu
                v-if="!collapsed"
                variant="block"
                class="flex min-w-0 flex-1"
            />
            <AccountMenu
                display="avatar"
                :side="collapsed ? 'right' : 'top'"
                :align="collapsed ? 'end' : 'start'"
            />
        </div>

        <Dialog v-model:open="keysOpen">
            <DialogContent
                class="workspace-panel ds-modal gap-0 p-0 font-ui text-(--ds-text) sm:max-w-xl"
            >
                <div class="flex flex-col gap-1 px-5 pt-5 pb-4">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('AI keys')
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            $t(
                                'Agents use your own Anthropic or OpenAI key, so model usage is billed to you, not marked up. Keys are stored encrypted and only handed to the runs that need them.',
                            )
                        }}
                    </DialogDescription>
                </div>
                <div class="max-h-[70vh] overflow-y-auto px-5 pb-5">
                    <AiProviders
                        v-if="page.props.aiKeys"
                        v-bind="page.props.aiKeys"
                    />
                </div>
            </DialogContent>
        </Dialog>
    </div>
</template>
