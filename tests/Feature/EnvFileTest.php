<?php

use App\Support\Deploys\EnvFile;

test('a pasted .env reads the way dotenv tools read it', function () {
    $parsed = EnvFile::parse(<<<'ENV'
# Comments and blank lines are skipped

export APP_NAME=Shop
PLAIN = spaced out   # a note after it
URL=https://example.com/#anchor
SINGLE='kept as $IS \n'
DOUBLE="a \"quoted\" word\tand a tab"
EMPTY=
KEY="-----BEGIN KEY-----
line two
-----END KEY-----"
AFTER=1
ENV);

    expect($parsed['errors'])->toBe([])
        ->and($parsed['variables'])->toBe([
            'APP_NAME' => 'Shop',
            'PLAIN' => 'spaced out',
            'URL' => 'https://example.com/#anchor',
            'SINGLE' => 'kept as $IS \n',
            'DOUBLE' => "a \"quoted\" word\tand a tab",
            'EMPTY' => '',
            'KEY' => "-----BEGIN KEY-----\nline two\n-----END KEY-----",
            'AFTER' => '1',
        ]);
});

test('a bad line or a quote that never closes is pointed out by its line', function () {
    expect(EnvFile::parse("A=1\n2BAD=x")['errors'])->toBe([2 => 'Line 2 should look like NAME=value.'])
        ->and(EnvFile::parse("A=1\nB=\"open\nC=2")['errors'])->toBe([2 => 'The value of B on line 2 opens a " quote that never closes.'])
        ->and(EnvFile::parse('LONG='.str_repeat('x', EnvFile::MAX_VALUE_BYTES + 1))['errors'])->toBe([1 => 'LONG is longer than 64 KB.']);
});

test('a value written out reads back the same', function () {
    foreach (['plain', "two\nlines", 'with "quotes" and $dollars', ''] as $value) {
        expect(EnvFile::parse(EnvFile::line('X', $value))['variables'])->toBe(['X' => $value]);
    }
});
