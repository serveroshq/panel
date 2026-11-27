<script setup lang="ts">
import { useMediaQuery } from '@vueuse/core';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import type { AppDeploy, Hook } from '@/components/apps/appTypes';
import { fieldErrors, sendJson } from '@/components/apps/appTypes';
import DashButton from '@/components/dash/DashButton.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import SettingsRow from '@/components/machines/settings/SettingsRow.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { describe, errorMessage } from '@/lib/errors';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import appsRoutes from '@/routes/workspace/apps';

const props = defineProps<{
    deploy: AppDeploy;
    workspaceSlug: string;
    machineId: number;
    canManage: boolean;
}>();

const emit = defineEmits<{ changed: [] }>();

// On a phone, a row's control goes under its label.
const narrow = useMediaQuery('(max-width: 639px)');

const args = computed(() => ({
    workspace: props.workspaceSlug,
    machine: props.machineId,
    service: props.deploy.service,
}));

const fromGit = computed(() => props.deploy.source === 'git');
const branch = ref(props.deploy.branch ?? 'main');
const onPush = ref(props.deploy.pushToDeploy?.on ?? false);
const onlyNewest = ref(props.deploy.pushToDeploy?.onlyNewest ?? true);
const saving = ref<string | null>(null);
const errors = ref<Record<string, string>>({});

watch(
    () => props.deploy.pushToDeploy,
    (push) => {
        if (saving.value) return;
        onPush.value = push?.on ?? false;
        onlyNewest.value = push?.onlyNewest ?? true;
    },
);
watch(
    () => props.deploy.branch,
    (next) => {
        if (saving.value !== 'push_branch') branch.value = next ?? 'main';
    },
);

const host = computed(() => {
    const repo = props.deploy.repo ?? '';
    const match = /^(?:https?:\/\/|git@)([^/:]+)/.exec(repo);

    return match?.[1] ?? null;
});

async function saveSetting(
    key: 'push_branch' | 'deploy_on_push' | 'push_only_newest',
    value: string | boolean,
): Promise<boolean> {
    saving.value = key;
    delete errors.value[key];
    try {
        const answer = await sendJson(
            onWorkspace(appsRoutes.settings(args.value)).url,
            'PUT',
            { [key]: value },
        );
        if (answer.status === 422) {
            errors.value = { ...errors.value, ...fieldErrors(answer.body) };

            return false;
        }
        if (!answer.ok) {
            toast.error(describe(answer.status, answer.body));

            return false;
        }
        emit('changed');

        return true;
    } catch (cause) {
        toast.error(errorMessage(cause));

        return false;
    } finally {
        saving.value = null;
    }
}

async function saveBranch(): Promise<void> {
    const next = branch.value.trim();
    if (!next || next === props.deploy.branch) {
        branch.value = props.deploy.branch ?? 'main';

        return;
    }
    if (await saveSetting('push_branch', next)) {
        toast.success(
            trans(':app follows :branch now.', {
                app: props.deploy.service,
                branch: next,
            }),
        );
    }
}

async function togglePush(on: boolean): Promise<void> {
    if (!(await saveSetting('deploy_on_push', on))) onPush.value = !on;
}

async function toggleNewest(on: boolean): Promise<void> {
    if (!(await saveSetting('push_only_newest', on))) onlyNewest.value = !on;
}

/* The deploy hook */
const hook = ref<Hook | null>(props.deploy.hook);
const madeUrl = ref<string | null>(null);
const hookBusy = ref<'make' | 'revoke' | null>(null);
const confirm = ref<'regenerate' | 'revoke' | null>(null);
const confirmOpen = computed({
    get: () => confirm.value !== null,
    set: (open: boolean) => {
        if (!open) confirm.value = null;
    },
});

watch(
    () => props.deploy.hook,
    (next) => (hook.value = next),
);

