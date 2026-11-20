<script setup lang="ts">
// A miniature of the dashboard in one theme's colours.
const { colours } = defineProps<{ colours: Record<string, string> }>();

const rows = [
    { status: 'green', width: '42%' },
    { status: 'green', width: '68%' },
    { status: 'amber', width: '24%' },
];
</script>

<template>
    <div
        class="flex size-full gap-1 p-1"
        :style="{ background: colours.panel, color: colours.text }"
    >
        <div class="flex w-[22%] shrink-0 flex-col gap-[3px] px-1 pt-1.5">
            <span
                class="mb-1 h-[5px] w-[60%] rounded-full"
                :style="{ background: colours.text }"
            />
            <span
                v-for="n in 5"
                :key="n"
                class="flex h-[9px] items-center rounded-[3px] px-1"
                :style="{
                    background:
                        n === 2 ? colours['nav-active-bg'] : 'transparent',
                    boxShadow:
                        n === 2 ? `0 0 0 1px ${colours.border}` : undefined,
                }"
            >
                <span
                    class="h-[3px] rounded-full"
                    :style="{
                        width: `${[70, 56, 64, 48, 60][n - 1]}%`,
                        background:
                            n === 2 ? colours.text : colours['text-faint'],
                    }"
                />
            </span>
        </div>

        <div
            class="flex min-w-0 grow flex-col gap-[5px] rounded-[5px] p-2"
            :style="{
                background: colours.frame,
                boxShadow: `0 0 0 1px ${colours.border}`,
            }"
        >
            <div class="flex items-center justify-between">
                <span
                    class="h-[5px] w-[34%] rounded-full"
                    :style="{ background: colours.text }"
                />
                <span
                    class="h-[9px] w-[22%] rounded-[3px]"
                    :style="{ background: colours['primary-bg'] }"
                />
            </div>
            <div class="grid grid-cols-3 gap-[4px]">
                <span
                    v-for="n in 3"
                    :key="n"
                    class="flex h-[18px] flex-col justify-center gap-[3px] rounded-[3px] px-1"
                    :style="{
                        background: colours.card,
                        boxShadow: `0 0 0 1px ${colours.border}`,
                    }"
                >
                    <span
                        class="h-[2px] w-[50%] rounded-full"
                        :style="{ background: colours['text-faint'] }"
                    />
                    <span
                        class="h-[4px] w-[30%] rounded-full"
                        :style="{ background: colours.text }"
                    />
                </span>
            </div>
            <div
                class="flex grow flex-col overflow-hidden rounded-[3px]"
                :style="{
                    background: colours.card,
                    boxShadow: `0 0 0 1px ${colours.border}`,
                }"
            >
                <span
                    v-for="(row, n) in rows"
                    :key="n"
                    class="flex h-1/3 items-center gap-1.5 px-1.5"
                    :style="{
                        borderTop: n > 0 ? `1px solid ${colours.border}` : '',
                    }"
                >
                    <span
                        class="size-[4px] shrink-0 rounded-full"
                        :style="{ background: colours[row.status] }"
                    />
                    <span
                        class="h-[3px] w-[30%] rounded-full"
                        :style="{ background: colours['text-muted'] }"
                    />
                    <span
                        class="ml-auto h-[3px] w-[28%] overflow-hidden rounded-full"
                        :style="{ background: colours['chart-empty'] }"
                    >
                        <span
                            class="block h-full"
                            :style="{
                                width: row.width,
                                background:
                                    row.status === 'amber'
                                        ? colours.amber
                                        : colours.chart,
                            }"
                        />
                    </span>
                </span>
            </div>
        </div>
    </div>
</template>
