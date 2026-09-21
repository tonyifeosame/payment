<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Services\PaystackReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * B2 — `paystack:check`: a read-only go-live checklist that says what the code
 * verifies, what is missing, and what must be confirmed by hand.
 */
class PaystackCheckTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const SECRET = 'sk_live_not-a-real-key';

    protected function setUp(): void
    {
        parent::setUp();

        // A configuration that passes every application-side check under
        // production rules; individual tests break one thing at a time.
        config([
            'app.key' => 'base64:'.base64_encode(random_bytes(32)),
            'app.debug' => false,
            'app.url' => 'https://pay.example.ng',
            'services.paystack.secret_key' => self::SECRET,
            'services.paystack.public_key' => 'pk_live_0123456789abcdef',
            'services.paystack.payment_url' => 'https://api.paystack.co',
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => 'smtp.example.ng',
            'mail.mailers.smtp.port' => 587,
            'mail.from.address' => 'receipts@feyra.ng',
            'mail.from.name' => 'FEYRA',
            'queue.default' => 'database',
            'cache.default' => 'database',
            'fees.reporting_timezone' => 'Africa/Lagos',
        ]);

        $this->fakeApi();
        $this->makeSchool('Greenfield Academy', 'greenfield');
    }

    /** @var array<string, \GuzzleHttp\Promise\PromiseInterface|\Illuminate\Http\Client\Response> keyed by URL pattern */
    private array $apiResponses = [];

    /**
     * One fake, registered once, that answers from a mutable table — Http::fake()
     * keeps the first stub registered for a pattern, so tests override responses
     * here instead of re-faking.
     */
    private function fakeApi(array $overrides = []): void
    {
        $fresh = $this->apiResponses === [];
        $this->apiResponses = array_merge($fresh ? [
            '*/bank*' => Http::response(['status' => true, 'data' => [['name' => 'GTBank', 'code' => '058']]], 200),
            '*/balance' => Http::response(['status' => true, 'data' => [['currency' => 'NGN', 'balance' => 12500000]]], 200),
        ] : $this->apiResponses, $overrides);

        if ($fresh) {
            Http::fake(function ($request) {
                foreach ($this->apiResponses as $pattern => $response) {
                    if (\Illuminate\Support\Str::is($pattern, $request->url())) {
                        return $response;
                    }
                }

                return Http::response(['status' => false, 'message' => 'unexpected call'], 500);
            });
        }
    }

    /** Run the command with the given options, capturing output and exit code. */
    private function runCheck(array $options = []): array
    {
        $output = new \Symfony\Component\Console\Output\BufferedOutput;
        $exit = $this->app->make(\Illuminate\Contracts\Console\Kernel::class)->call('paystack:check', $options, $output);

        return ['exit' => $exit, 'output' => $output->fetch()];
    }

    private function jsonChecks(array $options = []): array
    {
        $run = $this->runCheck(array_merge(['--production' => true, '--json' => true], $options));
        $json = json_decode($run['output'], true, 512, JSON_THROW_ON_ERROR);

        return ['exit' => $run['exit'], 'summary' => $json['summary'], 'checks' => collect($json['checks'])->keyBy('id')->all(), 'raw' => $run['output']];
    }

    // ------------------------------------------------------------------

    public function test_a_fully_configured_production_setup_has_no_fail_and_exits_zero(): void
    {
        $r = $this->jsonChecks();

        $this->assertSame(0, $r['exit']);
        $this->assertSame(0, $r['summary']['FAIL']);
        $this->assertSame(PaystackReadiness::PASS, $r['checks']['paystack.secret_key']['status']);
        $this->assertStringContainsString('LIVE key', $r['checks']['paystack.secret_key']['detail']);
        $this->assertSame(PaystackReadiness::PASS, $r['checks']['app.url']['status']);
        $this->assertSame(PaystackReadiness::PASS, $r['checks']['mail.mailer']['status']);
        $this->assertSame(PaystackReadiness::PASS, $r['checks']['queue.connection']['status']);
        $this->assertSame(PaystackReadiness::PASS, $r['checks']['db.schema']['status']);
        $this->assertSame(PaystackReadiness::PASS, $r['checks']['api.auth']['status']);
        $this->assertSame(PaystackReadiness::PASS, $r['checks']['api.balance']['status']);
        $this->assertStringContainsString('NGN 125,000.00', $r['checks']['api.balance']['detail']);
    }

    public function test_missing_secret_key_fails_and_skips_the_api(): void
    {
        config(['services.paystack.secret_key' => null]);
        $r = $this->jsonChecks();

        $this->assertSame(1, $r['exit']);
        $this->assertSame(PaystackReadiness::FAIL, $r['checks']['paystack.secret_key']['status']);
        $this->assertSame(PaystackReadiness::FAIL, $r['checks']['paystack.webhook_secret']['status']);
        $this->assertSame(PaystackReadiness::FAIL, $r['checks']['api.auth']['status']);
        Http::assertNothingSent();

        // Human-readable form says the same.
        $run = $this->runCheck(['--production' => true]);
        $this->assertSame(1, $run['exit']);
        $this->assertStringContainsString('[FAIL] PAYSTACK_SECRET_KEY — missing', $run['output']);
        $this->assertStringContainsString('Not ready: fix every FAIL above', $run['output']);
    }

    public function test_a_test_key_under_production_rules_fails_but_passes_outside_them(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_0123456789abcdef', 'services.paystack.public_key' => 'pk_test_0123456789abcdef']);

        $prod = $this->jsonChecks();
        $this->assertSame(PaystackReadiness::FAIL, $prod['checks']['paystack.secret_key']['status']);
        $this->assertStringContainsString('TEST key', $prod['checks']['paystack.secret_key']['detail']);
        $this->assertSame(1, $prod['exit']);

        $local = $this->jsonChecks(['--production' => false]);
        $this->assertSame(PaystackReadiness::PASS, $local['checks']['paystack.secret_key']['status']);
    }

    public function test_the_secret_is_never_exposed_in_any_output(): void
    {
        config(['app.debug' => true]); // even the noisiest configuration
        foreach ([['--production' => true], ['--production' => true, '--json' => true], []] as $options) {
            $run = $this->runCheck($options);
            $this->assertStringNotContainsString(self::SECRET, $run['output']);
            $this->assertStringNotContainsString('0123456789abcdef', $run['output']);
            $this->assertStringNotContainsString('sk_live_', $run['output']);
            $this->assertStringNotContainsString('pk_live_', $run['output']);
        }

        // The classification helper itself never returns any part of the key.
        $this->assertSame('live', PaystackReadiness::classifyKey(self::SECRET));
        $this->assertSame('test', PaystackReadiness::classifyKey('sk_test_x'));
        $this->assertSame('missing', PaystackReadiness::classifyKey(''));
        $this->assertSame('unrecognised', PaystackReadiness::classifyKey('hunter2'));
    }

    public function test_app_url_policy_missing_localhost_and_http(): void
    {
        config(['app.url' => '']);
        $r = $this->jsonChecks();
        $this->assertSame(PaystackReadiness::FAIL, $r['checks']['app.url']['status']);
        $this->assertSame(1, $r['exit']);

        config(['app.url' => 'http://localhost']);
        $this->assertSame(PaystackReadiness::FAIL, $this->jsonChecks()['checks']['app.url']['status']);

        config(['app.url' => 'http://pay.example.ng']);
        $r = $this->jsonChecks();
        $this->assertSame(PaystackReadiness::FAIL, $r['checks']['app.url']['status']);
        $this->assertStringContainsString('not HTTPS', $r['checks']['app.url']['detail']);

        // Outside production rules the same problems are warnings, not failures.
        $r = $this->jsonChecks(['--production' => false]);
        $this->assertSame(PaystackReadiness::WARN, $r['checks']['app.url']['status']);
        $this->assertSame(0, $r['exit']);
    }

    public function test_webhook_and_callback_endpoints_are_reported_from_the_route_table(): void
    {
        $r = $this->jsonChecks();

        $webhook = $r['checks']['webhook.route'];
        $this->assertSame(PaystackReadiness::PASS, $webhook['status']);
        $this->assertStringContainsString('POST https://pay.example.ng/paystack/webhook', $webhook['detail']);
        $this->assertStringContainsString('HMAC-SHA512', $webhook['detail']);
        $this->assertStringContainsString('x-paystack-signature', $webhook['detail']);

        $callback = $r['checks']['callback.route'];
        $this->assertSame(PaystackReadiness::PASS, $callback['status']);
        $this->assertStringContainsString('GET https://pay.example.ng/payment/callback', $callback['detail']);

        // The reported endpoint is the real one: it exists, is POST-only, and rejects
        // an unsigned delivery (so registering it in the dashboard is enough).
        $this->post('/paystack/webhook', [])->assertStatus(401);
        $this->get('/paystack/webhook')->assertStatus(405);
    }

    public function test_dashboard_only_prerequisites_are_manual_never_pass(): void
    {
        $r = $this->jsonChecks();

        $manual = collect($r['checks'])->filter(fn ($c) => str_starts_with($c['id'], 'manual.'));
        $this->assertCount(9, $manual);
        $this->assertTrue($manual->every(fn ($c) => $c['status'] === PaystackReadiness::MANUAL));

        foreach (['manual.live_mode', 'manual.transfers_enabled', 'manual.balance_funding', 'manual.transfer_otp', 'manual.webhook_registered', 'manual.webhook_reachable', 'manual.settlement_bank', 'manual.render_services', 'manual.render_env_parity'] as $id) {
            $this->assertArrayHasKey($id, $r['checks']);
        }
        $this->assertStringContainsString('https://pay.example.ng/paystack/webhook', $r['checks']['manual.webhook_registered']['detail']);
        $this->assertSame(9, $r['summary']['MANUAL']);
        $this->assertSame(0, $r['exit'], 'MANUAL items never fail the command');

        $run = $this->runCheck(['--production' => true]);
        $this->assertStringContainsString('[MANUAL] Transfers enabled on the live account', $run['output']);
        $this->assertStringContainsString('Confirm every MANUAL item', $run['output']);
    }

    public function test_paystack_api_failures_are_reported_without_leaking_anything(): void
    {
        // Key rejected → FAIL, message names the variable, not the key.
        $this->fakeApi(['*/bank*' => Http::response(['status' => false, 'message' => 'Invalid key: '.self::SECRET], 401)]);
        $r = $this->jsonChecks();
        $this->assertSame(PaystackReadiness::FAIL, $r['checks']['api.auth']['status']);
        $this->assertStringContainsString('rejected the configured key', $r['checks']['api.auth']['detail']);
        $this->assertStringNotContainsString(self::SECRET, $r['raw']);
        $this->assertArrayNotHasKey('api.balance', $r['checks'], 'balance is not queried once authentication failed');
        $this->assertSame(1, $r['exit']);

        // Unreachable → WARN (transient), only a one-line message.
        $this->fakeApi(['*/bank*' => Http::response('<html>502 Bad Gateway</html>', 502)]);
        $r = $this->jsonChecks();
        $this->assertSame(PaystackReadiness::WARN, $r['checks']['api.auth']['status']);
        $this->assertStringNotContainsString('<html>', $r['raw']);
        $this->assertSame(0, $r['exit']);

        // Balance endpoint failing is a WARN with no payload echoed.
        $this->fakeApi([
            '*/bank*' => Http::response(['status' => true, 'data' => [['name' => 'GTBank', 'code' => '058']]], 200),
            '*/balance' => Http::response(['status' => false, 'message' => 'Forbidden', 'data' => ['secret' => 'never']], 403),
        ]);
        $r = $this->jsonChecks();
        $this->assertSame(PaystackReadiness::PASS, $r['checks']['api.auth']['status']);
        $this->assertSame(PaystackReadiness::WARN, $r['checks']['api.balance']['status']);
        $this->assertStringNotContainsString('never', $r['raw']);

        // A zero balance is a warning that names the consequence.
        $this->fakeApi(['*/balance' => Http::response(['status' => true, 'data' => [['currency' => 'NGN', 'balance' => 0]]], 200)]);
        $r = $this->jsonChecks();
        $this->assertSame(PaystackReadiness::WARN, $r['checks']['api.balance']['status']);
        $this->assertStringContainsString('insufficient balance', $r['checks']['api.balance']['detail']);
    }

    public function test_no_api_skips_every_network_call(): void
    {
        $r = $this->jsonChecks(['--no-api' => true]);

        Http::assertNothingSent();
        $this->assertSame(PaystackReadiness::WARN, $r['checks']['api.skipped']['status']);
        $this->assertArrayNotHasKey('api.auth', $r['checks']);
        $this->assertSame(0, $r['exit']);
    }

    public function test_only_read_only_paystack_endpoints_are_called(): void
    {
        $this->jsonChecks();

        Http::assertSentCount(2);
        Http::assertSent(fn ($req) => $req->method() === 'GET' && str_starts_with($req->url(), 'https://api.paystack.co/bank'));
        Http::assertSent(fn ($req) => $req->method() === 'GET' && $req->url() === 'https://api.paystack.co/balance');
        Http::assertNotSent(fn ($req) => $req->method() !== 'GET');
    }

    public function test_exit_codes_follow_the_documented_rules(): void
    {
        // Only FAIL changes the exit code.
        $this->assertSame(0, $this->jsonChecks()['exit']);

        config(['mail.default' => 'log']);                       // FAIL under production rules
        $this->assertSame(1, $this->jsonChecks()['exit']);
        $this->assertSame(0, $this->jsonChecks(['--production' => false])['exit'], 'the same thing is a WARN outside production rules');

        config(['mail.default' => 'smtp', 'queue.default' => 'sync']);
        $r = $this->jsonChecks();
        $this->assertSame(PaystackReadiness::FAIL, $r['checks']['queue.connection']['status']);
        $this->assertSame(1, $r['exit']);

        config(['queue.default' => 'database', 'cache.default' => 'array']);
        $r = $this->jsonChecks();
        $this->assertSame(PaystackReadiness::WARN, $r['checks']['cache.store']['status']);
        $this->assertSame(0, $r['exit'], 'WARN alone never fails');

        config(['cache.default' => 'database', 'app.debug' => true]);
        $this->assertSame(1, $this->jsonChecks()['exit']);
        config(['app.debug' => false, 'app.key' => '']);
        $this->assertSame(1, $this->jsonChecks()['exit']);
    }

    public function test_the_command_never_writes_to_the_database_sends_mail_or_queues_work(): void
    {
        Mail::fake();
        Queue::fake();
        $this->makeSuccessfulTransaction($school = \App\Models\School::first());
        Payout::create(['school_id' => $school->id, 'transaction_id' => \App\Models\Transaction::first()->id, 'reference' => 'PO-1', 'amount' => 50000, 'currency' => 'NGN', 'status' => Payout::FAILED, 'last_error' => 'x']);
        $before = [Payout::count(), \App\Models\Transaction::count(), DB::table('payout_recovery_events')->count(), Payout::first()->toArray()];

        $writes = [];
        DB::listen(function ($query) use (&$writes) {
            if (preg_match('/^\s*(insert|update|delete|alter|create|drop|truncate)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $r = $this->jsonChecks();
        $this->runCheck(['--production' => true]);

        $this->assertSame([], $writes, 'paystack:check must never write');
        $this->assertSame($before, [Payout::count(), \App\Models\Transaction::count(), DB::table('payout_recovery_events')->count(), Payout::first()->toArray()]);
        Mail::assertNothingSent();
        Queue::assertNothingPushed();

        // …and it reported the ledger state it found.
        $this->assertSame(PaystackReadiness::WARN, $r['checks']['payouts.ledger']['status']);
        $this->assertStringContainsString('1 failed (payouts:retry once the cause is fixed)', $r['checks']['payouts.ledger']['detail']);
    }

    public function test_operator_tooling_and_schema_are_verified_from_the_application(): void
    {
        $r = $this->jsonChecks();

        $this->assertSame(PaystackReadiness::PASS, $r['checks']['transfers.operator_commands']['status']);
        foreach (PaystackReadiness::OPERATOR_COMMANDS as $command) {
            $this->assertStringContainsString($command, $r['checks']['transfers.operator_commands']['detail']);
            $this->assertArrayHasKey($command, \Illuminate\Support\Facades\Artisan::all(), "{$command} must be a registered Artisan command");
        }
        $this->assertSame(PaystackReadiness::PASS, $r['checks']['transfers.integration']['status']);
        $this->assertSame(PaystackReadiness::PASS, $r['checks']['db.schema']['status']);
        $this->assertStringContainsString('1 school(s) with verified bank details', $r['checks']['transfers.recipients']['detail']);
        $this->assertSame(PaystackReadiness::PASS, $r['checks']['paystack.webhook_secret']['status']);
        $this->assertStringContainsString('HMAC-SHA512', $r['checks']['paystack.webhook_secret']['detail']);
    }

    public function test_the_runbook_documents_the_real_endpoints_commands_and_checklist(): void
    {
        $path = base_path('docs/production/paystack-runbook.md');
        $this->assertFileExists($path);
        $runbook = file_get_contents($path);

        // Webhook URL pattern and signature scheme.
        $this->assertStringContainsString('POST {APP_URL}/paystack/webhook', $runbook);
        $this->assertStringContainsString('HMAC-SHA512', $runbook);
        $this->assertStringContainsString('x-paystack-signature', $runbook);
        $this->assertStringContainsString('/payment/callback', $runbook);

        // Every operator command, exactly as registered.
        foreach (['php artisan paystack:check --production', 'php artisan payouts:retry {reference}', 'php artisan payouts:retry --all-failed',
            'php artisan payouts:release {reference} --amount=', 'php artisan payouts:lookup {reference}', 'php artisan payouts:lookup --stale', 'php artisan payouts:run --dispatch'] as $cmd) {
            $this->assertStringContainsString($cmd, $runbook, "runbook must document `{$cmd}`");
        }
        foreach (PaystackReadiness::OPERATOR_COMMANDS as $command) {
            $this->assertArrayHasKey($command, \Illuminate\Support\Facades\Artisan::all());
        }
        $this->assertArrayHasKey('paystack:check', \Illuminate\Support\Facades\Artisan::all());

        // The sections the go-live process depends on.
        foreach (['## 1. Environment setup', '## 2. Paystack dashboard setup', '## 3. Pre-launch checklist', '## 4. First production transaction',
            '## 5. Payout recovery commands', '## 6. Incident handling', '## 7. Security', '## 8. Stop / rollback procedure'] as $heading) {
            $this->assertStringContainsString($heading, $runbook);
        }
        foreach (['Transfers capability', 'OTP', 'Paystack balance', 'needs_review', 'initiating', 'payout_recovery_events', 'Never', 'sk_live_'] as $must) {
            $this->assertStringContainsString($must, $runbook);
        }
        $this->assertStringNotContainsString(self::SECRET, $runbook);
    }
}