async function makeHook(): Promise<void> {
    confirm.value = null;
    hookBusy.value = 'make';
    try {
        const answer = await sendJson(
            onWorkspace(appsRoutes.hook.store(args.value)).url,
            'POST',
        );
        if (!answer.ok) {
            toast.error(describe(answer.status, answer.body));

            return;
        }
        madeUrl.value = String(answer.body.url ?? '');
        hook.value = answer.body.hook as Hook;
        emit('changed');
    } catch (cause) {
        toast.error(errorMessage(cause));
    } finally {
        hookBusy.value = null;
    }
}

async function revokeHook(): Promise<void> {
    confirm.value = null;
    hookBusy.value = 'revoke';
    try {
        const answer = await sendJson(
            onWorkspace(appsRoutes.hook.destroy(args.value)).url,
            'DELETE',
        );
        if (!answer.ok) {
            toast.error(describe(answer.status, answer.body));

            return;
        }
        madeUrl.value = null;
        hook.value = answer.body.hook as Hook;
        toast.success(
            trans('The deploy hook is off. Its address stops working.'),
        );
        emit('changed');
    } catch (cause) {
        toast.error(errorMessage(cause));
    } finally {
        hookBusy.value = null;
    }
}

const copied = ref(false);
function copy(value: string): void {
    void navigator.clipboard.writeText(value).then(() => {
        copied.value = true;
        window.setTimeout(() => (copied.value = false), 1500);
    });
}

const hookSenders: Record<string, string> = {
    'hook:github': 'GitHub',
    'hook:gitlab': 'GitLab',
    'hook:gitea': 'Gitea',
    'hook:bitbucket': 'Bitbucket',
};

const hookUsed = computed(() => {
    const current = hook.value;
    if (!current?.exists) return null;
    if (!current.lastUsedAt) return trans('Not used yet.');
    const from = current.lastTrigger ? hookSenders[current.lastTrigger] : null;

    return from
        ? trans('Last used :when, by a push on :from.', {
              when: timeAgo(current.lastUsedAt),
              from,
          })
        : trans('Last used :when.', { when: timeAgo(current.lastUsedAt) });
});

const hookMade = computed(() => {
    const current = hook.value;
    if (!current?.exists) return null;

    return [
        current.hint
            ? trans('Address ending :hint', { hint: current.hint })
            : null,
        current.by ? trans('made by :name', { name: current.by }) : null,
        current.createdAt ? timeAgo(current.createdAt) : null,
    ]
        .filter(Boolean)
        .join(' · ');
});

type Provider = 'github' | 'gitlab' | 'gitea' | 'bitbucket' | 'ci';
const provider = ref<Provider>('github');

watch(
    host,
    (name) => {
        if (!name) {
            provider.value = 'ci';
        } else if (name.includes('gitlab')) {
            provider.value = 'gitlab';
        } else if (name.includes('bitbucket')) {
            provider.value = 'bitbucket';
        } else if (name.includes('gitea') || name.includes('codeberg')) {
            provider.value = 'gitea';
        } else if (name === 'github.com') {
            provider.value = 'github';
        } else {
            provider.value = 'ci';
        }
    },
    { immediate: true },
);

const providers = computed(() =>
    [
        { value: 'github' as const, label: 'GitHub' },
        { value: 'gitlab' as const, label: 'GitLab' },
        { value: 'gitea' as const, label: 'Gitea, Forgejo' },
        { value: 'bitbucket' as const, label: 'Bitbucket' },
        { value: 'ci' as const, label: trans('Any CI') },
    ].filter((option) => fromGit.value || option.value === 'ci'),
);

