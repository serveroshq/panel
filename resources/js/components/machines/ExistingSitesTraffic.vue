<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, ref } from 'vue';
import SiteTraffic from '@/components/machines/SiteTraffic.vue';
import type { SiteTrafficState } from '@/components/machines/SiteTraffic.vue';
import TrafficFileDialog from '@/components/machines/TrafficFileDialog.vue';
import { appButtonClasses } from '@/lib/appButton';
import { fixed } from '@/lib/formatting';

export type ExistingSiteRow = SiteTrafficState & {
    label: string;
    where?: string;
    lastDay: {
        requests: number;
        errors: number;
        clientErrors: number;
        visitors: number;
    };
};

export type ExistingSitesTrafficProps = {
    /** The web server, on a web server's own page. */
    server: string | null;
    /** server: a web server's own sites; app: the sites in front of an app. */
    role?: 'server' | 'app';
    collecting: boolean;
    blocked: string | null;
    update?: string | null;
    canAddFile?: boolean;
    ports?: number[];
    sites: ExistingSiteRow[];
};

const props = defineProps<{
    machineId: number;
    machineName: string;
    serviceUrl: string;
    serviceName?: string;
    traffic: ExistingSitesTrafficProps;
    canManage: boolean;
}>();

const page = usePage();
const role = computed(() => props.traffic.role ?? 'server');
const selectedKey = computed(() => {
    const query = page.url.split('?')[1] ?? '';

    return new URLSearchParams(query).get('site');
});
// An app with one site in front of it shows that site, with no list.
const only = computed(() =>
    role.value === 'app' && props.traffic.sites.length === 1
        ? props.traffic.sites[0]
        : null,
);
const selected = computed(
    () =>
        props.traffic.sites.find((s) => s.site === selectedKey.value) ??
        only.value,
);
const listUrl = computed(() => `${props.serviceUrl}/traffic`);
const server = computed(() => props.traffic.server ?? trans('this service'));
const adding = ref(false);

function siteUrl(site: string): string {
    return `${listUrl.value}?site=${encodeURIComponent(site)}`;
}

function open(site: string): void {
    router.visit(siteUrl(site), { preserveScroll: true, preserveState: true });
}

function compact(value: number): string {
    if (value >= 1e9) return `${fixed(value / 1e9, 1)}B`;
    if (value >= 1e6) return `${fixed(value / 1e6, 1)}M`;
    if (value >= 1e4) return `${Math.round(value / 1e3)}k`;
    if (value >= 1e3) return `${fixed(value / 1e3, 1)}k`;

    return String(Math.round(value));
}

function rate(part: number, whole: number): string {
    if (whole === 0) return '—';
    const p = (part / whole) * 100;
    if (p === 0) return '0%';
    if (p < 0.1) return '<0.1%';

    return `${p < 10 ? fixed(p, 1) : Math.round(p)}%`;
}

/** The site's own log, not the copy ServerOS may have added. */
function ownLog(row: ExistingSiteRow): string {
    const site = row.existing;
    if (!site) return '—';
    if (site.unlogged) return '';
    const own =
        site.logs.find(
            (log) => !log.startsWith('/var/log/serveros-traffic/'),
        ) ?? site.logs[0];

    return own ?? '—';
}

/** Its log is the container's output, not a file. */
function fromOutput(row: ExistingSiteRow): boolean {
    const logs = row.existing?.logs ?? [];

    return (
        logs.length > 0 &&
        logs.every((log) => log === '/dev/stdout' || log === '/dev/stderr')
    );
}

/** What a site's second line says: its other names, or where it's set up. */
function detail(row: ExistingSiteRow): string {
    const site = row.existing;
    if (!site) return '';
    const parts: string[] = [];
    if (role.value === 'app' && row.where) parts.push(row.where);
    if (site.shared) {
        parts.push(site.names.join(', '));
    } else if (site.catchAll) {
        parts.push(trans('Every host no other site answers'));
    } else if (site.names.length > 1) {
        parts.push(site.names.slice(1).join(', '));
    } else if (site.apps?.length) {
        parts.push(trans('Sends to :apps', { apps: site.apps.join(', ') }));
    } else if (parts.length === 0 && site.configs[0]?.startsWith('/')) {
        // Where it's set up, without the server's own folder.
        parts.push(site.configs[0].split('/').slice(-2).join('/'));
    }

    return parts.join(' · ');
}

const counted = computed(() =>
    props.traffic.sites.filter((s) => !s.existing?.unlogged),
);
const unloggedCount = computed(
    () => props.traffic.sites.length - counted.value.length,
);
const title = computed(() =>
    role.value === 'app'
        ? trans('Sites that send requests to it')
        : trans('Sites in :server’s config', { server: server.value }),
);

function reload(): void {
    router.reload({ only: ['siteTraffic'] });
}

const settingsUrl = computed(() => `/machines/${props.machineId}/settings`);
</script>

