<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication as FortifyDisableTwoFactorAuthentication;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;

/**
 * Turning two-factor off needs a current code from the authenticator app or a
 * recovery code. Confirming the password alone isn't enough: it lasts a few
 * hours, and an account that signs in with Google or GitHub has none, so a
 * stolen session could otherwise remove two-factor. Fortify also calls this
 * to clear a setup that was never confirmed, which needs no code.
 */
class DisableTwoFactorAuthentication extends FortifyDisableTwoFactorAuthentication
{
    public const int WRONG_CODES_PER_MINUTE = 5;

    public function __construct(private readonly TwoFactorAuthenticationProvider $provider) {}

    /**
     * @param  mixed  $user
     * @return void
     */
    public function __invoke($user)
    {
        if ($user instanceof User && $user->two_factor_confirmed_at !== null) {
            $this->checkCode($user, request()->input('code'));
        }

        parent::__invoke($user);
    }

    private function checkCode(User $user, mixed $code): void
    {
        $code = is_string($code) ? trim($code) : '';
        if ($code === '') {
            throw ValidationException::withMessages(['code' => __('Enter a code from your authenticator app, or a recovery code.')]);
        }

        $attempts = 'two-factor-off:'.$user->id;
        if (RateLimiter::tooManyAttempts($attempts, self::WRONG_CODES_PER_MINUTE)) {
            throw ValidationException::withMessages(['code' => __('Too many wrong codes. Try again in a minute.')]);
        }
        if (! $this->matches($user, $code)) {
            RateLimiter::hit($attempts, 60);

            throw ValidationException::withMessages(['code' => __('That code didn’t work. Enter the current code from your authenticator app, or one of your recovery codes.')]);
        }
        RateLimiter::clear($attempts);
    }

    private function matches(User $user, string $code): bool
    {
        $digits = str_replace(' ', '', $code);
        if ($user->two_factor_secret !== null && preg_match('/^\d{6}$/', $digits) === 1
            && $this->provider->verify(decrypt($user->two_factor_secret), $digits)) {
            return true;
        }
        $recovery = $user->two_factor_recovery_codes !== null ? (array) $user->recoveryCodes() : [];

        return collect($recovery)->contains(fn (mixed $known) => is_string($known) && hash_equals($known, $code));
    }
}
