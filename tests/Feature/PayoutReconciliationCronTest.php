<?php

namespace Tests\Feature;

use App\Jobs\InitiateSchoolPayout;
use App\Models\Payout;
use App\Models\School;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Safety guarantees for `payouts:run --dispatch` when it runs on a schedule.
 *
 * The cron is a recovery mechanism, not a payout mechanism. It never moves money
 * itself: it records obligations that settlement missed and hands them to the same
 * InitiateSchoolPayout job, which applies the same atomic claim and state machine.
 * Running it every hour must therefore be a no-op whenever nothing is stranded.
 */
class PayoutReconciliationCronTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 50000.00;

    private const GROSS = 51250.00;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        $this->school = School::create([
            'name' => 'Greenfield', 'slug' => 'greenfield', 'email' => 'g@example.test',
            'admin_password' => Hash::make('password123'), 'account_number' => '0123456789',
            'bank' => 'GTB', 'bank_code' => '058', 'account_name' => 'Acct',
        ]);
        $this->school->paystack_recipient_code = 'RCP_g';
        $this->school->save();
    }

    private function settledTransaction(string $reference, ?array $meta = null): Transaction
    {
        return Transaction::create([
            'school_id' => $this->school->id,
            'reference' => $reference,
            'amount' => self::GROSS,
            'status' => 'success',
            'email' => 'p@example.test',
            'meta_data' => $meta ?? ['quantity' => 1, 'base_amount' => self::BASE, 'markup_amount' => 1250],
        ]);
    }

    private function payoutFor(Transaction $t, string $status, int $attempts = 1): Payout
    {
        return Payout::create([
            'school_id' => $this->school->id,
            'transaction_id' => $t->id,
            'reference' => 'PO-'.$t->reference,
            'amount' => self::BASE,
            'currency' => 'NGN',
            'status' => $status,
            'attempts' => $attempts,
        ]);
    }

    // ---------------------------------------------------------------
    // Repeated runs must not duplicate anything
    // ---------------------------------------------------------------

    public function test_running_hourly_never_creates_a_second_obligation(): void
    {
        Bus::fake();
        $this->settledTransaction('txn-1');

        $this->artisan('payouts:run --dispatch')->assertExitCode(0);
        $this->assertSame(1, Payout::count());
        $first = Payout::firstOrFail();

        // Simulate the next 5 hourly runs.
        for ($hour = 0; $hour < 5; $hour++) {
            $this->artisan('payouts:run --dispatch')->assertExitCode(0);
        }

        $this->assertSame(1, Payout::count(), 'reconciliation created duplicate obligations');
        $this->assertSame($first->reference, Payout::firstOrFail()->reference, 'the payout reference changed');
    }

    public function test_a_transaction_that_already_has_an_obligation_is_skipped(): void
    {
        Bus::fake();
        $t = $this->settledTransaction('txn-1');
        $this->payoutFor($t, Payout::PROCESSING);

        $this->artisan('payouts:run --dispatch')
            ->expectsOutputToContain('No settled payments are missing a payout obligation')
            ->assertExitCode(0);

        $this->assertSame(1, Payout::count());
        Bus::assertNotDispatched(InitiateSchoolPayout::class);
    }

    // ---------------------------------------------------------------
    // Only genuinely stranded obligations are re-queued
    // ---------------------------------------------------------------

    public function test_a_stranded_pending_obligation_is_requeued(): void
    {
        Bus::fake();
        $t = $this->settledTransaction('txn-1');
        $payout = $this->payoutFor($t, Payout::PENDING, attempts: 0);

        $this->artisan('payouts:run --dispatch')->assertExitCode(0);

        Bus::assertDispatched(InitiateSchoolPayout::class, fn ($job) => $job->payoutId === $payout->id);
        $this->assertSame(1, Payout::count());
    }

    public static function nonRequeueableStates(): array
    {
        return [
            'initiating (outcome unknown)' => [Payout::INITIATING],
            'processing (in flight)' => [Payout::PROCESSING],
            'success (already paid)' => [Payout::SUCCESS],
            'reversed (terminal)' => [Payout::REVERSED],
            'failed (needs operator reset)' => [Payout::FAILED],
            'needs_review (needs a human)' => [Payout::NEEDS_REVIEW],
        ];
    }

    /**
     * @dataProvider nonRequeueableStates
     */
    public function test_reconciliation_never_requeues_a_payout_in_state(string $status): void
    {
        Bus::fake();
        $t = $this->settledTransaction('txn-1');
        $payout = $this->payoutFor($t, $status);

        $this->artisan('payouts:run --dispatch')->assertExitCode(0);

        Bus::assertNotDispatched(InitiateSchoolPayout::class);
        $this->assertSame($status, $payout->refresh()->status, 'reconciliation changed a payout state');
        $this->assertSame(1, Payout::count());
    }

    public function test_a_pending_payout_already_attempted_is_not_requeued(): void
    {
        // attempts > 0 means a worker already claimed it at least once; only
        // never-queued obligations are recovered.
        Bus::fake();
        $t = $this->settledTransaction('txn-1');
        $this->payoutFor($t, Payout::PENDING, attempts: 2);

        $this->artisan('payouts:run --dispatch')->assertExitCode(0);

        Bus::assertNotDispatched(InitiateSchoolPayout::class);
    }

    // ---------------------------------------------------------------
    // Money correctness
    // ---------------------------------------------------------------

    public function test_reconciliation_records_the_school_share_not_the_gross(): void
    {
        Bus::fake();
        $this->settledTransaction('txn-1');

        $this->artisan('payouts:run --dispatch')->assertExitCode(0);

        $payout = Payout::firstOrFail();
        $this->assertEquals(self::BASE, (float) $payout->amount);
        $this->assertNotEquals(self::GROSS, (float) $payout->amount, 'reconciliation would transfer the platform markup');
    }

    public function test_an_unattributable_transaction_becomes_needs_review_and_is_never_transferred(): void
    {
        Bus::fake();
        $this->settledTransaction('legacy-1', meta: []); // no base_amount

        $this->artisan('payouts:run --dispatch')->assertExitCode(0);

        $payout = Payout::firstOrFail();
        $this->assertSame(Payout::NEEDS_REVIEW, $payout->status);
        $this->assertEquals(0.0, (float) $payout->amount);
        Bus::assertNotDispatched(InitiateSchoolPayout::class);

        // And a later hourly run still refuses to move it.
        $this->artisan('payouts:run --dispatch')
            ->expectsOutputToContain('need manual review')
            ->assertExitCode(0);
        Bus::assertNotDispatched(InitiateSchoolPayout::class);
        $this->assertSame(1, Payout::count());
    }

    // ---------------------------------------------------------------
    // Scope
    // ---------------------------------------------------------------

    public function test_unsettled_and_schoolless_transactions_are_ignored(): void
    {
        Bus::fake();
        Transaction::create([
            'school_id' => $this->school->id, 'reference' => 'still-pending', 'amount' => self::GROSS,
            'status' => 'pending', 'email' => 'p@example.test',
            'meta_data' => ['base_amount' => self::BASE],
        ]);
        Transaction::create([
            'school_id' => null, 'reference' => 'no-school', 'amount' => self::GROSS,
            'status' => 'success', 'email' => 'p@example.test',
            'meta_data' => ['base_amount' => self::BASE],
        ]);

        $this->artisan('payouts:run --dispatch')->assertExitCode(0);

        $this->assertSame(0, Payout::count());
        Bus::assertNotDispatched(InitiateSchoolPayout::class);
    }

    public function test_dry_run_changes_nothing_and_queues_nothing(): void
    {
        Bus::fake();
        $this->settledTransaction('txn-1');

        $this->artisan('payouts:run --dry-run')->assertExitCode(0);

        $this->assertSame(0, Payout::count());
        Bus::assertNotDispatched(InitiateSchoolPayout::class);
    }

    public function test_the_command_never_calls_paystack_itself(): void
    {
        // The cron must not move money; it only records and enqueues. Any outbound
        // HTTP here would mean the command bypassed the job/state machine.
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        Bus::fake();

        $this->settledTransaction('txn-1');
        $this->artisan('payouts:run --dispatch')->assertExitCode(0);

        Bus::assertDispatched(InitiateSchoolPayout::class, 1);
        $this->assertSame(Payout::PENDING, Payout::firstOrFail()->status);
    }
}
