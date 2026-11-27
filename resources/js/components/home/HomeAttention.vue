<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashTray from '@/components/dash/DashTray.vue';
import { issueHref } from '@/components/home/home';
import type { HomeIssue } from '@/components/home/home';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import { useAssistant } from '@/composables/useAssistant';
import { onWorkspace } from '@/lib/workspaceRoute';
import issueRoutes from '@/routes/workspace/issues';

const { issues, canDismiss } = defineProps<{
    issues: HomeIssue[];
    canDismiss: boolean;
}>();

const page = usePage<{ workspace: { slug: string; url: string } }>();
const assistant = useAssistant();

const SHOWN = 4;
const expanded = ref(false);
const opened = ref<string | null>(null);
const dismissing = ref<string[]>([]);

const shown = computed(() =>
    expanded.value ? issues : issues.slice(0, SHOWN),
);
const rest = computed(() => (expanded.value ? [] : issues.slice(SHOWN)));
const dismissible = computed(() => issues.filter((issue) => issue.dismissible));

const restLine = computed(() => {
    const titles = rest.value.slice(0, 2).map((issue) => issue.title);
    const others = rest.value.length - titles.length;
    const list =
        others > 0
            ? [
                  ...titles,
                  trans(':count others', { count: String(others) }),
              ].join(' · ')
            : titles.join(' · ');

    return trans(':count more: :list', {
        count: String(rest.value.length),
        list,
    });
});

const tone: Record<HomeIssue['severity'], string> = {
    critical: 'bg-status-down',
    warning: 'bg-[#d4900f] dark:bg-[#f5a833]',
    notice: 'bg-fg-faint',
};

function action(issue: HomeIssue): string {
    return issue.action.startsWith('Open ') ? trans('Open') : issue.action;
}

function dismiss(ids: string[]): void {
    dismissing.value = ids;
    router.post(
        onWorkspace(issueRoutes.dismiss(page.props.workspace.slug)).url,
        { ids },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['issues', 'overview'],
            onFinish: () => (dismissing.value = []),
        },
    );
}
</script>

<template>
    <DashTray icon="info-fill" :title="$t('Needs a look')">
        <template v-if="issues.length" #meta>{{ issues.length }}</template>
        <template #actions>
            <button
                v-if="expanded && canDismiss && dismissible.length > 1"
                type="button"
                class="cursor-pointer rounded-md text-[13px]/5 tracking-[-0.005em] text-fg-muted transition-colors hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:opacity-50"
                :disabled="dismissing.length > 0"
                @click="dismiss(dismissible.map((issue) => issue.id))"
            >
                {{ $t('Mark all as read') }}
            </button>
            <button
                type="button"
                class="flex cursor-pointer items-center gap-[9px] rounded-md text-[13px]/5 tracking-[-0.005em] text-fg-secondary transition-colors hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:cursor-default disabled:text-fg-muted"
                :aria-expanded="expanded"
                :disabled="issues.length === 0"
                @click="expanded = !expanded"
            >
                {{ expanded ? $t('Show fewer') : $t('View all') }}
                <FilledIcon
                    :name="expanded ? 'chevron-up' : 'chevron-right'"
                    :size="14"
                    class="text-fg-muted"
                />
            </button>
        </template>

        <ul v-if="issues.length">
            <li
                v-for="issue in shown"
                :key="issue.id"
                class="group flex items-center gap-3 border-b-[0.5px] border-edge-tabs py-2.5 pr-3 pl-4"
            >
                <span
                    class="size-2 shrink-0 rounded-full"
                    :class="tone[issue.severity]"
                    role="img"
                    :aria-label="issue.severity"
                />
                <button
                    type="button"
                    class="flex min-w-0 grow cursor-pointer flex-col items-start text-left focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                    :aria-expanded="opened === issue.id"
                    @click="opened = opened === issue.id ? null : issue.id"
                >
                    <span
                        class="max-w-full truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                        >{{ issue.title }}</span
                    >
                    <span
                        class="max-w-full text-[12px]/4 tracking-[-0.005em] text-pretty text-fg-tertiary"
                        :class="{ truncate: opened !== issue.id }"
                        >{{
                            opened === issue.id ? issue.detail : issue.brief
                        }}</span
                    >
                </button>
                <div class="flex shrink-0 items-center gap-1.5">
                    <DashButton
                        v-if="issue.ask"
                        size="sm"
                        :icon="Sparkles"
                        :disabled="assistant.streaming.value"
                        :title="$t('Fix with Ask AI')"
                        :aria-label="
                            $t('Fix with Ask AI: :title', {
                                title: issue.title,
                            })
                        "
                        class="focus-visible:opacity-100 pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100"
                        @click="assistant.askAbout(issue.ask)"
                    />
                    <DashButton
                        v-if="canDismiss && issue.dismissible"
                        size="sm"
                        icon="check"
                        :disabled="dismissing.includes(issue.id)"
                        :title="$t('Mark as read')"
                        :aria-label="
                            $t('Mark as read: :title', { title: issue.title })
                        "
                        class="focus-visible:opacity-100 pointer-fine:opacity-0 pointer-fine:group-hover:opacity-100"
                        @click="dismiss([issue.id])"
                    />
                    <a
                        v-if="issue.linked"
                        :href="issue.linked.url"
                        target="_blank"
                        rel="noopener"
                        class="flex h-6 items-center gap-1 rounded-[6px] px-1.5 font-ui-mono text-[12px]/4 text-fg-secondary hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        :title="$t('Its issue in Linear')"
                        >{{ issue.linked.key
                        }}<FilledIcon
                            name="open-fill"
                            :size="11"
                            class="text-fg-muted"
                    /></a>
                    <DashButton
                        size="sm"
                        :href="issueHref(issue.href, page.props.workspace.url)"
                        :label="action(issue)"
                    />
                </div>
            </li>
            <li v-if="rest.length">
                <button
                    type="button"
                    class="w-full cursor-pointer truncate pt-2.5 pr-4 pb-[11px] pl-9 text-left text-[12px]/4 tracking-[-0.005em] text-fg-muted transition-colors hover:text-fg-secondary focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
                    @click="expanded = true"
                >
                    {{ restLine }}
                </button>
            </li>
            <li
                v-else-if="!expanded"
                class="truncate pt-2.5 pr-4 pb-[11px] pl-9 text-[12px]/4 tracking-[-0.005em] text-fg-muted"
            >
                {{ $t('Nothing else needs a look.') }}
            </li>
        </ul>
        <p
            v-else
            class="flex grow items-center justify-center px-4 py-10 text-[13px]/5 text-fg-muted"
        >
            {{ $t('Nothing needs a look right now.') }}
        </p>
    </DashTray>
</template>
