<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { SelectMenu } from '@/components/ui/select';
import type { SelectMenuOption } from '@/components/ui/select';
import AppButton from '@/components/workspace/AppButton.vue';
import { errorMessage, failIfBad } from '@/lib/errors';

type Check = {
    ready: boolean;
    apps: { name: string; source: string; domains: string[]; data: string[] }[];
    blocking: string[];
    warnings: string[];
};

export type Move = {
    id: number;
    state: string;
    round: number;
    error: string | null;
    from: { id: number; name: string };
    to: { id: number; name: string; address: string | null };
    domains: string[];
    stopped: number;
    firewall: { rule: string; state: string; error: string | null }[];
    tunnel: string | null;
    apps: {
        name: string;
        state: string;
        error: string | null;
        copies: {
            container: string;
            method: string;
            state: string;
            bytes: number | null;
            error: string | null;
        }[];
    }[];
    can: {
        switch: boolean;
        rollBack: boolean;
        finish: boolean;
        cancel: boolean;
        retry: boolean;
    };
};

type Step = 'switch' | 'rollback' | 'finish' | 'cancel' | 'retry';

const props = defineProps<{
    machineId: number;
    machineName: string;
    targets: { id: number; name: string }[];
    move: Move | null;
}>();

const target = ref<number | null>(props.targets[0]?.id ?? null);
const check = ref<Check | null>(null);
const current = ref<Move | null>(props.move);
const busy = ref(false);
const problem = ref<string | null>(null);
const confirming = ref<'start' | Step | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

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
const moving = computed(
    () =>
        current.value !== null &&
        ['copying', 'ready', 'switching', 'switched', 'failed'].includes(
            current.value.state,
        ),
);

const STATES: Record<string, string> = {
    copying: 'Copying',
    ready: 'Ready to switch over',
    switching: 'Switching over',
    switched: 'Switched over',
    completed: 'Finished',
    rolled_back: 'Rolled back',
    cancelled: 'Cancelled',
    failed: 'Stopped by a problem',
    deploying: 'Deploying',
    deployed: 'Deployed',
    deploying_domains: 'Adding its domains',
    waiting: 'Waiting',
    sending: 'Sending',
    sent: 'Sent',
    restoring: 'Restoring',
    copied: 'Copied',
    opening: 'Opening',
    opened: 'Opened',
};

function label(state: string): string {
    return trans(STATES[state] ?? state);
}

function size(bytes: number | null): string {
    if (bytes === null) return '';
    const units = ['B', 'KiB', 'MiB', 'GiB', 'TiB'];
    let n = bytes;
    let i = 0;
    while (n >= 1024 && i < units.length - 1) {
        n /= 1024;
        i++;
    }
    return `${n.toFixed(i >= 3 ? 1 : 0)} ${units[i]}`;
}

