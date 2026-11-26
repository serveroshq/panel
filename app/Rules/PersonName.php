<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A person's name without a web address or email address in it. Names turn
 * up in emails ServerOS sends to other people, like invitations, where mail
 * apps would make one into a link.
 */
final class PersonName implements ValidationRule
{
    public const string LINK = '~(://|www\.|@|[a-z0-9-]\.[a-z]{2,}(?![a-z]))~i';

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && preg_match(self::LINK, $value) === 1) {
            $fail(__('Enter a name without a web address.'));
        }
    }
}
