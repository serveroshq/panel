/** A colour a workspace theme sets, for charts drawn in script. Only plain hex values count. */
export function themeColour(variable: string, fallback: string): string {
    if (typeof document === 'undefined') return fallback;
    const value = getComputedStyle(document.documentElement)
        .getPropertyValue(variable)
        .trim();

    return /^#[0-9a-f]{3,8}$/i.test(value) ? value : fallback;
}
