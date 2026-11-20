<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router, useForm } from '@inertiajs/vue3';
import {
    ArrowUp,
    Check,
    ChevronDown,
    Clock,
    GitBranch,
    Moon,
    FileText,
    Lock,
    Plus,
    X,
} from '@lucide/vue';
import { computed, nextTick, ref, useTemplateRef, watch } from 'vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import AiBrandIcon from '@/components/workspace/AiBrandIcon.vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import { appButtonClasses } from '@/lib/appButton';
import BranchPicker from './BranchPicker.vue';
import GitHubMark from './GitHubMark.vue';
import MachineDialog from './MachineDialog.vue';
import CodeIcon from '@/components/icons/CodeIcon.vue';
import ServersIcon from '@/components/icons/ServersIcon.vue';
import RepoPicker from './RepoPicker.vue';
import type { Hosting, StartContext } from './agentTasks';

export type Box = {
    id: number;
    name: string;
    online: boolean;
    slots: number;
    running: number;
    containers: { key: string; name: string; agent: boolean }[];
};

const props = defineProps<{
    boxes: Box[];
    twoFactor: boolean;
    planAllows: boolean;
    canManage: boolean;
    context?: StartContext | null;
    /** ServerOS's own machines, when the workspace can run agents there. */
    hosting?: Hosting | null;
}>();

const form = useForm({
    prompt: props.context?.prompt ?? '',
    title: '',
    machine_id: 0,
    service: '',
    agent: 'claude',
    model: '',
    repo: props.context?.repo ?? '',
    branch: props.context?.branch ?? '',
    pull_request: true,
    agents: 1,
    hosted: false,
    setup: '',
    check: '',
    minutes: 20,
    context: props.context?.ref ?? '',
    when: 'now' as 'now' | 'tonight',
});

// Code: an agent works on a repository in a container. Server: the server
// agent looks at the machine itself and asks before it changes anything.
const mode = ref<'code' | 'server'>('code');
const modes = [
    {
        id: 'code' as const,
        icon: CodeIcon,
        label: trans('Code'),
        note: trans('pull request back'),
        hint: trans('Work in a repository and open a pull request.'),
    },
    {
        id: 'server' as const,
        icon: ServersIcon,
        label: trans('Server'),
        note: trans('asks first'),
        hint: trans(
            'The server agent reads the machine’s logs and services, and asks before it changes anything.',
        ),
    },
];

const textarea = useTemplateRef<HTMLTextAreaElement>('textarea');

const withAgent = computed(() =>
    props.boxes.filter((box) => box.containers.some((c) => c.agent)),
);

const chosenBox = computed(
    () => props.boxes.find((box) => box.id === form.machine_id) ?? null,
);
const chosenContainer = computed(
    () =>
        chosenBox.value?.containers.find((c) => c.key === form.service) ?? null,
);

function pickDefaultPlace(): void {
    if (form.service) return;
    // Started from something on a machine: work there when it can.
    const near = props.boxes.find(
        (b) =>
            b.id === props.context?.machineId &&
            b.containers.some((c) => c.agent),
    );
    const box =
        near ?? withAgent.value.find((b) => b.online) ?? withAgent.value[0];
    const container = box?.containers.find((c) => c.agent);
    if (box && container) {
        form.machine_id = box.id;
        form.service = container.key;
    } else if (props.context?.machineId) {
        form.machine_id = props.context.machineId;
    } else if (props.hosting) {
        // No machine of their own with an agent container: ServerOS's,
        // which asks for agent time first if there's none.
        form.hosted = true;
    }
}
pickDefaultPlace();

const choosing = ref(false);

// The server agent needs only a machine: start with one that's online.
watch(mode, (next) => {
    if (next === 'server' && !chosenBox.value) {
        const box = props.boxes.find((b) => b.online) ?? props.boxes[0];
        if (box) form.machine_id = box.id;
    }
});

const placeLabel = computed(() => {
    if (form.hosted && mode.value === 'code')
        return props.hosting?.label ?? 'ServerOS';
    if (!chosenBox.value) return null;
    const container = chosenContainer.value;
    if (mode.value === 'server') return chosenBox.value.name;

    return container && !container.agent
        ? `${chosenBox.value.name} · ${container.name}`
        : chosenBox.value.name;
});

