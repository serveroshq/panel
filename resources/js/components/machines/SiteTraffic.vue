<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import TimeSeriesChart from '@/components/machines/TimeSeriesChart.vue';
import TrafficTimingDialog from '@/components/machines/TrafficTimingDialog.vue';
import type { TrafficChange } from '@/components/machines/TrafficTimingDialog.vue';
import { appButtonClasses } from '@/lib/appButton';
import { errorMessage, describe } from '@/lib/errors';
import { fixed } from '@/lib/formatting';
import { themeColour } from '@/lib/themeColour';

/** A site the machine's own web servers serve (or a log file set by hand). */
export type ExistingSite = {
    server: string;
    names: string[];
    logs: string[];
    configs: string[];
    timed: boolean;
    shared: boolean;
    copy: boolean;
    container?: string | null;
    /** Containers it sends requests to (Traefik's labels). */
    apps?: string[];
    catchAll?: boolean;
    /** Why nothing is counted: its config writes no log, or one ServerOS can't read. */
    unlogged?: 'off' | 'elsewhere' | null;
    editable?: boolean;
    manual?: boolean;
    /** ServerOS can start, stop or time this site's log. */
    canChange?: boolean;
    update?: string | null;
};

export type SiteTrafficState = {
    site: string;
    domain: string | null;
    collecting: boolean;
    blocked: string | null;
    existing?: ExistingSite | null;
};

type Point = {
    ts: number;
    requests: number | null;
    s2xx: number | null;
    s3xx: number | null;
    s4xx: number | null;
    s5xx: number | null;
    bytes: number | null;
    p50: number | null;
    p95: number | null;
    visitors: number | null;
};

type Ranked = { name: string; requests: number; share: number };

type Report = SiteTrafficState & {
    range: Range;
    step: number;
    hasData: boolean;
    summary: {
        requests: number;
        status: Record<'2xx' | '3xx' | '4xx' | '5xx', number>;
        errorRate: number | null;
        clientErrorRate: number | null;
        bytes: number;
        p50: number | null;
        p95: number | null;
        visitors: number;
    };
    series: Point[];
    paths: Ranked[];
    referrers: Ranked[];
};

type Range = '24h' | '7d' | '30d';

const props = defineProps<{
    machineId: number;
    machineName: string;
    app: string;
    state: SiteTrafficState;
    canManage: boolean;
}>();
const emit = defineEmits<{ changed: [] }>();

const existing = computed(() => props.state.existing ?? null);
// Sites of the machine's own web server only have times when their log
// format records them.
const timed = computed(() => existing.value?.timed ?? true);
const timing = ref(false);
const timingAsked = ref(false);
const change = ref<TrafficChange>('timing');
const label = computed(() => {
    if (!existing.value) return props.state.domain ?? '';
    if (existing.value.shared)
        return existing.value.logs[0] ?? props.state.site;
    if (existing.value.catchAll) return trans('All other hosts');

    return props.state.domain ?? trans('Default site');
});
const unlogged = computed(() => existing.value?.unlogged ?? null);
/** Where the web server runs, in words: "Caddy", "nginx in web". */
const where = computed(() => {
    const site = existing.value;
    if (!site) return '';

    return site.container
        ? trans(':server in :container', {
              server: site.server,
              container: site.container,
          })
        : site.server;
});
const url = computed(() =>
    existing.value
        ? `/machines/${props.machineId}/sites/${encodeURIComponent(props.state.site)}/traffic`
        : `/machines/${props.machineId}/apps/${encodeURIComponent(props.app)}/traffic`,
);

const ranges: { value: Range; label: string }[] = [
    { value: '24h', label: '24h' },
    { value: '7d', label: '7d' },
    { value: '30d', label: '30d' },
];
const range = ref<Range>('24h');
const report = ref<Report | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
let controller: AbortController | null = null;
let refresh: ReturnType<typeof setTimeout> | undefined;

const ready = computed(
    () =>
        (props.state.domain !== null || existing.value !== null) &&
        props.state.blocked === null &&
        props.state.collecting &&
        unlogged.value === null,
);

