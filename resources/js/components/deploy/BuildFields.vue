<script setup lang="ts">
import { X } from '@lucide/vue';
import { computed } from 'vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import DashSwitch from '@/components/dash/DashSwitch.vue';
import FilterPills from '@/components/dash/FilterPills.vue';
import { SelectMenu } from '@/components/ui/select';
import type { SelectMenuGroup, SelectMenuOption } from '@/components/ui/select';
import { volumeRow } from './buildForm';
import type { BuildForm, BuildMethod, RailpackHints } from './buildForm';
import {
    error as errorClass,
    field,
    hint,
    iconButton,
    label,
    select,
} from './styles';

const build = defineModel<BuildForm>('build', { required: true });
const port = defineModel<string | number>('port', { required: true });
const setup = defineModel<string>('setup', { default: '' });

const {
    method,
    setupOptions = [],
    canDeployBuild,
    machineName,
    web,
    portEditable,
    portPlaceholder = '',
    portNote = null,
    upload = false,
    railpack = null,
    errors = {},
} = defineProps<{
    method: BuildMethod;
    /** What it can be built with; empty hides the choice. */
    setupOptions?: (SelectMenuOption<string> | SelectMenuGroup<string>)[];
    canDeployBuild: boolean;
    machineName: string;
    web: boolean;
    portEditable: boolean;
    portPlaceholder?: string;
    portNote?: string | null;
    upload?: boolean;
    railpack?: RailpackHints | null;
    errors?: Record<string, string>;
}>();

function errorFor(...keys: string[]): string | undefined {
    for (const key of keys) {
        if (errors[key]) return errors[key];
    }

    return undefined;
}

const volumeErrors = computed(() =>
    Object.entries(errors)
        .filter(([key]) => key.startsWith('build.volumes'))
        .map(([, message]) => message),
);

const passOn = computed({
    get: () => (build.value.health.any ? 'any' : 'status'),
    set: (value: 'any' | 'status') =>
        (build.value.health.any = value === 'any'),
});

const startPlaceholder = computed(() => {
    if (railpack?.startCommand) return railpack.startCommand;

    return '';
});
</script>

