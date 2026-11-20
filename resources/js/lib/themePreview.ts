// The standard look, for previews of templates that leave a colour alone.
export const STANDARD_PREVIEW: Record<
    'light' | 'dark',
    Record<string, string>
> = {
    light: {
        panel: '#f3f3f1',
        frame: '#ffffff',
        card: '#ffffff',
        border: '#e7e7e4',
        text: '#151515',
        'text-muted': '#6b6b66',
        'text-faint': '#8e8e88',
        'nav-active-bg': '#ffffff',
        'primary-bg': '#1c4bb0',
        green: '#15803d',
        amber: '#b45309',
        red: '#b42318',
        chart: '#3b7bff',
        'chart-empty': '#e7e7e4',
    },
    dark: {
        panel: '#0f0f0f',
        frame: '#181818',
        card: '#1c1c1c',
        border: '#262626',
        text: '#eeeeee',
        'text-muted': '#a4a4a4',
        'text-faint': '#8a8a8a',
        'nav-active-bg': '#242424',
        'primary-bg': '#1c4bb0',
        green: '#4ade80',
        amber: '#fbbf24',
        red: '#f87171',
        chart: '#60a5fa',
        'chart-empty': '#222222',
    },
};
