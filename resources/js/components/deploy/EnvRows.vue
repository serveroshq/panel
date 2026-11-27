<script setup lang="ts">
import { X } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, nextTick, ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import { NAME_PATTERN, envRow, parseEnv } from './envFile';
import type { EnvRow } from './envFile';

const rows = defineModel<EnvRow[]>({ required: true });

const {
    flags = true,
    compose = false,
    error = null,
} = defineProps<{
    /** Whether each setting can be kept to the build or the running app. */
    flags?: boolean;
    /** Settings fill in ${NAME} in a Compose file. */
    compose?: boolean;
    error?: string | null;
}>();

const placeholder = 'DATABASE_URL=postgres://…\nexport API_KEY="…"';

const pasting = ref(false);
const pasteText = ref('');
const pasteError = ref<string | null>(null);
const pasteBox = ref<HTMLTextAreaElement | null>(null);
const list = ref<HTMLElement | null>(null);

/** Adds settings, replacing the value of one already there by name. */
function merge(found: { name: string; value: string }[]): void {
    const next = rows.value.filter((row) => row.name.trim() !== '');
    for (const { name, value } of found) {
        const existing = next.find((row) => row.name === name);
        if (existing) existing.value = value;
        else next.push(envRow(name, value));
    }
    rows.value = next;
}

function openPaste(): void {
    pasting.value = true;
    pasteError.value = null;
    void nextTick(() => pasteBox.value?.focus());
}

function addPasted(): void {
    const parsed = parseEnv(pasteText.value);
    if (parsed.error) {
        pasteError.value = parsed.error.name
            ? trans(
                  'The value of :name on line :line opens a quote that never closes.',
                  { name: parsed.error.name, line: String(parsed.error.line) },
              )
            : trans('Line :line should look like NAME=value.', {
                  line: String(parsed.error.line),
              });

        return;
    }
    if (parsed.variables.length === 0) {
        pasteError.value = trans(
            'Nothing to add. Paste lines like NAME=value.',
        );

        return;
    }
    merge(parsed.variables);
    pasteText.value = '';
    pasteError.value = null;
    pasting.value = false;
}

function addRow(): void {
    rows.value = [...rows.value, envRow()];
    void nextTick(() =>
        list.value
            ?.querySelector<HTMLInputElement>('li:last-child input')
            ?.focus(),
    );
}

function remove(id: number): void {
    rows.value = rows.value.filter((row) => row.id !== id);
}

// Pasting a whole .env into a name field adds every setting in it.
function onNamePaste(event: ClipboardEvent, row: EnvRow): void {
    const text = event.clipboardData?.getData('text') ?? '';
    if (!text.includes('=')) return;
    const parsed = parseEnv(text);
    if (parsed.error || parsed.variables.length === 0) return;
    event.preventDefault();
    if (row.name.trim() === '' && row.value === '') remove(row.id);
    merge(parsed.variables);
}

function setFlag(row: EnvRow, which: 'build' | 'runtime', on: boolean): void {
    row[which] = on;
    // A setting is always somewhere: turning off the one left turns the other on.
    if (!row.build && !row.runtime) {
        row[which === 'build' ? 'runtime' : 'build'] = true;
    }
}

const badNames = computed(
    () =>
        new Set(
            rows.value
                .filter(
                    (row) =>
                        row.name.trim() !== '' &&
                        !NAME_PATTERN.test(row.name.trim()),
                )
                .map((row) => row.id),
        ),
);

const empties = computed(
    () =>
        rows.value.filter((row) => row.name.trim() !== '' && row.value === '')
            .length,
);

const field =
    'h-[30px] w-full min-w-0 rounded-[6px] border-[0.5px] border-edge-field bg-surface-field px-2 font-ui-mono text-[12px]/5 text-fg-primary shadow-(--dash-shadow-field) outline-none placeholder:text-fg-faint focus:border-edge-checkbox-hover';
</script>