<template>
    <div class="flex flex-col gap-5">
        <p v-if="!canDeployBuild" :class="hint">
            {{
                $t(
                    'Update the ServerOS daemon on :machine for Railpack, volumes, release commands and finer health checks. It updates itself, or run “sudo serverosd update” on it.',
                    { machine: machineName },
                )
            }}
        </p>

        <div v-if="setupOptions.length" class="flex flex-col gap-1.5">
            <label for="deploy-build-with" :class="label">{{
                $t('Build with')
            }}</label>
            <SelectMenu
                id="deploy-build-with"
                v-model="setup"
                :options="setupOptions"
                :class="select"
            />
            <p v-if="errorFor('template', 'build.method')" :class="errorClass">
                {{ errorFor('template', 'build.method') }}
            </p>
        </div>

        <!-- Railpack: replace the commands it works out -->
        <div
            v-if="method === 'railpack' && canDeployBuild"
            class="grid gap-4 sm:grid-cols-3"
        >
            <div class="flex flex-col gap-1.5">
                <label for="deploy-install" :class="label">{{
                    $t('Install command')
                }}</label>
                <div :class="field">
                    <input
                        id="deploy-install"
                        v-model="build.install"
                        class="font-ui-mono"
                        :placeholder="$t('Worked out')"
                        autocomplete="off"
                        spellcheck="false"
                    />
                </div>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="deploy-build-command" :class="label">{{
                    $t('Build command')
                }}</label>
                <div :class="field">
                    <input
                        id="deploy-build-command"
                        v-model="build.build"
                        class="font-ui-mono"
                        :placeholder="$t('Worked out')"
                        autocomplete="off"
                        spellcheck="false"
                    />
                </div>
            </div>
            <div class="flex flex-col gap-1.5">
                <label for="deploy-start" :class="label">{{
                    $t('Start command')
                }}</label>
                <div :class="field">
                    <input
                        id="deploy-start"
                        v-model="build.start"
                        class="font-ui-mono"
                        :placeholder="startPlaceholder || $t('Worked out')"
                        autocomplete="off"
                        spellcheck="false"
                    />
                </div>
            </div>
            <p
                v-if="
                    errorFor(
                        'build.install_command',
                        'build.build_command',
                        'build.start_command',
                    )
                "
                :class="[errorClass, 'sm:col-span-3']"
            >
                {{
                    errorFor(
                        'build.install_command',
                        'build.build_command',
                        'build.start_command',
                    )
                }}
            </p>
            <p :class="[hint, '-mt-2 sm:col-span-3']">
                {{
                    $t(
                        'Leave them empty and Railpack works them out from your code.',
                    )
                }}
            </p>
        </div>

        <!-- Dockerfile: which one, and which stage -->
        <div
            v-if="method === 'dockerfile'"
            class="grid gap-4 sm:grid-cols-[2fr_1fr]"
        >
            <div class="flex flex-col gap-1.5">
                <label for="deploy-dockerfile" :class="label">{{
                    $t('Dockerfile')
                }}</label>
                <div :class="field">
                    <input
                        id="deploy-dockerfile"
                        v-model.trim="build.dockerfile"
                        class="font-ui-mono"
                        placeholder="Dockerfile"
                        :disabled="!canDeployBuild"
                        autocomplete="off"
                        spellcheck="false"
                    />
                </div>
                <p v-if="errorFor('build.dockerfile')" :class="errorClass">
                    {{ errorFor('build.dockerfile') }}
                </p>
                <p v-else :class="hint">
                    {{ $t('Its path in the repository.') }}
                </p>
            </div>
            <div v-if="canDeployBuild" class="flex flex-col gap-1.5">
                <label for="deploy-target" :class="label">
                    {{ $t('Build stage') }}
                    <span class="font-normal text-fg-faint">{{
                        ' ' + $t('(optional)')
                    }}</span>
                </label>
                <div :class="field">
                    <input
                        id="deploy-target"
                        v-model.trim="build.target"
                        class="font-ui-mono"
                        placeholder="production"
                        autocomplete="off"
                        spellcheck="false"
                    />
                </div>
                <p v-if="errorFor('build.target')" :class="errorClass">
                    {{ errorFor('build.target') }}
                </p>
            </div>
        </div>

        <!-- Port -->
        <div class="flex flex-col gap-1.5">
            <label for="deploy-port" :class="label">
                {{
                    method === 'compose'
                        ? $t('Port it publishes on this machine')
                        : $t('Port your app listens on')
                }}
            </label>
            <div v-if="portEditable" :class="[field, 'max-w-[200px]']">
                <input
                    id="deploy-port"
                    v-model.number="port"
                    type="number"
                    min="1"
                    max="65535"
                    class="tabular-nums"
                    :placeholder="portPlaceholder"
                />
            </div>
            <div
                v-else
                class="dash-field max-w-[200px] bg-surface-field-readonly text-fg-secondary tabular-nums"
            >
                {{ port }}
            </div>
            <p v-if="errorFor('port', 'build.port')" :class="errorClass">
                {{ errorFor('port', 'build.port') }}
            </p>
            <p v-else-if="portNote" :class="hint">{{ portNote }}</p>
        </div>

        <!-- Health check -->
        <div v-if="web" class="flex flex-col gap-3">
            <div class="flex items-start gap-2.5">
                <DashSwitch
                    v-if="canDeployBuild"
                    v-model="build.health.enabled"
                    size="sm"
                    class="mt-0.5"
                    :label="$t('Health check')"
                />
                <div class="flex min-w-0 flex-col gap-0.5">
                    <span :class="label">{{ $t('Health check') }}</span>
                    <span :class="hint">{{
                        build.health.enabled
                            ? $t(
                                  'A new version only goes live once it answers here. Until then the old one keeps serving.',
                              )
                            : $t(
                                  'Off: a new version goes live once its container keeps running.',
                              )
                    }}</span>
                </div>
            </div>
            <div
                v-if="build.health.enabled"
                class="grid gap-4"
                :class="canDeployBuild && 'sm:grid-cols-[1fr_auto_120px]'"
            >
                <div class="flex flex-col gap-1.5">
                    <label for="deploy-health" :class="label">{{
                        $t('Path')
                    }}</label>
                    <div :class="field">
                        <input
                            id="deploy-health"
                            v-model.trim="build.health.path"
                            class="font-ui-mono"
                            placeholder="/"
                            autocomplete="off"
                            spellcheck="false"
                        />
                    </div>
                </div>
                <div v-if="canDeployBuild" class="flex flex-col gap-1.5">
                    <span :class="label">{{ $t('Passes on') }}</span>
                    <div class="flex items-center gap-2">
                        <FilterPills
                            v-model="passOn"
                            :label="$t('Passes on')"
                            :options="[
                                { value: 'any', label: $t('Any answer') },
                                { value: 'status', label: $t('A status') },
                            ]"
                        />
                        <div
                            v-if="!build.health.any"
                            :class="[field, 'w-[72px]!']"
                        >
                            <input
                                v-model.number="build.health.status"
                                type="number"
                                min="100"
                                max="599"
                                class="tabular-nums"
                                :aria-label="$t('Status')"
                            />
                        </div>
                    </div>
                </div>
                <div v-if="canDeployBuild" class="flex flex-col gap-1.5">
                    <label for="deploy-start-period" :class="label">{{
                        $t('Time to boot')
                    }}</label>
                    <div :class="field">
                        <input
                            id="deploy-start-period"
                            v-model.number="build.health.startPeriod"
                            type="number"
                            min="0"
                            max="900"
                            class="tabular-nums"
                        />
                        <span class="shrink-0 text-fg-muted">{{
                            $t('sec')
                        }}</span>
                    </div>
                </div>
                <p
                    v-if="
                        errorFor(
                            'health_path',
                            'build.health.path',
                            'build.health.expected_status',
                            'build.health.start_period_secs',
                        )
                    "
                    :class="[errorClass, 'sm:col-span-3']"
                >
                    {{
                        errorFor(
                            'health_path',
                            'build.health.path',
                            'build.health.expected_status',
                            'build.health.start_period_secs',
                        )
                    }}
                </p>
                <p v-else-if="canDeployBuild" :class="[hint, 'sm:col-span-3']">
                    {{
                        build.health.any
                            ? $t(
                                  'Any answer below 500 passes, so a login page or a redirect counts.',
                              )
                            : $t('Only that status passes.')
                    }}
                </p>
            </div>
        </div>

        <template v-if="canDeployBuild && method !== 'compose'">
            <!-- Volumes -->
            <div class="flex flex-col gap-2">
                <span :class="label">{{ $t('Volumes') }}</span>
                <ul v-if="build.volumes.length" class="flex flex-col gap-2">
                    <li
                        v-for="(volume, index) in build.volumes"
                        :key="volume.id"
                        class="grid grid-cols-[minmax(0,1fr)_minmax(0,2fr)_auto] items-center gap-2"
                    >
                        <div :class="field">
                            <input
                                v-model.trim="volume.name"
                                class="font-ui-mono"
                                placeholder="data"
                                :aria-label="$t('Volume name')"
                                autocomplete="off"
                                spellcheck="false"
                            />
                        </div>
                        <div :class="field">
                            <input
                                v-model.trim="volume.path"
                                class="font-ui-mono"
                                placeholder="/app/storage"
                                :aria-label="$t('Folder in the container')"
                                autocomplete="off"
                                spellcheck="false"
                            />
                        </div>
                        <button
                            type="button"
                            :class="iconButton"
                            :aria-label="$t('Remove this volume')"
                            @click="build.volumes.splice(index, 1)"
                        >
                            <X class="size-3.5" :stroke-width="1.75" />
                        </button>
                    </li>
                </ul>
                <p
                    v-for="message in volumeErrors"
                    :key="message"
                    :class="errorClass"
                >
                    {{ message }}
                </p>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
                    <DashButton
                        size="sm"
                        icon="plus"
                        :label="$t('Add a volume')"
                        @click="build.volumes.push(volumeRow())"
                    />
                    <span :class="hint">{{
                        method === 'dockerfile'
                            ? $t(
                                  'Kept between deploys, as are the folders its Dockerfile names with VOLUME.',
                              )
                            : $t('Kept between deploys.')
                    }}</span>
                </div>
            </div>

            <!-- Commands -->
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="flex flex-col gap-1.5">
                    <label for="deploy-release" :class="label">{{
                        $t('Before it goes live')
                    }}</label>
                    <div :class="field">
                        <input
                            id="deploy-release"
                            v-model="build.release"
                            class="font-ui-mono placeholder:font-ui"
                            :placeholder="$t('Like a database migration')"
                            autocomplete="off"
                            spellcheck="false"
                        />
                    </div>
                    <p
                        v-if="errorFor('build.release_command')"
                        :class="errorClass"
                    >
                        {{ errorFor('build.release_command') }}
                    </p>
                    <p v-else :class="hint">
                        {{
                            $t(
                                'Runs once in the new version, migrations say. If it fails, the old version keeps serving.',
                            )
                        }}
                    </p>
                </div>
                <div class="flex flex-col gap-1.5">
                    <label for="deploy-post" :class="label">{{
                        $t('After it goes live')
                    }}</label>
                    <div :class="field">
                        <input
                            id="deploy-post"
                            v-model="build.postDeploy"
                            class="font-ui-mono placeholder:font-ui"
                            :placeholder="$t('Like clearing a cache')"
                            autocomplete="off"
                            spellcheck="false"
                        />
                    </div>
                    <p
                        v-if="errorFor('build.post_deploy_command')"
                        :class="errorClass"
                    >
                        {{ errorFor('build.post_deploy_command') }}
                    </p>
                    <p v-else :class="hint">
                        {{
                            $t(
                                'Runs in the new container once it serves. If it fails, the log says so.',
                            )
                        }}
                    </p>
                </div>
            </div>

            <!-- Git -->
            <div
                v-if="method !== 'image' && !upload"
                class="flex flex-wrap gap-x-6 gap-y-2"
            >
                <label
                    class="flex cursor-pointer items-center gap-2 text-[13px]/5 text-fg-secondary select-none"
                >
                    <DashCheckbox
                        v-model="build.submodules"
                        :label="$t('Fetch submodules')"
                    />
                    {{ $t('Fetch submodules') }}
                </label>
                <label
                    class="flex cursor-pointer items-center gap-2 text-[13px]/5 text-fg-secondary select-none"
                >
                    <DashCheckbox
                        v-model="build.lfs"
                        :label="$t('Fetch Git LFS files')"
                    />
                    {{ $t('Fetch Git LFS files') }}
                </label>
            </div>
        </template>
    </div>
</template>
