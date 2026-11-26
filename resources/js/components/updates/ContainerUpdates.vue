<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router } from '@inertiajs/vue3';
import { LoaderCircle } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import AppButton from '@/components/workspace/AppButton.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import { errorMessage, failIfBad } from '@/lib/errors';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';

export type ContainerRow = {
    id: number;
    machineId: number;
    machine: string | null;
    container: string;
    service: string;
    image: string;
    state: 'available' | 'current' | 'local' | 'unknown';
    error: string | null;
    checkedAt: string | null;
    availableSince: string | null;
    scannedAt: string | null;
    updateJob: string | null;
    vulnerabilities: {
        total: number;
        critical: number;
        high: number;
        exploited: number;
    };
};

type Finding = {
    cve: string;
    title: string | null;
    severity: string;
    severityLabel: string;
    exploited: boolean;
    url: string;
    package: string;
    installed: string | null;
    fixedIn: string | null;
};

const props = defineProps<{
    containers: ContainerRow[];
    canManage: boolean;
}>();

const filter = ref<'attention' | 'all'>('attention');
const picked = ref<number[]>([]);
const updating = ref(false);
const checking = ref(false);
const open = ref<number | null>(null);
const findings = ref<Record<number, Finding[]>>({});
const loadingFindings = ref<number | null>(null);

const needsAttention = (row: ContainerRow) =>
    row.state === 'available' || row.vulnerabilities.total > 0;
const shown = computed(() =>
    filter.value === 'attention'
        ? props.containers.filter(needsAttention)
        : props.containers,
);
const updatable = computed(() =>
    shown.value.filter((row) => row.state === 'available' && !row.updateJob),
);
const allPicked = computed(
    () =>
        updatable.value.length > 0 &&
        updatable.value.every((row) => picked.value.includes(row.id)),
);
const running = computed(() => props.containers.some((row) => row.updateJob));

let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    running,
    (value) => {
        clearTimeout(timer);
        if (value) {
            timer = setTimeout(
                () => router.reload({ only: ['containers'] }),
                3000,
            );
        }
    },
    { immediate: true },
);
watch(
    () => props.containers,
    () => {
        if (running.value) {
            clearTimeout(timer);
            timer = setTimeout(
                () => router.reload({ only: ['containers'] }),
                3000,
            );
        }
        picked.value = picked.value.filter((id) =>
            updatable.value.some((row) => row.id === id),
        );
    },
);
onBeforeUnmount(() => clearTimeout(timer));

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function post(path: string, body?: object): Promise<unknown> {
    const response = await fetch(path, {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        body: body ? JSON.stringify(body) : undefined,
    });
    await failIfBad(response);
    return response.json();
}

async function update(ids: number[]): Promise<void> {
    updating.value = true;
    try {
        const result = (await post('/container-images/update', { ids })) as {
            queued: Record<string, string>;
            failed: { container: string; error: string }[];
        };
        const count = Object.keys(result.queued).length;
        toast.success(
            count === 1
                ? trans('Updating 1 container.')
                : trans('Updating :count containers.', {
                      count: String(count),
                  }),
        );
        for (const failure of result.failed) {
            toast.error(`${failure.container}: ${failure.error}`);
        }
        picked.value = [];
        router.reload({ only: ['containers'] });
    } catch (e) {
        toast.error(errorMessage(e));
    } finally {
        updating.value = false;
    }
}

async function checkNow(): Promise<void> {
    checking.value = true;
    try {
        await post('/container-images/check');
        router.reload({ only: ['containers'] });
    } catch (e) {
        toast.error(errorMessage(e));
    } finally {
        checking.value = false;
    }
}

async function toggleFindings(row: ContainerRow): Promise<void> {
    if (open.value === row.id) {
        open.value = null;
        return;
    }
    open.value = row.id;
    if (findings.value[row.id]) return;
    loadingFindings.value = row.id;
    try {
        const response = await fetch(
            `/container-images/${row.id}/vulnerabilities`,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            },
        );
        await failIfBad(response);
        findings.value[row.id] = (
            (await response.json()) as { findings: Finding[] }
        ).findings;
    } catch (e) {
        toast.error(errorMessage(e));
        open.value = null;
    } finally {
        loadingFindings.value = null;
    }
}

function status(row: ContainerRow): string {
    if (row.updateJob) return trans('Updating');
    switch (row.state) {
        case 'available':
            return row.availableSince
                ? trans('Newer image since :when', {
                      when: timeAgo(row.availableSince),
                  })
                : trans('Newer image');
        case 'current':
            return trans('Up to date');
        case 'local':
            return trans('Built on the machine');
        default:
            return trans('Couldn’t check');
    }
}

