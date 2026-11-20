import assert from 'node:assert/strict';
import test from 'node:test';
import {
    countdown,
    fixed,
    timeUnits,
} from '../../resources/js/lib/formatting.ts';

await test('time units are floored, never rounded up', () => {
    assert.deepEqual(timeUnits((59 * 60 + 40) * 1000), {
        minutes: 59,
        hours: 0,
        days: 0,
    });
    assert.deepEqual(timeUnits(110 * 60 * 1000), {
        minutes: 110,
        hours: 1,
        days: 0,
    });
    assert.deepEqual(timeUnits(36 * 60 * 60 * 1000), {
        minutes: 2160,
        hours: 36,
        days: 1,
    });
    assert.deepEqual(timeUnits(-5000), { minutes: 0, hours: 0, days: 0 });
});

await test('a countdown never shows a second that has not started', () => {
    assert.equal(countdown(59.6 * 1000), '0:59');
    assert.equal(countdown(60_000), '1:00');
    assert.equal(countdown(90_000), '1:30');
    assert.equal(countdown(-1000), '0:00');
});

await test('fixed drops trailing zeroes without changing whole numbers', () => {
    assert.equal(fixed(2), '2');
    assert.equal(fixed(2.5), '2.5');
    assert.equal(fixed(2, 2), '2');
    assert.equal(fixed(1.5, 2), '1.5');
    assert.equal(fixed(10), '10');
    assert.equal(fixed(1024), '1024');
});
