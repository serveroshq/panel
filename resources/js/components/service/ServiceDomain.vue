<script setup lang="ts">
import { CircleAlert } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onMounted, ref, watch } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import DashTray from '@/components/dash/DashTray.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type {
    CloudflareApp,
    CloudflareDomain,
} from '@/components/integrations/cloudflare';
import {
    answerError,
    managedHere,
    sendJson,
} from '@/components/integrations/cloudflare';
import type { ExistingSiteRow } from '@/components/machines/ExistingSitesTraffic.vue';
import type { ServiceApp } from '@/components/service/serviceTypes';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';
import { onWorkspace } from '@/lib/workspaceRoute';
import appsRoutes from '@/routes/workspace/apps';
import machinesRoutes from '@/routes/workspace/machines';

const props = defineProps<{
    app: ServiceApp | null;
    sites?: ExistingSiteRow[];
    serves?: boolean;
    ports: number[];
    workspaceSlug: string;
    machineId: number;
    canManage: boolean;
    online: boolean;
    cloudflare?: CloudflareApp | null;
}>();

const emit = defineEmits<{ change: []; site: []; cloudflare: [] }>();

type Check = { status: 'ready' | 'missing' | 'proxied' | 'elsewhere' };

const domain = computed(() => props.app?.domains[0] ?? null);
const wildcard = computed(
    () => !!domain.value && !!props.app?.domains.includes(`*.${domain.value}`),
);
const cloudflareDomains = computed(() =>
    (props.cloudflare?.domains ?? []).filter((entry) => entry.zone !== null),
);
const cloudflareMain = computed(
    () =>
        cloudflareDomains.value.find(
            (entry) => entry.hostname === domain.value,
        ) ?? null,
);
const port = computed(() => {
    const ports = props.app?.port ? [props.app.port] : props.ports;
    if (ports.length === 0) return null;

    return ports.length === 1
        ? trans('Port :port', { port: String(ports[0]) })
        : trans('Ports :ports', { ports: ports.slice(0, 3).join(', ') });
});
const dns = ref<Check['status'] | null>(null);

async function check(): Promise<void> {
    dns.value = null;
    if (!domain.value) return;
    const asked = domain.value;
    try {
        const response = await fetch(
            onWorkspace(
                machinesRoutes.domainCheck({
                    workspace: props.workspaceSlug,
                    machine: props.machineId,
                }),
            ).url,
            {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN':
                        document.querySelector<HTMLMetaElement>(
                            'meta[name="csrf-token"]',
                        )?.content ?? '',
                },
                body: JSON.stringify({ domain: asked, wildcard: false }),
            },
        );
        if (response.ok && asked === domain.value) {
            dns.value = ((await response.json()) as Check).status;
        }
    } catch {
        dns.value = null;
    }
}

onMounted(check);
watch(domain, check);

const notes = computed(() => {
    const site = props.app?.site;
    if (!site) return '';
    const parts: string[] = [];
    const viaCloudflare = managedHere(cloudflareMain.value?.record ?? null);
    if (!viaCloudflare) {
        if (dns.value === 'ready') parts.push(trans('Points at this machine.'));
        else if (dns.value === 'proxied')
            parts.push(trans('Behind Cloudflare’s proxy.'));
        else if (dns.value === 'missing' || dns.value === 'elsewhere')
            parts.push(trans('Doesn’t point at this machine yet.'));
    }
    if (site.wwwRedirect && site.wwwName) {
        parts.push(
            trans(':www redirects here.', { www: String(site.wwwName) }),
        );
    }
    if (wildcard.value) {
        parts.push(
            trans('Every subdomain too, *.:domain.', {
                domain: String(domain.value),
            }),
        );
    }
    if (site.maintenance) parts.push(trans('The maintenance page is up.'));
    if (site.password) parts.push(trans('Behind a password.'));
    if (site.redirects.length) {
        parts.push(
            site.redirects.length === 1
                ? trans('1 redirect.')
                : trans(':count redirects.', {
                      count: String(site.redirects.length),
                  }),
        );
    }
    const job = site.job?.state;
    if (job === 'queued' || job === 'running') {
        parts.push(trans('Applying the last site change…'));
    } else if (job === 'failed' || job === 'stale') {
        parts.push(
            trans('The last site change didn’t apply: :reason', {
                reason: site.job?.error || trans('the machine didn’t answer'),
            }),
        );
    }

    return parts.join(' ');
});

