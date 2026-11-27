<?php

namespace App\Support\Deploys;

/**
 * Reads a pasted .env file the way dotenv tools do: blank lines and
 * comments skipped, an optional `export ` in front, single quotes kept as
 * they are, double quotes with escapes (\n, \t, \", \\), and quoted values
 * that run over several lines. An unquoted value ends at ` #`, which starts
 * a comment.
 */
final class EnvFile
{
    public const NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*$/';

    // Long enough for a private key or a certificate chain.
    public const MAX_VALUE_BYTES = 65536;

    public const MAX_VARIABLES = 200;

    public const MAX_BYTES = 512 * 1024;

    /**
     * @return array{variables: array<string, string>, errors: array<int, string>} errors by line number (from 1)
     */
    public static function parse(string $text): array
    {
        $variables = [];
        $errors = [];
        if (strlen($text) > self::MAX_BYTES) {
            return ['variables' => [], 'errors' => [1 => 'That’s more than 512 KB of settings. Paste fewer at a time.']];
        }

        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        $count = count($lines);
        for ($i = 0; $i < $count; $i++) {
            $number = $i + 1;
            $line = ltrim($lines[$i]);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^export\s+/', $line, $m) === 1) {
                $line = substr($line, strlen($m[0]));
            }

            $equals = strpos($line, '=');
            $name = $equals === false ? trim($line) : rtrim(substr($line, 0, $equals));
            if ($equals === false || preg_match(self::NAME_PATTERN, $name) !== 1) {
                $errors[$number] = "Line {$number} should look like NAME=value.";

                continue;
            }

            $rest = ltrim(substr($line, $equals + 1));
            $quote = $rest[0] ?? '';
            if ($quote === '"' || $quote === "'") {
                [$value, $end, $closed] = self::quoted($lines, $i, $quote);
                if (! $closed) {
                    $errors[$number] = "The value of {$name} on line {$number} opens a {$quote} quote that never closes.";

                    break;
                }
                $i = $end;
            } else {
                $value = rtrim((string) preg_replace('/\s+#.*$/', '', $rest));
            }

            if (strlen($value) > self::MAX_VALUE_BYTES) {
                $errors[$number] = "{$name} is longer than 64 KB.";

                continue;
            }
            unset($variables[$name]);
            $variables[$name] = $value;
            if (count($variables) > self::MAX_VARIABLES) {
                $errors[$number] = 'That’s more than '.self::MAX_VARIABLES.' settings. Paste fewer at a time.';

                break;
            }
        }

        return ['variables' => $variables, 'errors' => $errors];
    }

    /**
     * The text of a quoted value, which may run over the lines after it.
     *
     * @param  list<string>  $lines
     * @return array{0: string, 1: int, 2: bool} the value, the line it ends on, and whether it closed
     */
    private static function quoted(array $lines, int $start, string $quote): array
    {
        $first = ltrim($lines[$start]);
        if (preg_match('/^export\s+/', $first, $m) === 1) {
            $first = substr($first, strlen($m[0]));
        }
        $text = ltrim(substr($first, (int) strpos($first, '=') + 1));
        $text = substr($text, 1);
        $value = '';
        $line = $start;

        while (true) {
            $length = strlen($text);
            for ($at = 0; $at < $length; $at++) {
                $char = $text[$at];
                if ($quote === '"' && $char === '\\' && $at + 1 < $length) {
                    $next = $text[++$at];
                    $value .= match ($next) {
                        'n' => "\n",
                        'r' => "\r",
                        't' => "\t",
                        '"', '\\', '$', '`' => $next,
                        default => '\\'.$next,
                    };

                    continue;
                }
                if ($char === $quote) {
                    return [$value, $line, true];
                }
                $value .= $char;
            }
            if (++$line >= count($lines) || strlen($value) > self::MAX_VALUE_BYTES) {
                return [$value, $line, false];
            }
            $value .= "\n";
            $text = $lines[$line];
        }
    }

    /**
     * One value written so parse() reads it back the same: plain when it
     * can be, in double quotes with escapes otherwise.
     */
    public static function line(string $name, string $value): string
    {
        if ($value !== '' && preg_match('/^[A-Za-z0-9_.,:\/@%+=-]+$/', $value) === 1) {
            return "{$name}={$value}";
        }

        return $name.'="'.str_replace(['\\', '"', '$', '`', "\n", "\r", "\t"], ['\\\\', '\\"', '\\$', '\\`', '\\n', '\\r', '\\t'], $value).'"';
    }
}
