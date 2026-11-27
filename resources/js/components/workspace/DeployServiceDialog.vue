<script setup lang="ts">
import { ArrowLeft, Check, LoaderCircle } from '@lucide/vue';
import { usePage } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import {
    DialogContent,
    DialogDescription,
    DialogOverlay,
    DialogPortal,
    DialogRoot,
    DialogTitle,
} from 'reka-ui';
import { computed, nextTick, ref, watch } from 'vue';
import BrandLogo from '@/components/dash/BrandLogo.vue';
import DashButton from '@/components/dash/DashButton.vue';
import DashCheckbox from '@/components/dash/DashCheckbox.vue';
import AddressField from '@/components/deploy/AddressField.vue';
import { bareDomain } from '@/components/deploy/address';
import type { AddressMode } from '@/components/deploy/address';
import BuildFields from '@/components/deploy/BuildFields.vue';
import {
    buildPayload,
    changedCount,
    defaultHealth,
    emptyBuild,
    methodOf,
} from '@/components/deploy/buildForm';
import type { BuildForm, HealthForm } from '@/components/deploy/buildForm';
import CategoryRail from '@/components/deploy/CategoryRail.vue';
import {
    OWN_CODE,
    RAIL,
    SECTION_ORDER,
    featuredRank,
    isSshRepository,
    looksLikeRepository,
    repositoryLabel,
} from '@/components/deploy/catalog';
import DeployFollow from '@/components/deploy/DeployFollow.vue';
import type { FollowedJob } from '@/components/deploy/DeployFollow.vue';
import DockerInstall from '@/components/deploy/DockerInstall.vue';
import EnvRows from '@/components/deploy/EnvRows.vue';
import { envPayload, envRow } from '@/components/deploy/envFile';
import type { EnvRow } from '@/components/deploy/envFile';
import PickerButton from '@/components/deploy/PickerButton.vue';
import RepoList from '@/components/deploy/RepoList.vue';
import type {
    GitHubRepositories,
    GitLabRepositories,
} from '@/components/deploy/RepoList.vue';
import ScanSummary from '@/components/deploy/ScanSummary.vue';
import type { ScanDetection } from '@/components/deploy/ScanSummary.vue';
import {
    error,
    field,
    hint,
    iconButton,
    label,
    linkButton,
    sectionTitle,
    select,
} from '@/components/deploy/styles';
import TemplateCatalogue from '@/components/deploy/TemplateCatalogue.vue';
import type { CatalogueGroup } from '@/components/deploy/TemplateCatalogue.vue';
import TemplateLogo from '@/components/deploy/TemplateLogo.vue';
import {
    PackError,
    archiveName,
    fromDrop,
    fromInput,
    isZip,
    pack,
} from '@/components/deploy/zip';
import type { PickedFile } from '@/components/deploy/zip';
import FilledIcon from '@/components/icons/FilledIcon.vue';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { SelectMenu } from '@/components/ui/select';
import type { SelectMenuGroup, SelectMenuOption } from '@/components/ui/select';
import { useZipUpload } from '@/composables/useZipUpload';
import { describe, errorMessage } from '@/lib/errors';
import {
    SolarCircleAlert as CircleAlert,
    SolarCopy as Copy,
    SolarLockKeyhole as LockKeyhole,
} from '@/lib/solarIcons';
import { toast } from '@/lib/toast';
import { onWorkspace, workspacePath } from '@/lib/workspaceRoute';
import githubRoutes from '@/routes/workspace/github';
import gitlabRoutes from '@/routes/workspace/gitlab';
import machinesRoutes from '@/routes/workspace/machines';
import servicesRoutes from '@/routes/workspace/services';
import settingsRoutes from '@/routes/workspace/settings';

export type TemplateField = {
    key: string;
    label: string;
    hint: string | null;
    secret: boolean;
    required: boolean;
    accept: boolean;
    generated: boolean;
    default: string;
};

export type ServiceTemplate = {
    slug: string;
    name: string;
    category: string;
    description: string;
    icon: string;
    kind: 'compose' | 'app' | 'image';
    /** How it builds: null for ready-made stacks. */
    build?: 'railpack' | 'dockerfile' | 'template' | 'compose' | 'image' | null;
    /** The page's machine is too old for it. */
    needsUpdate?: boolean;
    port: number;
    web: boolean;
    portEditable: boolean;
    health: string | null;
    compose?: string | null;
    notes: string | null;
    env: TemplateField[];
};

type DeployResult = {
    jobId: string;
    template: string;
    generated: { key: string; label: string; value: string }[];
    kept?: string[];
    url: string | null;
    localUrl: string | null;
    tunnel: string | null;
    port: number | null;
    notes: string | null;
    domain?: string | null;
    commit?: string | null;
    appUrl?: string | null;
    machineId?: number;
};

type DeployMachine = {
    id: number;
    name: string;
    provider: string | null;
    online: boolean;
    canDeployBuild?: boolean;
};

type Detection = ScanDetection & { healthPath?: string | null };

const props = defineProps<{
    templates: ServiceTemplate[];
    machineId: number;
    /** A template's slug to open on, like coding-agent from ?deploy=coding-agent. */
    startWith?: string | null;
    /** Opened from somewhere else (like the Agents machine picker): back closes it, so you land where you came from. */
    backCloses?: boolean;
}>();

const emit = defineEmits<{ deployed: [result: DeployResult] }>();

const page = usePage<{ githubApp: boolean; workspace: { slug: string } }>();

const open = defineModel<boolean>('open', { default: false });

function csrf(): string {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? ''
    );
}

function request(url: string, body?: unknown): Promise<Response> {
    return fetch(url, {
        method: body === undefined ? 'GET' : 'POST',
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            ...(body === undefined
                ? {}
                : {
                      'Content-Type': 'application/json',
                      'X-CSRF-TOKEN': csrf(),
                  }),
        },
        body: body === undefined ? undefined : JSON.stringify(body),
    });
}

const sleep = (ms: number) =>
    new Promise((resolve) => window.setTimeout(resolve, ms));

const step = ref<'pick' | 'configure' | 'follow' | 'done'>('pick');

// The machine it deploys to, and what its daemon can do.
const target = ref(props.machineId);
watch(
    () => props.machineId,
    (id) => (target.value = id),
);
const machines = ref<DeployMachine[]>([]);
const machine = computed(
    () => machines.value.find((entry) => entry.id === target.value) ?? null,
);
const machineName = computed(() => machine.value?.name ?? '');

type Capabilities = {
    canDeployBuild: boolean;
    canInspect: boolean;
    docker: { installed: boolean | null; canInstall: boolean };
    domains: { domain: string; app: string }[];
};
const capabilities = ref<Capabilities | null>(null);
// Until the machine has answered, it's taken to be up to date.
const canDeployBuild = computed(
    () => capabilities.value?.canDeployBuild ?? true,
);

const taken = ref<{ services: string[]; ports: number[] }>({
    services: [],
    ports: [],
});
const machineProxy = ref<string | null>(null);
const canChooseProxy = ref(false);
const canChooseHttps = ref(false);
// Like 203-0-113-5.sslip.io: names under it resolve to the machine's public address.
const freeAddress = ref<string | null>(null);

// The catalogue.
const query = ref('');
const category = ref(OWN_CODE);
const picked = ref<string | null>(null);
const searchInput = ref<HTMLInputElement | null>(null);
const isOwnCode = computed(() => category.value === OWN_CODE);

const counts = computed(() => {
    const totals: Record<string, number> = { All: props.templates.length };
    for (const template of props.templates) {
        totals[template.category] = (totals[template.category] ?? 0) + 1;
    }

    return totals;
});

const ranked = computed(() =>
    [...props.templates].sort(
        (a, b) => featuredRank(a.slug) - featuredRank(b.slug),
    ),
);

const groups = computed<CatalogueGroup[]>(() => {
    const words = query.value.toLowerCase().split(/\s+/).filter(Boolean);
    const browsing = category.value === 'All' && words.length === 0;
    const matches = ranked.value.filter((template) => {
        if (category.value !== 'All' && template.category !== category.value) {
            return false;
        }
        const haystack =
            `${template.name} ${template.slug} ${template.description} ${template.category}`.toLowerCase();

        return words.every((word) => haystack.includes(word));
    });
    const order =
        category.value === 'All'
            ? [
                  ...SECTION_ORDER,
                  ...Object.keys(counts.value).filter(
                      (name) => name !== 'All' && !SECTION_ORDER.includes(name),
                  ),
              ]
            : [category.value];

    return order
        .map((name) => {
            const items = matches.filter(
                (template) => template.category === name,
            );

            return {
                category: name,
                count: browsing ? (counts.value[name] ?? 0) : items.length,
                items: browsing ? items.slice(0, 6) : items,
                more: browsing && items.length > 6,
            };
        })
        .filter((group) => group.items.length > 0);
});

const visible = computed(() => groups.value.flatMap((group) => group.items));

const pickedTemplate = computed(
    () =>
        props.templates.find((template) => template.slug === picked.value) ??
        null,
);

watch(query, () => (picked.value = visible.value[0]?.slug ?? null));
watch(
    visible,
    (list) => {
        if (!list.some((template) => template.slug === picked.value)) {
            picked.value = list[0]?.slug ?? null;
        }
    },
    { immediate: true },
);

const showUploadStrip = computed(
    () => category.value === 'All' && query.value.trim() === '',
);

const railOptions = computed(() => [
    OWN_CODE,
    ...RAIL.map((option) => option.value),
]);

function railLabel(value: string): string {
    if (value === 'All') return trans('All services');
    if (value === OWN_CODE) return trans('Your own code');

    return trans(value);
}

function seeAll(name: string): void {
    category.value = name;
}

function columns(): number {
    if (window.matchMedia('(min-width: 640px)').matches) return 3;

    return window.matchMedia('(min-width: 560px)').matches ? 2 : 1;
}

function movePick(by: number): void {
    const list = visible.value;
    if (!list.length) return;
    const at = list.findIndex((template) => template.slug === picked.value);
    const next = Math.min(list.length - 1, Math.max(0, (at < 0 ? 0 : at) + by));
    picked.value = list[next].slug;
    nextTick(() =>
        document
            .getElementById(`template-${picked.value}`)
            ?.scrollIntoView({ block: 'nearest' }),
    );
}

function onSearchKeydown(event: KeyboardEvent): void {
    const text = query.value.trim();

    if (event.key === 'Enter' && looksLikeRepository(text)) {
        event.preventDefault();
        query.value = '';
        importRepository(text);

        return;
    }

    if (isOwnCode.value) return;

    const by = columns();
    const moves: Record<string, number> = {
        ArrowDown: by,
        ArrowUp: -by,
        ArrowRight: 1,
        ArrowLeft: -1,
    };
    const move = moves[event.key];
    const caretKey =
        (event.key === 'ArrowLeft' || event.key === 'ArrowRight') &&
        query.value !== '';

    if (move !== undefined && !caretKey) {
        event.preventDefault();
        movePick(move);
    } else if (event.key === 'Enter' && pickedTemplate.value) {
        event.preventDefault();
        choose(pickedTemplate.value);
    }
}

function onSearchPaste(event: ClipboardEvent): void {
    const text = event.clipboardData?.getData('text')?.trim() ?? '';
    if (looksLikeRepository(text) && query.value.trim() === '') {
        event.preventDefault();
        importRepository(text);
    }
}

watch(query, (text) => {
    if (text.trim() && isOwnCode.value && !looksLikeRepository(text)) {
        category.value = 'All';
    }
});

function templateBySlug(
    slug: string | null | undefined,
): ServiceTemplate | null {
    return props.templates.find((template) => template.slug === slug) ?? null;
}

const appTemplates = computed(() =>
    props.templates.filter((template) => template.kind === 'app'),
);

const appTemplateOptions = computed<SelectMenuOption<string>[]>(() =>
    appTemplates.value.map((template) => ({
        value: template.slug,
        label: template.name,
    })),
);

// What code can be built with: Railpack, its own Dockerfile or Compose file,
// a prebuilt image instead, or ServerOS's setup for a framework.
const GENERIC = [
    'auto-build',
    'custom-dockerfile',
    'compose-file',
    'docker-image',
];
const setupOptions = computed<
    (SelectMenuOption<string> | SelectMenuGroup<string>)[]