async function load(quiet = false): Promise<void> {
    if (!ready.value) return;
    controller?.abort();
    controller = new AbortController();
    if (!quiet) loading.value = true;
    clearTimeout(refresh);

    try {
        const response = await fetch(`${url.value}?range=${range.value}`, {
            credentials: 'same-origin',
            headers: { Accept: 'application/json' },
            signal: controller.signal,
        });
        const body = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(describe(response.status, body));
        report.value = body as Report;
        error.value = null;
    } catch (e) {
        if ((e as Error).name === 'AbortError') return;
        error.value = errorMessage(e);
    } finally {
        loading.value = false;
    }
    // New minutes come in about once a minute.
    refresh = setTimeout(() => load(true), 60_000);
}

watch(range, () => load());
watch(url, () => {
    report.value = null;
    load();
});
watch(ready, (now) => {
    if (now) load();
});
onMounted(() => load());
onBeforeUnmount(() => {
    controller?.abort();
    clearTimeout(refresh);
});

const spans: Record<Range, string> = {
    '24h': trans('in the last 24 hours'),
    '7d': trans('in the last 7 days'),
    '30d': trans('in the last 30 days'),
};
const per = computed(
    () =>
        ({
            900: trans('per 15 minutes'),
            3600: trans('per hour'),
            21600: trans('per 6 hours'),
        })[report.value?.step ?? 900] ?? '',
);

function compact(value: number): string {
    if (value >= 1e9) return `${fixed(value / 1e9, 1)}B`;
    if (value >= 1e6) return `${fixed(value / 1e6, 1)}M`;
    if (value >= 1e4) return `${Math.round(value / 1e3)}k`;
    if (value >= 1e3) return `${fixed(value / 1e3, 1)}k`;

    return String(Math.round(value));
}

function bytes(value: number): string {
    if (value >= 1e12) return `${fixed(value / 1e12, 2)} TB`;
    if (value >= 1e9) return `${fixed(value / 1e9, 2)} GB`;
    if (value >= 1e6) return `${fixed(value / 1e6, 1)} MB`;
    if (value >= 1e3) return `${Math.round(value / 1e3)} kB`;

    return `${Math.round(value)} B`;
}

function ms(value: number | null): string {
    if (value === null) return '—';
    if (value >= 1000) return `${fixed(value / 1000, 2)} s`;

    return `${value < 10 ? fixed(value, 1) : Math.round(value)} ms`;
}

function percent(value: number | null): string {
    if (value === null) return '—';
    const p = value * 100;
    if (p === 0) return '0%';
    if (p < 0.1) return '<0.1%';

    return `${p < 10 ? fixed(p, 1) : Math.round(p)}%`;
}

const series = computed(() => report.value?.series ?? []);
const timestamps = computed(() => series.value.map((p) => p.ts));
const share = (part: number | null, whole: number | null): number | null =>
    part === null || whole === null || whole === 0 ? null : part / whole;

const charts = computed(() => {
    const points = series.value;
    const blue = themeColour('--ds-chart', '#60A5FA');
    const amber = themeColour('--ds-amber', '#FBBF24');
    const red = themeColour('--ds-red', '#F87171');

    return {
        requests: [
            {
                label: trans('All'),
                color: blue,
                values: points.map((p) => p.requests),
            },
            {
                label: '4xx',
                color: amber,
                values: points.map((p) => p.s4xx),
            },
            {
                label: '5xx',
                color: red,
                values: points.map((p) => p.s5xx),
            },
        ],
        errors: [
            {
                label: '4xx',
                color: amber,
                values: points.map((p) => share(p.s4xx, p.requests)),
            },
            {
                label: '5xx',
                color: red,
                values: points.map((p) => share(p.s5xx, p.requests)),
            },
        ],
        bandwidth: [
            {
                label: trans('Sent'),
                color: '#A78BFA',
                values: points.map((p) => p.bytes),
            },
        ],
        timing: [
            {
                label: 'p50',
                color: '#34D399',
                values: points.map((p) => p.p50),
            },
            {
                label: 'p95',
                color: blue,
                values: points.map((p) => p.p95),
            },
        ],
    };
});

