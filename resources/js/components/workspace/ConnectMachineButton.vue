<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router, usePage } from '@inertiajs/vue3';
import { ArrowLeft, Check, Plus } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import type { Component } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import ExtensionSlot from '@/components/ExtensionSlot.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import {
    Dialog,
    DialogClose,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import UpgradeDialog from '@/components/workspace/UpgradeDialog.vue';
import { useUpgradeLink } from '@/composables/useUpgradeLink';
import { errorMessage, failIfBad } from '@/lib/errors';
import { policyBaseline, policySettings } from '@/lib/machinePolicies';
import type { MachinePolicy } from '@/lib/machinePolicies';
import type { LockedPlan } from '@/lib/planLocks';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import machineGroupRoutes from '@/routes/workspace/machine-groups';
import {
    options as connectOptions,
    status as connectStatus,
} from '@/routes/workspace/machines/connect';
import workspaceRoutes from '@/routes/workspace';
import integrationRoutes from '@/routes/workspace/integrations';
import machinesRoutes from '@/routes/workspace/machines';
import settingsRoutes from '@/routes/workspace/settings';
import policyRoutes from '@/routes/workspace/settings/machine-policies';
import type { Workspace, WorkspaceAccess } from '@/types';

const slotParts = import.meta.glob<Component>(
    '../../../../vendor/serveroshq/*/resources/js/slots/workspace/ConnectMachine/**/*.vue',
    { eager: true, import: 'default' },
);

type Props = { size?: 'md' | 'sm' | 'xs' };
const { size = 'md' } = defineProps<Props>();

defineOptions({ inheritAttrs: false });

const page = usePage<{
    workspace: Workspace;
    workspaceAccess: WorkspaceAccess | null;
    appUrl: string;
}>();
const slug = computed(() => page.props.workspace.slug);
const billing = computed(() => page.props.workspace.billing);
const upgrade = useUpgradeLink();
const buttonSize = computed(() => (size === 'md' ? 'md' : 'sm'));
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);
const upgradeLabel = computed(() => {
    if (billing.value.canAddMachine) return null;
    if (billing.value.selfHosted) return null;

    return billing.value.plan === null
        ? trans('Choose a plan')
        : trans('Upgrade to connect more');
});

const nextPlan = computed<LockedPlan | null>(() => {
    if (billing.value.onTrial) return null;
    if (billing.value.plan === 'starter') return 'pro';
    if (billing.value.plan === 'pro') return 'business';

    return null;
});
const upgrading = ref(false);

type Method = 'command' | 'ssh' | 'provider';
type State = 'waiting' | 'expired' | 'enrolled' | 'connected' | 'discovered';
type Options = {
    defaultPolicyId: number;
    policies: MachinePolicy[];
    groups: { id: number; name: string; policyId: number | null }[];
    clouds?: {
        id: number;
        provider: string;
        name: string;
        label: string;
        canCreate: boolean;
    }[];
};

const open = ref(false);
const method = ref<Method>('command');
const loading = ref(false);
const error = ref('');
const command = ref('');
const expiresAt = ref<number | null>(null);
const enrolmentId = ref<number | null>(null);
const state = ref<State>('waiting');
const madeHere = ref(false);
const full = ref(false);
const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | null = null;
let clock: ReturnType<typeof setInterval> | null = null;

const options = ref<Options | null>(null);
const optionsError = ref('');
const policyId = ref<number | null>(null);
const groupId = ref<number | null>(null);

const view = ref<'pick' | 'policy' | 'group'>('pick');
const saving = ref(false);
const formError = ref('');
const newPolicy = ref({
    name: '',
    discovery: true,
    logs: true,
    security_watch: true,
});
const newGroup = ref<{ name: string; policy: number | null }>({
    name: '',
    policy: null,
});

const methods: { value: Method; label: string; icon: FilledIconName }[] = [
    { value: 'command', label: trans('One command'), icon: 'terminal-fill' },
    { value: 'ssh', label: trans('Over SSH'), icon: 'key-fill' },
    { value: 'provider', label: trans('From a provider'), icon: 'globe-fill' },
];

const chosenPolicy = computed(
    () => options.value?.policies.find((p) => p.id === policyId.value) ?? null,
);
const chosenGroup = computed(
    () => options.value?.groups.find((g) => g.id === groupId.value) ?? null,
);
const inherited = computed(
    () => chosenGroup.value?.policyId ?? options.value?.defaultPolicyId ?? null,
);

