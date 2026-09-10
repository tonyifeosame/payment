<?php

namespace Tests\Feature;

use App\Jobs\InitiateSchoolPayout;
use App\Models\Payout;
use App\Models\School;
use App\Models\Transaction;
use App\Services\PaymentSettlementService;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Model A immediate payouts — E2, E3, E4, E4b, E5.
 *
 * Base 50,000 + 2.5% platform markup = 51,250 charged.
 * The school is owed 50,000; the 1,250 markup belongs to the platform.
 */
class PayoutTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 50000.00;

    private const MARKUP = 1250.00;

    private const GROSS = 51250.00;

    private const GROSS_KOBO = 5125000;

    /** What the school is owed, in kobo — the amount a transfer must report. */
    private const BASE_KOBO = 5000000;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        config(['services.paystack.secret_key' => 'sk_test_secret']);

        $this->school = $this->makeSchool('Greenfield Academy', 'greenfield');
    }

    private function makeSchool(string $name, string $slug): School
    {
        $school = School::create([
            'name' => $name, 'slug' => $slug, 'email' => $slug.'@example.test',
            'admin_password' => Hash::make('password123'),
            'account_number' => '0123456789', 'bank' => 'GTB',
            'bank_code' => '058', 'account_name' => 'Acct '.$name,
        ]);
        $school->paystack_recipient_code = 'RCP_'.$slug;
        $school->save();

        return $school;
    }

    private function pendingTransaction(?School $school = null, string $reference = 'pay-ref-001'): Transaction
    {
        return Transaction::create([
            'school_id' => ($school ?? $this->school)->id,
            'reference' => $reference,
            'amount' => self::GROSS,
            'status' => 'pending',
            'email' => 'parent@example.test',
            'name' => 'Ada Parent',
            'meta_data' => [
                'quantity' => 1,
                'base_amount' => self::BASE,
                'markup_amount' => self::MARKUP,
                'gross_amount' => self::GROSS,
            ],
        ]);
    }

    private function fakeVerify(string $reference = 'pay-ref-001'): array
    {
        return ['*transaction/verify*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'channel' => 'card', 'reference' => $reference,
            'amount' => self::GROSS_KOBO, 'currency' => 'NGN',
        ]], 200)];
    }

    private function settle(string $reference = 'pay-ref-001'): array
    {
        return app(PaymentSettlementService::class)->settleByReference($reference);
    }

    private function webhookPayload(string $event, string $reference, array $data = []): string
    {
        return json_encode(['event' => $event, 'data' => array_merge(['reference' => $reference], $data)]);
    }

    private function postWebhook(string $body)
    {
        return $this->call('POST', '/paystack/webhook', [], [], [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_secret'),
             'CONTENT_TYPE' => 'application/json'], $body);
    }

    // =====================================================================
    // E2 — payout amount correctness
    // =====================================================================

    public function test_payout_amount_is_the_schools_share_not_the_gross(): void
    {
        Queue::fake();
        Http::fake($this->fakeVerify());
        $t = $this->pendingTransaction();

        $this->settle();

        $payout = Payout::firstOrFail();
        $this->assertEquals(self::BASE, (float) $payout->amount, 'the platform markup was transferred to the school');
        $this->assertNotEquals(self::GROSS, (float) $payout->amount);
        $this->assertEquals(self::GROSS, (float) $t->refresh()->amount, 'the amount charged must not change');
    }

    public function test_platform_fee_is_retained(): void
    {
        Queue::fake();
        Http::fake($this->fakeVerify());
        $this->pendingTransaction();

        $this->settle();

        $charged = self::GROSS;
        $paidOut = (float) Payout::firstOrFail()->amount;
        $this->assertEquals(self::MARKUP, round($charged - $paidOut, 2), 'platform fee retained does not match the markup');
    }

    public function test_payout_never_exceeds_the_amount_collected(): void
    {
        Queue::fake();
        // Metadata claims a base larger than what was actually charged.
        $t = Transaction::create([
            'school_id' => $this->school->id, 'reference' => 'odd-ref',
            'amount' => 1000.00, 'status' => 'pending', 'email' => 'p@example.test',
            'meta_data' => ['quantity' => 1, 'base_amount' => 9999],
        ]);
        Http::fake(['*transaction/verify*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'channel' => 'card', 'reference' => 'odd-ref',
            'amount' => 100000, 'currency' => 'NGN',
        ]], 200)]);

        $this->settle('odd-ref');

        $this->assertEquals(1000.00, (float) Payout::firstOrFail()->amount);
    }

    // =====================================================================
    // E5 — a settled payment creates exactly one payout and queues it
    // =====================================================================

    public function test_successful_payment_creates_exactly_one_payout(): void
    {
        Queue::fake();
        Http::fake($this->fakeVerify());
        $t = $this->pendingTransaction();

        $this->settle();

        $this->assertSame(1, Payout::count());
        $payout = Payout::firstOrFail();
        $this->assertSame($t->id, $payout->transaction_id);
        $this->assertSame($this->school->id, $payout->school_id);
        $this->assertSame(Payout::PENDING, $payout->status);
        $this->assertNotNull($payout->reference);
    }

    public function test_successful_payment_dispatches_the_payout_job(): void
    {
        Queue::fake();
        Http::fake($this->fakeVerify());
        $this->pendingTransaction();

        $this->settle();

        $payout = Payout::firstOrFail();
        Queue::assertPushed(InitiateSchoolPayout::class, fn ($job) => $job->payoutId === $payout->id);
    }

    public function test_a_failed_payment_creates_no_payout(): void
    {
        Queue::fake();
        Http::fake(['*transaction/verify*' => Http::response(['status' => true, 'data' => [
            'status' => 'failed', 'reference' => 'pay-ref-001',
        ]], 200)]);
        $this->pendingTransaction();

        $this->settle();

        $this->assertSame(0, Payout::count());
        Queue::assertNothingPushed();
    }

    // =====================================================================
    // E3 — duplicate protection
    // =====================================================================

    public function test_duplicate_settlement_does_not_create_another_payout(): void
    {
        Queue::fake();
        Http::fake($this->fakeVerify());
        $this->pendingTransaction();

        $this->settle();
        $this->settle();
        $this->settle();

        $this->assertSame(1, Payout::count());
        Queue::assertPushed(InitiateSchoolPayout::class, 1);
    }

    public function test_payment_replay_through_callback_and_webhook_creates_one_payout(): void
    {
        Queue::fake();
        Http::fake($this->fakeVerify());
        $this->pendingTransaction();

        $this->get('/payment/callback?reference=pay-ref-001');
        $this->postWebhook($this->webhookPayload('charge.success', 'pay-ref-001'));
        $this->get('/payment/callback?reference=pay-ref-001');

        $this->assertSame(1, Payout::count());
        Queue::assertPushed(InitiateSchoolPayout::class, 1);
    }

    public function test_callback_and_webhook_race_creates_one_payout(): void
    {
        Queue::fake();
        $call = 0;
        Http::fake(function () use (&$call) {
            $call++;
            if ($call === 1) {
                // The webhook settles while the callback is still verifying.
                $this->postWebhook($this->webhookPayload('charge.success', 'pay-ref-001'));
            }

            return Http::response(['status' => true, 'data' => [
                'status' => 'success', 'channel' => 'card', 'reference' => 'pay-ref-001',
                'amount' => self::GROSS_KOBO, 'currency' => 'NGN',
            ]], 200);
        });
        $this->pendingTransaction();

        $this->get('/payment/callback?reference=pay-ref-001');

        $this->assertSame(1, Payout::count());
        Queue::assertPushed(InitiateSchoolPayout::class, 1);
    }

    public function test_transfer_reference_is_unique(): void
    {
        Queue::fake();
        Http::fake($this->fakeVerify());
        $this->pendingTransaction();
        $this->settle();

        $this->expectException(\Illuminate\Database\QueryException::class);

        Payout::create([
            'school_id' => $this->school->id,
            'reference' => Payout::firstOrFail()->reference, // duplicate
            'amount' => 1, 'currency' => 'NGN', 'status' => Payout::PENDING,
        ]);
    }

    public function test_one_payout_per_transaction_is_enforced_by_the_database(): void
    {
        Queue::fake();
        Http::fake($this->fakeVerify());
        $t = $this->pendingTransaction();
        $this->settle();

        $this->expectException(\Illuminate\Database\QueryException::class);

        Payout::create([
            'school_id' => $this->school->id, 'transaction_id' => $t->id, // duplicate
            'reference' => 'PO-other', 'amount' => 1, 'currency' => 'NGN', 'status' => Payout::PENDING,
        ]);
    }

    public function test_running_the_job_twice_creates_only_one_transfer(): void
    {
        Http::fake([
            '*transaction/verify*' => Http::response(['status' => true, 'data' => [
                'status' => 'success', 'channel' => 'card', 'reference' => 'pay-ref-001',
                'amount' => self::GROSS_KOBO, 'currency' => 'NGN']], 200),
            '*/transfer' => Http::response(['status' => true, 'data' => [
                'transfer_code' => 'TRF_1', 'id' => 9, 'status' => 'pending']], 200),
        ]);
        $this->pendingTransaction();
        $this->settle();
        $payout = Payout::firstOrFail();

        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(\App\Services\PaystackService::class));
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(\App\Services\PaystackService::class));
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(\App\Services\PaystackService::class));

        $transfers = 0;
        Http::assertSent(function ($r) use (&$transfers) {
            if (str_ends_with($r->url(), '/transfer') && $r->method() === 'POST') {
                $transfers++;
            }

            return true;
        });

        $this->assertSame(1, $transfers, 'the job created more than one transfer');
        $this->assertSame(1, $payout->refresh()->attempts);
    }

    public function test_transfer_carries_our_reference_as_the_idempotency_key(): void
    {
        Http::fake([
            '*transaction/verify*' => Http::response(['status' => true, 'data' => [
                'status' => 'success', 'channel' => 'card', 'reference' => 'pay-ref-001',
                'amount' => self::GROSS_KOBO, 'currency' => 'NGN']], 200),
            '*/transfer' => Http::response(['status' => true, 'data' => [
                'transfer_code' => 'TRF_1', 'id' => 9, 'status' => 'pending']], 200),
        ]);
        $this->pendingTransaction();
        $this->settle();
        $payout = Payout::firstOrFail();

        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(\App\Services\PaystackService::class));

        Http::assertSent(function ($r) use ($payout) {
            if (! str_ends_with($r->url(), '/transfer') || $r->method() !== 'POST') {
                return false;
            }

            return ($r->data()['reference'] ?? null) === $payout->reference
                && (int) $r->data()['amount'] === (int) round(self::BASE * 100);
        });
    }

    // =====================================================================
    // Transfer status — never successful just because a request was sent
    // =====================================================================

    private function settleAndRunPayout(array $transferResponse): Payout
    {
        Http::fake(array_merge([
            '*transaction/verify*' => Http::response(['status' => true, 'data' => [
                'status' => 'success', 'channel' => 'card', 'reference' => 'pay-ref-001',
                'amount' => self::GROSS_KOBO, 'currency' => 'NGN']], 200),
        ], $transferResponse));

        $this->pendingTransaction();
        $this->settle();
        $payout = Payout::firstOrFail();
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(\App\Services\PaystackService::class));

        return $payout->refresh();
    }

    public function test_accepted_transfer_is_processing_not_success(): void
    {
        $payout = $this->settleAndRunPayout(['*/transfer' => Http::response(['status' => true, 'data' => [
            'transfer_code' => 'TRF_1', 'id' => 9, 'status' => 'pending']], 200)]);

        $this->assertSame(Payout::PROCESSING, $payout->status, 'a 200 from the API must never mean success');
        $this->assertSame('TRF_1', $payout->transfer_code);
        $this->assertNull($payout->completed_at);
    }

    public function test_transfer_success_webhook_marks_the_payout_successful(): void
    {
        $payout = $this->settleAndRunPayout(['*/transfer' => Http::response(['status' => true, 'data' => [
            'transfer_code' => 'TRF_1', 'id' => 9, 'status' => 'pending']], 200)]);

        $this->postWebhook($this->webhookPayload('transfer.success', $payout->reference, ['status' => 'success', 'amount' => self::BASE_KOBO, 'currency' => 'NGN']))
            ->assertOk();

        $payout->refresh();
        $this->assertSame(Payout::SUCCESS, $payout->status);
        $this->assertNotNull($payout->completed_at);
    }

    public function test_transfer_failed_webhook_marks_the_payout_failed(): void
    {
        $payout = $this->settleAndRunPayout(['*/transfer' => Http::response(['status' => true, 'data' => [
            'transfer_code' => 'TRF_1', 'id' => 9, 'status' => 'pending']], 200)]);

        $this->postWebhook($this->webhookPayload('transfer.failed', $payout->reference, ['status' => 'failed']))
            ->assertOk();

        $this->assertSame(Payout::FAILED, $payout->refresh()->status);
    }

    public function test_transfer_reversed_webhook_marks_the_payout_reversed(): void
    {
        $payout = $this->settleAndRunPayout(['*/transfer' => Http::response(['status' => true, 'data' => [
            'transfer_code' => 'TRF_1', 'id' => 9, 'status' => 'pending']], 200)]);

        $this->postWebhook($this->webhookPayload('transfer.reversed', $payout->reference, ['status' => 'reversed']))
            ->assertOk();

        $this->assertSame(Payout::REVERSED, $payout->refresh()->status);
    }

    public function test_transfer_webhook_is_idempotent(): void
    {
        $payout = $this->settleAndRunPayout(['*/transfer' => Http::response(['status' => true, 'data' => [
            'transfer_code' => 'TRF_1', 'id' => 9, 'status' => 'pending']], 200)]);

        $body = $this->webhookPayload('transfer.success', $payout->reference, ['status' => 'success', 'amount' => self::BASE_KOBO, 'currency' => 'NGN']);
        $this->postWebhook($body)->assertOk();
        $completedAt = $payout->refresh()->completed_at;

        $this->postWebhook($body)->assertOk();
        $this->postWebhook($body)->assertOk();

        $payout->refresh();
        $this->assertSame(Payout::SUCCESS, $payout->status);
        $this->assertEquals($completedAt, $payout->completed_at, 'a redelivery rewrote the completion time');
    }

    public function test_a_terminal_payout_is_never_moved_backwards(): void
    {
        $payout = $this->settleAndRunPayout(['*/transfer' => Http::response(['status' => true, 'data' => [
            'transfer_code' => 'TRF_1', 'id' => 9, 'status' => 'pending']], 200)]);

        $this->postWebhook($this->webhookPayload('transfer.success', $payout->reference, ['status' => 'success', 'amount' => self::BASE_KOBO, 'currency' => 'NGN']));
        $this->postWebhook($this->webhookPayload('transfer.failed', $payout->reference, ['status' => 'failed']));

        $this->assertSame(Payout::SUCCESS, $payout->refresh()->status, 'a late failure event downgraded a completed payout');
    }

    public function test_transfer_webhook_for_an_unknown_reference_is_acknowledged(): void
    {
        $this->postWebhook($this->webhookPayload('transfer.success', 'PO-nonexistent', ['status' => 'success', 'amount' => self::BASE_KOBO, 'currency' => 'NGN']))
            ->assertOk()
            ->assertJson(['status' => 'not_found']);
    }

    // =====================================================================
    // E4 — transfer failure never touches the payment
    // =====================================================================

    public function test_rejected_transfer_leaves_the_payment_successful(): void
    {
        $payout = $this->settleAndRunPayout(['*/transfer' => Http::response(
            ['status' => false, 'message' => 'Insufficient balance'], 400)]);

        $this->assertSame(Payout::FAILED, $payout->status);
        $this->assertSame('success', Transaction::firstOrFail()->status, 'a failed transfer changed the payment');
        $this->assertStringContainsString('Insufficient balance', (string) $payout->last_error);
    }

    public function test_a_failed_payout_is_retryable_by_resetting_it(): void
    {
        // Http::fake() merges stubs, so the first-match-wins order is set up once:
        // the first transfer attempt is refused, the second is accepted.
        Http::fake([
            '*transaction/verify*' => Http::response(['status' => true, 'data' => [
                'status' => 'success', 'channel' => 'card', 'reference' => 'pay-ref-001',
                'amount' => self::GROSS_KOBO, 'currency' => 'NGN']], 200),
            '*/transfer' => Http::sequence()
                ->push(['status' => false, 'message' => 'Insufficient balance'], 400)
                ->push(['status' => true, 'data' => [
                    'transfer_code' => 'TRF_2', 'id' => 10, 'status' => 'pending']], 200),
        ]);

        $this->pendingTransaction();
        $this->settle();
        $payout = Payout::firstOrFail();

        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(\App\Services\PaystackService::class));
        $this->assertSame(Payout::FAILED, $payout->refresh()->status);

        // Re-running the job alone must NOT silently re-send.
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(\App\Services\PaystackService::class));
        $this->assertSame(Payout::FAILED, $payout->refresh()->status);
        $this->assertSame(1, $payout->attempts, 'a failed payout re-sent itself without an operator resetting it');

        // An operator resets it, and it goes through again.
        $payout->forceFill(['status' => Payout::PENDING])->save();
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(\App\Services\PaystackService::class));

        $this->assertSame(Payout::PROCESSING, $payout->refresh()->status);
        $this->assertSame(2, $payout->attempts);
    }

    public function test_missing_recipient_fails_the_payout_but_not_the_payment(): void
    {
        $this->school->forceFill(['paystack_recipient_code' => null, 'bank_code' => null])->save();

        $payout = $this->settleAndRunPayout([]);

        $this->assertSame(Payout::FAILED, $payout->status);
        $this->assertStringContainsString('Recipient', (string) $payout->last_error);
        $this->assertSame('success', Transaction::firstOrFail()->status);
    }

    // =====================================================================
    // Ambiguous outcome — never blindly re-send
    // =====================================================================

    public function test_ambiguous_transfer_response_parks_the_payout_without_retrying(): void
    {
        $payout = $this->settleAndRunPayout(['*/transfer' => Http::response(
            ['status' => false, 'message' => 'gateway timeout'], 503)]);

        $this->assertSame(Payout::INITIATING, $payout->status, 'an unknown outcome must not be treated as failure');

        // Re-running must look the transfer up, not create another one.
        Http::fake([
            '*transfer/verify*' => Http::response(['status' => true, 'data' => [
                'status' => 'success', 'transfer_code' => 'TRF_X', 'id' => 11,
                'amount' => self::BASE_KOBO, 'currency' => 'NGN']], 200),
        ]);
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(\App\Services\PaystackService::class));

        $posted = 0;
        Http::assertSent(function ($r) use (&$posted) {
            if (str_ends_with($r->url(), '/transfer') && $r->method() === 'POST') {
                $posted++;
            }

            return true;
        });
        $this->assertSame(0, $posted, 'a second transfer was created for an ambiguous outcome');
        $this->assertSame(Payout::SUCCESS, $payout->refresh()->status);
    }

    public function test_unresolvable_lookup_leaves_the_payout_parked(): void
    {
        $payout = $this->settleAndRunPayout(['*/transfer' => Http::response(['status' => false], 503)]);
        $this->assertSame(Payout::INITIATING, $payout->status);

        Http::fake(['*transfer/verify*' => Http::response(['status' => false], 500)]);
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(\App\Services\PaystackService::class));

        $this->assertSame(Payout::INITIATING, $payout->refresh()->status, 'an unresolved payout must stay parked');
    }

    public function test_lookup_proving_no_transfer_exists_releases_the_payout(): void
    {
        $payout = $this->settleAndRunPayout(['*/transfer' => Http::response(['status' => false], 503)]);
        $this->assertSame(Payout::INITIATING, $payout->status);

        Http::fake(['*transfer/verify*' => Http::response(['status' => false, 'message' => 'Transfer not found'], 404)]);
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(\App\Services\PaystackService::class));

        $this->assertSame(Payout::FAILED, $payout->refresh()->status);
    }

    // =====================================================================
    // E4b — one school's failure never blocks another
    // =====================================================================

    public function test_school_a_transfer_failure_does_not_stop_school_b(): void
    {
        $beta = $this->makeSchool('Beta School', 'beta');

        Http::fake(function ($request) {
            if (str_contains($request->url(), 'transaction/verify')) {
                $ref = str_contains($request->url(), 'beta') ? 'beta-ref' : 'pay-ref-001';

                return Http::response(['status' => true, 'data' => [
                    'status' => 'success', 'channel' => 'card', 'reference' => $ref,
                    'amount' => self::GROSS_KOBO, 'currency' => 'NGN']], 200);
            }

            // Greenfield's recipient blows up; Beta's succeeds.
            if (($request->data()['recipient'] ?? '') === 'RCP_greenfield') {
                return Http::response(['status' => false, 'message' => 'Recipient blocked'], 400);
            }

            return Http::response(['status' => true, 'data' => [
                'transfer_code' => 'TRF_B', 'id' => 22, 'status' => 'pending']], 200);
        });

        $this->pendingTransaction($this->school, 'pay-ref-001');
        $this->pendingTransaction($beta, 'beta-ref');
        $this->settle('pay-ref-001');
        $this->settle('beta-ref');

        $this->assertSame(2, Payout::count());

        foreach (Payout::orderBy('id')->get() as $payout) {
            (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(\App\Services\PaystackService::class));
        }

        $alphaPayout = Payout::where('school_id', $this->school->id)->firstOrFail();
        $betaPayout = Payout::where('school_id', $beta->id)->firstOrFail();

        $this->assertSame(Payout::FAILED, $alphaPayout->status);
        $this->assertSame(Payout::PROCESSING, $betaPayout->status, "School B's payout was blocked by School A's failure");
        $this->assertSame('success', Transaction::where('reference', 'pay-ref-001')->first()->status);
        $this->assertSame('success', Transaction::where('reference', 'beta-ref')->first()->status);
    }

    // =====================================================================
    // Reconciliation command
    // =====================================================================

    public function test_reconciliation_creates_obligations_for_historical_payments(): void
    {
        Bus::fake();

        // A settled payment from before immediate payouts existed.
        Transaction::create([
            'school_id' => $this->school->id, 'reference' => 'legacy-ref', 'amount' => self::GROSS,
            'status' => 'success', 'email' => 'p@example.test',
            'meta_data' => ['quantity' => 1, 'base_amount' => self::BASE, 'markup_amount' => self::MARKUP],
        ]);

        $this->artisan('payouts:run --dispatch')->assertExitCode(0);

        $this->assertSame(1, Payout::count());
        $this->assertEquals(self::BASE, (float) Payout::firstOrFail()->amount);
        Bus::assertDispatched(InitiateSchoolPayout::class, 1);
    }

    public function test_reconciliation_never_double_pays_an_existing_payout(): void
    {
        Bus::fake();
        Queue::fake();
        Http::fake($this->fakeVerify());
        $this->pendingTransaction();
        $this->settle();
        $this->assertSame(1, Payout::count());

        // Settlement itself dispatched one job; reconciliation must add nothing.
        Bus::assertDispatchedTimes(InitiateSchoolPayout::class, 1);

        $this->artisan('payouts:run --dispatch')->assertExitCode(0);
        $this->artisan('payouts:run --dispatch')->assertExitCode(0);

        $this->assertSame(1, Payout::count(), 'reconciliation created a duplicate payout');
        Bus::assertDispatchedTimes(InitiateSchoolPayout::class, 1);
    }

    public function test_reconciliation_dry_run_changes_nothing(): void
    {
        Transaction::create([
            'school_id' => $this->school->id, 'reference' => 'legacy-ref', 'amount' => self::GROSS,
            'status' => 'success', 'email' => 'p@example.test',
            'meta_data' => ['base_amount' => self::BASE],
        ]);

        $this->artisan('payouts:run --dry-run')->assertExitCode(0);

        $this->assertSame(0, Payout::count());
    }

    public function test_reconciliation_ignores_unsettled_payments(): void
    {
        Bus::fake();
        $this->pendingTransaction(); // still pending

        $this->artisan('payouts:run --dispatch')->assertExitCode(0);

        $this->assertSame(0, Payout::count());
    }
}
