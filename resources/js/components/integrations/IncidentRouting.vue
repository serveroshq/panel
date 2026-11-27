<script setup lang="ts">
import { router, useForm } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import { getJson } from '@/components/integrations/incidents';
import type {
    LinearOptions,
    LinearRouting,
    PagerDutyRouting,
} from '@/components/integrations/incidents';
import { SelectMenu } from '@/components/ui/select';
import { onWorkspace } from '@/lib/workspaceRoute';
import integrationRoutes from '@/routes/workspace/integrations';

const props = defineProps<{
    provider: 'pagerduty' | 'linear';
    routing: PagerDutyRouting | LinearRouting;
    workspaceSlug: string;
    integrationId: number;
    disabled?: boolean;
}>();

const args = computed(() => ({
    workspace: props.workspaceSlug,
    integration: props.integrationId,
}));
const pd = computed(() =>
    props.provider === 'pagerduty' ? (props.routing as PagerDutyRouting) : null,
);
const ln = computed(() =>
    props.provider === 'linear' ? (props.routing as LinearRouting) : null,
);

const pdForm = useForm({
    monitors: pd.value?.monitors ?? 'all',
    monitor_ids: [...(pd.value?.monitor_ids ?? [])],
    machines: pd.value?.machines ?? true,
    cves: pd.value?.cves ?? true,
});
const lnForm = useForm({
    team: ln.value?.team?.id ?? '',
    project: ln.value?.project?.id ?? '',
    label: ln.value?.label?.id ?? '',
    monitors: ln.value?.monitors ?? true,
    minutes: ln.value?.minutes ?? 10,
    machines: ln.value?.machines ?? true,
    cves: ln.value?.cves ?? true,
});

watch(
    () => props.routing,
    () => {
        if (pd.value) {
            pdForm.defaults({
                monitors: pd.value.monitors,
                monitor_ids: [...pd.value.monitor_ids],
                machines: pd.value.machines,
                cves: pd.value.cves,
            });
            pdForm.reset();
        }
        if (ln.value) {
            lnForm.defaults({
                team: ln.value.team?.id ?? '',
                project: ln.value.project?.id ?? '',
                label: ln.value.label?.id ?? '',
                monitors: ln.value.monitors,
                minutes: ln.value.minutes,
                machines: ln.value.machines,
                cves: ln.value.cves,
            });
            lnForm.reset();
        }
    },
);

const monitorChoices = computed(() => [
    { value: 'all' as const, label: trans('All') },
    { value: 'some' as const, label: trans('Some') },
    { value: 'none' as const, label: trans('None') },
]);

function toggleMonitor(id: number, on: boolean): void {
    pdForm.monitor_ids = on
        ? [...new Set([...pdForm.monitor_ids, id])]
        : pdForm.monitor_ids.filter((m) => m !== id);
}

const options = ref<LinearOptions | null>(null);
const optionsError = ref<string | null>(null);
const loadingOptions = ref(false);

async function loadOptions(team: string): Promise<void> {
    loadingOptions.value = true;
    optionsError.value = null;
    try {
        options.value = await getJson<LinearOptions>(
            onWorkspace(
                integrationRoutes.options(args.value, {
                    query: team ? { team } : {},
                }),
            ).url,
        );
    } catch (e) {
        optionsError.value = e instanceof Error ? e.message : String(e);
    } finally {
        loadingOptions.value = false;
    }
}

onMounted(() => {
    if (props.provider === 'linear') void loadOptions(lnForm.team);
});

function pickTeam(team: string | number | null): void {
    lnForm.team = String(team ?? '');
    lnForm.project = '';
    lnForm.label = '';
    void loadOptions(lnForm.team);
}

const teamOptions = computed(() =>
    (options.value?.teams ?? (ln.value?.team ? [ln.value.team] : [])).map(
        (t) => ({
            value: t.id,
            label: t.key ? `${t.name} (${t.key})` : t.name,
        }),
    ),
);
const projectOptions = computed(() => [
    { value: '', label: trans('No project') },
    ...(
        options.value?.projects ?? (ln.value?.project ? [ln.value.project] : [])
    ).map((p) => ({ value: p.id, label: p.name })),
]);
const labelOptions = computed(() => [
    { value: '', label: trans('No label') },
    ...(options.value?.labels ?? (ln.value?.label ? [ln.value.label] : [])).map(
        (l) => ({ value: l.id, label: l.name }),
    ),
]);

