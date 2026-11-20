<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Check, SquareTerminal, X } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import AiBrandIcon from '@/components/workspace/AiBrandIcon.vue';
import type { AiBrand } from '@/lib/aiBrands';
import { useIsDark } from '@/composables/useAppearance';
import AgentSky from './AgentSky.vue';

export type Setup = {
    steps: { key: string; label: string; state: string }[];
    startedAt: string | null;
    region: string | null;
    waiting: number;
    error: string | null;
};

const props = defineProps<{
    setup: Setup;
    prompt: string;
    /** "acme/shop · main": what it works on, in one line. */
    meta: string;
    agent: string;
    agentName: string;
}>();

// The agent's own mark, in its colour; OpenCode has none, so a terminal.
const brand = computed<{ id: AiBrand; colour: string } | null>(() =>
    props.agent === 'claude'
        ? { id: 'claude', colour: 'text-[#D97757]' }
        : props.agent === 'codex'
          ? { id: 'codex', colour: 'text-white' }
          : null,
);

const isDark = useIsDark();

// The clock ticks here, between the page's polls.
const now = ref(Date.now());
const clock = setInterval(() => (now.value = Date.now()), 1000);
onBeforeUnmount(() => clearInterval(clock));

const elapsed = computed(() => {
    if (!props.setup.startedAt) return null;
    const s = Math.max(
        0,
        Math.floor((now.value - Date.parse(props.setup.startedAt)) / 1000),
    );

    return `${Math.floor(s / 60)}:${String(s % 60).padStart(2, '0')}`;
});

const mark =
    'flex size-7 items-center justify-center rounded-full bg-[#0b1a5c] shadow-[0_0_0_2px_rgb(13_31_110),inset_0_0_0_0.5px_rgb(255_255_255/.18)]';

const done = computed(
    () => props.setup.steps.filter((s) => s.state === 'done').length,
);
const dots = computed(() => Math.min(20, Math.max(1, props.setup.waiting)));

// Their words, with the line ServerOS adds for a pull request left out.
const words = computed(() =>
    props.prompt.replace(
        /^You’re working on the open pull request[^\n]*\n+/,
        '',
    ),
);
</script>

