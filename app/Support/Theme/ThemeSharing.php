<?php

namespace App\Support\Theme;

use App\Models\Activity;
use App\Models\ThemeShare;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Theme links: a copy of a theme (and the images it uses) that anyone with
 * the link can see and add to a workspace of their own, as a draft.
 */
final class ThemeSharing
{
    public const MAX_SHARES = 50;

    public function __construct(private readonly DashboardTheme $theme) {}

    /** @throws InvalidArgumentException when the CSS can't be used or there are too many links */
    public function share(Workspace $workspace, User $user, string $name, string $css): ThemeShare
    {
        $css = trim(str_replace("\r\n", "\n", $css))."\n";
        if (trim($css) === '') {
            throw new InvalidArgumentException('There’s no theme to share yet. Pick a template or write some CSS first.');
        }
        if (($problem = ThemeCss::problem($css)) !== null) {
            throw new InvalidArgumentException($problem);
        }
        if (ThemeShare::query()->where('workspace_id', $workspace->id)->count() >= self::MAX_SHARES) {
            throw new InvalidArgumentException('A workspace can have up to '.self::MAX_SHARES.' theme links. Stop sharing an old one first.');
        }

        $token = Str::lower(Str::random(12));
        $css = (string) preg_replace_callback(
            '#(?:https?://[a-z0-9.:-]+)?/storage/workspace-themes/(\d+)/([A-Za-z0-9._-]+)#i',
            function (array $m) use ($token): string {
                $from = "workspace-themes/{$m[1]}/{$m[2]}";
                $to = "theme-shares/{$token}/{$m[2]}";
                $disk = Storage::disk('public');
                if (! $disk->exists($from)) {
                    return $m[0];
                }
                if (! $disk->exists($to)) {
                    $disk->copy($from, $to);
                }

                return DashboardTheme::imageUrl($to);
            },
            $css,
        );

        $share = new ThemeShare(['token' => $token, 'name' => $name, 'css' => $css, 'user_id' => $user->id]);
        $share->workspace()->associate($workspace);
        $share->save();
        Activity::record($workspace, 'workspace.theme_share', $name, null, $user);

        return $share;
    }

    /** Adds a shared theme to a workspace as the person's draft, with copies of its images. */
    public function import(ThemeShare $share, Workspace $workspace, User $user): void
    {
        $disk = Storage::disk('public');
        $folder = DashboardTheme::imageFolder($workspace);
        $room = DashboardTheme::MAX_IMAGES - count($disk->files($folder));

        $css = (string) preg_replace_callback(
            '#(?:https?://[a-z0-9.:-]+)?/storage/theme-shares/'.preg_quote($share->token, '#').'/([A-Za-z0-9._-]+)#i',
            function (array $m) use ($share, $disk, $folder, &$room): string {
                $from = "theme-shares/{$share->token}/{$m[1]}";
                if ($room <= 0 || ! $disk->exists($from)) {
                    return $m[0];
                }
                $to = $folder.'/'.pathinfo($m[1], PATHINFO_FILENAME).'-'.Str::lower(Str::random(4)).'.'.pathinfo($m[1], PATHINFO_EXTENSION);
                $disk->copy($from, $to);
                $room--;

                return DashboardTheme::imageUrl($to);
            },
            $share->css,
        );

        $this->theme->saveDraft($workspace, $user, $css);
        $share->increment('imports');
        Activity::record($workspace, 'workspace.theme_import', $share->name, null, $user);
    }

    public function stop(ThemeShare $share, User $user): void
    {
        Storage::disk('public')->deleteDirectory('theme-shares/'.$share->token);
        $share->delete();
        Activity::record($share->workspace_id, 'workspace.theme_unshare', $share->name, null, $user);
    }

    /** The share a pasted link (or bare code) points at. */
    public static function find(string $link): ?ThemeShare
    {
        $link = trim($link);
        $token = preg_match('#/themes/([A-Za-z0-9]{8,32})(?:[/?\#]|$)#', $link, $m) ? $m[1] : (preg_match('/^[A-Za-z0-9]{8,32}$/', $link) ? $link : null);

        return $token === null ? null : ThemeShare::query()->where('token', strtolower($token))->first();
    }
}
