<script setup lang="ts">
import { LoaderCircle } from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { onBeforeUnmount, ref } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import { describe, errorMessage } from '@/lib/errors';
import {
    SolarCircleAlert as CircleAlert,
    SolarCircleCheck as CircleCheck,
} from '@/lib/solarIcons';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';

const {
    machineId,
    machineName,
    message = null,
} = defineProps<{
    machineId: number;
    machineName: string;
    /** What the deploy said, when it was turned down for it. */
    message?: string | null;
}>();

const emit = defineEmits<{ installed: [] }>();

type Install = {
    jobId: string;
    state: string;
    finished: boolean;
    error: string | null;
    version: string | null;
    installed: boolean;
    log: string[];
};

const page = usePage<{ workspace: { slug: string } }>();
const phase = ref<'idle' | 'confirm' | 'installing' | 'done' | 'failed'>(
    'idle',
);
const job = ref<Install | null>(null);
const failure = ref<string | null>(null);
let alive = true;

onBeforeUnmount(() => (alive = false));

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function install(): Promise<void> {
    phase.value = 'installing';
    failure.value = null;
    const args = { workspace: page.props.workspace.slug, machine: machineId };

    try {
        const response = await fetch(
            onWorkspace(machinesRoutes.docker.install(args)).url,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({ confirm: true }),
            },
        );
        const body = await response.json().catch(() => ({}));
        if (!response.ok) {
            failure.value = describe(response.status, body);
            phase.value = 'failed';

            return;
        }
        job.value = body as Install;

        while (alive && job.value && !job.value.finished) {
            await new Promise((resolve) => setTimeout(resolve, 2000));
            const next = await fetch(
                onWorkspace(
                    machinesRoutes.docker.show({
                        ...args,
                        job: job.value.jobId,
                    }),
                ).url,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                },
            ).catch(() => null);
            if (next?.ok) job.value = (await next.json()) as Install;
        }
        if (!alive || !job.value) return;

        if (job.value.state === 'succeeded') {
            phase.value = 'done';
            emit('installed');
        } else {
            failure.value =
                job.value.error ??
                trans('The install didn’t finish. Try again.');
            phase.value = 'failed';
        }
    } catch (cause) {
        failure.value = errorMessage(cause);
        phase.value = 'failed';
    }
}
</script>

<template>
    <div
        class="flex flex-col gap-2.5 rounded-[10px] border-[0.5px] px-3.5 py-3 text-[13px]/5"
        :class="
            phase === 'done'
                ? 'border-status-live/30 bg-status-live/8'
                : 'border-status-pending/30 bg-status-pending/8'
        "
        role="status"
        aria-live="polite"
    >
        <div class="flex items-start gap-2">
            <CircleCheck
                v-if="phase === 'done'"
                class="mt-0.5 size-3.5 shrink-0 text-status-live"
                :stroke-width="2"
                aria-hidden="true"
            />
            <LoaderCircle
                v-else-if="phase === 'installing'"
                class="mt-0.5 size-3.5 shrink-0 animate-spin text-fg-muted"
                :stroke-width="2"
                aria-hidden="true"
            />
            <CircleAlert
                v-else
                class="mt-0.5 size-3.5 shrink-0 text-status-pending"
                :stroke-width="2"
                aria-hidden="true"
            />
            <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                <p class="text-fg-primary">
                    <template v-if="phase === 'done'">
                        {{
                            job?.version
                                ? $t('Docker :version is on :machine now.', {
                                      version: job.version,
                                      machine: machineName,
                                  })
                                : $t('Docker is on :machine now.', {
                                      machine: machineName,
                                  })
                        }}
                    </template>
                    <template v-else-if="phase === 'installing'">
                        {{
                            $t('Installing Docker on :machine…', {
                                machine: machineName,
                            })
                        }}
                    </template>
                    <template v-else-if="phase === 'confirm'">
                        {{
                            $t(
                                'Install Docker on :machine? It takes a few minutes.',
                                { machine: machineName },
                            )
                        }}
                    </template>
                    <template v-else>
                        {{
                            message ??
                            $t(
                                'Docker isn’t installed on :machine, and every deploy needs it.',
                                { machine: machineName },
                            )
                        }}
                    </template>
                </p>
                <p
                    v-if="phase === 'installing'"
                    class="truncate font-ui-mono text-[11.5px]/4 text-fg-muted"
                >
                    {{
                        job?.log.at(-1) ??
                        $t('It takes a few minutes. Keep setting up meanwhile.')
                    }}
                </p>
                <p
                    v-else-if="phase === 'confirm'"
                    class="text-[12px]/4 text-fg-muted"
                >
                    {{
                        $t(
                            'From Docker’s own packages, with Compose and Buildx. Nothing else on the machine changes.',
                        )
                    }}
                </p>
                <p
                    v-else-if="phase === 'done'"
                    class="text-[12px]/4 text-fg-muted"
                >
                    {{ $t('Deploy when you’re ready.') }}
                </p>
                <p
                    v-else-if="phase === 'failed' && failure"
                    class="text-[12px]/4 text-status-down"
                >
                    {{ failure }}
                </p>
            </div>
            <div v-if="phase === 'confirm'" class="flex shrink-0 gap-2">
                <DashButton
                    size="sm"
                    :label="$t('Cancel')"
                    @click="phase = 'idle'"
                />
                <DashButton
                    size="sm"
                    variant="primary"
                    :label="$t('Install')"
                    @click="install"
                />
            </div>
            <DashButton
                v-else-if="phase === 'idle' || phase === 'failed'"
                size="sm"
                class="shrink-0"
                :label="
                    phase === 'failed' ? $t('Try again') : $t('Install Docker')
                "
                @click="phase = 'confirm'"
            />
        </div>
    </div>
</template>
