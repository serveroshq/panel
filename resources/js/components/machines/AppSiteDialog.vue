<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { ArrowRight, Plus, X } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { SelectMenu } from '@/components/ui/select';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import { appButtonClasses } from '@/lib/appButton';
import { toast } from '@/lib/toast';
import { describe, networkMessage } from '@/lib/errors';

export type SiteSwitches = {
    maintenance: boolean;
    customPage: boolean;
    password: boolean;
    user: string | null;
    wwwRedirect: boolean;
    wwwName: string | null;
    domain: string | null;
    redirects: { from: string; to: string; permanent: boolean }[];
    supported: boolean;
    job: { id: string; state: string; error: string | null } | null;
};

const props = defineProps<{
    machineId: number;
    app: string;
    site: SiteSwitches;
}>();
const open = defineModel<boolean>('open', { required: true });
const emit = defineEmits<{ saved: [] }>();

const maintenance = ref(false);
const ownPage = ref(false);
const page = ref('');
const protect = ref(false);
const username = ref('');
const password = ref('');
const www = ref(false);
const redirects = ref<{ from: string; to: string; permanent: boolean }[]>([]);
const busy = ref(false);
const error = ref<string | null>(null);

const statusOptions = [
    { value: '301', label: trans('Permanent') },
    { value: '302', label: trans('Temporary') },
];

watch(
    open,
    (now) => {
        if (!now) return;
        maintenance.value = props.site.maintenance;
        ownPage.value = props.site.customPage;
        page.value = '';
        protect.value = props.site.password;
        username.value = props.site.user ?? 'admin';
        password.value = '';
        www.value = props.site.wwwRedirect;
        redirects.value = props.site.redirects.map((r) => ({ ...r }));
        error.value = null;
    },
    { immediate: true },
);

const needsPassword = computed(
    () => protect.value && !props.site.password && password.value.length < 8,
);
const filledRedirects = computed(() =>
    redirects.value
        .filter((r) => r.from.trim() || r.to.trim())
        .map((r) => ({
            from: r.from.trim(),
            to: r.to.trim(),
            permanent: r.permanent,
        })),
);
const dirty = computed(
    () =>
        maintenance.value !== props.site.maintenance ||
        ownPage.value !== props.site.customPage ||
        page.value.trim() !== '' ||
        protect.value !== props.site.password ||
        (protect.value &&
            (username.value.trim() || 'admin') !==
                (props.site.user ?? 'admin')) ||
        password.value !== '' ||
        www.value !== props.site.wwwRedirect ||
        JSON.stringify(filledRedirects.value) !==
            JSON.stringify(props.site.redirects),
);

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function addRedirect(): void {
    redirects.value.push({ from: '', to: '', permanent: true });
}

function body(): Record<string, unknown> {
    const out: Record<string, unknown> = {
        maintenance: maintenance.value,
        password_protect: protect.value,
        www_redirect: www.value,
        redirects: filledRedirects.value,
    };
    if (!ownPage.value && props.site.customPage) out.maintenance_page = '';
    if (ownPage.value && page.value.trim()) out.maintenance_page = page.value;
    if (protect.value) {
        out.username = username.value.trim() || 'admin';
        if (password.value) out.password = password.value;
    }

    return out;
}

