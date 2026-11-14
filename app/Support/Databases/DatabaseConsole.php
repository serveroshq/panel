<?php

namespace App\Support\Databases;

use App\Models\DaemonJob;

/**
 * Looks inside a database container from the panel: its databases, its
 * tables, and the result of a query. Each runs as a `service_exec` job with
 * the database's own command-line client, logged in the same way the
 * daemon's backups log in (the container's own POSTGRES_USER or root
 * password), so nothing new is stored or asked for.
 *
 * The result comes back as the job's log, so it's capped well under what
 * the panel keeps of a log (500 lines) and marked with a per-job nonce so
 * nothing the query prints can pass for the markers.
 */
final class DatabaseConsole
{
    public const ACTIONS = ['databases', 'tables', 'query'];

    public const MAX_ROWS = 200;

    public const MAX_QUERY = 20_000;

    /** Kept under the panel's 500 log lines and well under the daemon's 256 KB log. */
    private const MAX_LINES = 400;

    private const MAX_BYTES = 160_000;

    public const TIMEOUT = 60;

    /** @param  array<string, mixed>  $service */
    public static function engine(array $service): ?string
    {
        if (! str_starts_with((string) ($service['key'] ?? ''), 'docker:')) {
            return null;
        }

        return match ($service['details']['backup'] ?? null) {
            'pg_dumpall' => 'postgres',
            'mysqldump' => 'mysql',
            default => null,
        };
    }

    /**
     * The shell command for an action. The database name and query go in as
     * single-quoted words: never as settings, whose values the daemon scrubs
     * from everything the command prints.
     */
    public static function command(string $engine, string $action, ?string $database, ?string $query, bool $write, string $nonce): string
    {
        $sql = match ($action) {
            'databases' => self::DATABASES[$engine],
            'tables' => self::TABLES[$engine],
            default => (string) $query,
        };
        $db = $database === null || $database === '' ? "''" : escapeshellarg($database);
        $out = '"$O"';

        $run = $engine === 'postgres'
            // Read-only is a guard against mistakes, not a wall: whoever may
            // run queries here may also open the console.
            ? implode("\n", [
                'U="${POSTGRES_USER:-postgres}"',
                "D={$db}; [ -n \"\$D\" ] || D=\"\${POSTGRES_DB:-\$U}\"",
                'export PGPASSWORD="${POSTGRES_PASSWORD:-}" PGOPTIONS="-c statement_timeout=50s'.($write ? '' : ' -c default_transaction_read_only=on').'"',
                'psql -X -q -v ON_ERROR_STOP=1 --csv -P null=NULL -U "$U" -d "$D" -c '.escapeshellarg($sql)." > {$out} 2> \"\$E\"",
            ])
            : implode("\n", [
                'C=mysql; command -v mariadb >/dev/null 2>&1 && C=mariadb',
                'export MYSQL_PWD="${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}"',
                "D={$db}; [ -n \"\$D\" ] || D=\"\${MARIADB_DATABASE:-\$MYSQL_DATABASE}\"",
                '$C -uroot --batch ${D:+-D "$D"} -e '.escapeshellarg(($write ? '' : 'SET SESSION TRANSACTION READ ONLY; ').$sql)." > {$out} 2> \"\$E\"",
            ]);

        return implode("\n", [
            'O=$(mktemp) E=$(mktemp)',
            'trap \'rm -f "$O" "$E"\' EXIT',
            $run,
            'code=$?',
            "echo '{$nonce} database' \"\$D\"",
            "echo '{$nonce} result'",
            'head -c '.self::MAX_BYTES.' "$O" | head -n '.self::MAX_LINES,
            // A result cut short ends on a line of its own.
            'echo',
            "echo '{$nonce} error'",
            'head -c 4000 "$E"',
            'echo',
            "echo '{$nonce} end' \"\$code\" \$(wc -c < \"\$O\") \$(wc -l < \"\$O\")",
        ]);
    }

