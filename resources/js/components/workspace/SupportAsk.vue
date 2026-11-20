<script setup lang="ts">
import { X } from '@lucide/vue';
import { ref } from 'vue';
import { appButtonClasses } from '@/lib/appButton';
import { SolarHeartPulse as Heart } from '@/lib/solarIcons';

const props = defineProps<{
    ask: { donateUrl: string; licenseUrl: string };
    workspaceId: number;
}>();

// Dismissed for a few months, in this browser. Storage can be blocked or
// empty (a private window), and then the card simply shows again.
const KEY = `serveros:support-ask:${props.workspaceId}`;
const AWAY_DAYS = 90;

function dismissedRecently(): boolean {
    try {
        const at = Number(localStorage.getItem(KEY));
        return at > 0 && Date.now() - at < AWAY_DAYS * 86_400_000;
    } catch {
        return false;
    }
}

const shown = ref(!dismissedRecently());

function dismiss(): void {
    shown.value = false;
    try {
        localStorage.setItem(KEY, String(Date.now()));
    } catch {
        // Nothing to remember it in: it shows again next time.
    }
}
</script>

<template>
    <section
        v-if="shown"
        class="ds-tile relative flex flex-col gap-4 px-5 py-4 sm:flex-row sm:items-center"
        :aria-label="$t('Support ServerOS')"
    >
        <span
            class="flex size-10 shrink-0 items-center justify-center rounded-lg border border-(--ds-border) bg-(--ds-field) text-(--ds-text-secondary)"
            aria-hidden="true"
        >
            <Heart class="size-5" />
        </span>
        <div class="min-w-0 flex-1 pr-6 sm:pr-0">
            <h2 class="text-[14px]/5 font-medium">
                {{ $t('Enjoying ServerOS? Help keep it going.') }}
            </h2>
            <p class="mt-0.5 text-[13px]/5 text-(--ds-text-muted)">
                {{
                    $t(
                        'Self-hosting is free and the code is open. A donation pays for the time that goes into it, and a Business license adds single sign-on, custom roles and a status page for every machine.',
                    )
                }}
            </p>
        </div>
        <div class="flex shrink-0 flex-wrap items-center gap-2 sm:mr-6">
            <a
                :href="ask.donateUrl"
                target="_blank"
                rel="noopener"
                :class="appButtonClasses('primary', 'sm')"
                @click="dismiss"
                >{{ $t('Donate') }}</a
            >
            <a
                :href="ask.licenseUrl"
                target="_blank"
                rel="noopener"
                :class="appButtonClasses('secondary', 'sm')"
                @click="dismiss"
                >{{ $t('Get a license') }}</a
            >
            <button
                type="button"
                :class="appButtonClasses('quiet', 'sm')"
                @click="dismiss"
            >
                {{ $t('Maybe later') }}
            </button>
        </div>
        <button
            type="button"
            class="absolute top-3 right-3 flex size-7 cursor-pointer items-center justify-center rounded-md text-(--ds-text-faint) hover:bg-(--ds-hover) hover:text-(--ds-text) focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
            :aria-label="$t('Close')"
            @click="dismiss"
        >
            <X class="size-4" :stroke-width="1.75" />
        </button>
    </section>
</template>
