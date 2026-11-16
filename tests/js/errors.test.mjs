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

await test('a network failure is explained, never shown as "Failed to fetch"', () => {
    globalThis.window = { isSecureContext: true };
    const secure = errorMessage(new TypeError('Failed to fetch'));
    assert.match(secure, /Couldn’t reach the panel/);
    assert.doesNotMatch(secure, /HTTPS/);

    globalThis.window = { isSecureContext: false };
    assert.match(errorMessage(new TypeError('Failed to fetch')), /HTTPS/);
    assert.match(
        errorMessage(
            new TypeError('NetworkError when attempting to fetch resource.'),
        ),
        /HTTPS/,
    );
    assert.match(
        errorMessage(
            Object.assign(new Error('Network error'), { code: 'ERR_NETWORK' }),
        ),
        /HTTPS/,
    );
});

await test('the server’s reason wins over a generic line', () => {
    assert.equal(
        describe(422, {
            message: 'The name field is required. (and 1 more error)',
            errors: { name: ['The name field is required.'] },
        }),
        'The name field is required.',
    );
    assert.equal(
        describe(503, { error: 'The daemon installer is not configured.' }),
        'The daemon installer is not configured.',
    );
    assert.equal(
        describe(409, {
            error: {
                type: 'conflict',
                message: 'Already running.',
                next_step: 'Wait for it.',
            },
        }),
        'Already running. Wait for it.',
    );
    assert.equal(
        describe(500, { message: 'Server Error' }),
        'The panel hit an error and logged it. Try again in a moment.',
    );
    assert.match(
        describe(419, { message: 'CSRF token mismatch.' }),
        /open too long/,
    );
    assert.match(describe(502, null), /didn’t answer/);
    assert.match(describe(418, null), /error 418/);
});

await test('failIfBad throws the reason, and errorMessage shows it', async () => {
    await assert.rejects(
        failIfBad(json(403, { message: 'This action is unauthorized.' })),
        (e) => {
            assert.match(errorMessage(e), /permission/);
            return true;
        },
    );
    const ok = json(200, { ok: true });
    assert.equal(await failIfBad(ok), ok);
});

await test('code bugs fall back instead of showing JavaScript internals', () => {
    assert.equal(
        errorMessage(
            new TypeError("Cannot read properties of undefined (reading 'x')"),
            'Fallback.',
        ),
        'Fallback.',
    );
    assert.equal(
        errorMessage(new SyntaxError('Unexpected token <'), 'Fallback.'),
        'Fallback.',
    );
    class DatabaseError extends Error {}
    assert.equal(
        errorMessage(new DatabaseError('Table is locked.')),
        'Table is locked.',
    );
});

await test('an Inertia response error is read like a fetch one', () => {
    const cause = Object.assign(new Error('Request failed with status 422'), {
        response: {
            status: 422,
            data: '{"errors":{"code":["That code is wrong."]}}',
        },
    });
    assert.equal(errorMessage(cause), 'That code is wrong.');
});

await test('job errors carry their next step', () => {
    assert.equal(
        jobReason(
            { message: 'Disk is full', next_step: 'Free some space.' },
            'x',
        ),
        'Disk is full. Free some space.',
    );
    assert.equal(jobReason({ message: 'Done badly.' }, 'x'), 'Done badly.');
    assert.equal(jobReason(null, 'Fallback.'), 'Fallback.');
});

await test('a failed job shows the line from the machine that says why', () => {
    assert.equal(
        jobReason(
            {
                message: 'apt-get exited 100',
                output_tail: [
                    'Reading package lists...',
                    'E: Unable to locate package nginx-full',
                    'W: ignored',
                ],
            },
            'x',
        ),
        'apt-get exited 100. The machine said: E: Unable to locate package nginx-full',
    );
    assert.equal(
        jobReason(
            {
                message: 'E: Unable to locate package x',
                output_tail: ['E: Unable to locate package x'],
            },
            'x',
        ),
        'E: Unable to locate package x',
    );
});

await test('edge cases read as plain sentences', () => {
    assert.equal(describe(200, {}), 'That didn’t work. Try again.');
    assert.match(
        describe(404, {
            message: 'No query results for model [App\\Models\\Machine] 5',
        }),
        /isn’t there any more/,
    );
    assert.match(
        describe(405, {
            message:
                'The POST method is not supported for route machines. Supported methods: GET, HEAD.',
        }),
        /error 405/,
    );
    assert.equal(
        describe(409, {
            error: 'Is it running?',
            next_step: 'Check the machine.',
        }),
        'Is it running? Check the machine.',
    );
    assert.equal(
        errorMessage(
            Object.assign(new Error('Request was cancelled (/x)'), {
                code: 'ERR_CANCELLED',
            }),
            'Fallback.',
        ),
        'Fallback.',
    );
    assert.equal(
        jobReason(
            { message: 'exited 1', output_tail: ['SECRET=x'] },
            'x',
            false,
        ),
        'exited 1',
    );
});
