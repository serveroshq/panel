<?php

namespace App\Support\Databases;

use App\Models\DaemonJob;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Looks inside a database container from the panel: its databases, its
 * tables, and the result of a query. Each runs as a `service_exec` job with
 * the database's own command-line client, logged in the same way the
 * daemon's backups log in (the container's own POSTGRES_USER or root
 * password), so nothing new is stored or asked for.
 *
 * The result comes back as the job's log, so it's capped well under what
 * the daemon keeps of a log (256 KB) and marked with a per-job nonce so
 * nothing the query prints can pass for the markers. What the client prints
 * goes through base64 first: the daemon reads output as UTF-8 lines (binary
 * columns and a cut-off character would stop it) and scrubs anything that
 * looks like `token=…`, and unpadded base64 has neither.
 */
final class DatabaseConsole
{
    public const ACTIONS = ['databases', 'tables', 'query'];

    public const MAX_ROWS = 200;

    public const MAX_QUERY = 20_000;

    private const MAX_LINES = 400;

    private const MAX_BYTES = 160_000;

    /** The daemon stops the job after this long; the client and the query stop first. */
    public const TIMEOUT = 60;

    private const CLIENT_TIMEOUT = 55;

    private const QUERY_TIMEOUT = 50;

    /** Marks a log entry the panel encrypted as it arrived. */
    private const SEALED = 'sealed:';

    /** Base64 without its `=` padding or line breaks, in lines short enough to send. */
    private const ENCODE = "base64 | tr -d '=\\n' | fold -w 3000";

    /** @param  array<string, mixed>  $service */
    public static function engine(array $service): ?string
    {
        // A database the machine runs itself: the daemon backs it up with the
        // same client the Database tab runs, and runs that client itself.
        if (self::onHost($service)) {
            return match ($service['details']['backup'] ?? null) {
                'pg_dumpall' => 'postgres',
                'mysqldump' => 'mysql',
                default => null,
            };
        }

        if (! str_starts_with((string) ($service['key'] ?? ''), 'docker:')) {
            return null;
        }

        // The image's own name too, as the daemon checks it: older daemons
        // also marked tools named after a database (postgrest) as one.
        $image = (string) ($service['details']['image'] ?? '');
        $name = strtolower(preg_split('/[:@]/', basename($image))[0] ?? '');
        $named = match (true) {
            in_array($name, ['postgres', 'postgresql', 'postgis', 'pgvector', 'timescaledb', 'timescaledb-ha'], true) => 'postgres',
            in_array($name, ['mysql', 'mysql-server', 'mariadb', 'percona', 'percona-server'], true) => 'mysql',
            default => null,
        };

        return match ($service['details']['backup'] ?? null) {
            'pg_dumpall' => $named === 'postgres' || $image === '' ? 'postgres' : null,
            'mysqldump' => $named === 'mysql' || $image === '' ? 'mysql' : null,
            default => null,
        };
    }

    /**
     * The shell command for an action. The database name and query go in as
     * single-quoted words: never as settings, whose values the daemon scrubs
     * from everything the command prints.
     */
    /** @param  array<string, mixed>  $service */
    public static function onHost(array $service): bool
    {
        return str_starts_with((string) ($service['key'] ?? ''), 'systemd:');
    }

    public static function sql(string $engine, string $action, ?string $query): string
    {
        return match ($action) {
            'databases' => self::DATABASES[$engine],
            'tables' => self::TABLES[$engine],
            default => (string) $query,
        };
    }

    /**
     * The daemon's own job for a database the machine runs itself. Only the
     * query goes over: the daemon runs the client with fixed arguments, as
     * the service's user, and prints what it finds the way command() does.
     *
     * @return array<string, mixed>
     */
    public static function hostJob(string $engine, string $service, string $action, ?string $database, ?string $query, bool $write, string $nonce): array
    {
        return [
            'type' => 'database_query',
            'service' => $service,
            'engine' => $engine,
            'database' => $database === null || $database === '' ? null : $database,
            'sql' => self::sql($engine, $action, $query),
            'read_only' => ! $write,
            'count' => $action === 'query',
            'nonce' => $nonce,
        ];
    }

