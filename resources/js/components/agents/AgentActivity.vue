<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import {
    Check,
    ChevronRight,
    Circle,
    FilePen,
    FilePlus,
    FileText,
    Globe,
    LoaderCircle,
    Search,
    SquareTerminal,
    Wrench,
} from '@lucide/vue';
import type { Component } from 'vue';
import { computed, ref, watch } from 'vue';
import { duration } from './agentTasks';
import type { AgentDetail, Step } from './agentTasks';

const props = defineProps<{
    agent: AgentDetail;
    now: number;
    /** Stays open once the agent's done, where it's the whole view. */
    expanded?: boolean;
}>();

const running = computed(() => props.agent.state === 'running');

// Open while it works, so you watch it think; folded once it's done.
const open = ref(props.expanded || running.value);
watch(running, (isRunning) => {
    if (!isRunning && !props.expanded) open.value = false;
});

const seconds = computed(() =>
    running.value
        ? Math.max(
              props.agent.seconds,
              Math.floor(
                  (props.now - Date.parse(props.agent.startedAt)) / 1000,
              ),
          )
        : props.agent.seconds,
);

const kinds: Record<string, { icon: Component; verb: string; many: string }> = {
    read: { icon: FileText, verb: 'Read', many: 'Read :n files' },
    find: { icon: Search, verb: 'Found', many: 'Searched for files :n times' },
    search: { icon: Search, verb: 'Searched for', many: 'Searched :n times' },
    edit: { icon: FilePen, verb: 'Edited', many: 'Edited :n files' },
    write: { icon: FilePlus, verb: 'Wrote', many: 'Wrote :n files' },
    run: { icon: SquareTerminal, verb: 'Ran', many: 'Ran :n commands' },
    web: { icon: Globe, verb: 'Looked up', many: 'Looked up :n pages' },
    tool: { icon: Wrench, verb: 'Used', many: 'Used :n tools' },
};

// Reads and searches in a row fold into one line, like "Read 5 files".
type Row =
    | { type: 'note'; text: string; key: number }
    | { type: 'group'; kind: string; steps: Step[]; key: number };

const rows = computed<Row[]>(() => {
    const out: Row[] = [];
    props.agent.steps.forEach((step, i) => {
        if (step.k === 'note') {
            out.push({ type: 'note', text: step.t, key: i });

            return;
        }
        const last = out[out.length - 1];
        const foldable = ['read', 'find', 'search', 'web'].includes(step.k);
        if (foldable && last?.type === 'group' && last.kind === step.k) {
            last.steps.push(step);
        } else {
            out.push({ type: 'group', kind: step.k, steps: [step], key: i });
        }
    });

    return out;
});

const groupsOpen = ref<Record<number, boolean>>({});

const counts = computed(() => {
    const n = (k: string[]) =>
        props.agent.steps.filter((s) => k.includes(s.k)).length;
    const files = new Set(
        props.agent.steps
            .filter((s) => ['edit', 'write'].includes(s.k))
            .map((s) => s.t),
    ).size;

    return [
        n(['read']) && trans(':n read', { n: String(n(['read'])) }),
        files &&
            (files === 1
                ? trans('1 file changed')
                : trans(':n files changed', { n: String(files) })),
        n(['run']) &&
            (n(['run']) === 1
                ? trans('1 command')
                : trans(':n commands', { n: String(n(['run'])) })),
    ].filter(Boolean) as string[];
});

const headline = computed(() => {
    const time = duration(seconds.value);
    if (running.value) {
        return props.agent.phase === 'pull_request'
            ? trans('Opening the pull request…')
            : trans('Working for :time', { time });
    }
    if (props.agent.state === 'failed')
        return trans('Failed after :time', { time });
    if (props.agent.state === 'cancelled')
        return trans('Stopped after :time', { time });

    return trans('Worked for :time', { time });
});

const lastKey = computed(() => rows.value[rows.value.length - 1]?.key);

const planDone = computed(
    () => props.agent.plan?.filter((t) => t.s === 'completed').length ?? 0,
);

function label(row: Extract<Row, { type: 'group' }>): string {
    const kind = kinds[row.kind] ?? kinds.tool;
    if (row.steps.length > 1)
        return trans(kind.many, { n: String(row.steps.length) });

    return trans(kind.verb);
}
</script>

