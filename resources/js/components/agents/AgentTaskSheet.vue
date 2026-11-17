<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { GitPullRequest, Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import AgentStatus from './AgentStatus.vue';
import { ago, agentState, prNumber, taskStatus, toneDot } from './agentTasks';
import type { TaskRow, TaskStatus } from './agentTasks';

const props = defineProps<{ tasks: TaskRow[] }>();
const open = defineModel<boolean>('open', { required: true });

type Filter = 'all' | 'running' | 'review' | 'failed';
const filter = ref<Filter>('all');
const search = ref('');

const tabs = computed(() => {
    const count = (s: TaskStatus) =>
        props.tasks.filter((t) => t.status === s).length;

    return [
        { value: 'all' as Filter, label: 'All', count: props.tasks.length },
        {
            value: 'running' as Filter,
            label: 'Working',
            count: count('running'),
        },
        {
            value: 'review' as Filter,
            label: 'To review',
            count: count('review'),
        },
        { value: 'failed' as Filter, label: 'Failed', count: count('failed') },
    ].filter((tab) => tab.value === 'all' || tab.count > 0);
});

const shown = computed(() => {
    const q = search.value.trim().toLowerCase();

    return props.tasks.filter(
        (t) =>
            (filter.value === 'all' || t.status === filter.value) &&
            (!q ||
                `${t.title} ${t.repo ?? ''} ${t.machine.name}`
                    .toLowerCase()
                    .includes(q)),
    );
});

const prs = (task: TaskRow) =>
    task.agents
        .filter((a) => a.pr)
        .map((a) => prNumber(a.pr))
        .join(' ');
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="workspace-panel ds-modal inset-y-2 right-2 h-auto w-[calc(100%-1rem)] gap-0 rounded-[14px] p-0 font-ui text-(--ds-text) sm:max-w-md"
        >
            <div class="flex flex-col gap-3 px-4 pt-4 pb-3">
                <div class="flex flex-col gap-0.5 pr-8">
                    <SheetTitle class="text-[15px]/6 font-semibold">{{
                        $t('Tasks')
                    }}</SheetTitle>
                    <SheetDescription
                        class="text-[12.5px]/4 text-(--ds-text-muted)"
                    >
                        {{
                            $t(
                                'Everything you’ve handed to agents in this workspace.',
                            )
                        }}
                    </SheetDescription>
                </div>
                <label
                    class="ds-raised-button w-full! cursor-text focus-within:outline-2 focus-within:outline-offset-2 focus-within:outline-(--ds-ring)"
                >
                    <Search
                        class="size-3.5 shrink-0 text-(--ds-text-muted)"
                        :stroke-width="1.6"
                    />
                    <span class="sr-only">{{ $t('Search tasks') }}</span>
                    <input
                        v-model="search"
                        type="search"
                        :placeholder="$t('Search tasks')"
                        class="h-full min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                    />
                </label>
                <div
                    class="ds-segments max-w-full overflow-x-auto"
                    role="group"
                    :aria-label="$t('Filter by status')"
                >
                    <button
                        v-for="tab in tabs"
                        :key="tab.value"
                        type="button"
                        class="cursor-pointer gap-1.5"
                        :aria-pressed="filter === tab.value"
                        @click="filter = tab.value"
                    >
                        {{ $t(tab.label) }}
                        <span
                            class="text-xs/4 tabular-nums"
                            :class="
                                filter === tab.value
                                    ? 'text-(--ds-text-muted)'
                                    : 'text-(--ds-text-faint)'
                            "
                            >{{ tab.count }}</span
                        >
                    </button>
                </div>
            </div>

            <ul
                class="min-h-0 flex-1 overflow-y-auto border-t border-(--ds-divider) p-1.5"
                :aria-label="$t('Tasks')"
            >
                <li v-for="task in shown" :key="task.id">
                    <Link
                        :href="`/agents/${task.id}`"
                        class="flex flex-col gap-1 rounded-[10px] px-2.5 py-2.5 transition-colors hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-(--ds-ring)"
                        @click="open = false"
                    >
                        <span class="flex items-center gap-2 text-[13.5px]/5">
                            <span
                                class="truncate font-medium text-(--ds-text)"
                                >{{ task.title }}</span
                            >
                            <span
                                class="ml-auto shrink-0 text-[12px]/4 text-(--ds-text-faint) tabular-nums"
                                >{{ ago(task.updatedAt) }}</span
                            >
                        </span>
                        <span
                            class="flex min-w-0 items-center gap-2 text-[12px]/4 text-(--ds-text-muted)"
                        >
                            <AgentStatus
                                v-bind="taskStatus(task.status)"
                                :busy="task.status === 'running'"
                                class="shrink-0 text-(--ds-text-secondary)"
                            />
                            <span
                                v-if="task.repo"
                                class="truncate font-ui-mono text-[11.5px]"
                                >{{ task.repo }}</span
                            >
                            <span
                                v-if="prs(task)"
                                class="ml-auto inline-flex shrink-0 items-center gap-1 text-(--ds-green) tabular-nums"
                            >
                                <GitPullRequest
                                    class="size-3"
                                    :stroke-width="1.75"
                                />
                                {{ prs(task) }}
                            </span>
                            <span
                                v-else
                                class="ml-auto flex shrink-0 items-center gap-0.5"
                                aria-hidden="true"
                            >
                                <span
                                    v-for="agent in task.agents.slice(-6)"
                                    :key="agent.run"
                                    class="size-1.5 rounded-full"
                                    :class="toneDot[agentState(agent).tone]"
                                />
                            </span>
                        </span>
                    </Link>
                </li>
                <li
                    v-if="!shown.length"
                    class="px-2.5 py-6 text-center text-[13px]/5 text-(--ds-text-muted)"
                >
                    {{
                        tasks.length
                            ? $t('No tasks match.')
                            : $t('No tasks yet.')
                    }}
                </li>
            </ul>
        </SheetContent>
    </Sheet>
</template>
