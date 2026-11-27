<script setup lang="ts">
import { Check, X } from '@lucide/vue';
import type { Operation } from '@/components/integrations/integrations';

const { operation } = defineProps<{ operation: Operation }>();
</script>

<template>
    <div class="flex flex-col gap-2.5">
        <ol class="flex flex-col gap-2" :aria-label="$t('Progress')">
            <li
                v-for="step in operation.steps"
                :key="step.key"
                class="flex items-center gap-2.5 text-[13px]/5"
            >
                <span
                    class="flex size-[18px] shrink-0 items-center justify-center rounded-full border-[0.5px]"
                    :class="{
                        'border-[rgba(62,196,109,0.3)] bg-[rgba(62,196,109,0.14)] text-status-live':
                            step.state === 'done',
                        'border-[rgba(240,105,90,0.3)] bg-[rgba(240,105,90,0.14)] text-status-down':
                            step.state === 'failed',
                        'border-edge-strong bg-surface-badge':
                            step.state === 'current' || step.state === 'todo',
                    }"
                    aria-hidden="true"
                >
                    <Check
                        v-if="step.state === 'done'"
                        :size="11"
                        :stroke-width="2"
                    />
                    <X
                        v-else-if="step.state === 'failed'"
                        :size="11"
                        :stroke-width="2"
                    />
                    <svg
                        v-else-if="step.state === 'current'"
                        viewBox="0 0 16 16"
                        class="size-3 animate-spin motion-reduce:animate-none"
                    >
                        <path
                            d="M8 1.5a6.5 6.5 0 0 1 6.5 6.5"
                            fill="none"
                            stroke="#3d63ff"
                            stroke-width="2.2"
                            stroke-linecap="round"
                        />
                    </svg>
                </span>
                <span
                    :class="
                        step.state === 'todo'
                            ? 'text-fg-muted'
                            : step.state === 'failed'
                              ? 'text-status-down'
                              : 'text-fg-primary'
                    "
                    >{{ step.label }}</span
                >
                <span v-if="step.state === 'current'" class="sr-only">{{
                    $t('In progress')
                }}</span>
            </li>
        </ol>
        <p
            v-if="operation.state === 'failed' && operation.error"
            class="rounded-[8px] border-[0.5px] border-[rgba(240,105,90,0.25)] bg-[rgba(240,105,90,0.07)] px-3 py-2 text-[12px]/[18px] text-fg-secondary"
            role="alert"
        >
            {{ operation.error }}
        </p>
    </div>
</template>
