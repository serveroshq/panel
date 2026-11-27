<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import DashTray from '@/components/dash/DashTray.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { LinkedApp } from '@/components/integrations/incidents';
import { workspacePath } from '@/lib/workspaceRoute';

defineProps<{ apps: LinkedApp[] }>();
</script>

<template>
    <DashTray
        icon="upload-fill"
        :title="$t('Apps')"
        :meta="apps.length ? String(apps.length) : undefined"
        panel-class="py-0.5"
    >
        <ul v-if="apps.length" class="flex flex-col">
            <li
                v-for="app in apps"
                :key="app.id"
                class="flex min-w-0 items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
            >
                <span class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                        >{{ app.service }}</span
                    >
                    <span class="truncate text-[12px]/4 text-fg-muted"
                        >{{ app.machine
                        }}<template v-if="app.environment">
                            · {{ app.environment }}</template
                        ></span
                    >
                </span>
                <span
                    class="max-w-[40%] shrink-0 truncate text-[13px]/5 text-fg-secondary"
                    >{{ app.project }}</span
                >
                <Link
                    :href="workspacePath(app.href)"
                    class="flex shrink-0 items-center rounded-[6px] p-1 text-fg-muted hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                    :aria-label="$t('Open :name', { name: app.service })"
                >
                    <FilledIcon name="chevron-right" :size="14" />
                </Link>
            </li>
        </ul>
        <p v-else class="px-3.5 py-3 text-[13px]/5 text-fg-muted">
            {{
                $t(
                    'No app sends releases here yet. Open an app’s Deploy settings and pick a Sentry project.',
                )
            }}
        </p>
    </DashTray>
</template>