const found = computed(() =>
    props.app || props.serves ? [] : (props.sites ?? []),
);

const appArgs = computed(() => ({
    workspace: props.workspaceSlug,
    machine: props.machineId,
    service: props.app?.service ?? '',
}));
const cloudflareBusy = ref<string | null>(null);

function cloudflareLine(entry: CloudflareDomain): string {
    const record = entry.record;
    if (record && record.here && record.status === 'active') {
        return trans('DNS managed by Cloudflare · :type to :content', {
            type: record.type ?? '',
            content: record.content ?? '',
        });
    }
    if (record?.status === 'failed') return record.error ?? '';
    if (record && !record.here) {
        return trans('Cloudflare points it at :app on :machine', {
            app: record.app,
            machine: record.machine ?? '',
        });
    }

    return trans(
        'In your Cloudflare account :account, not pointed by ServerOS yet',
        {
            account: entry.account ?? '',
        },
    );
}

async function pointHere(entry: CloudflareDomain): Promise<void> {
    if (!props.app || cloudflareBusy.value) return;
    cloudflareBusy.value = entry.hostname;
    const wildcardOf = entry.hostname.startsWith('*.');
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
                domain: wildcardOf ? entry.hostname.slice(2) : entry.hostname,
                wildcard: wildcardOf,
                service: props.app.service,
            },
        );
        if (answer.ok) {
            toast.success(
                trans('Pointed :domain here.', { domain: entry.hostname }),
            );
        } else {
            toast.error(
                answerError(
                    answer,
                    trans('Cloudflare didn’t take the change.'),
                ),
            );
        }
    } catch {
        toast.error(trans('Cloudflare didn’t take the change.'));
    } finally {
        cloudflareBusy.value = null;
        emit('cloudflare');
    }
}

async function setProxied(
    entry: CloudflareDomain,
    proxied: boolean,
): Promise<void> {
    if (!entry.record || cloudflareBusy.value) return;
    const record = entry.record;
    cloudflareBusy.value = entry.hostname;
    record.proxied = proxied;
    try {
        const answer = await sendJson(
            'PATCH',
            onWorkspace(appsRoutes.dns(appArgs.value)).url,
            { hostname: entry.hostname, proxied },
        );
        if (!answer.ok) {
            record.proxied = !proxied;
            toast.error(
                answerError(
                    answer,
                    trans('Cloudflare didn’t take the change.'),
                ),
            );
        }
    } catch {
        record.proxied = !proxied;
        toast.error(trans('Cloudflare didn’t take the change.'));
    } finally {
        cloudflareBusy.value = null;
    }
}

const purging = ref(false);

async function purge(): Promise<void> {
    if (purging.value) return;
    purging.value = true;
    try {
        const answer = await sendJson<{ hosts: string[] }>(
            'POST',
            onWorkspace(appsRoutes.purge(appArgs.value)).url,
        );
        if (answer.ok) {
            toast.success(
                answer.data.hosts.length === 1
                    ? trans('Purged Cloudflare’s cache for :host.', {
                          host: answer.data.hosts[0],
                      })
                    : trans('Purged Cloudflare’s cache for :count hostnames.', {
                          count: String(answer.data.hosts.length),
                      }),
            );
        } else {
            toast.error(
                answerError(
                    answer,
                    trans('Cloudflare didn’t purge the cache.'),
                ),
            );
        }
    } catch {
        toast.error(trans('Cloudflare didn’t purge the cache.'));
    } finally {
        purging.value = false;
        emit('cloudflare');
    }
}

const purgeAfterDeploy = ref(props.cloudflare?.purgeAfterDeploy ?? false);
watch(
    () => props.cloudflare?.purgeAfterDeploy,
    (value) => (purgeAfterDeploy.value = value ?? false),
);

