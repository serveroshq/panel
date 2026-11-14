<?php

use App\Models\User;

test('it finds the account whatever the case of the email, and makes it an admin', function () {
    $user = User::factory()->withTwoFactor()->create(['email' => 'Ops@Example.com']);

    $this->artisan('admin:grant', ['email' => '  OPS@example.COM '])
        ->expectsOutput('Ops@Example.com is an admin.')
        ->assertExitCode(0);

    expect($user->fresh()->is_admin)->toBeTrue();
});

test('--revoke takes admin away', function () {
    $user = User::factory()->admin()->create(['email' => 'ops@example.com']);

    $this->artisan('admin:grant', ['email' => 'ops@example.com', '--revoke' => true])
        ->expectsOutput('ops@example.com is no longer an admin.')
        ->assertExitCode(0);

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('an email with no account is an error, and nobody changes', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('admin:grant', ['email' => 'Nobody@Example.com'])
        ->expectsOutput('No account uses nobody@example.com. They need to sign up first.')
        ->assertExitCode(1);

    expect($user->fresh()->is_admin)->toBeFalse();
});

test('it warns that an unverified email can’t open the admin pages yet', function () {
    $user = User::factory()->unverified()->withTwoFactor()->create(['email' => 'ops@example.com']);

    $this->artisan('admin:grant', ['email' => 'ops@example.com'])
        ->expectsOutput('ops@example.com is an admin, but can\'t open /admin until their email is verified.')
        ->assertExitCode(0);

    expect($user->fresh()->is_admin)->toBeTrue();
});

test('it warns that an account without two-factor can’t open the admin pages yet', function () {
    $user = User::factory()->create(['email' => 'ops@example.com']);

    $this->artisan('admin:grant', ['email' => 'ops@example.com'])
        ->expectsOutput('ops@example.com is an admin, but can\'t open /admin until they turn on two-factor.')
        ->assertExitCode(0);

    expect($user->fresh()->is_admin)->toBeTrue();
});
