<script setup lang="ts">
import { trans } from 'laravel-vue-i18n';
import { LoaderCircle } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { SolarBox as Box } from '@/lib/solarIcons';
import AppButton from '@/components/workspace/AppButton.vue';
import TickBox from '@/components/workspace/TickBox.vue';
import { errorMessage, failIfBad } from '@/lib/errors';
import { timeAgo } from '@/lib/timeAgo';
import { toast } from '@/lib/toast';

const props = defineProps<{
    machineId: number;
    canManage: boolean;
    online: boolean;
}>();

type DockerImage = {
    id: string;
    tags: string[];
    size: number;
    created: string | null;
    containers: string[];
};
type DockerVolume = {
    name: string;
    size: number | null;
    created: string | null;
    project: string | null;
    anonymous: boolean;
    containers: string[];
};
type Listing = {
    jobId: string;
    kind: 'docker_resources';
    state: string;
    finished: boolean;
    error: string | null;
    images: DockerImage[];
    volumes: DockerVolume[];
    buildCache: { size: number; reclaimable: number };
};
type Pruned = {
    jobId: string;
    kind: 'docker_prune';
    state: string;
    finished: boolean;
    error: string | null;
    removedImages: number;
    removedVolumes: number;
    reclaimed: number;
    skipped: { name: string; reason: string }[];
};

const listing = ref<Listing | null>(null);
const loading = ref(false);
const removing = ref(false);
const confirming = ref(false);
const problem = ref<string | null>(null);
const kind = ref<'images' | 'volumes'>('images');
const filter = ref<'unused' | 'all'>('unused');
const pickedImages = ref<string[]>([]);
const pickedVolumes = ref<string[]>([]);
const clearCache = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;
let active = true;

onBeforeUnmount(() => {
    active = false;
    clearTimeout(timer);
});

const unusedImages = computed(
    () => listing.value?.images.filter((i) => !i.containers.length) ?? [],
);
const unusedVolumes = computed(
    () => listing.value?.volumes.filter((v) => !v.containers.length) ?? [],
);
const shownImages = computed(() =>
    [
        ...(filter.value === 'unused'
            ? unusedImages.value
            : (listing.value?.images ?? [])),
    ].sort((a, b) => b.size - a.size),
);
const shownVolumes = computed(() =>
    [
        ...(filter.value === 'unused'
            ? unusedVolumes.value
            : (listing.value?.volumes ?? [])),
    ].sort((a, b) => (b.size ?? -1) - (a.size ?? -1)),
);
const sum = (list: { size: number | null }[]) =>
    list.reduce((total, item) => total + (item.size ?? 0), 0);
const pickedSize = computed(
    () =>
        sum(
            unusedImages.value.filter((i) => pickedImages.value.includes(i.id)),
        ) +
        sum(
            unusedVolumes.value.filter((v) =>
                pickedVolumes.value.includes(v.name),
            ),
        ) +
        (clearCache.value ? (listing.value?.buildCache.reclaimable ?? 0) : 0),
);
const picked = computed(
    () =>
        pickedImages.value.length +
        pickedVolumes.value.length +
        (clearCache.value ? 1 : 0),
);
const allPicked = computed(() =>
    kind.value === 'images'
        ? unusedImages.value.length > 0 &&
          unusedImages.value.every((i) => pickedImages.value.includes(i.id))
        : unusedVolumes.value.length > 0 &&
          unusedVolumes.value.every((v) =>
              pickedVolumes.value.includes(v.name),
          ),
);

const summary = computed(() => {
    const l = listing.value;
    if (!l) return '';
    return [
        trans(
            ':count images take :size, :unused of them (:unusedSize) used by no container.',
            {
                count: String(l.images.length),
                size: bytes(sum(l.images)),
                unused: String(unusedImages.value.length),
                unusedSize: bytes(sum(unusedImages.value)),
            },
        ),
        trans(':count volumes, :unused used by no container.', {
            count: String(l.volumes.length),
            unused: String(unusedVolumes.value.length),
        }),
        l.buildCache.reclaimable > 0
            ? trans('The build cache can give back :size.', {
                  size: bytes(l.buildCache.reclaimable),
              })
            : '',
    ]
        .filter(Boolean)
        .join(' ');
});

