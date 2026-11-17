<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ArrowRight, Check, GitBranch, Search } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';

const props = defineProps<{ repo: string; branch: string }>();
const emit = defineEmits<{ pick: [branch: string] }>();

const page = usePage<{ githubApp: boolean }>();

const open = ref(false);
const search = ref('');
const input = useTemplateRef<HTMLInputElement>('input');
const branches = ref<{ repo: string; names: string[] } | null>(null);
const loading = ref(false);

async function load(): Promise<void> {
    const [owner, name] = props.repo.split('/');
    if (!page.props.githubApp || !owner || !name) return;
    if (branches.value?.repo === props.repo) return;
    loading.value = true;
    const repo = props.repo;
    try {
        const response = await fetch(
            `/github/repositories/${owner}/${name}/branches`,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            },
        );
        const names = response.ok ? (await response.json()).branches : null;
        // An answer for a repository that's no longer chosen is dropped.
        if (Array.isArray(names) && repo === props.repo) {
            branches.value = { repo, names };
        }
    } catch {
    } finally {
        loading.value = false;
    }
}

watch(open, async (isOpen) => {
    if (!isOpen) return;
    search.value = '';
    void load();
    await nextTick();
    input.value?.focus();
});

const names = computed(() => {
    const list =
        branches.value?.repo === props.repo ? branches.value.names : [];
    const q = search.value.trim().toLowerCase();

    return (q ? list.filter((n) => n.toLowerCase().includes(q)) : list).slice(
        0,
        60,
    );
});

const typed = computed(() => {
    const value = search.value.trim();

    return /^[\w./-]+$/.test(value) && !names.value.includes(value)
        ? value
        : null;
});

function choose(name: string): void {
    emit('pick', name);
    open.value = false;
}

const row =
    'flex h-9 w-full cursor-pointer items-center gap-2.5 rounded-[8px] px-2.5 text-left text-[13px]/4 transition-colors hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <PopoverRoot v-model:open="open">
        <PopoverTrigger as-child>
            <slot name="trigger" />
        </PopoverTrigger>
        <PopoverPortal>
            <PopoverContent
                side="top"
                align="start"
                :side-offset="8"
                :collision-padding="12"
                class="workspace-panel ds-modal z-50 flex max-h-[min(24rem,var(--reka-popover-content-available-height))] w-[min(18rem,calc(100vw-1.5rem))] flex-col overflow-hidden font-ui text-(--ds-text) data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95"
            >
                <label
                    class="m-2 flex h-9 shrink-0 items-center gap-2 rounded-[9px] border border-(--ds-border-strong) bg-(--ds-field) px-2.5"
                >
                    <Search
                        class="size-3.5 shrink-0 text-(--ds-text-muted)"
                        :stroke-width="1.6"
                    />
                    <span class="sr-only">{{ $t('Branch') }}</span>
                    <input
                        ref="input"
                        v-model="search"
                        class="min-w-0 grow bg-transparent font-ui-mono text-[12.5px]/4 outline-none placeholder:font-ui placeholder:text-(--ds-text-faint)"
                        :placeholder="$t('Find or type a branch')"
                        spellcheck="false"
                        autocomplete="off"
                        @keydown.enter.prevent="
                            typed ? choose(typed) : names[0] && choose(names[0])
                        "
                    />
                </label>
                <div
                    class="min-h-0 flex-1 overflow-y-auto border-t border-(--ds-divider) p-1.5"
                >
                    <button
                        v-if="typed"
                        type="button"
                        :class="row"
                        @click="choose(typed)"
                    >
                        <ArrowRight
                            class="size-3.5 shrink-0 text-(--ds-text-muted)"
                            :stroke-width="2"
                        />
                        <span class="text-(--ds-text-muted)">{{
                            $t('Use')
                        }}</span>
                        <span class="truncate font-ui-mono text-[12.5px]">{{
                            typed
                        }}</span>
                    </button>
                    <template v-if="loading">
                        <div
                            v-for="i in 3"
                            :key="i"
                            class="mx-2.5 my-2.5 h-4 w-1/2 animate-pulse rounded bg-(--ds-subtle)"
                        />
                    </template>
                    <template v-else>
                        <button
                            v-for="name in names"
                            :key="name"
                            type="button"
                            :class="row"
                            @click="choose(name)"
                        >
                            <GitBranch
                                class="size-3.5 shrink-0 text-(--ds-text-muted)"
                                :stroke-width="1.75"
                            />
                            <span class="truncate font-ui-mono text-[12.5px]">{{
                                name
                            }}</span>
                            <Check
                                v-if="name === branch"
                                class="ml-auto size-3.5 shrink-0"
                            />
                        </button>
                    </template>
                    <p
                        v-if="!loading && !names.length && !typed"
                        class="px-2.5 py-3 text-[12.5px]/5 text-(--ds-text-muted)"
                    >
                        {{ $t('Type the branch to start from.') }}
                    </p>
                </div>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
