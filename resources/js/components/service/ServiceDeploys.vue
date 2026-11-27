<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { ServiceSentry } from '@/components/integrations/incidents';
import type { AppVersion, ServiceApp } from '@/components/service/serviceTypes';
import { networkMessage, responseError } from '@/lib/errors';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import appsRoutes from '@/routes/workspace/apps';

const props = defineProps<{
    app: ServiceApp | null;
    workspaceSlug: string;
    machineId: number;
    canManage: boolean;
    canRollBack: boolean;
    messages?: Record<string, string>;
    sentry?: ServiceSentry | null;
}>();

const emit = defineEmits<{
    rollback: [version: AppVersion];
    settings: [];
    drop: [file: File | undefined];
}>();

const onPush = ref(props.app?.deployOnPush?.on ?? false);
watch(
    () => props.app?.deployOnPush?.on,
    (on) => {
        if (on !== undefined) onPush.value = on;
    },
);
const saving = ref(false);
const dragging = ref(false);

async function savePush(on: boolean): Promise<void> {
    if (!props.app) return;
    saving.value = true;
    const response = await fetch(
        onWorkspace(
            appsRoutes.settings({
                workspace: props.workspaceSlug,
                machine: props.machineId,
                service: props.app.service,
            }),
        ).url,
        {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN':
                    document.querySelector<HTMLMetaElement>(
                        'meta[name="csrf-token"]',
                    )?.content ?? '',
            },
            body: JSON.stringify({
                deploy_on_push: on,
                push_branch: props.app.deployOnPush?.branch ?? 'main',
            }),
        },
    ).catch(() => null);
    saving.value = false;
    if (!response?.ok) {
        onPush.value = !on;
        toast.error(
            response ? await responseError(response) : networkMessage(),
        );

        return;
    }
    router.reload({ only: ['app'] });
}

function source(version: AppVersion): string {
    const message = props.messages?.[version.commit];
    if (message) return message;
    if (!version.repo) return trans('Uploaded .zip');

    return version.push
        ? trans('Push to :repo', { repo: version.repo })
        : version.repo;
}

function meta(version: AppVersion): string {
    return [version.by, version.deployedAt ? timeAgo(version.deployedAt) : null]
        .filter(Boolean)
        .join(' · ');
}

function issues(count: number | null): string {
    if (count === null) return trans('Sentry');
    if (count === 0) return trans('No new issues');

    return count === 1
        ? trans('1 new issue')
        : trans(':count new issues', { count: String(count) });
}

function drop(event: DragEvent): void {
    dragging.value = false;
    if (props.app && props.canManage)
        emit('drop', event.dataTransfer?.files[0]);
}
</script>

<template>
    <DashTray
        icon="upload-fill"
        :title="$t('Deploys')"
        class="shrink-0 transition-shadow"
        :class="dragging && 'ring-2 ring-dash-blue/60'"
        @dragover.prevent="dragging = !!app && canManage"
        @dragleave="dragging = false"
        @drop.prevent="drop"
    >
        <template v-if="app?.deployOnPush" #actions>
            <div class="flex items-center gap-2">
                <span
                    class="text-[13px]/5 tracking-[-0.005em] whitespace-nowrap text-fg-muted max-sm:hidden"
                    >{{ $t('Deploy each push to') }}</span
                >
                <button
                    type="button"
                    class="flex h-5 cursor-pointer items-center rounded-[5px] border-[0.5px] border-edge-strong bg-surface-badge px-1.5 font-ui-mono text-[12px]/4 text-fg-secondary hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring) disabled:cursor-default"
                    :title="$t('Deploy settings')"
                    :disabled="!canManage"
                    @click="$emit('settings')"
                >
                    {{ app.deployOnPush.branch }}
                </button>
                <DashSwitch
                    v-model="onPush"
                    :label="$t('Deploy each push')"
                    :disabled="!canManage || saving"
                    @update:model-value="savePush"
                />
            </div>
        </template>
        <template v-else-if="app && canManage" #actions>
            <DashButton
                size="sm"
                :label="$t('Settings')"
                @click="$emit('settings')"
            />
        </template>

        <ul class="flex flex-col px-[18px] pt-3 pb-3.5">
            <template v-if="app">
                <li
                    v-for="version in app.versions"
                    :key="version.id"
                    class="group flex h-11 items-center gap-3 rounded-[8px] pr-2 pl-2.5 focus-within:bg-surface-raised hover:bg-surface-raised"
                >
                    <span
                        class="shrink-0 font-ui-mono text-[12px]/4 text-fg-muted"
                        >{{ version.commit.slice(0, 7) }}</span
                    >
                    <span
                        class="min-w-0 flex-1 truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                        >{{ source(version) }}</span
                    >
                    <span
                        class="shrink-0 text-[12px]/4 tracking-[-0.005em] whitespace-nowrap text-fg-muted max-sm:hidden"
                        >{{ meta(version) }}</span
                    >
                    <a
                        v-if="sentry?.releases[version.commit]"
                        :href="sentry.releases[version.commit].url"
                        target="_blank"
                        rel="noopener"
                        class="flex shrink-0 items-center gap-1 rounded-[5px] px-1 text-[12px]/4 tracking-[-0.005em] whitespace-nowrap hover:bg-surface-hover hover:text-fg-primary focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                        :class="
                            (sentry.releases[version.commit].newIssues ?? 0) > 0
                                ? 'text-status-pending'
                                : 'text-fg-muted'
                        "
                        :title="$t('Its release in Sentry')"
                        >{{ issues(sentry.releases[version.commit].newIssues)
                        }}<FilledIcon name="open-fill" :size="11"
                    /></a>
                    <span
                        v-if="version.live"
                        class="flex h-[22px] shrink-0 items-center gap-[5px] rounded-[11px] bg-status-live/14 pr-2 pl-[7px] text-[12px]/4 font-medium tracking-[-0.005em] text-status-live"
                    >
                        <span
                            class="size-1.5 rounded-full bg-status-live"
                            aria-hidden="true"
                        />
                        {{ $t('Live') }}
                    </span>
                    <span
                        v-else
                        class="flex w-[52px] shrink-0 justify-end group-focus-within:w-[78px] group-hover:w-[78px] max-md:w-[78px]"
                    >
                        <DashButton
                            v-if="canManage"
                            size="sm"
                            class="opacity-0 group-focus-within:opacity-100 group-hover:opacity-100 max-md:opacity-100"
                            :label="$t('Roll back')"
                            :disabled="!canRollBack"
                            @click="$emit('rollback', version)"
                        />
                    </span>
                </li>
            </template>
            <li
                v-if="!app || !app.versions.length"
                class="flex h-11 items-center pr-2 pl-2.5 text-[13px]/5 tracking-[-0.005em] text-fg-muted"
            >
                {{
                    app
                        ? $t('No deploys kept on the machine yet.')
                        : $t(
                              'ServerOS didn’t deploy this service, so it has no deploys to show.',
                          )
                }}
            </li>
        </ul>
    </DashTray>
</template>
