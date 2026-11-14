<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { LoaderCircle } from '@lucide/vue';
import { computed } from 'vue';
import type { BuildingService } from '@/components/machines/buildingServices';
import {
    serviceColumns,
    serviceColumnsWithMachine,
} from '@/components/machines/serviceFormat';
import ServiceLogo from '@/components/machines/ServiceLogo.vue';

const props = defineProps<{
    entry: BuildingService;
    canManage?: boolean;
    machineName?: string;
}>();

const emit = defineEmits<{ open: [] }>();

const stateLabel = computed(
    () =>
        ({
            queued: trans('Queued'),
            building: trans('Building'),
            starting: trans('Starting'),
            failed: trans('Failed'),
        })[props.entry.state],
);

const phaseLabel = computed(() => {
    const { phase, progress, state } = props.entry;
    return [
        trans('Deploy'),
        phase ? phase.charAt(0).toUpperCase() + phase.slice(1) : null,
        progress !== null && state === 'building'
            ? `${Math.round(progress)}%`
            : null,
    ]
        .filter(Boolean)
        .join(' · ');
});
</script>

<template>
    <button
        type="button"
        class="w-full cursor-pointer px-4 py-2.5 text-left text-[13px]/4 transition-colors hover:bg-(--ds-row-hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
        :class="
            machineName === undefined
                ? serviceColumns
                : serviceColumnsWithMachine
        "
        role="row"
        :aria-label="
            $t(':service: :state. Open the deploy log', {
                service: entry.name,
                state: stateLabel,
            })
        "
        @click="emit('open')"
    >
        <span class="flex min-w-0 items-center gap-3" role="cell">
            <span
                v-if="canManage"
                class="size-4 shrink-0 rounded-sm border-[1.5px] border-(--ds-border-hover) opacity-50"
                aria-hidden="true"
            />
            <ServiceLogo :name="entry.name" kind="" />
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="truncate font-medium">{{ entry.name }}</span>
                <span class="truncate text-xs text-(--ds-text-muted)">{{
                    phaseLabel
                }}</span>
            </span>
        </span>
        <span
            v-if="machineName !== undefined"
            class="hidden truncate text-(--ds-text-secondary) md:block"
            role="cell"
        >
            {{ machineName }}
        </span>
        <span
            class="flex items-center gap-1.75"
            :class="machineName === undefined ? 'md:col-start-2' : ''"
            role="cell"
        >
            <LoaderCircle
                v-if="entry.state !== 'failed'"
                class="size-3 shrink-0 animate-spin text-(--ds-amber)"
                :stroke-width="2.4"
            />
            <span v-else class="size-1.5 shrink-0 rounded-full bg-(--ds-red)" />
            {{ stateLabel }}
        </span>
        <span
            v-for="n in 4"
            :key="n"
            class="hidden text-(--ds-text-faint) md:block"
            role="cell"
            >—</span
        >
        <span role="cell" class="hidden md:block" />
    </button>
</template>
