<?php

namespace App\Support\Deploys;

use App\Http\Controllers\AppVersionController;
use App\Models\Activity;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\User;
use App\Support\DaemonJobQueue;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * An app's site switches: a maintenance page, a password in front of the
 * site, a www redirect and path redirects. Kept with the machine's app
 * settings and sent to the daemon as a site_options job, which applies them
 * at once (no redeploy) and keeps them through later deploys.
 *
 * The password is only ever stored and sent as a bcrypt hash, and never
 * shown again.
 */
final class SiteSwitches
{
    public const string KIND = 'site_options';

    public const int MAX_REDIRECTS = 50;

    public const int MAX_PAGE_BYTES = 262144;

    public const string USER_PATTERN = '/^[A-Za-z0-9._@-]{1,64}$/';

    /** An exact path: letters, numbers and - . _ ~ /, never under the certificate challenge folder. */
    public const string FROM_PATTERN = '#^/(?!\.well-known/acme-challenge/)(?!.*//)(?!(?:.*/)?\.\.(?:/|$))[A-Za-z0-9._~/-]{1,499}$#';

    /** A path on the same site or a full http(s) address, without spaces, quotes, %, $, {} or #. */
    public const string TO_PATTERN = '#^(?:https?://[A-Za-z0-9._~/:?&=+@!,*()-]+|/(?!/)[A-Za-z0-9._~/:?&=+@!,*()-]*)$#';

    public function __construct(private readonly DaemonJobQueue $queue) {}

    /**
     * What's kept for an app, hash included.
     *
     * @return array{maintenance: bool, maintenance_html: string|null, user: string|null, hash: string|null, www_redirect: bool, redirects: list<array{from: string, to: string, permanent: bool}>}
     */
    public static function stored(Machine $machine, string $app): array
    {
        $site = (array) ($machine->app_settings[$app]['site'] ?? []);
        $redirects = [];
        foreach ((array) ($site['redirects'] ?? []) as $redirect) {
            if (is_array($redirect) && is_string($redirect['from'] ?? null) && is_string($redirect['to'] ?? null)) {
                $redirects[] = ['from' => $redirect['from'], 'to' => $redirect['to'], 'permanent' => (bool) ($redirect['permanent'] ?? true)];
            }
        }

        return [
            'maintenance' => (bool) ($site['maintenance'] ?? false),
            'maintenance_html' => is_string($site['maintenance_html'] ?? null) && $site['maintenance_html'] !== '' ? $site['maintenance_html'] : null,
            'user' => is_string($site['user'] ?? null) ? $site['user'] : null,
            'hash' => is_string($site['hash'] ?? null) ? $site['hash'] : null,
            'www_redirect' => (bool) ($site['www_redirect'] ?? false),
            'redirects' => $redirects,
        ];
    }

    /**
     * What the app's page and MCP see: never the hash.
     *
     * @param  array<mixed>  $domains  the app's domains, as its last deploy had them
     * @return array{maintenance: bool, customPage: bool, password: bool, user: string|null, wwwRedirect: bool, wwwName: string|null, domain: string|null, redirects: list<array{from: string, to: string, permanent: bool}>, supported: bool, job: array{id: string, state: string, error: string|null}|null}
     */
    public static function props(Machine $machine, string $app, array $domains): array
    {
        $stored = self::stored($machine, $app);
        $domain = DeployRequest::plainDomains(array_values(array_filter($domains, 'is_string')))[0] ?? null;
        $job = null;
        $jobId = $machine->app_settings[$app]['site_job'] ?? null;
        if (is_string($jobId) && ($record = DaemonJob::query()->where('machine_id', $machine->id)->find($jobId)) !== null) {
            $job = [
                'id' => $record->id,
                'state' => $record->isStale() ? 'stale' : $record->state,
                'error' => is_array($record->error) ? (string) ($record->error['message'] ?? '') : null,
            ];
        }

        return [
            'maintenance' => $stored['maintenance'],
            'customPage' => $stored['maintenance_html'] !== null,
            'password' => $stored['hash'] !== null,
            'user' => $stored['hash'] !== null ? $stored['user'] : null,
            'wwwRedirect' => $stored['www_redirect'],
            'wwwName' => $domain === null ? null : self::counterpart($domain),
            'domain' => $domain,
            'redirects' => $stored['redirects'],
            'supported' => DaemonJobQueue::canSetSiteOptions($machine),
            'job' => $job,
        ];
    }

