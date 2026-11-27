<?php

namespace App\Support\Deploys;

use App\Models\AppDeployHook;
use App\Models\Machine;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * An app's secret deploy address. A POST to it deploys the app: a plain
 * one (optionally naming a commit or branch), or a push from GitHub,
 * GitLab, Gitea, Forgejo or Bitbucket, where only the app's own branch
 * deploys. Pushes get the same waiting and only-the-newest rules as
 * GitHub pushes do.
 */
final class DeployHooks
{
    public const string TOKEN_PATTERN = 'dh_[A-Za-z0-9]{40}';

    public const string GENERIC = 'hook';

    public const array PROVIDERS = ['github', 'gitlab', 'gitea', 'bitbucket'];

    public const array TRIGGERS = [self::GENERIC, 'hook:github', 'hook:gitlab', 'hook:gitea', 'hook:bitbucket'];

    public const int PER_MINUTE = 10;

    private const string ZERO = '0000000000000000000000000000000000000000';

    public function __construct(private readonly DeployOnPush $pushes, private readonly AppCommits $commits) {}

    /** @return array{hook: AppDeployHook, url: string} a new address; any older one stops working */
    public static function issue(Machine $machine, string $app, User $user): array
    {
        $token = 'dh_'.Str::random(40);
        $hook = AppDeployHook::query()->updateOrCreate(
            ['machine_id' => $machine->id, 'service' => $app],
            ['token_hash' => hash('sha256', $token), 'hint' => substr($token, -4), 'user_id' => $user->id, 'last_used_at' => null, 'last_trigger' => null],
        );

        return ['hook' => $hook, 'url' => self::url($token)];
    }

    public static function url(string $token): string
    {
        return route('deploy-hooks.receive', ['token' => $token]);
    }

    public static function find(string $token): ?AppDeployHook
    {
        return AppDeployHook::query()->with('machine.workspace')->where('token_hash', hash('sha256', $token))->first();
    }

    public static function of(Machine $machine, string $app): ?AppDeployHook
    {
        return AppDeployHook::query()->where('machine_id', $machine->id)->where('service', $app)->first();
    }

    /** @return array{exists: bool, hint: string|null, createdAt: string|null, lastUsedAt: string|null, lastTrigger: string|null, by: string|null} */
    public static function props(Machine $machine, string $app): array
    {
        $hook = self::of($machine, $app);

        return [
            'exists' => $hook !== null,
            'hint' => $hook === null ? null : '…'.$hook->hint,
            'createdAt' => $hook?->created_at?->toIso8601String(),
            'lastUsedAt' => $hook?->last_used_at?->toIso8601String(),
            'lastTrigger' => $hook?->last_trigger,
            'by' => $hook?->user?->name,
        ];
    }

    /**
     * What a request to the address asks for: which provider sent it, and
     * the branches it pushed with their commits.
     *
     * @return array{provider: string, pushes: array<string, string>|null, commit: string|null, branch: string|null, ignore: string|null}
     */
    public static function read(Request $request): array
    {
        $header = fn (string $name): string => strtolower(trim((string) $request->header($name)));
        $data = $request->isJson() ? (array) $request->json()->all() : $request->all();
        $result = ['provider' => self::GENERIC, 'pushes' => null, 'commit' => null, 'branch' => null, 'ignore' => null];

        // Gitea and Forgejo send GitHub's header too, so they go first.
        $gitea = $header('X-Forgejo-Event') ?: $header('X-Gitea-Event') ?: $header('X-Gogs-Event');
        if ($gitea !== '' || $header('X-GitHub-Event') !== '') {
            $result['provider'] = $gitea !== '' ? 'gitea' : 'github';
            $event = $gitea !== '' ? $gitea : $header('X-GitHub-Event');
            if ($event !== 'push') {
                return [...$result, 'ignore' => $event === 'ping' ? 'ping' : 'not a push'];
            }

            return [...$result, ...self::refPush((string) ($data['ref'] ?? ''), (string) ($data['after'] ?? ''), (bool) ($data['deleted'] ?? false))];
        }

        $gitlab = $header('X-Gitlab-Event');
        if ($gitlab !== '') {
            $result['provider'] = 'gitlab';
            if (($data['object_kind'] ?? null) !== 'push') {
                return [...$result, 'ignore' => 'not a push'];
            }

            return [...$result, ...self::refPush((string) ($data['ref'] ?? ''), (string) ($data['after'] ?? $data['checkout_sha'] ?? ''), false)];
        }

        $bitbucket = $header('X-Event-Key');
        if ($bitbucket !== '') {
            $result['provider'] = 'bitbucket';
            if ($bitbucket !== 'repo:push') {
                return [...$result, 'ignore' => 'not a push'];
            }
            $pushes = [];
            foreach (is_array($data['push']['changes'] ?? null) ? $data['push']['changes'] : [] as $change) {
                $new = is_array($change) && is_array($change['new'] ?? null) ? $change['new'] : null;
                $hash = is_array($new['target'] ?? null) ? ($new['target']['hash'] ?? null) : null;
                if ($new !== null && ($new['type'] ?? null) === 'branch' && is_string($new['name'] ?? null) && is_string($hash) && preg_match(DeployRequest::COMMIT_PATTERN, $hash) === 1) {
                    $pushes[$new['name']] = strtolower($hash);
                }
            }

            return $pushes === [] ? [...$result, 'ignore' => 'no branch was pushed'] : [...$result, 'pushes' => $pushes];
        }

        $commit = $data['commit'] ?? $data['ref'] ?? null;
        $branch = $data['branch'] ?? null;

        return [
            ...$result,
            'commit' => is_string($commit) && trim($commit) !== '' ? trim($commit) : null,
            'branch' => is_string($branch) && trim($branch) !== '' ? trim($branch) : null,
        ];
    }