>(() => {
    const labels: Record<string, string> = {
        'auto-build': trans('Railpack'),
        'custom-dockerfile': trans('Its Dockerfile'),
        'compose-file': trans('Its Compose file'),
        'docker-image': trans('A prebuilt image'),
    };
    const hints: Record<string, string> = {
        'auto-build': trans('works it out from the code'),
        'docker-image': trans('nothing is built'),
    };
    const generic = GENERIC.map((slug) => templateBySlug(slug))
        .filter((template): template is ServiceTemplate => template !== null)
        .map((template) => ({
            value: template.slug,
            label: labels[template.slug],
            hint: template.needsUpdate
                ? trans('needs a newer daemon')
                : hints[template.slug],
            disabled: Boolean(template.needsUpdate),
        }));
    const frameworks = appTemplates.value
        .filter((template) => !GENERIC.includes(template.slug))
        .map((template) => ({ value: template.slug, label: template.name }));

    return [
        { label: trans('From your code'), options: generic },
        {
            label: trans('ServerOS’s setup for a framework'),
            options: frameworks,
        },
    ];
});

// Your own code: a repository, a folder or .zip, or a prebuilt image.
const repoInput = ref('');
const repoField = ref<HTMLInputElement | null>(null);
const detection = ref<Detection | null>(null);
const detectError = ref<string | null>(null);
const scanning = ref<{ label: string; viaMachine: boolean } | null>(null);
// The configure step shows what the scan found, rather than a repository field.
const fromScan = ref(false);
// A setup chosen before the scan (from the library), kept over what it finds.
const preferredSetup = ref<string | null>(null);
let scanToken = 0;

const github = ref<GitHubRepositories | null>(null);
const gitlab = ref<GitLabRepositories | null>(null);
const branches = ref<{ repo: string; names: string[] } | null>(null);

const branchOptions = computed<SelectMenuOption<string>[]>(() => {
    const found = detection.value;

    if (!found || found.upload || branches.value?.repo !== found.repo) {
        return [];
    }

    const names =
        !found.branch || branches.value.names.includes(found.branch)
            ? branches.value.names
            : [found.branch, ...branches.value.names];

    return names.map((name) => ({ value: name, label: name }));
});

async function loadRepositories(): Promise<void> {
    await Promise.all([loadGitHub(), loadGitLab()]);
}

async function loadGitLab(): Promise<void> {
    if (gitlab.value) {
        return;
    }

    try {
        const response = await request(
            onWorkspace(gitlabRoutes.repositories(page.props.workspace.slug))
                .url,
        );

        gitlab.value = response.ok
            ? await response.json()
            : { connected: false, accounts: [], repositories: [] };
    } catch {
        gitlab.value = { connected: false, accounts: [], repositories: [] };
    }
}

async function loadGitHub(): Promise<void> {
    if (github.value || !page.props.githubApp) {
        return;
    }

    try {
        const response = await request(
            onWorkspace(githubRoutes.repositories(page.props.workspace.slug))
                .url,
        );

        github.value = response.ok
            ? await response.json()
            : { connected: false, accounts: [], repositories: [] };
    } catch {
        github.value = { connected: false, accounts: [], repositories: [] };
    }
}

async function loadBranches(found: Detection): Promise<void> {
    const match = found.upload
        ? null
        : found.repo.match(/^https:\/\/github\.com\/([^/]+)\/(.+?)(?:\.git)?$/);
    const fromGitLab =
        !found.upload && !match && found.repo.startsWith('https://');

    if (!match && !fromGitLab) {
        return;
    }

    try {
        const response = await request(
            onWorkspace(
                match
                    ? githubRoutes.branches({
                          workspace: page.props.workspace.slug,
                          owner: match[1],
                          repo: match[2],
                      })
                    : gitlabRoutes.branches(page.props.workspace.slug, {
                          query: { repo: found.repo },
                      }),
            ).url,
        );
        const names = response.ok ? (await response.json()).branches : null;

        // An answer for a repository that's no longer shown is dropped.
        if (names && detection.value?.repo === found.repo) {
            branches.value = { repo: found.repo, names };
        }
    } catch {}
}

// A private repository over SSH: the machine makes a deploy key for the app,
// the person adds it to the repository, and the machine reads it with it.
type SshSource = {
    repo: string;
    service: string;
    publicKey: string | null;
    branch: string;
    commit: string | null;
    ref: string | null;
    busy: 'key' | 'resolve' | 'inspect' | null;
    error: string | null;
};
const ssh = ref<SshSource | null>(null);
const sshSetup = ref('');
// The deploy key belongs to the app's name, so it can't change after.
const lockedService = ref(false);

const zip = useZipUpload();
const packing = ref(false);
const packProgress = ref(0);
const uploadName = ref('');
const fileInput = ref<HTMLInputElement | null>(null);
const folderPicker = ref<HTMLInputElement | null>(null);
const dragging = ref(0);

const busy = computed(
    () => packing.value || zip.uploading.value || scanning.value !== null,
);

function resetOwnCode(): void {
    scanToken++;
    scanning.value = null;
    detection.value = null;
    detectError.value = null;
    ssh.value = null;
    sshSetup.value = '';
    fromScan.value = false;
    preferredSetup.value = null;
}

function importRepository(text: string): void {
    const repository = text.trim();
    if (!repository || busy.value) return;

    category.value = OWN_CODE;
    repoInput.value = repository;

    if (isSshRepository(repository)) {
        startSsh(repository);

        return;
    }

    void scan({ repository });
}

function submitRepo(): void {
    const text = repoInput.value.trim();
    if (!text) {
        repoField.value?.focus();

        return;
    }
    if (!looksLikeRepository(text) && !/^[\w.-]+\/[\w.-]+$/.test(text)) {
        detectError.value = trans(
            'Paste the repository’s address, like https://github.com/you/app or git@gitlab.com:you/app.git.',
        );

        return;
    }
    importRepository(text);
}

type ScanInput = {
    repository: string;
    ref?: string | null;
    baseDir?: string | null;
    service?: string | null;
};

/**
 * Works out what a repository is: GitHub's API for a github.com one, the
 * machine for any other host (it fetches the repository, with the app's
 * deploy key for an SSH address). Opens the setup with what it found.
 */
async function scan(input: ScanInput, keep = false): Promise<void> {
    const token = ++scanToken;
    const overSsh = isSshRepository(input.repository);
    scanning.value = {
        label: repositoryLabel(input.repository).split('/tree/')[0],
        viaMachine: overSsh,
    };
    detectError.value = null;

    try {
        let found: Detection | null = null;
        let inspect = overSsh;

        if (!overSsh) {
            const repository =
                input.ref || input.baseDir
                    ? `${input.repository.replace(/\.git$/, '').split('/tree/')[0]}/tree/${input.ref ?? detection.value?.branch ?? 'HEAD'}${input.baseDir ? `/${input.baseDir}` : ''}`
                    : input.repository;
            const response = await request(
                onWorkspace(servicesRoutes.detect(page.props.workspace.slug))
                    .url,
                { repository, machine: target.value },
            );
            const body = await response.json().catch(() => ({}));
            if (token !== scanToken) return;

            if (response.ok) {
                found = body as Detection;
            } else if (response.status === 422 && body.inspect === true) {
                inspect = true;
            } else {
                detectError.value = describe(response.status, body);

                return;
            }
        }

        if (inspect) {
            scanning.value = { ...scanning.value, viaMachine: true };
            found = await inspectRepository(input, token);
        }
        if (token !== scanToken || !found) return;

        continueFromScan(found, { keep, service: input.service ?? null });
    } catch (cause) {
        if (token === scanToken) detectError.value = errorMessage(cause);
    } finally {
        if (token === scanToken) scanning.value = null;
    }
}

type MachineAnswer = {
    jobId?: string;
    state?: string;
    publicKey?: string;
    commit?: string;
    ref?: string | null;
    detection?: Detection;
    error?: string;
    nextStep?: string;
};

/** Runs a short job on the machine and waits for its answer, asking every 2 s. */
async function machineJob(
    url: string,
    body: Record<string, unknown>,
    token: () => boolean,
    seconds: number,
): Promise<{ answer: MachineAnswer | null; error: string | null }> {
    const machineId = target.value;
    const response = await request(url, body).catch(() => null);
    if (!response) {
        return {
            answer: null,
            error: trans('Couldn’t reach ServerOS. Try again.'),
        };
    }
    let answer = (await response.json().catch(() => ({}))) as MachineAnswer;
    if (!response.ok) {
        return { answer: null, error: describe(response.status, answer) };
    }

    const deadline = Date.now() + seconds * 1000;
    while (
        (answer.state === 'queued' || answer.state === 'running') &&
        answer.jobId &&
        Date.now() < deadline
    ) {
        await sleep(2000);
        if (!token()) return { answer: null, error: null };
        const next = await request(
            onWorkspace(
                machinesRoutes.deploy.job({
                    workspace: page.props.workspace.slug,
                    machine: machineId,
                    job: answer.jobId,
                }),
            ).url,
        ).catch(() => null);
        if (next?.ok) answer = (await next.json()) as MachineAnswer;
    }
    if (answer.state === 'queued' || answer.state === 'running') {
        return {
            answer: null,
            error: trans(
                'The machine hasn’t answered yet. Check it’s online, then try again.',
            ),
        };
    }
    if (answer.error) {
        return {
            answer: null,
            error: [answer.error, answer.nextStep]
                .filter(Boolean)
                .map((part) => part!.replace(/\.?$/, '.'))
                .join(' '),
        };
    }

    return { answer, error: null };
}

async function inspectRepository(
    input: ScanInput,
    token: number,
): Promise<Detection | null> {
    const { answer, error: problem } = await machineJob(
        onWorkspace(
            machinesRoutes.deploy.inspect({
                workspace: page.props.workspace.slug,
                machine: target.value,
            }),
        ).url,
        {
            repository: input.repository,
            ref: input.ref ?? null,
            base_dir: input.baseDir ?? null,
            service: input.service ?? null,
        },
        () => token === scanToken && open.value,
        200,
    );
    if (token !== scanToken) return null;
    if (problem) detectError.value = problem;

    return answer?.detection ?? null;
}

function cancelScan(): void {
    scanToken++;
    scanning.value = null;
}

/** Another branch or folder of the same code: looks again, keeping what's been filled in. */
function rescan(change: { branch?: string; folder?: string }): void {
    const found = detection.value;
    if (!found || found.upload || busy.value) return;
    void scan(
        {
            repository: found.repo,
            ref: change.branch ?? found.branch,
            baseDir:
                change.folder !== undefined
                    ? change.folder || null
                    : (found.baseDir ?? null),
            service: lockedService.value ? form.value.service : null,
        },
        true,
    );
}

function startSsh(repo: string): void {
    resetOwnCode();
    ssh.value = {
        repo,
        service: freeName(repoServiceName(repo) || 'app'),
        publicKey: null,
        branch: '',
        commit: null,
        ref: null,
        busy: null,
        error: null,
    };
}

async function makeKey(): Promise<void> {
    const current = ssh.value;
    if (!current || current.busy) return;
    current.busy = 'key';
    current.error = null;
    const { answer, error: problem } = await machineJob(
        onWorkspace(
            machinesRoutes.deploy.key({
                workspace: page.props.workspace.slug,
                machine: target.value,
            }),
        ).url,
        { service: current.service },
        () => ssh.value === current && open.value,
        60,
    );
    current.busy = null;
    current.error = problem;
    if (answer?.publicKey) current.publicKey = answer.publicKey;
}

// Newer daemons read the repository with the key and work out what it is.
async function inspectSsh(): Promise<void> {
    const current = ssh.value;
    if (!current || current.busy) return;
    current.busy = 'inspect';
    current.error = null;
    await scan({
        repository: current.repo,
        ref: current.branch || null,
        service: current.service,
    });
    if (ssh.value === current) current.busy = null;
}

// Older daemons only look up the branch's commit; the setup is chosen by hand.
async function lookUpBranch(): Promise<void> {
    const current = ssh.value;
    if (!current || current.busy) return;
    current.busy = 'resolve';
    current.error = null;
    const body: Record<string, string> = {
        service: current.service,
        repo: current.repo,
    };
    if (current.branch) body.ref = current.branch;
    const { answer, error: problem } = await machineJob(
        onWorkspace(
            machinesRoutes.deploy.resolve({
                workspace: page.props.workspace.slug,
                machine: target.value,
            }),
        ).url,
        body,
        () => ssh.value === current && open.value,
        60,
    );
    current.busy = null;
    current.error = problem;
    if (answer?.commit) {
        current.commit = answer.commit;
        current.ref = answer.ref ?? null;
    }
}

function continueSsh(): void {
    const current = ssh.value;
    const template = templateBySlug(sshSetup.value);
    if (!current?.commit || !template) return;
    choose(template, { repo: current.repo, commit: current.commit });
    form.value.service = current.service;
    suggested.value = { service: current.service, port: form.value.port };
    lockedService.value = true;
}

const canInspectSsh = computed(() => capabilities.value?.canInspect ?? true);