function vulnLine(row: ContainerRow): string {
    const v = row.vulnerabilities;
    if (!v.total) return row.scannedAt ? trans('None known') : '–';
    const worst = [
        v.exploited
            ? trans(':count exploited', { count: String(v.exploited) })
            : '',
        v.critical
            ? trans(':count critical', { count: String(v.critical) })
            : '',
        v.high ? trans(':count high', { count: String(v.high) }) : '',
    ].filter(Boolean);
    return worst.length ? `${v.total} (${worst.join(', ')})` : String(v.total);
}

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <section class="flex flex-col gap-3" aria-labelledby="containers-heading">
        <div class="flex min-h-8 flex-wrap items-center gap-3">
            <h2
                id="containers-heading"
                class="text-[15px]/6 font-semibold tracking-[-0.01em]"
            >
                {{ $t('Containers') }}
            </h2>
            <div class="ds-segments" role="group" :aria-label="$t('Filter')">
                <button
                    type="button"
                    class="cursor-pointer"
                    :class="focusRing"
                    :aria-pressed="filter === 'attention'"
                    @click="filter = 'attention'"
                >
                    {{ $t('Needs attention') }}
                </button>
                <button
                    type="button"
                    class="cursor-pointer"
                    :class="focusRing"
                    :aria-pressed="filter === 'all'"
                    @click="filter = 'all'"
                >
                    {{ $t('All') }}
                </button>
            </div>
            <div
                v-if="canManage"
                class="ml-auto flex flex-wrap items-center gap-2"
            >
                <AppButton size="sm" :disabled="checking" @click="checkNow">
                    <LoaderCircle
                        v-if="checking"
                        class="size-3.5 animate-spin opacity-60"
                    />
                    {{ $t('Check registries now') }}
                </AppButton>
                <AppButton
                    v-if="picked.length"
                    size="sm"
                    variant="primary"
                    :disabled="updating"
                    @click="update(picked)"
                >
                    {{
                        picked.length === 1
                            ? $t('Update 1 container')
                            : $t('Update :count containers', {
                                  count: String(picked.length),
                              })
                    }}
                </AppButton>
            </div>
        </div>

        <p class="text-[13px]/5 text-pretty text-(--ds-text-muted)">
            {{
                $t(
                    'Each container’s registry is checked about once an hour for a newer image on the same tag. Updating pulls it and starts the container again with the same settings. If it doesn’t stay up, the old one is put back.',
                )
            }}
        </p>

        <div class="ds-tile overflow-x-auto text-[13px]/5">
            <table class="w-full min-w-[48rem] table-fixed">
                <thead class="text-left text-xs/4 text-(--ds-text-muted)">
                    <tr class="border-b-2 border-black/5 dark:border-black/30">
                        <th v-if="canManage" class="w-10 py-2 pl-4">
                            <TickBox
                                :model-value="allPicked"
                                :disabled="!updatable.length"
                                :aria-label="
                                    $t(
                                        'Pick every container with a newer image',
                                    )
                                "
                                @update:model-value="
                                    picked = allPicked
                                        ? []
                                        : updatable.map((row) => row.id)
                                "
                            />
                        </th>
                        <th class="w-[22%] py-2 pr-3 pl-4 font-medium">
                            {{ $t('Container') }}
                        </th>
                        <th class="w-[28%] py-2 pr-3 font-medium">
                            {{ $t('Image') }}
                        </th>
                        <th class="py-2 pr-3 font-medium">
                            {{ $t('Status') }}
                        </th>
                        <th class="py-2 pr-3 font-medium">
                            {{ $t('Vulnerabilities') }}
                        </th>
                        <th v-if="canManage" class="w-24 py-2 pr-4" />
                    </tr>
                </thead>
                <tbody class="divide-y-2 divide-black/5 dark:divide-black/30">
                    <template v-for="row in shown" :key="row.id">
                        <tr>
                            <td v-if="canManage" class="py-2 pl-4">
                                <TickBox
                                    v-if="
                                        row.state === 'available' &&
                                        !row.updateJob
                                    "
                                    :model-value="picked.includes(row.id)"
                                    :aria-label="
                                        $t('Pick :name', {
                                            name: row.container,
                                        })
                                    "
                                    @update:model-value="
                                        picked = picked.includes(row.id)
                                            ? picked.filter(
                                                  (id) => id !== row.id,
                                              )
                                            : [...picked, row.id]
                                    "
                                />
                            </td>
                            <td class="max-w-0 py-2 pr-3 pl-4">
                                <span class="block truncate font-medium">{{
                                    row.container
                                }}</span>
                                <span
                                    class="block truncate text-xs/4 text-(--ds-text-muted)"
                                    >{{ row.machine }}</span
                                >
                            </td>
                            <td class="max-w-0 py-2 pr-3">
                                <span
                                    class="block truncate font-mono text-[12.5px]"
                                    :title="row.image"
                                    >{{ row.image }}</span
                                >
                            </td>
                            <td class="py-2 pr-3 whitespace-nowrap">
                                <span
                                    class="inline-flex items-center gap-1.5"
                                    :class="{
                                        'text-(--ds-blue)':
                                            row.state === 'available',
                                        'text-(--ds-text-muted)':
                                            row.state === 'local' ||
                                            row.state === 'current',
                                    }"
                                    :title="row.error ?? undefined"
                                >
                                    <LoaderCircle
                                        v-if="row.updateJob"
                                        class="size-3.5 animate-spin opacity-60"
                                    />
                                    {{ status(row) }}
                                </span>
                                <span
                                    v-if="row.state === 'unknown' && row.error"
                                    class="block max-w-64 truncate text-xs/4 text-(--ds-text-faint)"
                                    :title="row.error"
                                    >{{ row.error }}</span
                                >
                            </td>
                            <td class="py-2 pr-3 whitespace-nowrap">
                                <button
                                    v-if="row.vulnerabilities.total"
                                    type="button"
                                    class="cursor-pointer rounded-sm underline decoration-(--ds-text-faint) underline-offset-2 hover:decoration-current"
                                    :class="[
                                        focusRing,
                                        row.vulnerabilities.exploited ||
                                        row.vulnerabilities.critical
                                            ? 'text-(--ds-red)'
                                            : '',
                                    ]"
                                    :aria-expanded="open === row.id"
                                    @click="toggleFindings(row)"
                                >
                                    {{ vulnLine(row) }}
                                </button>
                                <span v-else class="text-(--ds-text-faint)">{{
                                    vulnLine(row)
                                }}</span>
                            </td>
                            <td v-if="canManage" class="py-2 pr-4 text-right">
                                <AppButton
                                    v-if="
                                        row.state === 'available' &&
                                        !row.updateJob
                                    "
                                    size="sm"
                                    :disabled="updating"
                                    @click="update([row.id])"
                                >
                                    {{ $t('Update') }}
                                </AppButton>
                            </td>
                        </tr>
                        <tr v-if="open === row.id">
                            <td
                                :colspan="canManage ? 6 : 4"
                                class="bg-(--ds-hover) px-4 py-3"
                            >
                                <p
                                    v-if="loadingFindings === row.id"
                                    class="text-(--ds-text-muted)"
                                >
                                    {{ $t('Loading') }}
                                </p>
                                <template v-else>
                                    <p
                                        class="mb-2 text-xs/4 text-pretty text-(--ds-text-muted)"
                                    >
                                        {{
                                            row.state === 'available'
                                                ? $t(
                                                      'These are fixed in the packages of :image’s distribution. The newer image may already have them.',
                                                      { image: row.image },
                                                  )
                                                : $t(
                                                      'These are fixed in the packages of :image’s distribution, but the image hasn’t been rebuilt with them yet.',
                                                      { image: row.image },
                                                  )
                                        }}
                                    </p>
                                    <ul class="flex flex-col gap-1.5">
                                        <li
                                            v-for="finding in findings[
                                                row.id
                                            ] ?? []"
                                            :key="finding.cve + finding.package"
                                            class="flex flex-wrap items-baseline gap-x-3 gap-y-0.5"
                                        >
                                            <a
                                                :href="finding.url"
                                                target="_blank"
                                                rel="noopener"
                                                class="rounded-sm font-mono text-[12.5px] underline decoration-(--ds-text-faint) underline-offset-2 hover:decoration-current"
                                                :class="focusRing"
                                                >{{ finding.cve }}</a
                                            >
                                            <span
                                                :class="
                                                    finding.exploited ||
                                                    finding.severity ===
                                                        'critical'
                                                        ? 'text-(--ds-red)'
                                                        : 'text-(--ds-text-muted)'
                                                "
                                                >{{
                                                    finding.exploited
                                                        ? $t(
                                                              ':severity, being exploited',
                                                              {
                                                                  severity:
                                                                      finding.severityLabel,
                                                              },
                                                          )
                                                        : finding.severityLabel
                                                }}</span
                                            >
                                            <span
                                                class="font-mono text-[12px] text-(--ds-text-muted)"
                                            >
                                                {{ finding.package }}
                                                {{ finding.installed }} →
                                                {{ finding.fixedIn }}
                                            </span>
                                            <span
                                                v-if="finding.title"
                                                class="min-w-0 truncate text-(--ds-text-muted)"
                                                >{{ finding.title }}</span
                                            >
                                        </li>
                                    </ul>
                                </template>
                            </td>
                        </tr>
                    </template>
                    <tr v-if="!shown.length">
                        <td
                            :colspan="canManage ? 6 : 4"
                            class="px-4 py-6 text-center text-(--ds-text-muted)"
                        >
                            {{
                                containers.length
                                    ? $t(
                                          'Every container runs the newest image, with no known vulnerabilities.',
                                      )
                                    : $t(
                                          'No containers checked yet. Machines need ServerOS daemon 0.1.1-pre.49 or newer.',
                                      )
                            }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </section>
</template>
