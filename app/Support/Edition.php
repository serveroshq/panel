<?php

namespace App\Support;

use App\Models\User;
use App\Models\Workspace;
use App\Support\Extensions\Extensions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class Edition
{
    public static function selfHosted(): bool
    {
        return config('serveros.edition') === 'self-hosted';
    }

    public static function installAdmin(?User $user, Workspace $workspace): bool
    {
        return self::selfHosted() && $user !== null
            && $workspace->owner_id === $user->id && $user->can('manageSettings', $workspace);
    }

    public static function withoutBilling(): bool
    {
        return self::selfHosted() && app(Extensions::class)->upgradeLink() === null;
    }

    public static function home(Request $request): RedirectResponse
    {
        return match (true) {
            $request->user() !== null => redirect()->route('dashboard'),
            User::query()->doesntExist() => redirect()->route('register'),
            default => redirect()->route('login'),
        };
    }
}
