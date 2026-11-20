<?php

namespace App\Support\Theme;

use App\Models\Activity;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * A workspace's custom dashboard CSS. Edits go to a draft that only the
 * person editing sees, so they (or Ask AI for them) can try things on the
 * real dashboard; publishing shows it to everyone in the workspace.
 */
final class DashboardTheme
{
    public const MAX_IMAGES = 20;

    public const IMAGE_TYPES = ['png', 'jpg', 'jpeg', 'webp', 'gif', 'avif'];

    /** Turns custom CSS off in this browser session, so a theme that breaks the page can still be fixed. */
    public const OFF_SESSION = 'dashboard_theme_off';

    /** @return array{css: string|null, draft: bool, published: bool, off: bool, settingsUrl: string} */
    public function shared(Workspace $workspace, ?User $user, Request $request): array
    {
        if ($request->hasSession() && in_array($request->query('theme'), ['off', 'on'], true)) {
            $request->session()->put(self::OFF_SESSION, $request->query('theme') === 'off');
        }
        $off = $request->hasSession() && $request->session()->get(self::OFF_SESSION) === true;
        $draft = $this->draftOf($workspace, $user) !== null;

        return [
            'css' => $off ? null : self::nonEmpty($draft ? $workspace->theme_draft_css : $workspace->theme_css),
            'draft' => $draft,
            'published' => $workspace->theme_css !== null,
            'off' => $off,
            'settingsUrl' => $workspace->url().'/settings/theme',
        ];
    }

    /** The person's unpublished CSS, or null when they have no draft. */
    public function draftOf(Workspace $workspace, ?User $user): ?string
    {
        return $user !== null && $workspace->theme_draft_user_id === $user->id ? $workspace->theme_draft_css : null;
    }

    /** What the person is editing: their draft, or the published CSS when they have none. */
    public function editing(Workspace $workspace, ?User $user): string
    {
        return $this->draftOf($workspace, $user) ?? $workspace->theme_css ?? '';
    }

    /** @throws InvalidArgumentException when the CSS can't be used */
    public function saveDraft(Workspace $workspace, User $user, string $css): void
    {
        $css = self::normalise($css);
        if (($problem = ThemeCss::problem($css)) !== null) {
            throw new InvalidArgumentException($problem);
        }

        $workspace->forceFill(['theme_draft_css' => $css, 'theme_draft_user_id' => $user->id])->save();
    }

    /** Shows the person's draft to everyone. False when they have no draft. */
    public function publish(Workspace $workspace, User $user, string $via = 'dashboard'): bool
    {
        $draft = $this->draftOf($workspace, $user);
        if ($draft === null) {
            return false;
        }
        if (($problem = ThemeCss::problem($draft)) !== null) {
            throw new InvalidArgumentException($problem);
        }

        $css = self::nonEmpty($draft);
        $workspace->forceFill([
            'theme_css' => $css,
            'theme_draft_css' => null,
            'theme_draft_user_id' => null,
            'theme_published_at' => now(),
        ])->save();
        Activity::record($workspace, $css === null ? 'workspace.theme_off' : 'workspace.theme_publish', null, null, $user, ['via' => $via]);

        return true;
    }

    public function discardDraft(Workspace $workspace, User $user): bool
    {
        if ($this->draftOf($workspace, $user) === null) {
            return false;
        }
        $workspace->forceFill(['theme_draft_css' => null, 'theme_draft_user_id' => null])->save();

        return true;
    }

    /** Goes back to the standard look for everyone. False when no theme was published. */
    public function turnOff(Workspace $workspace, User $user): bool
    {
        if ($workspace->theme_css === null) {
            return false;
        }
        $workspace->forceFill(['theme_css' => null, 'theme_published_at' => now()])->save();
        Activity::record($workspace, 'workspace.theme_off', null, null, $user);

        return true;
    }

