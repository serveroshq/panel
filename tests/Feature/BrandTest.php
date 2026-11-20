<?php

use App\Models\User;
use App\Notifications\MonitorAlert;
use App\Support\Workflows\AgentSpec;
use Inertia\Testing\AssertableInertia as Assert;

function brandFooter(): string
{
    $mail = (new MonitorAlert('api is down', ['It stopped answering.'], 'https://example.com/monitors/1', true))->toMail(User::factory()->make());

    return view($mail->view['html'], $mail->data())->render();
}

function unbranded(): void
{
    config()->set('serveros.brand', array_fill_keys(['company', 'address', 'website', 'terms_url', 'privacy_url', 'agent_email'], null) + ['source_url' => 'https://github.com/serveroshq/panel']);
    config()->set('app.name', 'ServerOS');
    config()->set('app.url', 'https://panel.example.com');
    config()->set('app.domain', 'panel.example.com');
    config()->set('serveros.mail_links', null);
    config()->set('serveros.mail_socials', null);
}

test('an install that names nobody shows no company or terms, and signs its emails with its own name and address', function () {
    unbranded();

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->where('brand', ['company' => null, 'terms' => null, 'privacy' => null, 'source' => 'https://github.com/serveroshq/panel']));

    expect(brandFooter())->toContain('<a href="https://panel.example.com" style="color:#8A8A84; text-decoration:underline;">panel.example.com</a>')->toContain('&copy; '.now()->year.' ServerOS</p>')
        ->not->toContain('ServerOS Cloud Ltd')
        ->not->toContain('serveros.com');

    expect(AgentSpec::script())->toContain('user.email="${SERVEROS_AGENT_AUTHOR_EMAIL:-agent@panel.example.com}"')
        ->not->toContain('agent@serveros.com');
});

test('a company, its address and its terms show where serveros.com’s always did', function () {
    unbranded();
    config()->set('serveros.brand', [
        'company' => 'Example Hosting Ltd', 'address' => '1 Example Street, London', 'website' => 'example.com',
        'terms_url' => '/terms', 'privacy_url' => 'https://example.com/privacy', 'agent_email' => 'bot@example.com', 'source_url' => null,
    ]);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->where('brand', ['company' => 'Example Hosting Ltd', 'terms' => '/terms', 'privacy' => 'https://example.com/privacy', 'source' => null]));

    expect(brandFooter())->toContain('<a href="https://panel.example.com" style="color:#8A8A84; text-decoration:underline;">example.com</a>')->toContain('&copy; '.now()->year.' Example Hosting Ltd · 1 Example Street, London');

    expect(AgentSpec::script())->toContain('${SERVEROS_AGENT_AUTHOR_EMAIL:-bot@example.com}');
});

test('an agent email that could break out of the script is never used', function () {
    unbranded();
    config()->set('serveros.brand.agent_email', 'x"; rm -rf / #@example.com');

    expect(AgentSpec::script())->toContain('${SERVEROS_AGENT_AUTHOR_EMAIL:-agent@localhost}');
});
