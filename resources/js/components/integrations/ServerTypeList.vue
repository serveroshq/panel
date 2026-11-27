<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import type { ServerTypeOption } from '@/components/integrations/integrations';
import { money, sizeLine } from '@/components/integrations/integrations';

const {
    types,
    currency,
    location = null,
    label,
} = defineProps<{
    types: ServerTypeOption[];
    currency: string;
    location?: string | null;
    label: string;
}>();

const picked = defineModel<string | null>({ default: null });

function monthly(type: ServerTypeOption): number | null {
    if (type.monthly !== undefined && type.monthly !== null)
        return type.monthly;
    if (location && type.prices?.[location])
        return type.prices[location].monthly;

    return null;
}

function kind(type: ServerTypeOption): string {
    const cpu = type.cpu === 'dedicated' ? trans('Dedicated') : trans('Shared');

    return type.architecture === 'arm' ? `${cpu} · Arm` : `${cpu} · x86`;
}
</script>

<template>
    <div
        role="radiogroup"
        :aria-label="label"
        class="flex max-h-[264px] flex-col overflow-y-auto rounded-[10px] border-[0.5px] border-edge-card bg-surface-card"
    >
        <button
            v-for="type in types"
            :key="type.name"
            type="button"
            role="radio"
            :aria-checked="picked === type.name"
            class="flex w-full cursor-pointer items-center gap-3 border-t-[0.5px] border-edge py-2 pr-3 pl-3 text-left first:border-t-0 hover:bg-surface-hover focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
            @click="picked = type.name"
        >
            <span
                class="flex size-[15px] shrink-0 items-center justify-center rounded-full border-[0.5px]"
                :class="
                    picked === type.name
                        ? 'border-dash-blue bg-dash-blue'
                        : 'border-edge-checkbox bg-surface-checkbox'
                "
                aria-hidden="true"
            >
                <span
                    v-if="picked === type.name"
                    class="size-[5px] rounded-full bg-white"
                />
            </span>
            <span class="flex min-w-0 flex-1 flex-col">
                <span
                    class="text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                    >{{ type.label }}</span
                >
                <span class="truncate text-[12px]/4 text-fg-muted"
                    >{{ sizeLine(type) }} · {{ kind(type) }}</span
                >
            </span>
            <span
                v-if="monthly(type) !== null"
                class="text-right text-[12.5px]/4 whitespace-nowrap text-fg-secondary tabular-nums"
                >{{
                    $t(':price a month', {
                        price: money(monthly(type) ?? 0, currency),
                    })
                }}</span
            >
        </button>
        <p
            v-if="types.length === 0"
            class="px-3.5 py-3 text-[13px]/5 text-fg-muted"
        >
            {{ $t('No server types fit here.') }}
        </p>
    </div>
</template>