    /** @return list<array{name: string, url: string, size: int}> Images uploaded for the theme, newest first. */
    public function images(Workspace $workspace): array
    {
        $disk = Storage::disk('public');
        $images = [];
        foreach ($disk->files(self::imageFolder($workspace)) as $path) {
            $images[] = ['name' => basename($path), 'url' => self::imageUrl($path), 'size' => $disk->size($path), 'at' => $disk->lastModified($path)];
        }
        usort($images, fn (array $a, array $b) => $b['at'] <=> $a['at']);

        return array_map(fn (array $image) => ['name' => $image['name'], 'url' => $image['url'], 'size' => $image['size']], $images);
    }

    /**
     * @return array{name: string, url: string, size: int}
     *
     * @throws InvalidArgumentException when the workspace has too many images
     */
    public function addImage(Workspace $workspace, User $user, UploadedFile $file): array
    {
        if (count(Storage::disk('public')->files(self::imageFolder($workspace))) >= self::MAX_IMAGES) {
            throw new InvalidArgumentException('Themes can have up to '.self::MAX_IMAGES.' images. Delete one first.');
        }

        $extension = strtolower($file->extension() ?: $file->getClientOriginalExtension());
        $stem = Str::limit(Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)), 40, '') ?: 'image';
        $name = $stem.'-'.Str::lower(Str::random(6)).'.'.$extension;
        $path = (string) $file->storeAs(self::imageFolder($workspace), $name, 'public');
        Activity::record($workspace, 'workspace.theme_image', $name, null, $user);

        return ['name' => $name, 'url' => self::imageUrl($path), 'size' => (int) $file->getSize()];
    }

    /**
     * @return array{name: string, url: string, size: int}
     *
     * @throws InvalidArgumentException when it isn't an image, or the workspace has too many
     */
    public function addImageData(Workspace $workspace, User $user, string $binary, string $extension, string $stem): array
    {
        if (! in_array($extension, self::IMAGE_TYPES, true) || strlen($binary) > 5_120_000 || @getimagesizefromstring($binary) === false) {
            throw new InvalidArgumentException('That image can’t be used. Themes take PNG, JPEG, WebP, GIF or AVIF up to 5 MB.');
        }
        if (count(Storage::disk('public')->files(self::imageFolder($workspace))) >= self::MAX_IMAGES) {
            throw new InvalidArgumentException('Themes can have up to '.self::MAX_IMAGES.' images. Delete one in Settings › Theme first.');
        }

        $name = Str::limit($stem, 40, '').'-'.Str::lower(Str::random(6)).'.'.$extension;
        $path = self::imageFolder($workspace).'/'.$name;
        Storage::disk('public')->put($path, $binary);
        Activity::record($workspace, 'workspace.theme_image', $name, null, $user, ['via' => 'assistant']);

        return ['name' => $name, 'url' => self::imageUrl($path), 'size' => strlen($binary)];
    }

    public function removeImage(Workspace $workspace, User $user, string $name): bool
    {
        $path = self::imageFolder($workspace).'/'.basename($name);
        if (! Storage::disk('public')->exists($path)) {
            return false;
        }
        Storage::disk('public')->delete($path);
        Activity::record($workspace, 'workspace.theme_image_remove', basename($name), null, $user);

        return true;
    }

    public static function imageFolder(Workspace $workspace): string
    {
        return 'workspace-themes/'.$workspace->id;
    }

    /** A path on this panel, so it works on the workspace's own address and custom domains too. */
    public static function imageUrl(string $path): string
    {
        $url = Storage::disk('public')->url($path);
        $host = parse_url($url, PHP_URL_HOST);

        return $host === null || $host === parse_url((string) config('app.url'), PHP_URL_HOST)
            ? (string) parse_url($url, PHP_URL_PATH)
            : $url;
    }

    private static function normalise(string $css): string
    {
        return trim(str_replace("\r\n", "\n", $css))."\n";
    }

    private static function nonEmpty(?string $css): ?string
    {
        return $css === null || trim($css) === '' ? null : $css;
    }
}
