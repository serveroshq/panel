<?php

namespace App\Support\Security;

/**
 * The CVSS 3.x base score from its vector, as FIRST's specification
 * works it out. OSV gives vectors, not scores.
 */
final class Cvss
{
    private const array WEIGHTS = [
        'AV' => ['N' => 0.85, 'A' => 0.62, 'L' => 0.55, 'P' => 0.2],
        'AC' => ['L' => 0.77, 'H' => 0.44],
        'UI' => ['N' => 0.85, 'R' => 0.62],
        'CIA' => ['H' => 0.56, 'L' => 0.22, 'N' => 0.0],
    ];

    public static function score(?string $vector): ?float
    {
        if (! is_string($vector) || ! preg_match('#^CVSS:3\.[01]/#', $vector)) {
            return null;
        }

        $m = [];
        foreach (explode('/', substr($vector, 9)) as $part) {
            [$key, $value] = array_pad(explode(':', $part, 2), 2, null);
            $m[$key] = $value;
        }
        foreach (['AV', 'AC', 'PR', 'UI', 'S', 'C', 'I', 'A'] as $key) {
            if (! isset($m[$key])) {
                return null;
            }
        }

        $changed = $m['S'] === 'C';
        $pr = match ($m['PR']) {
            'N' => 0.85,
            'L' => $changed ? 0.68 : 0.62,
            'H' => $changed ? 0.5 : 0.27,
            default => null,
        };
        $av = self::WEIGHTS['AV'][$m['AV']] ?? null;
        $ac = self::WEIGHTS['AC'][$m['AC']] ?? null;
        $ui = self::WEIGHTS['UI'][$m['UI']] ?? null;
        $c = self::WEIGHTS['CIA'][$m['C']] ?? null;
        $i = self::WEIGHTS['CIA'][$m['I']] ?? null;
        $a = self::WEIGHTS['CIA'][$m['A']] ?? null;
        if (in_array(null, [$pr, $av, $ac, $ui, $c, $i, $a], true)) {
            return null;
        }

        $iss = 1 - ((1 - $c) * (1 - $i) * (1 - $a));
        $impact = $changed ? 7.52 * ($iss - 0.029) - 3.25 * (($iss - 0.02) ** 15) : 6.42 * $iss;
        $exploitability = 8.22 * $av * $ac * $pr * $ui;

        if ($impact <= 0) {
            return 0.0;
        }

        return self::roundUp(min(($changed ? 1.08 : 1) * ($impact + $exploitability), 10));
    }

    /** CVSS 3.1's round up to one decimal, without float noise. */
    private static function roundUp(float $value): float
    {
        $int = (int) round($value * 100000);

        return $int % 10000 === 0 ? $int / 100000 : (floor($int / 10000) + 1) / 10;
    }
}
