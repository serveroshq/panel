<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import DashButton from '@/components/dash/DashButton.vue';
import StatusBadge from '@/components/dash/StatusBadge.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import IntegrationLogo from '@/components/integrations/IntegrationLogo.vue';
import type { Integration } from '@/components/integrations/integrations';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { workspacePath } from '@/lib/workspaceRoute';

const { integration } = defineProps<{ integration: Integration }>();

defineEmits<{ act: [integration: Integration] }>();
</script>

<template>
    <li
        class="flex min-w-0 items-center gap-3.5 border-t-[0.5px] border-edge-tray-panel px-3.5 py-3 first:border-t-0"
    >
        <IntegrationLogo
            :logo="integration.logo"
            :initials="integration.initials"
        />
        <div class="flex min-w-0 flex-1 flex-col gap-[3px]">
            <div class="flex min-w-0 items-center gap-2">
                <span
                    class="truncate text-[13px]/[18px] font-medium text-fg-primary"
                    >{{ integration.name }}</span
                >
                <StatusBadge
                    v-if="integration.connected"
                    status="live"
                    :label="$t('Connected')"
                />
            </div>
            <p class="text-[12px]/[17px] text-fg-muted">
                {{ integration.description }}
            </p>
        </div>
        <ul
            class="flex w-[196px] shrink-0 flex-wrap items-start gap-1 max-md:hidden"
            :aria-label="$t('Works with')"
        >
            <li
                v-for="tag in integration.tags"
                :key="tag"
                class="rounded-[5px] border-[0.5px] border-[#dcdcdc] bg-[#f2f2f2] px-[7px] py-0.5 text-[11px]/4 whitespace-nowrap text-fg-secondary dark:border-[#383838] dark:bg-surface-badge"
            >
                {{ tag }}
            </li>
        </ul>
        <div class="flex w-[92px] shrink-0 justify-end max-sm:w-auto">
            <template v-if="integration.action">
                <DropdownMenu v-if="integration.action.accounts?.length">
                    <DropdownMenuTrigger as-child>
                        <DashButton
                            :label="integration.action.label"
                            :aria-label="`${integration.action.label} ${integration.name}`"
                        />
                    </DropdownMenuTrigger>
                    <DropdownMenuContent
                        class="ds-modal min-w-52 font-ui"
                        align="end"
                    >
                        <DropdownMenuItem
                            v-for="account in integration.action.accounts"
                            :key="account.id"
                            as-child
                        >
                            <Link :href="workspacePath(account.href)">
                                <IntegrationLogo
                                    :logo="integration.logo"
                                    small
                                />
                                {{ account.label }}
                            </Link>
                        </DropdownMenuItem>
                        <DropdownMenuSeparator />
                        <DropdownMenuItem @select="$emit('act', integration)">
                            <FilledIcon name="plus-fill" />
                            {{ $t('Connect another') }}
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
                <DashButton
                    v-else-if="
                        integration.action.href &&
                        !integration.action.unavailable
                    "
                    :href="workspacePath(integration.action.href)"
                    :label="integration.action.label"
                    :aria-label="`${integration.action.label} ${integration.name}`"
                />
                <DashButton
                    v-else
                    :label="integration.action.label"
                    :aria-label="`${integration.action.label} ${integration.name}`"
                    aria-haspopup="dialog"
                    @click="$emit('act', integration)"
                />
            </template>
        </div>
    </li>
</template>
