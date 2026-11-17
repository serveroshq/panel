<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router, useForm } from '@inertiajs/vue3';
import {
    ArrowUp,
    Box as BoxIcon,
    Check,
    ChevronDown,
    Clock,
    FileText,
    GitBranch,
    Lock,
    Plus,
    Server,
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
import BranchPicker from './BranchPicker.vue';
import GitHubMark from './GitHubMark.vue';
import MachineDialog from './MachineDialog.vue';
import RepoPicker from './RepoPicker.vue';
import type { StartContext } from './agentTasks';

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
    setup: '',
    check: '',
    minutes: 20,
    context: props.context?.ref ?? '',
    when: 'now' as 'now' | 'tonight',
});

// Code: an agent works on a repository in a container. Server: the server
// agent looks at the machine itself and asks before it changes anything.
const mode = ref<'code' | 'server'>('code');

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
    if (!chosenBox.value) return null;
    const container = chosenContainer.value;
    if (mode.value === 'server') return chosenBox.value.name;

    return container && !container.agent
        ? `${chosenBox.value.name} · ${container.name}`
        : chosenBox.value.name;
});

function place(machineId: number, key: string): void {
    form.machine_id = machineId;
    if (mode.value === 'code') form.service = key;
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

const counts = [1, 2, 3, 5];
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
    if (!props.planAllows) return trans('Agents come with Pro.');
    if (!props.twoFactor)
        return trans('Turn on two-factor authentication to run agents.');
    if (!props.boxes.length)
        return trans('Connect a machine first: agents run on your own.');

    return null;
});

const ready = computed(
    () =>
        problem.value === null &&
        form.prompt.trim() !== '' &&
        !form.processing &&
        (mode.value === 'server' ? form.machine_id > 0 : !!form.service),
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
    })).post('/agents', { preserveScroll: true });
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

const chip =
    'inline-flex h-8 min-w-0 cursor-pointer items-center gap-1.5 rounded-[8px] px-2 text-[13px]/4 text-(--ds-text-secondary) transition-colors hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring)';
const field =
    'h-8 w-full rounded-[8px] border border-(--ds-border-strong) bg-(--ds-field) px-2.5 font-ui-mono text-[12px]/4 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) focus-visible:border-(--ds-border-hover)';
</script>

