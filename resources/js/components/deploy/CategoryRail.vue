<script setup lang="ts">
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { OWN_CODE, RAIL } from './catalog';

defineProps<{ counts: Record<string, number> }>();

const category = defineModel<string>({ required: true });

const item =
    'group/rail relative flex h-8 w-full shrink-0 cursor-pointer items-center gap-2.5 rounded-[6px] px-2.5 text-left font-ui text-[13px]/5 font-medium tracking-[-0.01em] text-fg-tertiary transition-colors duration-100 hover:text-fg-secondary focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring) aria-pressed:bg-white aria-pressed:text-fg-primary aria-pressed:shadow-[0_1px_2px_rgb(0_0_0/0.08),0_0_0_0.5px_rgb(0_0_0/0.1)] dark:aria-pressed:bg-[#212121] dark:aria-pressed:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)]';
const icon = 'text-fg-tertiary group-aria-pressed/rail:text-fg-primary';
</script>

<template>
    <nav
        class="hidden w-[212px] shrink-0 flex-col gap-0.5 overflow-y-auto border-r-[0.5px] border-[#e8e8e8] bg-[#f7f7f7] px-2.5 py-3 sm:flex dark:border-[#262626] dark:bg-[#141414]"
        :aria-label="$t('Category')"
    >
        <button
            type="button"
            :class="item"
            :aria-pressed="category === OWN_CODE"
            @click="category = OWN_CODE"
        >
            <FilledIcon name="code-fill" :class="icon" />
            <span class="min-w-0 flex-1 truncate">{{
                $t('Your own code')
            }}</span>
        </button>
        <div
            class="my-1.5 h-[0.5px] w-full shrink-0 bg-[#e8e8e8] dark:bg-[#262626]"
            aria-hidden="true"
        />
        <button
            v-for="option in RAIL"
            :key="option.value"
            type="button"
            :class="item"
            :aria-pressed="category === option.value"
            @click="category = option.value"
        >
            <FilledIcon :name="option.icon" :class="icon" />
            <span class="min-w-0 flex-1 truncate">{{
                option.value === 'All' ? $t('All services') : $t(option.value)
            }}</span>
            <span
                class="shrink-0 font-ui-mono text-[12px]/4 font-normal text-fg-faint tabular-nums group-aria-pressed/rail:text-fg-secondary"
                >{{ counts[option.value] ?? 0 }}</span
            >
        </button>
    </nav>
</template>