function place(machineId: number, key: string): void {
    form.hosted = false;
    form.machine_id = machineId;
    if (mode.value === 'code') form.service = key;
}

function pickHosted(): void {
    form.hosted = true;
    form.machine_id = 0;
    form.service = '';
}

function pickRepo(repo: string, defaultBranch: string | null): void {
    if (repo !== form.repo) form.branch = defaultBranch ?? '';
    form.repo = repo;
}

const hasRepo = computed(() => form.repo.trim() !== '');

const agents = [
    {
        id: 'claude',
        name: 'Claude Code',
        brand: 'claude' as const,
        models: [
            { id: '', label: trans('Default model'), local: false },
            { id: 'opus', label: 'Opus', local: false },
            { id: 'sonnet', label: 'Sonnet', local: false },
            { id: 'haiku', label: 'Haiku', local: false },
        ],
    },
    {
        id: 'codex',
        name: 'Codex',
        brand: 'codex' as const,
        models: [
            { id: '', label: trans('Default model'), local: false },
            { id: 'gpt-5-codex', label: 'gpt-5-codex', local: false },
        ],
    },
    {
        id: 'opencode',
        name: 'OpenCode',
        brand: null,
        models: [
            {
                id: 'ollama/qwen3-coder:30b',
                label: 'Qwen3 Coder, on your machine',
                local: true,
            },
            {
                id: 'ollama/gpt-oss:20b',
                label: 'gpt-oss 20b, on your machine',
                local: true,
            },
            {
                id: 'anthropic/claude-sonnet-4-5',
                label: 'Claude Sonnet',
                local: false,
            },
        ],
    },
];

const chosenAgent = computed(
    () => agents.find((a) => a.id === form.agent) ?? agents[0],
);
const chosenModel = computed(
    () => chosenAgent.value.models.find((m) => m.id === form.model) ?? null,
);
const isPrivate = computed(() => chosenModel.value?.local === true);

function chooseAgent(id: string, model: string): void {
    form.agent = id;
    form.model = model;
}

const counts = [1, 2, 3, 5, 10, 20];
const options = ref(false);

// How many start straight away, and how many wait for room on the machine.
const fits = computed(() => {
    const box = chosenBox.value;
    if (!box || form.when === 'tonight') return null;
    const free = Math.max(0, box.slots - box.running);

    return {
        now: Math.min(free, form.agents),
        wait: Math.max(0, form.agents - free),
    };
});

const problem = computed(() => {
    if (!props.canManage)
        return trans('Only people who manage machines start agents.');
    // ServerOS's machines need neither: they're paid for in agent time.
    if (!props.planAllows && !props.hosting)
        return trans('Agents come with Pro.');
    if (!props.planAllows && !form.hosted && mode.value === 'code')
        return trans(
            'Agents on your own machines come with Pro. Choose ServerOS’s machines, or a plan in Billing.',
        );
    if (!props.twoFactor && !form.hosted)
        return trans('Turn on two-factor authentication to run agents.');
    if (!props.boxes.length && !props.hosting)
        return trans('Connect a machine first: agents run on your own.');

    return null;
});

const ready = computed(
    () =>
        problem.value === null &&
        form.prompt.trim() !== '' &&
        !form.processing &&
        (mode.value === 'server'
            ? form.machine_id > 0
            : form.hosted
              ? !props.hosting?.refusal
              : !!form.service),
);

function submit(): void {
    if (!ready.value) return;
    if (mode.value === 'server') {
        // The server agent: Fixes reads the machine and proposes steps to approve.
        router.post(
            '/fixes',
            { machine: form.machine_id, note: form.prompt.slice(0, 1000) },
            {
                onError: (errors) =>
                    form.setError(
                        'prompt',
                        Object.values(errors)[0] ??
                            trans('Couldn’t start the server agent.'),
                    ),
            },
        );

        return;
    }
    form.transform((data) => ({
        ...data,
        pull_request: hasRepo.value && data.pull_request,
        context: data.context || null,
        hosted: data.hosted,
        machine_id: data.hosted ? null : data.machine_id,
        service: data.hosted ? null : data.service,
    })).post('/agents', { preserveScroll: true });
}

