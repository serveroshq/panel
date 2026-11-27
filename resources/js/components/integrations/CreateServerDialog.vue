<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import IntegrationLogo from '@/components/integrations/IntegrationLogo.vue';
import type {
    Operation,
    ServerPlans,
} from '@/components/integrations/integrations';
import { money, sendJson } from '@/components/integrations/integrations';
import OperationSteps from '@/components/integrations/OperationSteps.vue';
import ServerTypeList from '@/components/integrations/ServerTypeList.vue';
import {
    operationActive,
    useOperation,
} from '@/components/integrations/useOperation';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { SelectMenu } from '@/components/ui/select';
import { onWorkspace } from '@/lib/workspaceRoute';
import integrationRoutes from '@/routes/workspace/integrations';
import machinesRoutes from '@/routes/workspace/machines';
import type { Workspace } from '@/types';

const { integration } = defineProps<{
    integration: {
        id: number;
        label: string;
        providerName: string;
        logo: string;
    };
}>();

const emit = defineEmits<{ made: [operation: Operation] }>();
const open = defineModel<boolean>('open', { default: false });
const page = usePage<{ workspace: Workspace }>();
const slug = computed(() => page.props.workspace.slug);

const plans = ref<ServerPlans | null>(null);
const loading = ref(false);
const loadError = ref('');
const saving = ref(false);
const errors = ref<Record<string, string>>({});
const name = ref('');
const location = ref<string | null>(null);
const type = ref<string | null>(null);
const image = ref<string | null>(null);
const keys = ref<Record<string, boolean>>({});
const family = ref<'shared' | 'arm' | 'dedicated'>('shared');

const { operation, follow } = useOperation(
    () => slug.value,
    null,
    (done) => {
        if (done.state === 'done') {
            router.reload({ only: ['servers', 'operations', 'usage'] });
        }
    },
);

const familyOf = (t: { cpu: string; architecture: string }) =>
    t.cpu === 'dedicated'
        ? 'dedicated'
        : t.architecture === 'arm'
          ? 'arm'
          : 'shared';

const typesHere = computed(() =>
    (plans.value?.types ?? []).filter(
        (t) =>
            location.value !== null &&
            (t.locations ?? []).includes(location.value),
    ),
);
const families = computed(() =>
    (
        [
            { value: 'shared', label: trans('Shared') },
            { value: 'arm', label: trans('Arm') },
            { value: 'dedicated', label: trans('Dedicated') },
        ] as const
    )
        .map((option) => ({
            ...option,
            count: typesHere.value.filter((t) => familyOf(t) === option.value)
                .length,
        }))
        .filter((option) => option.count > 0),
);
const shownTypes = computed(() =>
    typesHere.value.filter((t) => familyOf(t) === family.value),
);
const chosenType = computed(
    () => plans.value?.types.find((t) => t.name === type.value) ?? null,
);
const images = computed(() =>
    (plans.value?.images ?? []).filter(
        (i) => i.architecture === (chosenType.value?.architecture ?? 'x86'),
    ),
);
const imageOptions = computed(() =>
    images.value.map((i) => ({
        value: i.id,
        label: i.recommended
            ? trans(':name (recommended)', { name: i.label })
            : i.label,
    })),
);
const price = computed(() => {
    const t = chosenType.value;
    if (!t || !location.value || !plans.value) return null;
    const amount = t.prices?.[location.value]?.monthly;

    return amount === undefined ? null : money(amount, plans.value.currency);
});

watch(location, () => {
    if (!families.value.some((f) => f.value === family.value)) {
        family.value = families.value[0]?.value ?? 'shared';
    }
    if (!shownTypes.value.some((t) => t.name === type.value)) {
        type.value = shownTypes.value[0]?.name ?? null;
    }
});
watch(family, () => {
    if (!shownTypes.value.some((t) => t.name === type.value)) {
        type.value = shownTypes.value[0]?.name ?? null;
    }
});
watch(chosenType, () => {
    if (!images.value.some((i) => i.id === image.value)) {
        image.value =
            images.value.find((i) => i.recommended)?.id ??
            images.value[0]?.id ??
            null;
    }
});

async function load(): Promise<void> {
    loading.value = true;
    loadError.value = '';
    const result = await sendJson<ServerPlans>(
        'GET',
        onWorkspace(
            integrationRoutes.servers.options({
                workspace: slug.value,
                integration: integration.id,
            }),
        ).url,
    );
    loading.value = false;
    if (!result.ok) {
        loadError.value =
            result.message ||
            trans('The options couldn’t be loaded. Try again.');

        return;
    }
    plans.value = result.data;
    keys.value = Object.fromEntries(result.data.keys.map((k) => [k.id, true]));
    location.value = result.data.locations[0]?.name ?? null;
}

