<?php

namespace Tests\Feature;

use App\Jobs\InitiateSchoolPayout;
use App\Models\Payout;
use App\Models\PayoutRecoveryEvent;
use App\Models\School;
use App\Models\Transaction;
use App\Services\PayoutService;
use App\Services\PaystackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * B1 — operator recovery tooling: payouts:retry, payouts:release, payouts:lookup.
 *
 * The commands never move money themselves. They apply transitions the state
 * machine already permits (under the row lock), write an immutable
 * PayoutRecoveryEvent in the same transaction, and hand the transfer to the
 * existing InitiateSchoolPayout job — whose atomic pending -> initiating claim is
 * what keeps a second dispatch, a concurrent cron run or a repeated command from
 * ever creating a second transfer.
 */
class PayoutRecoveryCommandsTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const BASE = 50000.00;

    private const BASE_KOBO = 5000000;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_secret_never_printed']);
        $this->school = $this->makeSchool('Greenfield Academy', 'greenfield', ['paystack_recipient_code' => 'RCP_greenfield', 'account_number' => '0123456789']);
    }

    /** A payout in the given state, backed by a settled ₦51,250 payment (₦50,000 fee). */
    private function payout(string $status, array $overrides = [], ?School $school = null): Payout
    {
        $school ??= $this->school;
        $transaction = $this->makeSuccessfulTransaction($school);

        $timestamps = array_intersect_key($overrides, array_flip(['created_at', 'updated_at']));
        $payout = Payout::create(array_merge([
            'school_id' => $school->id,
            'transaction_id' => $transaction->id,
            'reference' => 'PO-'.fake()->unique()->uuid(),
            'amount' => self::BASE,
            'currency' => 'NGN',
            'status' => $status,
            'attempts' => in_array($status, [Payout::PENDING, Payout::NEEDS_REVIEW], true) ? 0 : 1,
            'last_error' => $status === Payout::FAILED ? 'Insufficient balance' : ($status === Payout::NEEDS_REVIEW ? 'No base_amount recorded: the school share cannot be separated from the platform fee.' : null),
            'initiated_at' => in_array($status, [Payout::PENDING, Payout::NEEDS_REVIEW], true) ? null : now()->subMinutes(30),
            'completed_at' => in_array($status, [Payout::FAILED, Payout::SUCCESS, Payout::REVERSED], true) ? now()->subMinutes(20) : null,
        ], $overrides));

        // Timestamps are not mass-assignable; a test that needs an old row sets them directly.
        if ($timestamps !== []) {
            Payout::whereKey($payout->id)->update($timestamps);
        }

        return $payout->refresh();
    }

    private function acceptedTransfer(): void
    {
        Http::fake(['*/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_'.fake()->unique()->lexify('????'), 'id' => random_int(1, 99999), 'status' => 'pending']], 200)]);
    }

    private function runJob(Payout $payout): void
    {
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(PaystackService::class));
    }

    private function transferPosts(): int
    {
        return Http::recorded(fn ($r) => str_ends_with($r->url(), '/transfer') && $r->method() === 'POST')->count();
    }

    // =====================================================================
    // A. failed -> pending retry
    // =====================================================================

    public function test_retry_resets_a_failed_payout_to_pending_and_dispatches_the_job(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $payout = $this->payout(Payout::FAILED);

        $this->artisan('payouts:retry', ['reference' => $payout->reference, '--note' => 'balance topped up'])
            ->expectsOutputToContain("Payout {$payout->reference} reset from failed to pending.")
            ->expectsOutputToContain("Payout {$payout->reference} dispatched for retry.")
            ->assertExitCode(0);

        $payout->refresh();
        $this->assertSame(Payout::PENDING, $payout->status);
        $this->assertNull($payout->completed_at, 'the failure is no longer final');
        $this->assertSame('Insufficient balance', $payout->last_error, 'the reason for the failure stays on the row');
        $this->assertSame(1, $payout->attempts, 'attempts is the job\'s counter, not the command\'s');
        $this->assertStringStartsWith('PO-', $payout->reference);

        Bus::assertDispatched(InitiateSchoolPayout::class, fn ($job) => $job->payoutId === $payout->id);

        $event = PayoutRecoveryEvent::sole();
        $this->assertSame([$payout->id, $this->school->id, 'retry', 'failed', 'pending', 'artisan', null, 'balance topped up', 'reset'], [
            $event->payout_id, $event->school_id, $event->action, $event->previous_status, $event->new_status, $event->source, $event->amount, $event->reason, $event->result,
        ]);
        $this->assertNotNull($event->created_at);
    }

    public function test_retried_payout_goes_through_the_normal_job_and_transfer_checks(): void
    {
        $this->acceptedTransfer();
        $payout = $this->payout(Payout::FAILED);

        $this->artisan('payouts:retry', ['reference' => $payout->reference])->assertExitCode(0);
        $this->runJob($payout);

        $payout->refresh();
        $this->assertSame(Payout::PROCESSING, $payout->status, 'accepted is processing, never success');
        $this->assertSame(2, $payout->attempts);
        $this->assertSame(1, $this->transferPosts());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transfer')
            && $r['reference'] === $payout->reference && $r['amount'] === self::BASE_KOBO && $r['recipient'] === 'RCP_greenfield');
    }

    // =====================================================================
    // B–F. every non-failed state is refused
    // =====================================================================

    /** @dataProvider nonRetryableStates */
    public function test_a_payout_that_is_not_failed_cannot_be_retried(string $status, string $reason): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $payout = $this->payout($status, ['transfer_code' => $status === Payout::PENDING ? null : 'TRF_'.$status]);
        $before = $payout->refresh()->toArray();

        $this->artisan('payouts:retry', ['reference' => $payout->reference])
            ->expectsOutputToContain("Cannot retry {$payout->reference}: {$reason}.")
            ->assertExitCode(1);

        $this->assertSame($before, $payout->refresh()->toArray(), "a {$status} payout must be untouched");
        Bus::assertNotDispatched(InitiateSchoolPayout::class);

        $event = PayoutRecoveryEvent::sole();
        $this->assertSame(['retry', $status, null, 'rejected: payout is '.$status], [$event->action, $event->previous_status, $event->new_status, $event->result]);
    }

    public static function nonRetryableStates(): array
    {
        return [
            'success' => [Payout::SUCCESS, 'payout is already paid'],
            'processing' => [Payout::PROCESSING, 'payout is already processing'],
            'initiating' => [Payout::INITIATING, 'payout is initiating (outcome unknown — run payouts:lookup first)'],
            'reversed' => [Payout::REVERSED, 'payout was reversed and is final'],
            'needs_review' => [Payout::NEEDS_REVIEW, 'payout needs review (use payouts:release with an explicit amount)'],
        ];
    }

    public function test_retry_refuses_an_unknown_reference_and_bad_arguments(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);

        $this->artisan('payouts:retry', ['reference' => 'PO-does-not-exist'])->expectsOutputToContain('No payout with reference PO-does-not-exist.')->assertExitCode(1);
        $this->artisan('payouts:retry')->expectsOutputToContain('Give exactly one of')->assertExitCode(2);
        $this->artisan('payouts:retry', ['reference' => 'PO-x', '--all-failed' => true])->assertExitCode(2);

        Bus::assertNotDispatched(InitiateSchoolPayout::class);
        $this->assertDatabaseCount('payout_recovery_events', 0);
    }

    // =====================================================================
    // G–J. needs_review release
    // =====================================================================

    public function test_release_moves_a_reviewed_payout_to_pending_with_the_operator_amount(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $payout = $this->payout(Payout::NEEDS_REVIEW, ['amount' => 0]);

        $this->artisan('payouts:release', ['reference' => $payout->reference, '--amount' => '48500.50', '--note' => 'confirmed with the school'])
            ->expectsOutputToContain('Parked because: No base_amount recorded')
            ->expectsOutputToContain("Payout {$payout->reference} released from needs_review to pending with amount NGN 48,500.50.")
            ->expectsOutputToContain("Payout {$payout->reference} dispatched for transfer.")
            ->assertExitCode(0);

        $payout->refresh();
        $this->assertSame(Payout::PENDING, $payout->status);
        $this->assertSame(48500.50, (float) $payout->amount);
        $this->assertStringContainsString('No base_amount recorded', $payout->last_error, 'the original review reason is preserved on the row');
        $this->assertNull($payout->completed_at);

        Bus::assertDispatched(InitiateSchoolPayout::class, fn ($job) => $job->payoutId === $payout->id);

        $event = PayoutRecoveryEvent::sole();
        $this->assertSame(['release', 'needs_review', 'pending', 'artisan', 48500.50, 'released'], [
            $event->action, $event->previous_status, $event->new_status, $event->source, (float) $event->amount, $event->result,
        ]);
        $this->assertStringContainsString('confirmed with the school', $event->reason);
        $this->assertStringContainsString('parked because: No base_amount recorded', $event->reason);
    }

    public function test_release_without_an_amount_fails_and_changes_nothing(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $payout = $this->payout(Payout::NEEDS_REVIEW, ['amount' => 0]);

        $this->artisan('payouts:release', ['reference' => $payout->reference])
            ->expectsOutputToContain('--amount is required')
            ->assertExitCode(2);

        $this->assertSame(Payout::NEEDS_REVIEW, $payout->refresh()->status);
        $this->assertSame(0.0, (float) $payout->amount);
        Bus::assertNotDispatched(InitiateSchoolPayout::class);
        $this->assertDatabaseCount('payout_recovery_events', 0);
    }

    /** @dataProvider invalidAmounts */
    public function test_release_with_an_invalid_amount_fails(string $amount): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $payout = $this->payout(Payout::NEEDS_REVIEW, ['amount' => 0]);

        $this->artisan('payouts:release', ['reference' => $payout->reference, '--amount' => $amount])
            ->expectsOutputToContain("Invalid --amount \"{$amount}\"")
            ->assertExitCode(2);

        $this->assertSame(Payout::NEEDS_REVIEW, $payout->refresh()->status);
        Bus::assertNotDispatched(InitiateSchoolPayout::class);
        $this->assertDatabaseCount('payout_recovery_events', 0);
    }

    public static function invalidAmounts(): array
    {
        return ['zero' => ['0'], 'negative' => ['-5'], 'three decimals' => ['100.005'], 'words' => ['fifty'], 'thousands separator' => ['50,000'], 'kobo suffix' => ['50000k']];
    }

    // =====================================================================
    // Financial authority: the ceiling is the payment's school share
    //
    //   normal payment: meta base_amount 50,000 + markup 1,250 = 51,250 charged
    //   legacy payment: 51,250 charged, no base_amount (no trustworthy split)
    //   orphan payout:  no transaction at all
    // =====================================================================

    /** A settled legacy payment: gross recorded, no base_amount, fee columns NULL. */
    private function legacyPayout(string $status, array $overrides = []): Payout
    {
        $transaction = Transaction::create([
            'school_id' => $this->school->id, 'reference' => 'legacy-'.fake()->unique()->uuid(), 'amount' => 51250,
            'fee_amount' => null, 'service_fee' => null, 'status' => 'success', 'paid_at' => now(), 'email' => 'p@example.test',
            'meta_data' => ['quantity' => 1],
        ]);
        $this->assertFalse($transaction->receiptBreakdown()['has_breakdown']);

        return Payout::create(array_merge([
            'school_id' => $this->school->id, 'transaction_id' => $transaction->id, 'reference' => 'PO-'.fake()->unique()->uuid(),
            'amount' => 0, 'currency' => 'NGN', 'status' => $status, 'attempts' => 1, 'last_error' => 'No base_amount recorded',
        ], $overrides));
    }

    /** @dataProvider trustworthyReleaseAmounts */
    public function test_release_on_a_trustworthy_breakdown_is_capped_at_the_school_share(string $amount, bool $accepted): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $payout = $this->payout(Payout::NEEDS_REVIEW, ['amount' => 0]);
        $this->assertSame(50000.0, app(PayoutService::class)->payoutAmountFor($payout->transaction), 'authoritative school share');

        $command = $this->artisan('payouts:release', ['reference' => $payout->reference, '--amount' => $amount]);

        if ($accepted) {
            $command->expectsOutputToContain('released from needs_review to pending with amount NGN '.number_format((float) $amount, 2).'.')->assertExitCode(0)->run();
            $this->assertSame(Payout::PENDING, $payout->refresh()->status);
            $this->assertSame((float) $amount, (float) $payout->amount);
            Bus::assertDispatched(InitiateSchoolPayout::class, fn ($j) => $j->payoutId === $payout->id);
            $this->assertSame('released', PayoutRecoveryEvent::sole()->result);
        } else {
            $command->expectsOutputToContain("Cannot release {$payout->reference}: amount NGN ".number_format((float) $amount, 2).' exceeds the NGN 50,000.00 school share recorded for this payment (the parent paid NGN 51,250.00 including the service fee).')
                ->assertExitCode(1)->run();
            $this->assertSame(Payout::NEEDS_REVIEW, $payout->refresh()->status);
            $this->assertSame(0.0, (float) $payout->amount);
            Bus::assertNotDispatched(InitiateSchoolPayout::class);
            $event = PayoutRecoveryEvent::sole();
            $this->assertSame(['release', 'needs_review', null, (float) $amount], [$event->action, $event->previous_status, $event->new_status, (float) $event->amount]);
            $this->assertStringStartsWith('rejected: amount NGN', $event->result);
        }
    }

    public static function trustworthyReleaseAmounts(): array
    {
        return [
            '1. equal to the school share (50,000)' => ['50000', true],
            '2. one kobo above the school share' => ['50000.01', false],
            '3. the parent gross (51,250) — the service fee is not the school\'s' => ['51250', false],
            '3b. above the gross' => ['51250.01', false],
            '4. below the school share (operator discretion)' => ['49999.99', true],
        ];
    }

    /** @dataProvider legacyReleaseAmounts */
    public function test_release_on_a_legacy_payment_is_capped_at_the_gross_charged(string $amount, bool $accepted): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $payout = $this->legacyPayout(Payout::NEEDS_REVIEW);

        $command = $this->artisan('payouts:release', ['reference' => $payout->reference, '--amount' => $amount]);

        if ($accepted) {
            $command->assertExitCode(0)->run();
            $this->assertSame(Payout::PENDING, $payout->refresh()->status);
            $this->assertSame((float) $amount, (float) $payout->amount);
            Bus::assertDispatched(InitiateSchoolPayout::class);
        } else {
            $command->expectsOutputToContain("Cannot release {$payout->reference}: amount NGN ".number_format((float) $amount, 2).' exceeds the NGN 51,250.00 charged for this legacy payment, which has no trustworthy fee breakdown.')
                ->assertExitCode(1)->run();
            $this->assertSame(Payout::NEEDS_REVIEW, $payout->refresh()->status);
            Bus::assertNotDispatched(InitiateSchoolPayout::class);
            $this->assertStringStartsWith('rejected: amount NGN', PayoutRecoveryEvent::sole()->result);
        }
    }

    public static function legacyReleaseAmounts(): array
    {
        return [
            '5. up to the gross charged' => ['51250', true],
            '5b. below the gross' => ['50000', true],
            '6. above the gross charged' => ['51250.01', false],
        ];
    }

    public function test_release_is_refused_for_a_payout_with_no_transaction_and_is_audited(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $orphan = Payout::create(['school_id' => $this->school->id, 'transaction_id' => null, 'reference' => 'PO-orphan', 'amount' => 0, 'currency' => 'NGN',
            'status' => Payout::NEEDS_REVIEW, 'last_error' => 'parked', 'payout_date' => '2025-10-01', 'start_at' => '2025-10-01 00:00:00', 'end_at' => '2025-10-01 23:59:59']);

        $this->artisan('payouts:release', ['reference' => 'PO-orphan', '--amount' => '1'])
            ->expectsOutputToContain('Cannot release PO-orphan: amount cannot be authorised: this payout has no transaction (no payment) behind it.')
            ->assertExitCode(1);

        $this->assertSame(Payout::NEEDS_REVIEW, $orphan->refresh()->status);
        $this->assertSame(0.0, (float) $orphan->amount);
        Bus::assertNotDispatched(InitiateSchoolPayout::class);
        $event = PayoutRecoveryEvent::sole();
        $this->assertSame(['release', 'needs_review', null, 1.0, 'artisan'], [$event->action, $event->previous_status, $event->new_status, (float) $event->amount, $event->source]);
        $this->assertSame('rejected: amount cannot be authorised: this payout has no transaction (no payment) behind it', $event->result);
    }

    public function test_retry_refuses_a_payout_amount_above_the_school_share_and_sends_nothing(): void
    {
        Http::fake();
        // Tampered: the row claims 900,000 against a payment whose school share is 50,000.
        $payout = $this->payout(Payout::FAILED, ['amount' => 900000]);

        $this->artisan('payouts:retry', ['reference' => $payout->reference])
            ->expectsOutputToContain("Cannot retry {$payout->reference}: payout amount NGN 900,000.00 exceeds the NGN 50,000.00 school share recorded for this payment (the parent paid NGN 51,250.00 including the service fee).")
            ->assertExitCode(1);

        $payout->refresh();
        $this->assertSame(Payout::FAILED, $payout->status);
        $this->assertSame(900000.0, (float) $payout->amount, 'the amount is refused, never silently corrected');
        Http::assertNothingSent();
        $event = PayoutRecoveryEvent::sole();
        $this->assertSame(['retry', 'failed', null, 900000.0], [$event->action, $event->previous_status, $event->new_status, (float) $event->amount]);
        $this->assertStringStartsWith('rejected: payout amount NGN 900,000.00 exceeds', $event->result);

        // The same tampered row, if it somehow reached the job as pending, is refused
        // there too — released as a definitive failure with no transfer created.
        $payout->forceFill(['status' => Payout::PENDING])->save();
        $this->runJob($payout);
        $this->assertSame(Payout::FAILED, $payout->refresh()->status);
        $this->assertStringContainsString('Refused before transfer: payout amount NGN 900,000.00 exceeds', $payout->last_error);
        $this->assertSame(0, $this->transferPosts());
    }

    public function test_retry_refuses_a_legacy_payout_amount_above_the_gross_and_the_batch_continues(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $tampered = $this->legacyPayout(Payout::FAILED, ['amount' => 51250.01]);
        $fine = $this->legacyPayout(Payout::FAILED, ['amount' => 51250]);
        $orphan = Payout::create(['school_id' => $this->school->id, 'transaction_id' => null, 'reference' => 'PO-orphan-failed', 'amount' => 10, 'currency' => 'NGN',
            'status' => Payout::FAILED, 'last_error' => 'x', 'payout_date' => '2025-10-01', 'start_at' => '2025-10-01 00:00:00', 'end_at' => '2025-10-01 23:59:59']);

        $this->artisan('payouts:retry', ['--all-failed' => true])
            ->expectsOutputToContain("Cannot retry {$tampered->reference}: payout amount NGN 51,250.01 exceeds the NGN 51,250.00 charged for this legacy payment, which has no trustworthy fee breakdown.")
            ->expectsOutputToContain('Cannot retry PO-orphan-failed: payout amount cannot be authorised: this payout has no transaction (no payment) behind it.')
            ->expectsOutputToContain('processed: 3')
            ->expectsOutputToContain('reset: 1')
            ->expectsOutputToContain('skipped: 2')
            ->expectsOutputToContain('failed: 0')
            ->assertExitCode(0);

        $this->assertSame(Payout::FAILED, $tampered->refresh()->status);
        $this->assertSame(Payout::FAILED, $orphan->refresh()->status);
        $this->assertSame(Payout::PENDING, $fine->refresh()->status);
        Bus::assertDispatchedTimes(InitiateSchoolPayout::class, 1);
        Bus::assertDispatched(InitiateSchoolPayout::class, fn ($j) => $j->payoutId === $fine->id);
    }

    public function test_a_released_amount_is_exactly_what_the_job_sends_and_what_success_is_matched_against(): void
    {
        $payout = $this->payout(Payout::NEEDS_REVIEW, ['amount' => 0]);
        Bus::fake([InitiateSchoolPayout::class]);
        $this->artisan('payouts:release', ['reference' => $payout->reference, '--amount' => '48500.50'])->assertExitCode(0);

        $this->acceptedTransfer();
        $this->runJob($payout);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transfer') && $r['amount'] === 4850050 && $r['reference'] === $payout->reference);
        $this->assertSame(48500.50, (float) $payout->refresh()->amount, 'the job never recomputes the released amount');

        // Success is matched against payouts.amount: the released figure, not the
        // payment's share and not the gross.
        $payouts = app(PayoutService::class);
        $this->assertSame(Payout::NEEDS_REVIEW, $payouts->applyPaystackStatus($payout->fresh(), 'success', ['reference' => $payout->reference, 'amount' => 5000000, 'currency' => 'NGN']));
        $second = $this->payout(Payout::NEEDS_REVIEW, ['amount' => 0]);
        $this->artisan('payouts:release', ['reference' => $second->reference, '--amount' => '48500.50'])->assertExitCode(0);
        $this->runJob($second);
        $this->assertSame(Payout::SUCCESS, $payouts->applyPaystackStatus($second->fresh(), 'success', ['reference' => $second->reference, 'amount' => 4850050, 'currency' => 'NGN']));
    }

    public function test_service_fee_and_markup_values_never_raise_the_school_ceiling(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $payouts = app(PayoutService::class);

        // markup_amount tampered to 0: the breakdown re-derives the service fee from
        // total - base, and the ceiling stays the base amount.
        $t1 = $this->makeSuccessfulTransaction($this->school, ['meta_data' => ['quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 0]]);
        // service_fee / fee_amount columns tampered: they are not in the money chain.
        $t2 = $this->makeSuccessfulTransaction($this->school, ['fee_amount' => 999999, 'service_fee' => 0, 'meta_data' => ['quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 1250]]);
        // markup_amount inflated so parts exceed the total: the total wins, base stays.
        $t3 = $this->makeSuccessfulTransaction($this->school, ['meta_data' => ['quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 999999]]);

        foreach ([$t1, $t2, $t3] as $t) {
            $this->assertSame(50000.0, $payouts->payoutAmountFor($t));
            $p = Payout::create(['school_id' => $this->school->id, 'transaction_id' => $t->id, 'reference' => 'PO-'.$t->id, 'amount' => 0, 'currency' => 'NGN', 'status' => Payout::NEEDS_REVIEW, 'last_error' => 'x']);
            $this->assertNotNull($payouts->amountAuthorityProblem($p, 50000.01), 'ceiling must stay at the school share');
            $this->assertNotNull($payouts->amountAuthorityProblem($p, 51250.00));
            $this->assertNull($payouts->amountAuthorityProblem($p, 50000.00));
        }
    }

    /** @dataProvider nonReleasableStates */
    public function test_only_a_reviewed_payout_can_be_released(string $status): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $payout = $this->payout($status, ['transfer_code' => 'TRF_'.$status]);
        $before = $payout->refresh()->toArray();

        $this->artisan('payouts:release', ['reference' => $payout->reference, '--amount' => '1000'])
            ->expectsOutputToContain("Cannot release {$payout->reference}:")
            ->assertExitCode(1);

        $this->assertSame($before, $payout->refresh()->toArray());
        Bus::assertNotDispatched(InitiateSchoolPayout::class);
        $this->assertSame('rejected: payout is '.$status, PayoutRecoveryEvent::sole()->result);
    }

    public static function nonReleasableStates(): array
    {
        return array_map(fn ($s) => [$s], [Payout::SUCCESS, Payout::PROCESSING, Payout::INITIATING, Payout::REVERSED, Payout::FAILED, Payout::PENDING]);
    }

    public function test_release_dispatches_only_after_the_state_change_has_committed(): void
    {
        $dispatched = [];
        Bus::fake([InitiateSchoolPayout::class]);
        $payout = $this->payout(Payout::NEEDS_REVIEW, ['amount' => 0]);

        // Every dispatch happens with the row already committed as pending, so a
        // worker that picked the job up instantly would find a claimable payout.
        Bus::assertNothingDispatched();
        $this->artisan('payouts:release', ['reference' => $payout->reference, '--amount' => '50000'])->assertExitCode(0);
        Bus::assertDispatched(InitiateSchoolPayout::class, function ($job) use ($payout, &$dispatched) {
            $dispatched[] = $job->payoutId;

            return Payout::whereKey($payout->id)->where('status', Payout::PENDING)->where('amount', 50000)->exists();
        });
        $this->assertSame([$payout->id], $dispatched);

        // And the transfer the job then sends carries the operator's amount, in kobo,
        // through the unchanged transfer path.
        $this->acceptedTransfer();
        $this->runJob($payout);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/transfer') && $r['amount'] === 5000000 && $r['reference'] === $payout->reference);
        $this->assertSame(Payout::PROCESSING, $payout->refresh()->status);
    }

    // =====================================================================
    // K–N. lookup
    // =====================================================================

    public function test_ambiguous_lookup_leaves_the_payout_initiating_and_never_resends(): void
    {
        Http::fake(['*transfer/verify*' => Http::response(['status' => false, 'message' => 'upstream timeout'], 503)]);
        $payout = $this->payout(Payout::INITIATING);

        $this->artisan('payouts:lookup', ['reference' => $payout->reference])
            ->expectsOutputToContain("Payout {$payout->reference} is still unresolved")
            ->assertExitCode(1);

        $this->assertSame(Payout::INITIATING, $payout->refresh()->status);
        $this->assertSame(0, $this->transferPosts(), 'a lookup must never create a transfer');
        $event = PayoutRecoveryEvent::sole();
        $this->assertSame(['lookup', 'initiating', null, 'unresolved: unchanged'], [$event->action, $event->previous_status, $event->new_status, $event->result]);
    }

    public function test_definitive_lookup_applies_paystacks_answer(): void
    {
        Http::fake(['*transfer/verify*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'transfer_code' => 'TRF_FOUND', 'id' => 77, 'amount' => self::BASE_KOBO, 'currency' => 'NGN']], 200)]);
        $payout = $this->payout(Payout::INITIATING);

        $this->artisan('payouts:lookup', ['reference' => $payout->reference])
            ->expectsOutputToContain("Payout {$payout->reference}: Paystack reports the transfer as success; now success.")
            ->assertExitCode(0);

        $payout->refresh();
        $this->assertSame(Payout::SUCCESS, $payout->status);
        $this->assertSame('TRF_FOUND', $payout->transfer_code);
        $this->assertSame(0, $this->transferPosts());
        $this->assertSame(['lookup', 'initiating', 'success', 'resolved: initiating -> success'], array_values(PayoutRecoveryEvent::sole()->only('action', 'previous_status', 'new_status', 'result')));
    }

    public function test_lookup_with_a_mismatching_transfer_parks_the_payout_for_review(): void
    {
        // HIGH-2 still applies through the lookup path: a "successful" transfer for
        // the wrong amount is never recorded as paid.
        Http::fake(['*transfer/verify*' => Http::response(['status' => true, 'data' => [
            'status' => 'success', 'transfer_code' => 'TRF_WRONG', 'id' => 78, 'amount' => self::BASE_KOBO - 1, 'currency' => 'NGN']], 200)]);
        $payout = $this->payout(Payout::INITIATING);

        $this->artisan('payouts:lookup', ['reference' => $payout->reference])->assertExitCode(0);

        $this->assertSame(Payout::NEEDS_REVIEW, $payout->refresh()->status);
        $this->assertSame('resolved: initiating -> needs_review', PayoutRecoveryEvent::sole()->result);
    }

    public function test_lookup_proving_no_transfer_exists_releases_the_payout_for_retry(): void
    {
        Http::fake(['*transfer/verify*' => Http::response(['status' => false, 'message' => 'Transfer not found'], 404)]);
        $payout = $this->payout(Payout::INITIATING);

        $this->artisan('payouts:lookup', ['reference' => $payout->reference])
            ->expectsOutputToContain("released to failed — run `payouts:retry {$payout->reference}` to send it again.")
            ->assertExitCode(0);

        $this->assertSame(Payout::FAILED, $payout->refresh()->status);
        $this->assertSame(0, $this->transferPosts());
        $this->assertSame('released: initiating -> failed', PayoutRecoveryEvent::sole()->result);

        // …and the retry then works end to end.
        $this->acceptedTransfer();
        $this->artisan('payouts:retry', ['reference' => $payout->reference])->assertExitCode(0);
        $this->runJob($payout);
        $this->assertSame(Payout::PROCESSING, $payout->refresh()->status);
        $this->assertSame(1, $this->transferPosts());
    }

    /** @dataProvider nonLookupStates */
    public function test_lookup_leaves_every_other_state_alone(string $status): void
    {
        Http::fake();
        $payout = $this->payout($status, ['transfer_code' => 'TRF_'.$status]);
        $before = $payout->refresh()->toArray();

        $this->artisan('payouts:lookup', ['reference' => $payout->reference])
            ->expectsOutputToContain("Cannot look up {$payout->reference}:")
            ->assertExitCode(1);

        $this->assertSame($before, $payout->refresh()->toArray());
        Http::assertNothingSent();
        $this->assertDatabaseCount('payout_recovery_events', 0);
    }

    public static function nonLookupStates(): array
    {
        return array_map(fn ($s) => [$s], [Payout::PENDING, Payout::PROCESSING, Payout::SUCCESS, Payout::FAILED, Payout::REVERSED, Payout::NEEDS_REVIEW]);
    }

    public function test_stale_lookup_selects_only_old_initiating_payouts(): void
    {
        Http::fake(['*transfer/verify*' => Http::response(['status' => false, 'message' => 'Transfer not found'], 404)]);
        $old = $this->payout(Payout::INITIATING, ['initiated_at' => now()->subMinutes(PayoutService::STALE_INITIATING_MINUTES + 1)]);
        $legacyOld = $this->payout(Payout::INITIATING, ['initiated_at' => null, 'created_at' => now()->subMinutes(30), 'updated_at' => now()->subMinutes(30)]);
        $recent = $this->payout(Payout::INITIATING, ['initiated_at' => now()->subMinutes(2)]);
        $recentLegacy = $this->payout(Payout::INITIATING, ['initiated_at' => null]);
        $processing = $this->payout(Payout::PROCESSING, ['initiated_at' => now()->subHours(3)]);
        $failed = $this->payout(Payout::FAILED);

        $this->artisan('payouts:lookup', ['--stale' => true])
            ->expectsOutputToContain('2 initiating payout(s) older than 10 minutes.')
            ->expectsOutputToContain('processed: 2')
            ->expectsOutputToContain('resolved: 2')
            ->expectsOutputToContain('skipped: 0')
            ->expectsOutputToContain('failed: 0')
            ->assertExitCode(0);

        $this->assertSame(Payout::FAILED, $old->refresh()->status);
        $this->assertSame(Payout::FAILED, $legacyOld->refresh()->status);
        // Recent initiating payouts are untouched — no lookup, no event.
        $this->assertSame(Payout::INITIATING, $recent->refresh()->status);
        $this->assertSame(Payout::INITIATING, $recentLegacy->refresh()->status);
        $this->assertSame(Payout::PROCESSING, $processing->refresh()->status);
        $this->assertSame(Payout::FAILED, $failed->refresh()->status);
        $this->assertSame(0, $this->transferPosts());
        $this->assertSame(2, Http::recorded()->count(), 'exactly one lookup per stale payout');
        $this->assertEqualsCanonicalizing([$old->id, $legacyOld->id], PayoutRecoveryEvent::pluck('payout_id')->all());
    }

    public function test_stale_lookup_with_nothing_stale_reports_zeroes(): void
    {
        Http::fake();
        $this->payout(Payout::INITIATING, ['initiated_at' => now()->subMinutes(1)]);

        $this->artisan('payouts:lookup', ['--stale' => true])
            ->expectsOutputToContain('No initiating payouts older than 10 minutes.')
            ->expectsOutputToContain('processed: 0')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    // =====================================================================
    // O–P. repeats and concurrency never move money twice
    // =====================================================================

    public function test_running_retry_twice_is_safe_and_the_job_still_sends_once(): void
    {
        // Bus is faked so the two dispatches are held back and then run explicitly,
        // like two workers picking the same payout up.
        Bus::fake([InitiateSchoolPayout::class]);
        $this->acceptedTransfer();
        $payout = $this->payout(Payout::FAILED);

        $this->artisan('payouts:retry', ['reference' => $payout->reference])->assertExitCode(0);
        // A second operator (or the same one, twice) finds it pending: nothing to
        // reset, but the dispatch is repeated because the claim makes it harmless.
        $this->artisan('payouts:retry', ['reference' => $payout->reference])
            ->expectsOutputToContain("Payout {$payout->reference} is already pending; queued again.")
            ->assertExitCode(0);

        $this->assertSame(['reset', 'dispatched (already pending)'], PayoutRecoveryEvent::orderBy('id')->pluck('result')->all());
        // The job is ShouldBeUnique: while the first dispatch's lock is held the second
        // is dropped at dispatch time — a third layer before the claim guard below.
        Bus::assertDispatchedTimes(InitiateSchoolPayout::class, 1);

        // Both dispatches run: only the first claims; the second finds it in flight.
        $this->runJob($payout);
        $this->runJob($payout);

        $this->assertSame(1, $this->transferPosts(), 'a repeated recovery created a second transfer');
        $this->assertSame(Payout::PROCESSING, $payout->refresh()->status);
        $this->assertSame(2, $payout->attempts);
    }

    public function test_retry_is_refused_while_the_job_holds_the_claim(): void
    {
        // Simulates the cron/worker racing the operator: the job has just claimed the
        // payout (pending -> initiating) when the operator's retry lands.
        Bus::fake([InitiateSchoolPayout::class]);
        $payout = $this->payout(Payout::PENDING);
        $this->assertTrue(app(PayoutService::class)->claimForTransfer($payout));

        $this->artisan('payouts:retry', ['reference' => $payout->reference])
            ->expectsOutputToContain('payout is initiating (outcome unknown — run payouts:lookup first)')
            ->assertExitCode(1);

        $this->assertSame(Payout::INITIATING, $payout->refresh()->status);
        Bus::assertNotDispatched(InitiateSchoolPayout::class);
    }

    public function test_a_webhook_arriving_during_recovery_still_wins_and_nothing_is_resent(): void
    {
        // The transfer actually succeeded at Paystack; its webhook lands after the
        // operator's retry reset the row. The webhook's success is applied (pending
        // -> success is legitimate) and the queued job then finds nothing to claim.
        Bus::fake([InitiateSchoolPayout::class]);
        Http::fake();
        $payout = $this->payout(Payout::FAILED);
        $this->artisan('payouts:retry', ['reference' => $payout->reference])->assertExitCode(0);
        $this->assertSame(Payout::PENDING, $payout->refresh()->status);

        $body = json_encode(['event' => 'transfer.success', 'data' => ['reference' => $payout->reference, 'status' => 'success', 'transfer_code' => 'TRF_LATE', 'id' => 5, 'amount' => self::BASE_KOBO, 'currency' => 'NGN']]);
        $this->call('POST', '/paystack/webhook', [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_secret_never_printed'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame(Payout::SUCCESS, $payout->refresh()->status);

        $this->runJob($payout);
        $this->assertSame(0, $this->transferPosts());
        $this->assertSame(Payout::SUCCESS, $payout->refresh()->status);
    }

    public function test_release_twice_is_refused_the_second_time(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $payout = $this->payout(Payout::NEEDS_REVIEW, ['amount' => 0]);

        $this->artisan('payouts:release', ['reference' => $payout->reference, '--amount' => '50000'])->assertExitCode(0);
        $this->artisan('payouts:release', ['reference' => $payout->reference, '--amount' => '60000'])
            ->expectsOutputToContain("Cannot release {$payout->reference}: payout is already pending.")
            ->assertExitCode(1);

        $this->assertSame(50000.0, (float) $payout->refresh()->amount, 'the second amount was not applied');
        Bus::assertDispatchedTimes(InitiateSchoolPayout::class, 1);
    }

    // =====================================================================
    // Q. --all-failed processes every payout independently
    // =====================================================================

    public function test_all_failed_continues_past_a_payout_that_throws(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $beta = $this->makeSchool('Beta School', 'beta', ['paystack_recipient_code' => 'RCP_beta']);
        $first = $this->payout(Payout::FAILED);
        $broken = $this->payout(Payout::FAILED, [], $beta);
        $third = $this->payout(Payout::FAILED);
        $processing = $this->payout(Payout::PROCESSING, ['transfer_code' => 'TRF_P']);

        $real = new PayoutService;
        $this->partialMock(PayoutService::class, function ($mock) use ($broken, $real) {
            $mock->shouldReceive('retryFailed')->andReturnUsing(function (Payout $payout, ...$rest) use ($broken, $real) {
                if ($payout->id === $broken->id) {
                    throw new \RuntimeException('database went away');
                }

                return $real->retryFailed($payout, ...$rest);
            });
        });

        $this->artisan('payouts:retry', ['--all-failed' => true])
            ->expectsOutputToContain('3 failed payout(s).')
            ->expectsOutputToContain("Failed to retry {$broken->reference}: database went away")
            ->expectsOutputToContain('processed: 3')
            ->expectsOutputToContain('reset: 2')
            ->expectsOutputToContain('skipped: 0')
            ->expectsOutputToContain('failed: 1')
            ->assertExitCode(1);

        $this->assertSame(Payout::PENDING, $first->refresh()->status);
        $this->assertSame(Payout::FAILED, $broken->refresh()->status);
        $this->assertSame(Payout::PENDING, $third->refresh()->status);
        $this->assertSame(Payout::PROCESSING, $processing->refresh()->status);
        Bus::assertDispatchedTimes(InitiateSchoolPayout::class, 2);
        $this->assertEqualsCanonicalizing([$first->id, $third->id], PayoutRecoveryEvent::where('result', 'reset')->pluck('payout_id')->all());
    }

    public function test_all_failed_with_no_failed_payouts_reports_zeroes(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $this->payout(Payout::PROCESSING, ['transfer_code' => 'TRF_P']);

        $this->artisan('payouts:retry', ['--all-failed' => true])
            ->expectsOutputToContain('No failed payouts.')
            ->expectsOutputToContain('processed: 0')
            ->assertExitCode(0);

        Bus::assertNotDispatched(InitiateSchoolPayout::class);
    }

    // =====================================================================
    // R. the audit trail
    // =====================================================================

    public function test_recovery_events_are_immutable_rows_written_with_the_state_change(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $this->assertTrue(Schema::hasTable('payout_recovery_events'));
        $this->assertFalse(Schema::hasColumn('payout_recovery_events', 'updated_at'), 'events are never updated');

        $payout = $this->payout(Payout::FAILED);
        $this->artisan('payouts:retry', ['reference' => $payout->reference])->assertExitCode(0);

        $event = $payout->recoveryEvents()->sole();
        $this->assertSame($payout->school_id, $event->school_id);
        $this->assertSame($payout->id, $event->payout->id);
        $this->assertNull($event->updated_at);

        // A rejected release is recorded as a rejection, not as a change; and the
        // audit row carries the amount the operator typed.
        $this->artisan('payouts:release', ['reference' => $payout->reference, '--amount' => '10'])->assertExitCode(1);
        $this->assertSame(
            [['retry', 'failed', 'pending', null, 'reset'], ['release', 'pending', null, 10.0, 'rejected: payout is pending']],
            $payout->recoveryEvents()->get()->map(fn ($e) => [$e->action, $e->previous_status, $e->new_status, $e->amount !== null ? (float) $e->amount : null, $e->result])->all()
        );
    }

    public function test_a_failed_state_change_rolls_back_its_audit_row(): void
    {
        // The event and the transition share one transaction: if the transition
        // cannot be written, no event claims it happened.
        $payout = $this->payout(Payout::FAILED);
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'update "payouts"')) {
                throw new \RuntimeException('simulated write failure');
            }
        });

        try {
            app(PayoutService::class)->retryFailed($payout);
            $this->fail('expected the simulated failure to propagate');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated write failure', $e->getMessage());
        }

        $this->assertSame(Payout::FAILED, $payout->refresh()->status);
        $this->assertDatabaseCount('payout_recovery_events', 0);
    }

    // =====================================================================
    // S. tenant and security boundaries
    // =====================================================================

    public function test_command_output_never_reveals_secrets_or_full_account_numbers(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $payout = $this->payout(Payout::FAILED);

        $this->artisan('payouts:retry', ['reference' => $payout->reference])
            ->expectsOutputToContain('GTB ····6789')
            ->doesntExpectOutputToContain('0123456789')
            ->doesntExpectOutputToContain('sk_test_secret_never_printed')
            ->doesntExpectOutputToContain('RCP_greenfield')
            ->assertExitCode(0);

        $event = PayoutRecoveryEvent::sole();
        $this->assertStringNotContainsString('0123456789', (string) $event->reason);
        $this->assertStringNotContainsString('sk_test', json_encode($event->toArray()));
    }

    public function test_recovery_is_scoped_to_the_payouts_own_school_and_payment(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $beta = $this->makeSchool('Beta School', 'beta', ['paystack_recipient_code' => 'RCP_beta']);
        $alphaPayout = $this->payout(Payout::FAILED);
        $betaPayout = $this->payout(Payout::NEEDS_REVIEW, ['amount' => 0], $beta);

        $this->artisan('payouts:retry', ['reference' => $alphaPayout->reference])->assertExitCode(0);
        $this->artisan('payouts:release', ['reference' => $betaPayout->reference, '--amount' => '50000'])->assertExitCode(0);

        // Each event belongs to its payout's school; nothing crossed over, and the
        // other school's payout was not touched by the other command.
        $this->assertSame($this->school->id, $alphaPayout->recoveryEvents()->sole()->school_id);
        $this->assertSame($beta->id, $betaPayout->recoveryEvents()->sole()->school_id);
        $this->assertSame([$this->school->id, $beta->id], Payout::orderBy('id')->pluck('school_id')->all());
        Bus::assertDispatched(InitiateSchoolPayout::class, fn ($j) => $j->payoutId === $alphaPayout->id);
        Bus::assertDispatched(InitiateSchoolPayout::class, fn ($j) => $j->payoutId === $betaPayout->id);

        // The recovery tooling has no HTTP surface: no route can trigger it.
        foreach (\Illuminate\Support\Facades\Route::getRoutes()->getRoutes() as $route) {
            $this->assertStringNotContainsString('retry', $route->uri());
            $this->assertStringNotContainsString('release', $route->uri());
            $this->assertStringNotContainsString('lookup', $route->uri());
        }
    }

    public function test_the_school_admin_ledger_still_shows_the_recovered_payout_without_operator_details(): void
    {
        Bus::fake([InitiateSchoolPayout::class]);
        $payout = $this->payout(Payout::FAILED);
        $this->artisan('payouts:retry', ['reference' => $payout->reference, '--note' => 'ops ticket 42'])->assertExitCode(0);

        $this->actingAsSchoolAdmin($this->school)->get("/admin/greenfield/payouts/{$payout->id}")
            ->assertOk()->assertSee('Pending')->assertDontSee('ops ticket 42')->assertDontSee('Insufficient balance');
    }

    public function test_reconcile_initiating_is_the_single_rule_shared_with_the_job(): void
    {
        // The job's second run and the operator lookup must agree: same 404, same release.
        Http::fake(['*transfer/verify*' => Http::response(['status' => false, 'message' => 'Transfer not found'], 404)]);
        $viaJob = $this->payout(Payout::INITIATING);
        $viaCommand = $this->payout(Payout::INITIATING);

        $this->runJob($viaJob);
        $this->artisan('payouts:lookup', ['reference' => $viaCommand->reference])->assertExitCode(0);

        $this->assertSame(Payout::FAILED, $viaJob->refresh()->status);
        $this->assertSame(Payout::FAILED, $viaCommand->refresh()->status);
        $this->assertSame(0, $this->transferPosts());
        // Only the operator action is audited; the job's automatic lookup is not an operator event.
        $this->assertSame([$viaCommand->id], PayoutRecoveryEvent::pluck('payout_id')->all());
        $this->assertSame([], Transaction::where('status', '!=', 'success')->pluck('id')->all(), 'payments are never touched by payout recovery');
    }
}
