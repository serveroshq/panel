<?php

use App\Enums\Plan;
use App\Mcp\Scopes;
use App\Models\ApiIdempotencyKey;
use App\Models\McpToken;
use App\Models\Workspace;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

/** @return array{0: Workspace, 1: string} */
function idempotencyWorkspace(): array
{
    $workspace = Workspace::factory()->onPlan(Plan::Pro)->create();
    [, $key] = McpToken::issueKey($workspace, $workspace->owner, 'CI', Scopes::ALL, McpToken::API);

    return [$workspace, $key];
}

function idempotent(string $key, ?string $idempotencyKey, array $body, string $method = 'POST', string $path = 'machine-groups'): TestResponse
{
    $headers = ['Authorization' => "Bearer {$key}"];
    if ($idempotencyKey !== null) {
        $headers['Idempotency-Key'] = $idempotencyKey;
    }

    // Headers stick to the test case, so start clean each time.
    return test()->flushHeaders()->withHeaders($headers)->json($method, "/api/v1/{$path}", $body);
}

test('the same key and request twice does the work once and answers the same both times', function () {
    [$workspace, $key] = idempotencyWorkspace();

    $first = idempotent($key, 'group-eu', ['name' => 'eu-web'])->assertOk();
    $again = idempotent($key, 'group-eu', ['name' => 'eu-web'])->assertOk()->assertHeader('Idempotent-Replayed', 'true')->assertHeader('Idempotency-Key', 'group-eu');
    $first->assertHeader('Idempotency-Key', 'group-eu');

    expect($again->getContent())->toBe($first->getContent())
        ->and($workspace->machineGroups()->count())->toBe(1);

    // Without a key, sending it again does it again.
    idempotent($key, null, ['name' => 'us-web'])->assertOk();
    idempotent($key, null, ['name' => 'us-web'])->assertHeaderMissing('Idempotent-Replayed')->assertHeaderMissing('Idempotency-Key');
    expect(ApiIdempotencyKey::query()->count())->toBe(1);
});

test('the same key with a different request is a 409, and nothing is done', function () {
    [$workspace, $key] = idempotencyWorkspace();

    idempotent($key, 'release-1', ['name' => 'eu-web'])->assertOk();
    idempotent($key, 'release-1', ['name' => 'us-web'])->assertStatus(409)->assertJsonPath('error.type', 'conflict')->assertHeader('Idempotency-Key', 'release-1');
    idempotent($key, 'release-1', ['name' => 'eu-web'], 'POST', 'monitors')->assertStatus(409);

    expect($workspace->machineGroups()->pluck('name')->all())->toBe(['eu-web']);
});

test('a refusal is replayed too, and keys are per API key and last 24 hours', function () {
    [$workspace, $key] = idempotencyWorkspace();
    [, $otherKey] = McpToken::issueKey($workspace, $workspace->owner, 'Other', Scopes::ALL, McpToken::API);

    $refused = idempotent($key, 'bad', ['name' => ''])->assertStatus(422);
    idempotent($key, 'bad', ['name' => ''])->assertStatus(422)->assertHeader('Idempotent-Replayed', 'true')
        ->assertContent($refused->getContent());

    idempotent($key, 'shared', ['name' => 'one'])->assertOk();
    idempotent($otherKey, 'shared', ['name' => 'two'])->assertOk()->assertHeaderMissing('Idempotent-Replayed');

    $this->travel(ApiIdempotencyKey::HOURS + 1)->hours();
    idempotent($key, 'shared', ['name' => 'three'])->assertOk()->assertHeaderMissing('Idempotent-Replayed');

    expect($workspace->machineGroups()->orderBy('name')->pluck('name')->all())->toBe(['one', 'three', 'two']);
});

test('a key whose request is still running is a 409 until it answers, or for 5 minutes if it never does', function () {
    [, $key] = idempotencyWorkspace();
    $token = McpToken::query()->where('audience', McpToken::API)->sole();
    $body = ['name' => 'eu-web'];
    ApiIdempotencyKey::query()->create([
        'mcp_token_id' => $token->id,
        'key_hash' => hash('sha256', 'running'),
        'request_hash' => hash('sha256', "POST\napi/v1/machine-groups\n".json_encode($body)),
        'created_at' => now(),
    ]);

    idempotent($key, 'running', $body)->assertStatus(409)->assertHeader('Retry-After', '2')->assertJsonPath('error.message', fn (string $m) => str_contains($m, 'still running'));
    // A different request under that key isn't worth sending again, so it gets no Retry-After.
    idempotent($key, 'running', ['name' => 'other'])->assertStatus(409)->assertHeaderMissing('Retry-After');

    $this->travel(6)->minutes();
    idempotent($key, 'running', $body)->assertOk()->assertHeaderMissing('Idempotent-Replayed');
});

test('reads ignore the header, a malformed key is refused, and old keys are pruned', function () {
    [, $key] = idempotencyWorkspace();

    idempotent($key, 'read', [], 'GET', 'machines')->assertOk();
    idempotent($key, 'read', [], 'GET', 'machines')->assertOk()->assertHeaderMissing('Idempotent-Replayed');
    expect(ApiIdempotencyKey::query()->count())->toBe(0);

    idempotent($key, str_repeat('k', 256), ['name' => 'x'])->assertStatus(422)->assertJsonPath('error.type', 'invalid_arguments');
    idempotent($key, 'two words', ['name' => 'x'])->assertStatus(422);

    idempotent($key, 'old', ['name' => 'eu-web'])->assertOk();
    $this->travel(ApiIdempotencyKey::HOURS + 1)->hours();
    idempotent($key, 'new', ['name' => 'us-web'])->assertOk();
    Artisan::call('model:prune', ['--model' => [ApiIdempotencyKey::class]]);

    expect(ApiIdempotencyKey::query()->count())->toBe(1);
});

test('a stored answer is encrypted, so something shown once isn’t readable in the table', function () {
    [, $key] = idempotencyWorkspace();

    $first = idempotent($key, 'group-secret', ['name' => 'eu-secret-name'])->assertOk();

    expect((string) DB::table('api_idempotency_keys')->value('body'))->not->toContain('eu-secret-name');
    expect(idempotent($key, 'group-secret', ['name' => 'eu-secret-name'])->assertOk()->getContent())->toBe($first->getContent());
});
