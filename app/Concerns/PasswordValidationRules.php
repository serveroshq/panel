<?php

namespace App\Concerns;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rules\Password;

trait PasswordValidationRules
{
    /** @return array<int, Password|ValidationRule|array<mixed>|string> */
    protected function passwordRules(): array
    {
        // Long enough for any password manager; bcrypt only reads the first 72 bytes anyway.
        return ['required', 'string', 'max:128', Password::default(), 'confirmed'];
    }

    /** @return array<int, Password|ValidationRule|array<mixed>|string> */
    protected function currentPasswordRules(): array
    {
        return ['required', 'string', 'current_password'];
    }
}
