<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import { describe, errorMessage, jobReason } from '@/lib/errors';
import { toast } from '@/lib/toast';

export type TrafficSettingsProps = {
    enabled: boolean;
    blocked: string | null;
    proxy: string | null;
    sites: number | null;
    changing: string | null;
    error: string | null;
};

const props = defineProps<{
    machineId: number;
    machineName: string;
    traffic: TrafficSettingsProps;
    canManage: boolean;
}>();

const on = ref(props.traffic.enabled);
const busy = ref(false);
const error = ref<string | null>(props.traffic.error);
let poll: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => clearTimeout(poll));

watch(
    () => props.traffic,
    (next) => {
        if (busy.value) return;
        on.value = next.enabled;
        error.value = next.error;
    },
);

const restarts = computed(
    () => props.traffic.proxy === null || props.traffic.proxy === 'Traefik',
);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function change(next: boolean): Promise<void> {
    if (busy.value) return;
    busy.value = true;
    error.value = null;
    on.value = next;

    try {
        const response = await fetch(`/machines/${props.machineId}/traffic`, {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify({ enabled: next }),
        });
        const body = await response.json().catch(() => ({}));
        if (response.status !== 202) {
            throw new Error(describe(response.status, body));
        }
        await follow(body.jobId, next);
    } catch (e) {
        on.value = !next;
        error.value = errorMessage(e);
    } finally {
        busy.value = false;
        router.reload({ only: ['traffic'] });
    }
}

function follow(jobId: string, next: boolean): Promise<void> {
    let tries = 0;

    return new Promise((resolve, reject) => {
        const check = async () => {
            try {
                const response = await fetch(
                    `/machines/${props.machineId}/traffic/${jobId}`,
                    {
                        credentials: 'same-origin',
                        headers: { Accept: 'application/json' },
                    },
                );
                const job = await response.json();
                if (job.state === 'succeeded') {
                    toast.success(
                        next
                            ? trans(
                                  ':name counts traffic now. Each app’s Traffic tab shows it within a couple of minutes.',
                                  { name: props.machineName },
                              )
                            : trans(':name stopped counting traffic.', {
                                  name: props.machineName,
                              }),
                    );
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
                                trans(
                                    'The machine didn’t change it. Try again.',
                                ),
                            ),
                        ),
                    );

                    return;
                }
                if (++tries >= 90) {
                    reject(
                        new Error(
                            trans(
                                'The machine is taking a while. This shows the change once it’s done.',
                            ),
                        ),
                    );

                    return;
                }
                poll = setTimeout(check, 1500);
            } catch (e) {
                reject(e);
            }
        };
        poll = setTimeout(check, 1000);
    });
}

onMounted(() => {
    if (!props.traffic.changing) return;
    busy.value = true;
    follow(props.traffic.changing, props.traffic.enabled)
        .catch((e) => {
            on.value = !props.traffic.enabled;
            error.value = errorMessage(e);
        })
        .finally(() => {
            busy.value = false;
            router.reload({ only: ['traffic'] });
        });
});
</script>

<template>
    <section class="ds-tile" :aria-label="$t('Traffic')">
        <div class="flex items-start justify-between gap-4 px-5 py-4">
            <div class="flex max-w-xl flex-col gap-0.5">
                <h2 class="text-[13px]/5 font-medium">{{ $t('Traffic') }}</h2>
                <p class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        $t(
                            'Counts visits to the sites ServerOS runs on :name from the proxy’s access logs. Visitor IPs never leave the machine.',
                            { name: machineName },
                        )
                    }}
                    <template v-if="restarts">
                        {{
                            ' ' +
                            $t(
                                'On Traefik, switching it restarts the proxy, so sites blip for a second.',
                            )
                        }}
                    </template>
                </p>
            </div>
            <ToggleSwitch
                :model-value="on"
                class="mt-0.5"
                :label="$t('Count traffic')"
                :disabled="!canManage || busy || traffic.blocked !== null"
                @update:model-value="change"
            />
        </div>

        <p
            v-if="traffic.blocked"
            class="border-t border-(--ds-border) px-5 py-3 text-[13px]/5 text-(--ds-text-muted)"
        >
            {{ traffic.blocked }}
        </p>
        <p
            v-else-if="busy"
            class="flex items-center gap-2 border-t border-(--ds-border) px-5 py-3 text-[13px]/5 text-(--ds-text-muted)"
        >
            <LoaderCircle class="size-3.5 animate-spin" aria-hidden="true" />
            {{ on ? $t('Turning it on…') : $t('Turning it off…') }}
        </p>
        <p
            v-else-if="error"
            class="border-t border-(--ds-border) px-5 py-3 text-[13px]/5 text-(--ds-red)"
            role="alert"
        >
            {{ $t('That didn’t apply: :reason', { reason: error }) }}
        </p>
        <p
            v-else-if="traffic.enabled && traffic.sites !== null"
            class="border-t border-(--ds-border) px-5 py-3 text-[13px]/5 text-(--ds-text-muted)"
        >
            {{
                $t(
                    traffic.sites === 1
                        ? 'Counting 1 site. Each app’s Traffic tab shows its visits.'
                        : 'Counting :count sites. Each app’s Traffic tab shows its visits.',
                    { count: String(traffic.sites) },
                )
            }}
        </p>
    </section>
</template>