const steps = computed(() => {
    const branchName = props.deploy.branch ?? 'main';
    switch (provider.value) {
        case 'github':
            return trans(
                'In the repository: Settings › Webhooks › Add webhook. Paste the address, choose application/json, and keep “Just the push event”. Pushes to :branch deploy.',
                { branch: branchName },
            );
        case 'gitlab':
            return trans(
                'In the project: Settings › Webhooks. Paste the address and tick Push events. Pushes to :branch deploy.',
                { branch: branchName },
            );
        case 'gitea':
            return trans(
                'In the repository: Settings › Webhooks › Add webhook › Gitea or Forgejo. Paste the address and choose push events. Pushes to :branch deploy.',
                { branch: branchName },
            );
        case 'bitbucket':
            return trans(
                'In the repository: Repository settings › Webhooks › Add webhook. Paste the address and tick Repository push. Pushes to :branch deploy.',
                { branch: branchName },
            );
        default:
            return fromGit.value
                ? trans(
                      'Send a POST to deploy the newest commit of :branch. Send {"commit":"<sha or tag>"} as JSON to deploy that one.',
                      { branch: branchName },
                  )
                : trans('Send a POST to pull the image and deploy it again.');
    }
});

const curl = computed(
    () => `curl -X POST ${madeUrl.value ?? trans('<the hook address>')}`,
);
</script>