async function save(): Promise<void> {
    busy.value = true;
    error.value = null;
    const response = await fetch(
        `/machines/${props.machineId}/apps/${encodeURIComponent(props.app)}/site`,
        {
            method: 'PUT',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': csrf(),
            },
            body: JSON.stringify(body()),
        },
    ).catch(() => null);
    busy.value = false;
    const json = (await response?.json().catch(() => null)) as {
        message?: string;
        error?: string;
        errors?: Record<string, string[]>;
    } | null;
    if (!response?.ok) {
        error.value = response
            ? (json?.error ?? describe(response.status, json))
            : networkMessage();

        return;
    }
    open.value = false;
    toast.success(trans('Changing the site. It takes a few seconds.'));
    emit('saved');
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent
            class="workspace-panel ds-modal gap-0 p-0 font-ui text-foreground sm:max-w-2xl"
        >
            <form class="flex max-h-[85dvh] flex-col" @submit.prevent="save">
                <div
                    class="flex flex-col gap-1 border-b border-(--ds-divider) px-5 pt-5 pb-4"
                >
                    <DialogTitle class="text-base/6 font-semibold">{{
                        $t('Site settings')
                    }}</DialogTitle>
                    <DialogDescription
                        class="text-[13px]/5 text-pretty text-(--ds-text-muted)"
                    >
                        {{
                            ' ' +
                            $t(
                                'Changes apply in a few seconds, without a redeploy.',
                            ) +
                            ' '
                        }}
                    </DialogDescription>
                </div>

                <div
                    class="flex min-h-0 flex-1 flex-col divide-y divide-(--ds-divider) overflow-y-auto"
                >
                    <section class="flex flex-col gap-3 px-5 py-4">
                        <div class="flex items-start gap-4">
                            <div class="flex min-w-0 grow flex-col gap-0.5">
                                <span class="text-[13px] font-medium">{{
                                    $t('Maintenance page')
                                }}</span>
                                <span
                                    class="text-xs/4 text-(--ds-text-faint)"
                                    >{{
                                        $t(
                                            'Visitors see a “back soon” page while the app keeps running.',
                                        )
                                    }}</span
                                >
                            </div>
                            <ToggleSwitch
                                v-model="maintenance"
                                class="mt-0.5"
                                :label="$t('Maintenance page')"
                            />
                        </div>
                        <div
                            v-if="maintenance"
                            class="flex flex-col gap-2 rounded-[8px] bg-(--ds-active) p-3"
                        >
                            <div class="flex items-center gap-3">
                                <span
                                    class="grow text-xs text-(--ds-text-muted)"
                                    >{{
                                        ownPage
                                            ? $t('Your own page')
                                            : $t(
                                                  'A plain page with the site’s name',
                                              )
                                    }}</span
                                >
                                <button
                                    type="button"
                                    :class="appButtonClasses('quiet', 'xs')"
                                    @click="ownPage = !ownPage"
                                >
                                    {{
                                        ownPage
                                            ? $t('Use the plain page')
                                            : $t('Use my own HTML')
                                    }}
                                </button>
                            </div>
                            <textarea
                                v-if="ownPage"
                                v-model="page"
                                rows="5"
                                spellcheck="false"
                                :aria-label="$t('Maintenance page HTML')"
                                :placeholder="
                                    site.customPage
                                        ? $t(
                                              'Your page is saved. Paste new HTML to replace it.',
                                          )
                                        : '<h1>Back soon</h1>'
                                "
                                class="rounded-md border border-input bg-(--ds-card) px-3 py-2 font-ui-mono text-xs/5 text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                        </div>
                    </section>

                    <section class="flex flex-col gap-3 px-5 py-4">
                        <div class="flex items-start gap-4">
                            <div class="flex min-w-0 grow flex-col gap-0.5">
                                <span class="text-[13px] font-medium">{{
                                    $t('Password')
                                }}</span>
                                <span
                                    class="text-xs/4 text-(--ds-text-faint)"
                                    >{{
                                        $t(
                                            'Ask for a username and password before showing the site.',
                                        )
                                    }}</span
                                >
                            </div>
                            <ToggleSwitch
                                v-model="protect"
                                class="mt-0.5"
                                :label="$t('Password')"
                            />
                        </div>
                        <div
                            v-if="protect"
                            class="grid gap-3 rounded-[8px] bg-(--ds-active) p-3 sm:grid-cols-2"
                        >
                            <label class="flex flex-col gap-1.5">
                                <span
                                    class="text-xs font-medium text-(--ds-text-muted)"
                                    >{{ $t('Username') }}</span
                                >
                                <Input
                                    v-model="username"
                                    class="h-8 bg-(--ds-card) text-[13px]"
                                    autocomplete="off"
                                    spellcheck="false"
                                />
                            </label>
                            <label class="flex flex-col gap-1.5">
                                <span
                                    class="text-xs font-medium text-(--ds-text-muted)"
                                    >{{
                                        site.password
                                            ? $t('New password')
                                            : $t('Password')
                                    }}</span
                                >
                                <Input
                                    v-model="password"
                                    type="password"
                                    class="h-8 bg-(--ds-card) text-[13px]"
                                    autocomplete="new-password"
                                />
                                <span
                                    class="text-xs/4 text-(--ds-text-faint)"
                                    >{{
                                        site.password
                                            ? $t(
                                                  'Leave empty to keep the current one.',
                                              )
                                            : $t('At least 8 characters.')
                                    }}</span
                                >
                            </label>
                        </div>
                    </section>

                    <section
                        v-if="site.wwwName && site.domain"
                        class="flex items-start gap-4 px-5 py-4"
                    >
                        <div class="flex min-w-0 grow flex-col gap-0.5">
                            <span class="text-[13px] font-medium">{{
                                $t('www redirect')
                            }}</span>
                            <span class="text-xs/4 text-(--ds-text-faint)">
                                {{ ' ' + $t('Send') + ' ' }}
                                <span
                                    class="font-ui-mono text-(--ds-text-muted)"
                                    >{{ site.wwwName }}</span
                                >
                                {{ ' ' + $t('to') + ' ' }}
                                <span
                                    class="font-ui-mono text-(--ds-text-muted)"
                                    >{{ site.domain }}</span
                                >.
                                {{
                                    ' ' +
                                    $t('Point its DNS at this machine too.')
                                }}
                            </span>
                        </div>
                        <ToggleSwitch
                            v-model="www"
                            class="mt-0.5"
                            :label="$t('www redirect')"
                        />
                    </section>

                    <section class="flex flex-col gap-3 px-5 py-4">
                        <div class="flex items-start gap-4">
                            <div class="flex min-w-0 grow flex-col gap-0.5">
                                <span class="text-[13px] font-medium">{{
                                    $t('Redirects')
                                }}</span>
                                <span
                                    class="text-xs/4 text-(--ds-text-faint)"
                                    >{{
                                        $t(
                                            'Send an exact path to another path or a full address.',
                                        )
                                    }}</span
                                >
                            </div>
                            <button
                                type="button"
                                class="shrink-0"
                                :class="appButtonClasses('secondary', 'sm')"
                                @click="addRedirect"
                            >
                                <Plus class="size-3.5" />
                                {{ $t('Add') }}
                            </button>
                        </div>
                        <div
                            v-if="redirects.length"
                            class="overflow-hidden rounded-[8px] border border-(--ds-divider)"
                        >
                            <div
                                class="hidden grid-cols-[minmax(0,1fr)_16px_minmax(0,1.4fr)_8.5rem_28px] items-center gap-2 border-b border-(--ds-divider) bg-(--ds-active) px-3 py-1.5 text-xs font-medium text-(--ds-text-muted) sm:grid"
                            >
                                <span>{{ $t('From') }}</span>
                                <span />
                                <span>{{ $t('To') }}</span>
                                <span>{{ $t('Type') }}</span>
                                <span />
                            </div>
                            <ul class="divide-y divide-(--ds-divider)">
                                <li
                                    v-for="(redirect, index) in redirects"
                                    :key="index"
                                    class="grid grid-cols-[minmax(0,1fr)_16px_minmax(0,1.4fr)] items-center gap-2 px-3 py-2 sm:grid-cols-[minmax(0,1fr)_16px_minmax(0,1.4fr)_8.5rem_28px]"
                                >
                                    <Input
                                        v-model="redirect.from"
                                        class="h-8 font-ui-mono text-xs"
                                        placeholder="/old-page"
                                        :aria-label="$t('From')"
                                        spellcheck="false"
                                    />
                                    <ArrowRight
                                        class="size-3.5 text-(--ds-text-faint)"
                                        aria-hidden="true"
                                    />
                                    <Input
                                        v-model="redirect.to"
                                        class="h-8 font-ui-mono text-xs"
                                        placeholder="/new-page"
                                        :aria-label="$t('To')"
                                        :title="redirect.to"
                                        spellcheck="false"
                                    />
                                    <SelectMenu
                                        :model-value="
                                            redirect.permanent ? '301' : '302'
                                        "
                                        class="ds-raised-button col-span-2 h-8 w-full text-xs sm:col-span-1"
                                        :options="statusOptions"
                                        @update:model-value="
                                            (value) =>
                                                (redirect.permanent =
                                                    value === '301')
                                        "
                                    />
                                    <button
                                        type="button"
                                        class="justify-self-end"
                                        :class="appButtonClasses('quiet', 'xs')"
                                        :aria-label="$t('Remove this redirect')"
                                        @click="redirects.splice(index, 1)"
                                    >
                                        <X class="size-3.5" />
                                    </button>
                                </li>
                            </ul>
                        </div>
                    </section>
                </div>

                <div
                    class="flex items-center justify-end gap-2 border-t border-(--ds-divider) px-5 py-4"
                >
                    <p
                        v-if="error"
                        class="mr-auto text-[13px] text-(--ds-red)"
                        role="alert"
                    >
                        {{ error }}
                    </p>
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
                        :disabled="busy || needsPassword || !dirty"
                    >
                        {{ $t('Save') }}
                    </button>
                </div>
            </form>
        </DialogContent>
    </Dialog>
</template>