const form = computed(() => (props.provider === 'pagerduty' ? pdForm : lnForm));

function save(): void {
    const url = onWorkspace(integrationRoutes.routing.update(args.value)).url;
    if (props.provider === 'pagerduty') {
        pdForm.put(url, { preserveScroll: true });
    } else {
        lnForm
            .transform((data) => ({
                ...data,
                project: data.project || null,
                label: data.label || null,
            }))
            .put(url, { preserveScroll: true });
    }
}

const testing = ref(false);

function sendTest(): void {
    router.post(
        onWorkspace(integrationRoutes.test(args.value)).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (testing.value = true),
            onFinish: () => (testing.value = false),
        },
    );
}

const errors = computed(() => form.value.errors as Record<string, string>);
const firstError = computed(() => Object.values(errors.value)[0] ?? null);

const row =
    'flex items-start justify-between gap-4 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0';
const rowTitle = 'text-[13px]/5 tracking-[-0.005em] text-fg-primary';
const rowHelp = 'text-[12px]/4 tracking-[-0.005em] text-fg-muted';
</script>

<template>
    <DashTray
        icon="sliders"
        :title="provider === 'pagerduty' ? $t('What pages it') : $t('Issues')"
        :meta="
            provider === 'linear' && ln?.team
                ? $t('In :team', { team: ln.team.name })
                : undefined
        "
        panel-class="py-0.5"
    >
        <template v-if="provider === 'pagerduty'" #actions>
            <DashButton
                size="sm"
                :label="$t('Send a test event')"
                :loading="testing"
                :disabled="disabled"
                @click="sendTest"
            />
        </template>

        <form class="flex flex-col" @submit.prevent="save">
            <template v-if="pd">
                <div :class="row">
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span :class="rowTitle">{{
                            $t('Monitors going down')
                        }}</span>
                        <span :class="rowHelp">{{
                            $t(
                                'Triggers as soon as a monitor is confirmed down, and resolves when it’s back up. Muted and paused monitors don’t page.',
                            )
                        }}</span>
                    </span>
                    <FilterPills
                        v-model="pdForm.monitors"
                        :options="monitorChoices"
                        :label="$t('Monitors going down')"
                    />
                </div>
                <ul
                    v-if="pdForm.monitors === 'some'"
                    class="flex max-h-60 flex-col overflow-y-auto border-t-[0.5px] border-edge-tray-panel px-3.5 py-1.5"
                    :aria-label="$t('Monitors that page')"
                >
                    <li
                        v-for="monitor in pd.monitorOptions"
                        :key="monitor.id"
                        class="group/row flex items-center gap-2.5 py-1.5"
                    >
                        <DashCheckbox
                            :model-value="
                                pdForm.monitor_ids.includes(monitor.id)
                            "
                            :label="monitor.name"
                            @update:model-value="
                                toggleMonitor(monitor.id, $event)
                            "
                        />
                        <span
                            class="truncate text-[13px]/5 text-fg-secondary"
                            >{{ monitor.name }}</span
                        >
                    </li>
                    <li
                        v-if="!pd.monitorOptions.length"
                        class="py-1.5 text-[13px]/5 text-fg-muted"
                    >
                        {{ $t('No monitors yet.') }}
                    </li>
                </ul>
                <div :class="row">
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span :class="rowTitle">{{
                            $t('Machines that stop reporting')
                        }}</span>
                        <span :class="rowHelp">{{
                            $t(
                                'After five minutes without a report. Resolves when it reports again. Machines in maintenance or set to development don’t page.',
                            )
                        }}</span>
                    </span>
                    <DashSwitch
                        v-model="pdForm.machines"
                        :label="$t('Machines that stop reporting')"
                    />
                </div>
                <div :class="row">
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span :class="rowTitle">{{
                            $t('Critical CVEs being exploited')
                        }}</span>
                        <span :class="rowHelp">{{
                            $t(
                                'A critical CVE on CISA’s exploited list turns up on a machine. Resolves when no machine has it.',
                            )
                        }}</span>
                    </span>
                    <DashSwitch
                        v-model="pdForm.cves"
                        :label="$t('Critical CVEs being exploited')"
                    />
                </div>
            </template>

            <template v-else-if="ln">
                <div :class="row">
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span :class="rowTitle">{{ $t('Team') }}</span>
                        <span :class="rowHelp">{{
                            ln.done
                                ? $t('Fixed issues move to :state.', {
                                      state: ln.done.name,
                                  })
                                : $t('Where ServerOS opens issues.')
                        }}</span>
                    </span>
                    <SelectMenu
                        :model-value="lnForm.team"
                        class="ds-raised-button h-8 w-52 text-[13px]"
                        :options="teamOptions"
                        :placeholder="
                            loadingOptions ? $t('Loading') : $t('Pick a team')
                        "
                        :disabled="disabled"
                        @update:model-value="pickTeam"
                    />
                </div>
                <div v-if="lnForm.team" :class="row">
                    <span :class="rowTitle">{{ $t('Project') }}</span>
                    <SelectMenu
                        v-model="lnForm.project"
                        class="ds-raised-button h-8 w-52 text-[13px]"
                        :options="projectOptions"
                        :disabled="disabled || loadingOptions"
                    />
                </div>
                <div v-if="lnForm.team" :class="row">
                    <span :class="rowTitle">{{ $t('Label') }}</span>
                    <SelectMenu
                        v-model="lnForm.label"
                        class="ds-raised-button h-8 w-52 text-[13px]"
                        :options="labelOptions"
                        :disabled="disabled || loadingOptions"
                    />
                </div>
                <p
                    v-if="optionsError"
                    class="border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 text-[12.5px]/[18px] text-status-down"
                    role="alert"
                >
                    {{ optionsError }}
                </p>
                <div :class="row">
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span :class="rowTitle">{{
                            $t('Monitors that stay down')
                        }}</span>
                        <span :class="rowHelp">{{
                            $t(
                                'Opens an issue once a monitor has been down this long, and closes it when it’s back.',
                            )
                        }}</span>
                    </span>
                    <span class="flex shrink-0 items-center gap-2.5">
                        <label
                            v-if="lnForm.monitors"
                            class="flex items-center gap-1.5 text-[12.5px]/4 text-fg-muted"
                        >
                            <span class="dash-field w-14 font-ui-mono">
                                <input
                                    v-model.number="lnForm.minutes"
                                    type="number"
                                    min="0"
                                    max="1440"
                                    :aria-label="$t('Minutes')"
                                />
                            </span>
                            {{ $t('min') }}
                        </label>
                        <DashSwitch
                            v-model="lnForm.monitors"
                            :label="$t('Monitors that stay down')"
                        />
                    </span>
                </div>
                <div :class="row">
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span :class="rowTitle">{{
                            $t('Machines that stay offline')
                        }}</span>
                        <span :class="rowHelp">{{
                            $t(
                                'After the same wait without a report. Comments and closes it when the machine reports again.',
                            )
                        }}</span>
                    </span>
                    <DashSwitch
                        v-model="lnForm.machines"
                        :label="$t('Machines that stay offline')"
                    />
                </div>
                <div :class="row">
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span :class="rowTitle">{{ $t('Critical CVEs') }}</span>
                        <span :class="rowHelp">{{
                            $t(
                                'One issue a CVE, with the machines and the fixed version. Comments when more machines have it, and closes it when none do.',
                            )
                        }}</span>
                    </span>
                    <DashSwitch
                        v-model="lnForm.cves"
                        :label="$t('Critical CVEs')"
                    />
                </div>
            </template>

            <div
                class="flex items-center gap-2.5 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5"
            >
                <span
                    class="min-w-0 flex-1 text-[12px]/4"
                    :class="firstError ? 'text-status-down' : 'text-fg-muted'"
                    :role="firstError ? 'alert' : undefined"
                    >{{
                        firstError ??
                        (form.isDirty ? $t('Not saved yet.') : $t('Saved.'))
                    }}</span
                >
                <DashButton
                    type="submit"
                    size="sm"
                    variant="primary"
                    :label="$t('Save')"
                    :loading="form.processing"
                    :disabled="
                        disabled ||
                        !form.isDirty ||
                        (provider === 'linear' && !lnForm.team)
                    "
                />
            </div>
        </form>
    </DashTray>
</template>
