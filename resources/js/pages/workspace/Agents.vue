<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import {
    BookOpen,
    Bug,
    FlaskConical,
    GitPullRequest,
    ListTodo,
    PackageCheck,
    ShieldCheck,
} from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import AgentComposer from '@/components/agents/AgentComposer.vue';
import type { Box } from '@/components/agents/AgentComposer.vue';
import AgentStatus from '@/components/agents/AgentStatus.vue';
import AgentTaskSheet from '@/components/agents/AgentTaskSheet.vue';
import { ago, prNumber, taskStatus } from '@/components/agents/agentTasks';
import type { StartContext, TaskRow } from '@/components/agents/agentTasks';
import PlanLock from '@/components/workspace/PlanLock.vue';
import type { Workspace, WorkspaceAccess } from '@/types';

const props = defineProps<{
    workspace: Workspace;
    tasks: TaskRow[];
    boxes: Box[];
    planAllows: boolean;
    twoFactor: boolean;
    context: StartContext | null;
}>();

const page = usePage<{ workspaceAccess: WorkspaceAccess | null }>();
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const sheet = ref(false);

const composer = useTemplateRef<InstanceType<typeof AgentComposer>>('composer');

// Ways in for a first task; each fills the prompt to finish in your words.
const starters = [
    { label: trans('Fix a bug'), icon: Bug, prompt: trans('Fix this bug: ') },
    {
        label: trans('Add tests'),
        icon: FlaskConical,
        prompt: trans('Add tests for '),
    },
    {
        label: trans('Find security problems'),
        icon: ShieldCheck,
        prompt: trans(
            'Review this repository for security problems. Change nothing. List the most important first, each with the file and line and why it matters.',
        ),
    },
    {
        label: trans('Update dependencies'),
        icon: PackageCheck,
        prompt: trans(
            'Update dependencies with patch and minor releases only, fix anything that breaks, and keep the change small.',
        ),
    },
    {
        label: trans('Explain the code'),
        icon: BookOpen,
        prompt: trans('Explain how this works, and change nothing: '),
    },
];

const working = computed(
    () => props.tasks.filter((t) => t.status === 'running').length,
);
const toReview = computed(
    () => props.tasks.filter((t) => t.status === 'review').length,
);

// The few worth a look: what's still working or waiting on a review first.
const recent = computed(() => {
    const rank = (t: TaskRow) =>
        t.status === 'running' ? 0 : t.status === 'review' ? 1 : 2;

    return [...props.tasks]
        .sort(
            (a, b) =>
                rank(a) - rank(b) ||
                (b.updatedAt ?? '').localeCompare(a.updatedAt ?? ''),
        )
        .slice(0, 4);
});

const prs = (task: TaskRow) =>
    task.agents
        .filter((a) => a.pr)
        .map((a) => prNumber(a.pr))
        .join(' ');

const busy = computed(
    () => working.value > 0 || props.tasks.some((t) => t.status === 'queued'),
);

