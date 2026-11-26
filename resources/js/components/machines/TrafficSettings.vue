<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { ChevronRight, LoaderCircle } from '@lucide/vue';
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import ToggleSwitch from '@/components/workspace/ToggleSwitch.vue';
import { describe, errorMessage, jobReason } from '@/lib/errors';
import { fixed } from '@/lib/formatting';
import { toast } from '@/lib/toast';

export type TrafficSettingsProps = {
    enabled: boolean;
    blocked: string | null;
    proxy: string | null;
    sites: number | null;
    existing: number | null;
    changing: string | null;
    error: string | null;
};

type SiteRow = {
    site: string;
    kind: 'app' | 'existing';
    name: string;
    server: string;
    container?: string | null;
    names: string[];
    logs: string[];
    shared: boolean;
    timed: boolean | null;
    catchAll?: boolean;
    unlogged?: 'off' | 'elsewhere' | null;
    url: string | null;
    requests: number;
    errors: number;
    clientErrors: number;
    visitors: number;
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
const rows = ref<SiteRow[] | null>(null);
let poll: ReturnType<typeof setTimeout> | undefined;
let controller: AbortController | null = null;
onBeforeUnmount(() => {
    clearTimeout(poll);
    controller?.abort();
});

async function loadSites(): Promise<void> {
    if (!props.traffic.enabled || props.traffic.blocked) {
        rows.value = null;

        return;
    }
    controller?.abort();
    controller = new AbortController();
    try {
        const response = await fetch(
            `/machines/${props.machineId}/traffic/sites`,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
                signal: controller.signal,
            },
        );
        if (!response.ok) return;
        const body = await response.json();
        rows.value = Array.isArray(body.sites) ? (body.sites as SiteRow[]) : [];
    } catch {
        // The table is extra; the switch works without it.
    }
}

