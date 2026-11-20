<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import {
    ArrowUp,
    ArrowUpRight,
    ChevronLeft,
    ChevronRight,
    Check,
    ChevronDown,
    Clock,
    Ellipsis,
    GitMerge,
    Globe,
    LoaderCircle,
    Plus,
    Rocket,
} from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    reactive,
    ref,
    useTemplateRef,
    watch,
} from 'vue';
import AgentActivity from '@/components/agents/AgentActivity.vue';
import AgentChanges from '@/components/agents/AgentChanges.vue';
import AgentSetup from '@/components/agents/AgentSetup.vue';
import type { Setup } from '@/components/agents/AgentSetup.vue';
import {
    agentState,
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
    /** ServerOS's machine still being made for it. */
    setup?: Setup | null;
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

// The thread for the agent you're looking at: the agents it follows on
// from, oldest first, then it. Each turn is what was asked, then what it did.
const chain = computed<AgentDetail[]>(() => {
    const out: AgentDetail[] = [];
    const seenRuns = new Set<number>();
    let agent = selected.value;
    while (agent && !seenRuns.has(agent.run)) {
        seenRuns.add(agent.run);
        out.unshift(agent);
        agent = agent.after ? byRun(agent.after) : null;
    }

    return out;
});

const detail = useTemplateRef<HTMLElement>('detail');
const changesOpen = reactive<Record<number, boolean>>({});
const outputOpen = reactive<Record<number, boolean>>({});

function select(agent: AgentDetail): void {
    selectedRun.value = agent.run;
    const url = new URL(window.location.href);
    url.searchParams.set('agent', String(agent.run));
    window.history.replaceState(window.history.state, '', url);
    void nextTick(toEnd);
}

// Like a chat, the thread opens at its latest turn.
function toEnd(): void {
    const el = detail.value;
    if (el) el.scrollTo({ top: el.scrollHeight });
}
onMounted(() => void nextTick(toEnd));

/** "+19 -4, 2 files" as its parts, for the changes card. */
function stat(agent: AgentDetail): {
    added: string;
    removed: string;
    files: number | null;
} | null {
    const m = agent.stat?.match(/\+(\d+)\s+-(\d+)(?:,\s*(\d+))?/);
    if (!m) return null;

    return {
        added: m[1],
        removed: m[2],
        files: m[3] ? Number(m[3]) : null,
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

const waitingLine = computed(() => {
    const items = props.task.waiting;
    if (!items.length) return null;
    const first = waitingText(items[0]);

    return items.length === 1
        ? first
        : trans(':count agents waiting. :why', {
              count: String(items.length),
              why: first,
          });
});

const promptOpen = ref(false);
const contextOpen = ref(false);

// A clock for the agents that are working, so their time ticks between polls.
const now = ref(Date.now());
const clock = setInterval(() => {
    if (running.value) now.value = Date.now();
}, 1000);

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
                only:
                    ticks % 4 === 0
                        ? ['task', 'setup', 'tasks']
                        : ['task', 'setup'],
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

const workingCount = computed(
    () => props.task.agents.filter((a) => a.state === 'running').length,
);
const iconButton =
    'inline-flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-[6px] text-(--ds-text-muted) transition-colors hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="task.title" />

    <div
        class="workspace-panel flex h-full min-h-[520px] flex-col font-ui text-(--ds-text)"
    >
        <!-- The task, and which agent's thread you're reading -->
        <header
            class="flex h-12 shrink-0 items-center gap-2 border-b border-(--ds-divider) px-3 sm:px-4"
        >
            <Link
                href="/agents"
                :class="iconButton"
                class="lg:hidden"
                :aria-label="$t('Agents')"
            >
                <ChevronLeft class="size-4" :stroke-width="1.75" />
            </Link>
            <h1 class="min-w-0 truncate text-[14px]/5 font-medium">
                {{ task.title }}
            </h1>
            <span
                class="shrink-0 text-[12.5px]/4 max-sm:hidden"
                :class="toneText[status.tone]"
                >{{ status.label }}</span
            >

            <div class="ml-auto flex shrink-0 items-center gap-1.5">
                <!-- The agents on this task: which one's thread you're reading. -->
                <DropdownMenu v-if="task.agents.length > 1 || canManage">
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            :class="appButtonClasses('quiet', 'sm')"
                            :aria-label="$t('Agents on this task')"
                        >
                            <span class="text-(--ds-text)">{{
                                selected
                                    ? $t('Agent :n', {
                                          n: String(number(selected)),
                                      })
                                    : $t('Agents')
                            }}</span>
                            <span
                                v-if="task.agents.length > 1"
                                class="text-(--ds-text-faint) tabular-nums"
                                >{{
                                    $t('of :count', {
                                        count: String(task.agents.length),
                                    })
                                }}</span
                            >
                            <ChevronDown
                                class="size-3.5 text-(--ds-text-muted)"
                                :stroke-width="2"
                            />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="ds-modal min-w-64 font-ui"
                        align="end"
                    >
                        <DropdownMenuItem
                            v-for="agent in task.agents"
                            :key="agent.run"
                            class="gap-3"
                            @select="select(agent)"
                        >
                            <span class="w-4 shrink-0">
                                <Check
                                    v-if="selected?.run === agent.run"
                                    class="size-3.5 text-(--ds-text)"
                                    :stroke-width="2.25"
                                />
                            </span>
                            <span class="flex min-w-0 flex-col">
                                <span class="text-[13px]/5">{{
                                    $t('Agent :n', {
                                        n: String(number(agent)),
                                    })
                                }}</span>
                                <span
                                    class="truncate text-[12px]/4"
                                    :class="toneText[agentState(agent).tone]"
                                    >{{ agentState(agent).label
                                    }}<template v-if="agent.pr">
                                        · {{ prNumber(agent.pr) }}</template
                                    ></span
                                >
                            </span>
                        </DropdownMenuItem>
                        <template v-if="canManage">
                            <DropdownMenuSeparator v-if="task.agents.length" />
                            <DropdownMenuItem
                                class="gap-3"
                                :disabled="!canRun"
                                @select="anotherAgent"
                            >
                                <span class="w-4 shrink-0">
                                    <Plus class="size-3.5" :stroke-width="2" />
                                </span>
                                {{ $t('Another agent') }}
                            </DropdownMenuItem>
                        </template>
                    </DropdownMenuContent>
                </DropdownMenu>
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

        <!-- Its machine is still being made: their words, and how it's going. -->
        <AgentSetup
            v-if="setup"
            :setup="setup"
            :agent="task.agent"
            :agent-name="task.agentName"
            :prompt="task.prompt"
            :meta="
                [task.repo, task.repo ? task.branch : null]
                    .filter(Boolean)
                    .join(' · ')
            "
        />
        <template v-else>
            <div ref="detail" class="min-h-0 flex-1 overflow-y-auto">
                <div
                    class="mx-auto flex w-full max-w-[51rem] flex-col gap-8 px-4 pt-8 pb-10 sm:px-6"
                >
                    <template v-if="!chain.length">
                        <div class="flex flex-col items-end gap-1.5">
                            <p
                                class="max-w-[85%] rounded-[16px] bg-(--ds-hover) px-4 py-2.5 text-[14px]/6 whitespace-pre-wrap"
                                v-text="task.prompt"
                            />
                        </div>
                    </template>

                    <section
                        v-for="(agent, i) in chain"
                        :key="agent.run"
                        class="flex flex-col gap-5"
                        :aria-label="
                            $t('Agent :n', { n: String(number(agent)) })
                        "
                    >
                        <!-- What was asked -->
                        <div class="flex flex-col items-end gap-1.5">
                            <template v-if="i === 0">
                                <p
                                    class="max-w-[85%] rounded-[16px] bg-(--ds-hover) px-4 py-2.5 text-[14px]/6 whitespace-pre-wrap"
                                    :class="{ 'line-clamp-6': !promptOpen }"
                                    v-text="task.prompt"
                                />
                                <button
                                    v-if="
                                        task.prompt.length > 400 ||
                                        task.prompt.split('\n').length > 6
                                    "
                                    type="button"
                                    class="cursor-pointer px-1 text-[12px]/4 text-(--ds-text-muted) hover:text-(--ds-text)"
                                    @click="promptOpen = !promptOpen"
                                >
                                    {{ promptOpen ? $t('Less') : $t('More') }}
                                </button>
                                <p
                                    class="flex max-w-full flex-wrap justify-end gap-x-1.5 px-1 text-[12px]/4 text-(--ds-text-faint)"
                                >
                                    <span
                                        v-if="task.repo"
                                        class="font-ui-mono text-[11.5px]"
                                        >{{ task.repo }}@{{ task.branch }}</span
                                    >
                                    <span v-if="task.repo" aria-hidden="true"
                                        >·</span
                                    >
                                    <span>{{ task.machine.name }}</span>
                                    <template v-if="task.context">
                                        <span aria-hidden="true">·</span>
                                        <button
                                            type="button"
                                            class="cursor-pointer underline decoration-(--ds-border-strong) underline-offset-2 hover:text-(--ds-text)"
                                            :aria-expanded="contextOpen"
                                            @click="contextOpen = !contextOpen"
                                        >
                                            {{ task.context.label }}
                                        </button>
                                    </template>
                                </p>
                                <pre
                                    v-if="contextOpen && task.context?.text"
                                    class="max-h-56 w-full overflow-auto rounded-[10px] bg-(--ds-subtle) px-3 py-2 font-ui-mono text-[11px]/4.5 whitespace-pre-wrap text-(--ds-text-muted)"
                                    v-text="task.context.text"
                                />
                            </template>
                            <p
                                v-if="agent.notes"
                                class="max-w-[85%] rounded-[16px] bg-(--ds-hover) px-4 py-2.5 text-[14px]/6 whitespace-pre-wrap"
                                v-text="agent.notes"
                            />
                        </div>

                        <!-- What it did -->
                        <div class="flex flex-col gap-4">
                            <p
                                class="flex items-center gap-2 text-[12.5px]/4 text-(--ds-text-muted)"
                            >
                                <span class="font-medium text-(--ds-text)">{{
                                    $t('Agent :n', { n: String(number(agent)) })
                                }}</span>
                                <span
                                    :class="toneText[agentState(agent).tone]"
                                    >{{ agentState(agent).label }}</span
                                >
                            </p>

                            <p
                                v-if="
                                    agent.state === 'failed' &&
                                    (agent.error || agent.message)
                                "
                                class="text-[14px]/6 text-(--ds-red)"
                            >
                                {{ agent.error ?? agent.message }}
                            </p>
                            <!-- eslint-disable-next-line vue/no-v-html -- renderMarkdown sanitises -->
                            <div
                                v-if="agent.summary"
                                class="agent-prose text-[14px]/6.5 text-(--ds-text)"
                                v-html="renderMarkdown(agent.summary, true)"
                            />

                            <ul
                                v-if="agent.shots?.length"
                                class="grid gap-3 sm:grid-cols-2"
                                :aria-label="$t('What it looks like')"
                            >
                                <li v-for="shot in agent.shots" :key="shot.url">
                                    <a
                                        :href="shot.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="group block overflow-hidden rounded-[10px] border border-(--ds-border) bg-(--ds-subtle) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                                    >
                                        <img
                                            :src="shot.url"
                                            :alt="shot.name"
                                            loading="lazy"
                                            class="aspect-[16/10] w-full object-cover object-top transition-opacity group-hover:opacity-90"
                                        />
                                    </a>
                                </li>
                            </ul>

                            <AgentActivity
                                :key="agent.run"
                                :agent="agent"
                                :now="now"
                            />

                            <!-- Its pull request: the changes, and what's next for them -->
                            <div
                                v-if="agent.pr"
                                class="overflow-hidden rounded-[12px] border border-(--ds-border) bg-(--ds-card)"
                            >
                                <div
                                    class="flex flex-wrap items-center gap-x-3 gap-y-2 py-2 pr-2 pl-3"
                                >
                                    <button
                                        type="button"
                                        class="flex h-8 min-w-0 cursor-pointer items-center gap-2 rounded-[7px] pr-2 text-[13px]/4 focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                        :aria-expanded="
                                            !!changesOpen[agent.run]
                                        "
                                        @click="
                                            changesOpen[agent.run] =
                                                !changesOpen[agent.run]
                                        "
                                    >
                                        <ChevronRight
                                            class="size-3.5 shrink-0 text-(--ds-text-muted) transition-transform duration-150 motion-reduce:transition-none"
                                            :class="{
                                                'rotate-90':
                                                    changesOpen[agent.run],
                                            }"
                                            :stroke-width="2"
                                        />
                                        <span class="font-medium">{{
                                            stat(agent)?.files
                                                ? $t(':n files changed', {
                                                      n: String(
                                                          stat(agent)!.files,
                                                      ),
                                                  })
                                                : $t('Changes')
                                        }}</span>
                                        <span
                                            v-if="stat(agent)"
                                            class="text-[12.5px] tabular-nums"
                                            ><span class="text-(--ds-green)"
                                                >+{{ stat(agent)!.added }}</span
                                            >
                                            <span class="ml-1 text-(--ds-red)"
                                                >−{{
                                                    stat(agent)!.removed
                                                }}</span
                                            ></span
                                        >
                                    </button>

                                    <div
                                        class="ml-auto flex flex-wrap items-center gap-1.5"
                                    >
                                        <a
                                            v-if="
                                                agent.preview?.state ===
                                                    'live' && agent.preview.url
                                            "
                                            :href="agent.preview.url"
                                            target="_blank"
                                            rel="noopener"
                                            :class="
                                                appButtonClasses('quiet', 'sm')
                                            "
                                            :title="host(agent.preview.url)"
                                        >
                                            <Globe
                                                class="size-3.5"
                                                :stroke-width="1.75"
                                            />
                                            {{ $t('Preview') }}
                                        </a>
                                        <button
                                            v-else-if="
                                                canManage &&
                                                agent.preview?.state ===
                                                    'none' &&
                                                agent.prState === 'open'
                                            "
                                            type="button"
                                            :class="
                                                appButtonClasses('quiet', 'sm')
                                            "
                                            :disabled="
                                                acting !== null || !twoFactor
                                            "
                                            @click="act(agent, 'preview')"
                                        >
                                            <Globe
                                                class="size-3.5"
                                                :stroke-width="1.75"
                                            />
                                            {{ $t('Preview') }}
                                        </button>
                                        <a
                                            :href="agent.pr"
                                            target="_blank"
                                            rel="noopener"
                                            :class="
                                                appButtonClasses('quiet', 'sm')
                                            "
                                        >
                                            {{ prNumber(agent.pr) }}
                                            <ArrowUpRight
                                                class="size-3 text-(--ds-text-muted)"
                                                :stroke-width="2"
                                            />
                                        </a>
                                        <template v-if="canManage">
                                            <button
                                                v-if="agent.prState === 'open'"
                                                type="button"
                                                :class="
                                                    appButtonClasses(
                                                        'primary',
                                                        'sm',
                                                    )
                                                "
                                                :disabled="
                                                    acting !== null ||
                                                    !twoFactor
                                                "
                                                @click="act(agent, 'merge')"
                                            >
                                                <GitMerge
                                                    class="size-3.5"
                                                    :stroke-width="1.75"
                                                />
                                                {{ $t('Merge') }}
                                            </button>
                                            <button
                                                v-else-if="
                                                    agent.prState ===
                                                        'merged' &&
                                                    agent.canDeploy &&
                                                    !agent.deploy &&
                                                    agent.base === task.branch
                                                "
                                                type="button"
                                                :class="
                                                    appButtonClasses(
                                                        'primary',
                                                        'sm',
                                                    )
                                                "
                                                :disabled="
                                                    acting !== null ||
                                                    !twoFactor
                                                "
                                                @click="act(agent, 'deploy')"
                                            >
                                                <Rocket
                                                    class="size-3.5"
                                                    :stroke-width="1.75"
                                                />
                                                {{
                                                    $t('Deploy :service', {
                                                        service:
                                                            agent.canDeploy
                                                                .service ?? '',
                                                    })
                                                }}
                                            </button>
                                            <Link
                                                v-else-if="agent.deploy"
                                                :href="`/machines/${agent.deploy.machine.id}?job=${agent.deploy.id}`"
                                                :class="
                                                    appButtonClasses(
                                                        'quiet',
                                                        'sm',
                                                    )
                                                "
                                            >
                                                <span
                                                    class="size-1.5 rounded-full"
                                                    :class="
                                                        toneDot[
                                                            jobStatus(
                                                                agent.deploy
                                                                    .state,
                                                            ).tone === 'amber'
                                                                ? 'blue'
                                                                : jobStatus(
                                                                      agent
                                                                          .deploy
                                                                          .state,
                                                                  ).tone
                                                        ]
                                                    "
                                                />
                                                {{
                                                    agent.deploy.state ===
                                                    'succeeded'
                                                        ? $t(
                                                              'Live on :machine',
                                                              {
                                                                  machine:
                                                                      agent
                                                                          .deploy
                                                                          .machine
                                                                          .name,
                                                              },
                                                          )
                                                        : jobStatus(
                                                              agent.deploy
                                                                  .state,
                                                          ).label
                                                }}
                                            </Link>
                                        </template>
                                    </div>
                                </div>
                                <AgentChanges
                                    v-if="changesOpen[agent.run]"
                                    class="border-t border-(--ds-divider)"
                                    :task-id="task.id"
                                    :agent="agent"
                                />
                            </div>

                            <p
                                class="flex flex-wrap items-center gap-x-2 gap-y-1 text-[12px]/4 text-(--ds-text-faint)"
                            >
                                <span v-if="agent.model" class="font-ui-mono">{{
                                    agent.model
                                }}</span>
                                <span
                                    v-if="agent.cost !== null"
                                    class="tabular-nums"
                                    :title="
                                        $t(
                                            'What the model’s API charged for it.',
                                        )
                                    "
                                    >${{ agent.cost.toFixed(2) }}</span
                                >
                                <button
                                    type="button"
                                    class="cursor-pointer hover:text-(--ds-text)"
                                    :aria-expanded="!!outputOpen[agent.run]"
                                    @click="
                                        outputOpen[agent.run] =
                                            !outputOpen[agent.run]
                                    "
                                >
                                    {{
                                        outputOpen[agent.run]
                                            ? $t('Hide the output')
                                            : $t('Raw output')
                                    }}
                                </button>
                            </p>
                            <pre
                                v-if="outputOpen[agent.run]"
                                class="max-h-96 overflow-auto rounded-[10px] bg-(--ds-subtle) px-3.5 py-3 font-ui-mono text-[11.5px]/5 whitespace-pre-wrap text-(--ds-text-secondary)"
                                v-text="
                                    agent.log.length
                                        ? agent.log.join('\n')
                                        : $t(
                                              'Nothing yet. The machine sends its output as the agent works.',
                                          )
                                "
                            />
                        </div>
                    </section>

                    <p
                        v-if="waitingLine"
                        class="flex items-center gap-2 text-[13px]/5 text-(--ds-text-muted)"
                    >
                        <Clock
                            class="size-3.5 shrink-0 text-(--ds-amber)"
                            :stroke-width="2"
                        />
                        {{ waitingLine }}
                    </p>
                </div>
            </div>

            <!-- Follow up: a new agent picks up from this one -->
            <form
                v-if="canManage && selected"
                class="shrink-0 px-4 pt-1 pb-4 sm:px-6"
                @submit.prevent="sendReply"
            >
                <div
                    class="mx-auto max-w-3xl rounded-[16px] border border-(--ds-border-strong) bg-(--ds-card) shadow-[0_1px_2px_rgb(2_3_14/.04)] transition-colors focus-within:border-(--ds-border-focus)"
                >
                    <div
                        v-if="selected.state === 'running'"
                        class="flex h-14 items-center gap-2.5 pr-2.5 pl-4 text-[13.5px]/5 text-(--ds-text-muted)"
                    >
                        <LoaderCircle
                            class="size-4 animate-spin text-(--ds-blue) motion-reduce:animate-none"
                            :stroke-width="2"
                        />
                        {{
                            workingCount > 1
                                ? $t(':count agents are working', {
                                      count: String(workingCount),
                                  })
                                : $t('Working on it')
                        }}
                        <button
                            type="button"
                            class="ml-auto"
                            :class="appButtonClasses('secondary', 'sm')"
                            @click="stop"
                        >
                            {{ workingCount > 1 ? $t('Stop all') : $t('Stop') }}
                        </button>
                    </div>
                    <template v-else>
                        <label for="agent-reply" class="sr-only">{{
                            $t('Follow up')
                        }}</label>
                        <textarea
                            id="agent-reply"
                            ref="replyBox"
                            v-model="reply.notes"
                            rows="1"
                            maxlength="4000"
                            class="block max-h-50 min-h-12 w-full resize-none bg-transparent px-4 pt-3.5 pb-1 text-[14px]/6 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint)"
                            :placeholder="
                                twoFactor
                                    ? $t('Ask for a change')
                                    : $t(
                                          'Turn on two-factor authentication to run agents.',
                                      )
                            "
                            :disabled="!twoFactor"
                            @input="grow"
                            @keydown.enter.exact.prevent="sendReply"
                        />
                        <div class="flex items-center gap-2 pr-2 pb-2 pl-4">
                            <p
                                class="min-w-0 truncate text-[12px]/4 text-(--ds-text-faint)"
                            >
                                {{
                                    selected.pr && selected.prState === 'open'
                                        ? $t(
                                              'Agent :n’s branch, as a pull request on top of :pr',
                                              {
                                                  n: String(number(selected)),
                                                  pr:
                                                      prNumber(selected.pr) ??
                                                      '',
                                              },
                                          )
                                        : $t(
                                              'A new agent from :branch, with agent :n’s notes',
                                              {
                                                  branch: task.branch,
                                                  n: String(number(selected)),
                                              },
                                          )
                                }}
                            </p>
                            <button
                                type="submit"
                                class="ml-auto flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-full transition-[background-color,color,scale] duration-150 active:scale-95 disabled:cursor-default motion-reduce:active:scale-100"
                                :class="
                                    canRun &&
                                    reply.notes.trim() &&
                                    !reply.processing
                                        ? 'bg-(--ds-primary-bg) text-(--ds-primary-text) hover:bg-(--ds-primary-bg-hover)'
                                        : 'bg-(--ds-hover) text-(--ds-text-faint)'
                                "
                                :disabled="
                                    !canRun ||
                                    !reply.notes.trim() ||
                                    reply.processing
                                "
                                :aria-label="$t('Send')"
                            >
                                <ArrowUp class="size-4" :stroke-width="2.25" />
                            </button>
                        </div>
                    </template>
                </div>
            </form>
        </template>
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
