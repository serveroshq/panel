<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowUpRight,
    ChevronLeft,
    Clock,
    CornerDownRight,
    Ellipsis,
    GitMerge,
    Globe,
    PanelLeftOpen,
    Plus,
    Rocket,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import AgentActivity from '@/components/agents/AgentActivity.vue';
import AgentChanges from '@/components/agents/AgentChanges.vue';
import TaskRail from '@/components/agents/TaskRail.vue';
import {
    agentState,
    duration,
    prNumber,
    taskStatus,
    toneDot,
    toneText,
} from '@/components/agents/agentTasks';
import type {
    AgentDetail,
    TaskDetail,
    TaskRow,
    Waiting,
} from '@/components/agents/agentTasks';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { appButtonClasses } from '@/lib/appButton';
import { jobStatus } from '@/lib/jobStatus';
import { renderMarkdown } from '@/lib/markdown';
import { toast } from '@/lib/toast';
import type { Workspace } from '@/types';

const props = defineProps<{
    workspace: Workspace;
    task: TaskDetail;
    canManage: boolean;
    twoFactor: boolean;
    maxAgents: number;
    tasks?: TaskRow[];
}>();

const status = computed(() => taskStatus(props.task.status));
const running = computed(() => props.task.status === 'running');
const live = computed(() => ['running', 'queued'].includes(props.task.status));
const canRun = computed(() => props.canManage && props.twoFactor);

const number = (agent: AgentDetail): number =>
    props.task.agents.indexOf(agent) + 1;
const byRun = (run: number | null): AgentDetail | null =>
    props.task.agents.find((a) => a.run === run) ?? null;

// The agent you're looking at: from the address, else the one most worth
// looking at (working, then one with a pull request open, then the newest).
const selectedRun = ref<number | null>(
    Number(new URLSearchParams(window.location.search).get('agent')) || null,
);
const selected = computed<AgentDetail | null>(() => {
    const agents = props.task.agents;

    return (
        byRun(selectedRun.value) ??
        agents.find((a) => a.state === 'running') ??
        [...agents].reverse().find((a) => a.prState === 'open') ??
        agents[agents.length - 1] ??
        null
    );
});

type Tab = 'activity' | 'changes' | 'output';
const tab = ref<Tab>('activity');
const detail = useTemplateRef<HTMLElement>('detail');

function select(agent: AgentDetail): void {
    selectedRun.value = agent.run;
    const url = new URL(window.location.href);
    url.searchParams.set('agent', String(agent.run));
    window.history.replaceState(window.history.state, '', url);
    if (tab.value === 'changes' && !agent.pr) tab.value = 'activity';
    void nextTick(() => detail.value?.scrollTo({ top: 0 }));
}

function closeAgent(): void {
    selectedRun.value = null;
}

/** What a row says about its agent, in one line. */
function line(agent: AgentDetail): { text: string; tone: string } {
    if (agent.state === 'running') {
        const last = [...agent.steps].reverse().find((s) => s.k !== 'note');
        const verbs: Record<string, string> = {
            read: trans('Reading'),
            edit: trans('Editing'),
            write: trans('Writing'),
            run: trans('Running'),
            search: trans('Searching for'),
            find: trans('Looking for'),
            web: trans('Looking up'),
        };

        return {
            text:
                agent.phase === 'pull_request'
                    ? trans('Opening the pull request')
                    : last
                      ? `${verbs[last.k] ?? trans('Using')} ${last.t}`
                      : trans('Starting'),
            tone: 'text-(--ds-text-secondary)',
        };
    }
    if (agent.state === 'failed') {
        return {
            text: (agent.error ?? agent.message ?? trans('Failed')).split(
                '\n',
            )[0],
            tone: 'text-(--ds-red)',
        };
    }
    if (agent.state === 'cancelled')
        return { text: trans('Stopped'), tone: 'text-(--ds-text-muted)' };
    if (agent.pr) {
        const state =
            agent.prState === 'merged'
                ? trans('merged')
                : agent.prState === 'closed'
                  ? trans('closed')
                  : trans('open');

        return {
            text: `${prNumber(agent.pr)} ${state}${agent.stat ? ` · ${agent.stat}` : ''}`,
            tone: 'text-(--ds-text-secondary)',
        };
    }

    return {
        text:
            agent.summary.split('\n').find((l) => l.trim()) ??
            trans('Finished without changes'),
        tone: 'text-(--ds-text-muted)',
    };
}