<template>
    <div
        class="relative isolate flex min-h-0 flex-1 overflow-y-auto"
        role="status"
        aria-live="polite"
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
                    : 'bg-[linear-gradient(180deg,rgb(8_26_100/.6)_0%,rgb(8_26_100/.3)_45%,rgb(8_26_100/.15)_100%)]'
            "
        />

        <div
            class="m-auto flex w-full max-w-2xl flex-col px-5 py-14 text-white sm:px-8"
        >
            <!-- Who's on it: ServerOS's machine and the agent, in one quiet line. -->
            <p
                class="inline-flex items-center gap-2.5 self-start rounded-full bg-[rgb(2_3_14/.35)] py-1 pr-3.5 pl-1 text-[12.5px]/5 text-white/70 shadow-[inset_0_0_0_0.5px_rgb(255_255_255/.14)] backdrop-blur-md"
            >
                <span class="flex" aria-hidden="true">
                    <span :class="mark">
                        <img
                            src="/images/landing/serveros-logo.svg"
                            alt=""
                            class="size-3.5 object-contain"
                        />
                    </span>
                    <span :class="[mark, '-ml-1.5']">
                        <AiBrandIcon
                            v-if="brand"
                            :brand="brand.id"
                            class="size-3.5"
                            :class="brand.colour"
                        />
                        <SquareTerminal
                            v-else
                            class="size-3.5 text-white"
                            :stroke-width="1.75"
                        />
                    </span>
                </span>
                <span>
                    <span class="font-medium text-white">{{
                        setup.error ? $t('Couldn’t start') : $t('Setting up')
                    }}</span>
                    <span aria-hidden="true" class="px-1.5 text-white/35"
                        >·</span
                    >{{ agentName
                    }}<template v-if="setup.region"
                        ><span aria-hidden="true" class="px-1.5 text-white/35"
                            >·</span
                        >{{ setup.region }}</template
                    >
                </span>
            </p>

            <blockquote
                class="mt-5 line-clamp-6 text-[24px]/[1.22] font-normal tracking-[-0.03em] text-pretty whitespace-pre-line sm:text-[30px]/[1.18]"
            >
                {{ words }}
            </blockquote>
            <p v-if="meta" class="mt-4 text-[13px]/5 text-white/55">
                {{ meta }}
            </p>

            <ol
                class="mt-10 divide-y divide-white/10 overflow-hidden rounded-[14px] bg-[rgb(2_3_14/.38)] shadow-[inset_0_0_0_0.5px_rgb(255_255_255/.14),0_24px_60px_-24px_rgb(2_3_14/.6)] backdrop-blur-md"
            >
                <li
                    v-for="step in setup.steps"
                    :key="step.key"
                    class="flex h-14 items-center gap-3.5 px-4 sm:px-5"
                >
                    <span
                        class="relative flex size-6 shrink-0 items-center justify-center rounded-full"
                        :class="{
                            'bg-white text-[#0b2a8a]': step.state === 'done',
                            'bg-[#e5484d] text-white': step.state === 'failed',
                            'shadow-[inset_0_0_0_1.5px_rgb(255_255_255/.25)]':
                                step.state === 'pending' ||
                                step.state === 'active',
                        }"
                        aria-hidden="true"
                    >
                        <Check
                            v-if="step.state === 'done'"
                            class="size-3.5"
                            :stroke-width="3"
                        />
                        <X
                            v-else-if="step.state === 'failed'"
                            class="size-3.5"
                            :stroke-width="3"
                        />
                        <span
                            v-else-if="step.state === 'active'"
                            class="absolute inset-0 animate-spin rounded-full border-[1.5px] border-transparent border-t-white motion-reduce:animate-none"
                        />
                    </span>
                    <span
                        class="min-w-0 flex-1 truncate text-[14.5px]/5"
                        :class="{
                            'text-white/45': step.state === 'pending',
                            'text-white/80': step.state === 'done',
                            'agent-setup-shimmer': step.state === 'active',
                        }"
                        >{{ step.label }}</span
                    >
                    <span
                        v-if="step.state === 'active' && step.key === 'agents'"
                        class="flex shrink-0 gap-1"
                        aria-hidden="true"
                    >
                        <span
                            v-for="n in dots"
                            :key="n"
                            class="size-1.5 animate-pulse rounded-full bg-white motion-reduce:animate-none"
                            :style="{ animationDelay: `${n * 90}ms` }"
                        />
                    </span>
                    <span
                        v-else-if="step.state === 'active' && elapsed"
                        class="shrink-0 text-[12.5px]/4 text-white/55 tabular-nums"
                        >{{ elapsed }}</span
                    >
                </li>
            </ol>

            <p
                v-if="setup.error"
                class="mt-5 text-[13.5px]/5 text-pretty text-white/85"
            >
                {{ setup.error }}
                <Link
                    href="/agents"
                    class="ml-1 font-medium text-white underline decoration-white/40 underline-offset-4 hover:decoration-white"
                    >{{ $t('Back to Agents') }}</Link
                >
            </p>
            <p v-else class="mt-5 text-[13px]/5 text-white/60">
                {{
                    $t(
                        'Usually ready in 3 to 5 minutes. You can leave this page: your agents start on their own, and the time it takes to set up isn’t taken from your agent time.',
                    )
                }}
                <span class="sr-only">{{
                    $t(':done of :total steps done', {
                        done: String(done),
                        total: String(setup.steps.length),
                    })
                }}</span>
            </p>
        </div>
    </div>
</template>

<style scoped>
.agent-setup-shimmer {
    background: linear-gradient(
            90deg,
            rgb(255 255 255 / 0.65) 0%,
            #fff 45%,
            rgb(255 255 255 / 0.65) 60%
        )
        0 0 / 220% 100%;
    background-clip: text;
    color: transparent;
    animation: agent-setup-shimmer 2.2s linear infinite;
}

@keyframes agent-setup-shimmer {
    from {
        background-position: 110% 0;
    }
    to {
        background-position: -110% 0;
    }
}

@media (prefers-reduced-motion: reduce) {
    .agent-setup-shimmer {
        animation: none;
        color: #fff;
    }
}
</style>
