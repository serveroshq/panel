<script setup lang="ts">
import { computed, ref } from 'vue';
import { SelectMenu } from '@/components/ui/select';
import type { SelectMenuOption } from '@/components/ui/select';
import AppButton from '@/components/workspace/AppButton.vue';
import { errorMessage, failIfBad } from '@/lib/errors';

const props = defineProps<{
    machineId: number;
    machineName: string;
    targets: { id: number; name: string }[];
}>();

type Check = {
    ready: boolean;
    apps: { name: string; source: string; domains: string[]; data: string[] }[];
    blocking: string[];
    warnings: string[];
};

const target = ref<number | null>(props.targets[0]?.id ?? null);
const check = ref<Check | null>(null);
const checking = ref(false);
const problem = ref<string | null>(null);

const options = computed<SelectMenuOption<number>[]>(() =>
    props.targets.map((machine) => ({
        value: machine.id,
        label: machine.name,
    })),
);
const targetName = computed(
    () =>
        props.targets.find((machine) => machine.id === target.value)?.name ??
        '',
);

function choose(value: number): void {
    target.value = value;
    check.value = null;
    problem.value = null;
}

async function run(): Promise<void> {
    if (target.value === null) return;
    checking.value = true;
    problem.value = null;
    try {
        const response = await fetch(
            `/machines/${props.machineId}/move-check`,
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
                body: JSON.stringify({ to: target.value }),
            },
        );
        await failIfBad(response);
        check.value = (await response.json()) as Check;
    } catch (e) {
        problem.value = errorMessage(e);
    } finally {
        checking.value = false;
    }
}
</script>

<template>
    <section class="ds-tile" :aria-label="$t('Move to another machine')">
        <div
            class="flex flex-wrap items-center justify-between gap-4 px-5 py-4"
        >
            <div class="flex max-w-xl flex-col gap-0.5">
                <h2 class="text-[13px]/5 font-medium">
                    {{ $t('Move to another machine') }}
                </h2>
                <p class="text-[13px]/5 text-(--ds-text-muted)">
                    {{
                        ' ' +
                        $t(
                            'The machine file lists the apps ServerOS deployed on :name and their settings, without their values. Check what a move to another machine would take. Nothing is changed on either machine.',
                            { name: machineName },
                        ) +
                        ' '
                    }}
                </p>
            </div>
            <a
                :href="`/machines/${machineId}/machine-file`"
                class="ds-raised-button inline-flex h-8 items-center rounded-md px-3 text-[13px] font-medium"
                download
            >
                {{ $t('Download machine file') }}
            </a>
        </div>
        <div
            class="flex flex-wrap items-center gap-3 border-t border-(--ds-border) px-5 py-3"
        >
            <template v-if="targets.length > 0">
                <SelectMenu
                    :model-value="target"
                    :options="options"
                    class="ds-raised-button h-9 w-full text-[13px] sm:w-60"
                    :aria-label="$t('Machine to move to')"
                    :disabled="checking"
                    @update:model-value="choose"
                />
                <AppButton
                    size="sm"
                    :disabled="checking || target === null"
                    @click="run"
                >
                    {{ checking ? $t('Checking…') : $t('Check the move') }}
                </AppButton>
            </template>
            <p v-else class="text-[13px]/5 text-(--ds-text-muted)">
                {{
                    $t('Add another machine to this workspace to check a move.')
                }}
            </p>
        </div>
        <p
            v-if="problem"
            class="border-t border-(--ds-border) px-5 py-3 text-[13px]/5 text-(--ds-red)"
            role="alert"
        >
            {{ problem }}
        </p>
        <div
            v-if="check"
            class="flex flex-col gap-3 border-t border-(--ds-border) px-5 py-4 text-[13px]/5"
            role="status"
        >
            <p
                class="font-medium"
                :class="check.ready ? '' : 'text-(--ds-red)'"
            >
                {{
                    check.ready
                        ? $t('Nothing blocks a move to :to.', {
                              to: targetName,
                          })
                        : $t('This move can’t start yet.')
                }}
            </p>
            <ul
                v-if="check.blocking.length"
                class="flex list-disc flex-col gap-1 pl-5 text-(--ds-red)"
            >
                <li v-for="line in check.blocking" :key="line">{{ line }}</li>
            </ul>
            <ul
                v-if="check.warnings.length"
                class="flex list-disc flex-col gap-1 pl-5 text-(--ds-text-muted)"
            >
                <li v-for="line in check.warnings" :key="line">{{ line }}</li>
            </ul>
            <p class="text-(--ds-text-muted)">
                {{
                    $t(
                        'Apps aren’t moved for you yet: this check and the machine file show what a move would take.',
                    )
                }}
            </p>
        </div>
    </section>
</template>
