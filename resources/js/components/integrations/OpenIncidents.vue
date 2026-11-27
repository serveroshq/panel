<script setup lang="ts">
import DashTray from '@/components/dash/DashTray.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import type { OpenIncident } from '@/components/integrations/incidents';
import { timeAgo } from '@/lib/timeAgo';

defineProps<{
    incidents: OpenIncident[];
    title: string;
}>();

const icon: Record<OpenIncident['kind'], FilledIconName> = {
    monitor: 'monitors-fill',
    machine: 'machines-fill',
    cve: 'lock-fill',
};
</script>

<template>
    <DashTray
        icon="info-fill"
        :title="title"
        :meta="String(incidents.length)"
        panel-class="py-0.5"
    >
        <ul class="flex flex-col">
            <li
                v-for="incident in incidents"
                :key="incident.id"
                class="flex min-w-0 items-center gap-2.5 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
            >
                <span
                    class="size-2 shrink-0 rounded-full bg-status-down"
                    aria-hidden="true"
                />
                <FilledIcon
                    :name="icon[incident.kind]"
                    :size="14"
                    class="shrink-0 text-fg-muted"
                />
                <span
                    class="min-w-0 flex-1 truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                    >{{ incident.title }}</span
                >
                <a
                    v-if="incident.link"
                    :href="incident.link.url"
                    target="_blank"
                    rel="noopener"
                    class="flex shrink-0 items-center gap-1 rounded-[5px] px-1 text-[12px]/4 text-fg-secondary hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                    :class="{ 'font-ui-mono': incident.link.key }"
                    >{{ incident.link.key ?? $t('Open')
                    }}<FilledIcon
                        name="open-fill"
                        :size="12"
                        class="text-fg-muted"
                /></a>
                <span
                    class="shrink-0 text-[12px]/4 whitespace-nowrap text-fg-muted"
                    >{{ timeAgo(incident.openedAt) }}</span
                >
            </li>
        </ul>
    </DashTray>
</template>
