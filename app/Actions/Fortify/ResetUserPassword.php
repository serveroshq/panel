<?php

namespace App\Actions\Fortify;

use App\Concerns\PasswordValidationRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\ResetsUserPasswords;

class ResetUserPassword implements ResetsUserPasswords
{
    use PasswordValidationRules;

    /** @param  array<string, string>  $input */
    public function reset(User $user, array $input): void
    {
        Validator::make($input, [
            'password' => $this->passwordRules(),
        ])->validate();

        DB::transaction(function () use ($user, $input) {
            // Whoever signed up with an unconfirmed address may not own it,
            // so the reset link (which does reach the owner) also drops any
            // passkeys and two-factor set up before the address was confirmed.
            if (! $user->hasVerifiedEmail()) {
                $user->passkeys()->delete();
                $user->forceFill([
                    'two_factor_secret' => null,
                    'two_factor_recovery_codes' => null,
                    'two_factor_confirmed_at' => null,
                ]);
            }

            $user->forceFill([
                'password' => $input['password'],
                'password_set_at' => now(),
            ])->save();
        });

        $user->signOutEverywhere();
    }
}