<template>
    <div class="flex flex-col gap-2.5">
        <button
            type="button"
            class="group inline-flex w-fit cursor-pointer items-center gap-1.5 rounded-[6px] text-[13.5px]/5 text-(--ds-text-muted) hover:text-(--ds-text)"
            :aria-expanded="open"
            @click="open = !open"
        >
            <span :class="{ 'agent-shimmer': running }">{{ headline }}</span>
            <span
                v-if="counts.length && !running"
                class="text-(--ds-text-faint) max-sm:hidden"
                >· {{ counts.join(' · ') }}</span
            >
            <ChevronRight
                v-if="agent.steps.length"
                class="size-3.5 text-(--ds-text-faint) transition-transform duration-200 ease-out"
                :class="{ 'rotate-90': open }"
                :stroke-width="2"
            />
        </button>

        <!-- The agent's own plan, as it keeps it. -->
        <div
            v-if="agent.plan?.length && (open || running)"
            class="flex flex-col"
        >
            <p
                class="mb-2 flex items-center justify-between text-[12px]/4 font-medium text-(--ds-text-muted)"
            >
                {{ $t('Plan') }}
                <span class="font-normal text-(--ds-text-faint) tabular-nums">{{
                    $t(':done of :all done', {
                        done: String(planDone),
                        all: String(agent.plan.length),
                    })
                }}</span>
            </p>
            <ul class="flex flex-col gap-1.5">
                <li
                    v-for="(todo, i) in agent.plan"
                    :key="i"
                    class="flex items-start gap-2 text-[13px]/5"
                    :class="
                        todo.s === 'completed'
                            ? 'text-(--ds-text-muted)'
                            : 'text-(--ds-text)'
                    "
                >
                    <span
                        class="mt-0.5 flex size-4 shrink-0 items-center justify-center"
                    >
                        <Check
                            v-if="todo.s === 'completed'"
                            class="size-3.5 text-(--ds-green)"
                            :stroke-width="2.5"
                        />
                        <LoaderCircle
                            v-else-if="todo.s === 'in_progress' && running"
                            class="size-3.5 animate-spin text-(--ds-blue) motion-reduce:animate-none"
                            :stroke-width="2.25"
                        />
                        <Circle
                            v-else
                            class="size-3 text-(--ds-text-faint)"
                            :stroke-width="2"
                        />
                    </span>
                    <span
                        :class="{
                            'line-through decoration-(--ds-text-faint)':
                                todo.s === 'completed',
                        }"
                        >{{ todo.t }}</span
                    >
                </li>
            </ul>
        </div>

        <ol
            v-if="open && rows.length"
            class="relative ml-[7px] flex flex-col gap-0.5 border-l border-(--ds-divider) pl-4"
        >
            <li v-for="row in rows" :key="row.key" class="agent-step">
                <p
                    v-if="row.type === 'note'"
                    class="py-1 text-[13px]/5 text-(--ds-text-secondary)"
                    :class="{ 'agent-shimmer': running && row.key === lastKey }"
                >
                    {{ row.text }}
                </p>
                <template v-else>
                    <button
                        type="button"
                        class="flex w-full min-w-0 items-center gap-2 rounded-[6px] py-1 text-left text-[13px]/5"
                        :class="
                            row.steps.length > 1
                                ? 'cursor-pointer hover:text-(--ds-text)'
                                : 'cursor-default'
                        "
                        :aria-expanded="
                            row.steps.length > 1
                                ? !!groupsOpen[row.key]
                                : undefined
                        "
                        @click="
                            row.steps.length > 1 &&
                            (groupsOpen[row.key] = !groupsOpen[row.key])
                        "
                    >
                        <component
                            :is="(kinds[row.kind] ?? kinds.tool).icon"
                            class="size-3.5 shrink-0 text-(--ds-text-faint)"
                            :stroke-width="1.75"
                        />
                        <span
                            class="shrink-0 text-(--ds-text-muted)"
                            :class="{
                                'agent-shimmer': running && row.key === lastKey,
                            }"
                            >{{ label(row) }}</span
                        >
                        <template v-if="row.steps.length === 1">
                            <code
                                class="min-w-0 truncate rounded-[5px] font-ui-mono text-[12px] text-(--ds-text)"
                                :title="row.steps[0].t"
                                >{{ row.steps[0].t }}</code
                            >
                            <span
                                v-if="row.steps[0].d"
                                class="truncate text-[12px] text-(--ds-text-faint) max-md:hidden"
                                >{{ row.steps[0].d }}</span
                            >
                        </template>
                        <ChevronRight
                            v-else
                            class="size-3 shrink-0 text-(--ds-text-faint) transition-transform duration-200 ease-out"
                            :class="{ 'rotate-90': groupsOpen[row.key] }"
                            :stroke-width="2"
                        />
                    </button>
                    <ul
                        v-if="row.steps.length > 1 && groupsOpen[row.key]"
                        class="mb-1 ml-5.5 flex flex-col gap-0.5"
                    >
                        <li
                            v-for="(step, i) in row.steps"
                            :key="i"
                            class="truncate font-ui-mono text-[12px]/5 text-(--ds-text-secondary)"
                            :title="step.t"
                        >
                            {{ step.t }}
                        </li>
                    </ul>
                </template>
            </li>
        </ol>
    </div>
</template>

<style scoped>
.agent-shimmer {
    background: linear-gradient(
            90deg,
            var(--ds-text-faint) 30%,
            var(--ds-text) 50%,
            var(--ds-text-faint) 70%
        )
        0 0 / 300% 100%;
    -webkit-background-clip: text;
    background-clip: text;
    color: transparent;
    animation: activity-shimmer 2.2s linear infinite;
}
@keyframes activity-shimmer {
    from {
        background-position: 100% 0;
    }
    to {
        background-position: -200% 0;
    }
}
/* New steps settle in as they arrive. */
.agent-step {
    transition:
        opacity 200ms cubic-bezier(0.23, 1, 0.32, 1),
        transform 200ms cubic-bezier(0.23, 1, 0.32, 1);
    @starting-style {
        opacity: 0;
        transform: translateY(4px);
    }
}
@media (prefers-reduced-motion: reduce) {
    .agent-shimmer {
        animation: none;
        color: var(--ds-text-muted);
        background: none;
    }
    .agent-step {
        transition: opacity 150ms ease;
        @starting-style {
            opacity: 0;
            transform: none;
        }
    }
}
</style>
