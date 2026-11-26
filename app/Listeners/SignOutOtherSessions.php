<?php

namespace App\Listeners;

use App\Http\Controllers\Settings\SessionController;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;

/**
 * Turning two-factor on signs you out everywhere else, as changing the
 * password does: a session someone else already has shouldn't outlive the
 * new protection.
 */
class SignOutOtherSessions
{
    public function handle(TwoFactorAuthenticationConfirmed $event): void
    {
        SessionController::signOutOthers(request(), $event->user);
    }
}