function bytes(value: number | null): string {
    if (value === null) return '–';
    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let n = value;
    let i = 0;
    while (n >= 1000 && i < units.length - 1) {
        n /= 1000;
        i++;
    }
    return `${n.toFixed(i >= 3 ? 1 : 0)} ${units[i]}`;
}

function imageName(image: DockerImage): string {
    return image.tags[0] ?? image.id.replace('sha256:', '').slice(0, 12);
}

function volumeName(volume: DockerVolume): string {
    return volume.anonymous ? volume.name.slice(0, 12) : volume.name;
}

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

async function post(path: string, body?: object): Promise<string> {
    const response = await fetch(`/machines/${props.machineId}/${path}`, {
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
    return ((await response.json()) as { jobId: string }).jobId;
}

function follow<
    T extends { finished: boolean; state: string; error: string | null },
>(
    jobId: string,
    done: (status: T) => void,
    failed: (message: string) => void,
): void {
    const tick = async () => {
        if (!active) return;
        try {
            const response = await fetch(
                `/machines/${props.machineId}/docker/${jobId}`,
                {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                    cache: 'no-store',
                },
            );
            const status = (await response.json()) as T;
            if (status.state === 'stale') {
                return failed(
                    trans(
                        'The machine stopped answering. Check it’s online and try again.',
                    ),
                );
            }
            if (status.finished) {
                return status.state === 'succeeded'
                    ? done(status)
                    : failed(
                          status.error ?? trans('It didn’t finish. Try again.'),
                      );
            }
        } catch {}
        timer = setTimeout(tick, 1500);
    };
    void tick();
}

async function load(): Promise<void> {
    loading.value = true;
    problem.value = null;
    try {
        const jobId = await post('docker');
        follow<Listing>(
            jobId,
            (status) => {
                loading.value = false;
                listing.value = status;
                pickedImages.value = [];
                pickedVolumes.value = [];
                clearCache.value = false;
            },
            (message) => {
                loading.value = false;
                problem.value = message;
            },
        );
    } catch (e) {
        loading.value = false;
        problem.value = errorMessage(e);
    }
}

async function remove(): Promise<void> {
    removing.value = true;
    problem.value = null;
    try {
        const jobId = await post('docker/prune', {
            images: pickedImages.value,
            volumes: pickedVolumes.value,
            build_cache: clearCache.value,
        });
        follow<Pruned>(
            jobId,
            (status) => {
                removing.value = false;
                confirming.value = false;
                toast.success(
                    trans(
                        'Removed :images images and :volumes volumes. :size back.',
                        {
                            images: String(status.removedImages),
                            volumes: String(status.removedVolumes),
                            size: bytes(status.reclaimed),
                        },
                    ),
                );
                if (status.skipped.length) {
                    problem.value = status.skipped
                        .map((s) => trans(':name was kept: :reason', s))
                        .join(' ');
                }
                void load();
            },
            (message) => {
                removing.value = false;
                confirming.value = false;
                problem.value = message;
            },
        );
    } catch (e) {
        removing.value = false;
        problem.value = errorMessage(e);
    }
}

function toggleAll(): void {
    if (kind.value === 'images') {
        pickedImages.value = allPicked.value
            ? []
            : unusedImages.value.map((i) => i.id);
    } else {
        pickedVolumes.value = allPicked.value
            ? []
            : unusedVolumes.value.map((v) => v.name);
    }
}

function toggle(list: string[], value: string): string[] {
    return list.includes(value)
        ? list.filter((v) => v !== value)
        : [...list, value];
}

const focusRing =
    'focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-(--ds-ring)';
</script>

<template>
    <div v-if="canManage" class="flex flex-col gap-3">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <p class="text-sm text-pretty text-(--ds-text-muted)">
                {{
                    listing
                        ? summary
                        : $t(
                              'See the images and volumes Docker keeps, what each takes, and remove the ones no container uses.',
                          )
                }}
            </p>
            <AppButton
                size="sm"
                :disabled="loading || removing || !online"
                :title="online ? undefined : $t('The machine is offline.')"
                @click="load"
            >
                <LoaderCircle
                    v-if="loading"
                    class="size-3.5 animate-spin opacity-60"
                />
                <Box v-else class="size-3.5 opacity-60" />
                {{
                    loading
                        ? $t('Reading Docker')
                        : listing
                          ? $t('Read again')
                          : $t('See images and volumes')
                }}
            </AppButton>
        </div>

        <p v-if="problem" class="text-sm text-(--ds-red)" role="alert">
            {{ problem }}
        </p>

        <template v-if="listing">
            <div class="flex flex-wrap items-center gap-2">
                <div class="ds-segments" role="group" :aria-label="$t('Show')">
                    <button
                        type="button"
                        class="cursor-pointer gap-1.5"
                        :class="focusRing"
                        :aria-pressed="kind === 'images'"
                        @click="kind = 'images'"
                    >
                        {{ $t('Images') }}
                        <span
                            class="text-xs/4 text-(--ds-text-faint) tabular-nums"
                            >{{ listing.images.length }}</span
                        >
                    </button>
                    <button
                        type="button"
                        class="cursor-pointer gap-1.5"
                        :class="focusRing"
                        :aria-pressed="kind === 'volumes'"
                        @click="kind = 'volumes'"
                    >
                        {{ $t('Volumes') }}
                        <span
                            class="text-xs/4 text-(--ds-text-faint) tabular-nums"
                            >{{ listing.volumes.length }}</span
                        >
                    </button>
                </div>
                <div
                    class="ds-segments"
                    role="group"
                    :aria-label="$t('Filter')"
                >
                    <button
                        type="button"
                        class="cursor-pointer"
                        :class="focusRing"
                        :aria-pressed="filter === 'unused'"
                        @click="filter = 'unused'"
                    >
                        {{ $t('Unused') }}
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
                <label
                    v-if="listing.buildCache.reclaimable > 0"
                    class="ml-auto flex cursor-pointer items-center gap-2 text-[13px]/5"
                >
                    <TickBox v-model="clearCache" />
                    {{
                        $t('Clear the build cache (:size)', {
                            size: bytes(listing.buildCache.reclaimable),
                        })
                    }}
                </label>
            </div>

            <div class="ds-tile overflow-x-auto text-[13px]/5">
                <table class="w-full min-w-[34rem]">
                    <thead class="text-left text-xs/4 text-(--ds-text-muted)">
                        <tr
                            class="border-b-2 border-black/5 dark:border-black/30"
                        >
                            <th class="w-10 py-2 pl-4">
                                <TickBox
                                    :model-value="allPicked"
                                    :disabled="
                                        kind === 'images'
                                            ? !unusedImages.length
                                            : !unusedVolumes.length
                                    "
                                    :aria-label="$t('Pick every unused one')"
                                    @update:model-value="toggleAll"
                                />
                            </th>
                            <th class="py-2 pr-3 font-medium">
                                {{ $t('Name') }}
                            </th>
                            <th class="py-2 pr-3 text-right font-medium">
                                {{ $t('Size') }}
                            </th>
                            <th class="py-2 pr-3 font-medium">
                                {{ $t('Used by') }}
                            </th>
                            <th class="py-2 pr-4 font-medium">
                                {{ $t('Created') }}
                            </th>
                        </tr>
                    </thead>
                    <tbody
                        v-if="kind === 'images'"
                        class="divide-y-2 divide-black/5 dark:divide-black/30"
                    >
                        <tr v-for="image in shownImages" :key="image.id">
                            <td class="py-2 pl-4">
                                <TickBox
                                    v-if="!image.containers.length"
                                    :model-value="
                                        pickedImages.includes(image.id)
                                    "
                                    :aria-label="
                                        $t('Pick :name', {
                                            name: imageName(image),
                                        })
                                    "
                                    @update:model-value="
                                        pickedImages = toggle(
                                            pickedImages,
                                            image.id,
                                        )
                                    "
                                />
                            </td>
                            <td class="max-w-0 py-2 pr-3">
                                <span
                                    class="block truncate font-mono text-[12.5px]"
                                    :title="image.tags.join(', ') || image.id"
                                >
                                    {{ imageName(image) }}
                                </span>
                                <span
                                    v-if="!image.tags.length"
                                    class="text-xs/4 text-(--ds-text-faint)"
                                >
                                    {{ $t('No tag left') }}
                                </span>
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">
                                {{ bytes(image.size) }}
                            </td>
                            <td class="max-w-0 py-2 pr-3">
                                <span
                                    class="block truncate"
                                    :class="
                                        image.containers.length
                                            ? ''
                                            : 'text-(--ds-text-faint)'
                                    "
                                >
                                    {{
                                        image.containers.join(', ') ||
                                        $t('Nothing')
                                    }}
                                </span>
                            </td>
                            <td
                                class="py-2 pr-4 whitespace-nowrap text-(--ds-text-muted)"
                            >
                                {{
                                    image.created ? timeAgo(image.created) : '–'
                                }}
                            </td>
                        </tr>
                        <tr v-if="!shownImages.length">
                            <td
                                colspan="5"
                                class="px-4 py-6 text-center text-(--ds-text-muted)"
                            >
                                {{ $t('Every image is used by a container.') }}
                            </td>
                        </tr>
                    </tbody>
                    <tbody
                        v-else
                        class="divide-y-2 divide-black/5 dark:divide-black/30"
                    >
                        <tr v-for="volume in shownVolumes" :key="volume.name">
                            <td class="py-2 pl-4">
                                <TickBox
                                    v-if="!volume.containers.length"
                                    :model-value="
                                        pickedVolumes.includes(volume.name)
                                    "
                                    :aria-label="
                                        $t('Pick :name', {
                                            name: volumeName(volume),
                                        })
                                    "
                                    @update:model-value="
                                        pickedVolumes = toggle(
                                            pickedVolumes,
                                            volume.name,
                                        )
                                    "
                                />
                            </td>
                            <td class="max-w-0 py-2 pr-3">
                                <span
                                    class="block truncate font-mono text-[12.5px]"
                                    :title="volume.name"
                                >
                                    {{ volumeName(volume) }}
                                </span>
                                <span
                                    v-if="volume.project || volume.anonymous"
                                    class="text-xs/4 text-(--ds-text-faint)"
                                >
                                    {{
                                        volume.project
                                            ? $t('From :project', {
                                                  project: volume.project,
                                              })
                                            : $t('Anonymous')
                                    }}
                                </span>
                            </td>
                            <td class="py-2 pr-3 text-right tabular-nums">
                                {{ bytes(volume.size) }}
                            </td>
                            <td class="max-w-0 py-2 pr-3">
                                <span
                                    class="block truncate"
                                    :class="
                                        volume.containers.length
                                            ? ''
                                            : 'text-(--ds-text-faint)'
                                    "
                                >
                                    {{
                                        volume.containers.join(', ') ||
                                        $t('Nothing')
                                    }}
                                </span>
                            </td>
                            <td
                                class="py-2 pr-4 whitespace-nowrap text-(--ds-text-muted)"
                            >
                                {{
                                    volume.created
                                        ? timeAgo(volume.created)
                                        : '–'
                                }}
                            </td>
                        </tr>
                        <tr v-if="!shownVolumes.length">
                            <td
                                colspan="5"
                                class="px-4 py-6 text-center text-(--ds-text-muted)"
                            >
                                {{ $t('Every volume is used by a container.') }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>

            <div
                v-if="picked"
                class="flex flex-wrap items-center justify-end gap-2 text-[13px]/5"
            >
                <template v-if="confirming">
                    <span class="mr-auto text-pretty text-(--ds-text-muted)">
                        {{
                            pickedVolumes.length
                                ? $t(
                                      'Removing a volume deletes the data in it, and it can’t be undone.',
                                  )
                                : $t(
                                      'Removed images are pulled again if something needs them.',
                                  )
                        }}
                    </span>
                    <AppButton
                        size="sm"
                        :disabled="removing"
                        @click="confirming = false"
                    >
                        {{ $t('Cancel') }}
                    </AppButton>
                    <AppButton
                        size="sm"
                        variant="primary"
                        :disabled="removing"
                        @click="remove"
                    >
                        <LoaderCircle
                            v-if="removing"
                            class="size-3.5 animate-spin opacity-60"
                        />
                        {{ removing ? $t('Removing') : $t('Remove them') }}
                    </AppButton>
                </template>
                <AppButton
                    v-else
                    size="sm"
                    variant="primary"
                    @click="confirming = true"
                >
                    {{
                        $t('Remove :count picked (:size)', {
                            count: String(picked),
                            size: bytes(pickedSize),
                        })
                    }}
                </AppButton>
            </div>
        </template>
    </div>
</template>
