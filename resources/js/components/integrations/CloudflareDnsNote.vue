<script setup lang="ts">
import { CircleAlert, CircleCheck } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import type { CloudflareCheck } from '@/components/integrations/cloudflare';
import {
    answerError,
    managedHere,
    sendJson,
} from '@/components/integrations/cloudflare';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';

const props = defineProps<{
    cloudflare: CloudflareCheck;
    workspaceSlug: string;
    machineId: number;
    service: string;
    domain: string;
    wildcard?: boolean;
    wildcardCloudflare?: CloudflareCheck | null;
}>();

const emit = defineEmits<{ pointed: [check: Record<string, unknown>] }>();

const proxied = ref(props.cloudflare.record?.proxied ?? false);
const busy = ref(false);
const error = ref<string | null>(null);

const record = computed(() => props.cloudflare.record);
const done = computed(
    () =>
        managedHere(record.value) &&
        (!props.wildcard ||
            !props.wildcardCloudflare ||
            managedHere(props.wildcardCloudflare.record)),
);
const failure = computed(
    () =>
        error.value ??
        (record.value?.status === 'failed' ? record.value.error : null) ??
        (props.wildcard && props.wildcardCloudflare?.record?.status === 'failed'
            ? props.wildcardCloudflare.record.error
            : null),
);
const elsewhere = computed(() =>
    record.value && !record.value.here && record.value.status === 'active'
        ? record.value
        : null,
);

async function point(): Promise<void> {
    if (busy.value || !props.service) return;
    busy.value = true;
    error.value = null;
    try {
        const answer = await sendJson(
            'POST',
            onWorkspace(
                machinesRoutes.domainDns({
                    workspace: props.workspaceSlug,
                    machine: props.machineId,
                }),
            ).url,
            {
                domain: props.domain,
                wildcard: !!props.wildcard,
                service: props.service,
                proxied: props.cloudflare.forced ? true : proxied.value,
            },
        );
        if (answer.ok) {
            emit('pointed', answer.data);
        } else {
            if (answer.data.cloudflare) emit('pointed', answer.data);
            error.value = answerError(
                answer,
                trans('Cloudflare didn’t take the change.'),
            );
        }
    } catch {
        error.value = trans('Cloudflare didn’t take the change.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div
        class="flex flex-col gap-2.5 rounded-[10px] border-[0.5px] px-3.5 py-3 text-[12px]/4"
        :class="
            done && !failure
                ? 'border-status-live/30 bg-status-live/8'
                : failure
                  ? 'border-status-pending/30 bg-status-pending/8'
                  : 'border-edge-field bg-surface-field'
        "
        aria-live="polite"
    >
        <p class="flex items-start gap-1.5 text-fg-secondary">
            <CircleCheck
                v-if="done && !failure"
                class="mt-px size-3.5 shrink-0 text-status-live"
                :stroke-width="2"
                aria-hidden="true"
            />
            <CircleAlert
                v-else-if="failure"
                class="mt-px size-3.5 shrink-0 text-status-pending"
                :stroke-width="2"
                aria-hidden="true"
            />
            <BrandLogo v-else name="cloudflare" :size="14" class="mt-px" />
            <span v-if="failure">{{ failure }}</span>
            <span v-else-if="done">
                {{
                    record?.proxied
                        ? $t(
                              'DNS managed by Cloudflare: :domain points here, through Cloudflare’s proxy.',
                              { domain },
                          )
                        : $t(
                              'DNS managed by Cloudflare: :domain points here.',
                              { domain },
                          )
                }}
            </span>
            <span v-else-if="elsewhere">
                {{
                    $t(
                        'ServerOS points :domain at :app on :machine at Cloudflare now.',
                        {
                            domain,
                            app: elsewhere.app,
                            machine: elsewhere.machine ?? '',
                        },
                    )
                }}
            </span>
            <span v-else>
                {{
                    $t(
                        ':domain is in your Cloudflare account :account. ServerOS points it here as it deploys, or now.',
                        { domain, account: cloudflare.account },
                    )
                }}
            </span>
        </p>
        <p v-if="record?.warning && done" class="text-fg-muted">
            {{ record.warning }}
        </p>
        <template v-if="!done || failure">
            <p v-if="!cloudflare.canPoint" class="text-fg-muted">
                {{
                    $t(
                        'ServerOS doesn’t know a public address for this machine, so it can’t point the domain here.',
                    )
                }}
            </p>
            <div v-else class="flex flex-wrap items-center gap-x-3 gap-y-2">
                <DashButton
                    size="sm"
                    :label="
                        elsewhere
                            ? $t('Point it here instead')
                            : $t('Point it here')
                    "
                    :loading="busy"
                    :disabled="!service"
                    @click="point"
                />
                <label
                    v-if="!cloudflare.forced"
                    class="flex cursor-pointer items-center gap-2 text-fg-secondary select-none"
                >
                    <DashCheckbox
                        v-model="proxied"
                        :label="$t('Proxied through Cloudflare')"
                    />
                    {{ $t('Proxied through Cloudflare') }}
                </label>
            </div>
            <p
                v-if="cloudflare.canPoint && !cloudflare.forced"
                class="text-fg-muted"
            >
                {{
                    $t(
                        'Proxied hides this machine’s address, but the machine can’t get its own HTTPS certificate through it. Leave it off unless Cloudflare does HTTPS for you.',
                    )
                }}
            </p>
            <p v-else-if="cloudflare.forced" class="text-fg-muted">
                {{
                    $t(
                        'This machine serves its apps through a Cloudflare Tunnel, so the record is a proxied CNAME to the tunnel.',
                    )
                }}
            </p>
        </template>
    </div>
</template>
