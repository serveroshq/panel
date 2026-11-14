<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { router } from '@inertiajs/vue3';
import { LoaderCircle, Play, Search } from '@lucide/vue';
import {
    SolarDatabase as DatabaseIcon,
    SolarDownload as Download,
    SolarLock as Lock,
    SolarRefreshCw as RefreshCw,
    SolarShieldCheck as ShieldCheck,
} from '@/lib/solarIcons';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import InputError from '@/components/InputError.vue';
import { bytes } from '@/components/machines/serviceFormat';
import SelectMenu from '@/components/ui/select/SelectMenu.vue';
import {
    InputOTP,
    InputOTPGroup,
    InputOTPSlot,
} from '@/components/ui/input-otp';
import AppButton from '@/components/workspace/AppButton.vue';
import SubmitButton from '@/components/workspace/SubmitButton.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import { edit as securityEdit } from '@/routes/security';

type Result = {
    database: string | null;
    columns: string[];
    rows: (string | null)[][];
    truncated: boolean;
    changed: number | null;
    error: string | null;
};

type Table = {
    schema: string;
    name: string;
    kind: string;
    rows: number | null;
    bytes: number | null;
};

const props = defineProps<{
    machineId: number;
    serviceKey: string;
    engine: 'postgres' | 'mysql';
    consoleLock: {
        twoFactorEnabled: boolean;
        unlockedUntil: string | null;
        minutes: number;
    };
    available: boolean;
}>();

class DatabaseError extends Error {
    constructor(
        message: string,
        public code?: string,
    ) {
        super(message);
    }
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function call(
    method: 'GET' | 'POST',
    path: string,
    body?: unknown,
): Promise<{ ok: boolean; body: Record<string, unknown> }> {
    const response = await fetch(path, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf(),
        },
        ...(method === 'POST' ? { body: JSON.stringify(body ?? {}) } : {}),
    });

    return {
        ok: response.ok,
        body: await response.json().catch(() => ({})),
    };
}

const FINISHED = ['succeeded', 'failed', 'cancelled', 'timed_out', 'refused'];
const base = computed(
    () => `/machines/${props.machineId}/services/${props.serviceKey}/database`,
);

async function ask(payload: {
    action: 'databases' | 'tables' | 'query';
    database?: string | null;
    query?: string;
    write?: boolean;
}): Promise<Result> {
    const sent = await call('POST', base.value, payload);
    if (!sent.ok) {
        const errors = sent.body.errors as Record<string, string[]> | undefined;
        throw new DatabaseError(
            (errors && Object.values(errors)[0]?.[0]) ??
                (sent.body.error as string | undefined) ??
                trans('That didn’t work.'),
            sent.body.code as string | undefined,
        );
    }

    const id = sent.body.jobId as string;
    const started = Date.now();
    while (Date.now() - started < 100_000) {
        const waited = Date.now() - started;
        await new Promise((resolve) =>
            setTimeout(resolve, waited < 4000 ? 200 : 1000),
        );
        const status = await call('GET', `${base.value}/${id}`);
        const state = status.body.state as string | undefined;
        if (!status.ok || !state) {
            throw new DatabaseError(trans('Couldn’t check on it. Try again.'));
        }
        if (state === 'succeeded' && status.body.result) {
            return status.body.result as Result;
        }
        if (FINISHED.includes(state)) {
            throw new DatabaseError(
                (status.body.error as { message?: string } | null)?.message ??
                    trans('The machine couldn’t run that.'),
            );
        }
    }

    throw new DatabaseError(trans('The machine didn’t answer in time.'));
}

// Unlocking, as the files tab does it: these are the same people who may
// open the console.
const now = ref(Date.now());
let clock: ReturnType<typeof setInterval> | undefined;
onMounted(() => (clock = setInterval(() => (now.value = Date.now()), 1000)));
onBeforeUnmount(() => clearInterval(clock));

const unlockedMs = computed(() =>
    props.consoleLock.unlockedUntil
        ? Date.parse(props.consoleLock.unlockedUntil) - now.value
        : 0,
);
const unlocked = computed(
    () => props.consoleLock.twoFactorEnabled && unlockedMs.value > 0,
);
const countdown = computed(() => {
    const total = Math.max(0, Math.floor(unlockedMs.value / 1000));

    return `${Math.floor(total / 60)}:${String(total % 60).padStart(2, '0')}`;
});

const unlockCode = ref('');
const unlockError = ref<string | null>(null);
const unlocking = ref(false);

