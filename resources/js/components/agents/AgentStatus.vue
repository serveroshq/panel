<script setup lang="ts">
import { toneDot } from './agentTasks';
import type { Tone } from './agentTasks';

defineProps<{ label: string; tone: Tone; busy?: boolean }>();
</script>

<template>
    <span class="inline-flex min-w-0 items-center gap-1.5">
        <span class="relative flex size-1.5 shrink-0" aria-hidden="true">
            <span
                v-if="busy"
                class="absolute inset-0 animate-ping rounded-full opacity-60 motion-reduce:hidden"
                :class="toneDot[tone]"
            />
            <span
                class="relative size-1.5 rounded-full"
                :class="toneDot[tone]"
            />
        </span>
        <span class="truncate">{{ label }}</span>
    </span>
</template>
