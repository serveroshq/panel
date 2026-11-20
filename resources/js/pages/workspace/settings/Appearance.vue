<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { SolarLayoutDashboard as LayoutDashboard } from '@/lib/solarIcons';
import Tile from '@/components/workspace/Tile.vue';
import { useAppearance } from '@/composables/useAppearance';
import { useNavLayout } from '@/composables/useNavLayout';
import { forcedAppearance } from '@/lib/workspaceTheme';
import type { WorkspaceTheme } from '@/lib/workspaceTheme';

const { appearance, updateAppearance } = useAppearance();
const { layout, setLayout } = useNavLayout();
const page = usePage<{ workspaceTheme?: WorkspaceTheme | null }>();
const forced = computed(() =>
    forcedAppearance(page.props.workspaceTheme?.css ?? null),
);
</script>

<template>
    <Head :title="$t('Appearance')" />

    <Tile
        id="appearance"
        :title="$t('Appearance')"
        :description="$t('How the dashboard looks for you.')"
        :icon="LayoutDashboard"
    >
        <div class="flex flex-col gap-6">
            <div class="flex flex-col gap-2">
                <span class="text-[13px]/5 font-medium">{{ $t('Theme') }}</span>
                <div
                    class="ds-segments self-start"
                    role="group"
                    :aria-label="$t('Theme')"
                >
                    <button
                        v-for="[value, label] in [
                            ['light', 'Light'],
                            ['dark', 'Dark'],
                            ['system', 'System'],
                        ] as const"
                        :key="value"
                        type="button"
                        class="cursor-pointer"
                        :aria-pressed="appearance === value"
                        @click="updateAppearance(value)"
                    >
                        {{ $t(label) }}
                    </button>
                </div>
                <span v-if="forced" class="text-xs/4 text-(--ds-text-muted)">
                    {{
                        forced === 'dark'
                            ? $t(
                                  'This workspace’s theme is made for dark mode, so the dashboard stays dark here.',
                              )
                            : $t(
                                  'This workspace’s theme is made for light mode, so the dashboard stays light here.',
                              )
                    }}
                </span>
            </div>
            <div class="flex flex-col gap-2">
                <span class="text-[13px]/5 font-medium">{{
                    $t('Navigation')
                }}</span>
                <div
                    class="ds-segments self-start"
                    role="group"
                    :aria-label="$t('Navigation')"
                >
                    <button
                        v-for="[value, label] in [
                            ['top', 'Top bar'],
                            ['sidebar', 'Sidebar'],
                        ] as const"
                        :key="value"
                        type="button"
                        class="cursor-pointer"
                        :aria-pressed="layout === value"
                        @click="setLayout(value)"
                    >
                        {{ $t(label) }}
                    </button>
                </div>
                <span class="text-xs/4 text-(--ds-text-muted)">
                    {{
                        $t(
                            'Where the dashboard’s sections go: tabs along the top, or a sidebar down the left. Phones always use the menu.',
                        )
                    }}
                </span>
            </div>
        </div>
    </Tile>
</template>