const sends = computed(() => {
    const policy = chosenPolicy.value;
    if (!policy) return '';
    const parts = [trans('Health')];
    if (policy.discovery) parts.push(trans('services'), trans('ports'));
    if (policy.logs) parts.push(trans('logs'));

    return parts.join(', ');
});

const countdown = computed(() => {
    if (expiresAt.value === null) return null;
    const left = Math.max(0, Math.floor((expiresAt.value - now.value) / 1000));

    return `${Math.floor(left / 60)}:${String(left % 60).padStart(2, '0')}`;
});

type Token = { text: string; tone: 'plain' | 'string' | 'flag' };

function shorten(value: string): string {
    const inner = value.replace(/^'|'$/g, '');
    if (full.value || inner.length <= 24) return value;

    return `'${inner.slice(0, 12)}…${inner.slice(-7)}'`;
}

const lines = computed<Token[][]>(() => {
    const words = command.value.match(/'[^']*'|"[^"]*"|\S+/g) ?? [];
    const out: Token[][] = [[]];
    let split = false;

    for (const word of words) {
        if (split && word.startsWith('--'))
            out.push([{ text: '  ', tone: 'plain' }]);
        const line = out[out.length - 1];
        const option = word.match(/^(--?[\w-]+=)(.+)$/);
        if (option) {
            line.push(
                { text: option[1], tone: 'flag' },
                {
                    text:
                        option[1] === '--token='
                            ? shorten(option[2])
                            : option[2],
                    tone: 'string',
                },
            );
        } else {
            line.push({
                text: word,
                tone: /^['"]/.test(word) ? 'string' : 'plain',
            });
        }
        if (word === '--') split = true;
    }

    return out;
});

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function loadOptions(force = false): Promise<void> {
    if (options.value && !force) return;
    optionsError.value = '';
    try {
        const response = await failIfBad(
            await fetch(onWorkspace(connectOptions(slug.value)).url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            }),
        );
        options.value = (await response.json()) as Options;
        policyId.value ??= options.value.defaultPolicyId;
    } catch (cause) {
        optionsError.value = errorMessage(cause);
    }
}

function stopPolling(): void {
    if (timer) clearInterval(timer);
    timer = null;
}

async function poll(): Promise<void> {
    if (!open.value || enrolmentId.value === null) return;
    try {
        const response = await fetch(
            onWorkspace(
                connectStatus({
                    workspace: slug.value,
                    enrolment: enrolmentId.value,
                }),
            ).url,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                cache: 'no-store',
            },
        );
        if (!response.ok) return;
        const result = await response.json();
        state.value = result.state;
        if (result.state === 'expired') stopPolling();
        if (['enrolled', 'connected', 'discovered'].includes(result.state)) {
            stopPolling();
            open.value = false;
            toast.success(
                trans(':name is connecting. Follow its setup in Machines.', {
                    name: result.machineName ?? trans('Your machine'),
                }),
            );
            router.reload({ only: ['machines', 'workspace', 'stats'] });
        }
    } catch {}
}

async function makeCommand(): Promise<void> {
    loading.value = true;
    error.value = '';
    state.value = 'waiting';
    full.value = false;
    stopPolling();
    try {
        const response = await fetch(
            onWorkspace(machinesRoutes.connect(slug.value)).url,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    replace: madeHere.value,
                    policy:
                        policyId.value !== null &&
                        policyId.value !== inherited.value
                            ? policyId.value
                            : null,
                    group: groupId.value,
                }),
            },
        );
        await failIfBad(response);
        const result = await response.json();
        command.value = result.command;
        enrolmentId.value = result.enrolmentId;
        expiresAt.value = result.expiresAt
            ? Date.parse(result.expiresAt)
            : null;
        madeHere.value = true;
        timer = setInterval(poll, 4000);
    } catch (cause) {
        error.value = errorMessage(
            cause,
            trans('Could not create an install command.'),
        );
    } finally {
        loading.value = false;
    }
}

function createCommand(): Promise<void> {
    open.value = true;

    return makeCommand();
}

async function copyCommand(): Promise<void> {
    try {
        await navigator.clipboard.writeText(command.value);
        toast.success(trans('Install command copied'));
    } catch {
        full.value = true;
        toast.error(
            trans('Could not copy. Select the command and copy it manually.'),
        );
    }
}

function pickPolicy(id: number): void {
    if (id === policyId.value) return;
    policyId.value = id;
    void makeCommand();
}

function pickGroup(id: number | null): void {
    if (id === groupId.value) return;
    groupId.value = id;
    if (inherited.value !== null) policyId.value = inherited.value;
    void makeCommand();
}