function waitingText(item: Waiting): string {
    if (!item.tonight)
        return trans('Waiting for room on :machine', {
            machine: props.task.machine.name,
        });
    const at = item.at
        ? new Date(item.at).toLocaleTimeString(undefined, {
              hour: 'numeric',
              minute: '2-digit',
          })
        : '1:00';

    return trans('Starts after :time, once :machine is quiet', {
        time: at,
        machine: props.task.machine.name,
    });
}

const promptOpen = ref(false);
const contextOpen = ref(false);

// A clock for the agents that are working, so their time ticks between polls.
const now = ref(Date.now());
const clock = setInterval(() => {
    if (running.value) now.value = Date.now();
}, 1000);
function seconds(agent: AgentDetail): number {
    return agent.state === 'running'
        ? Math.max(
              agent.seconds,
              Math.floor((now.value - Date.parse(agent.startedAt)) / 1000),
          )
        : agent.seconds;
}

// One poll at a time while anything is working or deploying; the rail of
// other tasks every fourth.
let poll: ReturnType<typeof setInterval> | undefined;
let polling = false;
let ticks = 0;
onMounted(() => {
    poll = setInterval(() => {
        const deploying = props.task.agents.some(
            (a) => a.deploy && ['queued', 'running'].includes(a.deploy.state),
        );
        if (
            (live.value || deploying) &&
            !polling &&
            document.visibilityState === 'visible'
        ) {
            polling = true;
            ticks++;
            router.reload({
                only: ticks % 4 === 0 ? ['task', 'tasks'] : ['task'],
                onFinish: () => (polling = false),
            });
        }
    }, 2500);
});
onBeforeUnmount(() => {
    clearInterval(poll);
    clearInterval(clock);
});

// The tab says when agents are working, so you can look away.
watch(
    () => [props.task.status, props.task.title] as const,
    ([state, title]) => {
        document.title =
            state === 'running'
                ? `● ${title}`
                : state === 'queued'
                  ? `◌ ${title}`
                  : title;
    },
);

// Say so when an agent finishes.
const seen = new Map(props.task.agents.map((a) => [a.run, a.state]));
watch(
    () => props.task.agents.map((a) => [a.run, a.state, a.pr] as const),
    (agents) => {
        for (const [run, state, pr] of agents) {
            const before = seen.get(run);
            seen.set(run, state);
            if (before !== 'running' || state === 'running') continue;
            const agent = byRun(run);
            const name = agent
                ? trans('Agent :n', { n: String(number(agent)) })
                : '';
            if (state === 'succeeded') {
                toast.success(
                    pr
                        ? trans(':agent opened :pr.', {
                              agent: name,
                              pr: prNumber(pr) ?? '',
                          })
                        : trans(':agent finished.', { agent: name }),
                );
            } else if (state === 'failed') {
                toast.error(trans(':agent failed.', { agent: name }));
            }
        }
    },
);

// The rail of tasks, open unless you closed it.
const railOpen = ref(true);
try {
    railOpen.value = localStorage.getItem('agents.rail') !== 'closed';
} catch {}
function toggleRail(open: boolean): void {
    railOpen.value = open;
    try {
        localStorage.setItem('agents.rail', open ? 'open' : 'closed');
    } catch {}
}