function uploadLabel(files: PickedFile[]): string {
    if (files.length === 1) return files[0].file.name;
    const name = archiveName(files);

    return name === 'app.zip'
        ? trans(':count files', { count: String(files.length) })
        : name.replace(/\.zip$/, '');
}

async function takeFiles(files: PickedFile[]): Promise<void> {
    if (busy.value) return;
    category.value = OWN_CODE;
    resetOwnCode();

    if (files.length === 0) {
        detectError.value = trans(
            'Nothing to upload once dependencies and build folders are left out.',
        );

        return;
    }

    uploadName.value = uploadLabel(files);
    let file = files[0].file;

    if (!(files.length === 1 && isZip(file))) {
        packing.value = true;
        packProgress.value = 0;
        try {
            file = await pack(files, (done) => (packProgress.value = done));
        } catch (cause) {
            detectError.value =
                cause instanceof PackError
                    ? cause.message
                    : errorMessage(cause);

            return;
        } finally {
            packing.value = false;
        }
    }

    const found = await zip.upload(target.value, file);
    if (fileInput.value) fileInput.value.value = '';
    if (folderPicker.value) folderPicker.value.value = '';
    if (!found) {
        detectError.value = zip.error.value;

        return;
    }
    continueFromScan(found as Detection);
}

async function onDrop(transfer: DataTransfer | null): Promise<void> {
    dragging.value = 0;
    void takeFiles(await fromDrop(transfer));
}

function onPick(event: Event): void {
    void takeFiles(fromInput((event.target as HTMLInputElement).files));
}

const uploadPhase = computed(() => {
    if (packing.value) {
        return {
            text: trans('Packing :name…', { name: uploadName.value }),
            progress: packProgress.value,
        };
    }
    if (zip.uploading.value) {
        return zip.progress.value < 1
            ? {
                  text: trans('Uploading :name…', { name: uploadName.value }),
                  progress: zip.progress.value,
              }
            : { text: trans('Looking inside…'), progress: null };
    }

    return null;
});

watch(category, (next) => {
    if (next === OWN_CODE) {
        void loadRepositories();
    }
});

// The setup being configured.
const selected = ref<ServiceTemplate | null>(null);
const form = ref({
    service: '',
    port: '' as string | number,
    repo: '',
    commit: '',
    branch: null as string | null,
    base_dir: '',
    compose_file: '',
    php: '',
    domain: '',
    wildcard: false,
    proxy: '',
    https: true,
    env: {} as Record<string, string | boolean>,
});
const healthDefaults = ref<HealthForm>(
    defaultHealth({ kind: 'app', web: true, health: '/' }, null, true),
);
const build = ref<BuildForm>(emptyBuild(healthDefaults.value));
const envRows = ref<EnvRow[]>([]);
const importExample = ref(false);
const addressMode = ref<AddressMode>('free');
const advancedOpen = ref(false);
// A template's settings that have defaults, shown when someone asks.
const fieldsOpen = ref(false);
// What the deploy said when it was turned down for Docker missing.
const dockerMessage = ref<string | null>(null);
const errors = ref<Record<string, string>>({});
const submitting = ref(false);

watch(
    () => errors.value.form,
    (message) => {
        if (message) {
            toast.error(message);
        }
    },
);

const method = computed(() =>
    selected.value ? methodOf(selected.value) : 'dockerfile',
);
const isAppLike = computed(
    () => selected.value?.kind === 'app' || selected.value?.kind === 'image',
);
const uploadSource = computed(() =>
    detection.value?.upload && form.value.repo === detection.value.repo
        ? detection.value.upload
        : null,
);
// Each setting can be kept to the build or the running app where the build reads them.
const showFlags = computed(
    () =>
        canDeployBuild.value &&
        ['railpack', 'dockerfile', 'template'].includes(method.value),
);
const offersFree = computed(
    () =>
        Boolean(selected.value?.web) &&
        isAppLike.value &&
        canDeployBuild.value &&
        freeAddress.value !== null,
);
const buildOptions = computed(() => ({
    canDeployBuild: canDeployBuild.value,
    upload: uploadSource.value !== null,
    web: Boolean(selected.value?.web),
}));
const advancedChanged = computed(() =>
    changedCount(
        build.value,
        method.value,
        healthDefaults.value,
        buildOptions.value,
    ),
);

const suggested = ref<{ service: string; port: string | number } | null>(null);
const nameBase = ref('');

function freeName(base: string): string {
    if (!taken.value.services.includes(base)) {
        return base;
    }

    const stem = base.slice(0, 59);
    let n = 2;

    while (taken.value.services.includes(`${stem}-${n}`)) {
        n++;
    }

    return `${stem}-${n}`;
}

function freePort(port: number): number {
    let free = port;

    while (taken.value.ports.includes(free) && free < 65535) {
        free++;
    }

    return free;
}

async function loadTaken(): Promise<void> {
    const machineId = target.value;

    try {
        const response = await request(
            onWorkspace(
                machinesRoutes.deploy.taken({
                    workspace: page.props.workspace.slug,
                    machine: machineId,
                }),
            ).url,
        );

        if (!response.ok || machineId !== target.value) {
            return;
        }

        const body = (await response.json()) as {
            services: string[];
            ports: number[];
            proxy?: string | null;
            canChooseProxy?: boolean;
            canChooseHttps?: boolean;
            canDeployBuild?: boolean;
            canInspect?: boolean;
            docker?: { installed: boolean | null; canInstall: boolean };
            domains?: { domain: string; app: string }[];
            freeAddress?: string | null;
            machines?: DeployMachine[];
        };
        taken.value = { services: body.services, ports: body.ports };
        freeAddress.value = body.freeAddress ?? null;
        machineProxy.value = body.proxy ?? null;
        canChooseProxy.value = Boolean(body.canChooseProxy);
        canChooseHttps.value = Boolean(body.canChooseHttps);
        capabilities.value = {
            canDeployBuild: body.canDeployBuild ?? false,
            canInspect: body.canInspect ?? false,
            docker: body.docker ?? { installed: null, canInstall: false },
            domains: body.domains ?? [],
        };
        if (body.machines?.length) machines.value = body.machines;
    } catch {
        return;
    }

    const template = selected.value;

    if (
        step.value === 'configure' &&
        template &&
        suggested.value &&
        form.value.service === suggested.value.service &&
        form.value.port === suggested.value.port &&
        !lockedService.value
    ) {
        form.value.service = freeName(
            nameBase.value || suggested.value.service,
        );

        if (template.portEditable && template.kind === 'compose') {
            form.value.port = freePort(template.port);
        }

        suggested.value = {
            service: form.value.service,
            port: form.value.port,
        };
    }
}

watch(target, () => {
    if (open.value) void loadTaken();
});

const canSwitchMachine = computed(
    () => !props.backCloses && machines.value.length > 1,
);

const replacesExisting = computed(
    () =>
        step.value === 'configure' &&
        taken.value.services.includes(form.value.service),
);

const portTaken = computed(
    () =>
        Boolean(selected.value?.portEditable) &&
        selected.value?.kind === 'compose' &&
        !replacesExisting.value &&
        taken.value.ports.includes(Number(form.value.port)),
);

function repoServiceName(repo: string): string {
    const name = repo
        .replace(/\.git$/, '')
        .replace(/\/tree\/.*$/, '')
        .split(/[/:]/)
        .pop()
        ?.toLowerCase()
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/^[^a-z]+|-+$/g, '');

    return (name ?? '').slice(0, 63);
}

function serviceNameFor(template: ServiceTemplate): string {
    const name =
        template.slug === 'custom-dockerfile' ||
        template.slug === 'auto-build' ||
        template.slug === 'docker-image' ||
        template.compose
            ? 'app'
            : template.slug;

    return name.replace(/[^a-z0-9-]/g, '-').slice(0, 63);
}

/** The port a setup starts with: what the scan found, or its own. */
function defaultPort(
    template: ServiceTemplate,
    found: Detection | null,
): number | '' {
    if (template.kind === 'compose') {
        return template.portEditable ? freePort(template.port) : template.port;
    }
    if (!template.portEditable) return template.port;
    const how = methodOf(template);
    // A newer daemon reads the port itself (EXPOSE, or what Compose publishes).
    if (how === 'compose') {
        return (
            found?.composePort ?? (canDeployBuild.value ? '' : template.port)
        );
    }
    if (how === 'image') return canDeployBuild.value ? '' : template.port;
    if (how === 'dockerfile') {
        return found?.port ?? (canDeployBuild.value ? '' : template.port);
    }

    return template.port;
}

function templateEnv(
    template: ServiceTemplate,
): Record<string, string | boolean> {
    return Object.fromEntries(
        template.env.map((entry) => [
            entry.key,
            entry.accept ? false : entry.default,
        ]),
    );
}

/**
 * Switches the setup, keeping what isn't about how it's built: the name,
 * the address and the settings.
 */
function applyTemplate(template: ServiceTemplate): void {
    const found = fromScan.value ? detection.value : null;
    const same = selected.value?.slug === template.slug;
    selected.value = template;
    form.value.port = defaultPort(template, found);
    form.value.compose_file = template.compose
        ? (found?.composeFile ?? template.compose)
        : '';
    // A Compose file is named by its path from the root instead.
    form.value.base_dir = template.compose ? '' : (found?.baseDir ?? '');
    form.value.php =
        found?.php && found.template === template.slug ? found.php : '';
    // Looking at another branch or folder keeps what was typed in.
    if (!same) form.value.env = templateEnv(template);
    const path =
        found?.healthPath && found.template === template.slug
            ? found.healthPath
            : null;
    healthDefaults.value = defaultHealth(template, path, canDeployBuild.value);
    build.value = {
        ...build.value,
        health: { ...healthDefaults.value },
        image: template.kind === 'image' ? build.value.image : '',
    };
    if (offersFreeFor(template)) {
        addressMode.value =
            methodOf(template) === 'compose' && form.value.port === ''
                ? 'none'
                : 'free';
    }
    errors.value = {};
}

function offersFreeFor(template: ServiceTemplate): boolean {
    return (
        template.web &&
        (template.kind === 'app' || template.kind === 'image') &&
        canDeployBuild.value &&
        freeAddress.value !== null
    );
}

/** A fresh setup of a template: from the library, or for code just scanned. */
function choose(
    template: ServiceTemplate | null | undefined,
    from: {
        repo: string;
        commit: string;
        branch?: string | null;
        baseDir?: string | null;
        name?: string;
    } | null = null,
): void {
    if (!template) {
        return;
    }

    if (!from) {
        fromScan.value = false;
        detection.value = null;
    }
    lockedService.value = false;
    nameBase.value = from
        ? from.name || repoServiceName(from.repo) || serviceNameFor(template)
        : serviceNameFor(template);
    form.value = {
        service: freeName(nameBase.value),
        port: '',
        repo: from?.repo ?? '',
        commit: from?.commit ?? '',
        branch: from?.branch ?? null,
        base_dir: from?.baseDir ?? '',
        compose_file: '',
        php: '',
        domain: '',
        wildcard: false,
        proxy: '',
        https: true,
        env: {},
    };
    build.value = emptyBuild(healthDefaults.value);
    envRows.value = [];
    importExample.value = false;
    addressMode.value = 'free';
    advancedOpen.value = false;
    fieldsOpen.value = false;
    dockerMessage.value = null;
    selected.value = null;
    applyTemplate(template);
    suggested.value = { service: form.value.service, port: form.value.port };
    step.value = 'configure';
}

/** Opens the setup for what a scan or an upload found. */
function continueFromScan(
    found: Detection,
    options: { keep?: boolean; service?: string | null } = {},
): void {
    const keep = options.keep && step.value === 'configure';
    detection.value = found;
    fromScan.value = true;
    const slug =
        preferredSetup.value ??
        found.template ??
        // An upload isn't matched against Railpack: build what it can't name with it.
        (canDeployBuild.value ? 'auto-build' : 'custom-dockerfile');
    const template =
        templateBySlug(slug) ?? templateBySlug('custom-dockerfile');
    if (!template) return;

    if (keep) {
        form.value.repo = found.repo;
        form.value.commit = found.commit ?? '';
        form.value.branch = found.branch;
        applyTemplate(template);
    } else {
        // An app in a folder of a monorepo is named after the folder.
        const name = found.upload
            ? repoServiceName(found.upload.filename.replace(/\.zip$/i, ''))
            : repoServiceName(found.baseDir || found.repo);
        choose(template, {
            repo: found.repo,
            commit: found.commit ?? '',
            branch: found.upload ? null : found.branch,
            baseDir: found.baseDir ?? null,
            name: name || 'app',
        });
        if (options.service) {
            form.value.service = options.service;
            suggested.value = {
                service: options.service,
                port: form.value.port,
            };
            lockedService.value = true;
        }
    }
    void loadBranches(found);
}

