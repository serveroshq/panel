<script setup lang="ts">
import { Head, router, usePage } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, useTemplateRef } from 'vue';
import AgentComposer from '@/components/agents/AgentComposer.vue';
import AgentSky from '@/components/agents/AgentSky.vue';
import AgentsIcon from '@/components/icons/AgentsIcon.vue';
import CodeIcon from '@/components/icons/CodeIcon.vue';
import ServersIcon from '@/components/icons/ServersIcon.vue';
import { useIsDark } from '@/composables/useAppearance';
import { docsUrl } from '@/lib/docs';
import type { Box } from '@/components/agents/AgentComposer.vue';
import type {
    Hosting,
    StartContext,
    TaskRow,
} from '@/components/agents/agentTasks';
import PlanLock from '@/components/workspace/PlanLock.vue';
import type { Workspace, WorkspaceAccess } from '@/types';

const props = defineProps<{
    workspace: Workspace;
    tasks: TaskRow[];
    boxes: Box[];
    planAllows: boolean;
    twoFactor: boolean;
    context: StartContext | null;
    hosting?: Hosting | null;
}>();

const isDark = useIsDark();

const page = usePage<{
    workspaceAccess: WorkspaceAccess | null;
    docsUrl: string;
}>();
const docsBase = computed(() => page.props.docsUrl);
// What the docs cover: code, your machines, agents.
const glimpse = [CodeIcon, ServersIcon, AgentsIcon];
const canManage = computed(
    () =>
        page.props.workspaceAccess?.permissions.includes('manage-machines') ??
        false,
);

const composer = useTemplateRef<InstanceType<typeof AgentComposer>>('composer');

// Ways in for a first task; each fills the prompt to finish in your words.
const starters = [
    { label: trans('Fix a bug'), prompt: trans('Fix this bug: ') },
    {
        label: trans('Add tests'),
        prompt: trans('Add tests for '),
    },
    {
        label: trans('Find security problems'),
        prompt: trans(
            'Review this repository for security problems. Change nothing. List the most important first, each with the file and line and why it matters.',
        ),
    },
    {
        label: trans('Update dependencies'),
        prompt: trans(
            'Update dependencies with patch and minor releases only, fix anything that breaks, and keep the change small.',
        ),
    },
    {
        label: trans('Explain the code'),
        prompt: trans('Explain how this works, and change nothing: '),
    },
];

const working = computed(
    () => props.tasks.filter((t) => t.status === 'running').length,
);

const busy = computed(
    () => working.value > 0 || props.tasks.some((t) => t.status === 'queued'),
);

let poll: ReturnType<typeof setInterval> | undefined;
onMounted(() => {
    poll = setInterval(() => {
        if (busy.value && document.visibilityState === 'visible') {
            router.reload({ only: ['tasks'] });
        }
    }, 3000);
});
onBeforeUnmount(() => clearInterval(poll));
</script>