// Merge, deploy, preview: each checked again on the server.
const acting = ref<string | null>(null);
function act(agent: AgentDetail, what: 'merge' | 'deploy' | 'preview'): void {
    if (what === 'merge') {
        const siblings = props.task.agents.filter(
            (a) =>
                a.run !== agent.run &&
                a.turn === agent.turn &&
                a.prState === 'open',
        );
        const question = trans('Merge pull request :n into :branch?', {
            n: prNumber(agent.pr) ?? '',
            branch: agent.base || props.task.branch,
        });
        const closing = siblings.length
            ? ' ' +
              trans(
                  'The other agents’ pull requests for it (:prs) will be closed.',
                  { prs: siblings.map((a) => prNumber(a.pr)).join(', ') },
              )
            : '';
        if (!window.confirm(question + closing)) return;
    }
    acting.value = what;
    router.post(
        `/agents/${props.task.id}/runs/${agent.run}/${what}`,
        {},
        {
            preserveScroll: true,
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? trans('Couldn’t do that.'),
                ),
            onFinish: () => (acting.value = null),
        },
    );
}

const reply = useForm({ notes: '', agents: 1, after: null as number | null });
const replyBox = useTemplateRef<HTMLTextAreaElement>('replyBox');

function grow(): void {
    const el = replyBox.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 200)}px`;
}

function sendReply(): void {
    const agent = selected.value;
    if (
        !agent ||
        !reply.notes.trim() ||
        !canRun.value ||
        agent.state === 'running'
    )
        return;
    reply.after = agent.run;
    reply.post(`/agents/${props.task.id}/runs`, {
        preserveScroll: true,
        onSuccess: () => {
            reply.reset();
            selectedRun.value = null;
            void nextTick(grow);
        },
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Couldn’t start it.'),
            ),
    });
}

function anotherAgent(): void {
    router.post(
        `/agents/${props.task.id}/runs`,
        { agents: 1 },
        {
            preserveScroll: true,
            onSuccess: () => (selectedRun.value = null),
            onError: (errors) =>
                toast.error(
                    Object.values(errors)[0] ?? trans('Couldn’t start it.'),
                ),
        },
    );
}

function stop(): void {
    router.post(`/agents/${props.task.id}/stop`, {}, { preserveScroll: true });
}

function destroy(): void {
    if (!window.confirm(trans('Delete this task and its history?'))) return;
    router.delete(`/agents/${props.task.id}`, {
        onError: (errors) =>
            toast.error(
                Object.values(errors)[0] ?? trans('Couldn’t delete it.'),
            ),
    });
}

function host(url: string): string {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
}

const tabs = [
    { id: 'activity' as const, label: trans('Activity') },
    { id: 'changes' as const, label: trans('Changes') },
    { id: 'output' as const, label: trans('Output') },
];

const row =
    'flex w-full cursor-pointer gap-3 border-b border-(--ds-divider) px-4 py-2.5 text-left transition-colors hover:bg-(--ds-row-hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)';
const iconButton =
    'inline-flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-[6px] text-(--ds-text-muted) transition-colors hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="task.title" />

    <div
        class="workspace-panel flex h-full min-h-[520px] font-ui text-(--ds-text)"
    >
        <TaskRail
            v-if="railOpen && tasks"
            class="max-lg:hidden"
            :tasks="tasks"
            :current="task.id"
            @close="toggleRail(false)"
        />

        <div class="flex min-w-0 flex-1 flex-col">
            <!-- The task -->
            <header
                class="flex h-12 shrink-0 items-center gap-2 border-b border-(--ds-divider) px-3 sm:px-4"
            >
                <button
                    v-if="!railOpen && tasks"
                    type="button"
                    :class="iconButton"
                    class="max-lg:hidden"
                    :aria-label="$t('Show tasks')"
                    @click="toggleRail(true)"
                >
                    <PanelLeftOpen class="size-4" :stroke-width="1.75" />
                </button>
                <Link
                    href="/agents"
                    :class="iconButton"
                    class="lg:hidden"
                    :aria-label="$t('Agents')"
                >
                    <ChevronLeft class="size-4" :stroke-width="1.75" />
                </Link>
                <span
                    class="size-2 shrink-0 rounded-full"
                    :class="[
                        toneDot[status.tone],
                        running
                            ? 'animate-pulse motion-reduce:animate-none'
                            : '',
                    ]"
                    :title="status.label"
                />
                <h1 class="min-w-0 truncate text-[14px]/5 font-medium">
                    {{ task.title }}
                </h1>
                <span
                    class="shrink-0 text-[12.5px]/4 text-(--ds-text-muted) max-md:hidden"
                    >{{ status.label }}</span
                >

                <div class="ml-auto flex shrink-0 items-center gap-1">
                    <button
                        v-if="running && canManage"
                        type="button"
                        :class="appButtonClasses('quiet', 'sm')"
                        @click="stop"
                    >
                        {{ $t('Stop all') }}
                    </button>
                    <DropdownMenu v-if="canManage">
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                :class="iconButton"
                                :aria-label="$t('Task actions')"
                            >
                                <Ellipsis class="size-4" :stroke-width="1.75" />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="ds-modal min-w-48 font-ui"
                            align="end"
                        >
                            <DropdownMenuItem as-child>
                                <Link :href="`/machines/${task.machine.id}`">{{
                                    $t('Open :machine', {
                                        machine: task.machine.name,
                                    })
                                }}</Link>
                            </DropdownMenuItem>
                            <DropdownMenuSeparator />
                            <DropdownMenuItem
                                class="text-(--ds-red)"
                                :disabled="running"
                                @select="destroy"
                            >
                                {{ $t('Delete the task') }}
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
            </header>

            <div class="flex min-h-0 flex-1">
                <!-- The agents on it -->
                <section
                    class="flex min-h-0 w-full shrink-0 flex-col border-r border-(--ds-divider) md:w-[340px] xl:w-[380px]"
                    :class="{ 'max-md:hidden': selectedRun !== null }"
                    :aria-label="$t('Agents on this task')"
                >
                    <div class="min-h-0 flex-1 overflow-y-auto">
                        <div class="border-b border-(--ds-divider) px-4 py-3.5">
                            <p
                                class="text-[13px]/5 whitespace-pre-wrap text-(--ds-text)"
                                :class="{ 'line-clamp-3': !promptOpen }"
                                v-text="task.prompt"
                            />
                            <button
                                v-if="
                                    task.prompt.length > 180 ||
                                    task.prompt.includes('\n')
                                "
                                type="button"
                                class="mt-1 cursor-pointer text-[12px]/4 text-(--ds-text-muted) hover:text-(--ds-text)"
                                @click="promptOpen = !promptOpen"
                            >
                                {{ promptOpen ? $t('Less') : $t('More') }}
                            </button>
                            <dl
                                class="mt-3 grid grid-cols-[4.5rem_minmax(0,1fr)] gap-x-2 gap-y-1 text-[12px]/4"
                            >
                                <template v-if="task.repo">
                                    <dt class="text-(--ds-text-faint)">
                                        {{ $t('Repo') }}
                                    </dt>
                                    <dd
                                        class="truncate font-ui-mono text-[11.5px] text-(--ds-text-secondary)"
                                    >
                                        {{ task.repo }}@{{ task.branch }}
                                    </dd>
                                </template>
                                <dt class="text-(--ds-text-faint)">
                                    {{ $t('Machine') }}
                                </dt>
                                <dd class="truncate text-(--ds-text-secondary)">
                                    {{ task.machine.name }}
                                    <span class="text-(--ds-text-faint)"
                                        >·
                                        {{
                                            $t(':running of :slots busy', {
                                                running: String(
                                                    task.capacity.running,
                                                ),
                                                slots: String(
                                                    task.capacity.slots,
                                                ),
                                            })
                                        }}</span
                                    >
                                </dd>
                                <dt class="text-(--ds-text-faint)">
                                    {{ $t('Agent') }}
                                </dt>
                                <dd class="truncate text-(--ds-text-secondary)">
                                    {{ task.agentName }}
                                    <span
                                        v-if="task.model"
                                        class="font-ui-mono text-[11.5px] text-(--ds-text-faint)"
                                        >{{ task.model }}</span
                                    >
                                </dd>
                                <template v-if="task.context">
                                    <dt class="text-(--ds-text-faint)">
                                        {{ $t('From') }}
                                    </dt>
                                    <dd class="min-w-0">
                                        <button
                                            type="button"
                                            class="w-full cursor-pointer truncate text-left text-(--ds-text-secondary) underline decoration-(--ds-border-strong) underline-offset-2 hover:text-(--ds-text)"
                                            :aria-expanded="contextOpen"
                                            @click="contextOpen = !contextOpen"
                                        >
                                            {{ task.context.label }}
                                        </button>
                                    </dd>
                                </template>
                            </dl>
                            <pre
                                v-if="contextOpen && task.context?.text"
                                class="mt-2 max-h-56 overflow-auto border-l-2 border-(--ds-border-strong) pl-2.5 font-ui-mono text-[11px]/4.5 whitespace-pre-wrap text-(--ds-text-muted)"
                                v-text="task.context.text"
                            />
                        </div>

                        <div
                            class="flex h-9 items-center justify-between px-4 text-[12px]/4 text-(--ds-text-faint)"
                        >
                            <span
                                >{{ $t('Agents') }} ·
                                {{
                                    task.agents.length + task.waiting.length
                                }}</span
                            >
                            <button
                                v-if="canManage"
                                type="button"
                                class="inline-flex cursor-pointer items-center gap-1 rounded-[5px] hover:text-(--ds-text) disabled:pointer-events-none disabled:opacity-50"
                                :disabled="!canRun"
                                @click="anotherAgent"
                            >
                                <Plus class="size-3.5" :stroke-width="1.75" />
                                {{ $t('Another agent') }}
                            </button>
                        </div>

                        <ul class="border-t border-(--ds-divider)">
                            <li v-for="agent in task.agents" :key="agent.run">
                                <button
                                    type="button"
                                    :class="[
                                        row,
                                        selected?.run === agent.run
                                            ? 'bg-(--ds-hover)'
                                            : '',
                                    ]"
                                    :aria-current="
                                        selected?.run === agent.run
                                            ? 'true'
                                            : undefined
                                    "
                                    @click="select(agent)"
                                >
                                    <span
                                        class="flex w-3 shrink-0 justify-center pt-1.5"
                                    >
                                        <span
                                            class="size-1.5 rounded-full"
                                            :class="[
                                                toneDot[agentState(agent).tone],
                                                agent.state === 'running'
                                                    ? 'animate-pulse motion-reduce:animate-none'
                                                    : '',
                                            ]"
                                        />
                                    </span>
                                    <span
                                        class="flex min-w-0 flex-1 flex-col gap-0.5"
                                    >
                                        <span
                                            class="flex items-center gap-1.5 text-[13px]/5"
                                        >
                                            <span class="font-medium">{{
                                                $t('Agent :n', {
                                                    n: String(number(agent)),
                                                })
                                            }}</span>
                                            <span
                                                v-if="
                                                    agent.after &&
                                                    byRun(agent.after)
                                                "
                                                class="inline-flex min-w-0 items-center gap-0.5 truncate text-[12px] text-(--ds-text-faint)"
                                            >
                                                <CornerDownRight
                                                    class="size-3 shrink-0"
                                                    :stroke-width="1.75"
                                                />
                                                {{
                                                    $t('follows :n', {
                                                        n: String(
                                                            number(
                                                                byRun(
                                                                    agent.after,
                                                                )!,
                                                            ),
                                                        ),
                                                    })
                                                }}
                                            </span>
                                            <span
                                                class="ml-auto shrink-0 text-[12px] text-(--ds-text-faint) tabular-nums"
                                                >{{
                                                    duration(seconds(agent))
                                                }}</span
                                            >
                                        </span>
                                        <span
                                            class="truncate text-[12.5px]/4"
                                            :class="line(agent).tone"
                                            :title="line(agent).text"
                                            >{{ line(agent).text }}</span
                                        >
                                    </span>
                                </button>
                            </li>
                            <li
                                v-for="(item, i) in task.waiting"
                                :key="`waiting-${i}`"
                                class="flex gap-3 border-b border-(--ds-divider) px-4 py-2.5"
                            >
                                <span
                                    class="flex w-3 shrink-0 justify-center pt-1"
                                >
                                    <Clock
                                        class="size-3 text-(--ds-amber)"
                                        :stroke-width="2"
                                    />
                                </span>
                                <span class="flex min-w-0 flex-col gap-0.5">
                                    <span
                                        class="text-[13px]/5 text-(--ds-text-muted)"
                                        >{{ $t('Waiting') }}</span
                                    >
                                    <span
                                        class="truncate text-[12.5px]/4 text-(--ds-text-faint)"
                                        >{{ waitingText(item) }}</span
                                    >
                                </span>
                            </li>
                        </ul>
                    </div>
                </section>

                <!-- The agent you picked -->
                <section
                    v-if="selected"
                    class="flex min-w-0 flex-1 flex-col"
                    :class="{ 'max-md:hidden': selectedRun === null }"
                    :aria-label="
                        $t('Agent :n', { n: String(number(selected)) })
                    "
                >
                    <div
                        class="flex shrink-0 flex-wrap items-center gap-x-3 gap-y-2 border-b border-(--ds-divider) px-4 pt-3"
                    >
                        <button
                            type="button"
                            :class="iconButton"
                            class="-ml-1.5 md:hidden"
                            :aria-label="$t('All agents')"
                            @click="closeAgent"
                        >
                            <ChevronLeft class="size-4" :stroke-width="1.75" />
                        </button>
                        <div class="flex min-w-0 items-baseline gap-2">
                            <h2 class="text-[14px]/5 font-medium">
                                {{
                                    $t('Agent :n', {
                                        n: String(number(selected)),
                                    })
                                }}
                            </h2>
                            <span
                                class="text-[12.5px]/4"
                                :class="toneText[agentState(selected).tone]"
                                >{{ agentState(selected).label }}</span
                            >
                            <span
                                v-if="selected.model"
                                class="truncate font-ui-mono text-[11.5px] text-(--ds-text-faint) max-sm:hidden"
                                >{{ selected.model }}</span
                            >
                            <span
                                v-if="selected.cost !== null"
                                class="text-[12px] text-(--ds-text-faint) tabular-nums max-sm:hidden"
                                :title="
                                    $t('What the model’s API charged for it.')
                                "
                                >${{ selected.cost.toFixed(2) }}</span
                            >
                        </div>

                        <!-- The next step for this agent's work. -->
                        <div
                            v-if="selected.pr && canManage"
                            class="ml-auto flex items-center gap-1.5"
                        >
                            <a
                                v-if="
                                    selected.preview?.state === 'live' &&
                                    selected.preview.url
                                "
                                :href="selected.preview.url"
                                target="_blank"
                                rel="noopener"
                                :class="appButtonClasses('secondary', 'sm')"
                                :title="host(selected.preview.url)"
                            >
                                <Globe class="size-3.5" :stroke-width="1.75" />
                                {{ $t('Preview') }}
                            </a>
                            <button
                                v-else-if="
                                    selected.preview?.state === 'none' &&
                                    selected.prState === 'open'
                                "
                                type="button"
                                :class="appButtonClasses('secondary', 'sm')"
                                :disabled="acting !== null || !twoFactor"
                                @click="act(selected, 'preview')"
                            >
                                <Globe class="size-3.5" :stroke-width="1.75" />
                                {{ $t('Open a preview') }}
                            </button>
                            <a
                                :href="selected.pr"
                                target="_blank"
                                rel="noopener"
                                :class="appButtonClasses('secondary', 'sm')"
                            >
                                {{ prNumber(selected.pr) }}
                                <ArrowUpRight
                                    class="size-3 text-(--ds-text-muted)"
                                    :stroke-width="2"
                                />
                            </a>
                            <button
                                v-if="selected.prState === 'open'"
                                type="button"
                                :class="appButtonClasses('primary', 'sm')"
                                :disabled="acting !== null || !twoFactor"
                                @click="act(selected, 'merge')"
                            >
                                <GitMerge
                                    class="size-3.5"
                                    :stroke-width="1.75"
                                />
                                {{ $t('Merge') }}
                            </button>
                            <button
                                v-else-if="
                                    selected.prState === 'merged' &&
                                    selected.canDeploy &&
                                    !selected.deploy &&
                                    selected.base === task.branch
                                "
                                type="button"
                                :class="appButtonClasses('primary', 'sm')"
                                :disabled="acting !== null || !twoFactor"
                                @click="act(selected, 'deploy')"
                            >
                                <Rocket class="size-3.5" :stroke-width="1.75" />
                                {{
                                    $t('Deploy :service', {
                                        service:
                                            selected.canDeploy.service ?? '',
                                    })
                                }}
                            </button>
                            <Link
                                v-else-if="selected.deploy"
                                :href="`/machines/${selected.deploy.machine.id}?job=${selected.deploy.id}`"
                                :class="appButtonClasses('secondary', 'sm')"
                            >
                                <span
                                    class="size-1.5 rounded-full"
                                    :class="
                                        toneDot[
                                            jobStatus(selected.deploy.state)
                                                .tone === 'amber'
                                                ? 'blue'
                                                : jobStatus(
                                                      selected.deploy.state,
                                                  ).tone
                                        ]
                                    "
                                />
                                {{
                                    selected.deploy.state === 'succeeded'
                                        ? $t('Live on :machine', {
                                              machine:
                                                  selected.deploy.machine.name,
                                          })
                                        : jobStatus(selected.deploy.state).label
                                }}
                            </Link>
                        </div>

                        <nav
                            class="-mb-px flex w-full gap-5"
                            :aria-label="$t('What this agent did')"
                        >
                            <button
                                v-for="t in tabs"
                                :key="t.id"
                                type="button"
                                class="h-10 cursor-pointer border-b text-[13px]/4 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring) disabled:pointer-events-none disabled:opacity-40"
                                :class="
                                    tab === t.id
                                        ? 'border-(--ds-text) text-(--ds-text)'
                                        : 'border-transparent text-(--ds-text-muted) hover:text-(--ds-text)'
                                "
                                :disabled="t.id === 'changes' && !selected.pr"
                                :aria-pressed="tab === t.id"
                                @click="tab = t.id"
                            >
                                {{ t.label }}
                                <span
                                    v-if="t.id === 'changes' && selected.stat"
                                    class="ml-1 text-[11.5px] text-(--ds-text-faint)"
                                    >{{ selected.stat.split(',')[0] }}</span
                                >
                            </button>
                        </nav>
                    </div>

                    <div ref="detail" class="min-h-0 flex-1 overflow-y-auto">
                        <div
                            v-if="tab === 'activity'"
                            class="mx-auto flex max-w-3xl flex-col gap-5 px-5 py-5"
                        >
                            <p
                                v-if="selected.notes"
                                class="border-l-2 border-(--ds-border-strong) pl-3 text-[13px]/5 whitespace-pre-wrap text-(--ds-text-secondary)"
                                v-text="selected.notes"
                            />
                            <p
                                v-if="
                                    selected.state === 'failed' &&
                                    (selected.error || selected.message)
                                "
                                class="text-[13.5px]/6 text-(--ds-red)"
                            >
                                {{ selected.error ?? selected.message }}
                            </p>
                            <!-- eslint-disable-next-line vue/no-v-html -- renderMarkdown sanitises -->
                            <div
                                v-if="selected.summary"
                                class="agent-prose text-[14px]/6.5 text-(--ds-text)"
                                v-html="renderMarkdown(selected.summary, true)"
                            />
                            <AgentActivity
                                :key="selected.run"
                                :agent="selected"
                                :now="now"
                                expanded
                            />
                        </div>

                        <AgentChanges
                            v-else-if="tab === 'changes'"
                            :task-id="task.id"
                            :agent="selected"
                        />

                        <pre
                            v-else
                            class="px-5 py-4 font-ui-mono text-[11.5px]/5 whitespace-pre-wrap text-(--ds-text-secondary)"
                            v-text="
                                selected.log.length
                                    ? selected.log.join('\n')
                                    : $t(
                                          'Nothing yet. The machine sends its output as the agent works.',
                                      )
                            "
                        />
                    </div>

                    <!-- Follow up with this agent -->
                    <form
                        v-if="canManage && selected.state !== 'running'"
                        class="shrink-0 border-t border-(--ds-divider) px-4 py-3"
                        @submit.prevent="sendReply"
                    >
                        <div class="flex items-end gap-2">
                            <label for="agent-reply" class="sr-only">{{
                                $t('Follow up')
                            }}</label>
                            <textarea
                                id="agent-reply"
                                ref="replyBox"
                                v-model="reply.notes"
                                rows="1"
                                maxlength="4000"
                                class="max-h-50 min-h-8 flex-1 resize-none bg-transparent py-1.5 text-[13.5px]/5 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint)"
                                :placeholder="
                                    twoFactor
                                        ? $t(
                                              'Ask agent :n to change something',
                                              { n: String(number(selected)) },
                                          )
                                        : $t(
                                              'Turn on two-factor authentication to run agents.',
                                          )
                                "
                                :disabled="!twoFactor"
                                @input="grow"
                                @keydown.enter.exact.prevent="sendReply"
                            />
                            <button
                                type="submit"
                                :class="appButtonClasses('secondary', 'sm')"
                                :disabled="
                                    !canRun ||
                                    !reply.notes.trim() ||
                                    reply.processing
                                "
                            >
                                {{ $t('Send') }}
                            </button>
                        </div>
                        <p class="mt-1 text-[11.5px]/4 text-(--ds-text-faint)">
                            {{
                                selected.pr && selected.prState === 'open'
                                    ? $t(
                                          'A new agent picks up :pr’s branch and opens a pull request on top of it.',
                                          { pr: prNumber(selected.pr) ?? '' },
                                      )
                                    : $t(
                                          'A new agent starts from :branch with this one’s notes.',
                                          { branch: task.branch },
                                      )
                            }}
                        </p>
                    </form>
                </section>

                <section
                    v-else
                    class="flex flex-1 items-center justify-center text-[13px]/5 text-(--ds-text-muted) max-md:hidden"
                >
                    {{
                        task.waiting.length
                            ? $t('The agents are waiting to start.')
                            : $t('No agent has started on this task yet.')
                    }}
                </section>
            </div>
        </div>
    </div>
</template>

<style scoped>
.agent-prose :deep(p + p),
.agent-prose :deep(ul),
.agent-prose :deep(ol),
.agent-prose :deep(pre) {
    margin-top: 0.7em;
}
.agent-prose :deep(ul) {
    list-style: disc;
    padding-left: 1.2em;
}
.agent-prose :deep(ol) {
    list-style: decimal;
    padding-left: 1.2em;
}
.agent-prose :deep(li + li) {
    margin-top: 0.25em;
}
.agent-prose :deep(h1),
.agent-prose :deep(h2),
.agent-prose :deep(h3) {
    margin-top: 1em;
    font-weight: 600;
}
.agent-prose :deep(code) {
    font-family: var(--font-ui-mono, ui-monospace, monospace);
    font-size: 0.86em;
    color: var(--ds-text);
}
.agent-prose :deep(pre) {
    overflow-x: auto;
    padding: 0.6em 0.8em;
    border: 1px solid var(--ds-divider);
    border-radius: 6px;
}
.agent-prose :deep(a) {
    color: var(--ds-blue);
    text-decoration: underline;
    text-underline-offset: 3px;
}
</style>
