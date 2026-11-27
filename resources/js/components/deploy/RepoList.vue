<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { languageIcon, looksLikeRepository, shortAgo } from './catalog';
import PickerButton from './PickerButton.vue';
import TemplateLogo from './TemplateLogo.vue';

export type Repository = {
    name: string;
    url: string;
    private: boolean;
    branch: string;
    pushed?: string;
    language?: string | null;
};

export type GitHubAccount = {
    login: string;
    type: string | null;
    connectedBy: string | null;
};

export type GitHubRepositories = {
    connected: boolean;
    accounts?: GitHubAccount[];
    repositories: Repository[];
};

const {
    github,
    available,
    connectUrl,
    busy = null,
    disabled = false,
    searchless = false,
} = defineProps<{
    github: GitHubRepositories | null;
    available: boolean;
    connectUrl: string;
    busy?: string | null;
    disabled?: boolean;
    /** The query comes from a field outside the list, so it has none of its own. */
    searchless?: boolean;
}>();

const emit = defineEmits<{
    import: [url: string];
}>();

const query = defineModel<string>('query', { default: '' });

const accounts = computed(() => github?.accounts ?? []);
const account = ref<string | null>(null);

watch(
    accounts,
    (list) => {
        if (account.value && !list.some((a) => a.login === account.value)) {
            account.value = null;
        }
    },
    { immediate: true },
);

const accountLabel = computed(
    () =>
        account.value ??
        (accounts.value.length === 1 ? accounts.value[0].login : null),
);

const connectedBy = computed(() => {
    const chosen = accounts.value.find(
        (a) => a.login === (account.value ?? accounts.value[0]?.login),
    );

    return chosen?.connectedBy ?? null;
});

const pasted = computed(() => looksLikeRepository(query.value));

const matches = computed(() => {
    const term = query.value.trim().toLowerCase();
    if (!github || pasted.value) return [];

    return github.repositories
        .filter(
            (repository) =>
                !account.value ||
                repository.name
                    .toLowerCase()
                    .startsWith(`${account.value.toLowerCase()}/`),
        )
        .filter((repository) => repository.name.toLowerCase().includes(term))
        .slice(0, 8);
});

function owner(name: string): string {
    return name.includes('/') ? `${name.split('/')[0]}/` : '';
}

function repo(name: string): string {
    return name.includes('/') ? name.split('/').slice(1).join('/') : name;
}

