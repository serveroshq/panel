<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AppButton from '@/components/workspace/AppButton.vue';
import { appButtonClasses } from '@/lib/appButton';
import type { WorkspaceTheme } from '@/lib/workspaceTheme';

const page = usePage<{ workspaceTheme?: WorkspaceTheme | null }>();
const editClasses = appButtonClasses('secondary', 'sm');

const theme = computed(() => page.props.workspaceTheme ?? null);
const onThemePage = computed(
    () => page.component === 'workspace/settings/Theme',
);
const busy = ref(false);

const shown = computed(
    () =>
        theme.value !== null &&
        !onThemePage.value &&
        (theme.value.draft || theme.value.off),
);

function act(method: 'post' | 'delete', url: string): void {
    busy.value = true;
    router.visit(url, {
        method,
        preserveScroll: true,
        preserveState: true,
        onFinish: () => (busy.value = false),
    });
}

function turnBackOn(): void {
    window.location.href = `${window.location.pathname}?theme=on`;
}
</script>

<template>
    <div
        v-if="shown && theme"
        role="status"
        class="fixed bottom-[max(1rem,env(safe-area-inset-bottom))] left-1/2 z-40 flex max-w-[calc(100vw-2rem)] -translate-x-1/2 flex-wrap items-center justify-center gap-x-3 gap-y-2 rounded-[14px] border border-(--ds-border) bg-(--ds-frame) py-2 pr-2 pl-4 font-ui text-[13px]/5 text-(--ds-text) shadow-[0_8px_30px_-12px_rgb(0_0_0/0.35)]"
    >
        <template v-if="theme.off">
            <span>{{ $t('Custom theme is off in this browser.') }}</span>
            <AppButton size="sm" @click="turnBackOn">
                {{ $t('Turn it back on') }}
            </AppButton>
        </template>
        <template v-else>
            <span>{{ $t('You’re trying a theme only you can see.') }}</span>
            <div class="flex items-center gap-1.5">
                <AppButton
                    size="sm"
                    variant="quiet"
                    :disabled="busy"
                    @click="act('delete', '/settings/theme/draft')"
                >
                    {{ $t('Discard') }}
                </AppButton>
                <Link :href="theme.settingsUrl" :class="editClasses">
                    {{ $t('Edit') }}
                </Link>
                <AppButton
                    size="sm"
                    variant="primary"
                    :disabled="busy"
                    @click="act('post', '/settings/theme/publish')"
                >
                    {{ $t('Publish') }}
                </AppButton>
            </div>
        </template>
    </div>
</template>
