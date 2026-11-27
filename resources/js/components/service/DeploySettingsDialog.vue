<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import InputError from '@/components/InputError.vue';
import { getJson } from '@/components/integrations/incidents';
import type {
    SentryProject,
    ServiceSentry,
} from '@/components/integrations/incidents';
import type { ServiceApp } from '@/components/service/serviceTypes';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { SelectMenu } from '@/components/ui/select';
import { networkMessage, responseError } from '@/lib/errors';
import { onWorkspace } from '@/lib/workspaceRoute';
import appsRoutes from '@/routes/workspace/apps';
import integrationRoutes from '@/routes/workspace/integrations';

const open = defineModel<boolean>('open', { required: true });

const props = defineProps<{
    app: ServiceApp;
    workspaceSlug: string;
    machineId: number;
    sentry?: ServiceSentry | null;
}>();

const branch = ref('main');
const onPush = ref(false);
const onlyNewest = ref(true);
const snapshot = ref(true);
const database = ref('auto');
const error = ref<string | null>(null);
const saving = ref(false);
const sentryAccount = ref<number | null>(null);
const sentryProject = ref('');
const sentryProjects = ref<SentryProject[]>([]);
const sentryLoading = ref(false);
const sentryError = ref<string | null>(null);

async function loadSentryProjects(account: number | null): Promise<void> {
    sentryProjects.value = [];
    sentryError.value = null;
    if (account === null) return;
    sentryLoading.value = true;
    try {
        sentryProjects.value = (
            await getJson<{ projects: SentryProject[] }>(
                onWorkspace(
                    integrationRoutes.options({
                        workspace: props.workspaceSlug,
                        integration: account,
                    }),
                ).url,
            )
        ).projects;
    } catch (e) {
        sentryError.value = e instanceof Error ? e.message : String(e);
    } finally {
        sentryLoading.value = false;
    }
}

watch(
    open,
    (value) => {
        if (!value) return;
        branch.value = props.app.deployOnPush?.branch ?? 'main';
        onPush.value = props.app.deployOnPush?.on ?? false;
        onlyNewest.value = props.app.deployOnPush?.onlyNewest ?? true;
        snapshot.value = props.app.snapshotBefore;
        database.value = props.app.database.setting;
        error.value = null;
        sentryAccount.value =
            props.sentry?.link?.integrationId ??
            (props.sentry?.accounts.length === 1
                ? props.sentry.accounts[0].id
                : null);
        sentryProject.value = props.sentry?.link?.project ?? '';
        if (props.sentry?.accounts.length)
            void loadSentryProjects(sentryAccount.value);
    },
    { immediate: true },
);

const sentryAccountOptions = computed(() =>
    (props.sentry?.accounts ?? []).map((a) => ({
        value: a.id,
        label: a.label,
    })),
);
const sentryProjectOptions = computed(() => {
    const listed = sentryProjects.value.map((p) => ({
        value: p.slug,
        label: p.name,
    }));
    const link = props.sentry?.link;
    if (
        link &&
        link.integrationId === sentryAccount.value &&
        !listed.some((p) => p.value === link.project)
    ) {
        listed.unshift({ value: link.project, label: link.projectName });
    }

    return [{ value: '', label: trans('Don’t send releases') }, ...listed];
});
const sentryChanged = computed(() => {
    const link = props.sentry?.link;
    if (!props.sentry?.accounts.length) return false;
    if (!sentryProject.value) return !!link;

    return (
        !link ||
        link.integrationId !== sentryAccount.value ||
        link.project !== sentryProject.value
    );
});

function pickSentryAccount(value: string | number | null): void {
    sentryAccount.value = value === null || value === '' ? null : Number(value);
    sentryProject.value = '';
    void loadSentryProjects(sentryAccount.value);
}

async function saveSentry(): Promise<boolean> {
    const response = await fetch(
        onWorkspace(
            appsRoutes.sentry({
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
            body: JSON.stringify(
                sentryProject.value && sentryAccount.value
                    ? {
                          integration: sentryAccount.value,
                          project: sentryProject.value,
                      }
                    : { integration: null },
            ),
        },
    ).catch(() => null);
    if (!response?.ok) {
        error.value = response
            ? await responseError(response)
            : networkMessage();

        return false;
    }

    return true;
}

const databaseOptions = computed(() => {
    const db = props.app.database;

    return [
        {
            value: 'auto',
            label:
                db.setting === 'auto' && db.current
                    ? trans('From its settings: :display', {
                          display: db.current.display,
                      })
                    : trans('From its settings'),
        },
        ...db.options,
        { value: 'none', label: trans('None') },
    ];
});

async function save(): Promise<void> {
    saving.value = true;
    error.value = null;
    const push = props.app.deployOnPush;
    const changed = {
        snapshot_before: [snapshot.value, props.app.snapshotBefore],
        database: [database.value, props.app.database.setting],
        deploy_on_push: [onPush.value, push?.on],
        push_branch: [branch.value.trim() || 'main', push?.branch],
        push_only_newest: [onlyNewest.value, push?.onlyNewest],
    } as const;
    const body = Object.fromEntries(
        Object.entries(changed)
            .filter(
                ([key, [now, before]]) =>
                    now !== before &&
                    (push ||
                        (!key.startsWith('push') && key !== 'deploy_on_push')),
            )
            .map(([key, [now]]) => [key, now]),
    );
    if (Object.keys(body).length === 0) {
        if (sentryChanged.value && !(await saveSentry())) {
            saving.value = false;

            return;
        }
        open.value = false;
        saving.value = false;
        if (sentryChanged.value) router.reload({ only: ['app', 'sentry'] });

        return;
    }
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
            body: JSON.stringify(body),
        },
    ).catch(() => null);
    if (!response?.ok) {
        saving.value = false;
        error.value = response
            ? await responseError(response)
            : networkMessage();

        return;
    }
    if (sentryChanged.value && !(await saveSentry())) {
        saving.value = false;

        return;
    }
    saving.value = false;
    open.value = false;
    router.reload({ only: ['app', 'sentry'] });
}

