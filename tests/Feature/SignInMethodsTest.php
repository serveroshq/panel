<?php

use App\Actions\Fortify\ResetUserPassword;
use App\Models\User;

test('adding a passkey or two-factor needs a confirmed email', function () {
    $user = User::factory()->unverified()->create();
    $confirmed = ['auth.password_confirmed_at' => now()->timestamp];

    $this->actingAs($user)->withSession($confirmed)->getJson('/user/passkeys/options')->assertForbidden();
    $this->actingAs($user)->withSession($confirmed)->postJson('/user/passkeys')->assertForbidden();
    $this->actingAs($user)->withSession($confirmed)->postJson('/user/two-factor-authentication')->assertForbidden();

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('a confirmed account can still add a passkey', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->getJson('/user/passkeys/options')
        ->assertOk();
});

test('resetting the password of an unconfirmed account drops its passkeys and two-factor', function () {
    $user = User::factory()->unverified()->create([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt('[]'),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->passkeys()->create(['name' => 'Key', 'credential_id' => 'cred', 'credential' => ['publicKey' => 'key']]);

    app(ResetUserPassword::class)->reset($user->fresh(), [
        'password' => 'New-Password-1!',
        'password_confirmation' => 'New-Password-1!',
    ]);

    $user->refresh();
    expect($user->passkeys()->count())->toBe(0)
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull();
});

test('resetting the password of a confirmed account keeps its passkeys and two-factor', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->passkeys()->create(['name' => 'Key', 'credential_id' => 'cred', 'credential' => ['publicKey' => 'key']]);

    app(ResetUserPassword::class)->reset($user->fresh(), [
        'password' => 'New-Password-1!',
        'password_confirmation' => 'New-Password-1!',
    ]);

    $user->refresh();
    expect($user->passkeys()->count())->toBe(1)
        ->and($user->two_factor_secret)->not->toBeNull();
});
