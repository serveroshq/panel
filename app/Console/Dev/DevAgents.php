<?php

namespace App\Console\Dev;

use App\Models\AgentTask;
use App\Models\DaemonJob;
use App\Models\Machine;
use App\Models\ProjectEnvironment;
use App\Models\ProjectNode;
use App\Models\WorkflowRun;
use App\Models\Workspace;
use App\Support\Agents\AgentContext;
use App\Support\Agents\AgentTasks;
use App\Support\Workflows\AgentSpec;
use App\Support\Workflows\WorkflowSteps;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Local only: fills the Agents page with made-up tasks in every state, so
 * its screens can be worked on without a machine running agents.
 */
class DevAgents extends Command
{
    protected $signature = 'dev:agents
        {workspace=serveros-hq : The slug of the workspace to fill}
        {--clear : Remove the demo tasks instead}';

    protected $description = 'Add or remove demo agent tasks on a local workspace';

    private const MARK = '[demo] ';

    private const DEMO_JOB = 'dev-agents-demo';

    private const DEMO_PROJECT = 'Shop (demo)';

    public function handle(): int
    {
        $workspace = Workspace::query()->where('slug', $this->argument('workspace'))->first();
        if ($workspace === null) {
            $this->error('No workspace with that slug.');

            return self::FAILURE;
        }

        $old = AgentTask::query()->where('workspace_id', $workspace->id)->whereHas('workflow', fn ($q) => $q->where('description', 'like', self::MARK.'%'))->get();
        foreach ($old as $task) {
            $workflow = $task->workflow;
            DaemonJob::query()->whereIn('workflow_run_id', $task->runs()->pluck('id'))->delete();
            $task->delete();
            $workflow->delete();
        }
        DaemonJob::query()->whereIn('machine_id', $workspace->machines()->select('id'))->where('workflow_node', self::DEMO_JOB)->delete();
        $workspace->projects()->where('name', self::DEMO_PROJECT)->get()->each->delete();
        if ($this->option('clear')) {
            $this->info("Removed {$old->count()} demo tasks.");

            return self::SUCCESS;
        }

        /** @var Machine|null $machine */
        $machine = $workspace->machines()->orderBy('id')->first();
        $user = $workspace->owner;
        if ($machine === null || $user === null) {
            $this->error('The workspace needs a machine and an owner.');

            return self::FAILURE;
        }

        // acme/shop already runs on the machine, so a merged change can deploy there,
        // and a project builds a preview of every pull request.
        $this->job($machine, $user, 'deploy', 'shop', 'succeeded', ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'branch' => 'main', 'commit' => str_repeat('c', 40)], [], 3 * 86400);
        $project = $workspace->projects()->create(['name' => self::DEMO_PROJECT]);
        $project->forceFill(['previews' => ['enabled' => true, 'repo' => 'acme/shop', 'machine_id' => $machine->id, 'domain' => 'preview.acme.test', 'limit' => 3, 'user_id' => $user->id]])->save();
        $production = $project->production();
        (new ProjectNode)->forceFill(['project_id' => $project->id, 'environment_id' => $production->id, 'machine_id' => $machine->id, 'name' => 'shop', 'kind' => 'app'])->save();
        (new ProjectEnvironment)->forceFill(['project_id' => $project->id, 'name' => 'PR #412', 'position' => 1, 'source_id' => $production->id, 'pull_request' => 412])->save();

        $summary = "The total was computed before the coupon's percentage was applied, so the discount came off the pre-tax amount.\n\n- Moved the discount into `Cart::subtotal()` so tax is worked out on the discounted price.\n- Added a test for a 20% coupon on a taxed cart.\n\nCheck the receipt email: it reads the old `discount_total` field, which still exists.";
        $other = "Applied the coupon in `CheckoutController` instead of the cart.\n\n- Smaller change, but the cart page still shows the old total until checkout.\n- Added one test.";

