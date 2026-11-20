<script setup lang="ts">
import { computed, useTemplateRef } from 'vue';

const model = defineModel<string>({ required: true });

const {
    id,
    invalid = false,
    placeholder = '',
    readonly = false,
} = defineProps<{
    id?: string;
    invalid?: boolean;
    placeholder?: string;
    readonly?: boolean;
}>();

const highlight = useTemplateRef<HTMLElement>('highlight');

const lines = computed(() => model.value.split('\n'));
const lineCount = computed(() => lines.value.length);

// The text box is as big as its text, so the one box around it does all the
// scrolling and the colours, selection and line numbers can't drift apart.
const columns = computed(() =>
    Math.max(
        0,
        ...lines.value.map(
            (line) => line.length + (line.split('\t').length - 1) * 7,
        ),
    ),
);

const PIECES =
    /(\/\*[\s\S]*?(?:\*\/|$))|("(?:\\[\s\S]|[^"\\\n])*"?|'(?:\\[\s\S]|[^'\\\n])*'?)|(@[\w-]+)|(--[\w-]+)|(#[0-9a-fA-F]{3,8}\b|-?\d*\.?\d+(?:%|[a-z]+)?\b)|([{}])|([;:])|([^\s{}:;"'/@#\d-]+|[\s\S])/gi;

function escape(text: string): string {
    return text
        .replaceAll('&', '&amp;')
        .replaceAll('<', '&lt;')
        .replaceAll('>', '&gt;');
}

// A small CSS colourer: enough to tell selectors, properties and values apart.
const html = computed(() => {
    let depth = 0;
    let inValue = false;
    let out = '';

    for (const match of model.value.matchAll(PIECES)) {
        const [text, comment, string, at, variable, number, brace, mark] =
            match;
        const safe = escape(text);
        let kind: string | null = null;

        if (comment) kind = 'comment';
        else if (string) kind = 'string';
        else if (at) kind = 'at';
        else if (brace) {
            depth += text === '{' ? 1 : -1;
            depth = Math.max(depth, 0);
            inValue = false;
            kind = 'mark';
        } else if (mark) {
            if (text === ':' && depth > 0) inValue = true;
            if (text === ';') inValue = false;
            kind = 'mark';
        } else if (variable) kind = inValue ? 'value' : 'variable';
        else if (number && (depth === 0 ? false : inValue)) kind = 'number';
        else if (/\s/.test(text)) kind = null;
        else if (depth === 0) kind = 'selector';
        else kind = inValue ? 'value' : 'property';

        out += kind ? `<span class="css-${kind}">${safe}</span>` : safe;
    }

    // A trailing line break needs something after it to take up a line.
    return `${out}\n `;
});

// The text box shouldn't scroll on its own, but if a wide character nudges
// it, the colours follow.
function sync(event: Event): void {
    const area = event.target as HTMLTextAreaElement;
    if (highlight.value) {
        highlight.value.scrollTop = area.scrollTop;
        highlight.value.scrollLeft = area.scrollLeft;
    }
}

let escaped = false;

// Tab indents, as in any code editor. Press Esc first to move on with Tab.
function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
        escaped = true;
        return;
    }
    if (
        event.key !== 'Tab' ||
        escaped ||
        event.metaKey ||
        event.ctrlKey ||
        event.altKey
    ) {
        escaped = false;
        return;
    }
    event.preventDefault();
    const area = event.target as HTMLTextAreaElement;
    const { selectionStart: start, selectionEnd: end, value } = area;

    if (event.shiftKey) {
        const lineStart = value.lastIndexOf('\n', start - 1) + 1;
        const indent = value.slice(lineStart).match(/^ {1,4}/)?.[0] ?? '';
        if (!indent) return;
        model.value =
            value.slice(0, lineStart) + value.slice(lineStart + indent.length);
        requestAnimationFrame(() =>
            area.setSelectionRange(
                Math.max(lineStart, start - indent.length),
                Math.max(lineStart, end - indent.length),
            ),
        );
        return;
    }

    model.value = value.slice(0, start) + '    ' + value.slice(end);
    requestAnimationFrame(() => area.setSelectionRange(start + 4, start + 4));
}
</script>

<template>
    <div
        class="css-editor overflow-hidden rounded-[10px] border bg-(--ds-field) font-ui-mono text-[12.5px]/5 focus-within:outline-2 focus-within:outline-(--ds-ring)"
        :class="invalid ? 'border-(--ds-red)' : 'border-(--ds-border-strong)'"
    >
        <div class="max-h-[624px] overflow-auto overscroll-contain">
            <div class="flex w-max min-w-full">
                <div
                    aria-hidden="true"
                    class="sticky left-0 z-10 w-11 shrink-0 border-r border-(--ds-divider) bg-(--ds-subtle) py-3 pr-2.5 text-right text-(--ds-text-faint) tabular-nums select-none"
                >
                    <div v-for="line in lineCount" :key="line">{{ line }}</div>
                </div>
                <div
                    class="relative grow"
                    :style="{ minWidth: `calc(${columns + 2}ch + 1.5rem)` }"
                >
                    <pre
                        ref="highlight"
                        aria-hidden="true"
                        class="pointer-events-none absolute inset-0 m-0 overflow-hidden p-3 whitespace-pre text-(--ds-text)"
                        v-html="html"
                    />
                    <textarea
                        :id="id"
                        v-model="model"
                        wrap="off"
                        spellcheck="false"
                        autocapitalize="off"
                        autocomplete="off"
                        :aria-invalid="invalid"
                        aria-describedby="css-editor-keys"
                        :placeholder="placeholder"
                        :readonly="readonly"
                        :rows="Math.max(lineCount, 10)"
                        class="relative block w-full resize-none overflow-hidden bg-transparent p-3 whitespace-pre text-transparent caret-(--ds-text) outline-none selection:bg-[color-mix(in_srgb,var(--ds-accent)_30%,transparent)] selection:text-transparent placeholder:text-(--ds-text-faint)"
                        @scroll="sync"
                        @keydown="onKeydown"
                    />
                </div>
            </div>
        </div>
        <span id="css-editor-keys" class="sr-only">{{
            $t('Tab indents. Press Escape, then Tab, to move on.')
        }}</span>
    </div>
</template>

<style scoped>
.css-editor :deep(.css-comment) {
    color: var(--ds-text-faint);
    font-style: italic;
}

.css-editor :deep(.css-selector) {
    color: var(--ds-text);
    font-weight: 500;
}

.css-editor :deep(.css-at) {
    color: var(--ds-red);
}

.css-editor :deep(.css-property) {
    color: var(--ds-accent);
}

.css-editor :deep(.css-variable) {
    color: var(--ds-accent-strong);
}

.css-editor :deep(.css-value),
.css-editor :deep(.css-mark) {
    color: var(--ds-text-secondary);
}

.css-editor :deep(.css-number) {
    color: var(--ds-amber);
}

.css-editor :deep(.css-string) {
    color: var(--ds-green);
}
</style>
