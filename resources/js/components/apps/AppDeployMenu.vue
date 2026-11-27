<script setup lang="ts">
import { Plus } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, useTemplateRef, watch } from 'vue';
import type { AppDeploy, RedeployBody } from '@/components/apps/appTypes';
import { redeployApp, shortSha } from '@/components/apps/appTypes';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useZipUpload } from '@/composables/useZipUpload';
import { describe, errorMessage } from '@/lib/errors';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import appsRoutes from '@/routes/workspace/apps';

const props = defineProps<{
    deploy: AppDeploy;
    workspaceSlug: string;
    machineId: number;
    online: boolean;
    hasPlan: boolean;
    /** The deploy settings dialog (snapshots, database) is there to open. */
    hasSettings: boolean;
}>();

const emit = defineEmits<{
    deployed: [jobId: string, deploy: AppDeploy | null];
    settings: [];
}>();

const args = computed(() => ({
    workspace: props.workspaceSlug,
    machine: props.machineId,
    service: props.deploy.service,
}));

const zip = useZipUpload();
const zipInput = useTemplateRef<HTMLInputElement>('zipInput');
const commitOpen = ref(false);
const commitRef = ref('');
const commitError = ref<string | null>(null);
const sending = ref<string | null>(null);
const busy = computed(
    () =>
        sending.value !== null ||
        zip.uploading.value ||
        props.deploy.deploying !== null,
);
const blocked = computed(() => !props.online || !props.hasPlan);
const blockedReason = computed(() => {
    if (!props.online) return trans('The machine is offline.');
    if (!props.hasPlan) return trans('Choose a plan to deploy.');
    if (props.deploy.deploying) {
        return trans(':app is deploying now. Wait for it to finish.', {
            app: props.deploy.service,
        });
    }

    return undefined;
});

const live = computed(() => shortSha(props.deploy.liveCommit));
const builds = computed(() =>
    ['railpack', 'dockerfile', 'template'].includes(props.deploy.build.method),
);

async function send(key: string, body: RedeployBody): Promise<boolean> {
    if (sending.value) return false;
    sending.value = key;
    try {
        const answer = await redeployApp(
            onWorkspace(appsRoutes.redeploy(args.value)).url,
            body,
        );
        if (answer.ok) {
            emit('deployed', answer.jobId, answer.deploy);

            return true;
        }
        if (key === 'commit') {
            commitError.value =
                answer.errors.commit ?? describe(answer.status, answer.body);
        } else {
            toast.error(describe(answer.status, answer.body));
        }
    } catch (cause) {
        toast.error(errorMessage(cause));
    } finally {
        sending.value = null;
    }

    return false;
}

/* Deploy a branch, tag or commit. */
watch(commitOpen, (open) => {
    if (open) {
        commitRef.value = '';
        commitError.value = null;
    }
});

async function deployCommit(): Promise<void> {
    const ref = commitRef.value.trim();
    if (!ref) return;
    commitError.value = null;
    if (await send('commit', { commit: ref })) commitOpen.value = false;
}

/* Upload a new version as a .zip. */
watch(zip.error, (message) => {
    if (message) toast.error(message);
});

async function upload(file: File | undefined): Promise<void> {
    if (!file || busy.value) return;
    const source = await zip.upload(props.machineId, file);
    if (zipInput.value) zipInput.value.value = '';
    if (!source) return;
    sending.value = 'upload';
    try {
        const json = await zip.post(
            onWorkspace(appsRoutes.versions.store(args.value)).url,
            JSON.stringify({
                source: source.repo
                    .split('/')
                    .pop()
                    ?.replace(/\.git$/, ''),
            }),
            'application/json',
        );
        if (json.pushDeploysStopped === true) {
            toast.info(
                trans(
                    'Deploying each push is off now: uploaded code has no repository to push to.',
                ),
            );
        }
        if (typeof json.jobId === 'string') emit('deployed', json.jobId, null);
    } catch (cause) {
        toast.error(errorMessage(cause, trans('Couldn’t start the deploy.')));
    } finally {
        sending.value = null;
    }
}

defineExpose({ upload });

const label = computed(() => {
    if (zip.uploading.value) {
        return zip.progress.value >= 1
            ? trans('Unpacking…')
            : trans('Uploading :round%', {
                  round: String(Math.round(zip.progress.value * 100)),
              });
    }
    if (sending.value) return trans('Starting…');
    if (props.deploy.deploying) return trans('Deploying…');

    return trans('Deploy');
});

const hint = 'text-[12px]/4 text-fg-muted';
</script>

