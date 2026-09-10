<?php

namespace Tests\Feature;

use App\Mail\PaymentReceiptMail;
use App\Models\School;
use App\Models\Transaction;
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Concurrency regressions for the Phase 2 adversarial audit (H1, M2, M4, M5).
 *
 * PHPUnit is single-threaded, so these do not run requests in parallel. Instead they
 * reproduce the *exact interleaving* that matters: the window while one request is
 * out on the Paystack verify call, during which another request commits a change.
 * That window is driven deterministically by performing the competing work inside
 * the HTTP fake, which is precisely where the real-world race occurs.
 *
 * The row lock itself is only emitted on PostgreSQL/MySQL (SQLite compiles
 * lockForUpdate() to nothing) — see PostgresRowLockTest for that half.
 */
class PaymentConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Transaction $pending;

    private const GROSS = 51250.00;

    private const GROSS_KOBO = 5125000;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['services.paystack.secret_key' => 'sk_test_secret']);

        $this->school = School::create([
            'name' => 'Alpha School', 'slug' => 'alpha', 'email' => 'alpha@example.test',
            'admin_password' => Hash::make('password123'),
        ]);

        $this->pending = Transaction::create([
            'school_id' => $this->school->id,
            'reference' => 'alpha-ref-001',
            'amount' => self::GROSS,
            'status' => 'pending',
            'email' => 'payer@example.test',
            'name' => 'Alpha Payer',
            'meta_data' => ['quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 1250],
        ]);
    }

    private function verifyPayload(array $overrides = []): array
    {
        return ['status' => true, 'data' => array_merge([
            'status' => 'success',
            'channel' => 'card',
            'reference' => 'alpha-ref-001',
            'amount' => self::GROSS_KOBO,
            'currency' => 'NGN',
        ], $overrides)];
    }

    private function settlement(): PaymentSettlementService
    {
        return app(PaymentSettlementService::class);
    }

    private function webhookPayload(string $reference = 'alpha-ref-001'): string
    {
        return json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference]]);
    }

    private function postWebhook(string $body)
    {
        return $this->call('POST', '/paystack/webhook', [], [], [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_secret'),
             'CONTENT_TYPE' => 'application/json'],
            $body);
    }

    /**
     * Run $competing while the request under test is "out on the network", then
     * return $responseForA as that request's verify result.
     */
    private function fakeVerifyWithInterleave(array $responseForA, callable $competing): void
    {
        $call = 0;
        Http::fake(function () use (&$call, $responseForA, $competing) {
            $call++;

            if ($call === 1) {
                $competing();               // the competing delivery commits here

                return Http::response($responseForA, 200);
            }

            return Http::response($this->verifyPayload(), 200); // the competing call's own verify
        });
    }

    // =====================================================================
    // H1 — a stale mismatch must never downgrade a settled payment
    // =====================================================================

    public function test_H1_stale_mismatch_cannot_downgrade_a_settled_payment(): void
    {
        // A reads 'pending', goes to the network. B settles successfully. A then
        // comes back with a mismatching amount and tries to flag it.
        $this->fakeVerifyWithInterleave(
            $this->verifyPayload(['amount' => 1]),          // A sees a mismatch
            fn () => $this->settlement()->settleByReference('alpha-ref-001') // B settles
        );

        $result = $this->settlement()->settleByReference('alpha-ref-001');

        $this->pending->refresh();

        $this->assertSame('success', $this->pending->status, 'a confirmed payment was downgraded');
        $this->assertNotNull($this->pending->paid_at, 'paid_at must survive');
        $this->assertSame(PaymentSettlementService::ALREADY_SETTLED, $result['outcome']);
        Mail::assertQueued(PaymentReceiptMail::class, 1);
    }

    public function test_H1_stale_currency_mismatch_cannot_downgrade_either(): void
    {
        $this->fakeVerifyWithInterleave(
            $this->verifyPayload(['currency' => 'USD']),
            fn () => $this->settlement()->settleByReference('alpha-ref-001')
        );

        $result = $this->settlement()->settleByReference('alpha-ref-001');

        $this->assertSame('success', $this->pending->refresh()->status);
        $this->assertSame(PaymentSettlementService::ALREADY_SETTLED, $result['outcome']);
    }

    public function test_H1_a_genuine_mismatch_is_still_recorded_when_not_settled(): void
    {
        // The guard must not swallow real mismatches.
        Http::fake(['*transaction/verify*' => Http::response($this->verifyPayload(['amount' => 1]), 200)]);

        $result = $this->settlement()->settleByReference('alpha-ref-001');

        $this->pending->refresh();
        $this->assertSame('mismatch', $this->pending->status);
        $this->assertNull($this->pending->paid_at);
        $this->assertSame(PaymentSettlementService::AMOUNT_MISMATCH, $result['outcome']);
        Mail::assertNothingQueued();
    }

    // =====================================================================
    // M2 — metadata must survive a mismatch write
    // =====================================================================

    public function test_M2_mismatch_preserves_existing_metadata(): void
    {
        Http::fake(['*transaction/verify*' => Http::response($this->verifyPayload(['amount' => 1]), 200)]);

        $this->settlement()->settleByReference('alpha-ref-001');

        $meta = $this->pending->refresh()->meta_data;
        $this->assertSame(50000, $meta['base_amount'] ?? null, 'base_amount was wiped');
        $this->assertSame(1250, $meta['markup_amount'] ?? null);
        $this->assertSame(1, $meta['quantity'] ?? null);
        $this->assertSame('amount_mismatch', $meta['verification_error']['kind'] ?? null);
    }

    public function test_M2_mismatch_preserves_legacy_double_encoded_metadata(): void
    {
        // A row written by the old un-scoped TransactionController::store().
        DB::table('transactions')->insert([
            'school_id' => $this->school->id,
            'reference' => 'legacy-ref-001',
            'amount' => self::GROSS,
            'status' => 'pending',
            'email' => 'legacy@example.test',
            'meta_data' => json_encode(json_encode(['quantity' => 2, 'base_amount' => 50000])),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake(['*transaction/verify*' => Http::response($this->verifyPayload([
            'reference' => 'legacy-ref-001', 'amount' => 1,
        ]), 200)]);

        $this->settlement()->settleByReference('legacy-ref-001');

        $meta = Transaction::where('reference', 'legacy-ref-001')->first()->meta_data;
        $this->assertIsArray($meta);
        $this->assertSame(50000, $meta['base_amount'] ?? null, 'legacy base_amount was destroyed');
        $this->assertSame(2, $meta['quantity'] ?? null);
        $this->assertSame('amount_mismatch', $meta['verification_error']['kind'] ?? null);
    }

    // =====================================================================
    // Concurrent deliveries — exactly one settlement, exactly one receipt
    // =====================================================================

    public function test_callback_and_webhook_arriving_concurrently_settle_once(): void
    {
        // The webhook lands while the callback is mid-verify.
        $this->fakeVerifyWithInterleave(
            $this->verifyPayload(),
            fn () => $this->postWebhook($this->webhookPayload())
        );

        $this->get('/payment/callback?reference=alpha-ref-001');

        $this->assertSame(1, Transaction::where('status', 'success')->count());
        $this->assertSame(1, Transaction::where('reference', 'alpha-ref-001')->count());
        Mail::assertQueued(PaymentReceiptMail::class, 1);
    }

    public function test_webhook_arriving_while_another_webhook_verifies_settles_once(): void
    {
        $this->fakeVerifyWithInterleave(
            $this->verifyPayload(),
            fn () => $this->settlement()->settleByReference('alpha-ref-001')
        );

        $result = $this->settlement()->settleByReference('alpha-ref-001');

        $this->assertSame(PaymentSettlementService::ALREADY_SETTLED, $result['outcome']);
        $this->assertSame(1, Transaction::where('status', 'success')->count());
        Mail::assertQueued(PaymentReceiptMail::class, 1);
    }

    public function test_paid_at_is_written_once_and_never_rewritten(): void
    {
        $this->fakeVerifyWithInterleave(
            $this->verifyPayload(),
            fn () => $this->settlement()->settleByReference('alpha-ref-001')
        );

        $this->settlement()->settleByReference('alpha-ref-001');
        $firstPaidAt = $this->pending->refresh()->paid_at;

        Http::fake(['*transaction/verify*' => Http::response($this->verifyPayload(), 200)]);
        $this->settlement()->settleByReference('alpha-ref-001');
        $this->settlement()->settleByReference('alpha-ref-001');

        $this->assertEquals($firstPaidAt, $this->pending->refresh()->paid_at);
        Mail::assertQueued(PaymentReceiptMail::class, 1);
    }

    public function test_replay_long_after_settlement_changes_nothing(): void
    {
        Http::fake(['*transaction/verify*' => Http::response($this->verifyPayload(), 200)]);

        $this->settlement()->settleByReference('alpha-ref-001');
        $snapshot = $this->pending->refresh()->only(['status', 'paid_at', 'payment_method', 'paystack_reference']);

        for ($i = 0; $i < 5; $i++) {
            $this->postWebhook($this->webhookPayload())->assertOk();
            $this->get('/payment/callback?reference=alpha-ref-001');
        }

        $this->assertEquals($snapshot, $this->pending->refresh()->only(['status', 'paid_at', 'payment_method', 'paystack_reference']));
        $this->assertSame(1, Transaction::where('status', 'success')->count());
        Mail::assertQueued(PaymentReceiptMail::class, 1);
    }

    // =====================================================================
    // M5 — paystack_reference conflicts must not break settlement
    // =====================================================================

    public function test_M5_conflicting_paystack_reference_still_settles_the_payment(): void
    {
        // Another row already holds the reference Paystack is about to hand back.
        Transaction::create([
            'school_id' => $this->school->id,
            'reference' => 'other-ref-002',
            'paystack_reference' => 'alpha-ref-001',
            'amount' => 100,
            'status' => 'success',
            'email' => 'other@example.test',
        ]);

        Http::fake(['*transaction/verify*' => Http::response($this->verifyPayload(), 200)]);

        $result = $this->settlement()->settleByReference('alpha-ref-001');

        $this->pending->refresh();

        // The payment Paystack confirmed is still recorded as paid.
        $this->assertSame(PaymentSettlementService::SETTLED, $result['outcome']);
        $this->assertSame('success', $this->pending->status);
        $this->assertNotNull($this->pending->paid_at);

        // The clash is recorded for reconciliation rather than thrown.
        $this->assertSame('alpha-ref-001', $this->pending->meta_data['paystack_reference_conflict']['paystack_reference'] ?? null);
        $this->assertSame(50000, $this->pending->meta_data['base_amount'] ?? null);
        Mail::assertQueued(PaymentReceiptMail::class, 1);
    }

    public function test_M5_webhook_does_not_retry_forever_on_a_conflict(): void
    {
        Transaction::create([
            'school_id' => $this->school->id,
            'reference' => 'other-ref-003',
            'paystack_reference' => 'alpha-ref-001',
            'amount' => 100, 'status' => 'success', 'email' => 'other@example.test',
        ]);

        Http::fake(['*transaction/verify*' => Http::response($this->verifyPayload(), 200)]);

        // 2xx tells Paystack to stop redelivering.
        $this->postWebhook($this->webhookPayload())->assertOk();
        $this->assertSame('success', $this->pending->refresh()->status);
    }

    // =====================================================================
    // M4 — receipt delivery
    // =====================================================================

    public function test_M4_receipt_is_queued_not_sent_inline(): void
    {
        Http::fake(['*transaction/verify*' => Http::response($this->verifyPayload(), 200)]);

        $this->settlement()->settleByReference('alpha-ref-001');

        Mail::assertQueued(PaymentReceiptMail::class, 1);
        Mail::assertNothingSent(); // never rendered inside the web request
    }

    public function test_M4_settlement_survives_a_receipt_dispatch_failure(): void
    {
        Http::fake(['*transaction/verify*' => Http::response($this->verifyPayload(), 200)]);

        // Force the queue push to blow up.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('queue is down'));

        $result = $this->settlement()->settleByReference('alpha-ref-001');

        $this->pending->refresh();
        $this->assertSame(PaymentSettlementService::SETTLED, $result['outcome']);
        $this->assertSame('success', $this->pending->status, 'settlement must not roll back when the receipt cannot be queued');
        $this->assertNotNull($this->pending->paid_at);
    }
}