/** Pull requests can't be opened there: the same task, without them. */
function sendWithoutPullRequests(): void {
    form.pull_request = false;
    form.clearErrors('pull_request');
    submit();
}

/** Starts the prompt off from a suggestion, ready to finish in your words. */
function suggest(text: string): void {
    mode.value = 'code';
    form.prompt = text;
    void nextTick(() => {
        const el = textarea.value;
        if (!el) return;
        el.focus();
        el.setSelectionRange(text.length, text.length);
        grow();
    });
}
defineExpose({ suggest });

function grow(): void {
    const el = textarea.value;
    if (!el) return;
    el.style.height = 'auto';
    el.style.height = `${Math.min(el.scrollHeight, 320)}px`;
}

const contextOpen = ref(false);

// Quiet controls: the card's words carry it, not a row of boxes.
const chip =
    'inline-flex h-7 min-w-0 shrink-0 cursor-pointer items-center gap-1.5 rounded-[7px] px-2 text-[13px]/4 text-(--ds-text-muted) transition-colors hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring) disabled:cursor-default disabled:opacity-50';
const field =
    'h-full min-w-0 flex-1 bg-transparent font-ui-mono text-[12.5px] text-(--ds-text) outline-none placeholder:font-ui placeholder:text-[13px] placeholder:text-(--ds-text-faint)';
</script>