async function request(url: string, body?: object): Promise<unknown> {
    const response = await fetch(url, {
        method: body === undefined ? 'GET' : 'POST',
        credentials: 'same-origin',
        cache: 'no-store',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN':
                document.querySelector<HTMLMetaElement>(
                    'meta[name="csrf-token"]',
                )?.content ?? '',
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
    await failIfBad(response);
    return response.json();
}

function choose(value: number): void {
    target.value = value;
    check.value = null;
    problem.value = null;
    confirming.value = null;
}

async function run(action: () => Promise<void>): Promise<void> {
    busy.value = true;
    problem.value = null;
    try {
        await action();
    } catch (e) {
        problem.value = errorMessage(e);
    } finally {
        busy.value = false;
    }
}

function runCheck(): Promise<void> {
    return run(async () => {
        check.value = (await request(
            `/machines/${props.machineId}/move-check`,
            { to: target.value },
        )) as Check;
    });
}

function start(): Promise<void> {
    return run(async () => {
        current.value = (await request(`/machines/${props.machineId}/moves`, {
            to: target.value,
        })) as Move;
        check.value = null;
        confirming.value = null;
    });
}

function step(name: Step): Promise<void> {
    const move = current.value;
    if (move === null) return Promise.resolve();
    return run(async () => {
        current.value = (await request(
            `/machines/${props.machineId}/moves/${move.id}/${name}`,
            {},
        )) as Move;
        confirming.value = null;
    });
}

function follow(): void {
    clearTimeout(timer);
    const move = current.value;
    if (move === null || !['copying', 'switching'].includes(move.state)) {
        return;
    }
    timer = setTimeout(async () => {
        try {
            current.value = (await request(
                `/machines/${props.machineId}/moves/${move.id}`,
            )) as Move;
        } catch {
            // Try again on the next round.
        }
        follow();
    }, 5000);
}

watch(() => current.value?.state, follow, { immediate: true });
onBeforeUnmount(() => clearTimeout(timer));
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
                            'Deploys the apps ServerOS runs on :name to another machine and copies their data. :name keeps running until you switch over, and stays there to roll back to.',
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
            v-if="!moving"
            class="flex flex-wrap items-center gap-3 border-t border-(--ds-border) px-5 py-3"
        >
            <template v-if="targets.length > 0">
                <SelectMenu
                    :model-value="target"
                    :options="options"
                    class="ds-raised-button h-9 w-full text-[13px] sm:w-60"
                    :aria-label="$t('Machine to move to')"
                    :disabled="busy"
                    @update:model-value="choose"
                />
                <AppButton
                    size="sm"
                    :disabled="busy || target === null"
                    @click="runCheck"
                >
                    {{ $t('Check the move') }}
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
            v-if="check && !moving"
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
            <template v-if="check.ready">
                <p v-if="confirming === 'start'" class="text-(--ds-text-muted)">
                    {{
                        $t(
                            'Each app is deployed on :to without its domains, and its data is copied there. Nothing on :from changes until you switch over.',
                            { to: targetName, from: machineName },
                        )
                    }}
                </p>
                <div class="flex gap-2">
                    <AppButton
                        v-if="confirming !== 'start'"
                        size="sm"
                        :disabled="busy"
                        @click="confirming = 'start'"
                    >
                        {{ $t('Start the move') }}
                    </AppButton>
                    <template v-else>
                        <AppButton
                            size="sm"
                            variant="primary"
                            :disabled="busy"
                            @click="start"
                        >
                            {{ $t('Yes, start moving') }}
                        </AppButton>
                        <AppButton
                            size="sm"
                            :disabled="busy"
                            @click="confirming = null"
                        >
                            {{ $t('Not now') }}
                        </AppButton>
                    </template>
                </div>
            </template>
        </div>

        <div
            v-if="current"
            class="flex flex-col gap-3 border-t border-(--ds-border) px-5 py-4 text-[13px]/5"
            role="status"
            aria-live="polite"
        >
            <p class="font-medium">
                {{
                    $t(':from to :to: :state', {
                        from: current.from.name,
                        to: current.to.name,
                        state: label(current.state),
                    })
                }}
            </p>
            <p v-if="current.error" class="text-(--ds-red)">
                {{ current.error }}
            </p>

            <ul class="flex flex-col gap-2">
                <li
                    v-for="app in current.apps"
                    :key="app.name"
                    class="flex flex-col gap-0.5"
                >
                    <span>
                        <span class="font-medium">{{ app.name }}</span>
                        <span class="text-(--ds-text-muted)">
                            · {{ label(app.state) }}</span
                        >
                    </span>
                    <span
                        v-for="copy in app.copies"
                        :key="copy.container"
                        class="pl-3 text-(--ds-text-muted) tabular-nums"
                    >
                        {{ copy.container }} ({{ copy.method }}):
                        {{ label(copy.state) }}
                        <template v-if="copy.bytes !== null">
                            · {{ size(copy.bytes) }}</template
                        >
                    </span>
                    <span v-if="app.error" class="pl-3 text-(--ds-red)">{{
                        app.error
                    }}</span>
                    <template v-for="copy in app.copies" :key="copy.container">
                        <span v-if="copy.error" class="pl-3 text-(--ds-red)">{{
                            copy.error
                        }}</span>
                    </template>
                </li>
            </ul>

            <p
                v-if="current.firewall.length"
                class="text-(--ds-text-muted) tabular-nums"
            >
                {{ $t('Ports opened on :to:', { to: current.to.name }) }}
                <template
                    v-for="(entry, i) in current.firewall"
                    :key="entry.rule"
                >
                    {{ i > 0 ? ', ' : '' }}{{ entry.rule }} ({{
                        label(entry.state)
                    }})
                </template>
            </p>
            <p
                v-if="current.firewall.some((e) => e.error)"
                class="text-(--ds-red)"
            >
                {{
                    $t(
                        'Some ports couldn’t be opened on :to. Open them in its firewall yourself.',
                        { to: current.to.name },
                    )
                }}
            </p>

            <p
                v-if="
                    current.state === 'switched' && current.tunnel === 'moved'
                "
                class="text-(--ds-text-muted)"
            >
                {{
                    $t(
                        'Traffic now goes through the Cloudflare Tunnel on :to, so DNS doesn’t need changing. :from’s apps stay stopped, ready to roll back to.',
                        { to: current.to.name, from: current.from.name },
                    )
                }}
            </p>
            <p
                v-else-if="
                    current.state === 'switched' && current.domains.length
                "
                class="text-(--ds-text-muted)"
            >
                {{
                    $t(
                        'Point :domains at :address (:to). Each certificate is issued once its domain points there. :from’s apps stay stopped, ready to roll back to.',
                        {
                            domains: current.domains.join(', '),
                            address: current.to.address ?? '',
                            to: current.to.name,
                            from: current.from.name,
                        },
                    )
                }}
            </p>

            <p v-if="confirming === 'switch'" class="text-(--ds-text-muted)">
                {{
                    $t(
                        'This stops :from’s apps (its databases keep running), copies their data to :to again and gives the apps their domains there. They’re down from now until the domains point at :to. You’ll get alerts that :from’s apps stopped. Rolling back starts them again, but anything written on :to after the switch-over stays there.',
                        { from: current.from.name, to: current.to.name },
                    )
                }}
            </p>
            <p v-if="confirming === 'rollback'" class="text-(--ds-text-muted)">
                {{
                    $t(
                        'This starts :from’s apps again and takes the domains off the copies on :to. Anything written on :to since the switch-over isn’t copied back.',
                        { from: current.from.name, to: current.to.name },
                    )
                }}
            </p>
            <p v-if="confirming === 'cancel'" class="text-(--ds-text-muted)">
                {{
                    $t(
                        'The copies already on :to stay there. Remove them from :to if you don’t want them.',
                        { to: current.to.name },
                    )
                }}
            </p>

            <div class="flex flex-wrap gap-2">
                <template v-if="confirming === null">
                    <AppButton
                        v-if="current.can.switch"
                        size="sm"
                        variant="primary"
                        :disabled="busy"
                        @click="confirming = 'switch'"
                    >
                        {{ $t('Switch over') }}
                    </AppButton>
                    <AppButton
                        v-if="current.can.finish"
                        size="sm"
                        variant="primary"
                        :disabled="busy"
                        @click="step('finish')"
                    >
                        {{ $t('Finish the move') }}
                    </AppButton>
                    <AppButton
                        v-if="current.can.retry"
                        size="sm"
                        :disabled="busy"
                        @click="step('retry')"
                    >
                        {{ $t('Try again') }}
                    </AppButton>
                    <AppButton
                        v-if="current.can.rollBack"
                        size="sm"
                        :disabled="busy"
                        @click="confirming = 'rollback'"
                    >
                        {{ $t('Roll back') }}
                    </AppButton>
                    <AppButton
                        v-if="current.can.cancel"
                        size="sm"
                        :disabled="busy"
                        @click="confirming = 'cancel'"
                    >
                        {{ $t('Cancel the move') }}
                    </AppButton>
                </template>
                <template v-else-if="confirming !== 'start'">
                    <AppButton
                        size="sm"
                        variant="primary"
                        :disabled="busy"
                        @click="step(confirming as Step)"
                    >
                        {{
                            confirming === 'switch'
                                ? $t('Yes, switch over')
                                : confirming === 'rollback'
                                  ? $t('Yes, roll back')
                                  : $t('Yes, cancel it')
                        }}
                    </AppButton>
                    <AppButton
                        size="sm"
                        :disabled="busy"
                        @click="confirming = null"
                    >
                        {{ $t('Not now') }}
                    </AppButton>
                </template>
            </div>
        </div>
    </section>
</template>
