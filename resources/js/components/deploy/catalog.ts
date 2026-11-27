import { trans } from 'laravel-vue-i18n';
import type { FilledIconName } from '@/components/icons/filledIcons';
import { isBrandName } from '@/lib/brandLogos';
import type { BrandName } from '@/lib/brandLogos';

export const OWN_CODE = 'Custom';

export const RAIL: { value: string; icon: FilledIconName }[] = [
    { value: 'All', icon: 'table-fill' },
    { value: 'Apps', icon: 'projects-fill' },
    { value: 'Databases', icon: 'database-fill' },
    { value: 'Infrastructure', icon: 'machines-fill' },
    { value: 'Self-hosted', icon: 'layers-fill' },
    { value: 'Game servers', icon: 'gamepad-fill' },
];

export const SECTION_ORDER = [
    'Databases',
    'Apps',
    'Self-hosted',
    'Infrastructure',
    'Game servers',
];

const FEATURED = [
    'postgres',
    'mariadb',
    'mongodb',
    'redis',
    'clickhouse',
    'mysql',
    'valkey',
    'nextjs',
    'laravel',
    'astro',
    'node',
    'django',
    'bun',
    'n8n',
    'plausible',
    'uptime-kuma',
    'grafana',
    'nextcloud',
    'ghost',
    'wordpress',
    'umami',
    'mattermost',
    'gitea',
    'minio',
    'vaultwarden',
    'ollama',
    'home-assistant',
    'meilisearch',
    'metabase',
    'keycloak',
    'nocodb',
    'appsmith',
    'directus',
    'strapi',
    'rabbitmq',
    'elasticsearch',
    'jellyfin',
    'immich',
    'listmonk',
    'pocketbase',
    'authentik',
    'portainer',
    'excalidraw',
    'ntfy',
    'matomo',
    'minecraft-java',
    'minecraft-paper',
    'valheim',
    'terraria',
];

export function featuredRank(slug: string): number {
    const at = FEATURED.indexOf(slug);

    return at === -1 ? FEATURED.length : at;
}

export function shortDescription(description: string): string {
    const sentence = (description.split(/(?<=[.!?])\s/)[0] ?? description)
        .replace(/\.$/, '')
        .trim();
    const clause = sentence.match(/^(.{12,}?)[,:;]\s/);

    return clause ? clause[1] : sentence;
}

const KIT_LOGOS: Record<string, BrandName> = {
    postgresql: 'postgres',
    nextjs: 'next',
    nodejs: 'node',
    'uptime-kuma': 'kuma',
    meilisearch: 'meili',
};

export function kitLogo(icon: string | null | undefined): BrandName | null {
    if (!icon) return null;
    if (isBrandName(icon)) return icon;

    return KIT_LOGOS[icon] ?? null;
}

const LANGUAGE_ICONS: Record<string, string> = {
    php: 'php',
    blade: 'laravel',
    javascript: 'nodejs',
    typescript: 'nodejs',
    python: 'python',
    go: 'go',
    rust: 'rust',
    ruby: 'ruby',
    elixir: 'phoenix',
    java: 'openjdk',
    kotlin: 'ktor',
    'c#': 'dotnet',
    dart: 'dart',
    crystal: 'crystal',
    gleam: 'gleam',
    html: 'html',
    astro: 'astro',
    svelte: 'svelte',
    dockerfile: 'docker',
    vue: 'vite',
};

export function languageIcon(language: string | null | undefined): string {
    return LANGUAGE_ICONS[(language ?? '').toLowerCase()] ?? 'code';
}

const SSH_REPO = /^(?:[A-Za-z0-9._-]+@[A-Za-z0-9.-]+:|ssh:\/\/)/;

export function isSshRepository(text: string): boolean {
    return SSH_REPO.test(text.trim());
}

export function looksLikeRepository(text: string): boolean {
    const value = text.trim();

    return (
        isSshRepository(value) ||
        /^https?:\/\//i.test(value) ||
        /^(?:www\.)?github\.com\//i.test(value)
    );
}

export function repositoryLabel(repo: string): string {
    return repo
        .replace(/^https:\/\/github\.com\//, '')
        .replace(/^https:\/\/gitlab\.com\//, '')
        .replace(/\/-\/tree\//, '/tree/')
        .replace(/\.git$/, '')
        .replace(/^[^@]+@[^:]+:/, '');
}

export function shortAgo(iso: string | null | undefined): string | null {
    const then = iso ? Date.parse(iso) : NaN;
    if (Number.isNaN(then)) return null;
    const minutes = Math.max(0, Math.floor((Date.now() - then) / 60_000));
    const hours = Math.floor(minutes / 60);
    const days = Math.floor(hours / 24);

    if (minutes < 1) return trans('just now');
    if (hours < 1) return trans(':countm ago', { count: String(minutes) });
    if (days < 1) return trans(':counth ago', { count: String(hours) });
    if (days === 1) return trans('yesterday');
    if (days < 30) return trans(':countd ago', { count: String(days) });
    if (days < 365) {
        return trans(':countmo ago', { count: String(Math.floor(days / 30)) });
    }

    return trans(':county ago', { count: String(Math.floor(days / 365)) });
}