<template>
    <form class="agent-composer flex flex-col" @submit.prevent="submit">
        <!-- Code or Server: what the agent works on, as tabs over the sky. -->
        <!-- Like the pricing section's Monthly / Yearly switch on the sky. -->
        <div
            class="mb-3 inline-flex items-center gap-0.5 self-start rounded-[10px] bg-[rgb(2_3_14/.35)] p-[3px] shadow-[inset_0_0_0_0.5px_rgb(255_255_255/.12)] backdrop-blur-md"
            role="group"
            :aria-label="$t('What it works on')"
        >
            <button
                v-for="m in modes"
                :key="m.id"
                type="button"
                class="inline-flex h-[30px] cursor-pointer items-center gap-1.5 rounded-[7px] px-3.5 text-[14px] whitespace-nowrap text-white/75 transition-[color,background-color] duration-150 hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white/80 aria-pressed:bg-[linear-gradient(180deg,rgb(255_255_255/.09)_0%,rgb(255_255_255/.05)_49%,transparent_51%),linear-gradient(180deg,#3D5EF0_0%,#1621D1_100%)] aria-pressed:text-white aria-pressed:shadow-[inset_0_0.5px_0_rgb(255_255_255/.32),inset_0_0_0_0.5px_rgb(255_255_255/.1),0_1px_2px_rgb(2_3_14/.3)]"
                :aria-pressed="mode === m.id"
                :title="m.hint"
                @click="mode = m.id"
            >
                <component
                    :is="m.icon"
                    class="size-[15px] shrink-0"
                    :class="mode === m.id ? 'text-white' : 'text-white/70'"
                />
                {{ m.label }}
                <span
                    class="text-[12px]"
                    :class="mode === m.id ? 'text-white/80' : 'text-white/55'"
                    >{{ m.note }}</span
                >
            </button>
        </div>

        <div
            class="overflow-hidden rounded-[14px] bg-(--ds-card) text-(--ds-text) shadow-[0_0_0_1px_rgb(2_3_14/0.08),0_24px_60px_-24px_rgb(2_3_14/0.65)] dark:shadow-[0_0_0_1px_rgb(255_255_255/0.08),0_24px_60px_-24px_rgb(0_0_0/0.8)]"
        >
            <!-- What the task starts from: a failed deploy, logs, a monitor. -->
            <div
                v-if="context && form.context && mode === 'code'"
                class="mx-3 mt-3 rounded-[8px] border border-(--ds-border) bg-(--ds-subtle)"
            >
                <div class="flex items-center gap-2.5 px-3 py-2">
                    <FileText
                        class="size-4 shrink-0 text-(--ds-text-muted)"
                        :stroke-width="1.75"
                    />
                    <button
                        type="button"
                        class="flex min-w-0 flex-1 cursor-pointer flex-col text-left"
                        :aria-expanded="contextOpen"
                        @click="contextOpen = !contextOpen"
                    >
                        <span class="truncate text-[13px]/5 font-medium">{{
                            context.label
                        }}</span>
                        <span class="text-[11.5px]/4 text-(--ds-text-muted)">{{
                            $t(
                                ':lines lines of evidence from your machines, handed to the agent',
                                { lines: String(context.lines) },
                            )
                        }}</span>
                    </button>
                    <button
                        type="button"
                        class="flex size-6 cursor-pointer items-center justify-center rounded-[6px] text-(--ds-text-faint) hover:bg-(--ds-hover) hover:text-(--ds-text)"
                        :aria-label="$t('Remove')"
                        @click="form.context = ''"
                    >
                        <X class="size-3.5" :stroke-width="2" />
                    </button>
                </div>
                <pre
                    v-if="contextOpen"
                    class="max-h-48 overflow-auto border-t border-(--ds-divider) px-3 py-2 font-ui-mono text-[11px]/4.5 whitespace-pre-wrap text-(--ds-text-secondary)"
                    v-text="context.preview"
                />
            </div>

            <label for="agent-prompt" class="sr-only">{{
                $t('What should the agent do?')
            }}</label>
            <textarea
                id="agent-prompt"
                ref="textarea"
                v-model="form.prompt"
                rows="3"
                :maxlength="mode === 'server' ? 1000 : 4000"
                class="block max-h-80 w-full resize-none bg-transparent px-4 pt-3.5 pb-1 text-[14px]/6 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint)"
                :placeholder="
                    problem ??
                    (mode === 'server'
                        ? $t(
                              'Describe a problem on the machine. The disk on web-1 keeps filling up…',
                          )
                        : $t(
                              'Describe a task. The checkout shows the wrong total when a coupon is applied…',
                          ))
                "
                :disabled="!!problem"
                @input="grow"
                @keydown.enter.exact.prevent="submit"
                @keydown.meta.enter.prevent="submit"
            />

            <div
                v-if="options && mode === 'code'"
                class="mx-4 mb-1 divide-y divide-(--ds-divider) border-t border-(--ds-divider) text-[13px]/5"
            >
                <label class="flex h-10 items-center gap-3">
                    <span class="w-24 shrink-0 text-(--ds-text-muted)">{{
                        $t('Setup')
                    }}</span>
                    <input
                        v-model="form.setup"
                        :class="field"
                        :placeholder="$t('npm ci, runs before it starts')"
                        spellcheck="false"
                    />
                </label>
                <label class="flex h-10 items-center gap-3">
                    <span class="w-24 shrink-0 text-(--ds-text-muted)">{{
                        $t('Check')
                    }}</span>
                    <input
                        v-model="form.check"
                        :class="field"
                        :placeholder="
                            $t('npm test, must pass before it hands back')
                        "
                        spellcheck="false"
                    />
                </label>
                <div class="flex h-10 items-center gap-3">
                    <span class="w-24 shrink-0 text-(--ds-text-muted)">{{
                        $t('Time limit')
                    }}</span>
                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                :class="[chip, '-ml-2 text-(--ds-text)']"
                            >
                                {{
                                    $t(':count min', {
                                        count: String(form.minutes),
                                    })
                                }}
                                <ChevronDown
                                    class="size-3 text-(--ds-text-faint)"
                                    :stroke-width="2"
                                />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="ds-modal min-w-32 font-ui"
                            align="start"
                        >
                            <DropdownMenuItem
                                v-for="m in [5, 10, 20, 30, 45, 60]"
                                :key="m"
                                @select="form.minutes = m"
                            >
                                {{ $t(':count min', { count: String(m) }) }}
                                <Check
                                    v-if="form.minutes === m"
                                    class="ml-auto size-3.5"
                                />
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </div>
                <div v-if="hasRepo" class="flex h-10 items-center gap-3">
                    <span class="w-24 shrink-0 text-(--ds-text-muted)">{{
                        $t('Pull request')
                    }}</span>
                    <span
                        class="min-w-0 flex-1 truncate text-(--ds-text-faint)"
                        >{{
                            $t(
                                'ServerOS opens it; the agent never sees the token.',
                            )
                        }}</span
                    >
                    <ToggleSwitch
                        v-model="form.pull_request"
                        :label="$t('Open a pull request')"
                    />
                </div>
            </div>

            <p
                v-if="
                    form.errors.prompt || form.errors.repo || form.errors.model
                "
                class="px-4 pb-1 text-[12px]/4 text-(--ds-red)"
            >
                {{
                    form.errors.prompt ?? form.errors.repo ?? form.errors.model
                }}
            </p>
            <div
                v-else-if="form.errors.pull_request"
                class="mx-4 mb-2 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-[9px] bg-(--ds-subtle) px-3 py-2.5 text-[12.5px]/5 text-(--ds-text-secondary) shadow-[inset_0_0_0_1px_var(--ds-border)]"
            >
                <span class="min-w-0 flex-1 basis-64">{{
                    form.errors.pull_request
                }}</span>
                <button
                    type="button"
                    :class="appButtonClasses('secondary', 'sm')"
                    :disabled="form.processing"
                    @click="sendWithoutPullRequests"
                >
                    {{ $t('Send without pull requests') }}
                </button>
            </div>

            <div class="flex flex-wrap items-center gap-0.5 px-2 pt-1 pb-2">
                <template v-if="mode === 'code'">
                    <button
                        type="button"
                        :class="[chip, 'w-7 justify-center px-0']"
                        :aria-expanded="options"
                        :aria-label="$t('Setup, check and time limit')"
                        :title="$t('Setup, check and time limit')"
                        @click="options = !options"
                    >
                        <Plus
                            class="size-4 transition-transform"
                            :class="{ 'rotate-45': options }"
                            :stroke-width="1.75"
                        />
                    </button>

                    <RepoPicker :repo="form.repo" @pick="pickRepo">
                        <template #trigger>
                            <button type="button" :class="[chip, 'max-w-56']">
                                <GitHubMark class="size-3.5 shrink-0" />
                                <span
                                    class="truncate"
                                    :class="{ 'text-(--ds-text)': hasRepo }"
                                    >{{
                                        hasRepo ? form.repo : $t('Repository')
                                    }}</span
                                >
                            </button>
                        </template>
                    </RepoPicker>
                    <BranchPicker
                        v-if="hasRepo"
                        :repo="form.repo"
                        :branch="form.branch || 'main'"
                        @pick="(b) => (form.branch = b)"
                    >
                        <template #trigger>
                            <button
                                type="button"
                                :class="[chip, 'max-w-40 max-sm:hidden']"
                            >
                                <GitBranch
                                    class="size-3.5 shrink-0"
                                    :stroke-width="1.75"
                                />
                                <span class="truncate">{{
                                    form.branch || 'main'
                                }}</span>
                            </button>
                        </template>
                    </BranchPicker>
                </template>

                <button
                    type="button"
                    :class="[chip, 'max-w-60']"
                    :disabled="!boxes.length && !hosting"
                    @click="choosing = true"
                >
                    <ServersIcon class="size-3.5 shrink-0" />
                    <span
                        class="truncate"
                        :class="{ 'text-(--ds-text)': placeLabel }"
                        >{{ placeLabel ?? $t('Machine') }}</span
                    >
                    <span
                        v-if="chosenBox && !chosenBox.online"
                        class="size-1.5 shrink-0 rounded-full bg-(--ds-amber)"
                        :title="$t('Offline')"
                    />
                </button>
                <MachineDialog
                    v-model:open="choosing"
                    :boxes="boxes"
                    :machine-id="form.machine_id"
                    :service="form.service"
                    :can-deploy="canManage"
                    :machine-only="mode === 'server'"
                    :hosting="mode === 'code' ? (hosting ?? null) : null"
                    :hosted="form.hosted"
                    :own-locked="!planAllows"
                    @pick="place"
                    @pick-hosted="pickHosted"
                />

                <span class="grow" />

                <template v-if="mode === 'code'">
                    <span
                        v-if="isPrivate"
                        class="inline-flex items-center gap-1 px-1.5 text-[12px]/4 text-(--ds-green)"
                        :title="
                            $t(
                                'The model runs on your machine. Your code and the prompt never leave it.',
                            )
                        "
                    >
                        <Lock class="size-3" :stroke-width="2" />
                        {{ $t('Private') }}
                    </span>

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button type="button" :class="chip">
                                <AiBrandIcon
                                    v-if="chosenAgent.brand"
                                    :brand="chosenAgent.brand"
                                    class="size-3.5 shrink-0"
                                />
                                <span class="text-(--ds-text)">{{
                                    form.model && chosenModel
                                        ? chosenModel.label
                                        : chosenAgent.name
                                }}</span>
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="ds-modal min-w-64 font-ui"
                            align="end"
                        >
                            <template v-for="(a, i) in agents" :key="a.id">
                                <DropdownMenuSeparator v-if="i > 0" />
                                <DropdownMenuLabel
                                    class="flex items-center gap-1.5 text-[11.5px]/4 font-medium text-(--ds-text-muted)"
                                >
                                    <AiBrandIcon
                                        v-if="a.brand"
                                        :brand="a.brand"
                                        class="size-3"
                                    />
                                    {{ a.name }}
                                </DropdownMenuLabel>
                                <DropdownMenuItem
                                    v-for="m in a.models"
                                    :key="m.id"
                                    @select="chooseAgent(a.id, m.id)"
                                >
                                    {{ m.label }}
                                    <Lock
                                        v-if="m.local"
                                        class="size-3 text-(--ds-green)"
                                        :stroke-width="2"
                                    />
                                    <Check
                                        v-if="
                                            form.agent === a.id &&
                                            form.model === m.id
                                        "
                                        class="ml-auto size-3.5"
                                    />
                                </DropdownMenuItem>
                            </template>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                :class="chip"
                                :title="$t('How many agents')"
                            >
                                <span class="text-(--ds-text) tabular-nums"
                                    >×{{ form.agents }}</span
                                >
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="ds-modal min-w-56 font-ui"
                            align="end"
                        >
                            <DropdownMenuLabel
                                class="text-[11.5px]/4 font-normal text-(--ds-text-muted)"
                            >
                                {{
                                    $t(
                                        'More agents try it side by side, each on its own branch. Keep the best.',
                                    )
                                }}
                            </DropdownMenuLabel>
                            <DropdownMenuItem
                                v-for="n in counts"
                                :key="n"
                                @select="form.agents = n"
                            >
                                {{
                                    n === 1
                                        ? $t('1 agent')
                                        : $t(':count agents', {
                                              count: String(n),
                                          })
                                }}
                                <Check
                                    v-if="form.agents === n"
                                    class="ml-auto size-3.5"
                                />
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button
                                type="button"
                                :class="chip"
                                :title="$t('When it starts')"
                            >
                                <Moon
                                    v-if="form.when === 'tonight'"
                                    class="size-3.5 text-(--ds-text)"
                                    :stroke-width="1.75"
                                />
                                <Clock
                                    v-else
                                    class="size-3.5"
                                    :stroke-width="1.75"
                                />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="ds-modal w-72 font-ui"
                            align="end"
                        >
                            <DropdownMenuItem @select="form.when = 'now'">
                                <span class="flex flex-col">
                                    <span>{{ $t('Now') }}</span>
                                    <span
                                        class="text-[11.5px]/4 text-(--ds-text-muted)"
                                        >{{
                                            $t(
                                                'As many as fit start now; the rest wait for room.',
                                            )
                                        }}</span
                                    >
                                </span>
                                <Check
                                    v-if="form.when === 'now'"
                                    class="ml-auto size-3.5"
                                />
                            </DropdownMenuItem>
                            <DropdownMenuItem @select="form.when = 'tonight'">
                                <span class="flex flex-col">
                                    <span>{{ $t('Tonight') }}</span>
                                    <span
                                        class="text-[11.5px]/4 text-(--ds-text-muted)"
                                        >{{
                                            $t(
                                                'After 1am, once the machine is quiet. The pull requests are there in the morning.',
                                            )
                                        }}</span
                                    >
                                </span>
                                <Check
                                    v-if="form.when === 'tonight'"
                                    class="ml-auto size-3.5"
                                />
                            </DropdownMenuItem>
                        </DropdownMenuContent>
                    </DropdownMenu>
                </template>

                <button
                    type="submit"
                    class="ml-1 flex size-8 shrink-0 cursor-pointer items-center justify-center rounded-full transition-[background-color,color,scale] duration-150 active:scale-95 disabled:cursor-default motion-reduce:active:scale-100"
                    :class="
                        ready
                            ? 'bg-(--ds-primary-bg) text-(--ds-primary-text) hover:bg-(--ds-primary-bg-hover)'
                            : 'bg-(--ds-hover) text-(--ds-text-faint)'
                    "
                    :disabled="!ready"
                    :aria-label="
                        mode === 'server'
                            ? $t('Ask the server agent')
                            : form.agents > 1
                              ? $t('Start :count agents', {
                                    count: String(form.agents),
                                })
                              : $t('Start the agent')
                    "
                >
                    <ArrowUp class="size-4" :stroke-width="2.25" />
                </button>
            </div>
        </div>

        <p
            v-if="mode === 'server'"
            class="mt-2.5 px-1 text-[12.5px]/5 text-white/90 [text-shadow:0_1px_2px_rgb(2_3_14/0.45)]"
        >
            {{ $t('It asks before it changes anything.') }}
        </p>
        <p
            v-else-if="form.hosted && hosting"
            class="mt-2.5 flex flex-wrap items-center gap-x-2 gap-y-1 px-1 text-[12.5px]/5 text-white/90 [text-shadow:0_1px_2px_rgb(2_3_14/0.45)]"
        >
            <template v-if="hosting.refusal">
                {{
                    hosting.hasTime
                        ? hosting.refusal
                        : $t(
                              'Runs on ServerOS’s machines, so there’s no server to set up. :price to start.',
                              { price: hosting.price ?? '' },
                          )
                }}
                <button
                    v-if="!hosting.hasTime"
                    type="button"
                    class="cursor-pointer font-medium text-white underline decoration-white/40 underline-offset-4 hover:decoration-white"
                    @click="choosing = true"
                >
                    {{ $t('Buy agent time') }}
                </button>
            </template>
            <template v-else>
                <span v-if="hosting.error">{{ hosting.error }}</span>
                {{
                    hosting.ready
                        ? $t(':balance of agent time remaining.', {
                              balance: hosting.balance ?? '',
                          })
                        : $t(
                              ':balance of agent time remaining. Cloud agents may take a few minutes to start.',
                              { balance: hosting.balance ?? '' },
                          )
                }}
            </template>
        </p>
        <p
            v-else-if="fits && fits.wait > 0"
            class="mt-2.5 px-1 text-[12.5px]/5 text-white/90 [text-shadow:0_1px_2px_rgb(2_3_14/0.45)]"
        >
            {{
                fits.now
                    ? $t(':now start now, :wait wait for room', {
                          now: String(fits.now),
                          wait: String(fits.wait),
                      })
                    : $t('They wait for room on :machine', {
                          machine: chosenBox?.name ?? '',
                      })
            }}
        </p>
        <p
            v-if="
                mode === 'code' && hosting && !form.hosted && !hosting.hasTime
            "
            class="mt-2.5 flex flex-wrap items-center gap-x-2 gap-y-1 px-1 text-[12.5px]/5 text-white/85 [text-shadow:0_1px_2px_rgb(2_3_14/0.45)]"
        >
            <span
                class="rounded-full bg-white/15 px-1.5 py-px text-[10.5px]/4 font-medium tracking-wide text-white uppercase [text-shadow:none]"
                >{{ $t('New') }}</span
            >
            {{
                $t(
                    'Run agents on ServerOS’s machines instead, with nothing to set up. :price.',
                    { price: hosting.price ?? '' },
                )
            }}
            <button
                type="button"
                class="cursor-pointer font-medium text-white underline decoration-white/40 underline-offset-4 hover:decoration-white"
                @click="choosing = true"
            >
                {{ $t('See how') }}
            </button>
        </p>
    </form>
</template>
