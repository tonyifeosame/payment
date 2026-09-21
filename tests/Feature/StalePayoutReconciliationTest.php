<?php

namespace Tests\Feature;

use App\Jobs\InitiateSchoolPayout;
use App\Models\Payout;
use App\Models\PayoutRecoveryEvent;
use App\Models\School;
use App\Services\PayoutService;
use App\Services\PaystackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * H1 — stale `initiating` payouts are reconciled automatically by the hourly
 * `payouts:run --dispatch` cron (the exact command render.yaml schedules), through
 * the same lookup-only path as `payouts:lookup --stale`. Nothing here may ever
 * send a transfer.
 */
class StalePayoutReconciliationTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const BASE = 50000.00;

    private const BASE_KOBO = 5000000;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_not-a-real-key']);
        $this->school = $this->makeSchool('Greenfield Academy', 'greenfield', ['paystack_recipient_code' => 'RCP_greenfield']);
    }

    /** An `initiating` payout whose claim was taken $minutesAgo minutes ago. */
    private function initiating(int $minutesAgo, array $overrides = [], ?School $school = null): Payout
    {
        $school ??= $this->school;

        return Payout::create(array_merge([
            'school_id' => $school->id,
            'transaction_id' => $this->makeSuccessfulTransaction($school)->id,
            'reference' => 'PO-'.fake()->unique()->uuid(),
            'amount' => self::BASE,
            'currency' => 'NGN',
            'status' => Payout::INITIATING,
            'attempts' => 1,
            'initiated_at' => now()->subMinutes($minutesAgo),
        ], $overrides));
    }

    private function found(string $status, array $extra = []): array
    {
        return ['status' => true, 'data' => array_merge(['status' => $status, 'transfer_code' => 'TRF_'.strtoupper($status), 'id' => random_int(1, 9999), 'amount' => self::BASE_KOBO, 'currency' => 'NGN'], $extra)];
    }

    private function transferPosts(): int
    {
        return Http::recorded(fn ($r) => str_ends_with($r->url(), '/transfer') && $r->method() === 'POST')->count();
    }

    private function lookups(): int
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), '/transfer/verify/') && $r->method() === 'GET')->count();
    }

    /** The command exactly as the Render cron runs it. */
    private function cron()
    {
        return $this->artisan('payouts:run --dispatch');
    }

    // =====================================================================
    // 1–3, 13. discovery: only stale initiating payouts are looked up
    // =====================================================================

    public function test_the_cron_discovers_stale_initiating_payouts_and_ignores_fresh_ones(): void
    {
        Http::fake(['*transfer/verify*' => Http::response($this->found('success'), 200)]);
        Bus::fake();
        $stale = $this->initiating(PayoutService::STALE_INITIATING_MINUTES + 1);
        $exactlyThreshold = $this->initiating(PayoutService::STALE_INITIATING_MINUTES);
        $fresh = $this->initiating(PayoutService::STALE_INITIATING_MINUTES - 1);
        $legacyOld = $this->initiating(0, ['initiated_at' => null]);
        Payout::whereKey($legacyOld->id)->update(['updated_at' => now()->subHour()]);
        $legacyFresh = $this->initiating(0, ['initiated_at' => null]);

        $this->cron()
            ->expectsOutputToContain('Looking up payouts initiating for over 10 minutes:')
            ->expectsOutputToContain("{$stale->reference}: Paystack reports the transfer as success -> success")
            ->expectsOutputToContain('Stale payouts: found 3, changed 3 (3 success), released to failed 0, still ambiguous 0, resolved elsewhere 0, errors 0')
            ->assertExitCode(0);

        $this->assertSame(3, $this->lookups(), 'one GET per stale payout, none for fresh ones');
        $this->assertSame(Payout::SUCCESS, $stale->refresh()->status);
        $this->assertSame(Payout::SUCCESS, $exactlyThreshold->refresh()->status);
        $this->assertSame(Payout::SUCCESS, $legacyOld->refresh()->status);
        $this->assertSame(Payout::INITIATING, $fresh->refresh()->status, 'a fresh initiating payout is left for its own job/webhook');
        $this->assertSame(Payout::INITIATING, $legacyFresh->refresh()->status);
        Http::assertNotSent(fn ($r) => str_contains($r->url(), $fresh->reference) || str_contains($r->url(), $legacyFresh->reference));
        $this->assertSame(0, $this->transferPosts());
        Bus::assertNotDispatched(InitiateSchoolPayout::class);
    }

    public function test_non_initiating_payouts_are_never_queried(): void
    {
        Http::fake();
        Bus::fake();
        foreach ([Payout::PENDING, Payout::PROCESSING, Payout::SUCCESS, Payout::FAILED, Payout::REVERSED, Payout::NEEDS_REVIEW] as $i => $status) {
            $this->initiating(120, ['status' => $status, 'attempts' => $status === Payout::PENDING ? 1 : 1, 'transfer_code' => "TRF_{$i}"]);
        }
        $before = Payout::orderBy('id')->get()->map->only('id', 'status', 'amount', 'reference')->all();

        $this->cron()->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame($before, Payout::orderBy('id')->get()->map->only('id', 'status', 'amount', 'reference')->all());
        $this->assertDatabaseCount('payout_recovery_events', 0);
    }

    public function test_nothing_stale_means_no_lookup_and_no_output_about_it(): void
    {
        Http::preventStrayRequests();
        Bus::fake();
        $this->initiating(2);

        $this->cron()->doesntExpectOutputToContain('Looking up payouts')->assertExitCode(0);
        $this->assertDatabaseCount('payout_recovery_events', 0);
    }

    // =====================================================================
    // 4–7. Paystack's answer is applied through the existing rules
    // =====================================================================

    public function test_a_transfer_paystack_reports_successful_becomes_success(): void
    {
        Http::fake(['*transfer/verify*' => Http::response($this->found('success', ['transfer_code' => 'TRF_OK', 'id' => 501]), 200)]);
        Bus::fake();
        $payout = $this->initiating(30);

        $this->cron()->assertExitCode(0);

        $payout->refresh();
        $this->assertSame(Payout::SUCCESS, $payout->status);
        $this->assertSame('TRF_OK', $payout->transfer_code);
        $this->assertSame('501', $payout->transfer_id);
        $this->assertNotNull($payout->completed_at);
        $this->assertSame(self::BASE, (float) $payout->amount, 'the amount is never touched');
        $this->assertSame(0, $this->transferPosts());

        $event = PayoutRecoveryEvent::sole();
        $this->assertSame(['lookup', 'initiating', 'success', 'payouts:run', null, 'resolved: initiating -> success'], [$event->action, $event->previous_status, $event->new_status, $event->source, $event->amount, $event->result]);
    }

    public function test_a_transfer_paystack_reports_failed_becomes_failed_and_pending_stays_processing(): void
    {
        $failedRef = null;
        Http::fake(function ($request) use (&$failedRef) {
            if (str_contains($request->url(), (string) $failedRef)) {
                return Http::response($this->found('failed'), 200);
            }

            return Http::response($this->found('pending'), 200);
        });
        Bus::fake();
        $failed = $this->initiating(30);
        $failedRef = $failed->reference;
        $accepted = $this->initiating(30);

        $this->cron()->assertExitCode(0);

        $this->assertSame(Payout::FAILED, $failed->refresh()->status);
        $this->assertStringContainsString('Paystack reported transfer status: failed', $failed->last_error);
        $this->assertSame(Payout::PROCESSING, $accepted->refresh()->status, 'accepted-but-unfinished is processing, never success');
        $this->assertSame(0, $this->transferPosts());
        $this->assertEqualsCanonicalizing(['resolved: initiating -> failed', 'resolved: initiating -> processing'], PayoutRecoveryEvent::pluck('result')->all());
    }

    public function test_a_transfer_paystack_has_never_seen_is_released_to_failed_for_retry(): void
    {
        Http::fake(['*transfer/verify*' => Http::response(['status' => false, 'message' => 'Transfer not found'], 404)]);
        Bus::fake();
        $payout = $this->initiating(30);

        $this->cron()
            ->expectsOutputToContain("{$payout->reference}: Paystack has no transfer for this reference -> failed (retry with payouts:retry once the cause is known)")
            ->expectsOutputToContain('released to failed 1')
            ->assertExitCode(0);

        $payout->refresh();
        $this->assertSame(Payout::FAILED, $payout->status);
        $this->assertStringContainsString('Paystack has no transfer for this reference', $payout->last_error);
        $this->assertSame(0, $this->transferPosts());
        Bus::assertNotDispatched(InitiateSchoolPayout::class, 'the cron never re-sends; an operator retries');
        $this->assertSame('released: initiating -> failed', PayoutRecoveryEvent::sole()->result);

        // …and it is genuinely retryable through the existing operator path.
        Http::fake(['*/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_R', 'id' => 7, 'status' => 'pending']], 200)]);
        $this->artisan('payouts:retry', ['reference' => $payout->reference])->assertExitCode(0);
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(PaystackService::class));
        $this->assertSame(Payout::PROCESSING, $payout->refresh()->status);
        $this->assertSame(1, $this->transferPosts());
    }

    public function test_an_ambiguous_answer_leaves_the_payout_initiating_for_the_next_run(): void
    {
        $paystackUp = false;
        Http::fake(function () use (&$paystackUp) {
            return $paystackUp ? Http::response($this->found('success'), 200) : Http::response('<html>502</html>', 502);
        });
        Bus::fake();
        $payout = $this->initiating(30);

        $this->cron()
            ->expectsOutputToContain("{$payout->reference}: still unknown")
            ->expectsOutputToContain('still ambiguous 1')
            ->assertExitCode(0, 'an ambiguous provider answer is not an error of the run');

        $this->assertSame(Payout::INITIATING, $payout->refresh()->status);
        $this->assertSame(0, $this->transferPosts());
        $this->assertSame(0, PayoutRecoveryEvent::count(), 'no event when nothing changed — an hourly run must not spam the audit trail');

        // Next hour, Paystack answers: resolved.
        $paystackUp = true;
        $this->cron()->assertExitCode(0);
        $this->assertSame(Payout::SUCCESS, $payout->refresh()->status);
        $this->assertSame(1, PayoutRecoveryEvent::count());
    }

    public function test_a_successful_transfer_with_the_wrong_amount_is_parked_for_review(): void
    {
        Http::fake(['*transfer/verify*' => Http::response($this->found('success', ['amount' => self::BASE_KOBO + 100]), 200)]);
        Bus::fake();
        $payout = $this->initiating(30);

        $this->cron()->expectsOutputToContain('changed 1 (1 needs_review)')->assertExitCode(0);

        $payout->refresh();
        $this->assertSame(Payout::NEEDS_REVIEW, $payout->status, 'HIGH-2 still applies through the automatic path');
        $this->assertStringContainsString('amount mismatch', $payout->last_error);
        $this->assertSame(self::BASE, (float) $payout->amount);
        $this->assertSame('resolved: initiating -> needs_review', PayoutRecoveryEvent::sole()->result);
        $this->assertSame(0, $this->transferPosts());
    }

    public function test_a_reversed_transfer_is_recorded_as_reversed(): void
    {
        Http::fake(['*transfer/verify*' => Http::response($this->found('reversed'), 200)]);
        Bus::fake();
        $payout = $this->initiating(30);

        $this->cron()->assertExitCode(0);

        $this->assertSame(Payout::REVERSED, $payout->refresh()->status);
        $this->assertTrue($payout->isTerminal());
    }

    // =====================================================================
    // 8–10. overlap with webhooks, workers and other runs
    // =====================================================================

    public function test_a_payout_resolved_by_a_webhook_between_selection_and_lookup_is_left_alone(): void
    {
        // The webhook lands while the cron is running: reconcileInitiating re-reads
        // the row before asking Paystack and skips it.
        $payout = $this->initiating(30);
        Http::fake(function ($request) use ($payout) {
            // Simulate the race: by the time the lookup would go out, the row is success.
            Payout::whereKey($payout->id)->update(['status' => Payout::SUCCESS, 'transfer_code' => 'TRF_WH', 'completed_at' => now()]);

            return Http::response($this->found('failed'), 200); // a stale "failed" answer must not win
        });
        Bus::fake();

        // The re-read happens before the request, so the stale answer is never requested.
        $service = app(PayoutService::class);
        Payout::whereKey($payout->id)->update(['status' => Payout::SUCCESS, 'transfer_code' => 'TRF_WH', 'completed_at' => now()]);
        $counts = $service->reconcileStaleInitiating(app(PaystackService::class));

        $this->assertSame(0, $counts['found'], 'a payout that is no longer initiating is not even selected');
        $this->assertSame(Payout::SUCCESS, $payout->refresh()->status);
        Http::assertNothingSent();
    }

    public function test_a_late_failed_answer_cannot_downgrade_a_payout_that_became_success(): void
    {
        // Selected as stale, but a transfer.success webhook is applied after the
        // selection and before the answer is applied. applyPaystackStatus() is
        // transition-checked under the row lock: success -> failed is refused.
        $payout = $this->initiating(30);
        Http::fake(function () use ($payout) {
            app(PayoutService::class)->applyPaystackStatus($payout->fresh(), 'success', ['reference' => $payout->reference, 'transfer_code' => 'TRF_WH', 'id' => 9, 'amount' => self::BASE_KOBO, 'currency' => 'NGN']);

            return Http::response($this->found('failed'), 200);
        });
        Bus::fake();

        $counts = app(PayoutService::class)->reconcileStaleInitiating(app(PaystackService::class));

        $this->assertSame(1, $counts['found']);
        $this->assertSame(Payout::SUCCESS, $payout->refresh()->status, 'success is terminal for a failed report');
        $this->assertSame('TRF_WH', $payout->transfer_code);
        // The lookup answer changed nothing: reconcileInitiating reports the status
        // the machine kept, so the run records it as a change to success made
        // elsewhere, never as failed.
        $this->assertSame([Payout::SUCCESS], PayoutRecoveryEvent::pluck('new_status')->all());
    }

    public function test_a_payout_that_became_failed_elsewhere_is_not_modified(): void
    {
        $payout = $this->initiating(30);
        Payout::whereKey($payout->id)->update(['status' => Payout::FAILED, 'last_error' => 'Recipient blocked', 'completed_at' => now()]);
        Http::fake();
        Bus::fake();

        $this->cron()->assertExitCode(0);

        Http::assertNothingSent();
        $this->assertSame(Payout::FAILED, $payout->refresh()->status);
        $this->assertSame('Recipient blocked', $payout->last_error);
        Bus::assertNotDispatched(InitiateSchoolPayout::class);
    }

    public function test_overlapping_runs_and_a_worker_cannot_produce_a_second_transfer(): void
    {
        Http::fake([
            '*transfer/verify*' => Http::response(['status' => false, 'message' => 'Transfer not found'], 404),
            '*/transfer' => Http::response(['status' => true, 'data' => ['transfer_code' => 'TRF_ONE', 'id' => 1, 'status' => 'pending']], 200),
        ]);
        Bus::fake();
        $payout = $this->initiating(30);

        // Two cron executions overlap on the same stale payout.
        $first = app(PayoutService::class)->reconcileStaleInitiating(app(PaystackService::class));
        $second = app(PayoutService::class)->reconcileStaleInitiating(app(PaystackService::class));
        $this->assertSame(1, $first['released']);
        $this->assertSame(0, $second['found'], 'the second run finds nothing initiating');
        $this->assertSame(Payout::FAILED, $payout->refresh()->status);

        // The worker job running at the same time on a failed payout does nothing.
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(PaystackService::class));
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(PaystackService::class));
        $this->assertSame(0, $this->transferPosts());
        $this->assertSame(Payout::FAILED, $payout->refresh()->status);

        // Only an operator retry sends — once, however many times the job then runs.
        $this->artisan('payouts:retry', ['reference' => $payout->reference])->assertExitCode(0);
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(PaystackService::class));
        (new InitiateSchoolPayout($payout->id))->handle(app(PayoutService::class), app(PaystackService::class));
        $this->assertSame(1, $this->transferPosts());
        $this->assertSame(Payout::PROCESSING, $payout->refresh()->status);
    }

    // =====================================================================
    // 11–12. never a transfer; manual command unchanged
    // =====================================================================

    public function test_automatic_reconciliation_performs_only_get_lookups(): void
    {
        Http::fake(['*transfer/verify*' => Http::response($this->found('success'), 200)]);
        Bus::fake();
        $this->initiating(30);
        $this->initiating(45);

        $this->cron()->assertExitCode(0);

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
        Http::assertSent(fn ($r) => $r->method() === 'GET' && str_contains($r->url(), 'https://api.paystack.co/transfer/verify/PO-'));
        $this->assertSame(0, $this->transferPosts());
        $this->assertSame(2, Payout::count(), 'no payout created');
        $this->assertSame(2, Payout::where('reference', 'like', 'PO-%')->count());
        Bus::assertNotDispatched(InitiateSchoolPayout::class);
    }

    public function test_manual_stale_lookup_still_works_and_records_operator_events(): void
    {
        Http::fake(['*transfer/verify*' => Http::response($this->found('success'), 200)]);
        $stale = $this->initiating(30);
        $fresh = $this->initiating(2);

        $this->artisan('payouts:lookup', ['--stale' => true])
            ->expectsOutputToContain('1 initiating payout(s) older than 10 minutes.')
            ->expectsOutputToContain('resolved: 1')
            ->assertExitCode(0);

        $this->assertSame(Payout::SUCCESS, $stale->refresh()->status);
        $this->assertSame(Payout::INITIATING, $fresh->refresh()->status);
        $event = PayoutRecoveryEvent::sole();
        $this->assertSame([PayoutRecoveryEvent::SOURCE_ARTISAN, 'resolved: initiating -> success'], [$event->source, $event->result]);
    }

    public function test_a_dry_run_only_counts_stale_payouts_and_contacts_nobody(): void
    {
        Http::preventStrayRequests();
        Bus::fake();
        $payout = $this->initiating(30);

        $this->artisan('payouts:run --dry-run')
            ->expectsOutputToContain('1 payout(s) have been initiating for over 10 minutes and would be looked up (dry run: not contacted).')
            ->assertExitCode(0);

        $this->assertSame(Payout::INITIATING, $payout->refresh()->status);
        $this->assertDatabaseCount('payout_recovery_events', 0);
    }

    // =====================================================================
    // 14–15. audit trail, isolation, resilience, safe logging
    // =====================================================================

    public function test_one_failing_lookup_does_not_stop_the_others_and_is_reported(): void
    {
        $brokenRef = null;
        Http::fake(function ($request) use (&$brokenRef) {
            if (str_contains($request->url(), (string) $brokenRef)) {
                throw new \RuntimeException('connection reset by peer');
            }

            return Http::response($this->found('success'), 200);
        });
        Bus::fake();
        $a = $this->initiating(30);
        $broken = $this->initiating(31);
        $brokenRef = $broken->reference;
        $c = $this->initiating(32);

        // PaystackService::fetchTransfer catches transport errors as "unknown", so the
        // broken one stays initiating; the run continues and the others resolve.
        $this->cron()->expectsOutputToContain('found 3, changed 2 (2 success), released to failed 0, still ambiguous 1')->assertExitCode(0);

        $this->assertSame(Payout::SUCCESS, $a->refresh()->status);
        $this->assertSame(Payout::INITIATING, $broken->refresh()->status);
        $this->assertSame(Payout::SUCCESS, $c->refresh()->status);
        $this->assertSame(2, PayoutRecoveryEvent::count());
    }

    public function test_an_exception_inside_reconciliation_is_isolated_and_counted(): void
    {
        Http::fake(['*transfer/verify*' => Http::response($this->found('success'), 200)]);
        Bus::fake();
        $a = $this->initiating(30);
        $broken = $this->initiating(31);
        $c = $this->initiating(32);

        $real = new PayoutService;
        $this->partialMock(PayoutService::class, function ($mock) use ($broken, $real) {
            $mock->shouldReceive('reconcileInitiating')->andReturnUsing(function (Payout $payout, PaystackService $paystack) use ($broken, $real) {
                if ($payout->id === $broken->id) {
                    throw new \RuntimeException('database went away');
                }

                return $real->reconcileInitiating($payout, $paystack);
            });
        });

        $this->cron()
            ->expectsOutputToContain("FAILED {$broken->reference}: database went away")
            ->expectsOutputToContain('errors 1')
            ->assertExitCode(1);

        $this->assertSame(Payout::SUCCESS, $a->refresh()->status);
        $this->assertSame(Payout::INITIATING, $broken->refresh()->status);
        $this->assertSame(Payout::SUCCESS, $c->refresh()->status);
    }

    public function test_reconciliation_is_scoped_per_payout_and_output_never_reveals_secrets(): void
    {
        $beta = $this->makeSchool('Beta School', 'beta', ['paystack_recipient_code' => 'RCP_beta', 'account_number' => '9876543210']);
        $alphaRef = null;
        Http::fake(function ($request) use (&$alphaRef) {
            return str_contains($request->url(), (string) $alphaRef)
                ? Http::response($this->found('success'), 200)
                : Http::response(['status' => false, 'message' => 'Transfer not found'], 404);
        });
        Bus::fake();
        $alpha = $this->initiating(30);
        $alphaRef = $alpha->reference;
        $betaPayout = $this->initiating(30, [], $beta);

        $this->cron()
            ->doesntExpectOutputToContain('sk_test_not-a-real-key')
            ->doesntExpectOutputToContain('0123456789')
            ->doesntExpectOutputToContain('9876543210')
            ->doesntExpectOutputToContain('RCP_')
            ->assertExitCode(0);

        $this->assertSame(Payout::SUCCESS, $alpha->refresh()->status);
        $this->assertSame(Payout::FAILED, $betaPayout->refresh()->status);
        $this->assertSame([$this->school->id, $beta->id], PayoutRecoveryEvent::orderBy('id')->pluck('school_id')->all(), 'each event belongs to its payout\'s own school');
        $this->assertSame([$alpha->id, $betaPayout->id], PayoutRecoveryEvent::orderBy('id')->pluck('payout_id')->all());
        foreach (PayoutRecoveryEvent::all() as $event) {
            $encoded = json_encode($event->toArray());
            $this->assertStringNotContainsString('sk_test', $encoded);
            $this->assertStringNotContainsString('RCP_', $encoded);
            $this->assertStringNotContainsString('9876543210', $encoded);
        }
        // Payments are untouched by payout reconciliation.
        $this->assertSame(0, DB::table('transactions')->where('status', '!=', 'success')->count());
    }
}
