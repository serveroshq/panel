<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router } from '@inertiajs/vue3';
import { Check } from '@lucide/vue';
import {
    SolarCopy as Copy,
    SolarWebhook as WebhookIcon,
} from '@/lib/solarIcons';
import { computed, ref } from 'vue';
import { toast } from '@/lib/toast';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import AppButton from '@/components/workspace/AppButton.vue';
import DangerButton from '@/components/workspace/DangerButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import Tile from '@/components/workspace/Tile.vue';
import { timeAgo } from '@/lib/timeAgo';

export type WebhookDelivery = {
    id: number;
    eventId: string;
    event: string;
    state: 'pending' | 'delivered' | 'failed';
    attempts: number;
    responseStatus: number | null;
    error: string | null;
    createdAt: string;
    deliveredAt: string | null;
    nextAttemptAt: string | null;
};

export type WebhookEndpoint = {
    id: number;
    url: string;
    description: string | null;
    events: 'all' | string[];
    enabled: boolean;
    disabledReason: string | null;
    createdAt: string;
    lastDelivery: WebhookDelivery | null;
    deliveries: WebhookDelivery[];
};

defineProps<{
    webhooks: WebhookEndpoint[];
    events: string[];
    canChange: boolean;
}>();

function csrf(): string {
    return (
        document
            .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.getAttribute('content') ?? ''
    );
}

async function post(
    url: string,
    body: unknown,
): Promise<{ ok: boolean; body: Record<string, unknown> }> {
    const response = await fetch(url, {
        method: 'POST',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: JSON.stringify(body),
    });

    return {
        ok: response.ok,
        body: (await response.json().catch(() => ({}))) as Record<
            string,
            unknown
        >,
    };
}

const addOpen = ref(false);
const url = ref('');
const description = ref('');
const picked = ref<string[]>([]);
const errors = ref<Record<string, string>>({});
const saving = ref(false);
const secret = ref<string | null>(null);
const copied = ref(false);

function add(): void {
    url.value = '';
    description.value = '';
    picked.value = [];
    errors.value = {};
    secret.value = null;
    copied.value = false;
    addOpen.value = true;
}

async function create(): Promise<void> {
    if (!url.value.trim() || saving.value) return;
    saving.value = true;
    errors.value = {};
    try {
        const { ok, body } = await post('/settings/api/webhooks', {
            url: url.value.trim(),
            description: description.value.trim() || null,
            events: picked.value,
        });
        if (!ok || typeof body.secret !== 'string') {
            const found = (body.errors ?? {}) as Record<string, string[]>;
            errors.value = Object.fromEntries(
                Object.entries(found).map(([k, v]) => [
                    k.startsWith('events') ? 'events' : k,
                    v[0],
                ]),
            );
            if (!body.errors)
                errors.value.url =
                    (body.message as string | undefined) ??
                    trans('Couldn’t add the endpoint.');

            return;
        }
        secret.value = body.secret;
        router.reload({ only: ['webhooks'] });
    } finally {
        saving.value = false;
    }
}

async function copySecret(): Promise<void> {
    if (!secret.value) return;
    try {
        await navigator.clipboard.writeText(secret.value);
        copied.value = true;
        setTimeout(() => (copied.value = false), 1500);
    } catch {
        toast.error(trans('Couldn’t copy; select it and copy by hand.'));
    }
}

const testing = ref<number | null>(null);
async function test(endpoint: WebhookEndpoint): Promise<void> {
    testing.value = endpoint.id;
    try {
        const { ok, body } = await post(
            `/settings/api/webhooks/${endpoint.id}/test`,
            {},
        );
        const delivery = body.delivery as WebhookDelivery | undefined;
        if (ok && body.delivered) {
            toast.success(
                trans('The test event got through (:status).', {
                    status: String(delivery?.responseStatus ?? ''),
                }),
            );
        } else {
            toast.error(
                delivery?.error ??
                    (body.message as string | undefined) ??
                    trans('The test event didn’t get through.'),
            );
        }
        router.reload({ only: ['webhooks'] });
    } finally {
        testing.value = null;
    }
}

function setEnabled(endpoint: WebhookEndpoint, enabled: boolean): void {
    router.patch(
        `/settings/api/webhooks/${endpoint.id}`,
        { enabled },
        { preserveScroll: true, only: ['webhooks'] },
    );
}

