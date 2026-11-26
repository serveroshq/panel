<?php

namespace App\Mcp\Tools\Deploy;

use App\Http\Controllers\AppVersionController;
use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Support\Deploys\SiteSwitches;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class UpdateSiteSettings extends Tool
{
    public function __construct(private readonly SiteSwitches $switches) {}

    public function name(): string
    {
        return 'update_site_settings';
    }

    public function title(): string
    {
        return 'Change an app’s site switches';
    }

    public function description(): string
    {
        return 'Changes the switches on the site of an app ServerOS built from code, as the Site tile on its page does. They take effect in a few seconds with no redeploy, and stay through later deploys. '
            .'maintenance shows visitors a “back soon” page (503) while the app keeps running; maintenance_page replaces it with HTML of your own (empty puts the default back). '
            .'password_protect puts a username and password in front of the whole site (HTTP basic auth): send password (8 or more characters) to set or change it, username too (default admin). The password is stored hashed and never shown again; ask the person for it, don’t make one up. '
            .'www_redirect also answers on the domain’s www name (or its bare name, for a www domain) and sends visitors to the domain, getting HTTPS for it once its DNS points at the machine. '
            .'redirects replaces the list of path redirects: exact paths like /old-page sent to a path or a full https:// address, permanent (301, the default) or not (302). Send [] to remove them all. '
            .'Leave out anything that shouldn’t change. Check with the person before turning on maintenance or a password on a live site. Returns the queued job; follow it with get_job.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'app' => Schema::string('The app: the name it was deployed as (like shop), or app:<name>.', maxLength: 300),
            'maintenance' => Schema::boolean('Show the maintenance page instead of the app.'),
            'maintenance_page' => Schema::string('HTML for the maintenance page, up to 256 KB. Empty puts back the plain default.', maxLength: SiteSwitches::MAX_PAGE_BYTES),
            'password_protect' => Schema::boolean('Ask visitors for a username and password.'),
            'username' => Schema::string('The username, letters, numbers and . _ - @ (default admin).', maxLength: 64),
            'password' => Schema::string('The password, 8 or more characters. Needed the first time password_protect is turned on.', maxLength: 200),
            'www_redirect' => Schema::boolean('Also answer on the domain’s www name (or bare name) and redirect it to the domain.'),
            'redirects' => Schema::array(Schema::object([
                'from' => Schema::string('An exact path on the site, like /old-pricing.', maxLength: 500),
                'to' => Schema::string('A path on the site, like /pricing, or a full https:// address.', maxLength: 2000),
                'permanent' => Schema::boolean('301 when true (the default), 302 when false.'),
            ], ['from', 'to']), 'Every path redirect the site should have, replacing the current list.', maxItems: SiteSwitches::MAX_REDIRECTS),
            'wait' => Deploys::waitSchema(),
        ], ['machine', 'app']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $args = $this->validate($arguments, [
            'machine' => ['required', Deploys::reference()],
            'app' => ['required', Deploys::reference()],
            'wait' => Deploys::waitRules(),
        ]);
        $machine = $context->machine($args['machine']);
        $app = Deploys::appName($context, $machine, $args['app']);
        $last = AppVersionController::lastDeployOf($machine, $app);
        if ($last === null) {
            throw new ToolError("{$app} wasn’t deployed from code, so it has no site switches.");
        }

        $input = array_intersect_key($arguments, array_flip(SiteSwitches::fields()));
        if ($input === []) {
            throw new ToolError('Say what to change: maintenance, maintenance_page, password_protect, password, www_redirect or redirects.');
        }

        try {
            $job = $this->switches->update($machine, $app, $context->user, $input);
        } catch (ValidationException $e) {
            throw new ToolError(implode(' ', array_map(fn (array $m) => (string) $m[0], $e->errors())));
        } catch (RuntimeException $e) {
            throw new ToolError($e->getMessage());
        }
        $job = $context->waitForJob($job, (int) ($args['wait'] ?? 0));
        $machine->refresh();
        $site = SiteSwitches::props($machine, $app, (array) ($last->command['job']['domains'] ?? []));

        return [
            'jobId' => $job->id,
            'state' => $job->state,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'app' => $app,
            'site' => array_diff_key($site, ['job' => true, 'supported' => true]),
            'job' => Present::job($job, 20),
            'next' => 'The site changes in a few seconds, without a redeploy. Follow it with get_job (id '.$job->id.').',
        ];
    }
}