        [$coupon, $runs] = $this->task($workspace, $machine, 'Coupon total is wrong at checkout', 'The checkout page shows the wrong total when a coupon is applied. Find out why and fix it. Add a test.', 'acme/shop', [
            ['succeeded', 'https://github.com/acme/shop/pull/412', $summary, '+48 -12, 3 files', 38 * 60, 31 * 60],
            ['succeeded', 'https://github.com/acme/shop/pull/413', $other, '+19 -4, 2 files', 37 * 60, 33 * 60],
            ['failed', null, '', null, 36 * 60, 20 * 60, 'The check failed after the agent\'s change, so no pull request was opened.'],
            ['succeeded', 'https://github.com/acme/shop/pull/415', "Kept `discount_total` and now fill it from the cart, so the receipt email shows the right discount again.\n\n- `Order::fromCart()` copies the cart's discount into `discount_total`.\n- Extended the coupon test to check the receipt.", '+9 -2, 2 files', 15 * 60, 11 * 60, null, ['notes' => 'Keep the old discount_total field working for the receipt email, and check the receipt in the test.', 'after' => 0]],
        ], $user);
        // The follow-up was merged and is live.
        $deploy = $this->job($machine, $user, 'deploy', 'shop', 'succeeded', ['type' => 'deploy', 'service' => 'shop', 'repo' => 'https://github.com/acme/shop', 'branch' => 'main', 'commit' => str_repeat('d', 40)], ['Building shop', 'Starting shop', 'shop is answering'], 6 * 60);
        $last = WorkflowRun::query()->findOrFail($runs[3]);
        $last->forceFill(['steps' => [...$last->steps, AgentTasks::NODE => [...$last->steps[AgentTasks::NODE], 'merged' => ['sha' => str_repeat('d', 40), 'at' => now()->subMinutes(8)->getTimestamp(), 'by' => $user->name], 'deployJob' => $deploy->id]]])->save();
        foreach (['412' => 'open', '413' => 'open', '415' => 'merged'] as $number => $state) {
            Cache::forever(AgentTasks::pullKey($workspace->id, "https://github.com/acme/shop/pull/{$number}"), ['state' => $state, 'merged' => $state === 'merged', 'mergeSha' => $state === 'merged' ? str_repeat('d', 40) : null, 'headSha' => str_repeat('e', 40)]);
        }

        $this->task($workspace, $machine, 'Update dependencies', 'Update dependencies with patch and minor releases only, fix anything that breaks, and keep the change small.', 'acme/api', [
            ['running', null, '', null, 4 * 60, null],
        ], $user);

        // Two working, one waiting for room on the machine.
        [$billing] = $this->task($workspace, $machine, 'Add tests for the billing module', 'Add tests for app/Billing, which has none. Start with the parts most likely to break.', 'acme/api', [
            ['running', null, '', null, 9 * 60, null],
            ['running', null, '', null, 9 * 60, null],
        ], $user);
        $billing->queued()->create(['user_id' => $user->id, 'turn' => 'first', 'quiet' => false]);
        $billing->forceFill(['agents' => 3])->save();

        // Started from a failed deploy, with its log.
        $failed = $this->job($machine, $user, 'deploy', 'api', 'failed', ['type' => 'deploy', 'service' => 'api', 'repo' => 'https://github.com/acme/api', 'branch' => 'main', 'commit' => str_repeat('f', 40)], ['npm ci', 'npm run build', "src/billing/invoice.ts(42,7): error TS2322: Type 'string' is not assignable to type 'number'.", 'The build failed.'], 22 * 60);
        $context = AgentContext::resolve($workspace, "job:{$failed->id}");
        $this->task($workspace, $machine, 'The deploy of api failed', (string) ($context['prompt'] ?? 'Fix the failed deploy.'), 'acme/api', [
            ['succeeded', 'https://github.com/acme/api/pull/88', "The build broke on a type error: `Invoice.total` is typed as a number, but `formatTotal()` now returns a string.\n\n- `formatTotal()` returns the number again; the view formats it.\n- `npm run build` passes.", '+3 -3, 2 files', 21 * 60, 17 * 60],
        ], $user, true, $context === null ? [] : ['context' => array_intersect_key($context, array_flip(['ref', 'kind', 'label', 'text']))]);
        Cache::forever(AgentTasks::pullKey($workspace->id, 'https://github.com/acme/api/pull/88'), ['state' => 'open', 'merged' => false, 'mergeSha' => null, 'headSha' => str_repeat('e', 40)]);