const removing = ref<WebhookEndpoint | null>(null);
const removeBusy = ref(false);
function remove(): void {
    if (!removing.value) return;
    router.delete(`/settings/api/webhooks/${removing.value.id}`, {
        preserveScroll: true,
        onStart: () => (removeBusy.value = true),
        onFinish: () => {
            removeBusy.value = false;
            removing.value = null;
        },
    });
}

const open = ref<number | null>(null);

function eventsLabel(endpoint: WebhookEndpoint): string {
    return endpoint.events === 'all'
        ? trans('All events')
        : endpoint.events.join(', ');
}

function tone(delivery: WebhookDelivery): string {
    if (delivery.state === 'delivered')
        return 'bg-(--ds-green-bg) text-(--ds-green)';
    if (delivery.state === 'pending')
        return 'bg-(--ds-amber-bg) text-(--ds-amber)';

    return 'bg-(--ds-red-bg) text-(--ds-red)';
}

function stateLabel(delivery: WebhookDelivery): string {
    return {
        delivered: trans('Delivered'),
        pending: trans('Retrying'),
        failed: trans('Failed'),
    }[delivery.state];
}

const verifySnippet = computed(
    () =>
        "import { verifyWebhook } from '@serveros/sdk';\n\nconst event = await verifyWebhook(rawBody, req.headers['serveros-signature'], process.env.SERVEROS_WEBHOOK_SECRET);",
);

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
const labelClass = 'text-[13px]/5 font-medium text-(--ds-text-muted)';
const th = 'px-3 py-2.5 text-left text-[12px]/4 font-medium';
const pill = 'rounded-full px-1.5 py-px text-[11px]/4 whitespace-nowrap';
</script>

