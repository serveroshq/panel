<?php

namespace App\Http\Responses;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LogoutResponse as LogoutResponseContract;
use Symfony\Component\HttpFoundation\Response;

class LogoutResponse implements LogoutResponseContract
{
    public const string REDIRECT = 'sign_out.redirect';

    /**
     * @param  Request  $request
     */
    public function toResponse($request): Response
    {
        $idp = $request->attributes->get(self::REDIRECT);
        $idp = is_string($idp) && $idp !== '' ? $idp : null;

        if ($request->wantsJson()) {
            return $idp === null ? new JsonResponse('', 204) : new JsonResponse(['redirect' => $idp]);
        }

        if ($idp !== null) {
            return $request->header('X-Inertia') ? Inertia::location($idp) : redirect()->away($idp);
        }

        return Inertia::location(rtrim((string) config('app.url'), '/'));
    }
}