<template>
    <div class="flex flex-col gap-2">
        <div
            v-if="rows.length"
            class="overflow-clip rounded-[8px] border-[0.5px] border-[#e2e2e2] dark:border-[#2a2a2a]"
        >
            <div
                class="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)_auto] items-center gap-2 border-b-[0.5px] border-[#ececec] bg-[#fafafa] px-2.5 py-1.5 text-[11.5px]/4 font-medium text-fg-muted dark:border-[#262626] dark:bg-[#1a1a1a]"
            >
                <span>{{ $t('Name') }}</span>
                <span>{{ $t('Value') }}</span>
                <span class="flex items-center gap-0">
                    <template v-if="flags">
                        <span
                            class="w-[52px] text-center"
                            :title="$t('Available while it builds')"
                            >{{ $t('Build') }}</span
                        >
                        <span
                            class="w-[52px] text-center"
                            :title="$t('Available to the running app')"
                            >{{ $t('Runtime') }}</span
                        >
                    </template>
                    <span class="w-7" />
                </span>
            </div>
            <ul ref="list" class="max-h-[344px] overflow-y-auto">
                <li
                    v-for="row in rows"
                    :key="row.id"
                    class="grid grid-cols-[minmax(0,2fr)_minmax(0,3fr)_auto] items-start gap-2 border-b-[0.5px] border-[#ececec] px-2.5 py-1.5 last:border-b-0 dark:border-[#262626]"
                >
                    <input
                        v-model.trim="row.name"
                        :class="[
                            field,
                            badNames.has(row.id) &&
                                'border-status-down! text-status-down',
                        ]"
                        :aria-label="$t('Name')"
                        :aria-invalid="badNames.has(row.id) || undefined"
                        placeholder="NAME"
                        autocomplete="off"
                        spellcheck="false"
                        @paste="onNamePaste($event, row)"
                    />
                    <textarea
                        v-if="row.value.includes('\n')"
                        v-model="row.value"
                        :class="[field, 'h-auto! py-1']"
                        rows="3"
                        :aria-label="$t('Value of :name', { name: row.name })"
                        spellcheck="false"
                    />
                    <input
                        v-else
                        v-model="row.value"
                        :class="field"
                        :aria-label="$t('Value of :name', { name: row.name })"
                        autocomplete="off"
                        spellcheck="false"
                    />
                    <span class="flex h-[30px] items-center">
                        <template v-if="flags">
                            <span class="flex w-[52px] justify-center">
                                <DashCheckbox
                                    :model-value="row.build"
                                    :label="
                                        $t(':name while it builds', {
                                            name: row.name || $t('Setting'),
                                        })
                                    "
                                    @update:model-value="
                                        setFlag(row, 'build', $event)
                                    "
                                />
                            </span>
                            <span class="flex w-[52px] justify-center">
                                <DashCheckbox
                                    :model-value="row.runtime"
                                    :label="
                                        $t(':name for the running app', {
                                            name: row.name || $t('Setting'),
                                        })
                                    "
                                    @update:model-value="
                                        setFlag(row, 'runtime', $event)
                                    "
                                />
                            </span>
                        </template>
                        <button
                            type="button"
                            class="flex size-7 cursor-pointer items-center justify-center rounded-[6px] text-fg-muted hover:bg-black/5 hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) dark:hover:bg-white/5"
                            :aria-label="
                                $t('Remove :name', {
                                    name: row.name || $t('this setting'),
                                })
                            "
                            @click="remove(row.id)"
                        >
                            <X class="size-3.5" :stroke-width="1.75" />
                        </button>
                    </span>
                </li>
            </ul>
        </div>
        <p
            v-else-if="!pasting"
            class="text-[12.5px]/4 tracking-[-0.005em] text-fg-muted"
        >
            {{ $t('No settings yet.') }}
        </p>

        <div v-if="pasting" class="flex flex-col gap-2">
            <textarea
                ref="pasteBox"
                v-model="pasteText"
                rows="6"
                spellcheck="false"
                :aria-label="$t('Paste a .env')"
                :placeholder="placeholder"
                class="dash-field h-auto! py-2 font-ui-mono text-[12px]/5 outline-none"
                @keydown.meta.enter.prevent="addPasted"
                @keydown.ctrl.enter.prevent="addPasted"
            />
            <p v-if="pasteError" class="text-[12px]/4 text-status-down">
                {{ pasteError }}
            </p>
            <div class="flex gap-2">
                <DashButton
                    size="sm"
                    variant="primary"
                    :disabled="pasteText.trim() === ''"
                    :label="$t('Add these')"
                    @click="addPasted"
                />
                <DashButton
                    size="sm"
                    :label="$t('Cancel')"
                    @click="
                        pasting = false;
                        pasteText = '';
                        pasteError = null;
                    "
                />
            </div>
        </div>

        <div v-if="!pasting" class="flex flex-wrap items-center gap-2">
            <DashButton
                size="sm"
                icon="plus"
                :label="$t('Add a setting')"
                @click="addRow"
            />
            <DashButton
                size="sm"
                icon="file-fill"
                :label="$t('Paste a .env')"
                @click="openPaste"
            />
        </div>

        <p v-if="error" class="text-[12px]/4 text-status-down" role="alert">
            {{ error }}
        </p>
        <p
            v-if="badNames.size"
            class="text-[12px]/4 text-status-down"
            role="alert"
        >
            {{
                $t(
                    'Names use letters, digits and _, and don’t start with a digit.',
                )
            }}
        </p>
        <p
            v-if="compose"
            class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
        >
            {{
                $t(
                    'They fill in ${NAME} in the Compose file. Pass them to a service under environment, like DATABASE_URL: ${DATABASE_URL}.',
                )
            }}
        </p>
        <p
            v-else-if="flags && rows.length"
            class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
        >
            {{
                $t(
                    'Build: there while it builds. Runtime: there while it runs. Both are on unless you untick one.',
                )
            }}
        </p>
        <p
            v-if="empties"
            class="text-[12px]/4 tracking-[-0.005em] text-fg-muted"
        >
            {{
                $t(':count without a value are left out.', {
                    count: String(empties),
                })
            }}
        </p>
    </div>
</template>
