<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, useTemplateRef, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import AppButton from '@/components/workspace/AppButton.vue';
import CssEditor from '@/components/workspace/CssEditor.vue';
import ThemeSplitPreview from '@/components/workspace/ThemeSplitPreview.vue';
import Tile from '@/components/workspace/Tile.vue';
import { useAssistant } from '@/composables/useAssistant';
import { SolarImagePlus, SolarLayers } from '@/lib/solarIcons';
import { STANDARD_PREVIEW } from '@/lib/themePreview';
import { setThemePreview } from '@/lib/workspaceTheme';
import type { Workspace } from '@/types';

type Template = {
    key: string;
    name: string;
    description: string;
    credit: string;
    preview: { light: Record<string, string>; dark: Record<string, string> };
    css: string;
};

type Image = { name: string; url: string; size: number };

const props = defineProps<{
    workspace: Workspace;
    css: string;
    publishedCss: string | null;
    hasDraft: boolean;
    publishedAt: string | null;
    templates: Template[];
    images: Image[];
    maxImages: number;
    maxBytes: number;
    shares: {
        id: number;
        name: string;
        url: string;
        imports: number;
        createdAt: string | null;
    }[];
    sharedUrl: string | null;
}>();

const form = useForm({ css: props.css });
const upload = useForm<{ image: File | null }>({ image: null });
const fileInput = useTemplateRef<HTMLInputElement>('fileInput');
const confirmingOff = ref(false);
const copied = ref<string | null>(null);
const assistant = useAssistant();

const bytes = computed(() => new TextEncoder().encode(form.css).length);
const live = computed(() => props.publishedCss !== null);
const canPublish = computed(() => form.isDirty || props.hasDraft);
const inUse = computed(
    () =>
        props.templates.find((t) => t.css.trim() === form.css.trim())?.key ??
        null,
);
const status = computed(() => {
    if (props.hasDraft) return trans('Draft, only you see it');
    if (live.value) return trans('Published');
    return trans('Standard look');
});

function colours(
    template: Template,
    mode: 'light' | 'dark',
): Record<string, string> {
    return { ...STANDARD_PREVIEW[mode], ...template.preview[mode] };
}

function fileSize(size: number): string {
    return size >= 1_000_000
        ? `${(size / 1_000_000).toFixed(1)} MB`
        : `${Math.max(1, Math.round(size / 1000))} KB`;
}

// What's typed shows on this page straight away, before it's saved.
let previewTimer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => form.css,
    (css) => {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(
            () => setThemePreview(form.isDirty ? css : null),
            120,
        );
    },
);

// Ask AI edits the draft from the side chat: take it in unless something's being typed.
watch(
    () => props.css,
    (css) => {
        if (form.isDirty) return;
        form.defaults({ css });
        form.reset();
    },
);

onBeforeUnmount(() => {
    clearTimeout(previewTimer);
    setThemePreview(null);
});

function settle(): void {
    form.defaults({ css: form.css });
    form.reset();
    setThemePreview(null);
}

function saveDraft(): void {
    form.put('/settings/theme/draft', {
        preserveScroll: true,
        onSuccess: settle,
    });
}

function publish(): void {
    form.post('/settings/theme/publish', {
        preserveScroll: true,
        onSuccess: settle,
    });
}

function useTemplate(template: Template): void {
    form.css = template.css;
    saveDraft();
}

function discard(): void {
    if (!props.hasDraft) {
        form.reset();
        setThemePreview(null);
        return;
    }
    router.delete('/settings/theme/draft', {
        preserveScroll: true,
        onSuccess: () => {
            form.defaults({ css: props.css });
            form.reset();
            setThemePreview(null);
        },
    });
}

function turnOff(): void {
    if (!confirmingOff.value) {
        confirmingOff.value = true;
        return;
    }
    router.delete('/settings/theme', {
        preserveScroll: true,
        onFinish: () => (confirmingOff.value = false),
    });
}

function askAi(): void {
    assistant.composer.value = trans('Change the dashboard theme: ');
    assistant.open.value = true;
}

function pickImage(event: Event): void {
    const input = event.target as HTMLInputElement;
    upload.image = input.files?.[0] ?? null;
    input.value = '';
    if (!upload.image) return;
    upload.post('/settings/theme/images', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => upload.reset(),
    });
}

