<?php

use App\Support\Security\Feeds;

/** @param  array<string, mixed>  $metrics */
function nvd(array $metrics): array
{
    return ['id' => 'CVE-2026-1234', 'published' => '2026-01-01T00:00:00Z', 'metrics' => $metrics];
}

/** @return array<string, list<array<string, mixed>>> */
function nvdMetric(string $version, ?float $score, string $type = 'Primary'): array
{
    return [$version => [['type' => $type, 'cvssData' => ['baseScore' => $score]]]];
}

test('the newest CVSS version wins when the NVD lists several', function () {
    $entry = Feeds::parseNvd(nvd([
        ...nvdMetric('cvssMetricV31', 7.5),
        ...nvdMetric('cvssMetricV40', 9.1),
    ]));

    expect($entry['cvss'])->toBe(9.1)
        ->and($entry['severity'])->toBe('critical')
        ->and($entry['severity_by'])->toBe('nvd');
});

test('CVSS v3.1 is still preferred over v3.0', function () {
    $entry = Feeds::parseNvd(nvd([
        ...nvdMetric('cvssMetricV30', 5.0),
        ...nvdMetric('cvssMetricV31', 8.2),
    ]));

    expect($entry['cvss'])->toBe(8.2)
        ->and($entry['severity'])->toBe('high');
});

test('a primary score is preferred over a secondary score in the same version', function () {
    $entry = Feeds::parseNvd(nvd([
        'cvssMetricV40' => [
            ['type' => 'Secondary', 'cvssData' => ['baseScore' => 4.0]],
            ['type' => 'Primary', 'cvssData' => ['baseScore' => 9.8]],
        ],
    ]));

    expect($entry['cvss'])->toBe(9.8);
});

test('an older version is used when the newest one has no score', function () {
    $entry = Feeds::parseNvd(nvd([
        ...nvdMetric('cvssMetricV40', null),
        ...nvdMetric('cvssMetricV31', 6.5),
    ]));

    expect($entry['cvss'])->toBe(6.5)
        ->and($entry['severity'])->toBe('medium');
});
