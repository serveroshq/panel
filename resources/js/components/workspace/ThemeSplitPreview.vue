<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, useTemplateRef } from 'vue';
import ThemePreview from '@/components/workspace/ThemePreview.vue';

// Light mode on the left, dark on the right. Drawn at one size and scaled,
// so a large preview looks like a small one, only bigger.
defineProps<{
    light: Record<string, string>;
    dark: Record<string, string>;
}>();

const WIDTH = 240;
const frame = useTemplateRef<HTMLElement>('frame');
const zoom = ref(1);
let observer: ResizeObserver | null = null;

onMounted(() => {
    if (!frame.value) return;
    observer = new ResizeObserver(([entry]) => {
        zoom.value = entry.contentRect.width / WIDTH;
    });
    observer.observe(frame.value);
});

onBeforeUnmount(() => observer?.disconnect());
</script>

<template>
    <span
        ref="frame"
        aria-hidden="true"
        class="relative block aspect-[16/10] overflow-hidden"
    >
        <span
            class="absolute top-0 left-0 block"
            :style="{ width: `${WIDTH}px`, height: `${WIDTH / 1.6}px`, zoom }"
        >
            <ThemePreview class="absolute inset-0" :colours="light" />
            <ThemePreview
                class="absolute inset-0 [clip-path:polygon(62%_0,100%_0,100%_100%,38%_100%)]"
                :colours="dark"
            />
        </span>
    </span>
</template>
