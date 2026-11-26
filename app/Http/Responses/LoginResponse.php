<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;
use Laravel\Fortify\Contracts\TwoFactorLoginResponse as TwoFactorLoginResponseContract;
use Laravel\Fortify\Fortify;
use Symfony\Component\HttpFoundation\Response;

class LoginResponse implements LoginResponseContract, RegisterResponseContract, TwoFactorLoginResponseContract
{
    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $registered = $request->routeIs('register.store');
        if ($registered) {
            $request->session()->put('celebrate_trial', true);
        }

        $url = redirect()->intended(Fortify::redirects('login'))->getTargetUrl();

        if ($request->wantsJson()) {
            return $registered
                ? new JsonResponse(['redirect' => $url], 201)
                : new JsonResponse(['two_factor' => false, 'redirect' => $url]);
        }

        return Inertia::location($url);
    }
}
