<script setup lang="ts">
import { useForm, usePage } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import IntegrationLogo from '@/components/integrations/IntegrationLogo.vue';
import type { ProviderInfo } from '@/components/integrations/integrations';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { onWorkspace } from '@/lib/workspaceRoute';
import integrationRoutes from '@/routes/workspace/integrations';
import type { Workspace } from '@/types';

const { provider, logo = null } = defineProps<{
    provider: ProviderInfo | null;
    logo?: string | null;
}>();

const open = defineModel<boolean>('open', { default: false });
const page = usePage<{ workspace: Workspace }>();

const form = useForm<{ label: string; credentials: Record<string, string> }>({
    label: '',
    credentials: {},
});

watch(open, (shown) => {
    if (!shown) return;
    form.reset();
    form.clearErrors();
    form.credentials = Object.fromEntries(
        (provider?.fields ?? []).map((field) => [field.key, field.value ?? '']),
    );
});

const errors = computed(() => form.errors as Record<string, string>);

function submit(): void {
    if (!provider) return;
    form.post(
        onWorkspace(
            integrationRoutes.connect({
                workspace: page.props.workspace.slug,
                provider: provider.key,
            }),
        ).url,
        {
            preserveScroll: true,
            onSuccess: () => (open.value = false),
        },
    );
}

const fieldClass =
    'h-8 w-full rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-2.5 text-[13px]/5 text-fg-primary shadow-field outline-none placeholder:text-fg-faint focus:border-[rgba(51,97,255,0.7)]';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 !rounded-[12px] p-0 font-ui text-fg-primary sm:max-w-[480px]"
            :show-close-button="false"
        >
            <form
                v-if="provider"
                class="flex flex-col"
                @submit.prevent="submit"
            >
                <div class="flex items-start gap-3 pt-[18px] pr-4 pb-4 pl-5">
                    <IntegrationLogo :logo="logo ?? provider.key" />
                    <div class="flex min-w-0 flex-1 flex-col gap-0.5">
                        <DialogTitle
                            class="text-[16px]/6 font-medium tracking-[-0.01em] text-fg-primary"
                        >
                            {{ $t('Connect :name', { name: provider.name }) }}
                        </DialogTitle>
                        <DialogDescription
                            class="text-[13px]/5 text-fg-tertiary"
                        >
                            {{
                                $t(
                                    'ServerOS checks it with :name before saving it.',
                                    { name: provider.name },
                                )
                            }}
                        </DialogDescription>
                    </div>
                    <kbd
                        class="flex h-5 items-center rounded-[5px] border-[0.5px] border-edge-strong px-1.5 font-ui text-[11px]/[14px] text-fg-muted"
                        >Esc</kbd
                    >
                </div>

                <div class="flex flex-col gap-4 px-5 pb-[18px]">
                    <label
                        v-for="(field, index) in provider.fields"
                        :key="field.key"
                        class="flex flex-col gap-1.5"
                    >
                        <span
                            class="flex items-center justify-between gap-3 text-[12.5px]/4 text-fg-tertiary"
                        >
                            {{ field.label }}
                            <a
                                v-if="field.link"
                                :href="field.link"
                                target="_blank"
                                rel="noopener"
                                class="inline-flex items-center gap-1 text-fg-muted hover:text-fg-primary"
                                >{{ field.linkLabel ?? field.link
                                }}<FilledIcon name="open-fill" :size="12"
                            /></a>
                        </span>
                        <input
                            v-model="form.credentials[field.key]"
                            :type="field.secret ? 'password' : 'text'"
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="field.placeholder ?? ''"
                            :autofocus="index === 0"
                            :class="[
                                fieldClass,
                                field.secret ? 'font-ui-mono' : '',
                            ]"
                            :aria-invalid="!!errors[`credentials.${field.key}`]"
                            required
                        />
                        <span
                            v-if="errors[`credentials.${field.key}`]"
                            class="text-[12px]/4 text-status-down"
                            role="alert"
                            >{{ errors[`credentials.${field.key}`] }}</span
                        >
                        <span
                            v-if="field.help"
                            class="text-[12px]/4 text-fg-muted"
                            >{{ field.help }}</span
                        >
                    </label>

                    <label class="flex flex-col gap-1.5">
                        <span class="text-[12.5px]/4 text-fg-tertiary">{{
                            $t('Name')
                        }}</span>
                        <input
                            v-model="form.label"
                            type="text"
                            maxlength="80"
                            :placeholder="
                                provider.multiple
                                    ? $t('Optional, like the project’s name')
                                    : provider.name
                            "
                            :class="fieldClass"
                        />
                        <span
                            v-if="errors.label"
                            class="text-[12px]/4 text-status-down"
                            role="alert"
                            >{{ errors.label }}</span
                        >
                    </label>

                    <ul
                        v-if="provider.capabilities.length"
                        class="flex flex-col gap-1.5 rounded-[10px] border-[0.5px] border-edge-card bg-surface-card px-3.5 py-3"
                        :aria-label="$t('What it can do')"
                    >
                        <li
                            v-for="capability in provider.capabilities"
                            :key="capability.key"
                            class="flex items-start gap-2 text-[12.5px]/[18px]"
                        >
                            <FilledIcon
                                name="check"
                                :size="14"
                                class="mt-0.5 text-status-live"
                            />
                            <span class="text-fg-secondary">{{
                                capability.label
                            }}</span>
                        </li>
                    </ul>
                </div>

                <div
                    class="flex items-center gap-2 border-t-[0.5px] border-edge bg-surface-card-footer py-3 pr-4 pl-5"
                >
                    <span class="flex-1 text-[12px]/4 text-fg-muted">{{
                        $t('Kept encrypted. It’s never shown again.')
                    }}</span>
                    <DashButton :label="$t('Cancel')" @click="open = false" />
                    <DashButton
                        type="submit"
                        variant="primary"
                        :label="
                            form.processing ? $t('Checking…') : $t('Connect')
                        "
                        :loading="form.processing"
                    />
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