function submit(): void {
    const text = query.value.trim();
    if (!text || disabled) return;
    if (pasted.value) {
        emit('import', text);
    } else if (matches.value.length === 1) {
        emit('import', matches.value[0].url);
    }
}
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="flex flex-wrap items-center gap-2">
            <template v-if="github?.connected">
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <PickerButton
                            :label="accountLabel ?? $t('All accounts')"
                            logo="github"
                            strong
                        />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="ds-modal min-w-48 font-ui"
                        align="start"
                    >
                        <DropdownMenuItem
                            v-if="accounts.length > 1"
                            @select="account = null"
                        >
                            {{ $t('All accounts') }}
                            <Check
                                v-if="account === null"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-for="item in accounts"
                            :key="item.login"
                            @select="
                                account =
                                    accounts.length > 1 ? item.login : null
                            "
                        >
                            <BrandLogo name="github" :size="14" />
                            {{ item.login }}
                            <Check
                                v-if="accountLabel === item.login"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem as-child>
                            <a :href="connectUrl">{{
                                $t('Connect another account')
                            }}</a>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <span
                    class="flex min-w-0 items-center gap-1.5 text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                >
                    <span
                        class="size-1.5 shrink-0 rounded-full bg-[#3ec46d]"
                        aria-hidden="true"
                    />
                    <span class="truncate">{{
                        connectedBy
                            ? $t('Connected by :name', { name: connectedBy })
                            : $t('Connected')
                    }}</span>
                </span>
            </template>
            <template v-else-if="available && github">
                <a
                    :href="connectUrl"
                    class="dash-button dash-button--secondary dash-button--sm"
                >
                    <BrandLogo name="github" :size="14" />
                    {{ $t('Connect GitHub') }}
                </a>
                <span
                    class="text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                    >{{ $t('to pick from your repos, private ones too') }}</span
                >
            </template>
            <span
                v-else-if="!available"
                class="text-[12px]/4 tracking-[-0.005em] text-fg-tertiary"
                >{{
                    $t('Paste a public GitHub link, or a git SSH address.')
                }}</span
            >
            <span class="min-w-2 flex-1" />
            <label
                v-if="!searchless"
                class="flex h-7 w-[236px] max-w-full items-center gap-[7px] rounded-[6px] border-[0.5px] border-[#dcdcdc] bg-[#f5f5f5] px-[9px] shadow-[inset_0_1px_2px_rgb(0_0_0/0.06)] focus-within:border-[#4a4a4a] dark:border-[#2a2a2a] dark:bg-[#101010] dark:shadow-[inset_0_1px_2px_rgb(0_0_0/0.5)]"
            >
                <FilledIcon
                    name="search-fill"
                    :size="13"
                    class="shrink-0 text-fg-muted"
                />
                <span class="sr-only">{{
                    $t('Search repos, or paste a URL')
                }}</span>
                <input
                    v-model="query"
                    type="text"
                    autocomplete="off"
                    spellcheck="false"
                    :placeholder="
                        github?.connected
                            ? $t('Search repos, or paste a URL')
                            : $t('Paste a repository URL')
                    "
                    class="h-full min-w-0 flex-1 bg-transparent text-[12.5px]/4 tracking-[-0.005em] text-fg-primary outline-none placeholder:text-fg-faint"
                    @keydown.enter.prevent="submit"
                />
            </label>
        </div>

        <div
            v-if="available && github === null"
            class="h-[121px] animate-pulse rounded-[8px] bg-[#f2f2f2] dark:bg-[#1c1c1c]"
            :aria-label="$t('Loading your repositories')"
        />

        <ul
            v-else-if="
                searchless
                    ? !pasted && github?.connected
                    : pasted || matches.length || github?.connected
            "
            class="overflow-clip rounded-[8px] border-[0.5px] border-[#e2e2e2] bg-white shadow-[0_1px_2px_rgb(0_0_0/0.06)] dark:border-[#2a2a2a] dark:bg-[#1c1c1c] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.04),0_3px_6px_-2px_rgb(0_0_0/0.3),0_1px_1.5px_rgb(0_0_0/0.5)]"
        >
            <li
                v-if="pasted"
                class="flex h-10 items-center gap-2.5 pr-1.5 pl-2"
            >
                <span
                    class="flex size-[26px] shrink-0 items-center justify-center rounded-[6px] bg-[#f2f2f2] text-fg-secondary shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] dark:bg-[#262626] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)]"
                >
                    <FilledIcon name="branch-fill" :size="15" />
                </span>
                <span
                    class="min-w-0 flex-1 truncate font-ui-mono text-[12px]/5 text-fg-primary"
                    >{{ query.trim() }}</span
                >
                <button
                    type="button"
                    class="dash-button dash-button--secondary dash-button--sm"
                    :disabled="disabled"
                    @click="submit"
                >
                    {{ $t('Import') }}
                </button>
            </li>
            <li
                v-for="repository in pasted ? [] : matches"
                :key="repository.url"
                class="flex h-10 items-center gap-2.5 border-b-[0.5px] border-[#ececec] pr-1.5 pl-2 last:border-b-0 dark:border-[#262626]"
            >
                <span
                    class="flex size-[26px] shrink-0 items-center justify-center rounded-[6px] bg-[#f2f2f2] shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] dark:bg-[#262626] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)]"
                >
                    <TemplateLogo
                        :icon="languageIcon(repository.language)"
                        :size="15"
                    />
                </span>
                <span class="flex min-w-0 items-center text-[13px]/5">
                    <span class="truncate tracking-[-0.005em] text-fg-muted">{{
                        owner(repository.name)
                    }}</span>
                    <span
                        class="truncate font-medium tracking-[-0.01em] text-fg-primary"
                        >{{ repo(repository.name) }}</span
                    >
                    <FilledIcon
                        v-if="repository.private"
                        name="lock-fill"
                        :size="12"
                        class="ml-1.5 shrink-0 text-fg-muted"
                        :aria-label="$t('Private')"
                    />
                </span>
                <span class="flex-1" />
                <span
                    class="hidden shrink-0 items-center gap-1.5 text-[12px]/4 tracking-[-0.005em] text-fg-tertiary min-[560px]:flex"
                >
                    <template v-if="repository.language">
                        {{ repository.language }}
                        <span class="text-fg-faint" aria-hidden="true">·</span>
                    </template>
                    {{ shortAgo(repository.pushed) ?? '' }}
                </span>
                <button
                    type="button"
                    class="dash-button dash-button--secondary dash-button--sm"
                    :disabled="disabled"
                    :aria-busy="busy === repository.url || undefined"
                    @click="emit('import', repository.url)"
                >
                    {{
                        busy === repository.url ? $t('Looking…') : $t('Import')
                    }}
                </button>
            </li>
            <li
                v-if="!pasted && matches.length === 0"
                class="flex h-10 items-center px-3 text-[12.5px]/4 text-fg-muted"
            >
                {{
                    query.trim()
                        ? $t('No repository matches “:query”.', {
                              query: query.trim(),
                          })
                        : $t('No repositories are shared with ServerOS yet.')
                }}
            </li>
        </ul>
    </div>
</template>
