<?php

namespace Tests\Feature;

use App\Jobs\InitiateSchoolPayout;
use App\Models\Payout;
use App\Models\School;
use App\Models\Transaction;
use App\Services\PaymentSettlementService;
use App\Services\PaystackService;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Regressions for the eight findings raised in the payout adversarial audit:
 * HIGH-1 reversals, HIGH-2 transfer validation, HIGH-3 untrustworthy fee splits,
 * HIGH-4 worker config, MEDIUM-1 PostgreSQL-safe transactions, MEDIUM-2 transfers
 * outside the payment transaction, MEDIUM-3 durable obligations, LOW-1 uniqueness.
 */
class PayoutHardeningTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 50000.00;

    private const BASE_KOBO = 5000000;

    private const GROSS = 51250.00;

    private const GROSS_KOBO = 5125000;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        config(['services.paystack.secret_key' => 'sk_test_secret']);

        $this->school = School::create([
            'name' => 'Greenfield', 'slug' => 'greenfield', 'email' => 'g@example.test',
            'admin_password' => Hash::make('password123'), 'account_number' => '0123456789',
            'bank' => 'GTB', 'bank_code' => '058', 'account_name' => 'Acct',
        ]);
        $this->school->paystack_recipient_code = 'RCP_g';
        $this->school->save();
    }

    private function txn(?array $meta = ['quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 1250], string $ref = 'pref'): Transaction
    {
        return Transaction::create([
            'school_id' => $this->school->id, 'reference' => $ref, 'amount' => self::GROSS,
            'status' => 'pending', 'email' => 'p@example.test', 'meta_data' => $meta,
        ]);
    }

    private function fakeAll(): void
    {
        Http::fake([
            '*transaction/verify*' => Http::response(['status' => true, 'data' => [
                'status' => 'success', 'channel' => 'card', 'reference' => 'pref',
                'amount' => self::GROSS_KOBO, 'currency' => 'NGN']], 200),
            '*/transfer' => Http::response(['status' => true, 'data' => [
                'transfer_code' => 'TRF_1', 'id' => 9, 'status' => 'pending']], 200),
        ]);
    }

    private function webhook(string $event, array $data)
    {
        $body = json_encode(['event' => $event, 'data' => $data]);

        return $this->call('POST', '/paystack/webhook', [], [], [],
            ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_secret'),
             'CONTENT_TYPE' => 'application/json'], $body);
    }

    private function processingPayout(): Payout
    {
        $this->fakeAll();
        $this->txn();
        app(PaymentSettlementService::class)->settleByReference('pref');
        $p = Payout::firstOrFail();
        (new InitiateSchoolPayout($p->id))->handle(app(PayoutService::class), app(PaystackService::class));

        return $p->refresh();
    }

    private function successfulPayout(): Payout
    {
        $p = $this->processingPayout();
        $this->webhook('transfer.success', ['reference' => $p->reference, 'status' => 'success',
            'amount' => self::BASE_KOBO, 'currency' => 'NGN']);

        return $p->refresh();
    }

    // =====================================================================
    // HIGH-1 — reversals
    // =====================================================================

    public function test_HIGH1_success_can_be_reversed(): void
    {
        $p = $this->successfulPayout();
        $this->assertSame(Payout::SUCCESS, $p->status);

        $this->webhook('transfer.reversed', ['reference' => $p->reference, 'status' => 'reversed',
            'amount' => self::BASE_KOBO, 'currency' => 'NGN'])->assertOk();

        $this->assertSame(Payout::REVERSED, $p->refresh()->status, 'a genuine reversal was dropped');
        $this->assertNotNull($p->completed_at);
    }

    public function test_HIGH1_success_cannot_become_failed(): void
    {
        $p = $this->successfulPayout();

        $this->webhook('transfer.failed', ['reference' => $p->reference, 'status' => 'failed'])->assertOk();

        $this->assertSame(Payout::SUCCESS, $p->refresh()->status, 'a late failure downgraded a completed payout');
    }

    public function test_HIGH1_reversed_cannot_be_resurrected(): void
    {
        $p = $this->successfulPayout();
        $this->webhook('transfer.reversed', ['reference' => $p->reference, 'status' => 'reversed',
            'amount' => self::BASE_KOBO, 'currency' => 'NGN']);
        $this->assertSame(Payout::REVERSED, $p->refresh()->status);

        foreach (['success', 'failed', 'pending'] as $status) {
            $this->webhook('transfer.'.$status, ['reference' => $p->reference, 'status' => $status,
                'amount' => self::BASE_KOBO, 'currency' => 'NGN'])->assertOk();
            $this->assertSame(Payout::REVERSED, $p->refresh()->status, "reversed was resurrected to {$status}");
        }
    }

    public function test_HIGH1_reversal_is_idempotent(): void
    {
        $p = $this->successfulPayout();
        $body = ['reference' => $p->reference, 'status' => 'reversed', 'amount' => self::BASE_KOBO, 'currency' => 'NGN'];

        $this->webhook('transfer.reversed', $body);
        $completedAt = $p->refresh()->completed_at;
        $this->webhook('transfer.reversed', $body);

        $this->assertSame(Payout::REVERSED, $p->refresh()->status);
        $this->assertEquals($completedAt, $p->refresh()->completed_at);
    }

    public function test_HIGH1_transition_table_is_explicit(): void
    {
        $p = new Payout(['status' => Payout::SUCCESS]);
        $this->assertTrue($p->canTransitionTo(Payout::REVERSED));
        $this->assertFalse($p->canTransitionTo(Payout::FAILED));
        $this->assertFalse($p->canTransitionTo(Payout::PROCESSING));

        $r = new Payout(['status' => Payout::REVERSED]);
        $this->assertTrue($r->isTerminal());
        foreach ([Payout::SUCCESS, Payout::FAILED, Payout::PENDING, Payout::PROCESSING] as $t) {
            $this->assertFalse($r->canTransitionTo($t));
        }
    }

    // =====================================================================
    // HIGH-2 — transfer.success validation
    // =====================================================================

    public function test_HIGH2_correct_amount_and_currency_is_accepted(): void
    {
        $p = $this->processingPayout();

        $this->webhook('transfer.success', ['reference' => $p->reference, 'status' => 'success',
            'amount' => self::BASE_KOBO, 'currency' => 'NGN'])->assertOk();

        $this->assertSame(Payout::SUCCESS, $p->refresh()->status);
    }

    public function test_HIGH2_wrong_amount_is_rejected(): void
    {
        $p = $this->processingPayout();

        $this->webhook('transfer.success', ['reference' => $p->reference, 'status' => 'success',
            'amount' => 1, 'currency' => 'NGN'])->assertOk();

        $p->refresh();
        $this->assertNotSame(Payout::SUCCESS, $p->status, 'a 1-kobo transfer was recorded as full payment');
        $this->assertSame(Payout::NEEDS_REVIEW, $p->status);
        $this->assertStringContainsString('amount mismatch', (string) $p->last_error);
        $this->assertNull($p->completed_at);
    }

    public function test_HIGH2_wrong_currency_is_rejected(): void
    {
        $p = $this->processingPayout();

        $this->webhook('transfer.success', ['reference' => $p->reference, 'status' => 'success',
            'amount' => self::BASE_KOBO, 'currency' => 'USD'])->assertOk();

        $p->refresh();
        $this->assertSame(Payout::NEEDS_REVIEW, $p->status);
        $this->assertStringContainsString('currency mismatch', (string) $p->last_error);
    }

    public function test_HIGH2_missing_amount_is_rejected(): void
    {
        $p = $this->processingPayout();

        $this->webhook('transfer.success', ['reference' => $p->reference, 'status' => 'success'])->assertOk();

        $this->assertSame(Payout::NEEDS_REVIEW, $p->refresh()->status);
    }

    public function test_HIGH2_a_payout_needing_review_is_never_transferable(): void
    {
        $p = $this->processingPayout();
        $this->webhook('transfer.success', ['reference' => $p->reference, 'status' => 'success', 'amount' => 1]);
        $this->assertSame(Payout::NEEDS_REVIEW, $p->refresh()->status);

        (new InitiateSchoolPayout($p->id))->handle(app(PayoutService::class), app(PaystackService::class));

        $posts = 0;
        Http::assertSent(function ($r) use (&$posts) {
            if (str_ends_with($r->url(), '/transfer') && $r->method() === 'POST') {
                $posts++;
            }

            return true;
        });
        $this->assertSame(1, $posts, 'a payout under review initiated another transfer');
        $this->assertSame(Payout::NEEDS_REVIEW, $p->refresh()->status);
    }

    // =====================================================================
    // HIGH-3 — untrustworthy fee split
    // =====================================================================

    public function test_HIGH3_missing_metadata_never_transfers_the_gross(): void
    {
        Queue::fake();
        Http::fake(['*transaction/verify*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'channel' => 'card', 'reference' => 'pref',
            'amount' => self::GROSS_KOBO, 'currency' => 'NGN']], 200)]);

        $this->txn(null); // charged 51,250 with no breakdown at all
        app(PaymentSettlementService::class)->settleByReference('pref');

        $payout = Payout::firstOrFail();
        $this->assertSame(Payout::NEEDS_REVIEW, $payout->status);
        $this->assertEquals(0.0, (float) $payout->amount, 'a transferable amount was guessed');
        $this->assertNotEquals(self::GROSS, (float) $payout->amount);
        $this->assertStringContainsString('base_amount', (string) $payout->last_error);

        // Nothing may be queued for it.
        Queue::assertNothingPushed();
        $this->assertSame('success', Transaction::firstOrFail()->status);
    }

    public function test_HIGH3_review_payout_cannot_be_claimed_for_transfer(): void
    {
        Queue::fake();
        Http::fake(['*transaction/verify*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'channel' => 'card', 'reference' => 'pref',
            'amount' => self::GROSS_KOBO, 'currency' => 'NGN']], 200)]);
        $this->txn(null);
        app(PaymentSettlementService::class)->settleByReference('pref');
        $payout = Payout::firstOrFail();

        $this->assertFalse(app(PayoutService::class)->claimForTransfer($payout));

        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(PaystackService::class));

        Http::assertNotSent(fn ($r) => str_ends_with($r->url(), '/transfer') && $r->method() === 'POST');
    }

    public function test_HIGH3_dry_run_identifies_unattributable_transactions(): void
    {
        Transaction::create([
            'school_id' => $this->school->id, 'reference' => 'legacy', 'amount' => self::GROSS,
            'status' => 'success', 'email' => 'p@example.test', 'meta_data' => null,
        ]);

        $this->artisan('payouts:run --dry-run')
            ->expectsOutputToContain('UNKNOWN')
            ->assertExitCode(0);

        $this->assertSame(0, Payout::count());
    }

    public function test_HIGH3_reconciliation_reports_payouts_needing_review(): void
    {
        Bus::fake();
        Transaction::create([
            'school_id' => $this->school->id, 'reference' => 'legacy', 'amount' => self::GROSS,
            'status' => 'success', 'email' => 'p@example.test', 'meta_data' => null,
        ]);

        $this->artisan('payouts:run --dispatch')->assertExitCode(0);
        $this->assertSame(Payout::NEEDS_REVIEW, Payout::firstOrFail()->status);
        Bus::assertNotDispatched(InitiateSchoolPayout::class);

        // A later run surfaces it again rather than letting it go quiet.
        $this->artisan('payouts:run')->expectsOutputToContain('need manual review')->assertExitCode(0);
        $this->assertSame(1, Payout::count());
    }

    // =====================================================================
    // MEDIUM-2 — the transfer must never run inside the payment transaction
    // =====================================================================

    public function test_MEDIUM2_transfer_happens_only_after_the_settlement_commits(): void
    {
        config(['queue.default' => 'sync']); // worst case: dispatch runs the job inline

        // RefreshDatabase wraps each test in its own transaction, so the baseline is
        // 1, not 0. What matters is that the transfer adds no nesting on top of it.
        $baselineLevel = DB::transactionLevel();
        $levelDuringTransfer = null;
        $paymentStatusDuringTransfer = null;

        Http::fake(function ($request) use (&$levelDuringTransfer, &$paymentStatusDuringTransfer) {
            if (str_contains($request->url(), 'transaction/verify')) {
                return Http::response(['status' => true, 'data' => [
                    'status' => 'success', 'channel' => 'card', 'reference' => 'pref',
                    'amount' => self::GROSS_KOBO, 'currency' => 'NGN']], 200);
            }

            $levelDuringTransfer = DB::transactionLevel();
            $paymentStatusDuringTransfer = Transaction::where('reference', 'pref')->value('status');

            return Http::response(['status' => true, 'data' => [
                'transfer_code' => 'TRF_1', 'id' => 9, 'status' => 'pending']], 200);
        });

        $this->txn();
        app(PaymentSettlementService::class)->settleByReference('pref');

        $this->assertSame($baselineLevel, $levelDuringTransfer, 'the Paystack transfer ran inside the open settlement transaction');
        $this->assertSame('success', $paymentStatusDuringTransfer, 'the transfer ran before the payment was committed');
        $this->assertSame(Payout::PROCESSING, Payout::firstOrFail()->status);
    }

    // =====================================================================
    // MEDIUM-3 — the obligation must survive a dispatch failure
    // =====================================================================

    public function test_MEDIUM3_dispatch_failure_leaves_a_reconcilable_obligation(): void
    {
        config(['queue.default' => 'database']);
        $this->fakeAll();
        $this->txn();

        // The queue is unreachable at dispatch time.
        Queue::shouldReceive('connection')->andThrow(new \RuntimeException('queue unavailable'));

        app(PaymentSettlementService::class)->settleByReference('pref');

        // Payment stands.
        $this->assertSame('success', Transaction::firstOrFail()->status);

        // Obligation is durable and still transferable.
        $payout = Payout::firstOrFail();
        $this->assertSame(Payout::PENDING, $payout->status);
        $this->assertEquals(self::BASE, (float) $payout->amount);
        $this->assertSame(0, $payout->attempts, 'no transfer was attempted');

        // And it is discoverable by reconciliation, which can queue it later.
        $this->assertSame(0, Transaction::query()->whereDoesntHave('payout')->count());
    }

    public function test_MEDIUM3_reconciliation_requeues_a_stranded_obligation(): void
    {
        // The exact state a failed dispatch leaves behind: obligation committed,
        // transferable, but never queued (attempts = 0).
        $transaction = Transaction::create([
            'school_id' => $this->school->id, 'reference' => 'stranded', 'amount' => self::GROSS,
            'status' => 'success', 'email' => 'p@example.test',
            'meta_data' => ['quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 1250],
        ]);
        $payout = Payout::create([
            'school_id' => $this->school->id, 'transaction_id' => $transaction->id,
            'reference' => 'PO-stranded', 'amount' => self::BASE, 'currency' => 'NGN',
            'status' => Payout::PENDING, 'attempts' => 0,
        ]);

        // Reconciliation finds it even though the transaction already has a payout,
        // so `whereDoesntHave('payout')` alone would have missed it.
        Bus::fake();
        $this->artisan('payouts:run --dispatch')->assertExitCode(0);

        Bus::assertDispatched(InitiateSchoolPayout::class, 1);
        Bus::assertDispatched(InitiateSchoolPayout::class, fn ($job) => $job->payoutId === $payout->id);
        $this->assertSame(1, Payout::count(), 'reconciliation created a duplicate payout');
        $this->assertSame(Payout::PENDING, $payout->refresh()->status);
    }

    public function test_MEDIUM3_reconciliation_does_not_requeue_payouts_already_in_flight(): void
    {
        $transaction = Transaction::create([
            'school_id' => $this->school->id, 'reference' => 'inflight', 'amount' => self::GROSS,
            'status' => 'success', 'email' => 'p@example.test',
            'meta_data' => ['quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 1250],
        ]);
        Payout::create([
            'school_id' => $this->school->id, 'transaction_id' => $transaction->id,
            'reference' => 'PO-inflight', 'amount' => self::BASE, 'currency' => 'NGN',
            'status' => Payout::PROCESSING, 'attempts' => 1,
        ]);

        Bus::fake();
        $this->artisan('payouts:run --dispatch')->assertExitCode(0);

        Bus::assertNotDispatched(InitiateSchoolPayout::class);
    }

    // =====================================================================
    // LOW-1 — uniqueness declaration
    // =====================================================================

    public function test_LOW1_job_declares_uniqueness_correctly(): void
    {
        $job = new InitiateSchoolPayout(42);

        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, $job);
        $this->assertSame('payout:42', $job->uniqueId());
        $this->assertSame(600, $job->uniqueFor);
    }

    public function test_LOW1_database_remains_the_real_guard_even_without_the_lock(): void
    {
        // Two jobs for the same payout, no cache lock involved: the atomic
        // pending -> initiating claim still permits exactly one transfer.
        $this->fakeAll();
        $this->txn();
        app(PaymentSettlementService::class)->settleByReference('pref');
        $p = Payout::firstOrFail();

        foreach (range(1, 4) as $ignored) {
            (new InitiateSchoolPayout($p->id))->handle(app(PayoutService::class), app(PaystackService::class));
        }

        $posts = 0;
        Http::assertSent(function ($r) use (&$posts) {
            if (str_ends_with($r->url(), '/transfer') && $r->method() === 'POST') {
                $posts++;
            }

            return true;
        });
        $this->assertSame(1, $posts);
        $this->assertSame(1, $p->refresh()->attempts);
    }
}