        // Booked for tonight, while the machine is quiet.
        [$tonight] = $this->task($workspace, $machine, 'Upgrade to Laravel 13', 'Upgrade the app to Laravel 13. Follow the upgrade guide, fix what breaks, and keep the tests passing.', 'acme/shop', [], $user);
        foreach (range(1, 2) as $i) {
            $tonight->queued()->create(['user_id' => $user->id, 'turn' => 'tonight', 'not_before' => AgentTasks::tonight($machine), 'quiet' => true]);
        }
        $tonight->forceFill(['agents' => 2])->save();

        // OpenCode on a model on the machine itself: nothing left it.
        $this->task($workspace, $machine, 'Explain the payment flow', 'Explain how a payment moves through this app, from the checkout form to the webhook that marks the order paid. Change nothing.', 'acme/shop', [
            ['succeeded', null, "A payment goes through four places:\n\n1. `CheckoutController@store` creates the order as `pending` and a payment intent.\n2. The browser confirms the intent with the provider.\n3. `PaymentWebhookController` gets `payment.succeeded` and calls `MarkOrderPaid`.\n4. `MarkOrderPaid` sets the order `paid` and sends the receipt.\n\nIf the webhook never arrives, the order stays `pending`; nothing retries it.", null, 2 * 3600, 2 * 3600 - 9 * 60],
        ], $user, false, ['agent' => 'opencode', 'model' => 'ollama/qwen3-coder:30b']);

        $this->task($workspace, $machine, 'Review the auth code', 'Review the login and session code for security problems. Change nothing. List the most important findings first.', 'acme/shop', [
            ['succeeded', null, "Three things worth a look:\n\n1. **Session fixation**: `LoginController@store` doesn't regenerate the session id after login.\n2. Password reset tokens never expire (`config/auth.php`, `expire` is `0`).\n3. The remember-me cookie isn't marked `secure`.\n\nNothing else stood out.", null, 3 * 3600, 3 * 3600 - 14 * 60],
        ], $user, false);

        $this->task($workspace, $machine, 'Fix the flaky upload test', 'tests/Feature/UploadTest.php fails about one run in five on CI. Find out why and make it reliable.', 'acme/shop', [
            ['failed', null, '', null, 26 * 3600, 26 * 3600 - 6 * 60, 'Couldn\'t clone acme/shop at main. If it\'s private, connect it with ServerOS\'s GitHub App, or set GH_TOKEN on the agent\'s service.'],
        ], $user);

