<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { ArrowRight, Check, Lock, Search, X } from '@lucide/vue';
import {
    PopoverContent,
    PopoverPortal,
    PopoverRoot,
    PopoverTrigger,
} from 'reka-ui';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import GitHubMark from './GitHubMark.vue';

export type Repository = {
    name: string;
    url: string;
    private: boolean;
    branch: string;
};

const props = defineProps<{ repo: string }>();
const emit = defineEmits<{
    pick: [repo: string, defaultBranch: string | null];
}>();

const page = usePage<{ githubApp: boolean }>();

const open = ref(false);
const search = ref('');
const input = useTemplateRef<HTMLInputElement>('input');

const github = ref<{ connected: boolean; repositories: Repository[] } | null>(
    null,
);
const loading = ref(false);

async function load(): Promise<void> {
    if (github.value || loading.value || !page.props.githubApp) return;
    loading.value = true;
    try {
        const response = await fetch('/github/repositories', {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
        });
        if (response.ok) github.value = await response.json();
    } catch {
    } finally {
        loading.value = false;
    }
}
void load();

watch(open, async (isOpen) => {
    if (!isOpen) return;
    search.value = '';
    void load();
    await nextTick();
    input.value?.focus();
});

/** owner/name from what was typed or pasted, or null. */
function parse(value: string): string | null {
    const text = value.trim();
    const url = text.match(
        /github\.com[/:]([\w.-]+)\/([\w.-]+?)(?:\.git)?(?:[/#?].*)?$/i,
    );
    if (url) return `${url[1]}/${url[2]}`;
    const plain = text.match(/^([\w.-]+)\/([\w.-]+)$/);

    return plain ? `${plain[1]}/${plain[2]}` : null;
}

const matches = computed(() => {
    const q = search.value.trim().toLowerCase();
    const list = github.value?.repositories ?? [];

    return (
        q ? list.filter((r) => r.name.toLowerCase().includes(q)) : list
    ).slice(0, 50);
});

const typed = computed(() => {
    const name = parse(search.value);

    return name &&
        !matches.value.some((r) => r.name.toLowerCase() === name.toLowerCase())
        ? name
        : null;
});

function choose(name: string, branch: string | null): void {
    emit('pick', name, branch);
    open.value = false;
}

function onEnter(): void {
    if (typed.value) choose(typed.value, null);
    else if (matches.value[0])
        choose(matches.value[0].name, matches.value[0].branch || null);
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
                class="workspace-panel ds-modal z-50 flex max-h-[min(30rem,var(--reka-popover-content-available-height))] w-[min(25rem,calc(100vw-1.5rem))] flex-col overflow-hidden font-ui text-(--ds-text) data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-95"
            >
                <label
                    class="m-2 flex h-9 shrink-0 items-center gap-2 rounded-[9px] border border-(--ds-border-strong) bg-(--ds-field) px-2.5"
                >
                    <Search
                        class="size-3.5 shrink-0 text-(--ds-text-muted)"
                        :stroke-width="1.6"
                    />
                    <span class="sr-only">{{ $t('Repository') }}</span>
                    <input
                        ref="input"
                        v-model="search"
                        class="min-w-0 grow bg-transparent text-[13px]/4 outline-none placeholder:text-(--ds-text-faint)"
                        :placeholder="
                            github?.connected
                                ? $t(
                                      'Search your repositories, or paste a link',
                                  )
                                : $t('owner/repo, or a GitHub link')
                        "
                        spellcheck="false"
                        autocomplete="off"
                        @keydown.enter.prevent="onEnter"
                    />
                </label>

                <div
                    class="min-h-0 flex-1 overflow-y-auto border-t border-(--ds-divider) p-1.5"
                >
                    <button
                        v-if="typed"
                        type="button"
                        :class="row"
                        @click="choose(typed, null)"
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
                            v-for="i in 4"
                            :key="i"
                            class="mx-2.5 my-2.5 h-4 animate-pulse rounded bg-(--ds-subtle)"
                            :style="{ width: `${50 + ((i * 17) % 40)}%` }"
                        />
                    </template>

                    <template v-else-if="github?.connected">
                        <p
                            class="px-2.5 pt-1.5 pb-1 text-[11.5px]/4 text-(--ds-text-faint)"
                        >
                            {{ $t('Your repositories') }}
                        </p>
                        <button
                            v-for="r in matches"
                            :key="r.name"
                            type="button"
                            :class="row"
                            @click="choose(r.name, r.branch || null)"
                        >
                            <GitHubMark
                                class="size-3.5 shrink-0 text-(--ds-text-muted)"
                            />
                            <span class="truncate font-ui-mono text-[12.5px]">{{
                                r.name
                            }}</span>
                            <Lock
                                v-if="r.private"
                                class="size-3 shrink-0 text-(--ds-text-faint)"
                                :stroke-width="2"
                                :aria-label="$t('Private')"
                            />
                            <span
                                class="ml-auto shrink-0 font-ui-mono text-[11px] text-(--ds-text-faint)"
                                >{{ r.branch }}</span
                            >
                            <Check
                                v-if="
                                    repo.toLowerCase() === r.name.toLowerCase()
                                "
                                class="size-3.5 shrink-0"
                            />
                        </button>
                        <p
                            v-if="!matches.length && !typed"
                            class="px-2.5 py-4 text-center text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{
                                github.repositories.length
                                    ? $t('No repository matches.')
                                    : $t(
                                          'GitHub hasn’t shared any repositories with ServerOS yet.',
                                      )
                            }}
                        </p>
                    </template>

                    <a
                        v-else-if="page.props.githubApp"
                        href="/settings/github/connect"
                        class="m-1 flex items-center gap-3 rounded-[10px] border border-(--ds-border) p-3 text-left transition-colors hover:bg-(--ds-hover)"
                    >
                        <GitHubMark class="size-5 shrink-0" />
                        <span class="flex flex-col">
                            <span class="text-[13px]/5 font-medium">{{
                                $t('Connect GitHub')
                            }}</span>
                            <span
                                class="text-[12px]/4 text-(--ds-text-muted)"
                                >{{
                                    $t(
                                        'Pick from your repositories, private ones too.',
                                    )
                                }}</span
                            >
                        </span>
                        <ArrowRight
                            class="ml-auto size-3.5 text-(--ds-text-muted)"
                            :stroke-width="2"
                        />
                    </a>

                    <p
                        v-else-if="!typed"
                        class="px-2.5 py-3 text-[12.5px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            $t(
                                'Type owner/repo or paste a GitHub link. Public repositories work as they are; private ones need a GH_TOKEN on the agent’s container.',
                            )
                        }}
                    </p>
                </div>

                <button
                    v-if="repo"
                    type="button"
                    class="flex h-9 shrink-0 cursor-pointer items-center gap-2 border-t border-(--ds-divider) px-4 text-[12.5px]/4 text-(--ds-text-muted) hover:bg-(--ds-hover) hover:text-(--ds-text)"
                    @click="choose('', null)"
                >
                    <X class="size-3.5" :stroke-width="2" />
                    {{ $t('No repository: let it work without code') }}
                </button>
            </PopoverContent>
        </PopoverPortal>
    </PopoverRoot>
</template>