async function unlock(): Promise<void> {
    if (unlockCode.value.length !== 6 || unlocking.value) return;
    unlocking.value = true;
    unlockError.value = null;
    try {
        const response = await call('POST', '/console/unlock', {
            code: unlockCode.value,
        });
        if (!response.ok) {
            const errors = response.body.errors as
                | Record<string, string[]>
                | undefined;
            unlockError.value =
                errors?.code?.[0] ??
                (response.body.error as string | undefined) ??
                trans('Couldn’t unlock.');
            unlockCode.value = '';

            return;
        }
        unlockCode.value = '';
        router.reload({ only: ['consoleLock'] });
    } finally {
        unlocking.value = false;
    }
}

async function lockNow(): Promise<void> {
    await call('POST', '/console/lock');
    router.reload({ only: ['consoleLock'] });
}

function failed(error: unknown): string | null {
    if (error instanceof DatabaseError && error.code === 'console_locked') {
        router.reload({ only: ['consoleLock'] });

        return null;
    }

    return error instanceof Error ? error.message : trans('That didn’t work.');
}

const databases = ref<string[]>([]);
const database = ref<string | null>(null);
const tables = ref<Table[]>([]);
const loadingTables = ref(false);
const tablesError = ref<string | null>(null);
const tableFilter = ref('');

async function loadDatabases(): Promise<void> {
    loadingTables.value = true;
    tablesError.value = null;
    try {
        const result = await ask({ action: 'databases' });
        if (result.error) throw new DatabaseError(result.error);
        databases.value = result.rows.map((row) => row[0] ?? '');
        database.value =
            result.database && databases.value.includes(result.database)
                ? result.database
                : (databases.value[0] ?? null);
        await loadTables();
    } catch (error) {
        tablesError.value = failed(error);
    } finally {
        loadingTables.value = false;
    }
}

async function loadTables(): Promise<void> {
    loadingTables.value = true;
    tablesError.value = null;
    try {
        const result = await ask({
            action: 'tables',
            database: database.value,
        });
        if (result.error) throw new DatabaseError(result.error);
        tables.value = result.rows.map((row) => ({
            schema: row[0] ?? '',
            name: row[1] ?? '',
            kind: row[2] ?? 'table',
            rows: row[3] === null ? null : Number(row[3]),
            bytes: row[4] === null ? null : Number(row[4]),
        }));
    } catch (error) {
        tablesError.value = failed(error);
    } finally {
        loadingTables.value = false;
    }
}

const shownTables = computed(() => {
    const needle = tableFilter.value.trim().toLowerCase();

    return needle
        ? tables.value.filter((t) =>
              `${t.schema}.${t.name}`.toLowerCase().includes(needle),
          )
        : tables.value;
});

// Postgres tables outside public, and every MySQL table, are named with
// their schema only where it's needed to tell them apart.
function tableLabel(table: Table): string {
    return props.engine === 'postgres' && table.schema !== 'public'
        ? `${table.schema}.${table.name}`
        : table.name;
}

function quote(name: string): string {
    return props.engine === 'postgres'
        ? `"${name.replaceAll('"', '""')}"`
        : `\`${name.replaceAll('`', '``')}\``;
}

const query = ref('');
const allowChanges = ref(false);
const running = ref(false);
const result = ref<Result | null>(null);
const runError = ref<string | null>(null);
const tookMs = ref<number | null>(null);

async function run(): Promise<void> {
    if (running.value || query.value.trim() === '') return;
    running.value = true;
    runError.value = null;
    const started = Date.now();
    try {
        result.value = await ask({
            action: 'query',
            database: database.value,
            query: query.value,
            write: allowChanges.value,
        });
        tookMs.value = Date.now() - started;
    } catch (error) {
        result.value = null;
        runError.value = failed(error);
    } finally {
        running.value = false;
    }
}

function browse(table: Table): void {
    query.value = `SELECT * FROM ${quote(table.schema)}.${quote(table.name)} LIMIT 100;`;
    void run();
}

function onEditorKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
        event.preventDefault();
        void run();
    }
}