        $this->info("Added 8 demo tasks to {$workspace->name} on {$machine->name}, with a demo project for previews. Remove them with `php artisan dev:agents {$workspace->slug} --clear`.");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $spec
     * @param  list<string>  $log
     */
    private function job(Machine $machine, mixed $user, string $kind, string $service, string $state, array $spec, array $log, int $ago): DaemonJob
    {
        return DaemonJob::query()->create([
            'id' => (string) Str::uuid(),
            'machine_id' => $machine->id,
            'user_id' => $user->id,
            'kind' => $kind,
            'command' => ['job' => $spec],
            'service' => $service,
            'state' => $state,
            'log' => $log,
            'error' => $state === 'failed' ? ['message' => 'The build failed.'] : null,
            'workflow_node' => self::DEMO_JOB,
            'finished_at' => now()->subSeconds($ago),
            'created_at' => now()->subSeconds($ago + 120),
            'updated_at' => now()->subSeconds($ago),
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string|null, 2: string, 3: string|null, 4: int, 5: int|null, 6?: string|null, 7?: array{notes: string, after: int}}>  $agents
     * @param  array{agent?: string, model?: string, context?: array<string, mixed>}  $extra
     * @return array{0: AgentTask, 1: array<int, int>}
     */
    private function task(Workspace $workspace, Machine $machine, string $title, string $prompt, string $repo, array $agents, mixed $user, bool $pullRequest = true, array $extra = []): array
    {
        $service = 'docker:coding-agent';
        $agentKind = $extra['agent'] ?? 'claude';
        $model = $extra['model'] ?? null;
        $agentName = AgentSpec::AGENTS[$agentKind];
        $usedModel = $model ?? 'claude-sonnet-4-5';
        $graph = WorkflowSteps::clean(['nodes' => [
            ['id' => 'trigger', 'kind' => 'trigger', 'x' => -200, 'y' => 0, 'title' => 'From the Agents page', 'description' => '', 'rules' => ['event' => 'manual']],
            ['id' => AgentTasks::NODE, 'kind' => 'agent', 'x' => -200, 'y' => 200, 'title' => $title, 'description' => '', 'rules' => array_filter([
                'machine_id' => $machine->id, 'service' => $service, 'agent' => $agentKind, 'model' => $model, 'prompt' => $prompt, 'repo' => $repo,
                'branch' => '{{data.base}}', 'pull_request' => $pullRequest, 'pr_title' => $title, 'minutes' => 20,
            ], fn ($v) => $v !== null)],
        ], 'edges' => [['id' => 'e1', 'source' => 'trigger', 'port' => 'out', 'target' => AgentTasks::NODE]]]);

        $workflow = $workspace->workflows()->create([
            'name' => "Agent task: {$title}", 'description' => self::MARK.'made by dev:agents', 'graph' => $graph,
            'trigger' => 'manual', 'run_as' => $user->id, 'enabled' => false, 'internal' => true,
        ]);
        $oldest = max([60, ...array_column($agents, 4)]);
        $task = new AgentTask([
            'user_id' => $user->id, 'workflow_id' => $workflow->id, 'machine_id' => $machine->id, 'service' => $service,
            'title' => $title, 'prompt' => $prompt, 'agent' => $agentKind, 'model' => $model, 'repo' => $repo, 'branch' => 'main',
            'pull_request' => $pullRequest, 'agents' => max(1, count($agents)), 'context' => $extra['context'] ?? null,
        ]);
        $task->workspace_id = $workspace->id;
        $task->created_at = now()->subSeconds($oldest);
        $task->updated_at = now()->subSeconds(min([PHP_INT_MAX, 60, ...array_map(fn ($a) => $a[5] ?? 0, $agents)]));
        $task->save();

        $runIds = [];
        foreach ($agents as $i => $agent) {
            [$state, $pr, $summary, $stat, $startedAgo, $finishedAgo] = $agent;
            $error = $agent[6] ?? null;
            $follow = $agent[7] ?? null;
            $after = $follow !== null ? ($runIds[$follow['after']] ?? null) : null;
            $started = now()->subSeconds($startedAgo);
            $finished = $finishedAgo === null ? null : now()->subSeconds($finishedAgo);
            $step = array_filter([
                'state' => $state,
                'startedAt' => $started->getTimestamp(),
                'finishedAt' => $finished?->getTimestamp(),
                'message' => match ($state) {
                    'running' => "{$agentName} is working in coding-agent on {$machine->name}, for up to 20 min",
                    'failed' => $error,
                    default => $pr !== null ? "Opened {$pr} ({$agentName}, {$usedModel})" : "The agent finished ({$agentName}, {$usedModel})",
                },
                'summary' => $summary !== '' ? $summary : null,
                'pr' => $pr,
                'model' => $state === 'running' ? null : $usedModel,
            ], fn ($v) => $v !== null);
            $run = $workflow->runs()->create([
                'user_id' => $user->id,
                'state' => $state,
                'trigger' => ['kind' => AgentTasks::TRIGGER, 'summary' => $follow !== null ? "Follow-up by {$user->name}" : "Started by {$user->name}", 'data' => array_filter([
                    'task' => $task->id, 'attempt' => $i + 1, 'turn' => $follow !== null ? "follow-{$i}" : 'first', 'by' => $user->name,
                    'after' => $after, 'notes' => $follow['notes'] ?? null, 'previous' => $follow !== null ? ['state' => 'succeeded'] : null,
                    'base' => $after !== null ? "serveros/agent-{$after}-agent" : 'main',
                ], fn ($v) => $v !== null)],
                'graph' => $graph,
                'steps' => ['trigger' => ['state' => 'succeeded', 'startedAt' => $started->getTimestamp(), 'finishedAt' => $started->getTimestamp()], AgentTasks::NODE => $step],
                'frontier' => [],
                'logs' => [],
                'started_at' => $started,
                'finished_at' => $finished,
            ]);

            $runIds[$i] = $run->id;
            if ($pr !== null) {
                Cache::forever(AgentTasks::filesKey($workspace->id, $pr), self::files($pr));
            }

            $log = ["Cloning {$repo} at main", 'Setting up: npm ci', str_starts_with((string) $model, 'ollama/') ? "Running {$agentName} on {$model}, on this machine, for up to 20 minutes" : "Running {$agentName} for up to 20 minutes"];
            array_push($log, ...self::steps($title, $state, $i));
            if ($state !== 'running') {
                $log[] = AgentSpec::MODEL.' '.$usedModel;
                if (! str_starts_with((string) $model, 'ollama/')) {
                    $log[] = AgentSpec::COST.' '.number_format(0.08 + (($startedAgo % 97) / 100), 4, '.', '');
                }
            }
            if ($stat !== null) {
                $log[] = AgentSpec::STAT.' '.$stat;
            }
            if ($pr !== null) {
                $log[] = AgentSpec::PR.' '.$pr;
            }
            if ($error !== null) {
                $log[] = AgentSpec::ERROR.' '.$error;
            }
            if ($summary !== '') {
                $log[] = AgentSpec::SUMMARY;
                array_push($log, ...explode("\n", $summary));
            }
            DaemonJob::query()->create([
                'id' => (string) Str::uuid(),
                'machine_id' => $machine->id,
                'user_id' => $user->id,
                'kind' => 'service_exec',
                'command' => ['job' => ['type' => 'service_exec', 'service' => $service]],
                'service' => $service,
                'state' => $state === 'running' ? 'running' : ($state === 'failed' ? 'failed' : 'succeeded'),
                'log' => $log,
                'workflow_run_id' => $run->id,
                'workflow_node' => AgentTasks::NODE,
                'finished_at' => $finished,
                'created_at' => $started,
                'updated_at' => $finished ?? now(),
            ]);
        }

        return [$task, $runIds];
    }

    /**
     * The step lines an agent would have printed, so the activity feed has
     * something real-looking to show.
     *
     * @return list<string>
     */
    private static function steps(string $title, string $state, int $i): array
    {
        $plan = fn (array $todos) => ['k' => 'plan', 'todos' => array_map(fn ($t) => ['t' => $t[0], 's' => $t[1]], $todos)];
        $done = $state !== 'running';
        $script = match (true) {
            str_starts_with($title, 'Coupon') => [
                ['k' => 'note', 't' => 'I’ll start with where the checkout total is worked out.'],
                ['k' => 'find', 't' => 'app/**/Cart*.php'],
                ['k' => 'read', 't' => 'app/Cart/Cart.php'],
                ['k' => 'read', 't' => 'app/Http/Controllers/CheckoutController.php'],
                ['k' => 'read', 't' => 'app/Models/Coupon.php'],
                ['k' => 'search', 't' => 'amountOff('],
                $plan([['Find where the discount is applied', 'completed'], ['Apply it before tax', $done ? 'completed' : 'in_progress'], ['Add a test for a taxed cart', $done ? 'completed' : 'pending']]),
                ['k' => 'note', 't' => 'The discount comes off after tax, so a 20% coupon takes 20% of the tax too. Moving it into the subtotal.'],
                ['k' => 'edit', 't' => $i === 1 ? 'app/Http/Controllers/CheckoutController.php' : 'app/Cart/Cart.php'],
                ['k' => 'write', 't' => 'tests/Feature/CouponTotalTest.php'],
                ['k' => 'run', 't' => 'php artisan test --filter=Coupon', 'd' => 'Run the coupon tests'],
                ['k' => 'note', 't' => 'All 6 coupon tests pass.'],
            ],
            str_starts_with($title, 'Add tests') => [
                ['k' => 'find', 't' => 'app/Billing/**/*.php'],
                ['k' => 'read', 't' => 'app/Billing/Invoice.php'],
                ['k' => 'read', 't' => 'app/Billing/Proration.php'],
                ['k' => 'read', 't' => 'app/Billing/TaxRates.php'],
                $plan([['Test proration across a plan change', 'completed'], ['Test tax rates by country', 'in_progress'], ['Test refunds of partial periods', 'pending'], ['Run the whole suite', 'pending']]),
                ['k' => 'write', 't' => 'tests/Unit/Billing/ProrationTest.php'],
                ['k' => 'run', 't' => 'vendor/bin/pest tests/Unit/Billing', 'd' => 'Run the new billing tests'],
                ['k' => 'note', 't' => 'Proration is covered. Now the tax rates, which have a few edge cases around reverse charge.'],
                ['k' => 'write', 't' => 'tests/Unit/Billing/TaxRatesTest.php'],
            ],
            str_starts_with($title, 'Update dependencies') => [
                ['k' => 'read', 't' => 'package.json'],
                ['k' => 'run', 't' => 'npm outdated', 'd' => 'See what has newer releases'],
                $plan([['Update patch and minor releases', 'in_progress'], ['Fix what breaks', 'pending'], ['Run the build and tests', 'pending']]),
                ['k' => 'run', 't' => 'npm update', 'd' => 'Update within the allowed ranges'],
            ],
            str_starts_with($title, 'The deploy of api') => [
                ['k' => 'note', 't' => 'The build log ends on a type error in the billing code.'],
                ['k' => 'read', 't' => 'src/billing/format.ts'],
                ['k' => 'read', 't' => 'src/billing/InvoiceView.tsx'],
                ['k' => 'edit', 't' => 'src/billing/format.ts'],
                ['k' => 'edit', 't' => 'src/billing/InvoiceView.tsx'],
                ['k' => 'run', 't' => 'npm run build', 'd' => 'Check the build passes'],
            ],
            str_starts_with($title, 'Explain the payment flow') => [
                ['k' => 'search', 't' => 'payment.succeeded'],
                ['k' => 'read', 't' => 'app/Http/Controllers/CheckoutController.php'],
                ['k' => 'read', 't' => 'app/Http/Controllers/PaymentWebhookController.php'],
                ['k' => 'read', 't' => 'app/Actions/MarkOrderPaid.php'],
            ],
            default => [
                ['k' => 'find', 't' => 'app/**/*.php'],
                ['k' => 'read', 't' => 'routes/web.php'],
                ['k' => 'read', 't' => 'app/Http/Controllers/Auth/LoginController.php'],
                ['k' => 'search', 't' => 'regenerate('],
            ],
        };

        return array_map(fn (array $step) => AgentSpec::STEP.' '.json_encode($step), $script);
    }

    /** @return list<array{path: string, status: string, additions: int, deletions: int, patch: string|null}> */
    private static function files(string $pr): array
    {
        $cart = <<<'DIFF'
@@ -41,14 +41,20 @@ class Cart
     public function subtotal(): int
     {
-        return $this->lines->sum(fn (Line $line) => $line->price * $line->quantity);
+        $gross = $this->lines->sum(fn (Line $line) => $line->price * $line->quantity);
+
+        return $gross - $this->discount($gross);
     }
 
     public function total(): int
     {
-        $total = $this->subtotal() + $this->tax();
-
-        return $total - $this->coupon?->amountOff($total);
+        return $this->subtotal() + $this->tax();
+    }
+
+    private function discount(int $gross): int
+    {
+        return $this->coupon?->amountOff($gross) ?? 0;
     }
DIFF;
        $test = <<<'DIFF'
@@ -0,0 +1,18 @@
+<?php
+
+use App\Cart\Cart;
+use App\Models\Coupon;
+
+test('a percentage coupon comes off before tax', function () {
+    $cart = Cart::make()->add(price: 10_00, quantity: 2);
+    $cart->apply(Coupon::percent(20));
+
+    expect($cart->subtotal())->toBe(16_00)
+        ->and($cart->tax())->toBe(3_20)
+        ->and($cart->total())->toBe(19_20);
+});
DIFF;
        $controller = <<<'DIFF'
@@ -58,7 +58,9 @@ class CheckoutController
-        $total = $cart->total();
+        $total = $cart->coupon
+            ? $cart->total() - $cart->coupon->amountOff($cart->total())
+            : $cart->total();
DIFF;
        $order = <<<'DIFF'
@@ -22,6 +22,7 @@ class Order
             'subtotal' => $cart->subtotal(),
             'tax' => $cart->tax(),
+            'discount_total' => $cart->discountTotal(),
             'total' => $cart->total(),
DIFF;

        return match (true) {
            str_ends_with($pr, '/412') => [
                ['path' => 'app/Cart/Cart.php', 'status' => 'modified', 'additions' => 10, 'deletions' => 4, 'patch' => $cart],
                ['path' => 'tests/Feature/CouponTotalTest.php', 'status' => 'added', 'additions' => 13, 'deletions' => 0, 'patch' => $test],
                ['path' => 'resources/views/checkout/summary.blade.php', 'status' => 'modified', 'additions' => 2, 'deletions' => 2, 'patch' => "@@ -12,7 +12,7 @@\n-    <dd>{{ money(\$cart->total() - \$cart->discount) }}</dd>\n+    <dd>{{ money(\$cart->total()) }}</dd>"],
            ],
            str_ends_with($pr, '/88') => [
                ['path' => 'src/billing/format.ts', 'status' => 'modified', 'additions' => 2, 'deletions' => 2, 'patch' => "@@ -8,6 +8,6 @@ export function formatTotal(invoice: Invoice) {\n-  return invoice.total.toFixed(2);\n+  return invoice.total;\n }"],
                ['path' => 'src/billing/InvoiceView.tsx', 'status' => 'modified', 'additions' => 1, 'deletions' => 1, 'patch' => "@@ -21,7 +21,7 @@\n-      <td>{formatTotal(invoice)}</td>\n+      <td>{formatTotal(invoice).toFixed(2)}</td>"],
            ],
            str_ends_with($pr, '/413') => [
                ['path' => 'app/Http/Controllers/CheckoutController.php', 'status' => 'modified', 'additions' => 3, 'deletions' => 1, 'patch' => $controller],
                ['path' => 'tests/Feature/CheckoutTest.php', 'status' => 'modified', 'additions' => 16, 'deletions' => 3, 'patch' => null],
            ],
            default => [
                ['path' => 'app/Models/Order.php', 'status' => 'modified', 'additions' => 1, 'deletions' => 0, 'patch' => $order],
                ['path' => 'tests/Feature/CouponTotalTest.php', 'status' => 'modified', 'additions' => 8, 'deletions' => 2, 'patch' => "@@ -13,4 +13,10 @@\n         ->and(\$cart->total())->toBe(19_20);\n+\n+    \$order = Order::fromCart(\$cart);\n+    expect(\$order->discount_total)->toBe(4_00);"],
            ],
        };
    }
}
