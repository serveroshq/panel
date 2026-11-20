<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import InputError from '@/components/InputError.vue';
import AppButton from '@/components/workspace/AppButton.vue';
import CssEditor from '@/components/workspace/CssEditor.vue';
import ThemeSplitPreview from '@/components/workspace/ThemeSplitPreview.vue';
import { STANDARD_PREVIEW } from '@/lib/themePreview';

const props = defineProps<{
    theme: {
        name: string;
        css: string;
        url: string;
        imports: number;
        preview: {
            light: Record<string, string>;
            dark: Record<string, string>;
        };
    };
    signedIn: boolean;
    workspaces: { slug: string; name: string }[];
}>();

const appName = computed(() => String(usePage().props.name ?? 'ServerOS'));
const form = useForm({ workspace: props.workspaces[0]?.slug ?? '' });
const css = ref(props.theme.css);
const copied = ref(false);
const token = computed(() => props.theme.url.split('/').pop() ?? '');

const light = computed(() => ({
    ...STANDARD_PREVIEW.light,
    ...props.theme.preview.light,
}));
const dark = computed(() => ({
    ...STANDARD_PREVIEW.dark,
    ...props.theme.preview.dark,
}));
const added = computed(() =>
    props.theme.imports === 1
        ? trans('Added to 1 workspace')
        : trans('Added to :count workspaces', {
              count: String(props.theme.imports),
          }),
);

function add(): void {
    form.post(`/themes/${token.value}/import`);
}

async function copyLink(): Promise<void> {
    await navigator.clipboard.writeText(props.theme.url);
    copied.value = true;
    setTimeout(() => (copied.value = false), 1600);
}

function download(): void {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(
        new Blob([props.theme.css], { type: 'text/css' }),
    );
    link.download = `${props.theme.name.toLowerCase().replace(/[^a-z0-9]+/g, '-') || 'theme'}.css`;
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>

<template>
    <Head :title="theme.name" />

    <div
        class="ds-root workspace-panel min-h-dvh bg-(--ds-panel) font-ui text-(--ds-text) antialiased"
    >
        <header
            class="mx-auto flex max-w-4xl items-center justify-between px-5 py-5 sm:px-8"
        >
            <a
                href="/"
                class="flex items-center gap-2 rounded-[6px] text-[14px] font-semibold focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
            >
                <img
                    src="/images/landing/serveros-logo.svg"
                    alt=""
                    class="size-[18px] object-contain invert dark:invert-0"
                />
                {{ appName }}
            </a>
        </header>

        <main class="mx-auto flex max-w-4xl flex-col gap-6 px-5 pb-16 sm:px-8">
            <div class="flex flex-wrap items-end justify-between gap-4">
                <div class="flex flex-col gap-1">
                    <p class="text-[13px]/5 text-(--ds-text-muted)">
                        {{ $t('A dashboard theme for :app', { app: appName }) }}
                    </p>
                    <h1
                        class="text-[24px]/8 font-semibold tracking-[-0.01em] text-balance"
                    >
                        {{ theme.name }}
                    </h1>
                    <p
                        v-if="theme.imports > 0"
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{ added }}
                    </p>
                </div>
                <div class="flex gap-2">
                    <AppButton @click="copyLink">
                        {{ copied ? $t('Copied') : $t('Copy link') }}
                    </AppButton>
                    <AppButton @click="download">{{
                        $t('Download .css')
                    }}</AppButton>
                </div>
            </div>

            <ThemeSplitPreview
                class="rounded-[14px] shadow-[0_0_0_1px_var(--ds-border)]"
                :light="light"
                :dark="dark"
            />

            <section
                class="ds-tile flex flex-col gap-3 p-5"
                aria-labelledby="add-theme"
            >
                <div class="flex flex-col gap-0.5">
                    <h2 id="add-theme" class="text-[14px]/5 font-medium">
                        {{ $t('Add it to your workspace') }}
                    </h2>
                    <p class="text-[13px]/5 text-(--ds-text-muted)">
                        {{
                            $t(
                                'It becomes your draft, so only you see it until you publish it. Images it uses come with it.',
                            )
                        }}
                    </p>
                </div>

                <form
                    v-if="signedIn && workspaces.length"
                    class="flex flex-wrap items-center gap-2"
                    @submit.prevent="add"
                >
                    <label for="theme-workspace" class="sr-only">{{
                        $t('Workspace')
                    }}</label>
                    <select
                        id="theme-workspace"
                        v-model="form.workspace"
                        class="ds-select h-8 min-w-48 rounded-[9px] border border-(--ds-border-strong) bg-(--ds-field) pl-3 text-[13px] text-(--ds-text)"
                    >
                        <option
                            v-for="workspace in workspaces"
                            :key="workspace.slug"
                            :value="workspace.slug"
                        >
                            {{ workspace.name }}
                        </option>
                    </select>
                    <AppButton
                        type="submit"
                        variant="primary"
                        :disabled="form.processing"
                    >
                        {{ $t('Add theme') }}
                    </AppButton>
                    <InputError
                        class="basis-full"
                        :message="form.errors.workspace"
                    />
                </form>
                <p
                    v-else-if="signedIn"
                    class="text-[13px]/5 text-(--ds-text-secondary)"
                >
                    {{
                        $t(
                            'You need to be an owner or admin of a workspace to change its theme.',
                        )
                    }}
                </p>
                <div v-else class="flex flex-wrap items-center gap-2">
                    <a
                        :href="`/themes/${token}/add`"
                        class="inline-flex h-8 items-center rounded-[9px] border border-(--ds-primary-border) bg-(--ds-primary-bg) px-3 text-[13px] font-medium text-(--ds-primary-text) hover:bg-(--ds-primary-bg-hover) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                    >
                        {{ $t('Sign in to add it') }}
                    </a>
                    <a
                        href="/register"
                        class="text-[13px] text-(--ds-text-secondary) underline-offset-2 hover:underline"
                    >
                        {{ $t('New here? Create an account') }}
                    </a>
                </div>
            </section>

            <section class="flex flex-col gap-2" aria-labelledby="theme-css">
                <h2 id="theme-css" class="text-[14px]/5 font-medium">CSS</h2>
                <CssEditor v-model="css" readonly />
            </section>
        </main>
    </div>
</template>