    public static function command(string $engine, string $action, ?string $database, ?string $query, bool $write, string $nonce): string
    {
        $sql = self::sql($engine, $action, $query);
        $db = $database === null || $database === '' ? "''" : escapeshellarg($database);
        // After a query, a line of its own says how many rows it changed.
        $count = $action === 'query';
        $out = '"$O"';

        $run = $engine === 'postgres'
            // Read-only mode is a guard against mistakes, not a wall: whoever
            // may run queries here may also open the console.
            ? implode("\n", [
                'U="${POSTGRES_USER:-postgres}"',
                "D={$db}; [ -n \"\$D\" ] || D=\"\${POSTGRES_DB:-\$U}\"",
                'export PGPASSWORD="${POSTGRES_PASSWORD:-}" PGCLIENTENCODING=UTF8 PGOPTIONS="-c statement_timeout='.self::QUERY_TIMEOUT.'s'.($write ? '' : ' -c default_transaction_read_only=on').'"',
                '$T psql -X -q -v ON_ERROR_STOP=1 --csv -P null=NULL -U "$U" -d "$D" -c '.escapeshellarg($sql)
                    .($count ? ' -c '.escapeshellarg("\\echo {$nonce} :ROW_COUNT") : '')." > {$out} 2> \"\$E\"",
                'code=$?',
            ])
            // --binary-mode turns off the client's own commands (system, \!),
            // and the server stops a statement that runs too long: MySQL only
            // stops a SELECT, MariaDB any statement.
            : implode("\n", [
                'C=mysql; command -v mariadb >/dev/null 2>&1 && C=mariadb',
                // Binary columns come as raw bytes and the panel shows them as hex:
                // the clients' own --binary-as-hex prints NULL as 0x in MariaDB and MySQL 5.7.
                'L="SET SESSION max_execution_time='.(self::QUERY_TIMEOUT * 1000).';"',
                '$C --version 2>/dev/null | grep -qi mariadb && L="SET SESSION max_statement_time='.self::QUERY_TIMEOUT.';"',
                'export MYSQL_PWD="${MARIADB_ROOT_PASSWORD:-$MYSQL_ROOT_PASSWORD}"',
                "D={$db}; [ -n \"\$D\" ] || D=\"\${MARIADB_DATABASE:-\$MYSQL_DATABASE}\"",
                // Its own connection comes first (unbuffered, so it is written even if the
                // client is stopped), to stop the statement too.
                '$T $C -uroot --batch --unbuffered --binary-mode --default-character-set=utf8mb4 ${D:+-D "$D"} -e "SELECT CONNECTION_ID() AS id;$L"'.escapeshellarg(($write ? '' : 'SET SESSION TRANSACTION READ ONLY; ').$sql
                    .($count ? "\n;SELECT CONCAT('{$nonce} ', ROW_COUNT()) AS `{$nonce}`" : ''))." > {$out} 2> \"\$E\"",
                'code=$?',
                'I=$(sed -n 2p "$O"); sed -i 1,2d "$O"',
                // MySQL keeps running a write whose client is gone: stop it, and roll it back.
                'case "$I" in *[!0-9]*|\'\') ;; *) [ "$code" = 124 ] && $C -uroot -e "KILL $I" >/dev/null 2>&1 ;; esac',
            ]);

        return implode("\n", [
            'O=$(mktemp) E=$(mktemp)',
            'trap \'rm -f "$O" "$E"\' EXIT',
            // Stops the client, and with it the query, before the daemon gives up on the job.
            'T=; command -v timeout >/dev/null 2>&1 && T="timeout '.self::CLIENT_TIMEOUT.'"',
            $run,
            "echo '{$nonce} database' \"\$D\"",
            "echo '{$nonce} result'",
            'head -c '.self::MAX_BYTES.' "$O" | head -n '.self::MAX_LINES.' | '.self::ENCODE,
            'echo',
            "echo '{$nonce} error'",
            'head -c 4000 "$E" | '.self::ENCODE,
            'echo',
            "echo '{$nonce} end' \"\$code\" \$(wc -c < \"\$O\") \$(wc -l < \"\$O\")",
        ]);
    }