<template>
    <input
        ref="zipInput"
        type="file"
        accept=".zip,application/zip"
        class="hidden"
        @change="upload(($event.target as HTMLInputElement).files?.[0])"
    />
    <DropdownMenu>
        <DropdownMenuTrigger as-child>
            <DashButton
                variant="primary"
                :icon="Plus"
                :label="label"
                :loading="busy"
                :disabled="blocked"
                :title="blockedReason"
            />
        </DropdownMenuTrigger>
        <DropdownMenuContent class="ds-modal w-72 font-ui" align="end">
            <template v-if="deploy.source === 'git'">
                <DropdownMenuItem
                    class="items-start"
                    @select="send('latest', {})"
                >
                    <FilledIcon name="branch-fill" class="mt-0.5" />
                    <span class="flex min-w-0 flex-col">
                        <span>{{ $t('Deploy latest commit') }}</span>
                        <span :class="hint">{{
                            $t('The newest commit on :branch', {
                                branch: deploy.branch ?? 'main',
                            })
                        }}</span>
                    </span>
                </DropdownMenuItem>
                <DropdownMenuItem @select="commitOpen = true">
                    <FilledIcon name="code-fill" />
                    {{ $t('Deploy a commit…') }}
                </DropdownMenuItem>
                <DropdownMenuItem
                    v-if="live"
                    @select="send('again', { latest: false })"
                >
                    <FilledIcon name="update-fill" />
                    {{ $t('Redeploy :sha', { sha: live }) }}
                </DropdownMenuItem>
            </template>
            <DropdownMenuItem
                v-else-if="deploy.source === 'image'"
                class="items-start"
                @select="send('latest', {})"
            >
                <FilledIcon name="download" class="mt-0.5" />
                <span class="flex min-w-0 flex-col">
                    <span>{{ $t('Pull and deploy again') }}</span>
                    <span
                        v-if="deploy.build.image"
                        :class="[hint, 'truncate font-ui-mono']"
                        >{{ deploy.build.image }}</span
                    >
                </span>
            </DropdownMenuItem>
            <DropdownMenuItem
                v-else
                :disabled="!deploy.can.redeploy"
                class="items-start"
                @select="send('again', {})"
            >
                <FilledIcon name="update-fill" class="mt-0.5" />
                <span class="flex min-w-0 flex-col">
                    <span>{{ $t('Deploy again') }}</span>
                    <span v-if="!deploy.can.redeploy" :class="hint">{{
                        $t(
                            'The uploaded code was cleared. Upload it again below.',
                        )
                    }}</span>
                </span>
            </DropdownMenuItem>
            <DropdownMenuItem
                v-if="builds"
                :disabled="!deploy.can.rebuild"
                class="items-start"
                @select="send('rebuild', { latest: false, no_cache: true })"
            >
                <FilledIcon name="layers-fill" class="mt-0.5" />
                <span class="flex min-w-0 flex-col">
                    <span>{{ $t('Rebuild without cache') }}</span>
                    <span :class="hint">{{
                        deploy.can.rebuild
                            ? $t('Builds what it runs now from scratch')
                            : (deploy.update ??
                              $t('Only an app ServerOS builds can do this.'))
                    }}</span>
                </span>
            </DropdownMenuItem>
            <template v-if="deploy.source !== 'image'">
                <DropdownMenuSeparator />
                <DropdownMenuItem
                    class="items-start"
                    @select="zipInput?.click()"
                >
                    <FilledIcon name="upload-fill" class="mt-0.5" />
                    <span class="flex min-w-0 flex-col">
                        <span>{{ $t('Upload a new version (.zip)') }}</span>
                        <span v-if="deploy.source === 'git'" :class="hint">{{
                            deploy.pushToDeploy?.on
                                ? $t(
                                      'Turns off deploying each push, and the app stops following :branch.',
                                      { branch: deploy.branch ?? 'main' },
                                  )
                                : $t(
                                      'The app stops following :branch: uploaded code has no repository.',
                                      { branch: deploy.branch ?? 'main' },
                                  )
                        }}</span>
                    </span>
                </DropdownMenuItem>
            </template>
            <template v-if="hasSettings">
                <DropdownMenuSeparator />
                <DropdownMenuItem @select="emit('settings')">
                    <FilledIcon name="sliders" />
                    {{ $t('Snapshots and database') }}
                </DropdownMenuItem>
            </template>
        </DropdownMenuContent>
    </DropdownMenu>

    <Dialog v-model:open="commitOpen">
        <DialogContent
            class="workspace-panel ds-modal font-ui text-foreground sm:max-w-md"
        >
            <DialogHeader>
                <DialogTitle>{{ $t('Deploy a commit') }}</DialogTitle>
                <DialogDescription>
                    {{
                        $t(
                            'A branch or tag deploys its newest commit. The app keeps following :branch for later deploys.',
                            { branch: deploy.branch ?? 'main' },
                        )
                    }}
                </DialogDescription>
            </DialogHeader>
            <form class="flex flex-col gap-4" @submit.prevent="deployCommit">
                <div class="grid gap-2">
                    <label for="deploy-ref" class="text-[13px]/5">{{
                        $t('Branch, tag or commit')
                    }}</label>
                    <div class="dash-field">
                        <input
                            id="deploy-ref"
                            v-model="commitRef"
                            class="font-ui-mono"
                            :placeholder="$t('v1.4, a1b2c3d or a branch')"
                            autocomplete="off"
                            spellcheck="false"
                            maxlength="255"
                            autofocus
                        />
                    </div>
                    <p
                        v-if="commitError"
                        class="text-[12px]/4 text-status-down"
                        role="alert"
                    >
                        {{ commitError }}
                    </p>
                </div>
                <div class="flex justify-end gap-2">
                    <DashButton
                        size="sm"
                        :label="$t('Cancel')"
                        @click="commitOpen = false"
                    />
                    <DashButton
                        size="sm"
                        type="submit"
                        variant="primary"
                        :label="$t('Deploy')"
                        :loading="sending === 'commit'"
                        :disabled="!commitRef.trim()"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