    /**
     * What a finished job found: a table of rows, or the database's error.
     *
     * @return array{database: string|null, columns: list<string>, rows: list<list<string|null>>, truncated: bool, error: string|null}|null
     */
    public static function result(DaemonJob $job): ?array
    {
        // The command itself says which client ran and which nonce marks its output.
        $command = (string) ($job->command['job']['command'] ?? '');
        if (preg_match("/^echo '([0-9a-f]{32}) result'$/m", $command, $match) !== 1) {
            return null;
        }
        $nonce = $match[1];
        $engine = str_contains($command, "\npsql ") ? 'postgres' : 'mysql';

        $section = null;
        $parts = ['database' => [], 'result' => [], 'error' => [], 'end' => []];
        foreach (self::lines($job) as $line) {
            if (preg_match('/^'.preg_quote($nonce, '/').' (database|result|error|end)(?: (.*))?$/', $line, $m) === 1) {
                $section = $m[1];
                if (isset($m[2])) {
                    $parts[$section][] = $m[2];
                }

                continue;
            }
            if ($section !== null) {
                $parts[$section][] = $line;
            }
        }

        if ($parts['end'] === []) {
            return null;
        }
        [$code, $bytes, $lines] = array_map('intval', array_pad(preg_split('/\s+/', trim($parts['end'][0])) ?: [], 3, '0'));
        $error = trim(implode("\n", $parts['error']));
        $text = implode("\n", $parts['result']);
        $truncated = $bytes > self::MAX_BYTES || $lines > self::MAX_LINES;

        $table = $engine === 'postgres' ? self::csv($text, $truncated) : self::tsv($text, $truncated);
        $truncated = $truncated || count($table['rows']) > self::MAX_ROWS;

        return [
            'database' => ($parts['database'][0] ?? '') !== '' ? $parts['database'][0] : null,
            'columns' => $table['columns'],
            'rows' => array_slice($table['rows'], 0, self::MAX_ROWS),
            'truncated' => $truncated,
            'error' => $code === 0 ? null : ($error !== '' ? mb_substr($error, 0, 2000) : "The database client stopped (exit code {$code})."),
        ];
    }

    /** @return list<string> */
    private static function lines(DaemonJob $job): array
    {
        $lines = [];
        foreach ((array) ($job->log ?? []) as $entry) {
            foreach (explode("\n", (string) $entry) as $line) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * psql's CSV: quoted fields may hold commas, quotes and line breaks.
     *
     * @return array{columns: list<string>, rows: list<list<string|null>>}
     */
    private static function csv(string $text, bool $truncated): array
    {
        $stream = fopen('php://memory', 'r+');
        if ($stream === false) {
            return self::table([], $truncated);
        }
        fwrite($stream, rtrim($text, "\n"));
        rewind($stream);
        $records = [];
        while (($record = fgetcsv($stream, escape: '')) !== false) {
            if ($record !== [null]) {
                $records[] = array_map(fn ($v) => $v === null ? '' : (string) $v, $record);
            }
        }
        fclose($stream);

        return self::table($records, $truncated);
    }

    /**
     * mysql --batch: tab-separated, with tabs, line breaks and backslashes
     * escaped, so one row is one line.
     *
     * @return array{columns: list<string>, rows: list<list<string|null>>}
     */
    private static function tsv(string $text, bool $truncated): array
    {
        $records = [];
        foreach (explode("\n", rtrim($text, "\n")) as $line) {
            if ($line !== '') {
                $records[] = array_map(
                    fn (string $v) => strtr($v, ['\\\\' => '\\', '\\t' => "\t", '\\n' => "\n", '\\0' => "\0"]),
                    explode("\t", $line),
                );
            }
        }

        return self::table($records, $truncated);
    }

    /**
     * ponytail: both clients print NULL as the word NULL, so a text value
     * that is literally "NULL" shows as a null too.
     *
     * @param  list<list<string>>  $records
     * @return array{columns: list<string>, rows: list<list<string|null>>}
     */
    private static function table(array $records, bool $truncated): array
    {
        $columns = array_shift($records) ?? [];
        if ($truncated && $records !== []) {
            array_pop($records);
        }
        $width = count($columns);

        return [
            'columns' => $columns,
            'rows' => array_map(
                fn (array $row) => array_map(fn (string $v) => $v === 'NULL' ? null : $v, array_pad(array_slice($row, 0, $width), $width, '')),
                $records,
            ),
        ];
    }

    private const DATABASES = [
        'postgres' => 'SELECT datname AS name FROM pg_database WHERE NOT datistemplate AND datallowconn ORDER BY datname',
        'mysql' => "SELECT schema_name AS name FROM information_schema.schemata WHERE schema_name NOT IN ('mysql', 'information_schema', 'performance_schema', 'sys') ORDER BY schema_name",
    ];

    private const TABLES = [
        'postgres' => <<<'SQL'
            SELECT n.nspname AS schema, c.relname AS name,
                CASE c.relkind WHEN 'v' THEN 'view' WHEN 'm' THEN 'view' ELSE 'table' END AS kind,
                CASE WHEN c.reltuples < 0 THEN NULL ELSE c.reltuples::bigint END AS rows,
                pg_total_relation_size(c.oid) AS bytes
            FROM pg_class c JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE c.relkind IN ('r', 'p', 'v', 'm')
                AND n.nspname NOT IN ('pg_catalog', 'information_schema') AND n.nspname NOT LIKE 'pg\_toast%'
            ORDER BY n.nspname = 'public' DESC, n.nspname, c.relname
            SQL,
        'mysql' => <<<'SQL'
            SELECT table_schema AS `schema`, table_name AS name,
                IF(table_type = 'VIEW', 'view', 'table') AS kind,
                table_rows AS `rows`, data_length + index_length AS bytes
            FROM information_schema.tables WHERE table_schema = DATABASE() ORDER BY table_name
            SQL,
    ];
}
