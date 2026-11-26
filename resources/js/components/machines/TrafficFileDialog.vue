<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { onBeforeUnmount, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { appButtonClasses } from '@/lib/appButton';
import { describe, errorMessage, jobReason } from '@/lib/errors';
import { toast } from '@/lib/toast';

const props = defineProps<{
    machineId: number;
    /** The app's name, which the site is called by. */
    name: string;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ added: [] }>();

const siteName = ref(props.name);
const path = ref('');
const busy = ref(false);
const error = ref<string | null>(null);
let poll: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => clearTimeout(poll));

watch(open, (isOpen) => {
    if (isOpen) {
        siteName.value = props.name;
        error.value = null;
    } else {
        clearTimeout(poll);
    }
});

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function follow(jobId: string): Promise<void> {
    let tries = 0;

    return new Promise((resolve, reject) => {
        const check = async () => {
            try {
                const response = await fetch(
                    `/machines/${props.machineId}/traffic/files/${jobId}`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    },
                );
                const job = await response.json();
                if (job.state === 'succeeded') {
                    resolve();

                    return;
                }
                if (
                    [
                        'failed',
                        'refused',
                        'timed_out',
                        'cancelled',
                        'stale',
                    ].includes(job.state)
                ) {
                    reject(
                        new Error(
                            jobReason(
                                job.error,
                                trans('The machine didn’t do it. Try again.'),
                            ),
                        ),
                    );

                    return;
                }
                if (++tries >= 60) {
                    reject(
                        new Error(
                            trans(
                                'The machine is taking a while. Try again in a minute.',
                            ),
                        ),
                    );

                    return;
                }
                poll = setTimeout(check, 1000);
            } catch (e) {
                reject(e);
            }
        };
        poll = setTimeout(check, 600);
    });
}

async function save(): Promise<void> {
    busy.value = true;
    error.value = null;
    try {
        const response = await fetch(
            `/machines/${props.machineId}/traffic/files`,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    name: siteName.value.trim(),
                    path: path.value.trim(),
                }),
            },
        );
        const body = await response.json().catch(() => ({}));
        if (response.status !== 202) {
            throw new Error(describe(response.status, body));
        }
        await follow(String(body.jobId));
        toast.success(
            trans(
                'ServerOS counts :file now. Visits show up within a couple of minutes.',
                { file: path.value.trim() },
            ),
        );
        emit('added');
        open.value = false;
    } catch (e) {
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-lg"
        >
            <div class="flex flex-col gap-1 p-5 pb-4">
                <DialogTitle class="text-base/6 font-semibold">{{
                    $t('Count its own log file')
                }}</DialogTitle>
                <DialogDescription
                    class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                >
                    {{
                        $t(
                            'For an app that writes an access log itself, in the common or combined format or as JSON lines. ServerOS only reads the file, and starts from its end.',
                        )
                    }}
                </DialogDescription>
            </div>

            <form
                class="flex flex-col gap-4 border-t border-(--ds-divider) px-5 py-4"
                @submit.prevent="save"
            >
                <div class="flex flex-col gap-1.5">
                    <Label for="traffic-file-name">{{ $t('Name') }}</Label>
                    <Input
                        id="traffic-file-name"
                        v-model="siteName"
                        maxlength="60"
                        required
                    />
                </div>
                <div class="flex flex-col gap-1.5">
                    <Label for="traffic-file-path">{{ $t('File') }}</Label>
                    <Input
                        id="traffic-file-path"
                        v-model="path"
                        class="font-ui-mono text-[12px]"
                        placeholder="/srv/app/logs/access.log"
                        maxlength="500"
                        required
                    />
                </div>
                <p
                    v-if="error"
                    class="text-[13px]/5 text-(--ds-red)"
                    role="alert"
                >
                    {{ error }}
                </p>
                <div class="flex items-center justify-end gap-2">
                    <button
                        type="button"
                        :class="appButtonClasses('secondary')"
                        @click="open = false"
                    >
                        {{ $t('Cancel') }}
                    </button>
                    <button
                        type="submit"
                        :class="appButtonClasses('primary')"
                        :disabled="busy || !siteName.trim() || !path.trim()"
                    >
                        <LoaderCircle
                            v-if="busy"
                            class="size-3.5 animate-spin"
                            aria-hidden="true"
                        />
                        {{ busy ? $t('Adding it…') : $t('Count this file') }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
