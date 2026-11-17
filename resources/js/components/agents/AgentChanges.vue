<script setup lang="ts">
import { ChevronRight, FileDiff, GitPullRequest } from '@lucide/vue';
import { computed, reactive, ref, watch } from 'vue';
import type { AgentDetail } from './agentTasks';

type ChangedFile = {
    path: string;
    status: string;
    additions: number;
    deletions: number;
    patch: string | null;
};

const props = defineProps<{ taskId: number; agent: AgentDetail | null }>();

const files = ref<ChangedFile[]>([]);
const loading = ref(false);
const unavailable = ref(false);
const open = reactive<Record<string, boolean>>({});

async function load(): Promise<void> {
    const agent = props.agent;
    files.value = [];
    unavailable.value = false;
    if (!agent?.pr) return;
    loading.value = true;
    try {
        const response = await fetch(
            `/agents/${props.taskId}/runs/${agent.run}/changes`,
            {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            },
        );
        const data = response.ok ? await response.json() : null;
        if (props.agent?.run !== agent.run) return;
        files.value = data?.files ?? [];
        unavailable.value = !data || data.unavailable === true;
        for (const file of files.value) {
            open[file.path] ??= (file.patch?.split('\n').length ?? 0) < 400;
        }
    } catch {
        unavailable.value = true;
    } finally {
        loading.value = false;
    }
}

// Only when it's another agent or another PR, not on every poll of the page.
watch([() => props.agent?.run, () => props.agent?.pr], load, {
    immediate: true,
});

const totals = computed(() => ({
    additions: files.value.reduce((n, f) => n + f.additions, 0),
    deletions: files.value.reduce((n, f) => n + f.deletions, 0),
}));

type Line = { kind: 'add' | 'del' | 'ctx' | 'hunk'; text: string; n: string };

function lines(patch: string): Line[] {
    const out: Line[] = [];
    let oldN = 0;
    let newN = 0;
    for (const raw of patch.split('\n')) {
        const hunk = raw.match(/^@@ -(\d+)(?:,\d+)? \+(\d+)(?:,\d+)? @@/);
        if (hunk) {
            oldN = Number(hunk[1]);
            newN = Number(hunk[2]);
            out.push({ kind: 'hunk', text: raw, n: '' });
        } else if (raw.startsWith('+')) {
            out.push({ kind: 'add', text: raw.slice(1), n: String(newN++) });
        } else if (raw.startsWith('-')) {
            out.push({ kind: 'del', text: raw.slice(1), n: String(oldN++) });
        } else if (!raw.startsWith('\\')) {
            oldN++;
            out.push({ kind: 'ctx', text: raw.slice(1), n: String(newN++) });
        }
    }

    return out;
}

const tone: Record<Line['kind'], string> = {
    add: 'bg-(--ds-green)/10 text-(--ds-text)',
    del: 'bg-(--ds-red)/10 text-(--ds-text)',
    ctx: 'text-(--ds-text-secondary)',
    hunk: 'bg-(--ds-blue)/8 text-(--ds-text-muted)',
};
const mark: Record<Line['kind'], string> = {
    add: '+',
    del: '-',
    ctx: ' ',
    hunk: '',
};

function split(path: string): [string, string] {
    const at = path.lastIndexOf('/');

    return at === -1 ? ['', path] : [path.slice(0, at + 1), path.slice(at + 1)];
}

const statusLetter: Record<string, string> = {
    added: 'A',
    removed: 'D',
    modified: 'M',
    renamed: 'R',
};
</script>