<template>
    <Head :title="$t('Agents')" />

    <div
        class="workspace-panel flex min-h-full flex-col font-ui text-(--ds-text)"
    >
        <!-- The whole page is sky: dark cloud on the dark dashboard, cobalt on the light one. -->
        <section
            class="relative isolate flex min-h-full flex-1 overflow-hidden"
            :class="isDark ? 'bg-[#090a0e]' : 'bg-[#0b2a8a]'"
        >
            <AgentSky
                class="absolute inset-0 -z-10"
                :tone="isDark ? 'dark' : 'cobalt'"
            />
            <div
                aria-hidden="true"
                class="pointer-events-none absolute inset-0 -z-10"
                :class="
                    isDark
                        ? 'bg-[radial-gradient(75%_60%_at_50%_45%,transparent_0%,rgb(6_7_10/.35)_100%)]'
                        : 'bg-[linear-gradient(180deg,rgb(8_26_100/.55)_0%,rgb(8_26_100/.2)_40%,transparent_70%)]'
                "
            />

            <div
                class="m-auto flex w-full max-w-3xl flex-col px-4 py-16 sm:py-20"
            >
                <h1
                    class="text-center text-[28px]/9 font-semibold tracking-[-0.025em] text-balance text-white sm:text-[34px]/10"
                >
                    {{ $t('What should an agent work on?') }}
                </h1>
                <p
                    class="mx-auto mt-2 max-w-xl text-center text-[14px]/5 text-balance text-white/85"
                >
                    {{
                        hosting
                            ? $t(
                                  'Claude Code, Codex or OpenCode, on your machines or ours. Each agent hands back a pull request.',
                              )
                            : $t(
                                  'Claude Code, Codex or OpenCode on your own machines. Each agent hands back a pull request.',
                              )
                    }}
                </p>
                <!-- Read more, like the marketing site's GlimpseLink: tiles hinting at what's behind it, then an arrow. -->
                <a
                    :href="docsUrl(docsBase, '/guides/agents')"
                    target="_blank"
                    rel="noopener"
                    class="group mx-auto mt-5 inline-flex h-10 items-center gap-2.5 rounded-[10px] bg-(--ds-card) py-1 pr-1 pl-1.5 text-[13.5px]/[18px] font-medium whitespace-nowrap text-(--ds-text) shadow-[0_0_0_1px_rgb(2_3_14/.06),0_8px_24px_-12px_rgb(2_3_14/.55)] transition-shadow duration-200 hover:shadow-[0_0_0_1px_rgb(2_3_14/.08),0_12px_28px_-12px_rgb(2_3_14/.7)] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white/80 dark:shadow-[0_0_0_1px_rgb(255_255_255/.08),0_8px_24px_-12px_rgb(0_0_0/.8)]"
                >
                    <span aria-hidden="true" class="flex">
                        <span
                            v-for="(icon, i) in glimpse"
                            :key="i"
                            class="grid size-7 place-items-center rounded-[7px] bg-(--ds-hover) shadow-[0_0_0_2px_var(--ds-card)] transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] motion-reduce:transition-none"
                            :class="{
                                '-ml-2': i > 0,
                                'group-hover:-translate-x-0.5': i === 0,
                                'group-hover:translate-x-0.5':
                                    i === glimpse.length - 1,
                            }"
                        >
                            <component
                                :is="icon"
                                class="size-4 text-(--ds-text-muted) transition-colors duration-300 group-hover:text-(--ds-blue)"
                            />
                        </span>
                    </span>
                    {{ $t('Learn what agents can do') }}
                    <span
                        aria-hidden="true"
                        class="grid size-8 place-items-center rounded-[7px] bg-(--ds-hover) transition-colors duration-200 group-hover:bg-(--ds-blue) group-hover:text-white"
                    >
                        <ArrowRight
                            class="size-3.5 transition-transform duration-300 ease-[cubic-bezier(0.32,0.72,0,1)] group-hover:translate-x-0.5 motion-reduce:transition-none"
                            :stroke-width="2"
                        />
                    </span>
                </a>

                <PlanLock
                    :locked="!planAllows && !hosting"
                    feature="Agents"
                    plan="pro"
                    :description="
                        $t(
                            'Run coding agents on the machines you already pay for, side by side, with a pull request at the end.',
                        )
                    "
                >
                    <AgentComposer
                        ref="composer"
                        class="mt-8 w-full"
                        :boxes="boxes"
                        :two-factor="twoFactor"
                        :plan-allows="planAllows"
                        :can-manage="canManage"
                        :context="context"
                        :hosting="hosting"
                    />
                </PlanLock>

                <div
                    v-if="!context && canManage"
                    class="mt-4 flex flex-wrap justify-center gap-x-5 gap-y-1"
                >
                    <button
                        v-for="s in starters"
                        :key="s.label"
                        type="button"
                        class="cursor-pointer rounded-[4px] text-[13px]/6 text-white/90 transition-colors [text-shadow:0_1px_2px_rgb(2_3_14/0.45)] hover:text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-white/70"
                        @click="composer?.suggest(s.prompt)"
                    >
                        {{ s.label }}
                    </button>
                </div>
            </div>
        </section>
    </div>
</template>