    /** @return array{pushes: array<string, string>|null, ignore: string|null} */
    private static function refPush(string $ref, string $after, bool $deleted): array
    {
        if (! str_starts_with($ref, 'refs/heads/')) {
            return ['pushes' => null, 'ignore' => 'not a branch'];
        }
        if ($deleted || $after === self::ZERO || preg_match(DeployRequest::COMMIT_PATTERN, $after) !== 1) {
            return ['pushes' => null, 'ignore' => 'the branch was deleted'];
        }

        return ['pushes' => [substr($ref, strlen('refs/heads/')) => strtolower($after)], 'ignore' => null];
    }

    /**
     * Deploys the hook's app for what the request asks.
     *
     * @return array{status: int, body: array<string, mixed>}
     */
    public function handle(AppDeployHook $hook, Request $request): array
    {
        $machine = $hook->machine;
        $app = $hook->service;
        $asked = self::read($request);
        $trigger = $asked['provider'] === self::GENERIC ? self::GENERIC : 'hook:'.$asked['provider'];
        $ignored = fn (string $why): array => ['status' => 200, 'body' => ['ignored' => true, 'reason' => $why]];

        if ($asked['ignore'] !== null) {
            return $ignored($asked['ignore']);
        }
        $spec = AppDeploys::spec($machine, $app);
        if ($spec === null) {
            return ['status' => 404, 'body' => ['error' => "{$app} isn’t on {$machine->name} any more."]];
        }
        if (str_contains((string) ($spec['repo'] ?? ''), '/api/deploy-sources/')) {
            return ['status' => 409, 'body' => ['error' => "{$app}’s code was uploaded as a .zip, so there’s nothing for a hook to fetch. Upload a new version on its page."]];
        }

        $branch = DeployOnPush::setting($machine, $app)['branch'];
        $user = $this->actor($hook);
        if ($user === null) {
            return ['status' => 409, 'body' => ['error' => 'No one in the workspace can deploy it any more.']];
        }

        $image = AppDeploys::isImage($spec);
        if ($asked['pushes'] !== null) {
            if (! isset($asked['pushes'][$branch])) {
                return $ignored("only pushes to {$branch} deploy {$app}");
            }
            $commit = $asked['pushes'][$branch];
        } elseif ($image) {
            $commit = (string) $spec['commit'];
        } else {
            if ($asked['branch'] !== null && $asked['commit'] === null && $asked['branch'] !== $branch) {
                return $ignored("only {$branch} deploys {$app}");
            }
            try {
                $commit = $this->commits->resolve($machine, $user, $app, $spec, $asked['commit'] ?? $branch)['commit'];
            } catch (RuntimeException $e) {
                return ['status' => 422, 'body' => ['error' => $e->getMessage()]];
            }
        }

        $hook->forceFill(['last_used_at' => now(), 'last_trigger' => $trigger])->save();
        $outcome = $this->pushes->deployCommit($machine, $app, $commit, $branch, $trigger, $user, again: $image);
        if (! $outcome['started']) {
            return $outcome['reason'] === null
                ? $ignored('that commit is already deploying or live')
                : ['status' => 409, 'body' => ['error' => ucfirst(rtrim($outcome['reason'], '.')).'.']];
        }
        $job = $outcome['job'];

        return ['status' => 202, 'body' => [
            'deployed' => true,
            'jobId' => $job?->id,
            'app' => $app,
            'commit' => $image ? null : $commit,
            'waiting' => (bool) $job?->held,
        ]];
    }

    // Who made the address, while they can still manage the workspace's
    // machines; its owner otherwise.
    private function actor(AppDeployHook $hook): ?User
    {
        $workspace = $hook->machine->workspace;
        $user = $hook->user;
        if ($user !== null && $workspace->members()->whereKey($user->id)->exists() && $user->can('manageMachines', $workspace)) {
            return $user;
        }

        return $workspace->owner;
    }
}