<template>
    <Tile
        id="webhooks"
        :title="$t('Webhooks')"
        :description="
            $t(
                'Signed events sent to your own address as they happen, instead of polling: operations, jobs, deploys, and machines going offline and back. Each carries ids and states, never logs.',
            )
        "
        :icon="WebhookIcon"
        :padded="false"
    >
        <template v-if="canChange" #actions>
            <AppButton size="sm" @click="add">{{
                $t('Add endpoint')
            }}</AppButton>
        </template>

        <div v-if="webhooks.length" class="overflow-x-auto">
            <table class="w-full text-[13px]/5 sm:min-w-[640px]">
                <thead>
                    <tr class="text-(--ds-text-muted)">
                        <th :class="[th, 'pl-5']">{{ $t('Endpoint') }}</th>
                        <th :class="[th, 'max-sm:hidden']">
                            {{ $t('Events') }}
                        </th>
                        <th :class="th">{{ $t('Last delivery') }}</th>
                        <th :class="[th, 'pr-5']"></th>
                    </tr>
                </thead>
                <tbody>
                    <template v-for="endpoint in webhooks" :key="endpoint.id">
                        <tr class="border-t border-(--ds-divider) align-top">
                            <td class="px-3 py-2.5 pl-5">
                                <span
                                    class="font-ui-mono text-[12.5px] break-all"
                                    >{{ endpoint.url }}</span
                                >
                                <span
                                    v-if="endpoint.description"
                                    class="block text-[12.5px]/5 text-(--ds-text-muted)"
                                    >{{ endpoint.description }}</span
                                >
                                <span
                                    v-if="!endpoint.enabled"
                                    class="mt-1 block text-[12.5px]/5 text-(--ds-red)"
                                    >{{
                                        endpoint.disabledReason ?? $t('Off')
                                    }}</span
                                >
                            </td>
                            <td
                                class="px-3 py-2.5 text-(--ds-text-secondary) max-sm:hidden"
                            >
                                {{ eventsLabel(endpoint) }}
                            </td>
                            <td class="px-3 py-2.5 whitespace-nowrap">
                                <template v-if="endpoint.lastDelivery">
                                    <span
                                        :class="[
                                            pill,
                                            tone(endpoint.lastDelivery),
                                        ]"
                                        >{{
                                            endpoint.lastDelivery
                                                .responseStatus ??
                                            stateLabel(endpoint.lastDelivery)
                                        }}</span
                                    >
                                    <span
                                        class="ml-1.5 text-(--ds-text-muted)"
                                        >{{
                                            timeAgo(
                                                endpoint.lastDelivery.createdAt,
                                            )
                                        }}</span
                                    >
                                </template>
                                <span v-else class="text-(--ds-text-muted)">{{
                                    $t('None yet')
                                }}</span>
                            </td>
                            <td class="px-3 py-2.5 pr-5 text-right">
                                <div class="flex flex-wrap justify-end gap-1.5">
                                    <AppButton
                                        size="xs"
                                        variant="quiet"
                                        :aria-expanded="open === endpoint.id"
                                        @click="
                                            open =
                                                open === endpoint.id
                                                    ? null
                                                    : endpoint.id
                                        "
                                        >{{ $t('Deliveries') }}</AppButton
                                    >
                                    <template v-if="canChange">
                                        <AppButton
                                            size="xs"
                                            :disabled="testing === endpoint.id"
                                            @click="test(endpoint)"
                                            >{{
                                                testing === endpoint.id
                                                    ? $t('Sending…')
                                                    : $t('Send test')
                                            }}</AppButton
                                        >
                                        <AppButton
                                            size="xs"
                                            @click="
                                                setEnabled(
                                                    endpoint,
                                                    !endpoint.enabled,
                                                )
                                            "
                                            >{{
                                                endpoint.enabled
                                                    ? $t('Turn off')
                                                    : $t('Turn on')
                                            }}</AppButton
                                        >
                                        <AppButton
                                            size="xs"
                                            @click="removing = endpoint"
                                            >{{ $t('Remove') }}</AppButton
                                        >
                                    </template>
                                </div>
                            </td>
                        </tr>
                        <tr v-if="open === endpoint.id">
                            <td colspan="4" class="px-5 pb-4">
                                <p
                                    v-if="!endpoint.deliveries.length"
                                    class="text-[12.5px]/5 text-(--ds-text-muted)"
                                >
                                    {{ $t('No deliveries yet.') }}
                                </p>
                                <ul
                                    v-else
                                    class="flex flex-col divide-y divide-(--ds-divider) rounded-md border border-(--ds-divider)"
                                >
                                    <li
                                        v-for="delivery in endpoint.deliveries"
                                        :key="delivery.id"
                                        class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5 px-3 py-2 text-[12.5px]/5"
                                    >
                                        <span :class="[pill, tone(delivery)]">{{
                                            stateLabel(delivery)
                                        }}</span>
                                        <span class="font-ui-mono">{{
                                            delivery.event
                                        }}</span>
                                        <span
                                            v-if="delivery.responseStatus"
                                            class="font-ui-mono text-(--ds-text-muted) tabular-nums"
                                            >{{ delivery.responseStatus }}</span
                                        >
                                        <span class="text-(--ds-text-muted)">{{
                                            $t(
                                                delivery.attempts === 1
                                                    ? ':count attempt'
                                                    : ':count attempts',
                                                {
                                                    count: String(
                                                        delivery.attempts,
                                                    ),
                                                },
                                            )
                                        }}</span>
                                        <span
                                            v-if="delivery.error"
                                            class="text-(--ds-text-muted)"
                                            >{{ delivery.error }}</span
                                        >
                                        <span
                                            class="ml-auto whitespace-nowrap text-(--ds-text-muted)"
                                            >{{
                                                timeAgo(delivery.createdAt)
                                            }}</span
                                        >
                                    </li>
                                </ul>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
        <p
            v-else
            class="px-5 py-6 text-center text-[13px]/5 text-(--ds-text-muted)"
        >
            {{ $t('No webhook endpoints yet.') }}
        </p>
    </Tile>

    <Dialog v-model:open="addOpen">
        <DialogContent
            class="workspace-panel ds-modal gap-0 overflow-hidden p-0 font-ui text-foreground sm:max-w-md"
        >
            <form
                v-if="!secret"
                class="flex min-w-0 flex-col"
                @submit.prevent="create"
            >
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('Add a webhook endpoint')
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                        >{{
                            $t(
                                'Failed deliveries are tried again for up to 24 hours. An endpoint that keeps failing is turned off, and you get an email.',
                            )
                        }}</DialogDescription
                    >
                </div>
                <div class="flex flex-col gap-4 p-5">
                    <div class="flex flex-col gap-1.5">
                        <label for="webhook-url" :class="labelClass">{{
                            $t('Address')
                        }}</label>
                        <Input
                            id="webhook-url"
                            v-model="url"
                            type="url"
                            maxlength="2000"
                            placeholder="https://example.com/serveros"
                            autocomplete="off"
                            required
                        />
                        <InputError :message="errors.url" />
                    </div>
                    <div class="flex flex-col gap-1.5">
                        <label for="webhook-description" :class="labelClass">{{
                            $t('Note')
                        }}</label>
                        <Input
                            id="webhook-description"
                            v-model="description"
                            maxlength="120"
                            :placeholder="$t('CI notifier')"
                            autocomplete="off"
                        />
                        <InputError :message="errors.description" />
                    </div>
                    <fieldset class="flex flex-col gap-1.5">
                        <legend :class="labelClass">{{ $t('Events') }}</legend>
                        <span class="text-[12px]/4 text-(--ds-text-muted)">{{
                            $t('Leave them all unticked to get every event.')
                        }}</span>
                        <label
                            v-for="event in events"
                            :key="event"
                            class="flex cursor-pointer items-center gap-2 text-[13px]/5"
                        >
                            <input
                                v-model="picked"
                                type="checkbox"
                                :value="event"
                                class="accent-(--ds-blue)"
                                :class="focusRing"
                            />
                            <span class="font-ui-mono text-[12.5px]">{{
                                event
                            }}</span>
                        </label>
                        <InputError :message="errors.events" />
                    </fieldset>
                </div>
                <div
                    class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <AppButton
                        size="sm"
                        variant="quiet"
                        @click="addOpen = false"
                        >{{ $t('Cancel') }}</AppButton
                    >
                    <SubmitButton
                        :label="$t('Add endpoint')"
                        busy-label="Adding…"
                        :processing="saving"
                        :disabled="url.trim() === ''"
                    />
                </div>
            </form>

            <div v-else class="flex min-w-0 flex-col">
                <div class="flex flex-col gap-1 px-5 pt-5">
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('Copy the signing secret now')
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-(--ds-text-muted)"
                        >{{
                            $t(
                                'You won’t see it again. Check each delivery’s ServerOS-Signature header with it, so you know it came from ServerOS.',
                            )
                        }}</DialogDescription
                    >
                </div>
                <div class="flex flex-col gap-3 p-5">
                    <div class="flex gap-2">
                        <input
                            class="h-9 min-w-0 grow rounded-md border border-(--ds-border) bg-(--ds-field) px-3 font-ui-mono text-[12px]/5"
                            :class="focusRing"
                            :value="secret"
                            readonly
                            :aria-label="$t('Signing secret')"
                            @focus="
                                ($event.target as HTMLInputElement).select()
                            "
                        />
                        <AppButton @click="copySecret">
                            <component
                                :is="copied ? Check : Copy"
                                class="size-3.5"
                                :stroke-width="1.75"
                            />
                            {{ copied ? $t('Copied') : $t('Copy') }}
                        </AppButton>
                    </div>
                    <pre
                        class="overflow-x-auto rounded-md bg-(--ds-field) p-3 font-ui-mono text-[11.5px]/5 text-(--ds-text-secondary)"
                        >{{ verifySnippet }}</pre>
                </div>
                <div
                    class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
                >
                    <AppButton
                        size="sm"
                        variant="primary"
                        @click="addOpen = false"
                        >{{ $t('Done') }}</AppButton
                    >
                </div>
            </div>
        </DialogContent>
    </Dialog>

    <Dialog
        :open="removing !== null"
        @update:open="(value) => !value && (removing = null)"
    >
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-md"
        >
            <div class="flex flex-col gap-1 p-5">
                <DialogTitle class="text-base/6 font-semibold">{{
                    $t('Remove the webhook to :url?', {
                        url: removing?.url ?? '',
                    })
                }}</DialogTitle>
                <DialogDescription
                    class="text-[13px]/5 text-(--ds-text-muted)"
                    >{{
                        $t(
                            'Events stop going there at once, and its delivery history is deleted.',
                        )
                    }}</DialogDescription
                >
            </div>
            <div
                class="flex items-center justify-end gap-2 border-t border-(--ds-border) px-4 py-3"
            >
                <AppButton size="sm" variant="quiet" @click="removing = null">{{
                    $t('Cancel')
                }}</AppButton>
                <form @submit.prevent="remove">
                    <DangerButton
                        :label="$t('Remove')"
                        busy-label="Removing…"
                        :processing="removeBusy"
                    />
                </form>
            </div>
        </DialogContent>
    </Dialog>
</template>
