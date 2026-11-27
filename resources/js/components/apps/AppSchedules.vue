<script setup lang="ts">
import { X } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import type { AppDeploy, Schedule } from '@/components/apps/appTypes';
import { fieldErrors, rowStatus, sendJson } from '@/components/apps/appTypes';
import DashButton from '@/components/dash/DashButton.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import DashTable from '@/components/dash/DashTable.vue';
import DashTd from '@/components/dash/DashTd.vue';
import DashTh from '@/components/dash/DashTh.vue';
import DashTr from '@/components/dash/DashTr.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import { describe, errorMessage } from '@/lib/errors';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import { edit as securityEdit } from '@/routes/security';
import appsRoutes from '@/routes/workspace/apps';
import consoleRoutes from '@/routes/workspace/console';

const props = defineProps<{
    deploy: AppDeploy;
    workspaceSlug: string;
    machineId: number;
    canManage: boolean;
    online: boolean;
    running: boolean;
    consoleLock: {
        twoFactorEnabled: boolean;
        unlockedUntil: string | null;
        minutes: number;
    };
}>();

const emit = defineEmits<{
    log: [jobId: string, title: string];
    saved: [schedules: Schedule[]];
    unlocked: [];
}>();

type Draft = {
    id?: string;
    name: string | null;
    command: string;
    cron: string;
    enabled: boolean;
    timeout: number;
};

const list = ref<Schedule[]>(props.deploy.schedules);
const saving = ref(false);
const needs = ref<'two_factor' | 'unlock' | null>(null);
const unlockCode = ref('');
const unlockError = ref<string | null>(null);
const unlocking = ref(false);
let retry: Draft[] | null = null;

watch(
    () => props.deploy.schedules,
    (next) => {
        if (!saving.value) list.value = next;
    },
);

function draftOf(schedule: Schedule): Draft {
    return {
        id: schedule.id,
        name: schedule.name,
        command: schedule.command,
        cron: schedule.cron,
        enabled: schedule.enabled,
        timeout: schedule.timeout,
    };
}

/** Saves the whole list. Gives the field errors, if any. */
async function put(next: Draft[]): Promise<Record<string, string> | null> {
    saving.value = true;
    try {
        const answer = await sendJson(
            onWorkspace(
                appsRoutes.schedules({
                    workspace: props.workspaceSlug,
                    machine: props.machineId,
                    service: props.deploy.service,
                }),
            ).url,
            'PUT',
            { schedules: next },
        );
        if (answer.ok) {
            list.value = (answer.body.schedules ?? []) as Schedule[];
            needs.value = null;
            retry = null;
            emit('saved', list.value);

            return null;
        }
        if (answer.body.code === 'two_factor_required') {
            needs.value = 'two_factor';
            retry = next;

            return {};
        }
        if (answer.body.code === 'console_locked') {
            needs.value = 'unlock';
            retry = next;

            return {};
        }
        if (answer.status === 422) return fieldErrors(answer.body);
        toast.error(describe(answer.status, answer.body));

        return {};
    } catch (cause) {
        toast.error(errorMessage(cause));

        return {};
    } finally {
        saving.value = false;
    }
}

async function unlock(): Promise<void> {
    if (unlockCode.value.length !== 6 || unlocking.value) return;
    unlocking.value = true;
    unlockError.value = null;
    try {
        const answer = await sendJson(
            onWorkspace(consoleRoutes.unlock(props.workspaceSlug)).url,
            'POST',
            { code: unlockCode.value },
        );
        unlockCode.value = '';
        if (!answer.ok) {
            unlockError.value = describe(answer.status, answer.body);

            return;
        }
        emit('unlocked');
        if (retry) {
            const pendingList = retry;
            const errors = await put(pendingList);
            if (errors === null) {
                editOpen.value = false;
                toast.success(trans('Scheduled commands saved.'));
            }
        }
    } catch (cause) {
        unlockError.value = errorMessage(cause);
    } finally {
        unlocking.value = false;
    }
}

/* Add or edit one */
const editOpen = ref(false);
const editing = ref<Draft>({
    name: null,
    command: '',
    cron: '* * * * *',
    enabled: true,
    timeout: 300,
});
const editErrors = ref<Record<string, string>>({});

watch(editOpen, (open) => {
    if (!open && needs.value === 'unlock') {
        needs.value = null;
        retry = null;
    }
});

