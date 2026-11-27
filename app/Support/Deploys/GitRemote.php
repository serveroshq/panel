<?php

namespace App\Support\Deploys;

use App\Support\ServiceTemplates\RepositoryDetector;

/**
 * Repository addresses a deploy can fetch from: an HTTPS one, or an SSH one
 * on any git host, fetched with the app's deploy key on the machine.
 */
final class GitRemote
{
    // git@host:path.git or ssh://git@host[:port]/path.git; never an option or a local path.
    public const string SSH_PATTERN = '#^(?:[A-Za-z0-9._-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}:(?!/)|ssh://[A-Za-z0-9._-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}(?::\d{1,5})?/)[A-Za-z0-9._~-]+(?:/[A-Za-z0-9._~-]+){0,10}$#';

    // A branch or tag: no leading dash, dot or slash, no "..", like the daemon checks.
    public const string REF_PATTERN = '#^(?![-./])(?!.*\.\.)(?!.*//)(?!.*[./]$)(?!.*\.lock$)[A-Za-z0-9._/-]{1,200}$#';

    public static function isSsh(mixed $repo): bool
    {
        return is_string($repo) && strlen($repo) <= 500 && preg_match(self::SSH_PATTERN, $repo) === 1 && ! str_contains($repo, '..');
    }

    /** A short name for the repository, like acme/shop, for labels. */
    public static function label(string $repo): string
    {
        $path = self::isSsh($repo)
            ? (string) preg_replace('#^(?:ssh://)?[^@]+@[^:/]+(?::\d+)?[:/]#', '', $repo)
            : (string) (parse_url($repo, PHP_URL_PATH) ?? $repo);

        return (string) preg_replace('#\.git$#', '', trim($path, '/'));
    }

    /**
     * A clone address from a pasted link, with the branch and folder a
     * GitLab (/-/tree/<ref>/<folder>), Gitea or Forgejo (/src/branch/<ref>/…)
     * or Bitbucket (/src/<ref>/…) link points at.
     *
     * @return array{0: string, 1: string|null, 2: string|null}
     */
    public static function link(string $url): array
    {
        if (self::isSsh($url)) {
            return [$url, null, null];
        }
        $url = rtrim($url, '/');
        foreach (['#^(https://[^?\#]+?)/-/tree/([^/?\#]+)(?:/([^?\#]+))?$#', '#^(https://[^?\#]+?)/src/branch/([^/?\#]+)(?:/([^?\#]+))?$#', '#^(https://bitbucket\.org/[^/]+/[^/]+)/src/([^/?\#]+)(?:/([^?\#]+))?$#'] as $pattern) {
            if (preg_match($pattern, $url, $m) === 1) {
                $folder = isset($m[3]) && preg_match(RepositoryDetector::BASE_DIR_PATTERN, $m[3]) === 1 ? $m[3] : null;

                return [$m[1], rawurldecode($m[2]), $folder];
            }
        }

        return [(string) preg_replace('#[?\#].*$#', '', $url), null, null];
    }
}
