import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { getStoredAppearance, updateTheme } from '@/composables/useAppearance';

export type WorkspaceTheme = {
    css: string | null;
    draft: boolean;
    published: boolean;
    off: boolean;
    settingsUrl: string;
};

const STYLE_ID = 'workspace-theme';

let pageCss: string | null = null;

/** CSS being typed in Settings › Theme, shown before it's saved. */
export const themePreview = ref<string | null>(null);

/** The mode a theme is made for, when it sets --theme-appearance. */
export function forcedAppearance(css: string | null): 'dark' | 'light' | null {
    const match = css?.match(/--theme-appearance\s*:\s*(dark|light)\b/i);
    return match ? (match[1].toLowerCase() as 'dark' | 'light') : null;
}

function applyAppearance(css: string | null): void {
    const forced = forcedAppearance(css);
    const root = document.documentElement;
    if ((root.dataset.themeAppearance ?? null) === forced) return;
    if (forced) root.dataset.themeAppearance = forced;
    else delete root.dataset.themeAppearance;
    updateTheme(getStoredAppearance() || 'system');
}

function apply(): void {
    if (typeof document === 'undefined') return;
    const css = themePreview.value ?? pageCss;
    applyAppearance(css);
    let style = document.getElementById(STYLE_ID);
    if (!css) {
        style?.remove();
        return;
    }
    if (!style) {
        style = document.createElement('style');
        style.id = STYLE_ID;
    }
    if (style.textContent !== css) style.textContent = css;
    // Last in <head>, so it wins over stylesheets Vite adds later.
    if (style !== document.head.lastElementChild) document.head.append(style);
}

function fromPage(props: Record<string, unknown>): void {
    const theme = props.workspaceTheme as WorkspaceTheme | null | undefined;
    pageCss = theme && !theme.off ? theme.css : null;
    apply();
}

export function setThemePreview(css: string | null): void {
    themePreview.value = css;
    apply();
}

/** Keeps the workspace's custom CSS in step with the page, so it applies on dashboard pages and nowhere else. */
export function initializeWorkspaceTheme(
    initial: Record<string, unknown>,
): void {
    fromPage(initial);
    router.on('navigate', (event) => fromPage(event.detail.page.props));
    router.on('success', (event) => fromPage(event.detail.page.props));
}