function openAdd(): void {
    editing.value = {
        name: null,
        command: '',
        cron: '* * * * *',
        enabled: true,
        timeout: 300,
    };
    editErrors.value = {};
    needs.value = null;
    editOpen.value = true;
}

function openEdit(schedule: Schedule): void {
    editing.value = draftOf(schedule);
    editErrors.value = {};
    needs.value = null;
    editOpen.value = true;
}

const editIndex = computed(() =>
    editing.value.id
        ? list.value.findIndex((s) => s.id === editing.value.id)
        : list.value.length,
);

async function saveEdit(): Promise<void> {
    const next = list.value.map(draftOf);
    const draft = {
        ...editing.value,
        name: editing.value.name?.trim() || null,
        command: editing.value.command.trim(),
        cron: editing.value.cron.trim(),
        timeout: Number(editing.value.timeout) || 300,
    };
    if (draft.id) next[editIndex.value] = draft;
    else next.push(draft);
    editErrors.value = {};
    const errors = await put(next);
    if (errors === null) {
        editOpen.value = false;
        toast.success(trans('Scheduled commands saved.'));

        return;
    }
    const prefix = `schedules.${editIndex.value}.`;
    editErrors.value = Object.fromEntries(
        Object.entries(errors).map(([key, message]) => [
            key.startsWith(prefix) ? key.slice(prefix.length) : 'form',
            message,
        ]),
    );
}

async function toggle(schedule: Schedule, on: boolean): Promise<void> {
    const next = list.value.map((s) =>
        s.id === schedule.id ? { ...draftOf(s), enabled: on } : draftOf(s),
    );
    const errors = await put(next);
    if (errors !== null && needs.value) {
        editing.value = { ...draftOf(schedule), enabled: on };
        editErrors.value = {};
        editOpen.value = true;
    } else if (errors !== null) {
        toast.error(Object.values(errors)[0] ?? trans('Couldn’t save it.'));
    }
}

const removing = ref<Schedule | null>(null);
const removeOpen = computed({
    get: () => removing.value !== null,
    set: (open: boolean) => {
        if (!open) removing.value = null;
    },
});

async function remove(): Promise<void> {
    const target = removing.value;
    if (!target) return;
    const errors = await put(
        list.value.filter((s) => s.id !== target.id).map(draftOf),
    );
    if (errors === null) {
        removing.value = null;
    } else if (needs.value) {
        removing.value = null;
        editOpen.value = true;
    }
}

const presets = computed(() => [
    { value: '* * * * *', label: trans('Every minute') },
    { value: '*/5 * * * *', label: trans('5 minutes') },
    { value: '@hourly', label: trans('Hourly') },
    { value: '@daily', label: trans('Daily') },
    { value: '@weekly', label: trans('Weekly') },
]);

const preset = computed({
    get: () =>
        presets.value.find((p) => p.value === editing.value.cron.trim())
            ?.value ?? '',
    set: (value: string) => {
        if (value) editing.value.cron = value;
    },
});

function describeCron(cron: string): string | null {
    const value = cron.trim();
    const fixed: Record<string, string> = {
        '* * * * *': trans('Every minute'),
        '@hourly': trans('Every hour'),
        '0 * * * *': trans('Every hour'),
        '@daily': trans('Every day at 00:00 UTC'),
        '@midnight': trans('Every day at 00:00 UTC'),
        '@weekly': trans('Every Sunday at 00:00 UTC'),
        '@monthly': trans('On the 1st at 00:00 UTC'),
        '@yearly': trans('On 1 January at 00:00 UTC'),
        '@annually': trans('On 1 January at 00:00 UTC'),
    };
    if (fixed[value]) return fixed[value];
    let match = /^\*\/(\d+) \* \* \* \*$/.exec(value);
    if (match) {
        return trans('Every :count minutes', { count: match[1] });
    }
    match = /^(\d+) (\d+) \* \* \*$/.exec(value);
    if (match) {
        return trans('Every day at :time UTC', {
            time: `${match[2].padStart(2, '0')}:${match[1].padStart(2, '0')}`,
        });
    }

    return null;
}

function lastRun(schedule: Schedule): {
    status: ReturnType<typeof rowStatus>['status'];
    label: string;
} | null {
    const run = schedule.lastRun;
    if (!run) return null;
    if (
        run.state === 'succeeded' &&
        run.exitCode !== null &&
        run.exitCode !== 0
    ) {
        return {
            status: 'down',
            label: trans('Exit :code', { code: String(run.exitCode) }),
        };
    }

    return rowStatus({ state: run.state });
}