async function setPurgeAfterDeploy(on: boolean): Promise<void> {
    purgeAfterDeploy.value = on;
    try {
        const answer = await sendJson(
            'PUT',
            onWorkspace(appsRoutes.purge.setting(appArgs.value)).url,
            { on },
        );
        if (!answer.ok) {
            purgeAfterDeploy.value = !on;
            toast.error(answerError(answer, trans('Couldn’t save that.')));
        }
    } catch {
        purgeAfterDeploy.value = !on;
        toast.error(trans('Couldn’t save that.'));
    }
}

const lastPurgeLine = computed(() => {
    const last = props.cloudflare?.lastPurge;
    if (!last) return trans('Purges this app’s hostnames once a deploy works.');
    if (last.error) {
        return trans('The last purge didn’t work: :reason', {
            reason: last.error,
        });
    }

    return last.auto
        ? trans('Last purged after a deploy, :when.', {
              when: timeAgo(last.at),
          })
        : trans('Last purged by hand, :when.', { when: timeAgo(last.at) });
});
</script>

<template>
    <DashTray icon="globe-fill" :title="$t('Domain')" class="shrink-0">
        <template v-if="app && canManage" #actions>
            <DashButton
                v-if="cloudflare?.canPurge"
                size="sm"
                :label="$t('Purge cache')"
                :loading="purging"
                @click="purge"
            />
            <DashButton
                size="sm"
                :label="domain ? $t('Change domain') : $t('Add domain')"
                :disabled="!app.canMove || !online"
                :title="
                    app.canMove
                        ? undefined
                        : $t(
                              'Upload the code again as a new version to change this.',
                          )
                "
                @click="$emit('change')"
            />
        </template>

        <div class="flex flex-col gap-3 px-[18px] pt-3 pb-3.5">
            <div v-if="app && domain" class="flex items-center gap-2.5">
                <FilledIcon name="globe-fill" class="shrink-0 text-fg-muted" />
                <div class="flex min-w-0 flex-1 flex-col">
                    <span class="flex min-w-0 items-center gap-2">
                        <a
                            :href="`${app.https ? 'https' : 'http'}://${domain}`"
                            target="_blank"
                            rel="noopener"
                            class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary hover:underline hover:underline-offset-4"
                            >{{ domain }}</a
                        >
                        <span
                            v-if="app.https"
                            class="flex h-[18px] shrink-0 items-center gap-1 rounded-[9px] bg-status-live/14 pr-[7px] pl-1.5 text-[11px]/3.5 font-medium text-status-live"
                        >
                            <FilledIcon name="lock-fill" :size="10" />
                            {{ $t('HTTPS') }}
                        </span>
                    </span>
                    <button
                        v-if="canManage && notes"
                        type="button"
                        class="cursor-pointer truncate text-left text-[12px]/4 tracking-[-0.005em] text-fg-muted hover:text-fg-tertiary"
                        :title="$t('Site settings')"
                        @click="$emit('site')"
                    >
                        {{ notes }}
                    </button>
                    <span
                        v-else-if="notes"
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        >{{ notes }}</span
                    >
                </div>
                <span
                    v-if="port"
                    class="shrink-0 font-ui-mono text-[12px]/4 text-fg-muted"
                    >{{ port }}</span
                >
            </div>

            <div
                v-for="entry in cloudflareDomains"
                :key="'cf-' + entry.hostname"
                class="-mt-1 flex items-center gap-2.5 pl-[26px]"
            >
                <CircleAlert
                    v-if="entry.record?.status === 'failed'"
                    class="size-3.5 shrink-0 text-status-pending"
                    :stroke-width="2"
                    aria-hidden="true"
                />
                <BrandLogo v-else name="cloudflare" :size="14" />
                <div class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate text-[12px]/4 tracking-[-0.005em]"
                        :class="
                            entry.record?.status === 'failed'
                                ? 'text-status-pending'
                                : 'text-fg-tertiary'
                        "
                        :title="cloudflareLine(entry)"
                        >{{
                            entry.hostname === domain
                                ? cloudflareLine(entry)
                                : `${entry.hostname} · ${cloudflareLine(entry)}`
                        }}</span
                    >
                    <span
                        v-if="
                            entry.record?.warning && managedHere(entry.record)
                        "
                        class="truncate text-[12px]/4 text-fg-muted"
                        :title="entry.record.warning"
                        >{{ entry.record.warning }}</span
                    >
                </div>
                <template v-if="canManage && app">
                    <label
                        v-if="managedHere(entry.record)"
                        class="flex shrink-0 items-center gap-2 text-[12px]/4 text-fg-muted"
                        :title="
                            entry.record?.forced
                                ? $t(
                                      'Records for a Cloudflare Tunnel have to be proxied.',
                                  )
                                : $t(
                                      'Proxied hides this machine’s address, but the machine can’t get its own HTTPS certificate through it. Leave it off unless Cloudflare does HTTPS for you.',
                                  )
                        "
                    >
                        {{ $t('Proxied') }}
                        <DashSwitch
                            size="sm"
                            :model-value="entry.record?.proxied ?? false"
                            :disabled="
                                entry.record?.forced ||
                                cloudflareBusy === entry.hostname
                            "
                            :label="
                                $t('Proxy :domain through Cloudflare', {
                                    domain: entry.hostname,
                                })
                            "
                            @update:model-value="
                                (on: boolean) => setProxied(entry, on)
                            "
                        />
                    </label>
                    <DashButton
                        v-else-if="cloudflare?.canPoint"
                        size="sm"
                        :label="$t('Point it here')"
                        :loading="cloudflareBusy === entry.hostname"
                        @click="pointHere(entry)"
                    />
                </template>
            </div>

            <div
                v-if="app && canManage && cloudflare?.canPurge"
                class="flex items-center gap-2.5 border-t-[0.5px] border-edge-tray-panel pt-3"
            >
                <FilledIcon name="update-fill" class="shrink-0 text-fg-muted" />
                <div class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                        >{{
                            $t('Purge Cloudflare’s cache after each deploy')
                        }}</span
                    >
                    <span
                        class="truncate text-[12px]/4 tracking-[-0.005em]"
                        :class="
                            cloudflare?.lastPurge?.error
                                ? 'text-status-pending'
                                : 'text-fg-muted'
                        "
                        :title="lastPurgeLine"
                        >{{ lastPurgeLine }}</span
                    >
                </div>
                <DashSwitch
                    size="sm"
                    :model-value="purgeAfterDeploy"
                    :label="$t('Purge Cloudflare’s cache after each deploy')"
                    @update:model-value="setPurgeAfterDeploy"
                />
            </div>

            <div
                v-for="(site, index) in found"
                :key="site.label"
                class="flex items-center gap-2.5"
            >
                <FilledIcon name="globe-fill" class="shrink-0 text-fg-muted" />
                <div class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-primary"
                        >{{ site.label }}</span
                    >
                    <span
                        v-if="site.where"
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                        >{{ site.where }}</span
                    >
                </div>
                <span
                    v-if="index === 0 && port"
                    class="shrink-0 font-ui-mono text-[12px]/4 text-fg-muted"
                    >{{ port }}</span
                >
            </div>

            <div
                v-if="!domain && found.length === 0"
                class="flex items-center gap-2.5"
            >
                <FilledIcon name="globe-fill" class="shrink-0 text-fg-faint" />
                <div class="flex min-w-0 flex-1 flex-col">
                    <span
                        class="truncate text-[13px]/5 tracking-[-0.005em] text-fg-muted"
                        >{{
                            app
                                ? $t('No domain yet')
                                : serves
                                  ? $t('It’s the web server here')
                                  : $t('No domain found')
                        }}</span
                    >
                    <span
                        class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-faint"
                        >{{
                            app
                                ? $t(
                                      'It answers on its port on the machine until you add one.',
                                  )
                                : serves
                                  ? $t(
                                        'The sites it serves are on the Traffic tab.',
                                    )
                                  : $t(
                                        'No web server on this machine sends a domain to it.',
                                    )
                        }}</span
                    >
                </div>
                <span
                    v-if="port"
                    class="shrink-0 font-ui-mono text-[12px]/4 text-fg-muted"
                    >{{ port }}</span
                >
            </div>
        </div>
    </DashTray>
</template>
