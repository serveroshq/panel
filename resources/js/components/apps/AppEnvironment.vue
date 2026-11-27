<script setup lang="ts">
import { X } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import type { AppDeploy, AppVariableFlags } from '@/components/apps/appTypes';
import { fieldErrors, sendJson, shortSha } from '@/components/apps/appTypes';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import DashTray from '@/components/dash/DashTray.vue';
import type { LinkedDatabase } from '@/components/service/serviceTypes';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { describe, errorMessage } from '@/lib/errors';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import appsRoutes from '@/routes/workspace/apps';

const props = defineProps<{
    deploy: AppDeploy;
    linked: LinkedDatabase;
    workspaceSlug: string;
    machineId: number;
    canManage: boolean;
    online: boolean;
}>();

const emit = defineEmits<{ deployed: [jobId: string, deploy: AppDeploy] }>();

type Flags = { build: boolean; runtime: boolean };
type Pending = {
    values: Record<string, string>;
    flags: Record<string, Flags>;
    remove: string[];
    added: string[];
};

const NAME = /^[A-Za-z_][A-Za-z0-9_]*$/;
const PUBLIC =
    /^(NEXT_PUBLIC_|VITE_|NUXT_PUBLIC_|PUBLIC_|REACT_APP_|EXPO_PUBLIC_|GATSBY_)/;

function empty(): Pending {
    return { values: {}, flags: {}, remove: [], added: [] };
}

const pending = ref<Pending>(empty());
const saving = ref(false);
const error = ref<string | null>(null);

const variables = computed(() => props.deploy.build.variables);
const known = computed(
    () => new Map(variables.value.map((variable) => [variable.name, variable])),
);
const old = computed(() => !props.deploy.canDeployBuild);
const editable = computed(() => props.canManage && !saving.value);

type Row = AppVariableFlags & {
    state: 'new' | 'changed' | 'removed' | null;
};

const rows = computed<Row[]>(() => {
    const list: Row[] = variables.value.map((variable) => {
        const flags = pending.value.flags[variable.name];
        const removed = pending.value.remove.includes(variable.name);
        const changed =
            variable.name in pending.value.values ||
            (flags !== undefined &&
                (flags.build !== variable.build ||
                    flags.runtime !== variable.runtime));

        return {
            ...variable,
            ...flags,
            state: removed ? 'removed' : changed ? 'changed' : null,
        };
    });
    for (const name of pending.value.added) {
        list.push({
            name,
            ...(pending.value.flags[name] ?? { build: true, runtime: true }),
            from: null,
            auto: false,
            state: 'new',
        });
    }

    return list;
});

const changeCount = computed(
    () => rows.value.filter((row) => row.state !== null).length,
);

function flagsOf(name: string): Flags {
    const variable = known.value.get(name);

    return (
        pending.value.flags[name] ??
        (variable
            ? { build: variable.build, runtime: variable.runtime }
            : { build: true, runtime: true })
    );
}

function flaggable(row: Row): boolean {
    return (
        editable.value &&
        !old.value &&
        !row.from &&
        !row.auto &&
        row.state !== 'removed'
    );
}

function setFlag(row: Row, which: keyof Flags, on: boolean): void {
    if (!flaggable(row)) return;
    const name = row.name;
    const next = { ...flagsOf(name), [which]: on };
    if (!next.build && !next.runtime) return;
    pending.value.flags[name] = next;
}

function remove(name: string): void {
    if (pending.value.added.includes(name)) {
        pending.value.added = pending.value.added.filter((n) => n !== name);
        delete pending.value.values[name];
        delete pending.value.flags[name];

        return;
    }
    delete pending.value.values[name];
    pending.value.remove.push(name);
}

function undo(name: string): void {
    pending.value.remove = pending.value.remove.filter((n) => n !== name);
    delete pending.value.values[name];
    delete pending.value.flags[name];
}

function discard(): void {
    pending.value = empty();
    error.value = null;
}

/* Add or change one */
const editing = ref<{ name: string; isNew: boolean } | null>(null);
const editOpen = ref(false);
const editName = ref('');
const editValue = ref('');
const editFlags = ref<Flags>({ build: true, runtime: true });
const editError = ref<string | null>(null);

watch(editOpen, (open) => {
    if (!open) editing.value = null;
});

