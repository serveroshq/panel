<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import AgentsIcon from '@/components/icons/AgentsIcon.vue';
import ServersIcon from '@/components/icons/ServersIcon.vue';

// ServerOS has two sides that work like two products: managing servers,
// and agents. Both sidebars carry this switch in the same place, so going
// between them feels like turning a page, not leaving.
const page = usePage<{ agentsPreview?: boolean }>();

const inAgents = computed(() => page.component.startsWith('workspace/Agent'));

const sides = computed(() => [
    { key: 'servers', label: 'Servers', href: '/', on: !inAgents.value },
    { key: 'agents', label: 'Agents', href: '/agents', on: inAgents.value },
]);
</script>

<template>
    <nav
        v-if="page.props.agentsPreview"
        class="grid grid-cols-2 gap-0.5 rounded-[10px] bg-(--ds-hover) p-[3px]"
        :aria-label="$t('ServerOS')"
    >
        <Link
            v-for="side in sides"
            :key="side.key"
            :href="side.href"
            class="flex h-7 items-center justify-center gap-1.5 rounded-[7px] text-[13px]/4 font-medium transition-[background-color,color,box-shadow] duration-200 focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-(--ds-ring)"
            :class="
                side.on
                    ? 'bg-(--ds-frame) text-(--ds-text) shadow-[0_1px_2px_rgb(0_0_0/.12),0_0_0_0.5px_var(--ds-border)]'
                    : 'text-(--ds-text-muted) hover:text-(--ds-text)'
            "
            :aria-current="side.on ? 'page' : undefined"
        >
            <ServersIcon
                v-if="side.key === 'servers'"
                class="size-[15px] shrink-0"
            />
            <AgentsIcon v-else class="size-[15px] shrink-0" />
            {{ $t(side.label) }}
        </Link>
    </nav>
</template>