function removeImage(image: Image): void {
    router.delete(`/settings/theme/images/${encodeURIComponent(image.name)}`, {
        preserveScroll: true,
    });
}

async function copy(image: Image): Promise<void> {
    await navigator.clipboard.writeText(`url(${image.url})`);
    copied.value = image.name;
    setTimeout(() => {
        if (copied.value === image.name) copied.value = null;
    }, 1600);
}

function useAsBackground(image: Image): void {
    const rule = `\n[data-theme-part='content'] {\n    background: var(--ds-frame) url(${image.url}) center / cover no-repeat;\n}\n`;
    form.css = form.css.trimEnd() + '\n' + rule;
}

const th = 'px-3 py-2.5 text-left text-[12px]/4 font-medium';

// Sharing: a link others can add to their own workspace, or the CSS as a file.
const shareForm = useForm({
    name: trans(':workspace theme', { workspace: props.workspace.name }),
    css: '',
});
const importForm = useForm<{ link: string; file: File | null }>({
    link: '',
    file: null,
});
const importPicker = useTemplateRef<HTMLInputElement>('importPicker');
const shareError = computed(
    () =>
        (shareForm.errors as Record<string, string | undefined>).share ??
        shareForm.errors.css,
);
const copiedLink = ref<string | null>(null);

function createShare(): void {
    shareForm.css = form.css;
    shareForm.post('/settings/theme/shares', { preserveScroll: true });
}

function stopSharing(id: number): void {
    router.delete(`/settings/theme/shares/${id}`, { preserveScroll: true });
}

async function copyLink(url: string): Promise<void> {
    await navigator.clipboard.writeText(url);
    copiedLink.value = url;
    setTimeout(() => {
        if (copiedLink.value === url) copiedLink.value = null;
    }, 1600);
}

function downloadCss(): void {
    const link = document.createElement('a');
    link.href = URL.createObjectURL(new Blob([form.css], { type: 'text/css' }));
    link.download = `${props.workspace.slug}-theme.css`;
    link.click();
    URL.revokeObjectURL(link.href);
}

function importLink(): void {
    importForm.file = null;
    importForm.post('/settings/theme/import', {
        preserveScroll: true,
        onSuccess: () => importForm.reset(),
    });
}

function importFile(event: Event): void {
    const picker = event.target as HTMLInputElement;
    importForm.file = picker.files?.[0] ?? null;
    picker.value = '';
    if (!importForm.file) return;
    importForm.link = '';
    importForm.post('/settings/theme/import', {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => importForm.reset(),
    });
}

function shortDate(iso: string | null): string {
    return iso
        ? new Date(iso).toLocaleDateString(undefined, {
              day: 'numeric',
              month: 'short',
              year: 'numeric',
          })
        : '';
}
</script>