function openAdd(): void {
    editing.value = { name: '', isNew: true };
    editName.value = '';
    editValue.value = '';
    editFlags.value = { build: true, runtime: true };
    editError.value = null;
    editOpen.value = true;
}

function openEdit(name: string): void {
    const isNew = pending.value.added.includes(name);
    editing.value = { name, isNew };
    editName.value = name;
    editValue.value = pending.value.values[name] ?? '';
    editFlags.value = { ...flagsOf(name) };
    editError.value = null;
    editOpen.value = true;
}

function toggleEdit(which: keyof Flags, on: boolean): void {
    if (old.value) return;
    const next = { ...editFlags.value, [which]: on };
    if (!next.build && !next.runtime) return;
    editFlags.value = next;
}

const editPublic = computed(
    () => PUBLIC.test(editName.value.trim()) && !editFlags.value.build,
);

function applyEdit(): void {
    const current = editing.value;
    if (!current) return;
    const name = editName.value.trim();
    if (current.isNew && current.name === '') {
        if (!NAME.test(name)) {
            editError.value = trans(
                'Use letters, digits and underscores, not starting with a digit.',
            );

            return;
        }
        if (
            (known.value.has(name) && !pending.value.remove.includes(name)) ||
            pending.value.added.includes(name)
        ) {
            editError.value = trans(
                ':name is already there. Edit it instead.',
                {
                    name,
                },
            );

            return;
        }
        if (known.value.get(name)?.from) {
            editError.value = trans(
                ':name comes from a connection. Disconnect it to set it yourself.',
                { name },
            );

            return;
        }
        if (pending.value.remove.includes(name)) {
            pending.value.remove = pending.value.remove.filter(
                (n) => n !== name,
            );
        } else {
            pending.value.added.push(name);
        }
    }
    if (editValue.value !== '' || (current.isNew && current.name === '')) {
        pending.value.values[name] = editValue.value;
    }
    const before = known.value.get(name);
    const flags = { ...editFlags.value };
    if (
        !before ||
        before.build !== flags.build ||
        before.runtime !== flags.runtime ||
        pending.value.flags[name]
    ) {
        pending.value.flags[name] = flags;
    }
    editOpen.value = false;
}

/* Paste a .env */
const pasteOpen = ref(false);
const pasteText = ref('');
const pasteReplace = ref(false);
const pasteError = ref<string | null>(null);

function openPaste(): void {
    pasteText.value = '';
    pasteReplace.value = false;
    pasteError.value = null;
    pasteOpen.value = true;
}

function body(): Record<string, unknown> {
    const out: Record<string, unknown> = {};
    if (Object.keys(pending.value.values).length) {
        out.set = { ...pending.value.values };
    }
    if (pending.value.remove.length) out.remove = [...pending.value.remove];
    const flags: Record<string, string> = {};
    for (const [name, value] of Object.entries(pending.value.flags)) {
        if (pending.value.remove.includes(name)) continue;
        const before = known.value.get(name);
        if (
            before &&
            before.build === value.build &&
            before.runtime === value.runtime
        ) {
            continue;
        }
        flags[name] =
            value.build && value.runtime
                ? 'both'
                : value.build
                  ? 'build'
                  : 'runtime';
    }
    if (Object.keys(flags).length) out.env_flags = flags;

    return out;
}

async function save(extra: Record<string, unknown> = {}): Promise<boolean> {
    if (saving.value) return false;
    saving.value = true;
    error.value = null;
    try {
        const answer = await sendJson(
            onWorkspace(
                appsRoutes.env({
                    workspace: props.workspaceSlug,
                    machine: props.machineId,
                    service: props.deploy.service,
                }),
            ).url,
            'PUT',
            { ...body(), ...extra },
        );
        if (answer.status === 422) {
            const errors = fieldErrors(answer.body);
            if (errors.dotenv && 'dotenv' in extra) {
                pasteError.value = errors.dotenv;
            } else {
                error.value =
                    Object.values(errors)[0] ??
                    describe(answer.status, answer.body);
            }

            return false;
        }
        if (!answer.ok) {
            error.value = describe(answer.status, answer.body);

            return false;
        }
        pending.value = empty();
        const deploy = answer.body.deploy as AppDeploy;
        if (typeof answer.body.jobId === 'string') {
            emit('deployed', answer.body.jobId, deploy);
        } else {
            toast.info(trans('Nothing changed.'));
        }

        return true;
    } catch (cause) {
        error.value = errorMessage(cause);

        return false;
    } finally {
        saving.value = false;
    }
}