function startPolicy(): void {
    const base = chosenPolicy.value ?? policyBaseline;
    newPolicy.value = {
        name: '',
        discovery: base.discovery,
        logs: base.logs,
        security_watch: base.security_watch,
    };
    formError.value = '';
    view.value = 'policy';
}

function startGroup(): void {
    newGroup.value = { name: '', policy: policyId.value };
    formError.value = '';
    view.value = 'group';
}

async function create(
    url: string,
    body: Record<string, unknown>,
    pick: (fresh: Options, name: string) => void,
): Promise<void> {
    saving.value = true;
    formError.value = '';
    try {
        await failIfBad(
            await fetch(url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify(body),
            }),
        );
        await loadOptions(true);
        if (options.value) pick(options.value, String(body.name).trim());
        view.value = 'pick';
        void makeCommand();
    } catch (cause) {
        formError.value = errorMessage(cause);
    } finally {
        saving.value = false;
    }
}

function savePolicy(): Promise<void> {
    const { name, ...picks } = newPolicy.value;

    return create(
        onWorkspace(policyRoutes.store(slug.value)).url,
        {
            ...policySettings(chosenPolicy.value ?? policyBaseline),
            ...picks,
            name,
            applies_to: [],
        },
        (fresh, created) => {
            policyId.value =
                fresh.policies.find((p) => p.name === created)?.id ??
                policyId.value;
        },
    );
}

function saveGroup(): Promise<void> {
    return create(
        onWorkspace(machineGroupRoutes.store(slug.value)).url,
        {
            name: newGroup.value.name,
            machines: [],
            policy: newGroup.value.policy,
        },
        (fresh, created) => {
            groupId.value =
                fresh.groups.find((g) => g.name === created)?.id ??
                groupId.value;
        },
    );
}

const policiesUrl = computed(
    () => onWorkspace(settingsRoutes.machinePolicies(slug.value)).url,
);

watch(open, (value) => {
    if (value) {
        void loadOptions();
        now.value = Date.now();
        clock = setInterval(() => (now.value = Date.now()), 1000);

        return;
    }
    stopPolling();
    if (clock) clearInterval(clock);
    clock = null;
    command.value = '';
    expiresAt.value = null;
    enrolmentId.value = null;
    method.value = 'command';
    view.value = 'pick';
    madeHere.value = false;
    groupId.value = null;
    policyId.value = options.value?.defaultPolicyId ?? null;
});

watch(countdown, (value) => {
    if (value === '0:00' && state.value === 'waiting') {
        state.value = 'expired';
        stopPolling();
    }
});

onBeforeUnmount(() => {
    stopPolling();
    if (clock) clearInterval(clock);
});

defineExpose({ connect: createCommand });

const fieldClass =
    'h-8 w-full rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 font-ui text-[13px]/5 text-fg-primary shadow-field outline-none placeholder:text-fg-faint focus:border-edge-checkbox-hover';
</script>