<template>
    <Head :title="$t('Theme')" />

    <div class="flex flex-col gap-4">
        <Tile
            id="theme-css"
            :title="$t('Theme')"
            :description="
                $t(
                    'Your own CSS on top of the dashboard, for everyone in this workspace. Anything can be changed. Edits stay a draft only you see until you publish them.',
                )
            "
            :icon="SolarLayers"
        >
            <template #actions>
                <span
                    class="flex items-center gap-1.5 text-xs/4 text-(--ds-text-muted)"
                >
                    <span
                        aria-hidden="true"
                        class="size-1.5 rounded-full"
                        :class="
                            hasDraft
                                ? 'bg-(--ds-amber)'
                                : live
                                  ? 'bg-(--ds-green)'
                                  : 'bg-(--ds-text-faint)'
                        "
                    />
                    {{ status }}
                </span>
                <AppButton size="sm" @click="askAi">{{
                    $t('Ask AI')
                }}</AppButton>
            </template>

            <div class="flex flex-col gap-2">
                <label for="theme-css-input" class="sr-only">{{
                    $t('CSS')
                }}</label>
                <CssEditor
                    id="theme-css-input"
                    v-model="form.css"
                    :invalid="!!form.errors.css"
                    :placeholder="
                        $t(
                            '/* Pick a template below, ask AI, or write CSS here. */',
                        )
                    "
                />
                <InputError :message="form.errors.css" />
                <p class="text-xs/4 text-(--ds-text-muted)">
                    {{
                        $t(
                            'Broke something? Add ?theme=off to any dashboard address to turn custom CSS off for you, and ?theme=on to bring it back.',
                        )
                    }}
                </p>
            </div>

            <template #footer>
                <span
                    class="mr-auto text-xs/4 text-(--ds-text-muted) tabular-nums"
                    :class="{ 'text-(--ds-red)': bytes > maxBytes }"
                    >{{
                        $t(':used of :max KB', {
                            used: (bytes / 1000).toFixed(1),
                            max: String(Math.round(maxBytes / 1000)),
                        })
                    }}</span
                >
                <AppButton
                    v-if="hasDraft || form.isDirty"
                    variant="quiet"
                    :disabled="form.processing"
                    @click="discard"
                >
                    {{ hasDraft ? $t('Discard draft') : $t('Undo changes') }}
                </AppButton>
                <AppButton
                    :disabled="form.processing || !form.isDirty"
                    @click="saveDraft"
                >
                    {{ $t('Save draft') }}
                </AppButton>
                <AppButton
                    variant="primary"
                    :disabled="form.processing || !canPublish"
                    @click="publish"
                >
                    {{ $t('Publish') }}
                </AppButton>
            </template>
        </Tile>

        <Tile
            id="templates"
            :title="$t('Templates')"
            :description="
                $t(
                    'Pick one to start from. It becomes your draft, so you can change it before anyone sees it.',
                )
            "
        >
            <div
                class="grid grid-cols-1 gap-3 min-[480px]:grid-cols-2 xl:grid-cols-4"
                role="group"
                :aria-label="$t('Templates')"
            >
                <button
                    v-for="template in templates"
                    :key="template.key"
                    type="button"
                    class="group flex cursor-pointer flex-col gap-2 rounded-[12px] p-1.5 text-left transition-[background-color,box-shadow] duration-150 hover:bg-(--ds-hover) focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:cursor-wait aria-pressed:shadow-[0_0_0_2px_var(--ds-accent)] motion-reduce:transition-none"
                    :aria-pressed="inUse === template.key"
                    :title="
                        template.description +
                        (template.credit !== 'ServerOS'
                            ? ` (${template.credit})`
                            : '')
                    "
                    :disabled="form.processing"
                    @click="useTemplate(template)"
                >
                    <ThemeSplitPreview
                        class="rounded-[8px] shadow-[0_0_0_1px_var(--ds-border)]"
                        :light="colours(template, 'light')"
                        :dark="colours(template, 'dark')"
                    />
                    <span
                        class="flex items-center justify-between gap-2 px-1 pb-0.5"
                    >
                        <span class="truncate text-[13px]/5 font-medium">{{
                            template.name
                        }}</span>
                        <span
                            v-if="inUse === template.key"
                            class="flex size-4 shrink-0 items-center justify-center rounded-full bg-(--ds-accent) text-(--ds-primary-text)"
                        >
                            <Check class="size-2.5" :stroke-width="3" />
                            <span class="sr-only">{{ $t('In use') }}</span>
                        </span>
                    </span>
                </button>
            </div>
        </Tile>

        <Tile
            id="images"
            :title="$t('Images')"
            :description="
                $t(
                    'Pictures for backgrounds and anything else a theme shows. Up to :count, 5 MB each.',
                    { count: String(maxImages) },
                )
            "
            :padded="false"
        >
            <template #actions>
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/png,image/jpeg,image/webp,image/gif,image/avif"
                    class="sr-only"
                    tabindex="-1"
                    @change="pickImage"
                />
                <AppButton
                    size="sm"
                    :disabled="upload.processing || images.length >= maxImages"
                    @click="fileInput?.click()"
                >
                    <SolarImagePlus class="size-3.5" :stroke-width="1.75" />
                    {{ upload.processing ? $t('Uploading…') : $t('Upload') }}
                </AppButton>
            </template>

            <InputError
                v-if="upload.errors.image"
                class="px-4 pt-3"
                :message="upload.errors.image"
            />
            <div v-if="images.length" class="overflow-x-auto">
                <table class="w-full min-w-[560px] text-[13px]/5">
                    <thead>
                        <tr class="text-(--ds-text-muted)">
                            <th :class="[th, 'w-20 pl-4']">
                                <span class="sr-only">{{ $t('Preview') }}</span>
                            </th>
                            <th :class="th">{{ $t('Name') }}</th>
                            <th :class="th">{{ $t('Size') }}</th>
                            <th :class="[th, 'pr-4']" />
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="image in images"
                            :key="image.name"
                            class="border-t border-(--ds-divider)"
                        >
                            <td class="py-2 pr-3 pl-4">
                                <img
                                    :src="image.url"
                                    alt=""
                                    loading="lazy"
                                    class="h-9 w-14 rounded-[5px] object-cover shadow-[0_0_0_1px_var(--ds-border)]"
                                />
                            </td>
                            <td
                                class="px-3 py-2 font-ui-mono text-[12px] break-all text-(--ds-text-secondary)"
                            >
                                {{ image.name }}
                            </td>
                            <td
                                class="px-3 py-2 whitespace-nowrap text-(--ds-text-muted) tabular-nums"
                            >
                                {{ fileSize(image.size) }}
                            </td>
                            <td class="py-2 pr-4 pl-3">
                                <div
                                    class="flex items-center justify-end gap-1.5"
                                >
                                    <AppButton
                                        size="xs"
                                        @click="useAsBackground(image)"
                                    >
                                        {{ $t('Use as background') }}
                                    </AppButton>
                                    <AppButton
                                        size="xs"
                                        variant="quiet"
                                        @click="copy(image)"
                                    >
                                        {{
                                            copied === image.name
                                                ? $t('Copied')
                                                : $t('Copy url()')
                                        }}
                                    </AppButton>
                                    <AppButton
                                        size="xs"
                                        variant="danger"
                                        @click="removeImage(image)"
                                    >
                                        {{ $t('Delete') }}
                                    </AppButton>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
            <p
                v-else
                class="px-4 py-6 text-center text-[13px]/5 text-(--ds-text-muted)"
            >
                {{ $t('No images yet.') }}
            </p>
        </Tile>

        <Tile
            id="share"
            :title="$t('Share and import')"
            :description="
                $t(
                    'Share your theme with a link anyone can add to their own workspace, or bring one in from a link or a .css file.',
                )
            "
            :padded="false"
        >
            <div class="flex flex-col divide-y divide-(--ds-divider)">
                <div class="flex flex-col gap-2 px-4 py-3.5">
                    <span class="text-[13px]/5 font-medium">{{
                        $t('Share a link')
                    }}</span>
                    <form
                        class="flex flex-wrap gap-2"
                        @submit.prevent="createShare"
                    >
                        <label for="share-name" class="sr-only">{{
                            $t('Theme name')
                        }}</label>
                        <input
                            id="share-name"
                            v-model="shareForm.name"
                            maxlength="80"
                            :placeholder="$t('Theme name')"
                            class="h-8 min-w-0 grow basis-56 rounded-[9px] border border-(--ds-border-strong) bg-(--ds-field) px-3 text-[13px] text-(--ds-text) placeholder:text-(--ds-text-faint) focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        />
                        <AppButton
                            type="submit"
                            :disabled="
                                shareForm.processing ||
                                !form.css.trim() ||
                                !shareForm.name.trim()
                            "
                        >
                            {{ $t('Create link') }}
                        </AppButton>
                        <AppButton
                            :disabled="!form.css.trim()"
                            @click="downloadCss"
                        >
                            {{ $t('Download .css') }}
                        </AppButton>
                    </form>
                    <InputError :message="shareError" />
                    <div
                        v-if="sharedUrl"
                        class="flex flex-wrap items-center gap-2 rounded-[9px] bg-(--ds-green-bg) px-3 py-2 text-[13px]/5"
                        role="status"
                    >
                        <span class="text-(--ds-text-secondary)">{{
                            $t('Link ready:')
                        }}</span>
                        <a
                            :href="sharedUrl"
                            target="_blank"
                            rel="noopener"
                            class="min-w-0 truncate font-ui-mono text-[12px] text-(--ds-text) underline-offset-2 hover:underline"
                            >{{ sharedUrl }}</a
                        >
                        <AppButton
                            size="xs"
                            class="ml-auto"
                            @click="copyLink(sharedUrl)"
                        >
                            {{
                                copiedLink === sharedUrl
                                    ? $t('Copied')
                                    : $t('Copy')
                            }}
                        </AppButton>
                    </div>
                    <p class="text-xs/4 text-(--ds-text-muted)">
                        {{
                            $t(
                                'The link shares what’s in the editor now, with the images it uses. Later changes don’t change it.',
                            )
                        }}
                    </p>
                </div>

                <div class="flex flex-col gap-2 px-4 py-3.5">
                    <span class="text-[13px]/5 font-medium">{{
                        $t('Import')
                    }}</span>
                    <form
                        class="flex flex-wrap gap-2"
                        @submit.prevent="importLink"
                    >
                        <label for="import-link" class="sr-only">{{
                            $t('Theme link')
                        }}</label>
                        <input
                            id="import-link"
                            v-model="importForm.link"
                            maxlength="300"
                            :placeholder="$t('Paste a theme link')"
                            class="h-8 min-w-0 grow basis-56 rounded-[9px] border border-(--ds-border-strong) bg-(--ds-field) px-3 text-[13px] text-(--ds-text) placeholder:text-(--ds-text-faint) focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        />
                        <AppButton
                            type="submit"
                            :disabled="
                                importForm.processing || !importForm.link.trim()
                            "
                        >
                            {{ $t('Import') }}
                        </AppButton>
                        <input
                            ref="importPicker"
                            type="file"
                            accept=".css,text/css"
                            class="sr-only"
                            tabindex="-1"
                            @change="importFile"
                        />
                        <AppButton
                            :disabled="importForm.processing"
                            @click="importPicker?.click()"
                        >
                            {{ $t('Upload .css') }}
                        </AppButton>
                    </form>
                    <InputError
                        :message="
                            importForm.errors.link || importForm.errors.file
                        "
                    />
                    <p class="text-xs/4 text-(--ds-text-muted)">
                        {{
                            $t(
                                'It replaces what’s in the editor as your draft, so you can look before anyone else sees it.',
                            )
                        }}
                    </p>
                </div>

                <div v-if="shares.length" class="overflow-x-auto">
                    <table class="w-full min-w-[560px] text-[13px]/5">
                        <thead>
                            <tr class="text-(--ds-text-muted)">
                                <th :class="[th, 'pl-4']">
                                    {{ $t('Shared links') }}
                                </th>
                                <th :class="th">{{ $t('Created') }}</th>
                                <th :class="th">{{ $t('Added') }}</th>
                                <th :class="[th, 'pr-4']" />
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="share in shares"
                                :key="share.id"
                                class="border-t border-(--ds-divider)"
                            >
                                <td class="py-2 pr-3 pl-4">
                                    <a
                                        :href="share.url"
                                        target="_blank"
                                        rel="noopener"
                                        class="font-medium underline-offset-2 hover:underline"
                                        >{{ share.name }}</a
                                    >
                                </td>
                                <td
                                    class="px-3 py-2 whitespace-nowrap text-(--ds-text-muted)"
                                >
                                    {{ shortDate(share.createdAt) }}
                                </td>
                                <td
                                    class="px-3 py-2 text-(--ds-text-muted) tabular-nums"
                                >
                                    {{ share.imports }}
                                </td>
                                <td class="py-2 pr-4 pl-3">
                                    <div
                                        class="flex items-center justify-end gap-1.5"
                                    >
                                        <AppButton
                                            size="xs"
                                            @click="copyLink(share.url)"
                                        >
                                            {{
                                                copiedLink === share.url
                                                    ? $t('Copied')
                                                    : $t('Copy link')
                                            }}
                                        </AppButton>
                                        <AppButton
                                            size="xs"
                                            variant="danger"
                                            @click="stopSharing(share.id)"
                                        >
                                            {{ $t('Stop sharing') }}
                                        </AppButton>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </Tile>

        <Tile
            v-if="live"
            id="standard-look"
            :title="$t('Standard look')"
            :description="
                $t(
                    'Take the published theme off for everyone and go back to how ServerOS looks out of the box.',
                )
            "
        >
            <div>
                <AppButton variant="danger" @click="turnOff">
                    {{
                        confirmingOff
                            ? $t('Click again to take it off')
                            : $t('Go back to the standard look')
                    }}
                </AppButton>
            </div>
        </Tile>
    </div>
</template>
