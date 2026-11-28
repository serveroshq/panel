<script setup lang="ts">
import { Check, LoaderCircle } from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import type { CloudflareCheck } from '@/components/integrations/cloudflare';
import CloudflareDnsNote from '@/components/integrations/CloudflareDnsNote.vue';
import { SelectMenu } from '@/components/ui/select';
import type { SelectMenuOption } from '@/components/ui/select';
import {
    SolarCircleAlert as CircleAlert,
    SolarCircleCheck as CircleCheck,
    SolarCopy as Copy,
    SolarLockKeyhole as LockKeyhole,
} from '@/lib/solarIcons';
import { onWorkspace } from '@/lib/workspaceRoute';
import machinesRoutes from '@/routes/workspace/machines';
import { DOMAIN_PATTERN, PROXY_NAMES, bareDomain, isProxy } from './address';
import type { AddressMode, Proxy } from './address';
import {
    error as errorClass,
    field,
    hint,
    iconButton,
    label,
    select,
} from './styles';

const mode = defineModel<AddressMode>('mode', { required: true });
const domain = defineModel<string>('domain', { required: true });
const wildcard = defineModel<boolean>('wildcard', { required: true });
const proxy = defineModel<string>('proxy', { required: true });
const https = defineModel<boolean>('https', { required: true });

const {
    machineId,
    service,
    freeAddress = null,
    offersFree = false,
    machineProxy = null,
    canChooseProxy = false,
    canChooseHttps = false,
    canUseExternalProxy = false,
    takenDomains = [],
    errors = {},
} = defineProps<{
    machineId: number;
    service: string;
    /** Like 203-0-113-7.sslip.io: names under it point at the machine. */
    freeAddress?: string | null;
    /** The deploy gives the app a free address unless told not to. */
    offersFree?: boolean;
    machineProxy?: string | null;
    canChooseProxy?: boolean;
    canChooseHttps?: boolean;
    /** None: don't install or start a proxy. Needs a new enough daemon. */
    canUseExternalProxy?: boolean;
    /** Domains other apps on the machine serve. */
    takenDomains?: { domain: string; app: string }[];
    errors?: Record<string, string>;
}>();

const page = usePage<{ workspace: { slug: string } }>();

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

const freeName = computed(() =>
    freeAddress && service ? `${service}.${freeAddress}` : null,
);

const modes = computed(() => [
    { value: 'free' as AddressMode, label: trans('Free address') },
    { value: 'domain' as AddressMode, label: trans('Your domain') },
    { value: 'none' as AddressMode, label: trans('None') },
]);

const currentProxy = computed<Proxy>(() =>
    isProxy(machineProxy) ? machineProxy : 'caddy',
);
const chosenProxy = computed<Proxy>(() =>
    isProxy(proxy.value) ? proxy.value : currentProxy.value,
);
const proxyOptions = computed<SelectMenuOption<Proxy>[]>(() => [
    {
        value: 'caddy',
        label: 'Caddy',
        hint: trans('HTTPS on its own, wildcard subdomains too'),
    },
    {
        value: 'nginx',
        label: 'nginx',
        hint: trans('HTTPS from Let’s Encrypt, through certbot'),
    },
    {
        value: 'apache',
        label: 'Apache',
        hint: trans('HTTPS from Let’s Encrypt, through certbot'),
    },
    { value: 'traefik', label: 'Traefik', hint: trans('HTTPS on its own') },
    ...(canUseExternalProxy
        ? [
              {
                  value: 'none' as Proxy,
                  label: trans('None'),
                  hint: trans(
                      'A proxy you run yourself, or a Cloudflare Tunnel. ServerOS won’t install or start one.',
                  ),
              },
          ]
        : []),
]);

// Only the domain field when the app has no free address to offer.
const showsDomain = computed(() => !offersFree || mode.value === 'domain');

const owner = computed(() => {
    const wanted = bareDomain(domain.value);
    if (!wanted || !showsDomain.value) return null;

    return (
        takenDomains.find(
            (entry) => entry.domain === wanted && entry.app !== service,
        )?.app ?? null
    );
});

type DomainCheck = {
    domain: string;
    status: 'ready' | 'missing' | 'elsewhere' | 'proxied';
    expected: string | null;
    found: string[];
    record: { type: string; name: string; value: string } | null;
    /** The domain is in a connected Cloudflare account, which can point it here. */
    cloudflare?: CloudflareCheck | null;
    wildcard?: Omit<DomainCheck, 'wildcard'>;
};

