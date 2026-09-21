<?php

namespace Tests\Feature;

use App\Mail\PaymentReceiptMail;
use App\Models\School;
use App\Models\Transaction;
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regression tests for G1–G4 — Paystack payment integrity.
 */
class PaymentIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private School $alpha;

    private School $beta;

    private Transaction $pending;

    /** Gross charged to the payer: 50,000 base + 2.5% markup. */
    private const GROSS = 51250.00;

    private const GROSS_KOBO = 5125000;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['services.paystack.secret_key' => 'sk_test_secret']);

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');

        $this->pending = Transaction::create([
            'school_id' => $this->alpha->id,
            'reference' => 'alpha-ref-001',
            'amount' => self::GROSS,
            'status' => 'pending',
            'email' => 'payer@example.test',
            'name' => 'Alpha Payer',
            'category_name' => 'School Fees',
            'subcategory_name' => 'Primary',
            'meta_data' => ['quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 1250],
        ]);
    }

    private function makeSchool(string $name, string $slug): School
    {
        return School::create([
            'name' => $name,
            'slug' => $slug,
            'email' => $slug.'@example.test',
            'admin_password' => Hash::make('password123'),
            'account_number' => '0123456789',
            'bank' => 'GTB',
            'bank_code' => '058',
            'account_name' => 'Acct '.$name,
        ]);
    }

    /** Fake a Paystack /transaction/verify response. */
    private function fakeVerify(array $overrides = [], array $metadata = []): void
    {
        Http::fake([
            '*transaction/verify*' => Http::response([
                'status' => true,
                'data' => array_merge([
                    'status' => 'success',
                    'channel' => 'card',
                    'reference' => 'alpha-ref-001',
                    'amount' => self::GROSS_KOBO,
                    'currency' => 'NGN',
                    'metadata' => $metadata,
                ], $overrides),
            ], 200),
        ]);
    }

    private function webhookPayload(string $reference, string $event = 'charge.success'): string
    {
        return json_encode(['event' => $event, 'data' => ['reference' => $reference]]);
    }

    private function postWebhook(string $body, ?string $signature = null)
    {
        $signature ??= hash_hmac('sha512', $body, 'sk_test_secret');

        return $this->call(
            'POST',
            '/paystack/webhook',
            [], [], [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => $signature, 'CONTENT_TYPE' => 'application/json'],
            $body
        );
    }

    // =====================================================================
    // Valid payment succeeds
    // =====================================================================

    public function test_valid_callback_settles_the_payment(): void
    {
        $this->fakeVerify();

        $response = $this->get('/payment/callback?reference=alpha-ref-001');

        $response->assertRedirect('/s/alpha/payment');
        $response->assertSessionHas('success');

        $this->pending->refresh();
        $this->assertSame('success', $this->pending->status);
        $this->assertSame('card', $this->pending->payment_method);
        $this->assertNotNull($this->pending->paid_at);
        Mail::assertQueued(PaymentReceiptMail::class, 1);
    }

    public function test_valid_webhook_settles_the_payment(): void
    {
        $this->fakeVerify();

        $this->postWebhook($this->webhookPayload('alpha-ref-001'))->assertOk();

        $this->pending->refresh();
        $this->assertSame('success', $this->pending->status);
        Mail::assertQueued(PaymentReceiptMail::class, 1);
    }

    // =====================================================================
    // G2 — replay cannot duplicate or repeat side effects
    // =====================================================================

    public function test_callback_replay_does_not_duplicate_or_resend(): void
    {
        $this->fakeVerify();

        $this->get('/payment/callback?reference=alpha-ref-001');
        $this->get('/payment/callback?reference=alpha-ref-001');
        $this->get('/payment/callback?reference=alpha-ref-001');

        $this->assertSame(1, Transaction::where('reference', 'alpha-ref-001')->count());
        $this->assertSame(1, Transaction::where('status', 'success')->count());
        Mail::assertQueued(PaymentReceiptMail::class, 1); // exactly once, not three times
    }

    public function test_webhook_retry_is_idempotent(): void
    {
        $this->fakeVerify();
        $body = $this->webhookPayload('alpha-ref-001');

        $this->postWebhook($body)->assertOk();
        $second = $this->postWebhook($body);
        $third = $this->postWebhook($body);

        $second->assertOk()->assertJson(['status' => PaymentSettlementService::ALREADY_SETTLED]);
        $third->assertOk()->assertJson(['status' => PaymentSettlementService::ALREADY_SETTLED]);

        $this->assertSame(1, Transaction::where('status', 'success')->count());
        Mail::assertQueued(PaymentReceiptMail::class, 1);
    }

    public function test_callback_then_webhook_settles_only_once(): void
    {
        $this->fakeVerify();

        $this->get('/payment/callback?reference=alpha-ref-001');
        $this->postWebhook($this->webhookPayload('alpha-ref-001'))->assertOk();

        $this->assertSame(1, Transaction::where('status', 'success')->count());
        Mail::assertQueued(PaymentReceiptMail::class, 1);

        $paidAt = $this->pending->refresh()->paid_at;
        $this->postWebhook($this->webhookPayload('alpha-ref-001'));
        $this->assertEquals($paidAt, $this->pending->refresh()->paid_at, 'paid_at must not be rewritten by a replay');
    }

    public function test_reference_is_unique_at_the_database_level(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        Transaction::create([
            'school_id' => $this->alpha->id,
            'reference' => 'alpha-ref-001', // duplicate
            'amount' => 100,
            'status' => 'pending',
            'email' => 'other@example.test',
        ]);
    }

    public function test_replayed_callback_does_not_grant_receipt_access(): void
    {
        $this->fakeVerify();

        // Legitimate payer settles it (in a session we then discard).
        $this->get('/payment/callback?reference=alpha-ref-001');
        $this->flushSession();

        // A stranger replays the same callback URL, hoping to be handed the receipt.
        $this->get('/payment/callback?reference=alpha-ref-001');
        $this->assertNull(session('last_transaction_id'));

        $this->get('/payment/receipt/'.$this->pending->id)->assertNotFound();
    }

    // =====================================================================
    // G3 — amount / currency verification
    // =====================================================================

    public function test_incorrect_amount_is_rejected(): void
    {
        $this->fakeVerify(['amount' => 100]); // 1 NGN instead of 51,250

        $response = $this->get('/payment/callback?reference=alpha-ref-001');

        $this->pending->refresh();
        $this->assertNotSame('success', $this->pending->status);
        $this->assertSame('mismatch', $this->pending->status);
        $this->assertNull($this->pending->paid_at);
        $response->assertSessionHas('error');
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_underpayment_by_one_kobo_is_rejected(): void
    {
        $this->fakeVerify(['amount' => self::GROSS_KOBO - 1]);

        $this->get('/payment/callback?reference=alpha-ref-001');

        $this->assertSame('mismatch', $this->pending->refresh()->status);
    }

    public function test_incorrect_currency_is_rejected(): void
    {
        $this->fakeVerify(['currency' => 'USD']);

        $response = $this->get('/payment/callback?reference=alpha-ref-001');

        $this->pending->refresh();
        $this->assertSame('mismatch', $this->pending->status);
        $this->assertNull($this->pending->paid_at);
        $response->assertSessionHas('error');
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_webhook_also_rejects_amount_mismatch(): void
    {
        $this->fakeVerify(['amount' => 1]);

        $this->postWebhook($this->webhookPayload('alpha-ref-001'))
            ->assertOk()
            ->assertJson(['status' => PaymentSettlementService::AMOUNT_MISMATCH]);

        $this->assertSame('mismatch', $this->pending->refresh()->status);
    }

    public function test_paystack_reporting_failure_does_not_settle(): void
    {
        $this->fakeVerify(['status' => 'failed']);

        $this->get('/payment/callback?reference=alpha-ref-001')->assertSessionHas('error');

        // H5: a definitive `failed` from Paystack is recorded as failed (never
        // success); nothing is settled, queued or sent.
        $this->assertSame('failed', $this->pending->refresh()->status);
        $this->assertNull($this->pending->paid_at);
        $this->assertDatabaseCount('payouts', 0);
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_browser_cannot_assert_success_without_paystack_agreeing(): void
    {
        // Paystack is the only authority: the query string claims nothing useful.
        $this->fakeVerify(['status' => 'abandoned']);

        $this->get('/payment/callback?reference=alpha-ref-001&status=success&amount=5125000');

        $this->assertSame('pending', $this->pending->refresh()->status);
    }

    public function test_verification_failure_is_transient_and_asks_paystack_to_retry(): void
    {
        Http::fake(['*transaction/verify*' => Http::response(['status' => false, 'message' => 'boom'], 503)]);

        $this->postWebhook($this->webhookPayload('alpha-ref-001'))->assertStatus(500);

        $this->assertSame('pending', $this->pending->refresh()->status);
    }

    // =====================================================================
    // G4 — reference binding / tenant isolation
    // =====================================================================

    public function test_fabricated_metadata_cannot_redirect_payment_to_another_school(): void
    {
        $betaTransaction = Transaction::create([
            'school_id' => $this->beta->id,
            'reference' => 'beta-ref-001',
            'amount' => 999.00,
            'status' => 'pending',
            'email' => 'beta@example.test',
        ]);

        // Paystack echoes attacker-supplied metadata pointing at Beta's school and row.
        $this->fakeVerify([], [
            'transaction_id' => $betaTransaction->id,
            'school_id' => $this->beta->id,
            'school_slug' => 'beta',
            'school_name' => 'Beta School',
        ]);

        $response = $this->get('/payment/callback?reference=alpha-ref-001');

        // Settlement followed the reference, not the metadata.
        $this->assertSame('success', $this->pending->refresh()->status);
        $this->assertSame('pending', $betaTransaction->refresh()->status);
        $this->assertSame($this->alpha->id, $this->pending->school_id);

        // And the redirect went to Alpha, not the metadata's Beta.
        $response->assertRedirect('/s/alpha/payment');
    }

    public function test_metadata_transaction_id_cannot_settle_a_different_row(): void
    {
        $betaTransaction = Transaction::create([
            'school_id' => $this->beta->id,
            'reference' => 'beta-ref-002',
            'amount' => 999.00,
            'status' => 'pending',
            'email' => 'beta@example.test',
        ]);

        $this->fakeVerify([], ['transaction_id' => $betaTransaction->id]);

        $this->postWebhook($this->webhookPayload('alpha-ref-001'))->assertOk();

        $this->assertSame('success', $this->pending->refresh()->status);
        $this->assertSame('pending', $betaTransaction->refresh()->status);
    }

    public function test_unknown_reference_settles_nothing(): void
    {
        $this->fakeVerify();

        $this->postWebhook($this->webhookPayload('no-such-reference'))
            ->assertOk()
            ->assertJson(['status' => PaymentSettlementService::NOT_FOUND]);

        $this->assertSame('pending', $this->pending->refresh()->status);
        $this->assertSame(0, Transaction::where('status', 'success')->count());
    }

    public function test_callback_without_a_reference_is_handled_safely(): void
    {
        $this->get('/payment/callback')->assertRedirect(route('payment.index'));

        $this->assertSame('pending', $this->pending->refresh()->status);
    }

    // =====================================================================
    // G1 — webhook signature
    // =====================================================================

    public function test_webhook_rejects_a_missing_signature(): void
    {
        $this->postWebhook($this->webhookPayload('alpha-ref-001'), '')->assertStatus(401);

        $this->assertSame('pending', $this->pending->refresh()->status);
    }

    public function test_webhook_rejects_an_invalid_signature(): void
    {
        $this->postWebhook($this->webhookPayload('alpha-ref-001'), str_repeat('a', 128))
            ->assertStatus(401);

        $this->assertSame('pending', $this->pending->refresh()->status);
    }

    public function test_webhook_rejects_a_signature_for_a_different_body(): void
    {
        // Valid HMAC, but computed over a body the attacker then swapped out.
        $signed = $this->webhookPayload('some-other-reference');
        $signature = hash_hmac('sha512', $signed, 'sk_test_secret');

        $this->postWebhook($this->webhookPayload('alpha-ref-001'), $signature)->assertStatus(401);

        $this->assertSame('pending', $this->pending->refresh()->status);
    }

    public function test_webhook_rejects_a_signature_made_with_the_wrong_secret(): void
    {
        $body = $this->webhookPayload('alpha-ref-001');

        $this->postWebhook($body, hash_hmac('sha512', $body, 'sk_wrong_secret'))->assertStatus(401);

        $this->assertSame('pending', $this->pending->refresh()->status);
    }

    public function test_webhook_ignores_non_charge_events(): void
    {
        $this->fakeVerify();

        // transfer.* events now drive the payout state machine, so use an event the
        // application genuinely does not handle.
        $this->postWebhook($this->webhookPayload('alpha-ref-001', 'subscription.create'))
            ->assertOk()
            ->assertJson(['status' => 'ignored']);

        $this->assertSame('pending', $this->pending->refresh()->status);
    }

    public function test_webhook_route_is_csrf_exempt(): void
    {
        // A missing CSRF token must produce the signature rejection, not a 419.
        $this->postWebhook($this->webhookPayload('alpha-ref-001'), 'bad')->assertStatus(401);
    }

    public function test_webhook_is_a_post_only_endpoint(): void
    {
        $this->get('/paystack/webhook')->assertStatus(405);
    }
}
