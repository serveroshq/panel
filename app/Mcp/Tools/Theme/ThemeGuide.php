<?php

namespace App\Mcp\Tools\Theme;

final class ThemeGuide
{
    public static function text(): string
    {
        return <<<'TXT'
How dashboard themes work:
- A theme is one CSS stylesheet added after the dashboard's own, and it can restyle anything. Change the whole thing with css, or small parts with changes (find and replace).
- For a new look, send a palette: background, text and accent (and any optional colours) for light, dark or both. ServerOS turns it into a whole theme that colours every part of the dashboard. Leave out light (or dark) for a theme that's always dark (or light). Put anything else (backgrounds, fonts, borders, special touches) in extra_css. Or start from the template closest to what they want. Then make tweaks with changes. Don't write whole stylesheets of variables by hand.
- A theme made for one mode (a terminal look, a dark brand): add --theme-appearance: dark (or light) to html:root, and the dashboard uses that mode for everyone whatever their own setting. Then style only html.dark (or html:root).
- When you change the accent, change --ds-accent, --ds-blue, --ds-ring and the --ds-primary-* buttons together, so links, badges, charts and buttons match.
- Colours come from --ds-* variables. Set them on html:root for light mode and on html.dark for dark mode, and style both unless the person asks for one. Changing variables reaches every page, so start there.
  Backgrounds: --ds-panel (behind everything), --ds-sidebar, --ds-frame (the page), --ds-card, --ds-field (inputs), --ds-subtle, --ds-hover, --ds-row-hover, --ds-active, --ds-segment-track, --ds-segment-thumb.
  Lines: --ds-divider, --ds-border, --ds-border-strong, --ds-border-stronger, --ds-border-hover.
  Text: --ds-text, --ds-text-strong, --ds-text-secondary, --ds-text-muted, --ds-text-faint.
  Accent: --ds-accent, --ds-accent-strong, --ds-accent-soft, --ds-accent-bg, --ds-accent-border, --ds-ring (focus rings).
  Status: --ds-green, --ds-amber, --ds-red, each with -bg, -border and -strong.
  Buttons: --ds-primary-bg, --ds-primary-bg-hover, --ds-primary-border, --ds-primary-text (main buttons); --ds-button-bg, --ds-button-text (raised buttons).
  Menus and dialogs: --ds-menu, --ds-menu-hover, --ds-menu-rule, --ds-menu-icon, --ds-modal-bg. Choices: --ds-choice-bg, --ds-choice-selected-bg. Sidebar: --ds-nav-active-bg.
  Charts and meters: --ds-chart, --ds-chart-warn, --ds-chart-hot, --ds-chart-empty (plain hex colours).
  Cards: html:root .ds-tile { --tile-bg; --tile-inner; --tile-edge; --tile-rule } and the same under html.dark.
  Fonts: --font-ui and --font-ui-mono. Already loaded: 'Mona Sans', 'Inter', 'Instrument Sans', 'Schibsted Grotesk', 'Cabin', 'Geist Mono'. For others, put @import url('https://fonts.googleapis.com/css2?family=…&display=swap') (or fonts.bunny.net) at the very top.
- Parts of the page have data-theme-part: sidebar, topbar, content, main, page, page-header, page-title, assistant. Target them like [data-theme-part='content'] { … }. Useful classes: .ds-tile (cards), .ds-pill (badges), .ds-segments (tabs), .ds-modal (dialogs), .ds-raised-button, .sidebar-pill--active (the current page in the sidebar).
- Images: only the ones get_dashboard_theme lists (url(/storage/…)), or data: URLs (an inline SVG pattern works for simple shapes). Never use a picture from another site or an address you made up: it's refused and nothing saves. If the person wants pictures they haven't uploaded, make the rest of the theme now, then ask them to upload the pictures under Images in Settings › Theme so you can add them. Put a background on [data-theme-part='content'] or html, and make --ds-frame or cards partly see-through (color-mix(in srgb, … 80%, transparent)) if it should show through.
- Keep text readable against what's behind it: 4.5:1 contrast for normal text, in both modes.
- Not allowed: files from other sites (only Google Fonts and Bunny Fonts), and the "<" character. Up to 40 KB.
- If a theme breaks the page, adding ?theme=off to the address turns custom CSS off for that person until ?theme=on.
TXT;
    }
}