// ponytail: only the rows on screen; a full export is a dump, not a query.
function downloadCsv(shown: Result): void {
    const cell = (value: string | null): string =>
        value === null
            ? ''
            : /[",\r\n]/.test(value)
              ? `"${value.replaceAll('"', '""')}"`
              : value;
    const text = [shown.columns, ...shown.rows]
        .map((row) => row.map(cell).join(','))
        .join('\r\n');
    const url = URL.createObjectURL(new Blob([text], { type: 'text/csv' }));
    const link = document.createElement('a');
    link.href = url;
    link.download = `${shown.database ?? 'query'}.csv`;
    link.click();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}

function count(value: number | null): string {
    return value === null ? '—' : value.toLocaleString();
}

watch(
    unlocked,
    (open) => {
        if (open && props.available && databases.value.length === 0) {
            void loadDatabases();
        }
    },
    { immediate: true },
);

const field =
    'w-full rounded-lg border border-(--ds-border-strong) bg-(--ds-field) text-(--ds-text) outline-none placeholder:text-(--ds-text-faint) focus-visible:border-(--ds-border-focus)';
</script>

<template>
    <div class="flex flex-col gap-4">
        <div
            v-if="!available"
            class="ds-tile flex flex-col items-center gap-2 px-6 py-10 text-center"
        >
            <span class="ds-icon-chip" aria-hidden="true">
                <DatabaseIcon class="size-4.5" :stroke-width="2" />
            </span>
            <p class="max-w-md text-[13px]/5 text-(--ds-text-muted)">
                {{
                    $t(
                        'The database opens here while its machine is online and you can manage machines.',
                    )
                }}
            </p>
        </div>

        <div
            v-else-if="!unlocked"
            class="ds-tile flex flex-col items-center gap-4 px-6 py-10 text-center"
        >
            <span class="ds-icon-chip size-12! rounded-xl!" aria-hidden="true">
                <ShieldCheck class="size-5" :stroke-width="2" />
            </span>
            <template v-if="!consoleLock.twoFactorEnabled">
                <div class="flex max-w-md flex-col gap-1.5">
                    <h2 class="text-base font-semibold">
                        {{ $t('Databases need two-factor authentication') }}
                    </h2>
                    <p class="text-[13px]/5 text-(--ds-text-muted)">
                        {{
                            $t(
                                'A database holds your app’s data, so a password alone isn’t enough. Turn on two-factor for your account, then come back.',
                            )
                        }}
                    </p>
                </div>
                <AppButton variant="primary" size="sm" :href="securityEdit()">
                    {{ $t('Set up two-factor') }}
                </AppButton>
            </template>
            <form
                v-else
                class="flex flex-col items-center gap-4"
                @submit.prevent="unlock"
            >
                <div class="flex max-w-md flex-col gap-1.5">
                    <h2 class="text-base font-semibold">
                        {{ $t('Unlock to open the database') }}
                    </h2>
                    <p class="text-[13px]/5 text-(--ds-text-muted)">
                        {{
                            $t(
                                'Enter the 6-digit code from your authenticator app. Databases, files and the console stay unlocked for :minutes minutes on this device.',
                                { minutes: String(consoleLock.minutes ?? '') },
                            )
                        }}
                    </p>
                </div>
                <InputOTP
                    v-model="unlockCode"
                    :maxlength="6"
                    :disabled="unlocking"
                    @complete="unlock"
                >
                    <InputOTPGroup>
                        <InputOTPSlot
                            v-for="index in 6"
                            :key="index"
                            :index="index - 1"
                        />
                    </InputOTPGroup>
                </InputOTP>
                <InputError :message="unlockError ?? undefined" />
                <SubmitButton
                    :label="$t('Unlock')"
                    busy-label="Checking…"
                    :processing="unlocking"
                    :disabled="unlockCode.length !== 6"
                />
            </form>
        </div>

        <template v-else>
            <div
                class="flex flex-wrap items-center gap-x-3 gap-y-2 rounded-[10px] border border-(--ds-border) bg-(--ds-field)/50 px-3.5 py-2"
            >
                <ShieldCheck
                    class="size-4 shrink-0 text-(--ds-green)"
                    :stroke-width="2"
                    aria-hidden="true"
                />
                <p class="text-[13px]/5">
                    {{ $t('Unlocked with two-factor') + ' ' }}
                    <span class="text-(--ds-text-muted)"
                        >{{ $t('· locks in') }}
                        <span class="font-ui-mono tabular-nums">{{
                            countdown
                        }}</span></span
                    >
                </p>
                <AppButton
                    size="xs"
                    variant="quiet"
                    class="ml-auto"
                    @click="lockNow"
                >
                    <Lock class="size-3.5" :stroke-width="1.75" />
                    {{ $t('Lock now') }}
                </AppButton>
            </div>

            <div class="grid gap-4 lg:grid-cols-[16rem_minmax(0,1fr)]">
                <section
                    class="ds-tile flex max-h-[34rem] min-w-0 flex-col overflow-hidden"
                    :aria-label="$t('Tables')"
                >
                    <div
                        class="flex flex-col gap-2 border-b border-(--ds-divider) p-3"
                    >
                        <div class="flex items-center gap-2">
                            <SelectMenu
                                v-model="database"
                                class="min-w-0 grow"
                                :options="
                                    databases.map((name) => ({
                                        value: name,
                                        label: name,
                                    }))
                                "
                                :placeholder="$t('Database')"
                                :aria-label="$t('Database')"
                                :disabled="loadingTables"
                                @change="loadTables"
                            />
                            <AppButton
                                size="sm"
                                variant="quiet"
                                :disabled="loadingTables"
                                :aria-label="$t('Refresh tables')"
                                @click="loadTables"
                            >
                                <RefreshCw
                                    class="size-3.5"
                                    :class="loadingTables && 'animate-spin'"
                                    :stroke-width="1.75"
                                />
                            </AppButton>
                        </div>
                        <label
                            class="flex h-8 items-center gap-2 rounded-lg border border-(--ds-border-strong) bg-(--ds-field) px-2.5"
                        >
                            <Search
                                class="size-3.5 shrink-0 text-(--ds-text-muted)"
                                :stroke-width="1.6"
                            />
                            <span class="sr-only">{{
                                $t('Filter tables')
                            }}</span>
                            <input
                                v-model="tableFilter"
                                type="search"
                                :placeholder="$t('Filter tables')"
                                class="h-full min-w-0 grow bg-transparent text-[13px]/4 outline-none placeholder:text-(--ds-text-faint)"
                            />
                        </label>
                    </div>

                    <div class="min-h-0 grow overflow-y-auto py-1">
                        <p
                            v-if="tablesError"
                            class="px-3 py-3 text-[13px]/5 text-(--ds-red)"
                        >
                            {{ tablesError }}
                        </p>
                        <p
                            v-else-if="loadingTables && tables.length === 0"
                            class="flex items-center gap-2 px-3 py-3 text-[13px]/5 text-(--ds-text-muted)"
                        >
                            <LoaderCircle class="size-3.5 animate-spin" />
                            {{ $t('Looking for tables…') }}
                        </p>
                        <p
                            v-else-if="shownTables.length === 0"
                            class="px-3 py-3 text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{
                                tables.length
                                    ? $t('No tables match.')
                                    : $t('This database has no tables yet.')
                            }}
                        </p>
                        <button
                            v-for="table in shownTables"
                            :key="`${table.schema}.${table.name}`"
                            type="button"
                            class="flex w-full cursor-pointer items-center gap-2 px-3 py-1.5 text-left text-[13px]/5 transition-colors hover:bg-(--ds-row-hover) focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-(--ds-ring)"
                            :title="`${table.schema}.${table.name}`"
                            @click="browse(table)"
                        >
                            <span class="min-w-0 grow truncate font-ui-mono">{{
                                tableLabel(table)
                            }}</span>
                            <span
                                v-if="table.kind === 'view'"
                                class="shrink-0 text-xs text-(--ds-text-faint)"
                                >{{ $t('view') }}</span
                            >
                            <span
                                v-else
                                class="shrink-0 text-xs text-(--ds-text-faint) tabular-nums"
                                :title="bytes(table.bytes)"
                                >{{ count(table.rows) }}</span
                            >
                        </button>
                    </div>
                </section>

                <section class="flex min-w-0 flex-col gap-3">
                    <div class="ds-tile flex flex-col gap-0 overflow-hidden">
                        <label class="sr-only" for="database-query">{{
                            $t('Query')
                        }}</label>
                        <textarea
                            id="database-query"
                            v-model="query"
                            rows="5"
                            spellcheck="false"
                            :placeholder="
                                engine === 'postgres'
                                    ? 'SELECT * FROM users LIMIT 10;'
                                    : 'SELECT * FROM `users` LIMIT 10;'
                            "
                            class="min-h-24 w-full resize-y bg-transparent px-4 py-3 font-ui-mono text-[13px]/5 outline-none placeholder:text-(--ds-text-faint)"
                            @keydown="onEditorKeydown"
                        />
                        <div
                            class="flex flex-wrap items-center gap-3 border-t border-(--ds-divider) px-3 py-2"
                        >
                            <label
                                class="flex cursor-pointer items-center gap-2 text-[13px]/5"
                            >
                                <TickBox v-model="allowChanges" />
                                {{ $t('Allow changes') }}
                            </label>
                            <span
                                class="text-xs text-(--ds-text-muted)"
                                :class="allowChanges && 'text-(--ds-amber)!'"
                            >
                                {{
                                    allowChanges
                                        ? $t(
                                              'Queries can change and delete data.',
                                          )
                                        : $t('Read-only: nothing can change.')
                                }}
                            </span>
                            <AppButton
                                variant="primary"
                                size="sm"
                                class="ml-auto"
                                :disabled="running || query.trim() === ''"
                                @click="run"
                            >
                                <LoaderCircle
                                    v-if="running"
                                    class="size-3.5 animate-spin"
                                />
                                <Play
                                    v-else
                                    class="size-3.5"
                                    :stroke-width="2"
                                />
                                {{ $t('Run') }}
                                <kbd
                                    class="ml-0.5 font-ui text-[11px] opacity-70"
                                    aria-hidden="true"
                                    >⌘↵</kbd
                                >
                            </AppButton>
                        </div>
                    </div>

                    <p
                        v-if="runError"
                        class="rounded-[10px] bg-(--ds-red)/8 px-4 py-3 text-[13px]/5 text-(--ds-red) ring-1 ring-(--ds-red)/20"
                    >
                        {{ runError }}
                    </p>

                    <template v-else-if="result">
                        <pre
                            v-if="result.error"
                            class="overflow-x-auto rounded-[10px] bg-(--ds-red)/8 px-4 py-3 font-ui-mono text-[12.5px]/5 whitespace-pre-wrap text-(--ds-red) ring-1 ring-(--ds-red)/20"
                            >{{ result.error }}</pre>
                        <p
                            v-else-if="result.columns.length === 0"
                            class="ds-tile px-4 py-3 text-[13px]/5 text-(--ds-text-muted)"
                        >
                            {{
                                result.changed === null
                                    ? $t('Done. The query returned no rows.')
                                    : result.changed === 0
                                      ? $t('Done. No rows changed.')
                                      : result.changed === 1
                                        ? $t('Done. 1 row changed.')
                                        : $t('Done. :count rows changed.', {
                                              count: result.changed.toLocaleString(),
                                          })
                            }}
                        </p>
                        <div v-else class="flex min-w-0 flex-col gap-2">
                            <div class="flex items-center gap-2">
                                <p class="text-xs text-(--ds-text-muted)">
                                    {{
                                        result.truncated
                                            ? $t(
                                                  'The first :count rows. Add a LIMIT or a WHERE to see others.',
                                                  {
                                                      count: String(
                                                          result.rows.length,
                                                      ),
                                                  },
                                              )
                                            : result.rows.length === 1
                                              ? $t('1 row')
                                              : $t(':count rows', {
                                                    count: String(
                                                        result.rows.length,
                                                    ),
                                                })
                                    }}
                                    <span v-if="tookMs !== null">
                                        ·
                                        {{ (tookMs / 1000).toFixed(1) }} s</span
                                    >
                                </p>
                                <AppButton
                                    size="xs"
                                    variant="quiet"
                                    class="ml-auto"
                                    @click="downloadCsv(result)"
                                >
                                    <Download
                                        class="size-3.5"
                                        :stroke-width="1.75"
                                    />
                                    {{ $t('Download CSV') }}
                                </AppButton>
                            </div>
                            <div
                                class="ds-tile max-h-[30rem] overflow-auto"
                                role="region"
                                :aria-label="$t('Query result')"
                                tabindex="0"
                            >
                                <table
                                    class="w-full border-collapse text-left text-[12.5px]/5"
                                >
                                    <thead
                                        class="sticky top-0 bg-(--ds-subtle) text-xs text-(--ds-text-muted)"
                                    >
                                        <tr>
                                            <th
                                                v-for="(
                                                    column, i
                                                ) in result.columns"
                                                :key="i"
                                                class="border-b border-(--ds-divider) px-3 py-2 font-medium whitespace-nowrap"
                                            >
                                                {{ column }}
                                            </th>
                                        </tr>
                                    </thead>
                                    <tbody class="font-ui-mono">
                                        <tr
                                            v-for="(row, r) in result.rows"
                                            :key="r"
                                            class="border-b border-(--ds-divider) last:border-b-0 hover:bg-(--ds-row-hover)"
                                        >
                                            <td
                                                v-for="(value, c) in row"
                                                :key="c"
                                                class="max-w-80 truncate px-3 py-1.5 align-top whitespace-nowrap"
                                                :title="value ?? 'NULL'"
                                            >
                                                <span
                                                    v-if="value === null"
                                                    class="text-(--ds-text-faint) italic"
                                                    >NULL</span
                                                >
                                                <template v-else>{{
                                                    value
                                                }}</template>
                                            </td>
                                        </tr>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                    </template>

                    <p
                        v-else-if="!running"
                        class="text-[13px]/5 text-(--ds-text-muted)"
                    >
                        {{
                            $t(
                                'Pick a table to see its rows, or write a query.',
                            )
                        }}
                    </p>
                </section>
            </div>
        </template>
    </div>
</template>