function changeSetup(slug: string): void {
    const template = templateBySlug(slug);
    if (template && template.slug !== selected.value?.slug) {
        applyTemplate(template);
    }
}

function openAdvanced(): void {
    advancedOpen.value = true;
    void nextTick(() =>
        document
            .getElementById('deploy-advanced')
            ?.scrollIntoView({ block: 'start', behavior: 'smooth' }),
    );
}

// The settings a .env.example suggests, minus the ones the setup fills in itself.
const OWN_SETTINGS = ['PORT', 'HOST', 'DOCKER_BRIDGE'];
const exampleVariables = computed(() => {
    const own = new Set([
        ...OWN_SETTINGS,
        ...(selected.value?.env.map((entry) => entry.key) ?? []),
    ]);

    return (detection.value?.envExample?.variables ?? []).filter(
        (variable) => !own.has(variable.name),
    );
});

watch(importExample, (on) => {
    const examples = exampleVariables.value;
    if (on) {
        const have = new Set(envRows.value.map((row) => row.name));
        envRows.value = [
            ...examples
                .filter((variable) => !have.has(variable.name))
                .map((variable) => envRow(variable.name, variable.value)),
            ...envRows.value,
        ];
    } else {
        // Takes back the ones still as the example had them.
        envRows.value = envRows.value.filter(
            (row) =>
                !examples.some(
                    (variable) =>
                        variable.name === row.name &&
                        variable.value === row.value,
                ),
        );
    }
});

// An image's name makes a good app name, until someone types their own.
watch(
    () => build.value.image,
    (image) => {
        if (
            selected.value?.kind !== 'image' ||
            lockedService.value ||
            !suggested.value ||
            form.value.service !== suggested.value.service
        ) {
            return;
        }
        const name = image
            .replace(/@.*$/, '')
            .replace(/:[^/]*$/, '')
            .split('/')
            .pop()
            ?.toLowerCase()
            .replace(/[^a-z0-9-]+/g, '-')
            .replace(/^[^a-z]+|-+$/g, '')
            .slice(0, 63);
        form.value.service = freeName(name || 'app');
        suggested.value = { ...suggested.value, service: form.value.service };
    },
);

function back(): void {
    if (props.backCloses) {
        open.value = false;

        return;
    }
    if (fromScan.value || selected.value?.kind === 'image') {
        category.value = OWN_CODE;
    }
    step.value = 'pick';
    nextTick(() =>
        (isOwnCode.value ? repoField.value : searchInput.value)?.focus(),
    );
}

function errorFor(key: string): string | undefined {
    return errors.value[key];
}

// An app's template settings that have a default are filled in quietly;
// only the ones that need an answer show until someone opens them.
function needsInput(entry: TemplateField): boolean {
    return entry.accept || (entry.required && entry.default === '');
}

const shownFields = computed(() => {
    const fields = selected.value?.env ?? [];

    return isAppLike.value && !fieldsOpen.value
        ? fields.filter(needsInput)
        : fields;
});
const filledFields = computed(() =>
    isAppLike.value && !fieldsOpen.value
        ? (selected.value?.env ?? []).filter((entry) => !needsInput(entry))
        : [],
);
const filledNames = computed(() => {
    const names = filledFields.value.map((entry) => entry.key);

    return names.length > 4
        ? trans(':names and :count more', {
              names: names.slice(0, 3).join(', '),
              count: String(names.length - 3),
          })
        : names.join(', ');
});

const missingAcceptance = computed(
    () =>
        selected.value?.env.some(
            (entry) => entry.accept && form.value.env[entry.key] !== true,
        ) ?? false,
);

// Docker missing on the machine: install it from here (newer daemons).
const showDocker = computed(
    () =>
        dockerMessage.value !== null ||
        (capabilities.value?.docker.installed === false &&
            capabilities.value.docker.canInstall),
);

function dockerInstalled(): void {
    errors.value = Object.fromEntries(
        Object.entries(errors.value).filter(([key]) => key !== 'docker'),
    );
    void loadTaken();
}

const ADVANCED_KEYS = /^(port|health_path|dockerfile|template|build\.)/;

async function deploy(): Promise<void> {
    const template = selected.value;

    if (!template || submitting.value) {
        return;
    }

    const code = template.kind === 'app';
    const image = template.kind === 'image';

    // An SSH repository deploys a commit its machine looked up with the app's deploy key.
    if (
        code &&
        !uploadSource.value &&
        isSshRepository(form.value.repo) &&
        !/^[0-9a-f]{40}$/i.test(form.value.commit)
    ) {
        preferredSetup.value = template.slug;
        const keep = preferredSetup.value;
        category.value = OWN_CODE;
        const service = form.value.service;
        startSsh(form.value.repo);
        preferredSetup.value = keep;
        sshSetup.value = keep;
        if (ssh.value) {
            ssh.value.branch = form.value.commit;
            ssh.value.service = service || ssh.value.service;
        }
        step.value = 'pick';

        return;
    }

    submitting.value = true;
    errors.value = {};
    const machineId = target.value;

    const env = Object.fromEntries(
        Object.entries(form.value.env).map(([key, value]) => [
            key,
            typeof value === 'boolean' ? (value ? 'true' : '') : value,
        ]),
    );
    const settings = envPayload(envRows.value);
    const wantsDomain = offersFree.value
        ? addressMode.value === 'domain'
        : true;
    const domain =
        template.web && wantsDomain && form.value.domain
            ? bareDomain(form.value.domain)
            : null;
    const port =
        form.value.port === '' || form.value.port === null
            ? null
            : Number(form.value.port);

    try {
        const response = await request(
            onWorkspace(
                machinesRoutes.deploy({
                    workspace: page.props.workspace.slug,
                    machine: machineId,
                }),
            ).url,
            {
                template: template.slug,
                service: form.value.service,
                port: template.portEditable ? port : null,
                repo: code ? form.value.repo : null,
                commit: code ? form.value.commit || null : null,
                branch: code && form.value.branch ? form.value.branch : null,
                health_path:
                    code || image ? build.value.health.path || '/' : null,
                compose_file: template.compose
                    ? form.value.compose_file || null
                    : null,
                base_dir:
                    code && !template.compose
                        ? form.value.base_dir || null
                        : null,
                php: code && form.value.php ? form.value.php : null,
                domain,
                wildcard: Boolean(domain && form.value.wildcard),
                proxy:
                    domain &&
                    canChooseProxy.value &&
                    form.value.proxy &&
                    form.value.proxy !== (machineProxy.value ?? 'caddy')
                        ? form.value.proxy
                        : null,
                https: domain && canChooseHttps.value ? form.value.https : null,
                env,
                extra_env: isAppLike.value ? settings.text || null : null,
                ...(isAppLike.value &&
                showFlags.value &&
                Object.keys(settings.flags).length
                    ? { env_flags: settings.flags }
                    : {}),
                ...(offersFree.value
                    ? { free_address: addressMode.value !== 'none' }
                    : {}),
                ...(isAppLike.value
                    ? {
                          build: buildPayload(
                              build.value,
                              method.value,
                              healthDefaults.value,
                              buildOptions.value,
                          ),
                      }
                    : {}),
            },
        );
        const body = await response.json().catch(() => ({}));

        if (response.status === 422 && body.errors) {
            errors.value = Object.fromEntries(
                Object.entries(body.errors as Record<string, string[]>).map(
                    ([key, messages]) => [key, messages[0]],
                ),
            );
            if (errors.value.docker) dockerMessage.value = errors.value.docker;
            if (
                Object.keys(errors.value).some((key) => key.startsWith('env.'))
            ) {
                fieldsOpen.value = true;
            }
            if (
                Object.keys(errors.value).some((key) => ADVANCED_KEYS.test(key))
            ) {
                advancedOpen.value = true;
            }
            void nextTick(() =>
                document
                    .querySelector(
                        '#deploy-configure [role="alert"], #deploy-configure .text-status-down',
                    )
                    ?.scrollIntoView({ block: 'center', behavior: 'smooth' }),
            );

            return;
        }

        if (!response.ok) {
            errors.value = { form: describe(response.status, body) };

            return;
        }

        result.value = { ...(body as DeployResult), machineId };
        followJob.value = result.value.appUrl ? result.value.jobId : null;
        followed.value = null;
        step.value = followJob.value ? 'follow' : 'done';
        emit('deployed', result.value);
    } catch (cause) {
        errors.value = { form: errorMessage(cause) };
    } finally {
        submitting.value = false;
    }
}

const result = ref<DeployResult | null>(null);
const followJob = ref<string | null>(null);
const followed = ref<FollowedJob | null>(null);
const copied = ref<string | null>(null);

const followState = computed(() => {
    const state = followed.value?.state;
    if (state === 'succeeded') return 'live';
    if (['failed', 'timed_out', 'cancelled', 'refused'].includes(state ?? '')) {
        return 'failed';
    }

    return 'running';
});

async function copy(key: string, value: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        copied.value = key;
        setTimeout(() => {
            if (copied.value === key) {
                copied.value = null;
            }
        }, 1600);
    } catch {}
}

// Another machine, or the first answer from this one: what its daemon can
// do changes the defaults a new app gets, unless they've been changed.
watch(canDeployBuild, (now) => {
    const template = selected.value;
    if (step.value !== 'configure' || !template) return;
    const before = healthDefaults.value;
    healthDefaults.value = defaultHealth(template, before.path, now);
    const health = build.value.health;
    if (
        health.enabled === before.enabled &&
        health.any === before.any &&
        health.startPeriod === before.startPeriod
    ) {
        build.value.health = { ...healthDefaults.value, path: health.path };
    }
    if (form.value.port === '' && !now) form.value.port = template.port;
});

function onDragEnter(): void {
    if (isOwnCode.value && !busy.value && !ssh.value) dragging.value++;
}

function focusFirst(): void {
    if (step.value !== 'pick') return;
    (isOwnCode.value ? repoField.value : searchInput.value)?.focus();
}

watch(
    open,
    (isOpen) => {
        if (isOpen) {
            step.value = 'pick';
            target.value = props.machineId;
            query.value = '';
            category.value = OWN_CODE;
            selected.value = null;
            repoInput.value = '';
            resetOwnCode();
            lockedService.value = false;
            result.value = null;
            followJob.value = null;
            followed.value = null;
            errors.value = {};
            dockerMessage.value = null;
            void loadTaken();
            void loadRepositories();
        } else {
            // Stops any scan still waiting on the machine.
            scanToken++;
        }
    },
    { immediate: true },
);

// Opened from a link that names a template: go straight to setting it up.
watch(
    open,
    (isOpen) => {
        if (isOpen && props.startWith && step.value === 'pick') {
            choose(props.templates.find((t) => t.slug === props.startWith));
        }
    },
    { immediate: true },
);
</script>