async function savePaste(): Promise<void> {
    pasteError.value = null;
    if (await save({ dotenv: pasteText.value, replace: pasteReplace.value })) {
        pasteOpen.value = false;
    }
}

const what = computed(() => {
    const live = shortSha(props.deploy.liveCommit);
    const app = props.deploy.service;

    return live
        ? trans(
              'Saving deploys :app again on :sha with these settings. Build settings get a fresh build.',
              { app, sha: live },
          )
        : trans('Saving deploys :app again with these settings.', { app });
});

const flagTitle = computed(() =>
    old.value ? (props.deploy.update ?? undefined) : undefined,
);
</script>

<template>
    <div class="flex flex-col gap-3">
        <DashTray icon="key-fill" :title="$t('Environment variables')">
            <template v-if="canManage" #actions>
                <DashButton
                    size="sm"
                    :label="$t('Paste .env')"
                    :disabled="!editable"
                    @click="openPaste"
                />
                <DashButton
                    size="sm"
                    :label="$t('Add variable')"
                    :disabled="!editable"
                    @click="openAdd"
                />
            </template>

            <div class="px-[18px] pt-1 pb-1">
                <div
                    v-for="row in rows"
                    :key="row.name"
                    class="flex min-h-11 flex-wrap items-center gap-x-3 gap-y-1 border-b-[0.5px] border-edge-tabs py-1.5 last:border-b-0"
                >
                    <span
                        class="w-[220px] shrink-0 truncate font-ui-mono text-[12px]/4 max-sm:w-full"
                        :class="
                            row.state === 'removed'
                                ? 'text-fg-faint line-through'
                                : 'text-fg-primary'
                        "
                        :title="row.name"
                        >{{ row.name }}</span
                    >
                    <span
                        class="min-w-0 flex-1 truncate text-[12px]/4 tracking-[-0.005em]"
                    >
                        <template v-if="row.from">
                            <span class="text-fg-secondary">{{
                                $t('From') + ' '
                            }}</span>
                            <a
                                v-if="linked?.url && linked.name === row.from"
                                :href="linked.url"
                                class="text-fg-secondary underline decoration-from-font underline-offset-2 hover:text-fg-primary"
                                >{{
                                    $t(':name on :machine', {
                                        name: row.from,
                                        machine: linked.machine,
                                    })
                                }}</a
                            >
                            <span v-else class="text-fg-secondary">{{
                                row.from
                            }}</span>
                        </template>
                        <span v-else-if="row.auto" class="text-fg-muted">{{
                            $t('Set by ServerOS')
                        }}</span>
                        <span
                            v-else-if="row.name in pending.values"
                            class="font-medium text-dash-blue"
                            >{{
                                row.state === 'new'
                                    ? $t('New')
                                    : $t('New value')
                            }}</span
                        >
                        <span
                            v-else-if="row.state === 'removed'"
                            class="font-medium text-status-down"
                            >{{ $t('Removed') }}</span
                        >
                        <span
                            v-else
                            class="font-ui-mono text-fg-muted"
                            :title="$t('Values are never shown here')"
                            >••••••••••••</span
                        >
                        <span
                            v-if="
                                PUBLIC.test(row.name) &&
                                !row.build &&
                                row.state !== 'removed'
                            "
                            class="ml-2 text-fg-muted"
                            >{{ $t('Public settings need Build') }}</span
                        >
                    </span>
                    <span
                        class="flex shrink-0 items-center gap-3 text-[12px]/4 text-fg-secondary"
                        :title="flagTitle"
                    >
                        <label class="flex items-center gap-1.5">
                            <DashCheckbox
                                :model-value="row.build"
                                :label="$t('Build')"
                                :class="
                                    !flaggable(row) &&
                                    'pointer-events-none opacity-50'
                                "
                                @update:model-value="
                                    (on) => setFlag(row, 'build', on)
                                "
                            />
                            {{ $t('Build') }}
                        </label>
                        <label class="flex items-center gap-1.5">
                            <DashCheckbox
                                :model-value="row.runtime"
                                :label="$t('Runtime')"
                                :class="
                                    !flaggable(row) &&
                                    'pointer-events-none opacity-50'
                                "
                                @update:model-value="
                                    (on) => setFlag(row, 'runtime', on)
                                "
                            />
                            {{ $t('Runtime') }}
                        </label>
                    </span>
                    <span
                        v-if="canManage"
                        class="flex w-[92px] shrink-0 items-center justify-end gap-1"
                    >
                        <template v-if="!row.from && !row.auto">
                            <DashButton
                                v-if="row.state === 'removed'"
                                size="sm"
                                :label="$t('Undo')"
                                :disabled="!editable"
                                @click="undo(row.name)"
                            />
                            <template v-else>
                                <DashButton
                                    size="sm"
                                    :label="$t('Edit')"
                                    :disabled="!editable"
                                    @click="openEdit(row.name)"
                                />
                                <button
                                    type="button"
                                    class="flex size-7 cursor-pointer items-center justify-center rounded-md text-fg-muted hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:cursor-default disabled:opacity-50"
                                    :aria-label="
                                        $t('Remove :name', { name: row.name })
                                    "
                                    :disabled="!editable"
                                    @click="remove(row.name)"
                                >
                                    <X class="size-3.5" :stroke-width="1.75" />
                                </button>
                            </template>
                        </template>
                    </span>
                </div>
                <p
                    v-if="rows.length === 0"
                    class="py-3 text-[13px]/5 text-fg-muted"
                >
                    {{
                        $t(
                            'No variables yet. Add one, or paste a .env file. Each is given to the build and the running app unless you untick one.',
                        )
                    }}
                </p>
            </div>
        </DashTray>

        <div
            v-if="canManage && (changeCount > 0 || error)"
            class="dash-card sticky bottom-4 z-10 flex flex-wrap items-center gap-x-4 gap-y-2 px-[18px] py-3 shadow-lg"
        >
            <p class="min-w-0 flex-1 text-[12.5px]/[18px] text-fg-secondary">
                <span
                    v-if="error"
                    class="block text-status-down"
                    role="alert"
                    >{{ error }}</span
                >
                <template v-else>
                    {{
                        changeCount === 1
                            ? $t('1 change.')
                            : $t(':count changes.', {
                                  count: String(changeCount),
                              })
                    }}
                    {{ what }}
                </template>
            </p>
            <DashButton
                size="sm"
                :label="$t('Discard')"
                :disabled="saving"
                @click="discard"
            />
            <DashButton
                size="sm"
                variant="primary"
                :label="$t('Save and deploy')"
                :loading="saving"
                :disabled="
                    changeCount === 0 || !online || deploy.deploying !== null
                "
                :title="
                    deploy.deploying
                        ? $t('Wait for the deploy that’s running to finish.')
                        : undefined
                "
                @click="save()"
            />
        </div>
    </div>

    <Dialog v-model:open="editOpen">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-lg"
        >
            <DialogHeader>
                <DialogTitle>{{
                    editing?.name ? editing.name : $t('Add a variable')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        editing?.name && !editing.isNew
                            ? $t(
                                  'Values are never shown. Type a new one to replace it, or leave it empty to keep it.',
                              )
                            : $t(
                                  'It’s added when you save. Values can run over several lines.',
                              )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="applyEdit">
                <div v-if="!editing?.name" class="grid gap-2">
                    <label for="env-name" class="text-[13px]/5">{{
                        $t('Name')
                    }}</label>
                    <div class="dash-field">
                        <input
                            id="env-name"
                            v-model="editName"
                            class="font-ui-mono"
                            placeholder="API_KEY"
                            autocomplete="off"
                            spellcheck="false"
                            maxlength="128"
                            autofocus
                        />
                    </div>
                </div>
                <div class="grid gap-2">
                    <label for="env-value" class="text-[13px]/5">{{
                        $t('Value')
                    }}</label>
                    <div class="dash-field h-auto! items-start py-2">
                        <textarea
                            id="env-value"
                            v-model="editValue"
                            rows="4"
                            class="min-h-20 resize-y font-ui-mono text-[12.5px]/5"
                            :placeholder="
                                editing?.name && !editing.isNew
                                    ? $t('Leave empty to keep the value it has')
                                    : ''
                            "
                            autocomplete="off"
                            spellcheck="false"
                        />
                    </div>
                </div>
                <div class="flex flex-col gap-2" :title="flagTitle">
                    <label
                        class="flex items-start gap-2.5 text-[13px]/5 text-fg-secondary"
                    >
                        <DashCheckbox
                            :model-value="editFlags.runtime"
                            class="mt-0.5"
                            :class="
                                (old ||
                                    (editFlags.runtime && !editFlags.build)) &&
                                'pointer-events-none opacity-50'
                            "
                            :label="$t('Runtime')"
                            @update:model-value="
                                (on) => toggleEdit('runtime', on)
                            "
                        />
                        <span class="flex flex-col">
                            <span class="text-fg-primary">{{
                                $t('The running app')
                            }}</span>
                            <span class="text-[12px]/4 text-fg-muted">{{
                                $t('Set in its container as it runs.')
                            }}</span>
                        </span>
                    </label>
                    <label
                        class="flex items-start gap-2.5 text-[13px]/5 text-fg-secondary"
                    >
                        <DashCheckbox
                            :model-value="editFlags.build"
                            class="mt-0.5"
                            :class="
                                (old ||
                                    (editFlags.build && !editFlags.runtime)) &&
                                'pointer-events-none opacity-50'
                            "
                            :label="$t('Build')"
                            @update:model-value="
                                (on) => toggleEdit('build', on)
                            "
                        />
                        <span class="flex flex-col">
                            <span class="text-fg-primary">{{
                                $t('The build')
                            }}</span>
                            <span class="text-[12px]/4 text-fg-muted">{{
                                $t(
                                    'Readable while it builds, as a Dockerfile ARG or by Railpack.',
                                )
                            }}</span>
                        </span>
                    </label>
                    <p v-if="editPublic" class="text-[12px]/4 text-fg-muted">
                        {{
                            $t(
                                'Settings like this one are built into the page, so the build needs it.',
                            )
                        }}
                    </p>
                </div>
                <p
                    v-if="editError"
                    class="text-[12px]/4 text-status-down"
                    role="alert"
                >
                    {{ editError }}
                </p>
                <div class="flex justify-end gap-2">
                    <DashButton
                        size="sm"
                        :label="$t('Cancel')"
                        @click="editOpen = false"
                    />
                    <DashButton
                        size="sm"
                        type="submit"
                        variant="primary"
                        :label="editing?.name ? $t('Done') : $t('Add')"
                        :disabled="!editing?.name && !editName.trim()"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="pasteOpen">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-2xl"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('Paste a .env file') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'Lines like NAME=value. Quotes, export and # comments work, and quoted values can run over several lines. Saving deploys :app again.',
                            { app: deploy.service },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="savePaste">
                <div class="dash-field h-auto! items-start py-2">
                    <textarea
                        v-model="pasteText"
                        rows="12"
                        class="min-h-56 resize-y font-ui-mono text-[12.5px]/5"
                        :aria-label="$t('.env file')"
                        placeholder="APP_ENV=production&#10;APP_KEY=base64:…"
                        autocomplete="off"
                        spellcheck="false"
                        autofocus
                    />
                </div>
                <label
                    class="flex items-start gap-2.5 text-[13px]/5 text-fg-secondary"
                >
                    <DashCheckbox v-model="pasteReplace" class="mt-0.5" />
                    <span class="flex flex-col">
                        <span class="text-fg-primary">{{
                            $t('Remove the ones it leaves out')
                        }}</span>
                        <span class="text-[12px]/4 text-fg-muted">{{
                            $t(
                                'Off: variables you don’t paste stay as they are. Ones from a connection always stay.',
                            )
                        }}</span>
                    </span>
                </label>
                <p v-if="changeCount > 0" class="text-[12px]/4 text-fg-muted">
                    {{
                        $t('Your other changes on this page are saved with it.')
                    }}
                </p>
                <p
                    v-if="pasteError"
                    class="text-[12px]/4 text-status-down"
                    role="alert"
                >
                    {{ pasteError }}
                </p>
                <div class="flex justify-end gap-2">
                    <DashButton
                        size="sm"
                        :label="$t('Cancel')"
                        @click="pasteOpen = false"
                    />
                    <DashButton
                        size="sm"
                        type="submit"
                        variant="primary"
                        :label="$t('Save and deploy')"
                        :loading="saving"
                        :disabled="
                            !pasteText.trim() ||
                            !online ||
                            deploy.deploying !== null
                        "
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
