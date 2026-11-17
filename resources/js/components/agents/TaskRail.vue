<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { Link } from '@inertiajs/vue3';
import { PanelLeftClose, Search, SquarePen } from '@lucide/vue';
import { computed, ref } from 'vue';
import { taskStatus, toneDot } from './agentTasks';
import type { TaskRow } from './agentTasks';

const props = defineProps<{ tasks: TaskRow[]; current: number }>();
const emit = defineEmits<{ close: [] }>();

const search = ref('');

// Today, yesterday and the rest, like a chat app's history.
const groups = computed(() => {
    const q = search.value.trim().toLowerCase();
    const shown = props.tasks.filter(
        (t) => !q || `${t.title} ${t.repo ?? ''}`.toLowerCase().includes(q),
    );
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const yesterday = new Date(today.getTime() - 86_400_000);
    const week = new Date(today.getTime() - 6 * 86_400_000);
    const out: { label: string; tasks: TaskRow[] }[] = [
        { label: trans('Today'), tasks: [] },
        { label: trans('Yesterday'), tasks: [] },
        { label: trans('This week'), tasks: [] },
        { label: trans('Earlier'), tasks: [] },
    ];
    for (const task of shown) {
        const at = new Date(task.updatedAt ?? 0);
        const i = at >= today ? 0 : at >= yesterday ? 1 : at >= week ? 2 : 3;
        out[i].tasks.push(task);
    }

    return out.filter((g) => g.tasks.length);
});

const row =
    'flex h-8 w-full items-center gap-2.5 rounded-[7px] px-2 text-[13px]/4 transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <nav
        class="flex h-full w-64 shrink-0 flex-col border-r border-(--ds-divider)"
        :aria-label="$t('Tasks')"
    >
        <div class="flex h-14 shrink-0 items-center gap-1 px-2.5">
            <Link
                href="/agents"
                :class="[
                    row,
                    'flex-1 font-medium text-(--ds-text) hover:bg-(--ds-hover)',
                ]"
            >
                <SquarePen
                    class="size-4 text-(--ds-text-muted)"
                    :stroke-width="1.75"
                />
                {{ $t('New task') }}
            </Link>
            <button
                type="button"
                class="flex size-8 cursor-pointer items-center justify-center rounded-[7px] text-(--ds-text-faint) hover:bg-(--ds-hover) hover:text-(--ds-text)"
                :aria-label="$t('Hide tasks')"
                :title="$t('Hide tasks')"
                @click="emit('close')"
            >
                <PanelLeftClose class="size-4" :stroke-width="1.75" />
            </button>
        </div>
        <label
            class="mx-2.5 mb-2 flex h-8 shrink-0 items-center gap-2 rounded-[8px] bg-(--ds-subtle) px-2.5"
        >
            <Search
                class="size-3.5 text-(--ds-text-faint)"
                :stroke-width="1.75"
            />
            <span class="sr-only">{{ $t('Search tasks') }}</span>
            <input
                v-model="search"
                type="search"
                class="min-w-0 grow bg-transparent text-[13px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) [&::-webkit-search-cancel-button]:hidden"
                :placeholder="$t('Search tasks')"
            />
        </label>
        <div class="min-h-0 flex-1 overflow-y-auto px-2.5 pb-3">
            <section v-for="group in groups" :key="group.label" class="mt-2">
                <h3
                    class="px-2 pb-1 text-[11.5px]/4 font-medium text-(--ds-text-faint)"
                >
                    {{ group.label }}
                </h3>
                <Link
                    v-for="task in group.tasks"
                    :key="task.id"
                    :href="`/agents/${task.id}`"
                    :class="[
                        row,
                        task.id === current
                            ? 'bg-(--ds-hover) text-(--ds-text)'
                            : 'text-(--ds-text-secondary) hover:bg-(--ds-hover) hover:text-(--ds-text)',
                    ]"
                    :aria-current="task.id === current ? 'page' : undefined"
                    preserve-scroll
                >
                    <span
                        class="size-1.5 shrink-0 rounded-full"
                        :class="[
                            toneDot[taskStatus(task.status).tone],
                            task.status === 'running'
                                ? 'animate-pulse motion-reduce:animate-none'
                                : '',
                        ]"
                        :title="taskStatus(task.status).label"
                    />
                    <span class="truncate">{{ task.title }}</span>
                </Link>
            </section>
            <p
                v-if="!groups.length"
                class="px-2 py-4 text-[12.5px]/5 text-(--ds-text-muted)"
            >
                {{ $t('No tasks match.') }}
            </p>
        </div>
    </nav>
</template>