<template>
    <div v-if="selected" class="flex flex-col gap-4">
        <Link
            v-if="!only"
            :href="listUrl"
            preserve-scroll
            class="flex w-fit items-center gap-1.5 text-[13px]/5 text-(--ds-text-muted) hover:text-(--ds-text)"
        >
            <ArrowLeft class="size-3.5" aria-hidden="true" />
            {{
                role === 'app'
                    ? $t('All sites')
                    : $t('All :server sites', { server })
            }}
        </Link>
        <SiteTraffic
            :key="selected.site"
            :machine-id="machineId"
            :machine-name="machineName"
            :app="selected.site"
            :state="selected"
            :can-manage="canManage"
            @changed="reload"
        />
    </div>

    <div v-else class="flex flex-col gap-5">
        <div class="flex min-h-8 flex-wrap items-center justify-between gap-3">
            <div class="flex min-w-0 items-baseline gap-2">
                <h2 class="text-[15px] font-medium">{{ $t('Traffic') }}</h2>
                <span
                    v-if="traffic.sites.length"
                    class="truncate text-[13px] text-(--ds-text-faint)"
                    >{{ title }}</span
                >
            </div>
            <span
                v-if="traffic.collecting && counted.length"
                class="text-xs text-(--ds-text-faint)"
                >{{ $t('Last 24 hours') }}</span
            >
        </div>

        <div
            v-if="traffic.blocked"
            class="ds-tile flex flex-col items-center gap-1 px-5 py-10 text-center text-[13px]/5"
        >
            <p class="font-medium">{{ $t('Traffic isn’t available here') }}</p>
            <p class="max-w-md text-(--ds-text-muted)">
                {{ traffic.blocked }}
            </p>
        </div>

        <div
            v-else-if="traffic.update"
            class="ds-tile flex flex-col items-center gap-1 px-5 py-10 text-center text-[13px]/5"
        >
            <p class="font-medium">{{ $t('Update the daemon to see this') }}</p>
            <p class="max-w-md text-(--ds-text-muted)">{{ traffic.update }}</p>
        </div>

        <div
            v-else-if="!traffic.collecting"
            class="ds-tile flex flex-col items-center gap-1 px-5 py-10 text-center text-[13px]/5"
        >
            <p class="font-medium">
                {{ $t(':name doesn’t count traffic', { name: machineName }) }}
            </p>
            <p class="max-w-md text-(--ds-text-muted)">
                {{
                    role === 'app'
                        ? $t(
                              'Turn on Traffic in the machine’s settings to count visits from the access logs your web servers already write. ServerOS only reads them, and visitor IPs never leave the machine.',
                          )
                        : $t(
                              'Turn on Traffic in the machine’s settings to count visits to :server’s sites from the access logs it already writes. ServerOS only reads them, and visitor IPs never leave the machine.',
                              { server },
                          )
                }}
            </p>
            <Link
                v-if="canManage"
                :href="settingsUrl"
                class="mt-2 font-medium text-(--ds-text) underline-offset-4 hover:underline"
                >{{ $t('Open machine settings') }}</Link
            >
        </div>

        <div
            v-else-if="traffic.sites.length === 0 && role === 'app'"
            class="ds-tile flex flex-col items-center gap-1 px-5 py-10 text-center text-[13px]/5"
        >
            <p class="font-medium">{{ $t('No web server in front of it') }}</p>
            <p class="max-w-lg text-(--ds-text-muted)">
                {{
                    traffic.ports?.length
                        ? $t(
                              'ServerOS counts visits from the web server in front of an app, and none on :name sends requests to it. It may answer on port :port itself, or behind a load balancer elsewhere, where HTTPS hides each request from the machine.',
                              {
                                  name: machineName,
                                  port: String(traffic.ports[0]),
                              },
                          )
                        : $t(
                              'ServerOS counts visits from the web server in front of an app, and none on :name sends requests to it.',
                              { name: machineName },
                          )
                }}
            </p>
            <p class="max-w-lg text-(--ds-text-muted)">
                {{
                    $t(
                        'To see its visits, put the machine’s proxy in front of it with a domain, or count the access log it writes itself.',
                    )
                }}
            </p>
            <button
                v-if="canManage && traffic.canAddFile"
                type="button"
                :class="appButtonClasses('secondary', 'md', 'mt-2')"
                @click="adding = true"
            >
                {{ $t('Count its own log file') }}
            </button>
        </div>

        <div
            v-else-if="traffic.sites.length === 0"
            class="ds-tile flex flex-col items-center gap-1 px-5 py-10 text-center text-[13px]/5"
        >
            <p class="font-medium">{{ $t('No sites to count') }}</p>
            <p class="max-w-md text-(--ds-text-muted)">
                {{
                    $t(
                        'ServerOS found no site in :server’s config. Sites show up here within a minute of being added.',
                        { server },
                    )
                }}
            </p>
        </div>

        <section
            v-else
            class="ds-tile min-w-0 overflow-hidden"
            :aria-label="$t('Sites')"
        >
            <table
                class="w-full table-fixed border-collapse text-left text-[13px]/5"
            >
                <thead>
                    <tr class="text-[12px]/4 text-(--ds-text-faint)">
                        <th class="py-2.5 pr-3 pl-4 font-normal">
                            {{ $t('Site') }}
                        </th>
                        <th
                            class="hidden w-[28%] border-l border-(--ds-divider) px-3 font-normal md:table-cell"
                        >
                            {{ $t('Access log') }}
                        </th>
                        <th
                            class="w-20 border-l border-(--ds-divider) px-3 text-right font-normal sm:w-24"
                        >
                            {{ $t('Requests') }}
                        </th>
                        <th
                            class="hidden w-24 border-l border-(--ds-divider) px-3 text-right font-normal sm:table-cell"
                        >
                            {{ $t('Errors') }}
                        </th>
                        <th
                            class="w-20 border-l border-(--ds-divider) px-3 text-right font-normal max-sm:pr-4 sm:w-24"
                        >
                            {{ $t('Visitors') }}
                        </th>
                        <th
                            class="hidden w-36 border-l border-(--ds-divider) pr-4 pl-3 font-normal lg:table-cell"
                        >
                            {{ $t('Response times') }}
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in traffic.sites"
                        :key="row.site"
                        class="cursor-pointer border-t border-(--ds-divider) hover:bg-(--ds-hover)"
                        @click="open(row.site)"
                    >
                        <td class="min-w-0 py-2.5 pr-3 pl-4">
                            <Link
                                :href="siteUrl(row.site)"
                                preserve-scroll
                                preserve-state
                                class="block truncate font-medium text-(--ds-text)"
                                :title="row.label"
                                @click.stop
                                >{{ row.label }}</Link
                            >
                            <span
                                class="block truncate text-xs text-(--ds-text-faint)"
                                :title="detail(row)"
                                >{{ detail(row) || '\u00A0' }}</span
                            >
                        </td>
                        <td
                            class="hidden min-w-0 px-3 md:table-cell"
                            :title="row.existing?.logs.join(', ')"
                        >
                            <span
                                v-if="row.existing?.unlogged"
                                class="text-(--ds-text-muted)"
                                >{{ $t('Not logged') }}</span
                            >
                            <span
                                v-else-if="fromOutput(row)"
                                class="text-(--ds-text-secondary)"
                                >{{ $t('Container output') }}</span
                            >
                            <!-- Cut from the left, so the file's name stays in view. -->
                            <span
                                v-else
                                class="block truncate text-left font-ui-mono text-[12px] text-(--ds-text-secondary) [direction:rtl]"
                                >{{ '\u200E' + ownLog(row) }}</span
                            >
                        </td>
                        <template v-if="row.existing?.unlogged">
                            <td
                                class="px-3 text-right text-(--ds-text-faint) tabular-nums"
                            >
                                —
                            </td>
                            <td
                                class="hidden px-3 text-right text-(--ds-text-faint) tabular-nums sm:table-cell"
                            >
                                —
                            </td>
                            <td
                                class="px-3 text-right text-(--ds-text-faint) tabular-nums max-sm:pr-4"
                            >
                                —
                            </td>
                            <td
                                class="hidden pr-4 pl-3 text-(--ds-text-faint) lg:table-cell"
                            >
                                —
                            </td>
                        </template>
                        <template v-else>
                            <td class="px-3 text-right tabular-nums">
                                {{ compact(row.lastDay.requests) }}
                            </td>
                            <td
                                class="hidden px-3 text-right tabular-nums sm:table-cell"
                                :class="
                                    row.lastDay.requests > 0 &&
                                    row.lastDay.errors / row.lastDay.requests >=
                                        0.05
                                        ? 'text-(--ds-red)'
                                        : 'text-(--ds-text-muted)'
                                "
                            >
                                {{
                                    rate(
                                        row.lastDay.errors,
                                        row.lastDay.requests,
                                    )
                                }}
                            </td>
                            <td
                                class="px-3 text-right tabular-nums max-sm:pr-4"
                            >
                                {{ compact(row.lastDay.visitors) }}
                            </td>
                            <td
                                class="hidden pr-4 pl-3 text-(--ds-text-muted) lg:table-cell"
                            >
                                {{
                                    row.existing?.timed
                                        ? $t('Recorded')
                                        : $t('Not recorded')
                                }}
                            </td>
                        </template>
                    </tr>
                </tbody>
            </table>
        </section>

        <p
            v-if="traffic.collecting && traffic.sites.length"
            class="text-xs text-(--ds-text-faint)"
        >
            {{
                $t(
                    'Counted on :name from the access logs already written, which ServerOS only reads. Errors are the share of server errors (5xx).',
                    { name: machineName },
                )
            }}
            <template v-if="unloggedCount > 0">
                {{
                    $t(
                        unloggedCount === 1
                            ? 'One site writes no access log, so it has no numbers.'
                            : ':count sites write no access log, so they have no numbers.',
                        { count: String(unloggedCount) },
                    )
                }}
            </template>
        </p>

        <TrafficFileDialog
            v-if="canManage && traffic.canAddFile"
            v-model:open="adding"
            :machine-id="machineId"
            :name="serviceName ?? ''"
            @added="reload"
        />
    </div>
</template>