const tiles = computed(() => {
    const s = report.value?.summary;
    if (!s) return [];
    const rate = s.errorRate ?? 0;

    return [
        {
            label: trans('Requests'),
            value: compact(s.requests),
            note: spans[range.value],
            tone: '',
        },
        {
            label: trans('Error rate'),
            value: percent(s.errorRate),
            note: trans(':count server errors (5xx)', {
                count: compact(s.status['5xx']),
            }),
            tone:
                rate >= 0.05
                    ? 'text-(--ds-red)'
                    : rate >= 0.01
                      ? 'text-(--ds-amber)'
                      : '',
        },
        {
            label: trans('Bandwidth'),
            value: bytes(s.bytes),
            note: trans('sent to visitors'),
            tone: '',
        },
        timed.value
            ? {
                  label: trans('Response time'),
                  value: ms(s.p95),
                  note: trans('p95, median :value', { value: ms(s.p50) }),
                  tone: '',
              }
            : {
                  label: trans('Response time'),
                  value: trans('Not recorded'),
                  note: trans('not in this log’s format'),
                  // Words, not a number: smaller, so they fit the tile.
                  tone: 'text-[18px]/9 text-(--ds-text-muted)',
              },
        {
            label: trans('Visitors'),
            value: compact(s.visitors),
            note: trans('unique per day'),
            tone: '',
        },
    ];
});

const tables = computed(() => [
    {
        key: 'paths',
        title: trans('Top pages'),
        column: trans('Path'),
        empty: trans('No pages counted yet.'),
        rows: report.value?.paths ?? [],
        mono: true,
    },
    {
        key: 'referrers',
        title: trans('Top referrers'),
        column: trans('Site'),
        empty: trans('No visits came from other sites.'),
        rows: report.value?.referrers ?? [],
        mono: false,
    },
]);

const settingsUrl = computed(() => `/machines/${props.machineId}/settings`);
const output = (log: string) => log === '/dev/stdout' || log === '/dev/stderr';
const files = computed(() => existing.value?.logs.join(', ') ?? '');
const fromOutput = computed(
    () =>
        !!existing.value?.container &&
        existing.value.logs.length > 0 &&
        existing.value.logs.every(output),
);
const changeable = computed(
    () =>
        props.canManage &&
        existing.value !== null &&
        (existing.value.canChange ??
            ((existing.value.editable ?? true) && !existing.value.unlogged)),
);
const canFix = computed(
    () =>
        changeable.value &&
        !!existing.value &&
        ['nginx', 'Apache'].includes(existing.value.server) &&
        !existing.value.timed &&
        !existing.value.copy &&
        !existing.value.unlogged,
);
const canStart = computed(
    () => changeable.value && unlogged.value === 'off' && !existing.value?.copy,
);
const canUndo = computed(
    () => changeable.value && !!existing.value?.copy && !existing.value.manual,
);

/** How to turn its log on by hand, when ServerOS won't change its config. */
const byHand = computed(() => {
    const site = existing.value;
    if (!site) return '';
    if (site.server === 'Caddy')
        return trans(
            'Add a log directive to this site in its Caddyfile. Logs to the container’s output are read too.',
        );
    if (site.server === 'Traefik')
        return trans(
            'Start Traefik with --accesslog=true and --accesslog.format=json. Logs to the container’s output are read too.',
        );

    return trans(
        'Take out access_log off for this site in its config. Logs to the container’s output are read too.',
    );
});

function openChange(mode: TrafficChange): void {
    change.value = mode;
    timing.value = true;
}

function recorded(): void {
    if (change.value === 'timing') timingAsked.value = true;
    emit('changed');
}
</script>

