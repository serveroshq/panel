<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { IntegrationEventRow } from '@/components/integrations/incidents';
import { timeAgo } from '@/lib/timeAgo';
import { onWorkspace } from '@/lib/workspaceRoute';
import integrationRoutes from '@/routes/workspace/integrations';

const props = defineProps<{
    events: IntegrationEventRow[];
    workspaceSlug: string;
    integrationId: number;
    empty: string;
}>();

const tone: Record<IntegrationEventRow['state'], DashStatus> = {
    queued: 'pending',
    sending: 'deploying',
    retrying: 'pending',
    sent: 'live',
    skipped: 'stopped',
    failed: 'down',
};

function stateLabel(event: IntegrationEventRow): string {
    switch (event.state) {
        case 'sent':
            return trans('Sent');
        case 'skipped':
            return trans('Nothing to do');
        case 'failed':
            return trans('Failed');
        case 'retrying':
            return trans('Trying again');
        case 'sending':
            return trans('Sending');
        default:
            return trans('Waiting');
    }
}

const retrying = ref<number | null>(null);

function retry(event: IntegrationEventRow): void {
    router.post(
        onWorkspace(
            integrationRoutes.events.retry({
                workspace: props.workspaceSlug,
                integration: props.integrationId,
                event: event.id,
            }),
        ).url,
        {},
        {
            preserveScroll: true,
            onStart: () => (retrying.value = event.id),
            onFinish: () => (retrying.value = null),
        },
    );
}
</script>

<template>
    <DashTray
        icon="activity-fill"
        :title="$t('Recent events')"
        :meta="events.length ? String(events.length) : undefined"
        panel-class="py-0.5"
    >
        <ul v-if="events.length" class="flex flex-col">
            <li
                v-for="event in events"
                :key="event.id"
                class="flex flex-col gap-1 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
            >
                <div class="flex min-w-0 items-center gap-2.5">
                    <span
                        class="shrink-0 text-[13px]/5 font-medium tracking-[-0.005em] text-fg-primary"
                        >{{ event.label }}</span
                    >
                    <span
                        class="min-w-0 flex-1 truncate text-[13px]/5 tracking-[-0.005em] text-fg-tertiary"
                        :title="event.subject"
                        >{{ event.subject }}</span
                    >
                    <a
                        v-if="event.link"
                        :href="event.link.url"
                        target="_blank"
                        rel="noopener"
                        class="flex shrink-0 items-center gap-1 rounded-[5px] px-1 text-[12px]/4 text-fg-secondary hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        :class="{ 'font-ui-mono': event.link.key }"
                        >{{ event.link.key ?? $t('Open')
                        }}<FilledIcon
                            name="open-fill"
                            :size="12"
                            class="text-fg-muted"
                    /></a>
                    <span
                        class="shrink-0 text-[12px]/4 whitespace-nowrap text-fg-muted max-sm:hidden"
                        >{{
                            event.createdAt ? timeAgo(event.createdAt) : ''
                        }}</span
                    >
                    <StatusBadge
                        :status="tone[event.state]"
                        :label="stateLabel(event)"
                    />
                </div>
                <div
                    v-if="event.error"
                    class="flex items-center gap-2.5 text-[12px]/4"
                >
                    <span
                        class="min-w-0 flex-1"
                        :class="
                            event.state === 'failed'
                                ? 'text-status-down'
                                : 'text-fg-muted'
                        "
                        role="status"
                    >
                        {{ event.error }}
                        <template
                            v-if="
                                event.state === 'retrying' &&
                                event.nextAttemptAt
                            "
                        >
                            {{
                                $t('Trying again :when.', {
                                    when: timeAgo(event.nextAttemptAt),
                                })
                            }}
                        </template>
                    </span>
                    <DashButton
                        v-if="event.state === 'failed'"
                        size="sm"
                        :label="$t('Try again')"
                        :loading="retrying === event.id"
                        @click="retry(event)"
                    />
                </div>
            </li>
        </ul>
        <p v-else class="px-3.5 py-3 text-[13px]/5 text-fg-muted">
            {{ empty }}
        </p>
    </DashTray>
</template>