<template>
    <DashTray
        v-if="fromGit"
        icon="branch-fill"
        :title="$t('Branch and pushes')"
        panel-class="px-[18px] pt-1 pb-1"
    >
        <SettingsRow
            :label="$t('Branch')"
            :hint="
                $t(
                    'Deploy latest commit, pushes and the deploy hook all deploy this branch.',
                )
            "
            label-for="app-branch"
            :error="errors.push_branch"
            :stack="narrow"
        >
            <div class="dash-field w-[220px] max-w-full">
                <input
                    id="app-branch"
                    v-model="branch"
                    class="font-ui-mono text-[12.5px]"
                    spellcheck="false"
                    autocomplete="off"
                    maxlength="200"
                    :disabled="!canManage || saving === 'push_branch'"
                    @blur="saveBranch"
                    @keydown.enter.prevent="
                        ($event.target as HTMLInputElement).blur()
                    "
                />
            </div>
        </SettingsRow>
        <SettingsRow
            :label="$t('Deploy each push')"
            :hint="
                deploy.pushToDeploy
                    ? $t(
                          'Each push to :branch deploys it, through the ServerOS app on GitHub.',
                          { branch: deploy.branch ?? 'main' },
                      )
                    : $t(
                          'This works for GitHub repositories. For :host, use the deploy hook below.',
                          { host: host ?? $t('this repository') },
                      )
            "
            :error="errors.deploy_on_push"
        >
            <DashSwitch
                v-if="deploy.pushToDeploy"
                v-model="onPush"
                :label="$t('Deploy each push')"
                :disabled="!canManage || saving !== null"
                @update:model-value="togglePush"
            />
        </SettingsRow>
        <SettingsRow
            v-if="deploy.pushToDeploy && onPush"
            :label="$t('Only build the newest push')"
            :hint="
                onlyNewest
                    ? $t(
                          'A push that comes in while it’s deploying waits, and a newer push replaces it.',
                      )
                    : $t(
                          'Every push deploys as it comes in, even while another is deploying.',
                      )
            "
            :error="errors.push_only_newest"
        >
            <DashSwitch
                v-model="onlyNewest"
                :label="$t('Only build the newest push')"
                :disabled="!canManage || saving !== null"
                @update:model-value="toggleNewest"
            />
        </SettingsRow>
    </DashTray>

    <DashTray v-if="hook" icon="plug" :title="$t('Deploy hook')">
        <template v-if="canManage" #actions>
            <template v-if="hook.exists">
                <DashButton
                    size="sm"
                    :label="$t('Make a new address')"
                    :loading="hookBusy === 'make'"
                    :disabled="hookBusy !== null"
                    @click="confirm = 'regenerate'"
                />
                <DashButton
                    size="sm"
                    :label="$t('Turn off')"
                    :loading="hookBusy === 'revoke'"
                    :disabled="hookBusy !== null"
                    @click="confirm = 'revoke'"
                />
            </template>
            <DashButton
                v-else
                size="sm"
                :label="$t('Make a deploy hook')"
                :loading="hookBusy === 'make'"
                @click="makeHook"
            />
        </template>

        <div class="flex flex-col gap-3 px-[18px] pt-3 pb-3.5">
            <p class="text-[13px]/5 text-fg-secondary">
                {{
                    fromGit
                        ? $t(
                              'An address that deploys :app when something sends a POST to it: a push on GitLab, Gitea, Bitbucket or GitHub, or a step in your CI.',
                              { app: deploy.service },
                          )
                        : $t(
                              'An address that pulls the image and deploys :app again when something sends a POST to it, like your CI after it pushes a new image.',
                              { app: deploy.service },
                          )
                }}
            </p>

            <div
                v-if="madeUrl"
                class="flex flex-col gap-2 rounded-[10px] border-[0.5px] border-edge-tray-panel bg-surface-well px-3 py-2.5"
            >
                <div class="flex min-w-0 items-center gap-2">
                    <code
                        class="min-w-0 flex-1 truncate font-ui-mono text-[12px]/5 text-fg-primary select-all"
                        >{{ madeUrl }}</code
                    >
                    <DashButton
                        size="sm"
                        variant="primary"
                        :label="copied ? $t('Copied') : $t('Copy')"
                        @click="copy(madeUrl)"
                    />
                </div>
                <p class="text-[12px]/4 text-fg-muted">
                    {{
                        $t(
                            'Copy it now. ServerOS keeps only a fingerprint of it, so it can’t show it again.',
                        )
                    }}
                </p>
            </div>
            <div
                v-else-if="hook.exists"
                class="flex flex-col text-[12.5px]/[18px]"
            >
                <span class="text-fg-primary">{{ hookMade }}</span>
                <span class="text-fg-muted">{{ hookUsed }}</span>
            </div>
            <p v-else class="text-[12.5px]/[18px] text-fg-muted">
                {{ $t('No deploy hook yet.') }}
            </p>

            <div
                class="flex flex-col gap-2 border-t-[0.5px] border-edge-tabs pt-3"
            >
                <FilterPills
                    v-model="provider"
                    :options="providers"
                    :label="$t('Set it up on')"
                    class="self-start max-sm:max-w-full max-sm:overflow-x-auto"
                />
                <p class="text-[12.5px]/[18px] text-fg-secondary">
                    {{ steps }}
                </p>
                <code
                    v-if="provider === 'ci'"
                    class="truncate rounded-[6px] bg-surface-well px-2 py-1 font-ui-mono text-[12px]/5 text-fg-primary"
                    >{{ curl }}</code
                >
            </div>
        </div>
    </DashTray>

    <Dialog v-model:open="confirmOpen">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{
                    confirm === 'revoke'
                        ? $t('Turn the deploy hook off?')
                        : $t('Make a new address?')
                }}</DialogTitle>
                <DialogDescription>
                    {{
                        confirm === 'revoke'
                            ? $t(
                                  'Its address stops working straight away, so anything that sends to it stops deploying :app.',
                                  { app: deploy.service },
                              )
                            : $t(
                                  'The address you have now stops working. Put the new one wherever the old one was.',
                              )
                    }}
                </DialogDescription>
            </DialogHeader>
            <div class="flex justify-end gap-2">
                <DashButton
                    size="sm"
                    :label="$t('Cancel')"
                    @click="confirm = null"
                />
                <DashButton
                    size="sm"
                    :variant="confirm === 'revoke' ? 'danger' : 'primary'"
                    :label="
                        confirm === 'revoke'
                            ? $t('Turn off')
                            : $t('Make a new address')
                    "
                    @click="confirm === 'revoke' ? revokeHook() : makeHook()"
                />
            </div>
        </DialogContent>
    </Dialog>
</template>