    /** The name a www redirect sends to the domain: www.<domain>, or the bare name for a www domain. */
    public static function counterpart(string $domain): ?string
    {
        if (str_starts_with($domain, '*.')) {
            return null;
        }
        if (str_starts_with($domain, 'www.')) {
            $bare = substr($domain, 4);

            return str_contains($bare, '.') ? $bare : null;
        }

        return 'www.'.$domain;
    }

    /** @return list<string> the fields a change can have */
    public static function fields(): array
    {
        return array_values(array_filter(array_keys(self::rules()), fn (string $key) => ! str_contains($key, '.')));
    }

    /** @return array<string, list<mixed>> */
    public static function rules(): array
    {
        return [
            'maintenance' => ['sometimes', 'boolean'],
            'maintenance_page' => ['sometimes', 'nullable', 'string', 'max:'.self::MAX_PAGE_BYTES],
            'password_protect' => ['sometimes', 'boolean'],
            'username' => ['sometimes', 'nullable', 'string', 'regex:'.self::USER_PATTERN],
            'password' => ['sometimes', 'nullable', 'string', 'min:8', 'max:200'],
            'www_redirect' => ['sometimes', 'boolean'],
            'redirects' => ['sometimes', 'array', 'max:'.self::MAX_REDIRECTS],
            'redirects.*.from' => ['required', 'string', 'regex:'.self::FROM_PATTERN, 'distinct'],
            'redirects.*.to' => ['required', 'string', 'max:2000', 'regex:'.self::TO_PATTERN],
            'redirects.*.permanent' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public static function messages(): array
    {
        return [
            'username.regex' => 'Use letters, numbers and . _ - @ in the username.',
            'redirects.*.from.regex' => 'Redirect an exact path, like /old-page: letters, numbers and - . _ ~ / only.',
            'redirects.*.from.distinct' => 'That path is redirected twice.',
            'redirects.*.to.regex' => 'Send it to a path like /new-page or a full https:// address, without spaces, quotes, %, $ or #.',
            'maintenance_page.max' => 'The maintenance page can be up to 256 KB.',
        ];
    }

    /**
     * Applies a change (only the keys given) and sends the result to the
     * machine. Returns the queued job.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws ValidationException when the input doesn't make sense
     * @throws RuntimeException when the machine can't take it now
     */
    public function update(Machine $machine, string $app, User $user, array $input): DaemonJob
    {
        $input = Validator::make($input, self::rules(), self::messages())->validate();

        $last = AppVersionController::lastDeployOf($machine, $app);
        if ($last === null) {
            throw new RuntimeException('This service wasn’t deployed from code.');
        }
        $domains = array_values(array_filter((array) ($last->command['job']['domains'] ?? []), 'is_string'));
        if (DeployRequest::plainDomains($domains) === []) {
            throw ValidationException::withMessages(['domain' => 'Give the app a domain first.']);
        }
        if (! DaemonJobQueue::canSetSiteOptions($machine)) {
            throw new RuntimeException($machine->name.'’s daemon is too old for these. It updates itself, or run “sudo serverosd update” on the server.');
        }
        if (($reason = $this->queue->unavailable($machine)) !== null) {
            throw new RuntimeException($reason);
        }
        if (self::changing($machine, $app)) {
            throw new RuntimeException('The last change to this site is still being applied. Try again in a moment.');
        }

        $before = self::stored($machine, $app);
        $next = $before;
        if (array_key_exists('maintenance', $input)) {
            $next['maintenance'] = (bool) $input['maintenance'];
        }
        if (array_key_exists('maintenance_page', $input)) {
            $html = is_string($input['maintenance_page']) ? $input['maintenance_page'] : '';
            $next['maintenance_html'] = trim($html) === '' ? null : $html;
        }
        if (array_key_exists('www_redirect', $input)) {
            $next['www_redirect'] = (bool) $input['www_redirect'];
        }
        if (array_key_exists('redirects', $input)) {
            $next['redirects'] = array_values(array_map(fn (array $r) => [
                'from' => (string) $r['from'],
                'to' => (string) $r['to'],
                'permanent' => (bool) ($r['permanent'] ?? true),
            ], (array) $input['redirects']));
        }

        $protect = array_key_exists('password_protect', $input) ? (bool) $input['password_protect'] : $before['hash'] !== null;
        $password = is_string($input['password'] ?? null) && $input['password'] !== '' ? $input['password'] : null;
        $username = is_string($input['username'] ?? null) && $input['username'] !== '' ? $input['username'] : null;
        if (! $protect) {
            $next['user'] = null;
            $next['hash'] = null;
        } else {
            $next['user'] = $username ?? $before['user'] ?? 'admin';
            if ($password !== null) {
                $next['hash'] = password_hash($password, PASSWORD_BCRYPT);
            } elseif ($before['hash'] === null) {
                throw ValidationException::withMessages(['password' => 'Choose a password of at least 8 characters.']);
            }
        }

        if ($next === $before) {
            throw ValidationException::withMessages(['site' => 'Nothing changed.']);
        }

        $record = $this->queue->queue($machine, $user, self::KIND, self::job($app, $next), $app, 120);

        $settings = $machine->app_settings ?? [];
        $settings[$app] = [...($settings[$app] ?? []), 'site' => $next, 'site_job' => $record->id];
        $machine->forceFill(['app_settings' => $settings])->save();

        Activity::record($machine->workspace_id, 'app.site', $app, $machine, $user, self::changes($before, $next));

        return $record;
    }

    /** Whether a change to this site is still on its way. */
    public static function changing(Machine $machine, string $app): bool
    {
        $jobId = $machine->app_settings[$app]['site_job'] ?? null;
        if (! is_string($jobId)) {
            return false;
        }
        $job = DaemonJob::query()->where('machine_id', $machine->id)->find($jobId);

        return $job !== null && $job->isActive() && ! $job->isStale();
    }

    /**
     * The daemon's job for a set of switches.
     *
     * @param  array{maintenance: bool, maintenance_html: string|null, user: string|null, hash: string|null, www_redirect: bool, redirects: list<array{from: string, to: string, permanent: bool}>}  $stored
     * @return array<string, mixed>
     */
    public static function job(string $app, array $stored): array
    {
        $options = [
            'maintenance' => $stored['maintenance'],
            'www_redirect' => $stored['www_redirect'],
        ];
        if ($stored['maintenance_html'] !== null) {
            $options['maintenance_html'] = $stored['maintenance_html'];
        }
        if ($stored['hash'] !== null && $stored['user'] !== null) {
            $options['basic_auth'] = ['user' => $stored['user'], 'hash' => $stored['hash']];
        }
        if ($stored['redirects'] !== []) {
            $options['redirects'] = $stored['redirects'];
        }

        return ['type' => self::KIND, 'service' => $app, 'options' => $options];
    }

    /**
     * What changed, for the activity line: never the password or its hash.
     *
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $next
     * @return array<string, mixed>
     */
    private static function changes(array $before, array $next): array
    {
        $changes = [];
        if ($before['maintenance'] !== $next['maintenance']) {
            $changes['maintenance'] = $next['maintenance'];
        }
        if ($before['maintenance_html'] !== $next['maintenance_html']) {
            $changes['page'] = $next['maintenance_html'] !== null;
        }
        if (($before['hash'] !== null) !== ($next['hash'] !== null)) {
            $changes['password'] = $next['hash'] !== null;
        } elseif ($before['hash'] !== $next['hash'] || $before['user'] !== $next['user']) {
            $changes['password_changed'] = true;
        }
        if ($before['www_redirect'] !== $next['www_redirect']) {
            $changes['www'] = $next['www_redirect'];
        }
        if ($before['redirects'] !== $next['redirects']) {
            $changes['redirects'] = count($next['redirects']);
        }

        return $changes;
    }
}