<template>
    <div class="flex min-h-0 flex-1 flex-col">
        <div
            v-if="!agent?.pr || (!loading && !files.length)"
            class="flex flex-1 flex-col items-center justify-center gap-4 px-6 text-center"
        >
            <div class="ds-icon-chip" aria-hidden="true">
                <FileDiff class="size-4.5" :stroke-width="1.75" />
            </div>
            <p class="text-[15px]/6 font-medium text-(--ds-text)">
                {{
                    !agent
                        ? $t('No changes yet.')
                        : agent.state === 'running'
                          ? $t(
                                'The changes show here once it opens a pull request.',
                            )
                          : agent.pr && unavailable
                            ? $t('GitHub didn’t send the changes.')
                            : $t('This agent didn’t change any code.')
                }}
            </p>
            <a
                v-if="agent?.pr && unavailable"
                :href="`${agent.pr}/files`"
                target="_blank"
                rel="noopener"
                class="text-[13px]/5 text-(--ds-blue) hover:underline hover:underline-offset-4"
                >{{ $t('Open them on GitHub') }}</a
            >
        </div>

        <div
            v-else-if="loading"
            class="flex flex-col gap-2 p-4"
            aria-busy="true"
        >
            <div
                v-for="i in 3"
                :key="i"
                class="h-9 animate-pulse rounded-[9px] bg-(--ds-subtle)"
            />
        </div>

        <div v-else class="min-h-0 flex-1 overflow-y-auto">
            <div
                class="sticky top-0 z-10 flex items-center gap-2 border-b border-(--ds-divider) bg-(--ds-panel) px-4 py-2.5 text-[12.5px]/4"
            >
                <span class="font-medium text-(--ds-text)">{{
                    files.length === 1
                        ? $t('1 file changed')
                        : $t(':count files changed', {
                              count: String(files.length),
                          })
                }}</span>
                <span class="text-(--ds-green) tabular-nums"
                    >+{{ totals.additions }}</span
                >
                <span class="text-(--ds-red) tabular-nums"
                    >-{{ totals.deletions }}</span
                >
                <a
                    :href="agent.pr"
                    target="_blank"
                    rel="noopener"
                    class="ml-auto inline-flex items-center gap-1 text-(--ds-text-muted) hover:text-(--ds-text)"
                >
                    <GitPullRequest class="size-3.5" :stroke-width="1.75" />
                    {{ $t('Review on GitHub') }}
                </a>
            </div>

            <section
                v-for="file in files"
                :key="file.path"
                class="border-b border-(--ds-divider)"
            >
                <button
                    type="button"
                    class="flex w-full cursor-pointer items-center gap-2 px-4 py-2.5 text-left text-[12.5px]/4 hover:bg-(--ds-row-hover)"
                    :aria-expanded="open[file.path]"
                    @click="open[file.path] = !open[file.path]"
                >
                    <ChevronRight
                        class="size-3.5 shrink-0 text-(--ds-text-faint) transition-transform"
                        :class="{ 'rotate-90': open[file.path] }"
                        :stroke-width="2"
                    />
                    <span
                        class="w-3 shrink-0 font-ui-mono text-[11px] text-(--ds-text-faint)"
                        >{{ statusLetter[file.status] ?? 'M' }}</span
                    >
                    <span class="min-w-0 truncate font-ui-mono text-[12px]">
                        <span class="text-(--ds-text-muted)">{{
                            split(file.path)[0]
                        }}</span
                        ><span class="text-(--ds-text)">{{
                            split(file.path)[1]
                        }}</span>
                    </span>
                    <span
                        class="ml-auto shrink-0 text-(--ds-green) tabular-nums"
                        >+{{ file.additions }}</span
                    >
                    <span class="shrink-0 text-(--ds-red) tabular-nums"
                        >-{{ file.deletions }}</span
                    >
                </button>
                <div
                    v-if="open[file.path]"
                    class="overflow-x-auto pb-2 font-ui-mono text-[11.5px]/5"
                >
                    <p
                        v-if="!file.patch"
                        class="px-4 py-1 font-ui text-[12px] text-(--ds-text-muted)"
                    >
                        {{ $t('Too big or binary to show here.') }}
                    </p>
                    <table v-else class="w-full border-collapse">
                        <tbody>
                            <tr
                                v-for="(line, i) in lines(file.patch)"
                                :key="i"
                                :class="tone[line.kind]"
                            >
                                <td
                                    class="w-10 pr-2 text-right align-top text-(--ds-text-faint) select-none"
                                >
                                    {{ line.n }}
                                </td>
                                <td
                                    class="w-4 align-top text-(--ds-text-faint) select-none"
                                >
                                    {{ mark[line.kind] }}
                                </td>
                                <td
                                    class="pr-4 whitespace-pre"
                                    v-text="line.text"
                                />
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>
</template>
