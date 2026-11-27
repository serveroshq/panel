<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed } from 'vue';
import DashTray from '@/components/dash/DashTray.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import type { DashStatus } from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { timeAgo } from '@/lib/timeAgo';
import { workspacePath } from '@/lib/workspaceRoute';

export type GitLabProject = {
    path: string;
    url: string;
    apps: {
        machine: string;
        app: string;
        href: string;
        push: boolean;
        branch: string;
    }[];
    hook: {
        state: 'active' | 'none' | 'missing' | 'disabled' | 'unknown';
        lastPushAt: string | null;
    };
};

const { projects } = defineProps<{
    projects: GitLabProject[] | undefined;
}>();

const meta = computed(() => {
    if (!projects) return null;
    const apps = projects.reduce(
        (sum, project) => sum + project.apps.length,
        0,
    );

    if (apps === 0) return null;

    return apps === 1
        ? trans('1 app deployed from here')
        : trans(':count apps deployed from here', { count: String(apps) });
});

const hookBadge = computed<
    Record<
        Exclude<GitLabProject['hook']['state'], 'none'>,
        { status: DashStatus; label: string }
    >
>(() => ({
    active: { status: 'live', label: trans('Webhook on') },
    missing: { status: 'down', label: trans('Webhook missing') },
    disabled: { status: 'down', label: trans('Turned off at GitLab') },
    unknown: { status: 'pending', label: trans('Couldn’t check') },
}));

function owner(path: string): string {
    return path.includes('/') ? `${path.slice(0, path.lastIndexOf('/'))}/` : '';
}

function name(path: string): string {
    return path.slice(path.lastIndexOf('/') + 1);
}

function hookNote(project: GitLabProject): string {
    switch (project.hook.state) {
        case 'active':
            return project.hook.lastPushAt
                ? trans('Last push :when', {
                      when: timeAgo(project.hook.lastPushAt),
                  })
                : trans('No push yet');
        case 'missing':
            return trans(
                'Someone deleted it at GitLab. Turn deploying each push off and on again to add it back.',
            );
        case 'disabled':
            return trans(
                'GitLab stopped sending pushes after failed deliveries. Turn it back on in the project’s Webhooks settings.',
            );
        case 'unknown':
            return trans('GitLab didn’t answer.');
        default:
            return project.apps.some((app) => app.push)
                ? ''
                : trans('Turn on deploying each push on an app to add one.');
    }
}
</script>

<template>
    <DashTray
        icon="branch-fill"
        :title="$t('Projects')"
        :meta="meta"
        panel-class="py-0.5"
    >
        <div
            v-if="projects === undefined"
            class="flex flex-col"
            role="status"
            :aria-label="$t('Loading projects')"
        >
            <div
                v-for="n in 2"
                :key="n"
                class="flex items-center gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
            >
                <span
                    class="h-3 w-36 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
                <span
                    class="h-3 w-24 animate-pulse rounded bg-surface-badge motion-reduce:animate-none"
                />
            </div>
        </div>
        <ul v-else-if="projects.length" class="flex flex-col">
            <li
                v-for="project in projects"
                :key="project.path"
                class="flex min-w-0 items-start gap-3 border-t-[0.5px] border-edge-tray-panel px-3.5 py-2.5 first:border-t-0"
            >
                <div class="flex min-w-0 flex-1 flex-col gap-1">
                    <a
                        :href="project.url"
                        target="_blank"
                        rel="noopener"
                        class="flex min-w-0 items-center gap-1 text-[13px]/5 hover:underline"
                    >
                        <span class="flex min-w-0">
                            <span
                                class="truncate tracking-[-0.005em] text-fg-muted"
                                >{{ owner(project.path) }}</span
                            >
                            <span
                                class="truncate font-medium tracking-[-0.01em] text-fg-primary"
                                >{{ name(project.path) }}</span
                            >
                        </span>
                        <FilledIcon
                            name="open-fill"
                            :size="12"
                            class="shrink-0 text-fg-muted"
                        />
                    </a>
                    <div class="flex flex-wrap gap-x-3 gap-y-1">
                        <Link
                            v-for="app in project.apps"
                            :key="`${app.machine}/${app.app}`"
                            :href="workspacePath(app.href)"
                            class="flex min-w-0 items-center gap-1.5 text-[12px]/4 text-fg-tertiary hover:text-fg-primary"
                        >
                            <FilledIcon
                                name="machines-fill"
                                :size="12"
                                class="text-fg-muted"
                            />
                            <span class="truncate"
                                >{{ app.machine }} · {{ app.app }}</span
                            >
                            <span
                                v-if="app.push"
                                class="font-ui-mono text-[11.5px]/4 text-fg-muted"
                                :title="$t('Deploys each push to this branch')"
                                >{{ app.branch }}</span
                            >
                        </Link>
                    </div>
                </div>
                <div
                    class="flex w-[220px] shrink-0 flex-col items-end gap-1 text-right max-sm:w-[132px]"
                >
                    <StatusBadge
                        v-if="project.hook.state !== 'none'"
                        :status="hookBadge[project.hook.state].status"
                        :label="hookBadge[project.hook.state].label"
                    />
                    <span v-else class="text-[12px]/4 text-fg-muted">{{
                        $t('No webhook')
                    }}</span>
                    <span
                        v-if="hookNote(project)"
                        class="text-[12px]/4 text-fg-muted"
                        >{{ hookNote(project) }}</span
                    >
                </div>
            </li>
        </ul>
        <p v-else class="px-3.5 py-3 text-[13px]/5 text-fg-muted">
            {{
                $t(
                    'No app is deployed from this account’s projects yet. Pick one of them when you deploy code.',
                )
            }}
        </p>
    </DashTray>
</template>
