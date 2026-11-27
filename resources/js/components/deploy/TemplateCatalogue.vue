<script setup lang="ts">
import { ref } from 'vue';
import ServiceCard from '@/components/dash/ServiceCard.vue';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import type { ServiceTemplate } from '@/components/workspace/DeployServiceDialog.vue';
import { shortDescription } from './catalog';
import TemplateLogo from './TemplateLogo.vue';

export type CatalogueGroup = {
    category: string;
    count: number;
    items: ServiceTemplate[];
    more: boolean;
};

defineProps<{
    groups: CatalogueGroup[];
    picked: string | null;
    showUpload: boolean;
    query: string;
}>();

const emit = defineEmits<{
    pick: [template: ServiceTemplate];
    choose: [template: ServiceTemplate];
    seeAll: [category: string];
    drop: [transfer: DataTransfer | null];
    chooseFiles: [];
}>();

const dragging = ref(false);

function onDrop(event: DragEvent): void {
    dragging.value = false;
    emit('drop', event.dataTransfer);
}
</script>

<template>
    <div class="flex flex-col gap-5 px-5 py-4">
        <div
            v-if="showUpload"
            class="flex h-14 shrink-0 items-center gap-3 rounded-[8px] border border-dashed pr-3 pl-2.5 transition-colors duration-150"
            :class="
                dragging
                    ? 'border-[rgb(77_123_255/0.9)] bg-[rgb(51_97_255/0.06)]'
                    : 'border-[#d4d4d4] bg-[#fafafa] dark:border-[#383838] dark:bg-[#1a1a1a]'
            "
            @dragover.prevent="dragging = true"
            @dragleave.prevent="dragging = false"
            @drop.prevent="onDrop"
        >
            <span
                class="flex size-8 shrink-0 items-center justify-center rounded-[6px] bg-linear-to-b from-white to-[#f2f2f2] text-[#555] shadow-[0_1px_2px_rgb(0_0_0/0.1),0_0_0_0.5px_rgb(0_0_0/0.1)] dark:from-[#2e2e2e] dark:to-[#232323] dark:text-[#d0d0d0] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.07),0_3px_6px_-2px_rgb(0_0_0/0.35),0_1px_1.5px_rgb(0_0_0/0.55)]"
                aria-hidden="true"
            >
                <FilledIcon name="upload-fill" />
            </span>
            <span class="flex min-w-0 flex-1 flex-col gap-px">
                <span
                    class="truncate text-[13px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
                    >{{ $t('Your own code') }}</span
                >
                <span
                    class="truncate text-[12px]/4 tracking-[-0.005em] text-fg-muted"
                    >{{
                        $t(
                            'Paste a repository link in search, or drop a folder or a .zip here.',
                        )
                    }}</span
                >
            </span>
            <button
                type="button"
                class="dash-button dash-button--secondary h-6! shrink-0"
                @click="emit('chooseFiles')"
            >
                {{ $t('Choose files') }}
            </button>
        </div>

        <p
            v-if="groups.length === 0"
            class="px-3 py-16 text-center text-[13px]/5 text-fg-muted"
        >
            {{ $t('Nothing matches “:query”.', { query }) }}
        </p>

        <section
            v-for="group in groups"
            :key="group.category"
            class="flex flex-col gap-2"
            :aria-label="$t(group.category)"
        >
            <div class="flex items-center gap-2">
                <h3
                    class="text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                >
                    {{ $t(group.category) }}
                </h3>
                <span class="font-ui-mono text-[12px]/4 text-fg-faint">{{
                    group.count
                }}</span>
                <span class="flex-1" />
                <button
                    v-if="group.more"
                    type="button"
                    class="flex cursor-pointer items-center gap-[3px] rounded-[4px] text-[12px]/4 font-medium tracking-[-0.005em] text-fg-muted hover:text-fg-secondary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)"
                    @click="emit('seeAll', group.category)"
                >
                    {{ $t('See all') }}
                    <FilledIcon name="chevron-right" :size="12" />
                </button>
            </div>
            <div
                class="grid grid-cols-1 gap-2 min-[560px]:grid-cols-2 sm:grid-cols-3"
                role="radiogroup"
                :aria-label="$t(group.category)"
            >
                <ServiceCard
                    v-for="template in group.items"
                    :id="`template-${template.slug}`"
                    :key="template.slug"
                    :name="template.name"
                    :description="shortDescription(template.description)"
                    :title="template.description"
                    :selected="picked === template.slug"
                    @click="emit('pick', template)"
                    @dblclick="emit('choose', template)"
                >
                    <template #logo>
                        <TemplateLogo :icon="template.icon" />
                    </template>
                </ServiceCard>
            </div>
        </section>
    </div>
</template>