let poll: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    if (new URLSearchParams(window.location.search).has('tasks')) {
        sheet.value = true;
    }
    poll = setInterval(() => {
        if (busy.value && document.visibilityState === 'visible') {
            router.reload({ only: ['tasks'] });
        }
    }, 3000);
});
onBeforeUnmount(() => clearInterval(poll));

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <Head :title="$t('Agents')" />

    <div
        class="workspace-panel flex min-h-full flex-col font-ui text-(--ds-text)"
    >
        <header class="flex items-center justify-end gap-2 px-4 pt-4 sm:px-6">
            <button
                v-if="tasks.length"
                type="button"
                class="ds-raised-button cursor-pointer gap-2 text-[13px]!"
                :class="focusRing"
                @click="sheet = true"
            >
                <ListTodo
                    class="size-3.5 text-(--ds-text-muted)"
                    :stroke-width="1.75"
                />
                <span class="font-medium">{{ $t('Tasks') }}</span>
                <span
                    v-if="working"
                    class="inline-flex items-center gap-1 text-(--ds-text-muted) tabular-nums"
                >
                    <span
                        class="size-1.5 animate-pulse rounded-full bg-(--ds-blue) motion-reduce:animate-none"
                    />
                    {{ working }}
                </span>
                <span
                    v-if="toReview"
                    class="inline-flex items-center gap-1 text-(--ds-text-muted) tabular-nums"
                >
                    <span class="size-1.5 rounded-full bg-(--ds-green)" />
                    {{ toReview }}
                </span>
                <span
                    v-if="!working && !toReview"
                    class="text-(--ds-text-muted) tabular-nums"
                    >{{ tasks.length }}</span
                >
            </button>
        </header>

        <main
            class="flex flex-1 flex-col items-center justify-center px-4 pt-8 pb-[14vh] sm:px-6"
        >
            <h1
                class="text-center text-[26px]/8 font-semibold tracking-[-0.02em] text-balance"
            >
                {{ $t('What should an agent work on?') }}
            </h1>
            <p
                class="mt-2 max-w-xl text-center text-[14px]/5 text-balance text-(--ds-text-muted)"
            >
                {{
                    $t(
                        'Claude Code, Codex or OpenCode, on your own machine. You get a pull request back.',
                    )
                }}
            </p>

            <PlanLock
                :locked="!planAllows"
                feature="Agents"
                plan="pro"
                :description="
                    $t(
                        'Run coding agents on the machines you already pay for, side by side, with a pull request at the end.',
                    )
                "
            >
                <AgentComposer
                    ref="composer"
                    class="mt-8 w-full max-w-3xl"
                    :boxes="boxes"
                    :two-factor="twoFactor"
                    :plan-allows="planAllows"
                    :can-manage="canManage"
                    :context="context"
                />
            </PlanLock>

            <div
                v-if="!context && canManage"
                class="mt-4 flex w-full max-w-3xl flex-wrap justify-center gap-1.5"
            >
                <button
                    v-for="s in starters"
                    :key="s.label"
                    type="button"
                    class="inline-flex h-8 cursor-pointer items-center gap-1.5 rounded-full border border-(--ds-border) px-3.5 text-[13px]/4 text-(--ds-text-secondary) transition-[background-color,color,scale] duration-150 hover:bg-(--ds-hover) hover:text-(--ds-text) active:scale-[0.97]"
                    :class="focusRing"
                    @click="composer?.suggest(s.prompt)"
                >
                    <component
                        :is="s.icon"
                        class="size-3.5 text-(--ds-text-faint)"
                        :stroke-width="1.75"
                    />
                    {{ s.label }}
                </button>
            </div>

            <section
                v-if="recent.length"
                class="mt-10 w-full max-w-3xl"
                :aria-label="$t('Recent tasks')"
            >
                <div
                    class="flex items-center justify-between px-3 pb-1.5 text-[12px]/4 text-(--ds-text-faint)"
                >
                    <span>{{ $t('Recent') }}</span>
                    <button
                        v-if="tasks.length > recent.length"
                        type="button"
                        class="cursor-pointer rounded-[5px] hover:text-(--ds-text)"
                        :class="focusRing"
                        @click="sheet = true"
                    >
                        {{
                            $t('All :count tasks', {
                                count: String(tasks.length),
                            })
                        }}
                    </button>
                </div>
                <ul class="flex flex-col">
                    <li v-for="task in recent" :key="task.id">
                        <Link
                            :href="`/agents/${task.id}`"
                            class="flex h-11 items-center gap-3 rounded-[10px] px-3 text-[13.5px]/5 transition-colors hover:bg-(--ds-hover)"
                            :class="focusRing"
                        >
                            <AgentStatus
                                v-bind="taskStatus(task.status)"
                                :busy="task.status === 'running'"
                                class="w-34 shrink-0 text-[12.5px] text-(--ds-text-muted) max-sm:w-auto [&>span:last-child]:max-sm:hidden"
                            />
                            <span class="min-w-0 truncate text-(--ds-text)">{{
                                task.title
                            }}</span>
                            <span
                                v-if="task.repo"
                                class="shrink-0 truncate font-ui-mono text-[11.5px] text-(--ds-text-faint) max-md:hidden"
                                >{{ task.repo }}</span
                            >
                            <span
                                v-if="prs(task)"
                                class="ml-auto inline-flex shrink-0 items-center gap-1 text-[12px]/4 text-(--ds-green) tabular-nums"
                            >
                                <GitPullRequest
                                    class="size-3"
                                    :stroke-width="1.75"
                                />
                                {{ prs(task) }}
                            </span>
                            <span
                                class="shrink-0 text-[12px]/4 text-(--ds-text-faint) tabular-nums"
                                :class="{ 'ml-auto': !prs(task) }"
                                >{{ ago(task.updatedAt) }}</span
                            >
                        </Link>
                    </li>
                </ul>
            </section>
        </main>

        <AgentTaskSheet v-model:open="sheet" :tasks="tasks" />
    </div>
</template>