const dns = ref<DomainCheck | null>(null);
const dnsChecking = ref(false);
let dnsTimer: ReturnType<typeof setTimeout> | undefined;
let dnsRequest = 0;

async function checkDomain(): Promise<void> {
    const wanted = bareDomain(domain.value);
    const requestId = ++dnsRequest;

    if (!showsDomain.value || !DOMAIN_PATTERN.test(wanted)) {
        dns.value = null;
        dnsChecking.value = false;

        return;
    }

    dnsChecking.value = true;

    try {
        const response = await fetch(
            onWorkspace(
                machinesRoutes.domainCheck({
                    workspace: page.props.workspace.slug,
                    machine: machineId,
                }),
            ).url,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': csrf(),
                },
                body: JSON.stringify({
                    domain: wanted,
                    wildcard: wildcard.value,
                }),
            },
        );

        if (requestId === dnsRequest) {
            dns.value = response.ok
                ? ((await response.json()) as DomainCheck)
                : null;
        }
    } catch {
        if (requestId === dnsRequest) dns.value = null;
    } finally {
        if (requestId === dnsRequest) dnsChecking.value = false;
    }
}

watch(
    () => [domain.value, wildcard.value, machineId, showsDomain.value],
    () => {
        clearTimeout(dnsTimer);
        dns.value = null;
        dnsTimer = setTimeout(checkDomain, 500);
    },
);

onBeforeUnmount(() => clearTimeout(dnsTimer));

const copied = ref<string | null>(null);

async function copy(key: string, value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
        setTimeout(() => {
            if (copied.value === key) copied.value = null;
        }, 1600);
    } catch {}
}

const domainInput = ref<HTMLInputElement | null>(null);

function chooseMode(next: AddressMode): void {
    mode.value = next;
    if (next === 'domain') setTimeout(() => domainInput.value?.focus(), 0);
}
</script>