const unavailable = computed(() => props.deploy.schedulesUnavailable);
</script>

<template>
    <section class="dash-card flex flex-col overflow-hidden">
        <div class="flex flex-wrap items-center gap-3 px-[18px] py-3">
            <div class="flex min-w-0 flex-1 flex-col">
                <h2
                    class="text-[14px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t('Scheduled commands') }}
                </h2>
                <p class="text-[13px]/5 text-fg-tertiary">
                    {{
                        unavailable ??
                        $t(
                            'Each runs in :app’s live container when it’s due, as the person who saved it, never on top of its own last run. Times are UTC.',
                            { app: deploy.service },
                        )
                    }}
                </p>
            </div>
            <DashButton
                v-if="canManage && !unavailable"
                size="sm"
                :label="$t('Add command')"
                :disabled="saving || list.length >= 20"
                @click="openAdd"
            />
        </div>

        <p
            v-if="!unavailable && canManage && !consoleLock.twoFactorEnabled"
            class="border-t-[0.5px] border-edge-row px-[18px] py-2.5 text-[12.5px]/[18px] text-fg-secondary"
        >
            {{
                $t(
                    'They run commands on the machine as you, so saving them needs two-factor authentication on your account and the console unlocked, as the console does.',
                )
            }}
            <a
                :href="securityEdit().url"
                class="text-fg-primary underline decoration-from-font underline-offset-2"
                >{{ $t('Set up two-factor') }}</a
            >
        </p>
        <p
            v-else-if="!unavailable && !running && list.length"
            class="border-t-[0.5px] border-edge-row px-[18px] py-2.5 text-[12.5px]/[18px] text-fg-secondary"
        >
            {{
                $t(':app isn’t running, so these wait until a deploy works.', {
                    app: deploy.service,
                })
            }}
        </p>

        <DashTable v-if="!unavailable" class="border-t-[0.5px] border-edge-row">
            <template #head>
                <DashTh icon="terminal-fill" fill first>{{
                    $t('Command')
                }}</DashTh>
                <DashTh icon="history" :width="200">{{
                    $t('Schedule')
                }}</DashTh>
                <DashTh icon="activity-fill" :width="200">{{
                    $t('Last run')
                }}</DashTh>
                <DashTh icon="update-fill" :width="130">{{
                    $t('Next run')
                }}</DashTh>
                <DashTh :width="canManage ? 150 : 80" class="border-r-0!">{{
                    $t('On')
                }}</DashTh>
            </template>
            <DashTr v-for="schedule in list" :key="schedule.id">
                <DashTd first :height="52">
                    <span class="flex min-w-0 flex-col">
                        <span
                            v-if="schedule.name"
                            class="truncate text-fg-primary"
                            >{{ schedule.name }}</span
                        >
                        <span
                            class="max-w-[420px] truncate font-ui-mono text-[12px]/4"
                            :class="
                                schedule.name
                                    ? 'text-fg-muted'
                                    : 'text-fg-primary'
                            "
                            :title="schedule.command"
                            >{{ schedule.command }}</span
                        >
                    </span>
                </DashTd>
                <DashTd :height="52">
                    <span class="flex min-w-0 flex-col">
                        <span
                            class="font-ui-mono text-[12px]/4 text-fg-primary"
                            >{{ schedule.cron }}</span
                        >
                        <span
                            v-if="describeCron(schedule.cron)"
                            class="text-[12px]/4 text-fg-muted"
                            >{{ describeCron(schedule.cron) }}</span
                        >
                    </span>
                </DashTd>
                <DashTd :height="52">
                    <button
                        v-if="schedule.lastRun && lastRun(schedule)"
                        type="button"
                        class="flex cursor-pointer items-center gap-2 rounded-md focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        :title="$t('Open its output')"
                        @click="
                            emit(
                                'log',
                                schedule.lastRun.jobId,
                                schedule.name ?? schedule.command,
                            )
                        "
                    >
                        <StatusBadge
                            :status="lastRun(schedule)!.status"
                            :label="lastRun(schedule)!.label"
                        />
                        <span class="text-[12px]/4 text-fg-muted">{{
                            timeAgo(schedule.lastRun.at)
                        }}</span>
                    </button>
                    <span v-else class="text-fg-muted">{{
                        $t('Not yet')
                    }}</span>
                </DashTd>
                <DashTd :height="52">
                    <span
                        class="text-fg-secondary"
                        :title="
                            schedule.nextRunAt
                                ? new Date(schedule.nextRunAt).toLocaleString()
                                : undefined
                        "
                        >{{
                            schedule.enabled && schedule.nextRunAt
                                ? timeAgo(schedule.nextRunAt)
                                : '–'
                        }}</span
                    >
                </DashTd>
                <DashTd :height="52" class="border-r-0!">
                    <DashSwitch
                        :model-value="schedule.enabled"
                        size="sm"
                        :label="$t('Run it on schedule')"
                        :disabled="!canManage || saving"
                        @update:model-value="(on) => toggle(schedule, on)"
                    />
                    <template v-if="canManage">
                        <span class="grow" />
                        <DashButton
                            size="sm"
                            :label="$t('Edit')"
                            :disabled="saving"
                            @click="openEdit(schedule)"
                        />
                        <button
                            type="button"
                            class="flex size-7 shrink-0 cursor-pointer items-center justify-center rounded-md text-fg-muted hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:cursor-default disabled:opacity-50"
                            :aria-label="$t('Remove')"
                            :disabled="saving"
                            @click="removing = schedule"
                        >
                            <X class="size-3.5" :stroke-width="1.75" />
                        </button>
                    </template>
                </DashTd>
            </DashTr>
            <tr v-if="list.length === 0">
                <td colspan="5" class="px-5 py-4 text-[13px]/5 text-fg-muted">
                    {{
                        $t(
                            'None yet. Add one to run something like php artisan schedule:run every minute.',
                        )
                    }}
                </td>
            </tr>
        </DashTable>
    </section>

    <Dialog v-model:open="editOpen">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-lg"
        >
            <DialogHeader>
                <DialogTitle>{{
                    needs === 'unlock'
                        ? $t('Unlock the console')
                        : needs === 'two_factor'
                          ? $t('Two-factor authentication needed')
                          : editing.id
                            ? $t('Edit scheduled command')
                            : $t('Schedule a command')
                }}</DialogTitle>
                <DialogDescription :class="needs && 'sr-only'">
                    {{
                        $t(
                            'It runs with sh -c in :app’s live container, as you.',
                            { app: deploy.service },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>

            <form
                v-if="needs === 'unlock'"
                class="flex flex-col items-center gap-4 py-2 text-center"
                @submit.prevent="unlock"
            >
                <p class="text-[13px]/5 text-fg-secondary">
                    {{
                        $t(
                            'Unlock the console to save this. Enter the 6-digit code from your authenticator app. It stays unlocked for :minutes minutes on this device.',
                            { minutes: String(consoleLock.minutes) },
                        )
                    }}
                </p>
                <InputOTP
                    v-model="unlockCode"
                    :maxlength="6"
                    :disabled="unlocking"
                    autofocus
                    @complete="unlock"
                >
                    <InputOTPGroup>
                        <InputOTPSlot
                            v-for="index in 6"
                            :key="index"
                            :index="index - 1"
                        />
                    </InputOTPGroup>
                </InputOTP>
                <p
                    v-if="unlockError"
                    class="text-[12px]/4 text-status-down"
                    role="alert"
                >
                    {{ unlockError }}
                </p>
                <div class="flex justify-end gap-2 self-stretch">
                    <DashButton
                        size="sm"
                        :label="$t('Back')"
                        @click="needs = null"
                    />
                    <DashButton
                        size="sm"
                        type="submit"
                        variant="primary"
                        :label="$t('Unlock and save')"
                        :loading="unlocking || saving"
                        :disabled="unlockCode.length !== 6"
                    />
                </div>
            </form>

            <div v-else-if="needs === 'two_factor'" class="flex flex-col gap-4">
                <p class="text-[13px]/5 text-fg-secondary">
                    {{
                        $t(
                            'Scheduled commands run on the machine as you, so they need two-factor authentication on your account, like the console. Turn it on, then save this again.',
                        )
                    }}
                </p>
                <div class="flex justify-end gap-2">
                    <DashButton
                        size="sm"
                        :label="$t('Back')"
                        @click="needs = null"
                    />
                    <DashButton
                        size="sm"
                        variant="primary"
                        :label="$t('Set up two-factor')"
                        :href="securityEdit().url"
                    />
                </div>
            </div>

            <form v-else class="flex flex-col gap-4" @submit.prevent="saveEdit">
                <div class="grid gap-2">
                    <label for="schedule-command" class="text-[13px]/5">{{
                        $t('Command')
                    }}</label>
                    <div class="dash-field h-auto! items-start py-2">
                        <textarea
                            id="schedule-command"
                            v-model="editing.command"
                            rows="2"
                            class="resize-y font-ui-mono text-[12.5px]/5"
                            placeholder="php artisan schedule:run"
                            spellcheck="false"
                            autocomplete="off"
                            maxlength="2000"
                            autofocus
                        />
                    </div>
                    <p
                        v-if="editErrors.command"
                        class="text-[12px]/4 text-status-down"
                    >
                        {{ editErrors.command }}
                    </p>
                </div>
                <div class="grid gap-2">
                    <label for="schedule-cron" class="text-[13px]/5">{{
                        $t('When')
                    }}</label>
                    <FilterPills
                        v-model="preset"
                        :options="presets"
                        :label="$t('Common schedules')"
                        class="self-start max-sm:max-w-full max-sm:overflow-x-auto"
                    />
                    <div class="dash-field">
                        <input
                            id="schedule-cron"
                            v-model="editing.cron"
                            class="font-ui-mono"
                            placeholder="*/5 * * * *"
                            spellcheck="false"
                            autocomplete="off"
                            maxlength="120"
                        />
                        <span
                            v-if="describeCron(editing.cron)"
                            class="shrink-0 text-[12px] text-fg-muted"
                            >{{ describeCron(editing.cron) }}</span
                        >
                    </div>
                    <p
                        v-if="editErrors.cron"
                        class="text-[12px]/4 text-status-down"
                    >
                        {{ editErrors.cron }}
                    </p>
                    <p v-else class="text-[12px]/4 text-fg-muted">
                        {{
                            $t(
                                'Five cron fields in UTC, or @hourly, @daily, @weekly, @monthly.',
                            )
                        }}
                    </p>
                </div>
                <div class="grid grid-cols-[1fr_120px] gap-3">
                    <div class="grid gap-2">
                        <label for="schedule-name" class="text-[13px]/5">{{
                            $t('Name (optional)')
                        }}</label>
                        <div class="dash-field">
                            <input
                                id="schedule-name"
                                :value="editing.name ?? ''"
                                :placeholder="$t('Laravel scheduler')"
                                maxlength="80"
                                autocomplete="off"
                                @input="
                                    editing.name = (
                                        $event.target as HTMLInputElement
                                    ).value
                                "
                            />
                        </div>
                    </div>
                    <div class="grid gap-2">
                        <label for="schedule-timeout" class="text-[13px]/5">{{
                            $t('Stop after')
                        }}</label>
                        <div class="dash-field">
                            <input
                                id="schedule-timeout"
                                v-model.number="editing.timeout"
                                class="font-ui-mono"
                                inputmode="numeric"
                                autocomplete="off"
                            />
                            <span class="text-[12px] text-fg-muted">{{
                                $t('s')
                            }}</span>
                        </div>
                    </div>
                </div>
                <p
                    v-if="
                        editErrors.timeout || editErrors.name || editErrors.form
                    "
                    class="text-[12px]/4 text-status-down"
                >
                    {{
                        editErrors.timeout ?? editErrors.name ?? editErrors.form
                    }}
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
                        :label="$t('Save')"
                        :loading="saving"
                        :disabled="
                            !editing.command.trim() || !editing.cron.trim()
                        "
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>

    <Dialog v-model:open="removeOpen">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    $t('Remove this scheduled command?')
                }}</DialogTitle>
                <DialogDescription>
                    <span class="font-ui-mono">{{ removing?.command }}</span>
                    {{
                        $t('stops running. Its past runs stay in the history.')
                    }}
                </DialogDescription>
            </DialogHeader>
            <div class="flex justify-end gap-2">
                <DashButton
                    size="sm"
                    :label="$t('Cancel')"
                    @click="removing = null"
                />
                <DashButton
                    size="sm"
                    variant="danger"
                    :label="$t('Remove')"
                    :loading="saving"
                    @click="remove"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
