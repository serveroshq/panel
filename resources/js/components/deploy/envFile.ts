/**
 * Reads a pasted .env the way the panel's EnvFile does (and dotenv tools):
 * blank lines and comments skipped, an optional `export ` in front, single
 * quotes kept as they are, double quotes with escapes, quoted values over
 * several lines, and ` # comment` after an unquoted value. The panel reads
 * what the dialog sends again, so this only has to agree with it.
 */
export const NAME_PATTERN = /^[A-Za-z_][A-Za-z0-9_]*$/;

export type EnvParse = {
    variables: { name: string; value: string }[];
    /** The first line that couldn't be read, from 1. */
    error: { line: number; name: string | null } | null;
};

function withoutExport(line: string): string {
    return line.replace(/^export\s+/, '');
}

export function parseEnv(text: string): EnvParse {
    const found = new Map<string, string>();
    const lines = text.split(/\r\n|\r|\n/);
    let error: EnvParse['error'] = null;

    for (let i = 0; i < lines.length; i++) {
        const number = i + 1;
        const raw = lines[i].trimStart();
        if (raw === '' || raw.startsWith('#')) continue;
        const line = withoutExport(raw);

        const equals = line.indexOf('=');
        const name =
            equals === -1 ? line.trim() : line.slice(0, equals).trimEnd();
        if (equals === -1 || !NAME_PATTERN.test(name)) {
            error ??= { line: number, name: null };
            continue;
        }

        const rest = line.slice(equals + 1).trimStart();
        const quote = rest[0] ?? '';
        let value: string;
        if (quote === '"' || quote === "'") {
            const read = quoted(lines, i, rest.slice(1), quote);
            if (!read.closed) {
                error ??= { line: number, name };
                break;
            }
            value = read.value;
            i = read.end;
        } else {
            value = rest.replace(/\s+#.*$/, '').trimEnd();
        }

        found.delete(name);
        found.set(name, value);
    }

    return {
        variables: [...found].map(([name, value]) => ({ name, value })),
        error,
    };
}

function quoted(
    lines: string[],
    start: number,
    first: string,
    quote: string,
): { value: string; end: number; closed: boolean } {
    let text = first;
    let value = '';
    let line = start;
    const escapes: Record<string, string> = {
        n: '\n',
        r: '\r',
        t: '\t',
        '"': '"',
        '\\': '\\',
        $: '$',
        '`': '`',
    };

    for (;;) {
        for (let at = 0; at < text.length; at++) {
            const char = text[at];
            if (quote === '"' && char === '\\' && at + 1 < text.length) {
                const next = text[++at];
                value += escapes[next] ?? `\\${next}`;
                continue;
            }
            if (char === quote) return { value, end: line, closed: true };
            value += char;
        }
        if (++line >= lines.length) return { value, end: line, closed: false };
        value += '\n';
        text = lines[line];
    }
}

/** One setting written so the panel reads it back the same. */
export function envLine(name: string, value: string): string {
    if (value !== '' && /^[A-Za-z0-9_.,:/@%+=-]+$/.test(value)) {
        return `${name}=${value}`;
    }

    const escaped = value
        .replaceAll('\\', '\\\\')
        .replaceAll('"', '\\"')
        .replaceAll('$', '\\$')
        .replaceAll('`', '\\`')
        .replaceAll('\n', '\\n')
        .replaceAll('\r', '\\r')
        .replaceAll('\t', '\\t');

    return `${name}="${escaped}"`;
}

export type EnvRow = {
    id: number;
    name: string;
    value: string;
    build: boolean;
    runtime: boolean;
};

let nextRow = 1;

export function envRow(name = '', value = ''): EnvRow {
    return { id: nextRow++, name, value, build: true, runtime: true };
}

/**
 * The settings as the deploy takes them: a .env for their values (empty
 * ones left out) and each one's switches, sent only when not both.
 */
export function envPayload(rows: EnvRow[]): {
    text: string;
    flags: Record<string, 'build' | 'runtime'>;
} {
    const kept = rows.filter(
        (row) => row.name.trim() !== '' && row.value !== '',
    );
    const flags: Record<string, 'build' | 'runtime'> = {};
    for (const row of kept) {
        if (row.build !== row.runtime) {
            flags[row.name.trim()] = row.build ? 'build' : 'runtime';
        }
    }

    return {
        text: kept.map((row) => envLine(row.name.trim(), row.value)).join('\n'),
        flags,
    };
}