<template>
    <DialogRoot v-model:open="open">
        <DialogPortal>
            <DialogOverlay
                class="fixed inset-0 z-50 bg-black/[0.62] backdrop-blur-[3px] data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:animate-in data-[state=open]:fade-in-0"
            />
            <DialogContent
                class="workspace-panel fixed top-1/2 left-1/2 z-50 flex h-[min(680px,calc(100dvh-2rem))] w-[min(920px,calc(100vw-2rem))] -translate-x-1/2 -translate-y-1/2 flex-col overflow-clip rounded-[12px] border-[0.5px] border-[#e2e2e2] bg-white font-ui text-fg-primary shadow-[0_24px_48px_-12px_rgb(0_0_0/0.25),0_1px_2px_rgb(0_0_0/0.1)] outline-none data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=closed]:zoom-out-[0.98] data-[state=open]:animate-in data-[state=open]:fade-in-0 data-[state=open]:zoom-in-[0.98] dark:border-[#2c2c2c] dark:bg-[#161616] dark:shadow-[0_24px_48px_-12px_rgb(0_0_0/0.55),0_1px_2px_rgb(0_0_0/0.5)]"
                @open-auto-focus.prevent="focusFirst"
            >
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    class="sr-only"
                    tabindex="-1"
                    aria-hidden="true"
                    @change="onPick"
                />
                <input
                    ref="folderPicker"
                    type="file"
                    webkitdirectory
                    class="sr-only"
                    tabindex="-1"
                    aria-hidden="true"
                    @change="onPick"
                />

                <!-- Header -->
                <header
                    class="flex shrink-0 flex-wrap items-center gap-x-3.5 gap-y-3 border-b-[0.5px] border-[#e8e8e8] py-[18px] pr-[18px] pl-5 dark:border-[#262626]"
                >
                    <button
                        v-if="step === 'configure'"
                        type="button"
                        :class="iconButton"
                        :aria-label="
                            backCloses ? $t('Back') : $t('Back to the library')
                        "
                        @click="back"
                    >
                        <ArrowLeft class="size-4" :stroke-width="1.75" />
                    </button>
                    <span
                        class="flex size-[38px] shrink-0 items-center justify-center rounded-[7px] shadow-[0_1px_2px_rgb(0_0_0/0.08),0_0_0_0.5px_rgb(0_0_0/0.08)] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.06),0_3px_6px_-2px_rgb(0_0_0/0.35),0_1px_1.5px_rgb(0_0_0/0.55)]"
                        :class="
                            step === 'done' ||
                            (step === 'follow' && followState === 'live')
                                ? 'bg-[#3ec46d]/15 text-[#3ec46d]'
                                : step === 'follow' && followState === 'failed'
                                  ? 'bg-status-down/12 text-status-down'
                                  : 'bg-[#f2f2f2] text-fg-tertiary dark:bg-[#222]'
                        "
                        aria-hidden="true"
                    >
                        <svg
                            v-if="
                                step === 'done' ||
                                (step === 'follow' && followState === 'live')
                            "
                            viewBox="0 0 24 24"
                            class="size-[18px]"
                            fill="none"
                            stroke="currentColor"
                            stroke-width="2.4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                        >
                            <path
                                class="mi-draw"
                                pathLength="1"
                                d="M5 12.5 10 17.5 19 7"
                            />
                        </svg>
                        <CircleAlert
                            v-else-if="
                                step === 'follow' && followState === 'failed'
                            "
                            class="size-[18px]"
                            :stroke-width="2"
                        />
                        <LoaderCircle
                            v-else-if="step === 'follow'"
                            class="size-[18px] animate-spin"
                            :stroke-width="2"
                        />
                        <TemplateLogo
                            v-else-if="step === 'configure' && selected"
                            :icon="selected.icon"
                        />
                        <FilledIcon
                            v-else-if="isOwnCode"
                            name="code-fill"
                            :size="18"
                        />
                        <FilledIcon v-else name="package-fill" :size="18" />
                    </span>
                    <div class="flex min-w-0 flex-1 basis-56 flex-col gap-px">
                        <DialogTitle
                            class="truncate text-[15px]/[22px] font-medium tracking-[-0.01em] text-fg-primary"
                        >
                            <template v-if="step === 'configure' && selected">
                                {{
                                    fromScan && detection
                                        ? $t('Deploy :name', {
                                              name: detection.upload
                                                  ? detection.upload.filename
                                                  : repositoryLabel(
                                                        detection.repo,
                                                    ),
                                          })
                                        : selected.kind === 'image'
                                          ? $t('Run a Docker image')
                                          : $t('Set up :name', {
                                                name: selected.name,
                                            })
                                }}
                            </template>
                            <template v-else-if="step === 'follow'">
                                {{
                                    followState === 'live'
                                        ? $t(':service is live', {
                                              service: form.service,
                                          })
                                        : followState === 'failed'
                                          ? $t(':service didn’t deploy', {
                                                service: form.service,
                                            })
                                          : $t('Deploying :service', {
                                                service: form.service,
                                            })
                                }}
                            </template>
                            <template v-else-if="step === 'done' && result">
                                {{
                                    result.commit
                                        ? $t('Deploying :service at :commit', {
                                              service: form.service,
                                              commit: result.commit.slice(0, 7),
                                          })
                                        : $t('Deploying :service', {
                                              service: form.service,
                                          })
                                }}
                            </template>
                            <template v-else-if="isOwnCode">{{
                                $t('Deploy your code')
                            }}</template>
                            <template v-else>{{
                                $t('Deploy a service')
                            }}</template>
                        </DialogTitle>
                        <DialogDescription
                            class="truncate text-[13px]/[18px] tracking-[-0.005em] text-fg-tertiary"
                        >
                            <template v-if="step === 'configure' && selected">
                                {{
                                    fromScan
                                        ? $t(
                                              'Check what ServerOS found, then deploy. Everything can be changed after.',
                                          )
                                        : selected.description
                                }}
                            </template>
                            <template v-else-if="step === 'follow'">
                                {{
                                    followState === 'running'
                                        ? $t(
                                              'On :machine. Closing this doesn’t stop it.',
                                              { machine: machineName },
                                          )
                                        : $t('On :machine.', {
                                              machine: machineName,
                                          })
                                }}
                            </template>
                            <template v-else-if="step === 'done'">
                                {{
                                    $t(
                                        'Follow it in the Deployments tab. The first deploy pulls images, so give it a minute.',
                                    )
                                }}
                            </template>
                            <template v-else-if="isOwnCode">
                                {{
                                    $t(
                                        'Paste a repository and ServerOS works out how to build and run it.',
                                    )
                                }}
                            </template>
                            <template v-else-if="machineName">
                                {{
                                    $t(
                                        'Runs on :machine. Pick a service, or bring your own code.',
                                        { machine: machineName },
                                    )
                                }}
                            </template>
                            <template v-else>
                                {{
                                    $t(
                                        'Pick a service, or bring your own code.',
                                    )
                                }}
                            </template>
                        </DialogDescription>
                    </div>
                    <label
                        v-if="step === 'pick'"
                        class="order-last flex h-[34px] w-full items-center gap-2 rounded-[6px] border-[0.5px] border-[#dcdcdc] bg-[#f5f5f5] px-2.5 shadow-[inset_0_1px_2px_rgb(0_0_0/0.06)] focus-within:border-[#a3a3a3] sm:order-none sm:w-[272px] dark:border-[#2a2a2a] dark:bg-[#101010] dark:shadow-[inset_0_1px_2px_rgb(0_0_0/0.5)] dark:focus-within:border-[#4a4a4a]"
                    >
                        <FilledIcon
                            name="search-fill"
                            :size="15"
                            class="shrink-0 text-fg-tertiary"
                        />
                        <span class="sr-only">{{ $t('Search services') }}</span>
                        <input
                            ref="searchInput"
                            v-model="query"
                            type="text"
                            role="combobox"
                            aria-expanded="true"
                            aria-controls="deploy-catalogue"
                            :aria-activedescendant="
                                picked && !isOwnCode
                                    ? `template-${picked}`
                                    : undefined
                            "
                            autocomplete="off"
                            spellcheck="false"
                            :placeholder="$t('Search services')"
                            class="h-full min-w-0 flex-1 bg-transparent text-[13px]/5 tracking-[-0.005em] text-fg-primary outline-none placeholder:text-fg-faint"
                            @keydown="onSearchKeydown"
                            @paste="onSearchPaste"
                        />
                    </label>
                    <kbd
                        class="hidden shrink-0 rounded-[5px] border-[0.5px] border-[#dcdcdc] bg-[#f7f7f7] px-[7px] py-0.5 font-ui-mono text-[11px]/4 text-fg-tertiary shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] sm:block dark:border-[#333] dark:bg-[#1f1f1f] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)]"
                        aria-hidden="true"
                        >Esc</kbd
                    >
                </header>

                <!-- Pick: rail + your own code or the catalogue -->
                <div
                    v-if="step === 'pick'"
                    class="flex min-h-0 flex-1 flex-col sm:flex-row"
                >
                    <div
                        class="flex shrink-0 gap-1 overflow-x-auto border-b-[0.5px] border-[#e8e8e8] px-3 py-2 sm:hidden dark:border-[#262626]"
                        role="group"
                        :aria-label="$t('Category')"
                    >
                        <button
                            v-for="option in railOptions"
                            :key="option"
                            type="button"
                            class="h-7 shrink-0 cursor-pointer rounded-[6px] px-2.5 text-[12.5px]/4 font-medium whitespace-nowrap text-fg-tertiary aria-pressed:bg-[#f2f2f2] aria-pressed:text-fg-primary dark:aria-pressed:bg-[#212121]"
                            :aria-pressed="category === option"
                            @click="category = option"
                        >
                            {{ railLabel(option) }}
                        </button>
                    </div>
                    <CategoryRail v-model="category" :counts="counts" />

                    <div
                        id="deploy-catalogue"
                        class="min-h-0 min-w-0 flex-1 overflow-y-auto"
                    >
                        <div
                            v-if="!isOwnCode && looksLikeRepository(query)"
                            class="flex flex-col items-center gap-1 px-5 py-16 text-center"
                        >
                            <p
                                class="text-[13px]/5 font-medium text-fg-primary"
                            >
                                {{ $t('Deploy this repository?') }}
                            </p>
                            <p class="text-[12.5px]/4 text-fg-muted">
                                {{
                                    $t(
                                        'Press Enter and ServerOS looks inside it, then sets it up.',
                                    )
                                }}
                            </p>
                        </div>
                        <TemplateCatalogue
                            v-else-if="!isOwnCode"
                            :groups="groups"
                            :picked="picked"
                            :show-upload="showUploadStrip"
                            :query="query"
                            @pick="(template) => (picked = template.slug)"
                            @choose="choose"
                            @see-all="seeAll"
                            @drop="onDrop"
                            @choose-files="fileInput?.click()"
                        />

                        <!-- Your own code -->
                        <div
                            v-else
                            class="relative flex min-h-full flex-col gap-4 px-5 pt-5 pb-5"
                            @dragenter.prevent="onDragEnter"
                            @dragover.prevent
                            @dragleave.prevent="
                                dragging = Math.max(0, dragging - 1)
                            "
                            @drop.prevent="onDrop($event.dataTransfer)"
                        >
                            <!-- SSH: a deploy key for a private repository -->
                            <div
                                v-if="ssh"
                                class="flex flex-col gap-3 rounded-[10px] border-[0.5px] border-[#e2e2e2] bg-[#fafafa] p-4 text-[13px]/5 dark:border-[#2a2a2a] dark:bg-[#1a1a1a]"
                            >
                                <p class="break-all text-fg-secondary">
                                    {{
                                        $t(
                                            ':repo is read over SSH. The machine makes a deploy key for the app, you add it to the repository, then the machine reads it with that key.',
                                            { repo: ssh.repo },
                                        )
                                    }}
                                </p>
                                <div class="flex flex-col gap-1.5">
                                    <label for="ssh-service" :class="label">{{
                                        $t('App name')
                                    }}</label>
                                    <div :class="field">
                                        <input
                                            id="ssh-service"
                                            v-model.trim="ssh.service"
                                            maxlength="63"
                                            pattern="[a-z][a-z0-9\-]*"
                                            class="font-ui-mono"
                                            :disabled="
                                                !!ssh.publicKey ||
                                                ssh.busy !== null
                                            "
                                        />
                                    </div>
                                </div>
                                <template v-if="ssh.publicKey">
                                    <div class="flex flex-col gap-1.5">
                                        <span :class="label">{{
                                            $t('Deploy key')
                                        }}</span>
                                        <div class="flex items-start gap-2">
                                            <code
                                                class="min-w-0 flex-1 rounded-[7px] border-[0.5px] border-edge-field bg-surface-field p-2 font-ui-mono text-[11.5px]/4 break-all text-fg-secondary"
                                                >{{ ssh.publicKey }}</code
                                            >
                                            <button
                                                type="button"
                                                :class="iconButton"
                                                :aria-label="
                                                    $t('Copy the deploy key')
                                                "
                                                @click="
                                                    copy(
                                                        'ssh-key',
                                                        ssh.publicKey,
                                                    )
                                                "
                                            >
                                                <Check
                                                    v-if="copied === 'ssh-key'"
                                                    class="mi-pop size-3.5 text-status-live"
                                                    :stroke-width="2.25"
                                                />
                                                <Copy
                                                    v-else
                                                    class="mi-copy size-3.5"
                                                    :stroke-width="1.75"
                                                />
                                            </button>
                                        </div>
                                        <p :class="hint">
                                            {{
                                                $t(
                                                    'Add it to the repository as a read-only deploy key: Settings → Deploy keys on GitHub and Gitea, Settings → Repository → Deploy keys on GitLab, Repository settings → Access keys on Bitbucket.',
                                                )
                                            }}
                                        </p>
                                    </div>
                                    <div class="flex flex-col gap-1.5">
                                        <label for="ssh-branch" :class="label">
                                            {{ $t('Branch') }}
                                            <span
                                                class="font-normal text-fg-faint"
                                                >{{
                                                    ' ' +
                                                    $t(
                                                        '(empty for the default one)',
                                                    )
                                                }}</span
                                            >
                                        </label>
                                        <div :class="field">
                                            <input
                                                id="ssh-branch"
                                                v-model.trim="ssh.branch"
                                                class="font-ui-mono"
                                                :placeholder="$t('main')"
                                                autocomplete="off"
                                                spellcheck="false"
                                                @keydown.enter.prevent="
                                                    canInspectSsh
                                                        ? inspectSsh()
                                                        : lookUpBranch()
                                                "
                                            />
                                        </div>
                                    </div>
                                    <template
                                        v-if="!canInspectSsh && ssh.commit"
                                    >
                                        <p class="text-fg-secondary">
                                            {{
                                                $t('Found :ref at :commit.', {
                                                    ref: (
                                                        ssh.ref ?? ''
                                                    ).replace(
                                                        /^refs\/(heads|tags)\//,
                                                        '',
                                                    ),
                                                    commit: ssh.commit.slice(
                                                        0,
                                                        7,
                                                    ),
                                                })
                                            }}
                                        </p>
                                        <div class="flex flex-col gap-1.5">
                                            <label
                                                for="ssh-setup"
                                                :class="label"
                                                >{{ $t('Setup') }}</label
                                            >
                                            <SelectMenu
                                                id="ssh-setup"
                                                v-model="sshSetup"
                                                :options="appTemplateOptions"
                                                :placeholder="
                                                    $t('Choose a setup')
                                                "
                                                :class="select"
                                            />
                                        </div>
                                    </template>
                                </template>
                                <p v-if="ssh.error" :class="error" role="alert">
                                    {{ ssh.error }}
                                </p>
                                <p
                                    v-if="scanning && ssh.busy === 'inspect'"
                                    class="flex items-center gap-1.5"
                                    :class="hint"
                                >
                                    <LoaderCircle
                                        class="size-3.5 animate-spin"
                                        :stroke-width="1.75"
                                        aria-hidden="true"
                                    />
                                    {{
                                        $t(
                                            ':machine is reading it, which can take a minute.',
                                            { machine: machineName },
                                        )
                                    }}
                                </p>
                                <div class="flex justify-end">
                                    <DashButton
                                        size="sm"
                                        :label="$t('Use another repository')"
                                        @click="
                                            resetOwnCode();
                                            repoInput = '';
                                            nextTick(() => repoField?.focus());
                                        "
                                    />
                                </div>
                            </div>

                            <!-- Looking inside -->
                            <div
                                v-else-if="scanning || uploadPhase"
                                class="flex items-center gap-3 rounded-[10px] border-[0.5px] border-[#e2e2e2] bg-[#fafafa] px-4 py-3.5 dark:border-[#2a2a2a] dark:bg-[#1a1a1a]"
                                role="status"
                                aria-live="polite"
                            >
                                <LoaderCircle
                                    class="size-4 shrink-0 animate-spin text-fg-muted"
                                    :stroke-width="2"
                                    aria-hidden="true"
                                />
                                <div class="flex min-w-0 flex-1 flex-col gap-1">
                                    <p
                                        class="truncate text-[13px]/5 font-medium text-fg-primary"
                                    >
                                        {{
                                            uploadPhase
                                                ? uploadPhase.text
                                                : $t('Looking inside :repo…', {
                                                      repo:
                                                          scanning?.label ?? '',
                                                  })
                                        }}
                                    </p>
                                    <div
                                        v-if="
                                            uploadPhase?.progress !== null &&
                                            uploadPhase?.progress !== undefined
                                        "
                                        class="h-1 overflow-hidden rounded-full bg-black/6 dark:bg-white/8"
                                    >
                                        <div
                                            class="h-full rounded-full bg-dash-blue transition-[width] duration-300"
                                            :style="{
                                                width: `${Math.round(uploadPhase.progress * 100)}%`,
                                            }"
                                        />
                                    </div>
                                    <p v-else :class="hint">
                                        {{
                                            scanning?.viaMachine
                                                ? $t(
                                                      ':machine fetches it to read it, which can take a minute.',
                                                      { machine: machineName },
                                                  )
                                                : $t(
                                                      'Reading how it’s built, its port and its settings.',
                                                  )
                                        }}
                                    </p>
                                </div>
                                <DashButton
                                    v-if="scanning"
                                    size="sm"
                                    :label="$t('Cancel')"
                                    @click="cancelScan"
                                />
                            </div>

                            <template v-else>
                                <form
                                    class="flex flex-col gap-1.5"
                                    @submit.prevent="submitRepo"
                                >
                                    <label
                                        for="deploy-repo-input"
                                        :class="label"
                                        >{{ $t('Repository') }}</label
                                    >
                                    <div :class="[field, 'h-[38px]!']">
                                        <FilledIcon
                                            name="branch-fill"
                                            :size="15"
                                            class="shrink-0 text-fg-muted"
                                        />
                                        <input
                                            id="deploy-repo-input"
                                            ref="repoField"
                                            v-model="repoInput"
                                            type="text"
                                            inputmode="url"
                                            autocomplete="off"
                                            spellcheck="false"
                                            :placeholder="
                                                gitlab?.connected
                                                    ? $t(
                                                          'Search repos, or paste a URL',
                                                      )
                                                    : github?.connected
                                                      ? $t(
                                                            'Paste a link, or search your GitHub repositories',
                                                        )
                                                      : 'https://github.com/you/app'
                                            "
                                            class="font-ui-mono text-[12.5px]"
                                            @input="detectError = null"
                                        />
                                    </div>
                                    <p
                                        v-if="detectError"
                                        :class="error"
                                        role="alert"
                                    >
                                        {{ detectError }}
                                    </p>
                                    <p v-else :class="hint">
                                        {{
                                            gitlab?.connected
                                                ? $t(
                                                      'GitHub, GitLab, Gitea, Bitbucket or your own git server. For a private one elsewhere, paste its SSH address, like git@git.example.com:you/app.git.',
                                                  )
                                                : $t(
                                                      'GitHub, GitLab, Gitea, Bitbucket or your own git server. For a private one elsewhere, paste its SSH address, like git@gitlab.com:you/app.git.',
                                                  )
                                        }}
                                    </p>
                                </form>

                                <RepoList
                                    v-if="
                                        page.props.githubApp ||
                                        gitlab?.connected
                                    "
                                    v-model:query="repoInput"
                                    searchless
                                    :github="github"
                                    :gitlab="gitlab"
                                    :available="page.props.githubApp"
                                    :connect-url="
                                        workspacePath(
                                            '/settings/github/connect',
                                        )
                                    "
                                    :gitlab-connect-url="
                                        workspacePath('/integrations')
                                    "
                                    :busy="null"
                                    :disabled="busy"
                                    @import="importRepository"
                                />

                                <div class="mt-1 flex flex-col gap-2">
                                    <h3 :class="sectionTitle">
                                        {{ $t('Or') }}
                                    </h3>
                                    <div class="grid gap-2 sm:grid-cols-2">
                                        <div
                                            class="flex items-center gap-3 rounded-[8px] border-[0.5px] px-3 py-2.5 transition-colors"
                                            :class="
                                                dragging
                                                    ? 'border-[rgb(77_123_255/0.9)] bg-[rgb(51_97_255/0.06)]'
                                                    : 'border-[#e2e2e2] bg-white dark:border-[#2a2a2a] dark:bg-[#1c1c1c]'
                                            "
                                        >
                                            <span
                                                class="flex size-8 shrink-0 items-center justify-center rounded-[6px] bg-[#f2f2f2] text-fg-secondary shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] dark:bg-[#262626]"
                                                aria-hidden="true"
                                            >
                                                <FilledIcon
                                                    name="upload-fill"
                                                />
                                            </span>
                                            <span
                                                class="flex min-w-0 flex-1 flex-col"
                                            >
                                                <span
                                                    class="text-[13px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
                                                    >{{
                                                        $t(
                                                            'Upload a folder or .zip',
                                                        )
                                                    }}</span
                                                >
                                                <span
                                                    class="truncate text-[12px]/4 text-fg-muted"
                                                    >{{
                                                        $t('Or drop it here')
                                                    }}</span
                                                >
                                            </span>
                                            <DropdownMenu>
                                                <DropdownMenuTrigger as-child>
                                                    <DashButton
                                                        size="sm"
                                                        :label="$t('Choose')"
                                                    />
                                                </DropdownMenuTrigger>
                                                <DropdownMenuContent
                                                    class="ds-modal min-w-40 font-ui"
                                                    align="end"
                                                >
                                                    <DropdownMenuItem
                                                        @select="
                                                            folderPicker?.click()
                                                        "
                                                    >
                                                        {{ $t('A folder') }}
                                                    </DropdownMenuItem>
                                                    <DropdownMenuItem
                                                        @select="
                                                            fileInput?.click()
                                                        "
                                                    >
                                                        {{
                                                            $t(
                                                                'Files or a .zip',
                                                            )
                                                        }}
                                                    </DropdownMenuItem>
                                                </DropdownMenuContent>
                                            </DropdownMenu>
                                        </div>
                                        <button
                                            type="button"
                                            class="flex cursor-pointer items-center gap-3 rounded-[8px] border-[0.5px] border-[#e2e2e2] bg-white px-3 py-2.5 text-left transition-colors hover:bg-[#fafafa] focus-visible:outline-2 focus-visible:outline-(--ds-ring) dark:border-[#2a2a2a] dark:bg-[#1c1c1c] dark:hover:bg-[#202020]"
                                            @click="
                                                choose(
                                                    templateBySlug(
                                                        'docker-image',
                                                    ),
                                                )
                                            "
                                        >
                                            <span
                                                class="flex size-8 shrink-0 items-center justify-center rounded-[6px] bg-[#f2f2f2] shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] dark:bg-[#262626]"
                                                aria-hidden="true"
                                            >
                                                <TemplateLogo
                                                    icon="docker"
                                                    :size="17"
                                                />
                                            </span>
                                            <span
                                                class="flex min-w-0 flex-1 flex-col"
                                            >
                                                <span
                                                    class="text-[13px]/[18px] font-medium tracking-[-0.01em] text-fg-primary"
                                                    >{{
                                                        $t('Run a Docker image')
                                                    }}</span
                                                >
                                                <span
                                                    class="truncate text-[12px]/4 text-fg-muted"
                                                    >{{
                                                        $t(
                                                            'From Docker Hub or any registry',
                                                        )
                                                    }}</span
                                                >
                                            </span>
                                            <FilledIcon
                                                name="chevron-right"
                                                :size="14"
                                                class="shrink-0 text-fg-faint"
                                            />
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <!-- Configure -->
                <form
                    v-else-if="step === 'configure' && selected"
                    id="deploy-configure"
                    class="min-h-0 flex-1 overflow-y-auto"
                    novalidate
                    @submit.prevent="deploy"
                >
                    <div
                        class="mx-auto flex w-full max-w-[640px] flex-col gap-5 px-5 py-5"
                    >
                        <DockerInstall
                            v-if="showDocker"
                            :machine-id="target"
                            :machine-name="machineName"
                            :message="dockerMessage"
                            @installed="dockerInstalled"
                        />

                        <p
                            v-if="selected.needsUpdate"
                            class="rounded-[8px] border-[0.5px] border-status-pending/30 bg-status-pending/8 px-3 py-2 text-[12.5px]/4 text-fg-secondary"
                        >
                            {{
                                $t(
                                    'The ServerOS daemon on :machine is too old for this. It updates itself, or run “sudo serverosd update” on it.',
                                    { machine: machineName },
                                )
                            }}
                        </p>

                        <ScanSummary
                            v-if="
                                fromScan && detection && selected.kind === 'app'
                            "
                            v-model:import-example="importExample"
                            :detection="detection"
                            :method="method"
                            :setup-name="selected.name"
                            :icon="selected.icon"
                            :port="form.port"
                            :can-deploy-build="canDeployBuild"
                            :branch-options="branchOptions"
                            :busy="busy"
                            :compose-file="form.compose_file"
                            @rescan="rescan"
                            @change-build="openAdvanced"
                        />
                        <p v-if="fromScan && detectError" :class="error">
                            {{ detectError }}
                        </p>
                        <p
                            v-else-if="!isAppLike && selected.notes"
                            :class="hint"
                        >
                            {{ selected.notes }}
                        </p>

                        <!-- A prebuilt image -->
                        <div
                            v-if="selected.kind === 'image'"
                            class="flex flex-col gap-1.5"
                        >
                            <label for="deploy-image" :class="label">{{
                                $t('Image')
                            }}</label>
                            <div :class="field">
                                <input
                                    id="deploy-image"
                                    v-model.trim="build.image"
                                    class="font-ui-mono"
                                    placeholder="ghcr.io/you/app:1.4"
                                    autocomplete="off"
                                    spellcheck="false"
                                    autofocus
                                />
                            </div>
                            <p v-if="errorFor('build.image')" :class="error">
                                {{ errorFor('build.image') }}
                            </p>
                            <p :class="hint">
                                {{
                                    $t(
                                        'Like nginx:alpine or ghcr.io/you/app:1.4.',
                                    ) + ' '
                                }}
                                <a
                                    :href="
                                        onWorkspace(
                                            settingsRoutes.general(
                                                page.props.workspace.slug,
                                            ),
                                        ).url
                                    "
                                    target="_blank"
                                    class="text-fg-secondary underline decoration-fg-faint underline-offset-2 hover:text-fg-primary"
                                    >{{
                                        $t(
                                            'A private one needs its registry’s login in Settings.',
                                        )
                                    }}</a
                                >
                            </p>
                        </div>

                        <!-- From the library: the repository by hand -->
                        <template v-if="selected.kind === 'app' && !fromScan">
                            <div
                                v-if="uploadSource"
                                class="flex flex-col gap-1.5"
                            >
                                <span :class="label">{{ $t('Code') }}</span>
                                <div
                                    class="dash-field bg-surface-field-readonly text-fg-secondary"
                                >
                                    <FilledIcon
                                        name="zip-fill"
                                        class="shrink-0 text-fg-muted"
                                    />
                                    <span class="min-w-0 truncate">{{
                                        $t(':name, :count files, uploaded', {
                                            name: uploadSource.filename,
                                            count: String(uploadSource.files),
                                        })
                                    }}</span>
                                </div>
                            </div>
                            <div
                                v-else
                                class="grid gap-4 sm:grid-cols-[2fr_1fr]"
                            >
                                <div class="flex flex-col gap-1.5">
                                    <label for="deploy-repo" :class="label">{{
                                        $t('Git repository')
                                    }}</label>
                                    <div :class="field">
                                        <input
                                            id="deploy-repo"
                                            v-model.trim="form.repo"
                                            type="text"
                                            inputmode="url"
                                            required
                                            :readonly="lockedService"
                                            class="font-ui-mono text-[12.5px]"
                                            placeholder="https://github.com/you/app"
                                            autocomplete="off"
                                            spellcheck="false"
                                        />
                                    </div>
                                    <p v-if="errorFor('repo')" :class="error">
                                        {{ errorFor('repo') }}
                                    </p>
                                    <p v-else :class="hint">
                                        {{
                                            $t(
                                                'An HTTPS address, or an SSH one like git@gitlab.com:you/app.git for a private repository.',
                                            )
                                        }}
                                    </p>
                                </div>
                                <div class="flex flex-col gap-1.5">
                                    <label for="deploy-commit" :class="label">{{
                                        $t('Branch, tag or commit')
                                    }}</label>
                                    <div :class="field">
                                        <input
                                            id="deploy-commit"
                                            v-model.trim="form.commit"
                                            maxlength="255"
                                            :readonly="lockedService"
                                            :placeholder="$t('Default branch')"
                                            class="font-ui-mono"
                                        />
                                    </div>
                                    <p v-if="errorFor('commit')" :class="error">
                                        {{ errorFor('commit') }}
                                    </p>
                                </div>
                            </div>
                        </template>
                        <p
                            v-if="
                                fromScan &&
                                (errorFor('repo') ||
                                    errorFor('commit') ||
                                    errorFor('branch'))
                            "
                            :class="error"
                        >
                            {{
                                errorFor('repo') ??
                                errorFor('commit') ??
                                errorFor('branch')
                            }}
                        </p>

                        <div class="grid gap-4 sm:grid-cols-2">
                            <div class="flex flex-col gap-1.5">
                                <label for="deploy-service" :class="label">{{
                                    isAppLike ? $t('Name') : $t('Service name')
                                }}</label>
                                <div :class="field">
                                    <input
                                        id="deploy-service"
                                        v-model.trim="form.service"
                                        :readonly="lockedService"
                                        :title="
                                            lockedService
                                                ? $t(
                                                      'The deploy key belongs to this name.',
                                                  )
                                                : undefined
                                        "
                                        required
                                        maxlength="63"
                                        pattern="[a-z][a-z0-9\-]*"
                                        class="font-ui-mono"
                                    />
                                </div>
                                <p v-if="errorFor('service')" :class="error">
                                    {{ errorFor('service') }}
                                </p>
                                <p v-else-if="replacesExisting" :class="hint">
                                    {{
                                        $t(
                                            ':service is already on this machine. Deploying with this name replaces it and keeps its data. Pick another name to add a second one.',
                                            { service: form.service },
                                        )
                                    }}
                                </p>
                            </div>
                            <div
                                v-if="
                                    selected.portEditable &&
                                    selected.kind === 'compose'
                                "
                                class="flex flex-col gap-1.5"
                            >
                                <label for="deploy-port" :class="label">
                                    {{ $t('Port on this machine') }}
                                </label>
                                <div :class="field">
                                    <input
                                        id="deploy-port"
                                        v-model.number="form.port"
                                        type="number"
                                        min="1"
                                        max="65535"
                                        class="tabular-nums"
                                    />
                                </div>
                                <p v-if="errorFor('port')" :class="error">
                                    {{ errorFor('port') }}
                                </p>
                                <p v-else-if="portTaken" :class="hint">
                                    {{
                                        $t(
                                            'Something on this machine already uses port :port.',
                                            { port: String(form.port) },
                                        )
                                    }}
                                </p>
                            </div>
                        </div>

                        <AddressField
                            v-if="selected.web"
                            v-model:mode="addressMode"
                            v-model:domain="form.domain"
                            v-model:wildcard="form.wildcard"
                            v-model:proxy="form.proxy"
                            v-model:https="form.https"
                            :machine-id="target"
                            :service="form.service"
                            :free-address="freeAddress"
                            :offers-free="offersFree"
                            :machine-proxy="machineProxy"
                            :can-choose-proxy="canChooseProxy"
                            :can-choose-https="canChooseHttps"
                            :taken-domains="capabilities?.domains ?? []"
                            :errors="errors"
                        />

                        <!-- Settings: the template's own, and for an app a .env, each for the build, the running app or both -->
                        <section
                            v-if="selected.env.length || isAppLike"
                            class="flex flex-col gap-3"
                            aria-labelledby="deploy-settings"
                        >
                            <h3 id="deploy-settings" :class="sectionTitle">
                                {{ $t('Settings') }}
                            </h3>
                            <p v-if="filledFields.length" :class="hint">
                                {{
                                    $t('ServerOS fills in :names for :name.', {
                                        names: filledNames,
                                        name: selected.name,
                                    }) + ' '
                                }}
                                <button
                                    type="button"
                                    :class="linkButton"
                                    @click="fieldsOpen = true"
                                >
                                    {{ $t('Change them') }}
                                </button>
                            </p>
                            <template
                                v-for="entry in shownFields"
                                :key="entry.key"
                            >
                                <label
                                    v-if="entry.accept"
                                    class="flex cursor-pointer items-start gap-2.5 text-[13px]/5 text-fg-secondary select-none"
                                >
                                    <DashCheckbox
                                        v-model="form.env[entry.key] as boolean"
                                        class="mt-[3px]"
                                        :label="entry.label"
                                    />
                                    <span>{{ entry.label }}</span>
                                </label>
                                <div v-else class="flex flex-col gap-1.5">
                                    <label
                                        :for="`deploy-env-${entry.key}`"
                                        :class="label"
                                    >
                                        {{ entry.label }}
                                        <span
                                            class="ml-1 font-ui-mono text-[11px] font-normal text-fg-faint"
                                            >{{ entry.key }}</span
                                        >
                                    </label>
                                    <div :class="field">
                                        <input
                                            :id="`deploy-env-${entry.key}`"
                                            v-model="
                                                form.env[entry.key] as string
                                            "
                                            :placeholder="
                                                entry.generated
                                                    ? $t('Generated on deploy')
                                                    : undefined
                                            "
                                            autocomplete="off"
                                            spellcheck="false"
                                        />
                                    </div>
                                    <p v-if="entry.hint" :class="hint">
                                        {{ entry.hint }}
                                    </p>
                                </div>
                                <p
                                    v-if="errorFor(`env.${entry.key}`)"
                                    :class="error"
                                >
                                    {{ errorFor(`env.${entry.key}`) }}
                                </p>
                            </template>
                            <EnvRows
                                v-if="isAppLike"
                                v-model="envRows"
                                :flags="showFlags"
                                :compose="method === 'compose'"
                                :error="
                                    errorFor('extra_env') ??
                                    errorFor('env_flags')
                                "
                            />
                        </section>

                        <!-- Everything else, with defaults that work -->
                        <section
                            v-if="isAppLike"
                            id="deploy-advanced"
                            class="flex scroll-mt-4 flex-col gap-4 border-t-[0.5px] border-[#ececec] pt-4 dark:border-[#262626]"
                        >
                            <button
                                type="button"
                                class="group flex cursor-pointer items-center gap-2 text-left"
                                :aria-expanded="advancedOpen"
                                aria-controls="deploy-advanced-fields"
                                @click="advancedOpen = !advancedOpen"
                            >
                                <FilledIcon
                                    name="chevron-right"
                                    :size="14"
                                    class="shrink-0 text-fg-muted transition-transform duration-150"
                                    :class="advancedOpen && 'rotate-90'"
                                />
                                <span
                                    class="text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary"
                                    >{{ $t('Advanced') }}</span
                                >
                                <span
                                    class="min-w-0 truncate text-[12.5px]/4 text-fg-muted"
                                    >{{
                                        advancedChanged
                                            ? $t(':count changed', {
                                                  count: String(
                                                      advancedChanged,
                                                  ),
                                              })
                                            : selected.kind === 'image'
                                              ? $t(
                                                    'Port, health check, volumes, commands',
                                                )
                                              : $t(
                                                    'Build, port, health check, volumes, commands',
                                                )
                                    }}</span
                                >
                            </button>
                            <div
                                v-show="advancedOpen"
                                id="deploy-advanced-fields"
                                class="flex flex-col gap-4"
                            >
                                <BuildFields
                                    v-model:build="build"
                                    v-model:port="form.port"
                                    :setup="selected.slug"
                                    :method="method"
                                    :setup-options="
                                        selected.kind === 'app' || fromScan
                                            ? setupOptions
                                            : []
                                    "
                                    :can-deploy-build="canDeployBuild"
                                    :machine-name="machineName"
                                    :web="selected.web"
                                    :port-editable="selected.portEditable"
                                    :port-placeholder="
                                        method === 'image'
                                            ? $t('The one it exposes')
                                            : method === 'compose'
                                              ? $t('The one it publishes')
                                              : method === 'dockerfile'
                                                ? $t('Its EXPOSE, or 80')
                                                : ''
                                    "
                                    :port-note="
                                        !selected.portEditable
                                            ? $t(
                                                  'Set by ServerOS’s :name setup.',
                                                  { name: selected.name },
                                              )
                                            : method === 'railpack'
                                              ? $t(
                                                    'ServerOS sets PORT to it. Listen on it, on 0.0.0.0.',
                                                )
                                              : null
                                    "
                                    :upload="uploadSource !== null"
                                    :railpack="detection?.railpack ?? null"
                                    :errors="errors"
                                    @update:setup="changeSetup"
                                />
                                <p v-if="selected.notes" :class="hint">
                                    {{ selected.notes }}
                                </p>
                            </div>
                        </section>

                        <p
                            v-if="errorFor('form') || errorFor('template')"
                            :class="error"
                            role="alert"
                        >
                            {{ errorFor('form') ?? errorFor('template') }}
                        </p>
                    </div>
                </form>

                <!-- Follow the deploy -->
                <div
                    v-else-if="step === 'follow' && result && followJob"
                    class="min-h-0 flex-1 overflow-y-auto"
                >
                    <div
                        class="mx-auto flex w-full max-w-[640px] flex-col gap-4 px-5 py-5 text-[13px]/5"
                    >
                        <div
                            v-if="result.generated.length"
                            class="flex flex-col gap-2"
                        >
                            <p class="text-fg-secondary">
                                {{
                                    $t(
                                        'Save these now. ServerOS doesn’t keep them, so this is the only time you’ll see them.',
                                    )
                                }}
                            </p>
                            <ul class="dash-card">
                                <li
                                    v-for="secret in result.generated"
                                    :key="secret.key"
                                    class="flex items-center gap-3 border-t-[0.5px] border-edge-row px-3.5 py-2.5 first:border-t-0"
                                >
                                    <span class="flex min-w-0 flex-1 flex-col">
                                        <span
                                            class="text-[12px]/4 text-fg-muted"
                                            >{{ secret.label }}</span
                                        >
                                        <span
                                            class="truncate font-ui-mono text-[13px]/5 text-fg-primary select-all"
                                            >{{ secret.value }}</span
                                        >
                                    </span>
                                    <button
                                        type="button"
                                        :class="iconButton"
                                        :aria-label="
                                            $t('Copy :label', {
                                                label: String(secret.label),
                                            })
                                        "
                                        @click="copy(secret.key, secret.value)"
                                    >
                                        <Check
                                            v-if="copied === secret.key"
                                            class="mi-pop size-3.5 text-status-live"
                                            :stroke-width="2.25"
                                        />
                                        <Copy
                                            v-else
                                            class="mi-copy size-3.5"
                                            :stroke-width="1.75"
                                        />
                                    </button>
                                </li>
                            </ul>
                        </div>
                        <p v-if="result.kept?.length" class="text-fg-muted">
                            {{
                                $t(
                                    'Kept :keys from the last deploy, so what’s already stored and connected keeps working.',
                                    { keys: result.kept.join(', ') },
                                )
                            }}
                        </p>
                        <DeployFollow
                            :machine-id="result.machineId ?? target"
                            :machine-name="machineName"
                            :service="form.service"
                            :job-id="followJob"
                            :url="result.url"
                            @update="(job) => (followed = job)"
                            @retried="(id) => (followJob = id)"
                        />
                    </div>
                </div>

                <!-- Done: a ready-made service -->
                <div
                    v-else-if="step === 'done' && result"
                    class="min-h-0 flex-1 overflow-y-auto"
                >
                    <div
                        class="mx-auto flex w-full max-w-[640px] flex-col gap-4 px-5 py-5 text-[13px]/5"
                    >
                        <p v-if="result.kept?.length" class="text-fg-secondary">
                            {{
                                $t(
                                    'Kept :keys from the last deploy, so what’s already stored and connected keeps working.',
                                    { keys: result.kept.join(', ') },
                                )
                            }}
                        </p>
                        <div
                            v-if="result.generated.length"
                            class="flex flex-col gap-2"
                        >
                            <p class="text-fg-secondary">
                                {{
                                    $t(
                                        'Save these now. ServerOS doesn’t keep them, so this is the only time you’ll see them.',
                                    )
                                }}
                            </p>
                            <ul class="dash-card">
                                <li
                                    v-for="secret in result.generated"
                                    :key="secret.key"
                                    class="flex items-center gap-3 border-t-[0.5px] border-edge-row px-3.5 py-2.5 first:border-t-0"
                                >
                                    <span class="flex min-w-0 flex-1 flex-col">
                                        <span
                                            class="text-[12px]/4 text-fg-muted"
                                            >{{ secret.label }}</span
                                        >
                                        <span
                                            class="truncate font-ui-mono text-[13px]/5 text-fg-primary select-all"
                                            >{{ secret.value }}</span
                                        >
                                    </span>
                                    <button
                                        type="button"
                                        :class="iconButton"
                                        :aria-label="
                                            $t('Copy :label', {
                                                label: String(secret.label),
                                            })
                                        "
                                        @click="copy(secret.key, secret.value)"
                                    >
                                        <Check
                                            v-if="copied === secret.key"
                                            class="mi-pop size-3.5 text-status-live"
                                            :stroke-width="2.25"
                                        />
                                        <Copy
                                            v-else
                                            class="mi-copy size-3.5"
                                            :stroke-width="1.75"
                                        />
                                    </button>
                                </li>
                            </ul>
                        </div>

                        <p v-if="result.url" class="text-fg-muted">
                            {{ $t('It will answer at') + ' ' }}
                            <span class="font-ui-mono text-fg-primary">{{
                                result.url
                            }}</span>
                        </p>
                        <p
                            v-if="result.url?.startsWith('https://')"
                            class="flex items-start gap-1.5 text-fg-muted"
                        >
                            <LockKeyhole
                                class="mt-0.5 size-3.5 shrink-0"
                                :stroke-width="1.75"
                                aria-hidden="true"
                            />
                            {{
                                $t(
                                    'The proxy on the machine gets the certificate and redirects HTTP to HTTPS, as soon as the domain points here.',
                                )
                            }}
                        </p>
                        <div
                            v-if="!result.url && result.localUrl"
                            class="flex flex-col gap-2 text-fg-muted"
                        >
                            <p>
                                {{
                                    $t(
                                        'With no domain it only listens on the machine itself, at',
                                    ) + ' '
                                }}
                                <span class="font-ui-mono text-fg-primary">{{
                                    result.localUrl
                                }}</span
                                >{{
                                    $t(
                                        '. Add a domain to open it from anywhere, or reach it from your computer through an SSH tunnel, then open that address:',
                                    )
                                }}
                            </p>
                            <div
                                v-if="result.tunnel"
                                class="dash-field h-auto! py-1 pr-1"
                            >
                                <code
                                    class="min-w-0 flex-1 truncate font-ui-mono text-[12px]/5 text-fg-primary select-all"
                                    >{{ result.tunnel }}</code
                                >
                                <button
                                    type="button"
                                    :class="iconButton"
                                    :aria-label="
                                        $t('Copy the SSH tunnel command')
                                    "
                                    @click="copy('tunnel', result.tunnel)"
                                >
                                    <Check
                                        v-if="copied === 'tunnel'"
                                        class="mi-pop size-3.5 text-status-live"
                                        :stroke-width="2.25"
                                    />
                                    <Copy
                                        v-else
                                        class="mi-copy size-3.5"
                                        :stroke-width="1.75"
                                    />
                                </button>
                            </div>
                        </div>
                        <p v-if="result.notes" class="text-fg-muted">
                            {{ result.notes }}
                        </p>
                    </div>
                </div>

                <!-- Footer -->
                <footer
                    class="flex h-[60px] shrink-0 items-center gap-2.5 border-t-[0.5px] border-[#e8e8e8] bg-[#fafafa] pr-3.5 pl-5 dark:border-[#262626] dark:bg-[#151515]"
                >
                    <template v-if="step === 'pick' || step === 'configure'">
                        <template v-if="step === 'configure' && selected">
                            <span
                                class="hidden size-[26px] shrink-0 items-center justify-center rounded-[6px] bg-[#f0f0f0] shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] min-[560px]:flex dark:bg-[#2d2d2d] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)]"
                            >
                                <TemplateLogo
                                    :icon="selected.icon"
                                    :size="16"
                                />
                            </span>
                            <span
                                class="hidden min-w-0 truncate font-ui-mono text-[12.5px]/5 text-fg-primary min-[560px]:block"
                                >{{ form.service || selected.name }}</span
                            >
                        </template>
                        <template v-else-if="!isOwnCode && pickedTemplate">
                            <span
                                class="hidden size-[26px] shrink-0 items-center justify-center rounded-[6px] bg-[#f0f0f0] shadow-[0_1px_1.5px_rgb(0_0_0/0.08)] min-[560px]:flex dark:bg-[#2d2d2d] dark:shadow-[inset_0_1px_0_rgb(255_255_255/0.05),0_1px_1.5px_rgb(0_0_0/0.5)]"
                            >
                                <TemplateLogo
                                    :icon="pickedTemplate.icon"
                                    :size="16"
                                />
                            </span>
                            <span
                                class="hidden min-w-0 truncate text-[13px]/5 font-medium tracking-[-0.01em] text-fg-primary min-[560px]:block"
                                >{{ pickedTemplate.name }}</span
                            >
                        </template>
                        <span
                            class="hidden shrink-0 text-[13px]/5 tracking-[-0.005em] text-fg-tertiary min-[700px]:block"
                            >{{
                                step === 'pick' && isOwnCode
                                    ? $t('Deploys to')
                                    : $t('will run on')
                            }}</span
                        >
                        <DropdownMenu
                            v-if="canSwitchMachine && !lockedService && !busy"
                        >
                            <DropdownMenuTrigger as-child>
                                <PickerButton
                                    class="hidden min-[700px]:flex"
                                    :label="machineName"
                                    :logo="machine?.provider"
                                />
                            </DropdownMenuTrigger>
                            <DropdownMenuContent
                                class="ds-modal max-h-[50svh] min-w-52 overflow-y-auto font-ui"
                                align="start"
                                side="top"
                            >
                                <DropdownMenuItem
                                    v-for="entry in machines"
                                    :key="entry.id"
                                    :disabled="
                                        !entry.online && entry.id !== target
                                    "
                                    @select="target = entry.id"
                                >
                                    <BrandLogo
                                        :name="entry.provider"
                                        :size="14"
                                    />
                                    <span class="truncate">{{
                                        entry.name
                                    }}</span>
                                    <span
                                        v-if="!entry.online"
                                        class="ml-auto pl-3 text-[12px] text-fg-faint"
                                        >{{ $t('Offline') }}</span
                                    >
                                    <Check
                                        v-else-if="entry.id === target"
                                        class="ml-auto size-3.5"
                                    />
                                </DropdownMenuItem>
                            </DropdownMenuContent>
                        </DropdownMenu>
                        <PickerButton
                            v-else-if="machineName"
                            class="hidden min-[700px]:flex"
                            :label="machineName"
                            :logo="machine?.provider"
                            :menu="false"
                            disabled
                        />
                        <span class="flex-1" />
                        <DashButton
                            :label="$t('Cancel')"
                            @click="open = false"
                        />

                        <!-- Your own code -->
                        <template v-if="step === 'pick' && isOwnCode">
                            <DashButton
                                v-if="ssh && !ssh.publicKey"
                                variant="primary"
                                :loading="ssh.busy === 'key'"
                                :disabled="ssh.busy !== null || !ssh.service"
                                :label="
                                    ssh.busy === 'key'
                                        ? $t('Making the key…')
                                        : $t('Make a deploy key')
                                "
                                @click="makeKey"
                            />
                            <DashButton
                                v-else-if="ssh && canInspectSsh"
                                variant="primary"
                                :loading="ssh.busy === 'inspect'"
                                :disabled="ssh.busy !== null"
                                :label="
                                    ssh.busy === 'inspect'
                                        ? $t('Looking inside…')
                                        : $t('Key added, look inside')
                                "
                                @click="inspectSsh"
                            />
                            <template v-else-if="ssh">
                                <DashButton
                                    v-if="!ssh.commit"
                                    variant="primary"
                                    :loading="ssh.busy === 'resolve'"
                                    :disabled="ssh.busy !== null"
                                    :label="
                                        ssh.busy === 'resolve'
                                            ? $t('Checking…')
                                            : $t('Key added, check')
                                    "
                                    @click="lookUpBranch"
                                />
                                <DashButton
                                    v-else
                                    variant="primary"
                                    :disabled="!sshSetup"
                                    :label="$t('Continue')"
                                    @click="continueSsh"
                                />
                            </template>
                            <DashButton
                                v-else
                                variant="primary"
                                :loading="busy"
                                :disabled="busy || repoInput.trim() === ''"
                                :label="
                                    busy
                                        ? $t('Looking inside…')
                                        : $t('Continue')
                                "
                                @click="submitRepo"
                            />
                        </template>

                        <DashButton
                            v-else-if="step === 'pick'"
                            variant="primary"
                            :disabled="!pickedTemplate"
                            :label="
                                pickedTemplate
                                    ? $t('Set up :name', {
                                          name: pickedTemplate.name,
                                      })
                                    : $t('Set up')
                            "
                            @click="choose(pickedTemplate)"
                        />

                        <DashButton
                            v-else-if="selected"
                            type="submit"
                            form="deploy-configure"
                            variant="primary"
                            :loading="submitting"
                            :disabled="
                                missingAcceptance ||
                                busy ||
                                (selected.kind === 'image' && !build.image)
                            "
                            :label="
                                submitting
                                    ? $t('Queuing…')
                                    : $t('Deploy :name', {
                                          name:
                                              isAppLike && form.service
                                                  ? form.service
                                                  : selected.name,
                                      })
                            "
                        />
                    </template>

                    <template v-else-if="step === 'follow' && result">
                        <span class="flex-1" />
                        <DashButton
                            :label="$t('Close')"
                            @click="open = false"
                        />
                        <DashButton
                            v-if="result.appUrl"
                            :variant="
                                followState === 'running'
                                    ? 'secondary'
                                    : 'primary'
                            "
                            :href="result.appUrl"
                            :label="
                                $t('Open :service', { service: form.service })
                            "
                            @click="open = false"
                        />
                    </template>

                    <template v-else-if="step === 'done'">
                        <span
                            v-if="machineName"
                            class="min-w-0 flex-1 truncate text-[13px]/5 tracking-[-0.005em] text-fg-tertiary"
                            >{{
                                $t('On :machine', { machine: machineName })
                            }}</span
                        >
                        <span v-else class="flex-1" />
                        <DashButton
                            v-if="!backCloses"
                            :label="$t('Deploy another')"
                            @click="back"
                        />
                        <DashButton
                            variant="primary"
                            :label="$t('Done')"
                            @click="open = false"
                        />
                    </template>
                </footer>
            </DialogContent>
        </DialogPortal>
    </DialogRoot>
</template>