watch(open, (shown) => {
    if (!shown) return;
    errors.value = {};
    if (!operationActive(operation.value)) {
        operation.value = null;
        name.value = '';
    }
    if (!plans.value) void load();
});

async function submit(): Promise<void> {
    if (!type.value || !location.value || !image.value) return;
    saving.value = true;
    errors.value = {};
    const result = await sendJson<{ operation: Operation }>(
        'POST',
        onWorkspace(
            integrationRoutes.servers.store({
                workspace: slug.value,
                integration: integration.id,
            }),
        ).url,
        {
            name: name.value.trim(),
            location: location.value,
            type: type.value,
            image: image.value,
            keys: Object.entries(keys.value)
                .filter(([, on]) => on)
                .map(([id]) => id),
        },
    );
    saving.value = false;
    if (!result.ok) {
        errors.value = {
            ...result.errors,
            form:
                result.errors.form ??
                (Object.keys(result.errors).length
                    ? ''
                    : result.message || trans('That didn’t work. Try again.')),
        };

        return;
    }
    follow(result.data.operation);
    emit('made', result.data.operation);
}

const fieldClass =
    'h-8 w-full rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field outline-none placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]';
const labelClass = 'text-[12.5px]/4 text-fg-tertiary';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal flex max-h-[calc(100svh-2rem)] flex-col gap-0 overflow-hidden !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[560px]"
            :show-close-button="false"
        >
            <div class="flex items-start gap-3 pt-[18px] pr-4 pb-4 pl-5">
                <IntegrationLogo :logo="integration.logo" />
                <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                    <DialogTitle
                        class="text-[16px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                    >
                        {{
                            operation
                                ? operation.name
                                : $t('New server in :name', {
                                      name: integration.label,
                                  })
                        }}
                    </DialogTitle>
                    <DialogDescription class="text-[13px]/5 text-fg-tertiary">
                        {{
                            operation
                                ? $t(
                                      'You can close this. It keeps going, and shows on the account page.',
                                  )
                                : $t(
                                      'It installs ServerOS as it starts, and joins :workspace on its own.',
                                      { workspace: page.props.workspace.name },
                                  )
                        }}
                    </DialogDescription>
                </div>
                <kbd
                    class="flex h-5 items-center rounded-[5px] border-[0.5px] border-edge-strong px-1.5 font-ui text-[11px]/[14px] text-fg-muted"
                    >Esc</kbd
                >
            </div>

            <div v-if="operation" class="flex flex-col gap-4 px-5 pb-5">
                <OperationSteps :operation="operation" />
                <div class="flex justify-end gap-2">
                    <DashButton :label="$t('Close')" @click="open = false" />
                    <DashButton
                        v-if="operation.state === 'done' && operation.machineId"
                        variant="primary"
                        :href="
                            onWorkspace(
                                machinesRoutes.show({
                                    workspace: slug,
                                    machine: operation.machineId,
                                }),
                            ).url
                        "
                        :label="$t('Open :name', { name: operation.name })"
                    />
                </div>
            </div>

            <form
                v-else
                class="flex min-h-0 flex-1 flex-col"
                @submit.prevent="submit"
            >
                <div
                    v-if="loading"
                    class="px-5 pb-6 text-[13px]/5 text-fg-muted"
                    role="status"
                >
                    {{
                        $t('Asking :name what it has…', {
                            name: integration.providerName,
                        })
                    }}
                </div>
                <div
                    v-else-if="loadError"
                    class="flex flex-col items-start gap-3 px-5 pb-5"
                >
                    <p class="text-[13px]/5 text-status-down" role="alert">
                        {{ loadError }}
                    </p>
                    <DashButton
                        size="sm"
                        :label="$t('Try again')"
                        @click="load"
                    />
                </div>
                <div
                    v-else-if="plans"
                    class="flex min-h-0 flex-1 flex-col gap-4 overflow-y-auto px-5 pb-[18px]"
                >
                    <label class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('Name') }}</span>
                        <input
                            v-model="name"
                            type="text"
                            maxlength="63"
                            required
                            autofocus
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="$t('web-3')"
                            :class="fieldClass"
                        />
                        <span
                            v-if="errors.name"
                            class="text-[12px]/4 text-status-down"
                            role="alert"
                            >{{ errors.name }}</span
                        >
                    </label>

                    <div class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('Location') }}</span>
                        <div
                            role="radiogroup"
                            :aria-label="$t('Location')"
                            class="grid grid-cols-2 gap-1.5 sm:grid-cols-3"
                        >
                            <button
                                v-for="place in plans.locations"
                                :key="place.name"
                                type="button"
                                role="radio"
                                :aria-checked="location === place.name"
                                class="flex cursor-pointer flex-col items-start rounded-[8px] border-[0.5px] px-2.5 py-1.5 text-left focus-visible:outline-2 focus-visible:outline-(--ds-ring)"
                                :class="
                                    location === place.name
                                        ? 'border-[rgba(51,97,255,0.7)] bg-[rgba(51,97,255,0.08)]'
                                        : 'border-edge-card bg-surface-card hover:bg-surface-hover'
                                "
                                @click="location = place.name"
                            >
                                <span
                                    class="text-[13px]/5 font-medium text-fg-primary"
                                    >{{ place.city }}</span
                                >
                                <span
                                    class="font-ui-mono text-[11.5px]/4 text-fg-muted"
                                    >{{ place.name }} ·
                                    {{ place.country }}</span
                                >
                            </button>
                        </div>
                        <span
                            v-if="errors.location"
                            class="text-[12px]/4 text-status-down"
                            >{{ errors.location }}</span
                        >
                    </div>

                    <div class="flex flex-col gap-1.5">
                        <div class="flex items-center justify-between gap-3">
                            <span :class="labelClass">{{
                                $t('Server type')
                            }}</span>
                            <FilterPills
                                v-if="families.length > 1"
                                v-model="family"
                                :options="families"
                                :label="$t('Kind of server')"
                            />
                        </div>
                        <ServerTypeList
                            v-model="type"
                            :types="shownTypes"
                            :currency="plans.currency"
                            :location="location"
                            :label="$t('Server type')"
                        />
                        <span
                            v-if="errors.type"
                            class="text-[12px]/4 text-status-down"
                            >{{ errors.type }}</span
                        >
                    </div>

                    <label class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('System') }}</span>
                        <SelectMenu
                            v-model="image"
                            :options="imageOptions"
                            :aria-label="$t('System')"
                            class="h-8 rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field"
                        />
                        <span
                            v-if="errors.image"
                            class="text-[12px]/4 text-status-down"
                            >{{ errors.image }}</span
                        >
                    </label>

                    <div class="flex flex-col gap-1.5">
                        <span :class="labelClass">{{ $t('SSH keys') }}</span>
                        <div
                            v-if="plans.keys.length"
                            class="flex flex-col rounded-[10px] border-[0.5px] border-edge-card bg-surface-card"
                        >
                            <label
                                v-for="key in plans.keys"
                                :key="key.id"
                                class="flex cursor-pointer items-center gap-2.5 border-t-[0.5px] border-edge px-3 py-2 first:border-t-0"
                            >
                                <DashCheckbox
                                    v-model="keys[key.id]"
                                    :label="key.name"
                                />
                                <span class="flex-1 truncate text-[13px]/5">{{
                                    key.name
                                }}</span>
                                <span
                                    v-if="key.fingerprint"
                                    class="font-ui-mono text-[11px]/4 text-fg-faint"
                                    >{{ key.fingerprint.slice(-11) }}</span
                                >
                            </label>
                        </div>
                        <span class="text-[12px]/4 text-fg-muted">{{
                            $t(
                                'Optional. Without one, use the ServerOS terminal, or reset the root password at :provider.',
                                { provider: integration.providerName },
                            )
                        }}</span>
                    </div>

                    <p
                        v-if="errors.form"
                        class="rounded-[8px] border-[0.5px] border-[rgba(240,105,90,0.25)] bg-[rgba(240,105,90,0.07)] px-3 py-2 text-[12.5px]/[18px] text-fg-secondary"
                        role="alert"
                    >
                        {{ errors.form }}
                    </p>
                </div>

                <div
                    class="flex shrink-0 items-center gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
                >
                    <span
                        class="flex flex-1 items-center gap-1.5 text-[12.5px]/4 text-fg-muted"
                    >
                        <template v-if="price">
                            <FilledIcon name="info-fill" :size="13" />
                            {{
                                $t(':price a month at :provider', {
                                    price,
                                    provider: integration.providerName,
                                })
                            }}
                        </template>
                    </span>
                    <DashButton :label="$t('Cancel')" @click="open = false" />
                    <DashButton
                        type="submit"
                        variant="primary"
                        :label="$t('Make server')"
                        :loading="saving"
                        :disabled="!plans || !type || !image || !name.trim()"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