<template>
    <div class="flex flex-col gap-5">
        <div class="flex min-h-8 flex-wrap items-center justify-between gap-3">
            <div class="flex min-w-0 items-baseline gap-2">
                <h2 class="text-[15px] font-medium">{{ $t('Traffic') }}</h2>
                <span
                    v-if="label"
                    class="truncate text-(--ds-text-faint)"
                    :class="
                        existing?.catchAll
                            ? 'text-[13px]'
                            : 'font-ui-mono text-[12px]'
                    "
                    :title="label"
                    >{{ label }}</span
                >
            </div>
            <div class="flex items-center gap-3">
                <LoaderCircle
                    v-if="loading && report"
                    class="size-3.5 animate-spin text-(--ds-text-faint)"
                    aria-hidden="true"
                />
                <div
                    v-if="ready"
                    class="ds-segments ds-segments--sm"
                    role="group"
                    :aria-label="$t('Time range')"
                >
                    <button
                        v-for="option in ranges"
                        :key="option.value"
                        type="button"
                        class="cursor-pointer"
                        :aria-pressed="range === option.value"
                        @click="range = option.value"
                    >
                        {{ option.label }}
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="!state.domain && !existing"
            class="ds-tile flex flex-col items-center gap-1 px-5 py-10 text-center text-[13px]/5"
        >
            <p class="font-medium">{{ $t('This app has no domain') }}</p>
            <p class="max-w-md text-(--ds-text-muted)">
                {{
                    $t(
                        'Traffic is counted for sites the proxy serves. Give the app a domain to see its visits here.',
                    )
                }}
            </p>
        </div>

        <div
            v-else-if="state.blocked"
            class="ds-tile flex flex-col items-center gap-1 px-5 py-10 text-center text-[13px]/5"
        >
            <p class="font-medium">{{ $t('Traffic isn’t available here') }}</p>
            <p class="max-w-md text-(--ds-text-muted)">{{ state.blocked }}</p>
        </div>

        <div
            v-else-if="!state.collecting"
            class="ds-tile flex flex-col items-center gap-1 px-5 py-10 text-center text-[13px]/5"
        >
            <p class="font-medium">
                {{ $t(':name doesn’t count traffic', { name: machineName }) }}
            </p>
            <p class="max-w-md text-(--ds-text-muted)">
                {{
                    existing
                        ? $t(
                              'Turn on Traffic in the machine’s settings to count visits from the access logs your web server already writes. ServerOS only reads them, and visitor IPs never leave the machine.',
                          )
                        : $t(
                              'Turn on Traffic in the machine’s settings to count visits from the proxy’s access logs. Visitor IPs never leave the machine.',
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
            v-else-if="unlogged"
            class="ds-tile flex flex-col items-center gap-1 px-5 py-10 text-center text-[13px]/5"
        >
            <p class="font-medium">{{ $t('This site isn’t logged') }}</p>
            <p class="max-w-md text-(--ds-text-muted)">
                {{
                    unlogged === 'off'
                        ? $t(
                              ':server writes no access log for this site, so there are no visits to count.',
                              { server: where },
                          )
                        : $t(
                              'This site’s log goes to syslog or the journal, which ServerOS can’t read. Point it at a file to count its visits.',
                          )
                }}
                <template v-if="unlogged === 'off' && !canStart">
                    {{
                        existing?.update
                            ? existing.update
                            : existing?.container
                              ? byHand
                              : ''
                    }}
                </template>
            </p>
            <button
                v-if="canStart"
                type="button"
                :class="appButtonClasses('secondary', 'md', 'mt-2')"
                @click="openChange('add')"
            >
                {{ $t('Start logging this site') }}
            </button>
        </div>

        <div
            v-else-if="error && !report"
            class="ds-tile px-5 py-10 text-center text-[13px]/5 text-(--ds-red)"
            role="alert"
        >
            {{ error }}
        </div>

        <div
            v-else-if="!report"
            class="ds-tile flex items-center justify-center gap-2 px-5 py-10 text-[13px]/5 text-(--ds-text-muted)"
        >
            <LoaderCircle class="size-3.5 animate-spin" aria-hidden="true" />
            {{ $t('Loading traffic…') }}
        </div>

        <div
            v-else-if="!report.hasData"
            class="ds-tile flex flex-col items-center gap-1 px-5 py-10 text-center text-[13px]/5"
        >
            <p class="font-medium">{{ $t('No visits yet') }}</p>
            <p class="max-w-md text-(--ds-text-muted)">
                {{
                    $t(
                        'Traffic shows up within a couple of minutes of the first visit.',
                    )
                }}
            </p>
        </div>

        <template v-else>
            <section
                v-if="existing?.shared"
                class="ds-tile flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-center sm:justify-between"
                :aria-label="$t('Counted together')"
            >
                <div class="flex min-w-0 flex-col gap-0.5 text-[13px]/5">
                    <h3 class="font-medium">{{ $t('Counted together') }}</h3>
                    <p class="text-(--ds-text-muted)">
                        {{
                            $t(
                                ':count sites write :file, and its lines don’t say which site a request was for, so they’re counted as one: :names.',
                                {
                                    count: String(existing.names.length),
                                    file: existing.logs[0] ?? '',
                                    names: existing.names.join(', '),
                                },
                            )
                        }}
                    </p>
                </div>
                <button
                    v-if="canFix"
                    type="button"
                    :class="
                        appButtonClasses(
                            'secondary',
                            'md',
                            'self-start sm:self-center',
                        )
                    "
                    @click="openChange('timing')"
                >
                    {{ $t('Count them apart') }}
                </button>
            </section>

            <ul
                class="grid grid-cols-2 gap-4 lg:grid-cols-5"
                :aria-label="$t('Summary')"
            >
                <li
                    v-for="tile in tiles"
                    :key="tile.label"
                    class="ds-tile flex min-w-0 flex-col gap-1 px-5 py-4 last:max-lg:col-span-2"
                >
                    <span class="text-[13px] text-(--ds-text-muted)">{{
                        tile.label
                    }}</span>
                    <span
                        class="truncate font-medium tabular-nums"
                        :class="[
                            tile.tone.includes('text-[18px]')
                                ? ''
                                : 'text-[28px]/9',
                            tile.tone,
                        ]"
                        >{{ tile.value }}</span
                    >
                    <span class="truncate text-xs text-(--ds-text-faint)">{{
                        tile.note
                    }}</span>
                </li>
            </ul>

            <div class="grid gap-4 lg:grid-cols-2">
                <TimeSeriesChart
                    :title="$t('Requests :per', { per })"
                    :timestamps="timestamps"
                    :series="charts.requests"
                    :format="compact"
                />
                <TimeSeriesChart
                    :title="$t('Error rate')"
                    :timestamps="timestamps"
                    :series="charts.errors"
                    :format="percent"
                    :min-max="0.01"
                    :max="1"
                />
                <TimeSeriesChart
                    :title="$t('Bandwidth :per', { per })"
                    :timestamps="timestamps"
                    :series="charts.bandwidth"
                    :format="bytes"
                    :min-max="1000"
                />
                <TimeSeriesChart
                    v-if="timed"
                    :title="$t('Response time')"
                    :timestamps="timestamps"
                    :series="charts.timing"
                    :format="ms"
                    :min-max="10"
                />
                <section
                    v-else
                    class="ds-tile flex min-h-48 min-w-0 flex-col gap-3 p-4"
                    :aria-label="$t('Response time')"
                >
                    <h3
                        class="text-[13px]/5 font-medium text-(--ds-text-muted)"
                    >
                        {{ $t('Response time') }}
                    </h3>
                    <div
                        class="flex flex-1 flex-col items-center justify-center gap-1 px-4 text-center text-[13px]/5"
                    >
                        <p class="font-medium">{{ $t('Not recorded') }}</p>
                        <p class="max-w-sm text-(--ds-text-muted)">
                            {{
                                timingAsked
                                    ? $t(
                                          'Response times show up here for new visits within a couple of minutes.',
                                      )
                                    : $t(
                                          'This site’s access log doesn’t say how long requests took. ServerOS can add a copy of the log that does, and shows you every line first.',
                                      )
                            }}
                        </p>
                        <button
                            v-if="canFix && !timingAsked"
                            type="button"
                            :class="appButtonClasses('secondary', 'md', 'mt-2')"
                            @click="openChange('timing')"
                        >
                            {{ $t('Record response times') }}
                        </button>
                    </div>
                </section>
            </div>

            <div class="grid gap-4 lg:grid-cols-2">
                <section
                    v-for="table in tables"
                    :key="table.key"
                    class="ds-tile min-w-0 overflow-hidden"
                    :aria-label="table.title"
                >
                    <h3
                        class="px-4 pt-3 pb-2 text-[13px]/5 font-medium text-(--ds-text-muted)"
                    >
                        {{ table.title }}
                    </h3>
                    <table
                        class="w-full table-fixed border-collapse text-left text-[13px]/5"
                    >
                        <thead>
                            <tr class="text-[12px]/4 text-(--ds-text-faint)">
                                <th class="py-2 pr-3 pl-4 font-normal">
                                    {{ table.column }}
                                </th>
                                <th
                                    class="w-24 border-l border-(--ds-divider) px-3 text-right font-normal"
                                >
                                    {{ $t('Requests') }}
                                </th>
                                <th
                                    class="w-20 border-l border-(--ds-divider) pr-4 pl-3 text-right font-normal"
                                >
                                    {{ $t('Share') }}
                                </th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr
                                v-for="row in table.rows"
                                :key="row.name"
                                class="border-t border-(--ds-divider)"
                            >
                                <td class="relative py-2 pr-3 pl-4">
                                    <span
                                        class="absolute inset-y-1 left-1 rounded-sm bg-(--ds-blue)/10"
                                        :style="{
                                            width: `calc(${Math.max(1, row.share * 100)}% - 0.25rem)`,
                                        }"
                                        aria-hidden="true"
                                    />
                                    <span
                                        class="relative block truncate"
                                        :class="
                                            table.mono &&
                                            'font-ui-mono text-[12px]'
                                        "
                                        :title="row.name"
                                        >{{ row.name }}</span
                                    >
                                </td>
                                <td class="px-3 text-right tabular-nums">
                                    {{ compact(row.requests) }}
                                </td>
                                <td
                                    class="pr-4 pl-3 text-right text-(--ds-text-muted) tabular-nums"
                                >
                                    {{ percent(row.share) }}
                                </td>
                            </tr>
                            <tr
                                v-if="table.rows.length === 0"
                                class="border-t border-(--ds-divider)"
                            >
                                <td
                                    colspan="3"
                                    class="px-4 py-6 text-center text-(--ds-text-faint)"
                                >
                                    {{ table.empty }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>
            </div>

            <p v-if="existing" class="text-xs text-(--ds-text-faint)">
                {{
                    existing.copy
                        ? $t(
                              'Counted on :name from a log ServerOS added to :server’s config. Visitor IPs and user agents never leave the machine, and pages are shown without their query strings.',
                              { name: machineName, server: where },
                          )
                        : fromOutput
                          ? $t(
                                'Counted on :name from the output of the container :container, which ServerOS only reads. Visitor IPs and user agents never leave the machine, and pages are shown without their query strings.',
                                {
                                    name: machineName,
                                    container: existing.container ?? '',
                                },
                            )
                          : $t(
                                'Counted on :name from :files, which ServerOS only reads. Visitor IPs and user agents never leave the machine, and pages are shown without their query strings.',
                                { name: machineName, files },
                            )
                }}
                <button
                    v-if="canUndo"
                    type="button"
                    class="cursor-pointer text-(--ds-text-muted) underline underline-offset-2 hover:text-(--ds-text)"
                    @click="openChange('remove')"
                >
                    {{ $t('Take ServerOS’s lines out') }}
                </button>
            </p>
            <p v-else class="text-xs text-(--ds-text-faint)">
                {{
                    $t(
                        'Counted on :name from the proxy’s access logs. Visitor IPs and user agents never leave the machine, and pages are shown without their query strings.',
                        { name: machineName },
                    )
                }}
            </p>
        </template>

        <TrafficTimingDialog
            v-if="canFix || canStart || canUndo"
            v-model:open="timing"
            :machine-id="machineId"
            :site="state.site"
            :label="label"
            :mode="change"
            :server="where"
            @applied="recorded"
        />
    </div>
</template>
