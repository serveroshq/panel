<?php

namespace App\Mcp;

/**
 * Cursor paging for list tools: pass `cursor` (the `next_cursor` the last page
 * gave) to get the next page. `offset` still works but is deprecated.
 */
final class Paging
{
    /** @return array<string, array<string, mixed>> */
    public static function schema(): array
    {
        return [
            'cursor' => Schema::string('Where the last page ended: its next_cursor. Leave out for the first page.', maxLength: 200),
            'offset' => Schema::integer('Deprecated: use cursor. How many to skip.', 0) + ['deprecated' => true],
        ];
    }

    /** @return array<string, list<string>> */
    public static function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'nullable', 'string', 'max:200'],
            'offset' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /** @param  array<string, mixed>  $data */
    public static function offset(array $data): int
    {
        $cursor = $data['cursor'] ?? null;
        if (! is_string($cursor) || $cursor === '') {
            return (int) ($data['offset'] ?? 0);
        }
        $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
        if (! is_string($decoded) || preg_match('/^o(\d{1,9})$/', $decoded, $m) !== 1) {
            throw new ToolError('Invalid arguments: that cursor isn’t one a list gave. Start again without it.');
        }

        return (int) $m[1];
    }

    /** The cursor for the page after one that ended at $end, or null at the end. */
    public static function next(int $end, int $total): ?string
    {
        return $end < $total ? rtrim(strtr(base64_encode("o{$end}"), '+/', '-_'), '=') : null;
    }
}