function compact(value: number): string {
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

/** A row's second line: the app's domain, or the site's other names or its log. */
function detail(row: SiteRow): string {
    if (row.kind === 'app') return row.names[0] ?? trans('No domain');
    if (row.unlogged === 'off') return trans('Writes no access log');
    if (row.unlogged) return trans('Logs to syslog or the journal');
    if (row.shared) return row.names.join(', ');
    if (row.catchAll) return trans('Every host no other site answers');
    if (row.names.length > 1) return row.names.slice(1).join(', ');

    // The log's file name: the folder is on the site's own page.
    // The site's own log, not the copy with times ServerOS may have added.
    const own =
        row.logs.find((log) => !log.startsWith('/var/log/serveros-traffic/')) ??
        row.logs[0];
    if (own === '/dev/stdout' || own === '/dev/stderr') {
        return trans('The container’s output');
    }

    return own?.split('/').pop() ?? '';
}

const counted = computed(() =>
    props.traffic.sites === null && props.traffic.existing === null
        ? null
        : (props.traffic.sites ?? 0) + (props.traffic.existing ?? 0),
);

watch(
    () => props.traffic,
    (next) => {
        if (busy.value) return;
        on.value = next.enabled;
        error.value = next.error;
        loadSites();
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
    loadSites();
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
                        traffic.existing !== null
                            ? $t(
                                  'Counts visits to the sites on :name from their access logs: the apps ServerOS runs, and the sites your own web servers serve, whose logs ServerOS only reads. Visitor IPs never leave the machine.',
                                  { name: machineName },
                              )
                            : $t(
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
            v-else-if="traffic.enabled && counted !== null && !rows?.length"
            class="border-t border-(--ds-border) px-5 py-3 text-[13px]/5 text-(--ds-text-muted)"
        >
            {{
                $t(
                    counted === 1
                        ? 'Counting 1 site. Each app’s Traffic tab shows its visits.'
                        : 'Counting :count sites. Each app’s Traffic tab shows its visits.',
                    { count: String(counted) },
                )
            }}
        </p>

        <div
            v-if="traffic.enabled && !busy && !error && rows?.length"
            class="border-t border-(--ds-border)"
        >
            <table
                class="w-full table-fixed border-collapse text-left text-[13px]/5"
                :aria-label="$t('Sites counted')"
            >
                <thead>
                    <tr class="text-[12px]/4 text-(--ds-text-faint)">
                        <th class="py-2 pr-3 pl-5 font-normal">
                            {{ $t('Site') }}
                        </th>
                        <th
                            class="hidden w-36 border-l border-(--ds-divider) px-3 font-normal sm:table-cell"
                        >
                            {{ $t('Served by') }}
                        </th>
                        <th
                            class="w-20 border-l border-(--ds-divider) px-3 text-right font-normal sm:w-24"
                        >
                            {{ $t('Requests') }}
                        </th>
                        <th
                            class="hidden w-20 border-l border-(--ds-divider) px-3 text-right font-normal sm:table-cell"
                        >
                            {{ $t('Errors') }}
                        </th>
                        <th
                            class="w-20 border-l border-(--ds-divider) px-3 text-right font-normal sm:w-24"
                        >
                            {{ $t('Visitors') }}
                        </th>
                        <th class="w-10 pr-4" aria-hidden="true" />
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="row in rows"
                        :key="row.site"
                        class="border-t border-(--ds-divider)"
                        :class="row.url && 'hover:bg-(--ds-hover)'"
                    >
                        <td class="min-w-0 py-2 pr-3 pl-5">
                            <component
                                :is="row.url ? Link : 'span'"
                                :href="row.url ?? undefined"
                                class="block truncate font-medium text-(--ds-text)"
                                :title="row.names.join(', ') || row.name"
                                >{{ row.name }}</component
                            >
                            <span
                                class="block truncate text-xs text-(--ds-text-faint)"
                                :title="detail(row)"
                                >{{ detail(row) }}</span
                            >
                        </td>
                        <td
                            class="hidden min-w-0 px-3 text-(--ds-text-muted) sm:table-cell"
                        >
                            <span class="block truncate">{{ row.server }}</span>
                            <span
                                v-if="row.container"
                                class="block truncate text-xs text-(--ds-text-faint)"
                                :title="row.container"
                                >{{
                                    $t('in :container', {
                                        container: row.container,
                                    })
                                }}</span
                            >
                        </td>
                        <template v-if="row.unlogged">
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
                                class="px-3 text-right text-(--ds-text-faint) tabular-nums"
                            >
                                —
                            </td>
                        </template>
                        <template v-else>
                            <td class="px-3 text-right tabular-nums">
                                {{ compact(row.requests) }}
                            </td>
                            <td
                                class="hidden px-3 text-right tabular-nums sm:table-cell"
                                :class="
                                    row.requests > 0 &&
                                    row.errors / row.requests >= 0.05
                                        ? 'text-(--ds-red)'
                                        : 'text-(--ds-text-muted)'
                                "
                            >
                                {{ rate(row.errors, row.requests) }}
                            </td>
                            <td class="px-3 text-right tabular-nums">
                                {{ compact(row.visitors) }}
                            </td>
                        </template>
                        <td class="pr-4 text-right">
                            <Link
                                v-if="row.url"
                                :href="row.url"
                                class="inline-flex size-6 items-center justify-center rounded-md text-(--ds-text-faint) hover:bg-(--ds-hover) hover:text-(--ds-text)"
                                :aria-label="
                                    $t('Traffic for :name', { name: row.name })
                                "
                            >
                                <ChevronRight
                                    class="size-4"
                                    aria-hidden="true"
                                />
                            </Link>
                        </td>
                    </tr>
                </tbody>
            </table>
            <p
                class="border-t border-(--ds-divider) px-5 py-2.5 text-xs text-(--ds-text-faint)"
            >
                {{
                    $t(
                        'The last 24 hours. Errors are the share of server errors (5xx). A site that writes no access log has no numbers; its page says how to start one.',
                    )
                }}
            </p>
        </div>
    </section>
</template>
