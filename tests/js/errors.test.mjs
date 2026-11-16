import assert from 'node:assert/strict';
import test from 'node:test';
import {
    describe,
    errorMessage,
    failIfBad,
    jobReason,
} from '../../resources/js/lib/errors.ts';

const json = (status, body) =>
    new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });

test('a network failure is explained, never shown as "Failed to fetch"', () => {
    globalThis.window = { isSecureContext: true };
    const secure = errorMessage(new TypeError('Failed to fetch'));
    assert.match(secure, /Couldn’t reach the panel/);
    assert.doesNotMatch(secure, /HTTPS/);

    globalThis.window = { isSecureContext: false };
    assert.match(errorMessage(new TypeError('Failed to fetch')), /HTTPS/);
    assert.match(
        errorMessage(new TypeError('NetworkError when attempting to fetch resource.')),
        /HTTPS/,
    );
    assert.match(
        errorMessage(Object.assign(new Error('Network error'), { code: 'ERR_NETWORK' })),
        /HTTPS/,
    );
});

test('the server’s reason wins over a generic line', () => {
    assert.equal(
        describe(422, { message: 'The name field is required. (and 1 more error)', errors: { name: ['The name field is required.'] } }),
        'The name field is required.',
    );
    assert.equal(describe(503, { error: 'The daemon installer is not configured.' }), 'The daemon installer is not configured.');
    assert.equal(
        describe(409, { error: { type: 'conflict', message: 'Already running.', next_step: 'Wait for it.' } }),
        'Already running. Wait for it.',
    );
    assert.equal(describe(500, { message: 'Server Error' }), 'The panel hit an error and logged it. Try again in a moment.');
    assert.match(describe(419, { message: 'CSRF token mismatch.' }), /open too long/);
    assert.match(describe(502, null), /didn’t answer/);
    assert.match(describe(418, null), /error 418/);
});

test('failIfBad throws the reason, and errorMessage shows it', async () => {
    await assert.rejects(failIfBad(json(403, { message: 'This action is unauthorized.' })), (e) => {
        assert.match(errorMessage(e), /permission/);
        return true;
    });
    const ok = json(200, { ok: true });
    assert.equal(await failIfBad(ok), ok);
});

test('code bugs fall back instead of showing JavaScript internals', () => {
    assert.equal(errorMessage(new TypeError("Cannot read properties of undefined (reading 'x')"), 'Fallback.'), 'Fallback.');
    assert.equal(errorMessage(new SyntaxError('Unexpected token <'), 'Fallback.'), 'Fallback.');
    class DatabaseError extends Error {}
    assert.equal(errorMessage(new DatabaseError('Table is locked.')), 'Table is locked.');
});

test('an Inertia response error is read like a fetch one', () => {
    const cause = Object.assign(new Error('Request failed with status 422'), {
        response: { status: 422, data: '{"errors":{"code":["That code is wrong."]}}' },
    });
    assert.equal(errorMessage(cause), 'That code is wrong.');
});

test('job errors carry their next step', () => {
    assert.equal(jobReason({ message: 'Disk is full', next_step: 'Free some space.' }, 'x'), 'Disk is full. Free some space.');
    assert.equal(jobReason({ message: 'Done badly.' }, 'x'), 'Done badly.');
    assert.equal(jobReason(null, 'Fallback.'), 'Fallback.');
});