<template>
    <template v-if="upgradeLabel && canManage && nextPlan">
        <DashButton
            v-bind="$attrs"
            variant="primary"
            :size="buttonSize"
            icon="lock-fill"
            :title="upgradeLabel"
            @click="upgrading = true"
        >
            <slot>{{ $t('Connect machine') }}</slot>
        </DashButton>
        <UpgradeDialog
            v-model:open="upgrading"
            feature="More machines"
            :plan="nextPlan"
        />
    </template>
    <DashButton
        v-else-if="upgradeLabel && canManage && upgrade"
        v-bind="$attrs"
        :size="buttonSize"
        icon="open-fill"
        :href="upgrade.href"
        :label="upgradeLabel"
    />
    <DashButton
        v-else-if="canManage && !upgradeLabel"
        v-bind="$attrs"
        variant="primary"
        :size="buttonSize"
        :icon="Plus"
        @click="createCommand"
    >
        <slot>{{ $t('Connect machine') }}</slot>
    </DashButton>

    <Dialog v-model:open="open">
        <DialogContent
            :show-close-button="false"
            class="workspace-panel flex max-h-[calc(100svh-2rem)] flex-col gap-0 overflow-hidden rounded-[12px] border-[0.5px] border-[#dcdcdc] bg-[#fbfbfb] p-0 font-ui text-fg-primary shadow-[0_24px_48px_-12px_rgb(0_0_0/0.55),0_1px_2px_0_rgb(0_0_0/0.5)] sm:max-w-[600px] dark:border-[#2c2c2c] dark:bg-[#161616]"
        >
            <header
                class="flex shrink-0 items-center gap-3.5 border-b-[0.5px] border-edge-row py-[18px] pr-[18px] pl-5"
            >
                <span
                    class="flex size-[38px] shrink-0 items-center justify-center rounded-[7px] bg-[#efefef] text-fg-secondary shadow-[0_3px_6px_-2px_rgb(0_0_0/0.35),0_1px_1.5px_0_rgb(0_0_0/0.55),inset_0_1px_0_0_rgb(255_255_255/0.06)] dark:bg-[#222]"
                    aria-hidden="true"
                >
                    <FilledIcon name="machines-fill" :size="18" />
                </span>
                <span class="flex min-w-0 flex-1 flex-col gap-px">
                    <DialogTitle
                        class="text-[15px]/[22px] font-medium text-fg-primary"
                        >{{ $t('Connect a machine') }}</DialogTitle
                    >
                    <DialogDescription
                        class="text-[13px]/[19px] text-fg-tertiary"
                        >{{
                            $t(
                                'Any Linux server you manage, from any provider.',
                            )
                        }}</DialogDescription
                    >
                </span>
                <DialogClose
                    class="cursor-pointer rounded-[5px] border-[0.5px] border-[#d4d4d4] bg-[#f4f4f4] px-[7px] py-0.5 font-ui-mono text-[11px]/4 text-fg-tertiary shadow-[0_1px_1.5px_0_rgb(0_0_0/0.5),inset_0_1px_0_0_rgb(255_255_255/0.05)] hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) dark:border-[#333] dark:bg-[#1f1f1f]"
                    :aria-label="$t('Close')"
                    >Esc</DialogClose
                >
            </header>

            <div class="min-h-0 flex-1 overflow-y-auto">
                <div class="px-5 pt-4">
                    <div
                        class="flex gap-0.5 rounded-[8px] border-[0.5px] border-edge-row bg-[#ececec] p-0.5 shadow-[inset_0_1px_2px_0_rgb(0_0_0/0.15)] dark:bg-[#101010] dark:shadow-[inset_0_1px_2px_0_rgb(0_0_0/0.5)]"
                        role="tablist"
                        :aria-label="$t('How to connect')"
                    >
                        <button
                            v-for="item in methods"
                            :key="item.value"
                            type="button"
                            role="tab"
                            :aria-selected="method === item.value"
                            class="flex h-[30px] min-w-0 flex-1 cursor-pointer items-center justify-center gap-[7px] rounded-[6px] px-3 text-[12.5px]/[18px] font-medium whitespace-nowrap focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                            :class="
                                method === item.value
                                    ? 'border-[0.5px] border-[#d4d4d4] bg-white text-fg-primary shadow-[0_1px_2px_0_rgb(0_0_0/0.5)] dark:border-[#404040] dark:bg-[#2b2b2b]'
                                    : 'text-fg-muted hover:text-fg-secondary'
                            "
                            @click="method = item.value"
                        >
                            <FilledIcon :name="item.icon" :size="14" />
                            <span class="truncate">{{ item.label }}</span>
                        </button>
                    </div>
                </div>

                <div
                    v-if="method === 'provider' && options?.clouds?.length"
                    class="flex flex-col gap-3 p-5"
                >
                    <p class="text-[13px]/[19px] text-fg-tertiary">
                        {{
                            $t(
                                'Make a server that joins on its own, or import one you have.',
                            )
                        }}
                    </p>
                    <div
                        class="flex flex-col rounded-[10px] border-[0.5px] border-edge-card bg-surface-card"
                    >
                        <div
                            v-for="cloud in options.clouds"
                            :key="cloud.id"
                            class="flex items-center gap-2.5 border-t-[0.5px] border-edge px-3 py-2.5 first:border-t-0"
                        >
                            <BrandLogo :name="cloud.provider" />
                            <span class="flex min-w-0 flex-1 flex-col">
                                <span
                                    class="truncate text-[13px]/5 font-medium text-fg-primary"
                                    >{{ cloud.label }}</span
                                >
                                <span class="text-[12px]/4 text-fg-muted">{{
                                    cloud.name
                                }}</span>
                            </span>
                            <DashButton
                                size="sm"
                                :label="$t('Import')"
                                :href="
                                    onWorkspace(
                                        integrationRoutes.show({
                                            workspace: slug,
                                            integration: cloud.id,
                                        }),
                                    ).url
                                "
                            />
                            <DashButton
                                v-if="cloud.canCreate"
                                size="sm"
                                variant="primary"
                                :icon="Plus"
                                :label="$t('New server')"
                                :href="
                                    onWorkspace(
                                        integrationRoutes.show(
                                            {
                                                workspace: slug,
                                                integration: cloud.id,
                                            },
                                            { query: { new: 1 } },
                                        ),
                                    ).url
                                "
                            />
                        </div>
                    </div>
                </div>

                <div
                    v-else-if="method !== 'command'"
                    class="flex flex-col items-start gap-3 p-5"
                >
                    <p class="text-[13.5px]/[22px] font-medium text-fg-primary">
                        {{
                            method === 'ssh'
                                ? $t('Over SSH is coming')
                                : $t('Connect a cloud account first')
                        }}
                    </p>
                    <p class="text-[13px]/[19px] text-fg-tertiary">
                        {{
                            method === 'ssh'
                                ? $t(
                                      'Soon you can give ServerOS an address, a user and a key, and it installs the daemon for you. For now, run the one command on the server.',
                                  )
                                : $t(
                                      'Connect Hetzner Cloud in Integrations, then make servers that join on their own, or import the ones you have.',
                                  )
                        }}
                    </p>
                    <div class="flex flex-wrap gap-2">
                        <DashButton
                            v-if="method === 'provider'"
                            size="sm"
                            variant="primary"
                            icon="plug"
                            :label="$t('Open Integrations')"
                            :href="
                                onWorkspace(workspaceRoutes.integrations(slug))
                                    .url
                            "
                        />
                        <DashButton
                            size="sm"
                            icon="terminal-fill"
                            :label="$t('Use one command')"
                            @click="method = 'command'"
                        />
                    </div>
                </div>

                <form
                    v-else-if="view === 'policy'"
                    class="flex flex-col gap-4 p-5"
                    @submit.prevent="savePolicy"
                >
                    <button
                        type="button"
                        class="flex w-fit cursor-pointer items-center gap-1.5 rounded-sm text-[13px]/5 text-fg-muted hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        @click="view = 'pick'"
                    >
                        <ArrowLeft class="size-3.5" :stroke-width="1.75" />
                        {{ $t('New policy') }}
                    </button>
                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px]/4 text-fg-tertiary">{{
                            $t('Name')
                        }}</span>
                        <input
                            v-model="newPolicy.name"
                            type="text"
                            maxlength="60"
                            required
                            autofocus
                            :placeholder="$t('e.g. Production')"
                            :class="fieldClass"
                        />
                    </label>
                    <div class="dash-card flex flex-col">
                        <label
                            v-for="[key, title, body] in [
                                [
                                    'discovery',
                                    $t('Services and ports'),
                                    $t(
                                        'What runs, whether it’s up, and the ports it listens on',
                                    ),
                                ],
                                [
                                    'logs',
                                    $t('Logs'),
                                    $t('Service logs, as they are'),
                                ],
                                [
                                    'security_watch',
                                    $t('Security checks'),
                                    $t(
                                        'SSH settings, failed sign-ins and whether security updates are on',
                                    ),
                                ],
                            ] as const"
                            :key="key"
                            class="flex cursor-pointer items-center gap-4 border-b-[0.5px] border-edge-row px-4 py-2.5 last:border-b-0"
                        >
                            <span class="flex min-w-0 grow flex-col">
                                <span
                                    class="text-[13px]/5 font-medium text-fg-primary"
                                    >{{ title }}</span
                                >
                                <span
                                    class="text-[12.5px]/4 text-fg-tertiary"
                                    >{{ body }}</span
                                >
                            </span>
                            <ToggleSwitch
                                v-model="newPolicy[key]"
                                :label="title"
                            />
                        </label>
                    </div>
                    <p class="text-[12.5px]/4 text-fg-muted">
                        {{
                            $t(
                                'Health is always sent. Updates and the rest copy :name; change them in Manage policies.',
                                {
                                    name:
                                        chosenPolicy?.name ?? $t('the default'),
                                },
                            )
                        }}
                    </p>
                    <p
                        v-if="formError"
                        role="alert"
                        class="text-[13px]/5 text-status-down"
                    >
                        {{ formError }}
                    </p>
                    <div class="flex justify-end gap-2">
                        <DashButton
                            size="sm"
                            :label="$t('Cancel')"
                            @click="view = 'pick'"
                        />
                        <DashButton
                            size="sm"
                            type="submit"
                            :label="$t('Create policy')"
                            :loading="saving"
                            :disabled="!newPolicy.name.trim()"
                        />
                    </div>
                </form>

                <form
                    v-else-if="view === 'group'"
                    class="flex flex-col gap-4 p-5"
                    @submit.prevent="saveGroup"
                >
                    <button
                        type="button"
                        class="flex w-fit cursor-pointer items-center gap-1.5 rounded-sm text-[13px]/5 text-fg-muted hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        @click="view = 'pick'"
                    >
                        <ArrowLeft class="size-3.5" :stroke-width="1.75" />
                        {{ $t('New group') }}
                    </button>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px]/4 text-fg-tertiary">{{
                                $t('Name')
                            }}</span>
                            <input
                                v-model="newGroup.name"
                                type="text"
                                maxlength="60"
                                required
                                autofocus
                                :placeholder="$t('e.g. Web servers')"
                                :class="fieldClass"
                            />
                        </label>
                        <label class="flex flex-col gap-1.5">
                            <span class="text-[12.5px]/4 text-fg-tertiary">{{
                                $t('Its machines follow')
                            }}</span>
                            <select
                                v-model="newGroup.policy"
                                :class="fieldClass"
                            >
                                <option
                                    v-for="policy in options?.policies ?? []"
                                    :key="policy.id"
                                    :value="policy.id"
                                >
                                    {{ policy.name }}
                                </option>
                            </select>
                        </label>
                    </div>
                    <p
                        v-if="formError"
                        role="alert"
                        class="text-[13px]/5 text-status-down"
                    >
                        {{ formError }}
                    </p>
                    <div class="flex justify-end gap-2">
                        <DashButton
                            size="sm"
                            :label="$t('Cancel')"
                            @click="view = 'pick'"
                        />
                        <DashButton
                            size="sm"
                            type="submit"
                            :label="$t('Create group')"
                            :loading="saving"
                            :disabled="!newGroup.name.trim()"
                        />
                    </div>
                </form>

                <ol v-else class="flex flex-col gap-[18px] p-5">
                    <li class="flex gap-3">
                        <span
                            class="flex size-[22px] shrink-0 items-center justify-center rounded-full border border-[#d4d4d4] text-[11px]/3.5 font-medium text-fg-muted dark:border-[#3d3d3d]"
                            aria-hidden="true"
                            >1</span
                        >
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <p
                                class="text-[13.5px]/[22px] font-medium text-fg-primary"
                            >
                                {{ $t('Run this on the server') }}
                            </p>
                            <p class="text-[13px]/[19px] text-fg-tertiary">
                                {{
                                    $t(
                                        'As root, or with sudo. It installs the ServerOS daemon and starts it.',
                                    )
                                }}
                            </p>
                            <div
                                class="mt-1.5 min-h-[104px] overflow-x-auto rounded-[8px] border-[0.5px] border-edge-row bg-[#f1f1f1] px-3.5 py-3 shadow-[inset_0_1px_2px_0_rgb(0_0_0/0.12)] dark:bg-[#101010] dark:shadow-[inset_0_1px_2px_0_rgb(0_0_0/0.5)]"
                            >
                                <p
                                    v-if="loading"
                                    class="flex items-center gap-2 text-[13px]/5 text-fg-tertiary"
                                >
                                    <FilledIcon
                                        name="history"
                                        class="animate-spin text-fg-muted motion-reduce:animate-none"
                                    />
                                    {{
                                        $t(
                                            'Preparing a one-use install command…',
                                        )
                                    }}
                                </p>
                                <div
                                    v-else-if="error"
                                    class="flex flex-col items-start gap-2"
                                >
                                    <p
                                        role="alert"
                                        class="text-[13px]/5 text-status-down"
                                    >
                                        {{ error }}
                                    </p>
                                    <DashButton
                                        size="sm"
                                        :label="$t('Try again')"
                                        @click="makeCommand"
                                    />
                                </div>
                                <code
                                    v-else-if="command"
                                    class="block font-ui-mono text-[12px]/5 whitespace-pre text-fg-primary"
                                    :class="{
                                        'break-all whitespace-pre-wrap': full,
                                    }"
                                    :aria-label="command"
                                    ><span
                                        v-for="(line, index) in lines"
                                        :key="index"
                                        class="block"
                                        ><template
                                            v-for="(token, at) in line"
                                            :key="at"
                                            ><span
                                                :class="{
                                                    'text-fg-secondary':
                                                        token.tone === 'string',
                                                    'text-fg-muted':
                                                        token.tone === 'flag',
                                                }"
                                                >{{ token.text }}</span
                                            >{{
                                                at < line.length - 1 &&
                                                token.tone !== 'flag' &&
                                                token.text !== '  '
                                                    ? ' '
                                                    : ''
                                            }}</template
                                        >{{
                                            index < lines.length - 1
                                                ? ' \\'
                                                : ''
                                        }}</span
                                    ></code
                                >
                            </div>
                            <div class="flex items-center gap-2 pt-1.5">
                                <FilledIcon
                                    name="activity-fill"
                                    :size="14"
                                    class="text-fg-muted"
                                />
                                <span class="text-[12px]/4 text-fg-muted">{{
                                    countdown && state !== 'expired'
                                        ? $t('One use · expires in :time', {
                                              time: countdown,
                                          })
                                        : $t(
                                              'One use · expires after 15 minutes',
                                          )
                                }}</span>
                                <span class="grow" />
                                <DashButton
                                    variant="primary"
                                    icon="copy"
                                    :label="$t('Copy command')"
                                    :disabled="!command || state === 'expired'"
                                    @click="copyCommand"
                                />
                            </div>
                        </div>
                    </li>
                    <li class="flex gap-3">
                        <span
                            class="flex size-[22px] shrink-0 items-center justify-center rounded-full border border-[#d4d4d4] text-[11px]/3.5 font-medium text-fg-muted dark:border-[#3d3d3d]"
                            aria-hidden="true"
                            >2</span
                        >
                        <div class="flex min-w-0 flex-1 flex-col gap-1">
                            <p
                                class="text-[13.5px]/[22px] font-medium text-fg-primary"
                            >
                                {{ $t('It connects out and looks around') }}
                            </p>
                            <p class="text-[13px]/[19px] text-fg-tertiary">
                                {{
                                    $t(
                                        'The daemon connects to ServerOS over HTTPS and lists what’s already running and the ports it uses. It changes nothing.',
                                    )
                                }}
                            </p>
                        </div>
                    </li>
                    <li class="flex gap-3" aria-live="polite">
                        <span
                            v-if="state === 'expired'"
                            class="flex size-[22px] shrink-0 items-center justify-center rounded-full bg-[#f5a833]/12 text-[12px] font-medium text-[#f5a833]"
                            aria-hidden="true"
                            >!</span
                        >
                        <span
                            v-else
                            class="flex size-[22px] shrink-0 items-center justify-center rounded-full bg-[#87a3ff]/12"
                            aria-hidden="true"
                        >
                            <svg
                                viewBox="0 0 12 12"
                                class="size-3 animate-spin motion-reduce:animate-none"
                            >
                                <circle
                                    cx="6"
                                    cy="6"
                                    r="4.8"
                                    fill="none"
                                    stroke="#87a3ff"
                                    stroke-opacity="0.25"
                                    stroke-width="1.6"
                                />
                                <path
                                    d="M6 1.2a4.8 4.8 0 0 1 4.8 4.8"
                                    fill="none"
                                    stroke="#87a3ff"
                                    stroke-width="1.6"
                                    stroke-linecap="round"
                                />
                            </svg>
                        </span>
                        <div
                            class="flex min-w-0 flex-1 flex-col items-start gap-1"
                        >
                            <p
                                class="text-[13.5px]/[22px] font-medium"
                                :class="
                                    state === 'expired'
                                        ? 'text-[#f5a833]'
                                        : 'text-[#4d6fe8] dark:text-[#87a3ff]'
                                "
                            >
                                {{
                                    state === 'expired'
                                        ? $t('This command expired')
                                        : $t('Waiting for the daemon to enrol…')
                                }}
                            </p>
                            <p class="text-[13px]/[19px] text-fg-tertiary">
                                {{
                                    state === 'expired'
                                        ? $t(
                                              'Each command works once and only for 15 minutes. Make a new one and run that instead.',
                                          )
                                        : $t(
                                              'Keep this open. The machine shows up in your list as soon as it says hello, and its setup carries on there.',
                                          )
                                }}
                            </p>
                            <DashButton
                                v-if="state === 'expired'"
                                size="sm"
                                class="mt-1"
                                :label="$t('Make a new command')"
                                @click="makeCommand"
                            />
                        </div>
                    </li>
                </ol>
            </div>

            <div
                v-if="method === 'command' && view === 'pick'"
                class="flex shrink-0 flex-wrap items-center gap-2 border-y-[0.5px] border-edge-row bg-[#f4f4f4] px-5 py-3 dark:bg-[#131313]"
            >
                <span class="text-[12.5px]/[18px] text-fg-tertiary">{{
                    $t('Follows')
                }}</span>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="flex h-[26px] max-w-48 cursor-pointer items-center gap-1.5 rounded-[6px] border-[0.5px] border-[#d4d4d4] bg-white pr-[7px] pl-[9px] text-[12.5px]/[18px] font-medium text-fg-primary shadow-card focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:opacity-60 dark:border-[#333] dark:bg-[#1f1f1f]"
                            :disabled="!options"
                        >
                            <FilledIcon
                                name="sliders"
                                :size="13"
                                class="text-fg-muted"
                            />
                            <span class="truncate">{{
                                chosenPolicy?.name ?? $t('Default policy')
                            }}</span>
                            <FilledIcon
                                name="chevrons-up-down"
                                :size="12"
                                class="text-fg-muted"
                            />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="ds-modal min-w-52 font-ui"
                        align="start"
                    >
                        <DropdownMenuItem
                            v-for="policy in options?.policies ?? []"
                            :key="policy.id"
                            @select="pickPolicy(policy.id)"
                        >
                            {{ policy.name }}
                            <span
                                v-if="policy.isDefault"
                                class="text-[12px] text-fg-muted"
                                >{{ $t('Default') }}</span
                            >
                            <Check
                                v-if="policy.id === policyId"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem @select="startPolicy">
                            <Plus class="size-4" :stroke-width="1.75" />
                            {{ $t('New policy…') }}
                        </DropdownMenuItem>
                        <DropdownMenuItem as-child>
                            <a :href="policiesUrl">
                                <FilledIcon name="settings-fill" />
                                {{ $t('Manage policies') }}
                            </a>
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <span class="text-[12.5px]/[18px] text-fg-tertiary">{{
                    $t('in')
                }}</span>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="flex h-[26px] max-w-48 cursor-pointer items-center gap-1.5 rounded-[6px] border-[0.5px] border-[#d4d4d4] bg-white pr-[7px] pl-[9px] text-[12.5px]/[18px] font-medium text-fg-primary shadow-card focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:opacity-60 dark:border-[#333] dark:bg-[#1f1f1f]"
                            :disabled="!options"
                        >
                            <FilledIcon
                                name="folder-fill"
                                :size="13"
                                class="text-fg-muted"
                            />
                            <span class="truncate">{{
                                chosenGroup?.name ?? $t('No group')
                            }}</span>
                            <FilledIcon
                                name="chevrons-up-down"
                                :size="12"
                                class="text-fg-muted"
                            />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="ds-modal min-w-52 font-ui"
                        align="start"
                    >
                        <DropdownMenuItem @select="pickGroup(null)">
                            {{ $t('No group') }}
                            <Check
                                v-if="groupId === null"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-for="group in options?.groups ?? []"
                            :key="group.id"
                            @select="pickGroup(group.id)"
                        >
                            {{ group.name }}
                            <Check
                                v-if="group.id === groupId"
                                class="ml-auto size-3.5"
                            />
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem @select="startGroup">
                            <Plus class="size-4" :stroke-width="1.75" />
                            {{ $t('New group…') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <span class="grow" />
                <span
                    v-if="optionsError"
                    class="text-[12px]/4 text-status-down"
                    role="alert"
                    >{{ optionsError }}</span
                >
                <span v-else class="text-[12px]/4 text-fg-faint">{{
                    sends
                }}</span>
            </div>

            <p
                v-if="method === 'command' && view === 'pick'"
                class="flex shrink-0 gap-2 px-5 pt-3 pb-3.5 text-[12px]/[18px] text-fg-muted"
            >
                <FilledIcon
                    name="info-fill"
                    :size="14"
                    class="mt-0.5 text-fg-muted"
                />
                <span>
                    {{
                        $t(
                            'Needs Linux with systemd, sudo, curl and outbound HTTPS. The daemon runs as root.',
                        ) + ' '
                    }}
                    <a
                        :href="`${page.props.appUrl}/install.sh`"
                        target="_blank"
                        rel="noopener"
                        class="text-fg-secondary underline underline-offset-2 hover:text-fg-primary"
                        >{{ $t('Read the install script') }}</a
                    >{{ ', ' + $t('or') + ' '
                    }}<a
                        :href="`${page.props.appUrl}/security`"
                        target="_blank"
                        rel="noopener"
                        class="text-fg-secondary underline underline-offset-2 hover:text-fg-primary"
                        >{{ $t('see what the daemon can and can’t do') }}</a
                    >.
                </span>
            </p>

            <footer
                class="flex h-[58px] shrink-0 items-center gap-2.5 border-t-[0.5px] border-edge-row bg-[#f4f4f4] pr-3.5 pl-5 dark:bg-[#151515]"
            >
                <div class="min-w-0 flex-1">
                    <ExtensionSlot
                        name="workspace/ConnectMachine/footer"
                        :parts="slotParts"
                    />
                </div>
                <DialogClose as-child>
                    <DashButton :label="$t('Close')" />
                </DialogClose>
            </footer>
        </DialogContent>
    </Dialog>
</template>