    /**
     * What a finished job found: a table of rows, or the database's error.
     *
     * @return array{database: string|null, columns: list<string>, rows: list<list<string|null>>, truncated: bool, changed: int|null, error: string|null}|null
     */
    public static function result(DaemonJob $job): ?array
    {
        $sent = (array) ($job->command['job'] ?? []);
        if (($sent['type'] ?? null) === 'database_query') {
            $nonce = (string) ($sent['nonce'] ?? '');
            $engine = ($sent['engine'] ?? null) === 'postgres' ? 'postgres' : 'mysql';
            if (preg_match('/^[0-9a-f]{32}$/', $nonce) !== 1) {
                return null;
            }
        } else {
            // The command itself says which client ran and which nonce marks its output.
            $command = (string) ($sent['command'] ?? '');
            if (preg_match("/^echo '([0-9a-f]{32}) result'$/m", $command, $match) !== 1) {
                return null;
            }
            $nonce = $match[1];
            $engine = str_contains($command, ' psql -X ') ? 'postgres' : 'mysql';
        }

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
        $text = self::decode($parts['result']);
        $error = self::decode($parts['error']);
        if ($text === null || $error === null) {
            return null;
        }
        $error = trim(mb_scrub($error, 'UTF-8'));
        $truncated = $bytes > self::MAX_BYTES || $lines > self::MAX_LINES;
        if ($truncated && ($end = strrpos($text, "\n")) !== false) {
            // Cut at the last whole line, not inside a row or a character.
            $text = substr($text, 0, $end + 1);
        }

        // The changed-rows line comes after the query's own output.
        $changed = null;
        $result = explode("\n", $text);
        foreach ($result as $i => $line) {
            if (str_starts_with($line, $nonce)) {
                foreach (array_slice($result, $i) as $after) {
                    if (preg_match('/^'.$nonce.' (-?\d+)$/', $after, $m) === 1) {
                        $changed = (int) $m[1];
                    }
                }
                $text = implode("\n", array_slice($result, 0, $i));
                break;
            }
        }

        $table = $engine === 'postgres' ? self::csv($text, $truncated) : self::tsv($text);
        $truncated = $truncated || count($table['rows']) > self::MAX_ROWS;

        return [
            'database' => ($parts['database'][0] ?? '') !== '' ? $parts['database'][0] : null,
            'columns' => $table['columns'],
            'rows' => array_slice($table['rows'], 0, self::MAX_ROWS),
            'truncated' => $truncated,
            'changed' => $table['columns'] === [] && $changed !== null && $changed >= 0 ? $changed : null,
            'error' => match (true) {
                $code === 0 => null,
                $code === 124 => 'The query ran for over '.self::QUERY_TIMEOUT.' seconds, so it was stopped.',
                $error !== '' => mb_substr($error, 0, 2000),
                default => "The database client stopped (exit code {$code}).",
            },
        ];
    }

    /**
     * Lines as they arrive from the machine, encrypted: they can hold rows
     * of the customer's data, and stay in the job until the result is read.
     *
     * @param  list<string>  $lines
     */
    public static function seal(array $lines): string
    {
        return self::SEALED.Crypt::encryptString(implode("\n", $lines));
    }

    /** @param  list<string>  $lines  base64 split over lines */
    private static function decode(array $lines): ?string
    {
        $text = base64_decode(preg_replace('/\s+/', '', implode('', $lines)) ?? '', true);

        return $text === false ? null : $text;
    }

    /**
     * A value that isn't text (a BINARY id, a blob) shows as hex: bytes that
     * aren't UTF-8, or control characters text doesn't hold (a BINARY(16)
     * padded with zero bytes is still valid UTF-8).
     */
    private static function cell(string $value): string
    {
        return mb_check_encoding($value, 'UTF-8') && preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $value) !== 1
            ? $value
            : '0x'.strtoupper(bin2hex($value));
    }

    /** @return list<string> */
    private static function lines(DaemonJob $job): array
    {
        $lines = [];
        foreach ((array) ($job->log ?? []) as $entry) {
            $entry = (string) $entry;
            if (str_starts_with($entry, self::SEALED)) {
                try {
                    $entry = Crypt::decryptString(substr($entry, strlen(self::SEALED)));
                } catch (DecryptException) {
                    continue;
                }
            }
            foreach (explode("\n", $entry) as $line) {
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
    private static function tsv(string $text): array
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

        // result() already cut at a whole line, which is a whole row here.
        return self::table($records, false);
    }

    /**
     * Both clients print NULL as the word NULL, so a text value that is
     * literally "NULL" shows as a null too. A partial result drops its last
     * record, which in psql's CSV can be a quoted value cut mid-line.
     *
     * @param  list<list<string>>  $records
     * @return array{columns: list<string>, rows: list<list<string|null>>}
     */
    private static function table(array $records, bool $partial): array
    {
        $columns = array_shift($records) ?? [];
        if ($partial && $records !== []) {
            array_pop($records);
        }
        $width = count($columns);

        return [
            'columns' => array_map(self::cell(...), $columns),
            'rows' => array_map(
                fn (array $row) => array_map(fn (string $v) => $v === 'NULL' ? null : self::cell($v), array_pad(array_slice($row, 0, $width), $width, '')),
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