<template>
    <div class="flex flex-col gap-1.5">
        <div class="flex flex-wrap items-center justify-between gap-2">
            <label
                :for="showsDomain ? 'deploy-domain' : undefined"
                :class="label"
            >
                {{ offersFree ? $t('Address') : $t('Domain') }}
                <span v-if="!offersFree" class="font-normal text-fg-faint">{{
                    ' ' + $t('(optional)')
                }}</span>
            </label>
            <FilterPills
                v-if="offersFree"
                :model-value="mode"
                :options="modes"
                :label="$t('Address')"
                @update:model-value="chooseMode"
            />
        </div>

        <template v-if="offersFree && mode === 'free'">
            <div
                class="dash-field bg-surface-field-readonly font-ui-mono text-[12.5px] text-fg-secondary"
            >
                <LockKeyhole
                    class="size-3.5 shrink-0 text-fg-muted"
                    :stroke-width="1.75"
                    aria-hidden="true"
                />
                <span class="min-w-0 truncate"
                    >https://{{ freeName ?? `…${freeAddress ?? ''}` }}</span
                >
            </div>
            <p :class="hint">
                {{
                    chosenProxy === 'none'
                        ? $t(
                              'The name points at this machine. ServerOS won’t serve it. Point your tunnel or proxy at the app’s port.',
                          )
                        : $t(
                              'Works as soon as it’s deployed, with HTTPS. Swap in your own domain any time.',
                          )
                }}
            </p>
        </template>

        <p v-else-if="offersFree && mode === 'none'" :class="hint">
            {{
                $t(
                    'It only listens on the machine itself. Give it an address any time from its page.',
                )
            }}
        </p>

        <template v-if="showsDomain">
            <div :class="field">
                <input
                    id="deploy-domain"
                    ref="domainInput"
                    v-model.trim="domain"
                    :placeholder="$t('app.example.com')"
                    autocomplete="off"
                    spellcheck="false"
                />
            </div>
            <p v-if="errors.domain" :class="errorClass">
                {{ errors.domain }}
            </p>
            <p v-else-if="owner" :class="errorClass">
                {{
                    $t(
                        ':domain is already :app’s domain on this machine. Give this one another, or move :app first.',
                        { domain: bareDomain(domain), app: owner },
                    )
                }}
            </p>
            <p v-if="!domain" class="flex items-start gap-1.5" :class="hint">
                <LockKeyhole
                    class="mt-px size-3.5 shrink-0"
                    :stroke-width="1.75"
                    aria-hidden="true"
                />
                <span>
                    {{
                        $t(
                            'Point a domain at this machine and it gets HTTPS automatically, with a certificate that renews itself.',
                        ) + ' '
                    }}
                    <button
                        v-if="!offersFree && freeName"
                        type="button"
                        class="cursor-pointer text-fg-secondary underline decoration-fg-faint underline-offset-2 hover:text-fg-primary"
                        @click="domain = freeName"
                    >
                        {{
                            $t('No domain yet? Use :address', {
                                address: freeName,
                            })
                        }}
                    </button>
                </span>
            </p>
            <p
                v-else-if="dnsChecking && !dns"
                class="flex items-center gap-1.5"
                :class="hint"
                aria-live="polite"
            >
                <LoaderCircle
                    class="size-3.5 animate-spin"
                    :stroke-width="1.75"
                    aria-hidden="true"
                />
                {{ $t('Checking DNS…') }}
            </p>
            <CloudflareDnsNote
                v-else-if="dns?.cloudflare"
                :cloudflare="dns.cloudflare"
                :wildcard-cloudflare="dns.wildcard?.cloudflare"
                :workspace-slug="page.props.workspace.slug"
                :machine-id="machineId"
                :service="service"
                :domain="dns.domain"
                :wildcard="wildcard"
                @pointed="(check) => (dns = check as DomainCheck)"
            />
            <div
                v-else-if="dns"
                class="flex flex-col gap-2.5 rounded-[10px] border-[0.5px] px-3.5 py-3 text-[12px]/4"
                :class="
                    dns.status === 'ready'
                        ? 'border-status-live/30 bg-status-live/8'
                        : 'border-status-pending/30 bg-status-pending/8'
                "
                aria-live="polite"
            >
                <p class="flex items-start gap-1.5 text-fg-secondary">
                    <CircleCheck
                        v-if="dns.status === 'ready'"
                        class="mt-px size-3.5 shrink-0 text-status-live"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                    <CircleAlert
                        v-else
                        class="mt-px size-3.5 shrink-0 text-status-pending"
                        :stroke-width="2"
                        aria-hidden="true"
                    />
                    <span v-if="dns.status === 'ready'">
                        <span class="font-ui-mono">{{ dns.domain }}</span>
                        {{
                            ' ' +
                            $t(
                                'points to this machine. HTTPS is set up as it deploys.',
                            )
                        }}
                    </span>
                    <span v-else-if="dns.status === 'missing'">
                        {{ $t('No DNS record for') + ' ' }}
                        <span class="font-ui-mono">{{ dns.domain }}</span>
                        {{
                            ' ' +
                            $t('yet. Add this one where you manage the domain:')
                        }}
                    </span>
                    <span v-else-if="dns.status === 'proxied'">
                        {{
                            $t(
                                'It’s behind Cloudflare’s proxy, which blocks the certificate. Set the record to DNS only (grey cloud):',
                            )
                        }}
                    </span>
                    <span v-else>
                        {{
                            $t(
                                'It points to :value, not this machine. Change the record to:',
                                { value: dns.found.join(', ') },
                            )
                        }}
                    </span>
                </p>
                <template v-if="dns.status !== 'ready' && dns.record">
                    <dl
                        class="grid grid-cols-[auto_1fr_auto] items-center gap-x-3 gap-y-1 rounded-[7px] border-[0.5px] border-edge-field bg-surface-field px-3 py-2 font-ui-mono text-[12px]/5"
                    >
                        <dt class="text-fg-faint">{{ $t('Type') }}</dt>
                        <dd class="col-span-2">{{ dns.record.type }}</dd>
                        <dt class="text-fg-faint">{{ $t('Name') }}</dt>
                        <dd class="col-span-2 truncate">
                            {{ dns.record.name }}
                        </dd>
                        <dt class="text-fg-faint">{{ $t('Value') }}</dt>
                        <dd class="truncate">{{ dns.record.value }}</dd>
                        <button
                            type="button"
                            :class="iconButton"
                            :aria-label="$t('Copy the IP address')"
                            @click="copy('dns', dns.record.value)"
                        >
                            <Check
                                v-if="copied === 'dns'"
                                class="mi-pop size-3.5 text-status-live"
                                :stroke-width="2.25"
                            />
                            <Copy
                                v-else
                                class="mi-copy size-3.5"
                                :stroke-width="1.75"
                            />
                        </button>
                    </dl>
                    <div class="flex items-center justify-between gap-3">
                        <span class="text-fg-muted">
                            {{
                                $t(
                                    'You can deploy now. HTTPS starts once the record points here, which can take a few minutes.',
                                )
                            }}
                        </span>
                        <DashButton
                            size="sm"
                            :loading="dnsChecking"
                            :label="
                                dnsChecking
                                    ? $t('Checking…')
                                    : $t('Check again')
                            "
                            @click="checkDomain"
                        />
                    </div>
                </template>
            </div>

            <div
                v-if="
                    canChooseProxy && (showsDomain ? !!domain : mode === 'free')
                "
                class="mt-1 flex flex-col gap-1.5"
            >
                <label for="deploy-proxy" :class="label">{{
                    $t('Reverse proxy')
                }}</label>
                <SelectMenu
                    id="deploy-proxy"
                    :model-value="chosenProxy"
                    :options="proxyOptions"
                    :class="select"
                    @update:model-value="(value) => (proxy = value)"
                />
                <p v-if="chosenProxy === 'none'" :class="hint">
                    {{
                        $t(
                            'ServerOS won’t install or start a reverse proxy, or stop the one already on this machine. Point your tunnel or proxy at the app’s port.',
                        )
                    }}
                </p>
                <p v-else-if="chosenProxy !== currentProxy" :class="hint">
                    {{
                        $t(
                            'This machine uses :from now. Deploying moves every site on it to :to and stops :from, since only one of them can use ports 80 and 443.',
                            {
                                from: PROXY_NAMES[currentProxy],
                                to: PROXY_NAMES[chosenProxy],
                            },
                        )
                    }}
                </p>
                <p v-if="errors.proxy" :class="errorClass">
                    {{ errors.proxy }}
                </p>
            </div>
            <p v-else-if="errors.proxy" :class="errorClass">
                {{ errors.proxy }}
            </p>
            <label
                v-if="domain && canChooseHttps && chosenProxy !== 'none'"
                class="mt-1 flex cursor-pointer items-start gap-2.5 text-[13px]/5 select-none"
            >
                <DashCheckbox
                    v-model="https"
                    class="mt-[3px]"
                    :label="$t('Serve over HTTPS')"
                />
                <span class="flex flex-col gap-0.5">
                    <span class="text-fg-primary">{{
                        $t('Serve over HTTPS')
                    }}</span>
                    <span :class="hint">{{
                        https
                            ? $t(
                                  ':proxy gets a certificate and sends HTTP to HTTPS, as soon as the domain points here.',
                                  { proxy: PROXY_NAMES[chosenProxy] },
                              )
                            : $t(
                                  'Plain HTTP, no certificate. For when a CDN or proxy in front does HTTPS.',
                              )
                    }}</span>
                </span>
            </label>
            <p v-if="errors.https" :class="errorClass">{{ errors.https }}</p>
            <label
                v-if="domain && chosenProxy !== 'none'"
                class="mt-1 flex cursor-pointer items-start gap-2.5 text-[13px]/5 select-none"
            >
                <DashCheckbox
                    v-model="wildcard"
                    class="mt-[3px]"
                    :label="$t('Allow wildcard subdomains')"
                />
                <span class="flex flex-col gap-0.5">
                    <span class="text-fg-primary">{{
                        $t('Allow wildcard subdomains')
                    }}</span>
                    <span :class="hint">{{
                        $t(
                            chosenProxy === 'caddy' && https
                                ? 'Every subdomain, like tenant.:domain, is served by this app and gets its own certificate the first time it’s visited. No DNS provider needed.'
                                : 'Every subdomain, like tenant.:domain, is served by this app over plain HTTP: a certificate for every subdomain needs Caddy.',
                            { domain: bareDomain(domain) || 'example.com' },
                        )
                    }}</span>
                </span>
            </label>
            <p v-if="errors.wildcard" :class="errorClass">
                {{ errors.wildcard }}
            </p>
            <p
                v-if="
                    wildcard &&
                    dns?.wildcard &&
                    !dns.wildcard.cloudflare &&
                    dns.wildcard.status !== 'ready' &&
                    dns.wildcard.record
                "
                class="text-[12px]/4 text-fg-muted"
                aria-live="polite"
            >
                {{
                    dns.wildcard.status === 'proxied'
                        ? $t(
                              'Subdomains are behind Cloudflare’s proxy, which blocks their certificates. Set it to DNS only:',
                          )
                        : $t('Subdomains need a record too:')
                }}
                <span class="font-ui-mono text-fg-primary"
                    >{{ dns.wildcard.record.type }}
                    {{ dns.wildcard.record.name }} →
                    {{ dns.wildcard.record.value }}</span
                >
            </p>
        </template>
        <p v-else-if="errors.domain" :class="errorClass">{{ errors.domain }}</p>
    </div>
</template>
