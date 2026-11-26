<?php

use App\Models\User;
use App\Support\Extensions\Extensions;
use App\Support\Extensions\SignInHooks;
use Illuminate\Http\Request;

test('a json sign-in says where to go next', function () {
    $user = User::factory()->create();

    $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertExactJson(['two_factor' => false, 'redirect' => route('dashboard')]);

    $this->assertAuthenticatedAs($user);
});

test('a json sign-in goes where the person was headed', function () {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => route('dashboard', ['to' => '/billing'])])
        ->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertJsonPath('redirect', route('dashboard', ['to' => '/billing']));

    expect(session()->has('url.intended'))->toBeFalse();
});

test('a wrong password still answers with errors, and says nothing about where to go', function () {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => route('dashboard', ['to' => '/billing'])])
        ->postJson(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('email')
        ->assertJsonMissingPath('redirect');

    $this->assertGuest();
    expect(session('url.intended'))->toBe(route('dashboard', ['to' => '/billing']));
});

test('a json sign-in with two-factor asks for the code first, then says where to go', function () {
    $user = User::factory()->withTwoFactor()->create(['two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP')]);

    $this->postJson(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertOk()
        ->assertExactJson(['two_factor' => true]);
    $this->assertGuest();

    $this->postJson(route('two-factor.login.store'), ['code' => '000000'])->assertUnprocessable();
    $this->assertGuest();

    $this->postJson(route('two-factor.login.store'), ['recovery_code' => 'recovery-code-1'])
        ->assertOk()
        ->assertExactJson(['two_factor' => false, 'redirect' => route('dashboard')]);
    $this->assertAuthenticatedAs($user);
});

test('a json sign-up says where to go next, and still gets the trial welcome', function () {
    $this->withSession(['url.intended' => route('dashboard', ['to' => '/billing?choose=pro'])])
        ->postJson(route('register.store'), [
            'name' => 'Alex Rivera',
            'email' => 'alex@northwind.dev',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
        ->assertCreated()
        ->assertExactJson(['redirect' => route('dashboard', ['to' => '/billing?choose=pro'])]);

    $this->assertAuthenticatedAs(User::firstWhere('email', 'alex@northwind.dev'));
    expect(session('celebrate_trial'))->toBeTrue();
});

test('a json sign-out answers with nothing, or with where single sign-on wants the person to go', function () {
    $this->actingAs(User::factory()->create())->postJson(route('logout'))->assertNoContent();
    $this->assertGuest();

    app(Extensions::class)->addSignInHooks(new class extends SignInHooks
    {
        public function signingOut(Request $request): ?string
        {
            return 'https://idp.example/logout';
        }
    });

    $this->actingAs(User::factory()->create())->postJson(route('logout'))
        ->assertOk()
        ->assertExactJson(['redirect' => 'https://idp.example/logout']);
    $this->assertGuest();
});

test('the panel\'s own sign-in pages still move on with an inertia visit', function () {
    $user = User::factory()->create();

    $this->withSession(['url.intended' => route('dashboard', ['to' => '/billing'])])
        ->post(route('login.store'), ['email' => $user->email, 'password' => 'password'])
        ->assertRedirect(route('dashboard', ['to' => '/billing']));
});
