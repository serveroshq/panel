<?php

namespace App\Support\Integrations\GitLab;

use App\Models\GitLabProjectHook;
use App\Models\Integration;
use App\Support\Integrations\Capability;
use App\Support\Integrations\ConnectedAccount;
use App\Support\Integrations\ConnectField;
use App\Support\Integrations\HostsRepositories;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;

final class GitLabProvider extends Provider implements HostsRepositories
{
    private const array READS_CODE = ['read_repository', 'write_repository', 'api'];

    public function key(): string
    {
        return GitLabRepositories::PROVIDER;
    }

    public function name(): string
    {
        return 'GitLab';
    }

    public function fields(): array
    {
        return [
            new ConnectField(
                key: 'token',
                label: self::t('Access token'),
                help: self::t('A personal, group or project access token with the read_api and read_repository scopes. To deploy each push, give it api instead of read_api, from an account that’s a Maintainer of the project.'),
                link: 'https://gitlab.com/-/user_settings/personal_access_tokens?name=ServerOS&scopes=read_api,read_repository',
                linkLabel: self::t('Make one on gitlab.com'),
                placeholder: self::t('Paste the token'),
                rules: ['required', 'string', 'min:20', 'max:200', 'regex:/^\S+$/'],
            ),
            new ConnectField(
                key: 'url',
                label: self::t('GitLab address'),
                secret: false,
                help: self::t('Keep gitlab.com, or use the address of your own GitLab.'),
                placeholder: GitLabClient::GITLAB_COM,
                rules: ['nullable', 'string', 'max:200'],
                value: GitLabClient::GITLAB_COM,
            ),
        ];
    }

    public function capabilities(): array
    {
        return [Capability::Repositories, Capability::PushDeploys];
    }

    public function verify(array $credentials): ConnectedAccount
    {
        $instance = GitLabClient::normalise($credentials['url'] ?? null)
            ?? throw new IntegrationFailed(self::t('That isn’t a GitLab address. Use the https:// address you open GitLab at, like https://gitlab.example.com.'));
        $client = new GitLabClient($instance, (string) ($credentials['token'] ?? ''));

        $user = $client->get('user');
        $username = $user['username'] ?? null;
        if (! is_string($username) || $username === '') {
            throw new IntegrationFailed(self::t('That address answered, but not the way GitLab does. Check it’s your GitLab’s address.'));
        }

        $scopes = null;
        try {
            $self = $client->get('personal_access_tokens/self');
            $scopes = array_values(array_filter((array) ($self['scopes'] ?? []), 'is_string'));
        } catch (IntegrationFailed $e) {
            if ($e->refusedCredentials()) {
                throw $e;
            }
        }
        if ($scopes !== null && array_intersect($scopes, self::READS_CODE) === []) {
            throw new IntegrationFailed(self::t('This token can’t read code. Make one with the read_api and read_repository scopes.'), IntegrationFailed::PERMISSION);
        }

        $host = GitLabClient::host($instance);

        return new ConnectedAccount(
            $instance === GitLabClient::GITLAB_COM ? $username : self::t(':user on :host', ['user' => $username, 'host' => $host]),
            [
                'username' => $username,
                'instance' => $instance,
                'scopes' => $scopes === null ? null : implode(', ', $scopes),
                'hooks' => $scopes === null ? null : in_array('api', $scopes, true),
            ],
        );
    }

    public function summary(Integration $integration): string
    {
        $username = (string) ($integration->meta['username'] ?? $integration->label);

        return self::t('@:user on :host', ['user' => $username, 'host' => GitLabClient::host(GitLabRepositories::instanceOf($integration))]);
    }

    public function facts(Integration $integration): array
    {
        $meta = $integration->meta ?? [];
        $facts = [
            ['label' => self::t('Signed in as'), 'value' => '@'.($meta['username'] ?? '')],
            ['label' => self::t('Address'), 'value' => GitLabClient::host(GitLabRepositories::instanceOf($integration))],
        ];
        if (is_string($meta['scopes'] ?? null)) {
            $facts[] = ['label' => self::t('Scopes'), 'value' => $meta['scopes']];
        }

        return $facts;
    }

    public function projects(Integration $integration): array
    {
        return app(GitLabHooks::class)->projects($integration);
    }

    public function usage(Integration $integration): array
    {
        $apps = app(GitLabHooks::class)->apps($integration->workspace, $integration);
        $hooks = GitLabProjectHook::query()->where('integration_id', $integration->id)->count();
        $out = [];
        $out[] = match (count($apps)) {
            0 => self::t('Its projects are listed when you deploy your own code.'),
            1 => self::t('1 app is deployed from a project here.'),
            default => self::t(':count apps are deployed from projects here.', ['count' => count($apps)]),
        };
        if ($hooks > 0) {
            $out[] = $hooks === 1
                ? self::t('1 project has a ServerOS webhook, so its pushes deploy.')
                : self::t(':count projects have a ServerOS webhook, so their pushes deploy.', ['count' => $hooks]);
        }

        return $out;
    }

    public function dependents(Integration $integration): array
    {
        $apps = app(GitLabHooks::class)->apps($integration->workspace, $integration);
        $pushing = count(array_filter($apps, fn (array $app) => $app['push']['on']));
        $out = [];
        if ($apps !== []) {
            $out[] = count($apps) === 1
                ? self::t('1 app can’t deploy a private project or show commit messages from here any more.')
                : self::t(':count apps can’t deploy private projects or show commit messages from here any more.', ['count' => count($apps)]);
        }
        if ($pushing > 0) {
            $out[] = $pushing === 1
                ? self::t('1 app stops deploying each push, and ServerOS takes its webhook off GitLab.')
                : self::t(':count apps stop deploying each push, and ServerOS takes their webhooks off GitLab.', ['count' => $pushing]);
        }

        return $out;
    }

    public function disconnect(Integration $integration): ?string
    {
        $removed = app(GitLabHooks::class)->removeAll($integration);
        GitLabRepositories::forget($integration);

        return $removed === 0 ? null : ($removed === 1
            ? self::t('Disconnected :name and took ServerOS’s webhook off 1 project.', ['name' => $integration->label])
            : self::t('Disconnected :name and took ServerOS’s webhooks off :count projects.', ['name' => $integration->label, 'count' => $removed]));
    }
}
