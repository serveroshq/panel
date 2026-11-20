<?php

namespace App\Support\Licensing;

use App\Enums\Feature;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Edition;

/**
 * A self-hosted install asks the people who run it, once they've had a
 * week with it and connected a machine, to donate or get a Scale
 * license. Never on serveros.com, never once the workspace is licensed, and
 * never for members who can't act on it. Dismissing it is the browser's to
 * remember, for a few months.
 */
final class SupportAsk
{
    public const int AFTER_DAYS = 7;

    /** @return array{donateUrl: string, licenseUrl: string}|null */
    public static function for(Workspace $workspace, ?User $user, int $machines): ?array
    {
        if (! Edition::selfHosted() || ! config('serveros.support_ask.enabled', true)
            || $user === null || ! $user->can('manageSettings', $workspace)
            || $machines === 0 || $workspace->created_at === null || $workspace->created_at->gt(now()->subDays(self::AFTER_DAYS))
            || self::licensed($workspace)) {
            return null;
        }

        return [
            'donateUrl' => (string) config('serveros.support_ask.donate_url'),
            'licenseUrl' => (string) config('serveros.support_ask.license_url'),
        ];
    }

    private static function licensed(Workspace $workspace): bool
    {
        foreach (CommunityLicense::paidFeatures() as $feature) {
            if ($workspace->hasFeature(Feature::from($feature))) {
                return true;
            }
        }

        return false;
    }
}