<template>
    <form
        class="agent-composer rounded-[18px] border border-(--ds-border) bg-(--ds-subtle) p-1.5 shadow-[0_12px_32px_-18px_rgb(0_0_0/0.45)]"
        @submit.prevent="submit"
    >
        <div
            class="rounded-[13px] border border-(--ds-border-strong) bg-(--ds-card)"
        >
            <!-- What the task starts from: a failed deploy, logs, a monitor. -->
            <div
                v-if="context && form.context && mode === 'code'"
                class="mx-3 mt-3 rounded-[10px] border border-(--ds-border) bg-(--ds-subtle)"
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
                class="block max-h-80 w-full resize-none bg-transparent px-4 pt-3.5 pb-1 text-[14.5px]/6 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint)"
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
                class="grid gap-2 px-3 pt-1 pb-2 sm:grid-cols-[1fr_1fr_8rem]"
            >
                <label class="flex flex-col gap-1">
                    <span class="text-[11.5px]/4 text-(--ds-text-muted)">{{
                        $t('Setup, before it starts')
                    }}</span>
                    <input
                        v-model="form.setup"
                        :class="field"
                        placeholder="npm ci"
                        spellcheck="false"
                    />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-[11.5px]/4 text-(--ds-text-muted)">{{
                        $t('Check that must pass')
                    }}</span>
                    <input
                        v-model="form.check"
                        :class="field"
                        placeholder="npm test"
                        spellcheck="false"
                    />
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-[11.5px]/4 text-(--ds-text-muted)">{{
                        $t('Time limit')
                    }}</span>
                    <select v-model.number="form.minutes" :class="field">
                        <option
                            v-for="m in [5, 10, 20, 30, 45, 60]"
                            :key="m"
                            :value="m"
                        >
                            {{ $t(':count min', { count: String(m) }) }}
                        </option>
                    </select>
                </label>
                <label
                    v-if="hasRepo"
                    class="flex items-center gap-2 text-[12.5px]/5 text-(--ds-text-secondary) sm:col-span-3"
                >
                    <input v-model="form.pull_request" type="checkbox" />
                    {{
                        $t(
                            'Open a pull request with what it changed. ServerOS pushes it; the agent never sees the GitHub token.',
                        )
                    }}
                </label>
            </div>

            <div class="flex flex-wrap items-center gap-0.5 px-2 pb-2">
                <div
                    class="ds-segments mr-1 [&>button]:px-2! [&>button]:py-0.5! [&>button]:text-[12.5px]!"
                    role="group"
                    :aria-label="$t('What it works on')"
                >
                    <button
                        type="button"
                        class="cursor-pointer gap-1"
                        :aria-pressed="mode === 'code'"
                        @click="mode = 'code'"
                    >
                        <GitHubMark class="size-3" />
                        {{ $t('Code') }}
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer gap-1"
                        :aria-pressed="mode === 'server'"
                        :title="
                            $t(
                                'The server agent reads the machine’s logs and services, and asks before it changes anything.',
                            )
                        "
                        @click="mode = 'server'"
                    >
                        <Server class="size-3" :stroke-width="2" />
                        {{ $t('Server') }}
                    </button>
                </div>

                <template v-if="mode === 'code'">
                    <button
                        type="button"
                        :class="[chip, 'w-8 justify-center px-0']"
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

                    <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                            <button type="button" :class="chip">
                                <AiBrandIcon
                                    v-if="chosenAgent.brand"
                                    :brand="chosenAgent.brand"
                                    class="size-3.5 shrink-0"
                                />
                                <span class="font-medium text-(--ds-text)">{{
                                    chosenAgent.name
                                }}</span>
                                <span
                                    v-if="form.model"
                                    class="max-w-40 truncate text-(--ds-text-muted)"
                                    >{{
                                        chosenModel?.label ?? form.model
                                    }}</span
                                >
                                <ChevronDown
                                    class="size-3 text-(--ds-text-faint)"
                                    :stroke-width="2"
                                />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="ds-modal min-w-64 font-ui"
                            align="start"
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
                            <button type="button" :class="chip">
                                <span class="tabular-nums">{{
                                    form.agents === 1
                                        ? $t('1 agent')
                                        : $t(':count agents', {
                                              count: String(form.agents),
                                          })
                                }}</span>
                                <ChevronDown
                                    class="size-3 text-(--ds-text-faint)"
                                    :stroke-width="2"
                                />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="ds-modal min-w-56 font-ui"
                            align="start"
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
                            <button type="button" :class="chip">
                                <Clock class="size-3.5" :stroke-width="1.75" />
                                {{
                                    form.when === 'now'
                                        ? $t('Now')
                                        : $t('Tonight')
                                }}
                                <ChevronDown
                                    class="size-3 text-(--ds-text-faint)"
                                    :stroke-width="2"
                                />
                            </button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent
                            class="ds-modal w-72 font-ui"
                            align="start"
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

                    <span
                        v-if="isPrivate"
                        class="ml-1 inline-flex items-center gap-1 text-[12px]/4 text-(--ds-green)"
                        :title="
                            $t(
                                'The model runs on your machine. Your code and the prompt never leave it.',
                            )
                        "
                    >
                        <Lock class="size-3" :stroke-width="2" />
                        {{ $t('Private') }}
                    </span>
                </template>
                <span
                    v-else
                    class="px-1 text-[12px]/4 text-(--ds-text-muted)"
                    >{{ $t('It asks before it changes anything.') }}</span
                >

                <span
                    v-if="
                        form.errors.prompt ||
                        form.errors.repo ||
                        form.errors.model
                    "
                    class="ml-auto truncate pr-2 text-[12px]/4 text-(--ds-red)"
                    >{{
                        form.errors.prompt ??
                        form.errors.repo ??
                        form.errors.model
                    }}</span
                >
                <span
                    v-else-if="mode === 'code' && fits && fits.wait > 0"
                    class="ml-auto pr-2 text-[12px]/4 text-(--ds-text-muted)"
                    >{{
                        fits.now
                            ? $t(':now start now, :wait wait for room', {
                                  now: String(fits.now),
                                  wait: String(fits.wait),
                              })
                            : $t('They wait for room on :machine', {
                                  machine: chosenBox?.name ?? '',
                              })
                    }}</span
                >
            </div>
        </div>

        <div class="flex items-center gap-1 pt-1.5 pr-0 pl-1.5">
            <template v-if="mode === 'code'">
                <RepoPicker :repo="form.repo" @pick="pickRepo">
                    <template #trigger>
                        <button type="button" :class="[chip, 'max-w-64']">
                            <GitHubMark class="size-3.5 shrink-0" />
                            <span
                                class="truncate"
                                :class="
                                    hasRepo
                                        ? 'text-(--ds-text)'
                                        : 'text-(--ds-text-faint)'
                                "
                                >{{
                                    hasRepo ? form.repo : $t('Repository')
                                }}</span
                            >
                            <ChevronDown
                                class="size-3 shrink-0 text-(--ds-text-faint)"
                                :stroke-width="2"
                            />
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
                            :class="[chip, 'max-w-48 max-sm:hidden']"
                        >
                            <GitBranch
                                class="size-3.5 shrink-0"
                                :stroke-width="1.75"
                            />
                            <span class="truncate font-ui-mono text-[12px]">{{
                                form.branch || 'main'
                            }}</span>
                        </button>
                    </template>
                </BranchPicker>
            </template>

            <button
                type="button"
                :class="[chip, 'ml-auto max-w-64']"
                :disabled="!boxes.length"
                @click="choosing = true"
            >
                <BoxIcon class="size-3.5 shrink-0" :stroke-width="1.75" />
                <span
                    class="truncate"
                    :class="
                        placeLabel
                            ? 'text-(--ds-text)'
                            : 'text-(--ds-text-faint)'
                    "
                    >{{ placeLabel ?? $t('Choose a machine') }}</span
                >
                <span
                    v-if="chosenBox && !chosenBox.online"
                    class="size-1.5 shrink-0 rounded-full bg-(--ds-amber)"
                    :title="$t('Offline')"
                />
                <ChevronDown
                    class="size-3 shrink-0 text-(--ds-text-faint)"
                    :stroke-width="2"
                />
            </button>
            <MachineDialog
                v-model:open="choosing"
                :boxes="boxes"
                :machine-id="form.machine_id"
                :service="form.service"
                :can-deploy="canManage"
                :machine-only="mode === 'server'"
                @pick="place"
            />

            <button
                type="submit"
                class="flex size-10 shrink-0 cursor-pointer items-center justify-center rounded-[11px] transition-[background-color,color,scale] active:scale-95 disabled:cursor-default"
                :class="
                    ready
                        ? 'bg-[#1C4BB0] text-white hover:bg-[#2257C7]'
                        : 'bg-(--ds-card) text-(--ds-text-faint)'
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
    </form>
</template>
