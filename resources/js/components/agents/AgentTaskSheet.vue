<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import { computed, ref } from 'vue';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetTitle,
} from '@/components/ui/sheet';
import { since, taskPhrase, taskPhraseClass } from './agentTasks';
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
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent
            side="right"
            class="workspace-panel ds-modal inset-y-2 right-2 h-auto w-[calc(100%-1rem)] gap-0 rounded-[14px] p-0 font-ui text-(--ds-text) sm:max-w-md"
        >
            <div class="px-4 pt-4">
                <SheetTitle class="text-[15px]/6 font-semibold">{{
                    $t('Tasks')
                }}</SheetTitle>
                <SheetDescription class="sr-only">
                    {{
                        $t(
                            'Everything you’ve handed to agents in this workspace.',
                        )
                    }}
                </SheetDescription>
            </div>

            <label
                class="mx-4 mt-3 flex h-8 cursor-text items-center gap-2 border-b border-(--ds-divider) focus-within:border-(--ds-text-muted)"
            >
                <Search
                    class="size-3.5 shrink-0 text-(--ds-text-faint)"
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

            <nav
                class="flex gap-5 border-b border-(--ds-divider) px-4"
                :aria-label="$t('Filter by status')"
            >
                <button
                    v-for="tab in tabs"
                    :key="tab.value"
                    type="button"
                    class="-mb-px h-10 cursor-pointer border-b text-[13px]/4 transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                    :class="
                        filter === tab.value
                            ? 'border-(--ds-text) text-(--ds-text)'
                            : 'border-transparent text-(--ds-text-muted) hover:text-(--ds-text)'
                    "
                    :aria-pressed="filter === tab.value"
                    @click="filter = tab.value"
                >
                    {{ $t(tab.label) }}
                    <span class="ml-1 text-(--ds-text-faint) tabular-nums">{{
                        tab.count
                    }}</span>
                </button>
            </nav>

            <ul
                class="min-h-0 flex-1 divide-y divide-(--ds-divider) overflow-y-auto"
                :aria-label="$t('Tasks')"
            >
                <li v-for="task in shown" :key="task.id">
                    <Link
                        :href="`/agents/${task.id}`"
                        class="flex flex-col gap-0.5 px-4 py-2.5 transition-colors hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:outline-offset-[-2px] focus-visible:outline-(--ds-ring)"
                        @click="open = false"
                    >
                        <span class="flex items-baseline gap-3 text-[13px]/5">
                            <span
                                class="min-w-0 flex-1 truncate text-(--ds-text)"
                                >{{ task.title }}</span
                            >
                            <span
                                class="shrink-0 text-[12px] text-(--ds-text-faint) tabular-nums"
                                >{{ since(task.updatedAt) }}</span
                            >
                        </span>
                        <span
                            class="flex min-w-0 items-center gap-1.5 text-[12px]/4 text-(--ds-text-faint)"
                        >
                            <span
                                v-if="task.status === 'running'"
                                class="size-1.5 shrink-0 animate-pulse rounded-full bg-(--ds-blue) motion-reduce:animate-none"
                                aria-hidden="true"
                            />
                            <span
                                class="shrink-0"
                                :class="taskPhraseClass(task)"
                                >{{ taskPhrase(task) }}</span
                            >
                            <template v-if="task.repo">
                                <span aria-hidden="true">·</span>
                                <span class="truncate">{{ task.repo }}</span>
                            </template>
                        </span>
                    </Link>
                </li>
                <li
                    v-if="!shown.length"
                    class="px-4 py-6 text-center text-[13px]/5 text-(--ds-text-muted)"
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
