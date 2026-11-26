<?php

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

beforeEach(function () {
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturnUsing(fn ($secret, $code) => $code === '123456');
    $provider->shouldReceive('generateSecretKey')->andReturn('secret');
    $provider->shouldReceive('qrCodeUrl')->andReturn('otpauth://totp/test');
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);
});

function signedInElsewhere(User $user, string $id = 'phone'): void
{
    DB::table(config('session.table', 'sessions'))->insert([
        'id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'Phone', 'payload' => base64_encode(serialize([])), 'last_activity' => time(),
    ]);
}

test('turning two-factor on signs you out everywhere else, as changing the password does', function () {
    $user = User::factory()->create();
    signedInElsewhere($user);
    $remembered = $user->remember_token;
    $confirmed = ['auth.password_confirmed_at' => time()];

    $this->actingAs($user)->withSession($confirmed)->post(route('two-factor.enable'))->assertSessionHasNoErrors();
    expect(DB::table('sessions')->where('id', 'phone')->exists())->toBeTrue();

    $this->actingAs($user)->withSession($confirmed)->post(route('two-factor.confirm'), ['code' => '123456'])->assertSessionHasNoErrors();

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull()
        ->and(DB::table('sessions')->where('id', 'phone')->exists())->toBeFalse()
        ->and($user->fresh()->remember_token)->not->toBe($remembered);
    $this->actingAs($user)->get(route('security.edit'))->assertOk();
});

test('turning two-factor off needs a current code from the app or a recovery code, not just the password', function () {
    $user = User::factory()->withTwoFactor()->create();
    $confirmed = ['auth.password_confirmed_at' => time()];

    $this->actingAs($user)->withSession($confirmed)->delete(route('two-factor.disable'))->assertSessionHasErrors('code');
    $this->actingAs($user)->withSession($confirmed)->delete(route('two-factor.disable'), ['code' => '000000'])->assertSessionHasErrors('code');
    $this->actingAs($user)->withSession($confirmed)->delete(route('two-factor.disable'), ['code' => 'recovery-code-2'])->assertSessionHasErrors('code');
    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->actingAs($user)->withSession($confirmed)->delete(route('two-factor.disable'), ['code' => '123 456'])->assertSessionHasNoErrors();
    expect($user->fresh()->two_factor_secret)->toBeNull()->and($user->fresh()->two_factor_confirmed_at)->toBeNull();

    $other = User::factory()->withTwoFactor()->create();
    $this->actingAs($other)->withSession($confirmed)->delete(route('two-factor.disable'), ['code' => 'recovery-code-1'])->assertSessionHasNoErrors();
    expect($other->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('an account that signs in with Google or GitHub still needs a code to turn two-factor off', function () {
    $user = User::factory()->withoutPassword()->withTwoFactor()->create();
    $confirmed = ['auth.password_confirmed_at' => time()];

    $this->actingAs($user)->withSession($confirmed)->delete(route('two-factor.disable'))->assertSessionHasErrors('code');
    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->actingAs($user)->withSession($confirmed)->delete(route('two-factor.disable'), ['code' => '123456'])->assertSessionHasNoErrors();
    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

test('guessing codes to turn two-factor off stops after a few tries', function () {
    $user = User::factory()->withTwoFactor()->create();
    $confirmed = ['auth.password_confirmed_at' => time()];

    foreach (range(1, 5) as $try) {
        $this->actingAs($user)->withSession($confirmed)->delete(route('two-factor.disable'), ['code' => sprintf('%06d', $try)]);
    }
    $this->actingAs($user)->withSession($confirmed)->delete(route('two-factor.disable'), ['code' => '123456'])->assertSessionHasErrors('code');

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();
});

test('a two-factor setup that was never confirmed is cleared without a code', function () {
    $user = User::factory()->create(['two_factor_secret' => encrypt('secret'), 'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1']))]);

    $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->delete(route('two-factor.disable'))->assertSessionHasNoErrors();

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

test('a name can’t carry a web address into the emails sent for you', function () {
    $this->post(route('register.store'), ['name' => 'Claim it at prize.example', 'email' => 'alex@northwind.dev', 'password' => 'password', 'password_confirmation' => 'password'])
        ->assertSessionHasErrors('name');
    expect(User::query()->where('email', 'alex@northwind.dev')->exists())->toBeFalse();

    $user = User::factory()->create();
    foreach (['Visit example.com', 'https://evil.test', 'www.evil', 'sam@evil.test'] as $name) {
        $this->actingAs($user)->patch(route('profile.update'), ['name' => $name, 'email' => $user->email])->assertSessionHasErrors('name');
    }

    foreach (['Dr. Ada O’Neil-Lovelace', 'José Núñez', 'Mr. J. Smith'] as $name) {
        $this->actingAs($user)->patch(route('profile.update'), ['name' => $name, 'email' => $user->email])->assertSessionHasNoErrors();
        expect($user->fresh()->name)->toBe($name);
    }
});

test('a new password is at most 128 characters', function () {
    $user = User::factory()->create();
    $long = str_repeat('Aa1!', 33);

    $this->actingAs($user)->put(route('user-password.update'), ['current_password' => 'password', 'password' => $long, 'password_confirmation' => $long])
        ->assertSessionHasErrors('password');
    $this->actingAs($user)->put(route('user-password.update'), ['current_password' => 'password', 'password' => substr($long, 0, 128), 'password_confirmation' => substr($long, 0, 128)])
        ->assertSessionHasNoErrors();
});