const row = 'flex items-start justify-between gap-4 py-3';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('Deploy settings') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'How :app deploys. Changes apply from its next deploy.',
                            { app: app.service },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form
                class="flex flex-col divide-y-[0.5px] divide-edge-row"
                @submit.prevent="save"
            >
                <template v-if="app.deployOnPush">
                    <div :class="row">
                        <span class="flex flex-col gap-0.5">
                            <span class="text-[13px]/5 text-fg-primary">{{
                                $t('Deploy each push')
                            }}</span>
                            <span class="text-[12px]/4 text-fg-muted">{{
                                $t(
                                    'Each push deploys with the settings it has now. Needs the GitHub app on the repository.',
                                )
                            }}</span>
                        </span>
                        <DashSwitch
                            v-model="onPush"
                            :label="$t('Deploy each push')"
                        />
                    </div>
                    <label :class="row" for="push-branch">
                        <span class="flex flex-col gap-0.5">
                            <span class="text-[13px]/5 text-fg-primary">{{
                                $t('Branch')
                            }}</span>
                            <span class="text-[12px]/4 text-fg-muted">{{
                                $t('Pushes to this branch deploy.')
                            }}</span>
                        </span>
                        <Input
                            id="push-branch"
                            v-model="branch"
                            class="h-8 w-36 font-ui-mono text-xs"
                            spellcheck="false"
                            autocomplete="off"
                        />
                    </label>
                    <div v-if="onPush" :class="row">
                        <span class="flex flex-col gap-0.5">
                            <span class="text-[13px]/5 text-fg-primary">{{
                                $t('Only build the newest push')
                            }}</span>
                            <span class="text-[12px]/4 text-fg-muted">{{
                                onlyNewest
                                    ? $t(
                                          'A push that comes in while it’s deploying waits, and a newer push replaces it.',
                                      )
                                    : $t(
                                          'Every push deploys as it comes in, even while another is deploying.',
                                      )
                            }}</span>
                        </span>
                        <DashSwitch
                            v-model="onlyNewest"
                            :label="$t('Only build the newest push')"
                        />
                    </div>
                </template>
                <div :class="row">
                    <span class="flex flex-col gap-0.5">
                        <span class="text-[13px]/5 text-fg-primary">{{
                            $t('Snapshot data before each deploy')
                        }}</span>
                        <span class="text-[12px]/4 text-fg-muted">{{
                            $t(
                                'So a roll back can put its data back too, with Code and data.',
                            )
                        }}</span>
                    </span>
                    <DashSwitch
                        v-model="snapshot"
                        :label="$t('Snapshot data before each deploy')"
                    />
                </div>
                <div v-if="snapshot" :class="row">
                    <span class="flex flex-col gap-0.5">
                        <span class="text-[13px]/5 text-fg-primary">{{
                            $t('Its database')
                        }}</span>
                        <span class="text-[12px]/4 text-fg-muted">{{
                            app.database.current
                                ? $t(
                                      'Snapshotted with each deploy, and put back by Code and data.',
                                  )
                                : app.database.options.length
                                  ? $t(
                                        'None found in its settings. Pick one if it keeps its data there.',
                                    )
                                  : $t(
                                        'No database ServerOS manages on this machine.',
                                    )
                        }}</span>
                    </span>
                    <SelectMenu
                        v-model="database"
                        class="ds-raised-button h-8 max-w-44 text-[13px]"
                        :options="databaseOptions"
                    />
                </div>
                <template v-if="sentry?.accounts.length">
                    <div v-if="sentry.accounts.length > 1" :class="row">
                        <span class="flex flex-col gap-0.5">
                            <span class="text-[13px]/5 text-fg-primary">{{
                                $t('Sentry account')
                            }}</span>
                        </span>
                        <SelectMenu
                            :model-value="sentryAccount"
                            class="ds-raised-button h-8 w-40 shrink-0 text-[13px]"
                            :options="sentryAccountOptions"
                            :placeholder="$t('Pick one')"
                            @update:model-value="pickSentryAccount"
                        />
                    </div>
                    <div :class="row">
                        <span class="flex flex-col gap-0.5">
                            <span class="text-[13px]/5 text-fg-primary">{{
                                $t('Sentry project')
                            }}</span>
                            <span
                                class="text-[12px]/4"
                                :class="
                                    sentryError
                                        ? 'text-status-down'
                                        : 'text-fg-muted'
                                "
                                >{{
                                    sentryError ??
                                    $t(
                                        'Each deploy makes a release there with its commit, and sets SENTRY_RELEASE on the app.',
                                    )
                                }}</span
                            >
                        </span>
                        <SelectMenu
                            v-model="sentryProject"
                            class="ds-raised-button h-8 w-40 shrink-0 text-[13px]"
                            :options="sentryProjectOptions"
                            :placeholder="
                                sentryLoading ? $t('Loading') : undefined
                            "
                            :disabled="!sentryAccount || sentryLoading"
                        />
                    </div>
                </template>
                <div class="flex flex-col gap-3 pt-4">
                    <InputError :message="error ?? undefined" />
                    <div class="flex justify-end gap-2">
                        <DashButton
                            size="sm"
                            :label="$t('Cancel')"
                            @click="open = false"
                        />
                        <DashButton
                            size="sm"
                            variant="primary"
                            type="submit"
                            :label="$t('Save')"
                            :loading="saving"
                        />
                    </div>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
