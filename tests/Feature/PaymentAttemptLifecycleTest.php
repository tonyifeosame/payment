<?php

namespace Tests\Feature;

use App\Jobs\InitiateSchoolPayout;
use App\Mail\PaymentReceiptMail;
use App\Models\Payout;
use App\Models\School;
use App\Models\Transaction;
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * H5 — the payment-attempt lifecycle: a definitive Paystack failure is recorded
 * as `failed`; ambiguity leaves `pending`; checkouts pending past the configured
 * window are VERIFIED (never aged out blindly) by `payments:expire-pending`; and
 * `success` can never be overwritten by any of it.
 */
class PaymentAttemptLifecycleTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const GROSS = 51250.00;

    private const GROSS_KOBO = 5125000;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Bus::fake([InitiateSchoolPayout::class]);
        config(['services.paystack.secret_key' => 'sk_test_not-a-real-key', 'fees.pending_payment_expiry_hours' => 24]);
        $this->alpha = $this->makeSchool('Alpha School', 'alpha', ['paystack_recipient_code' => 'RCP_alpha']);
        $this->beta = $this->makeSchool('Beta School', 'beta', ['paystack_recipient_code' => 'RCP_beta']);
    }

    /** A pending checkout as PaymentCheckoutService records it (gross 51,250 = 50,000 + 1,250). */
    private function pending(School $school, string $reference, int $hoursAgo = 0): Transaction
    {
        $t = Transaction::create([
            'school_id' => $school->id, 'reference' => $reference, 'amount' => self::GROSS, 'fee_amount' => 50000, 'service_fee' => 1250,
            'status' => 'pending', 'email' => 'parent@example.test', 'name' => 'Ada Parent', 'category_name' => 'School Fees', 'subcategory_name' => 'Tuition',
            'meta_data' => ['quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 1250, 'gross_amount' => self::GROSS],
        ]);
        if ($hoursAgo > 0) {
            Transaction::whereKey($t->id)->update(['created_at' => now()->subHours($hoursAgo), 'updated_at' => now()->subHours($hoursAgo)]);
        }

        return $t->refresh();
    }

    /** Paystack's verify answer for a reference. */
    private function verifyAnswer(string $status, array $extra = []): array
    {
        return ['status' => true, 'data' => array_merge(['status' => $status, 'reference' => 'x', 'amount' => self::GROSS_KOBO, 'currency' => 'NGN', 'channel' => 'card', 'gateway_response' => ucfirst($status)], $extra)];
    }

    /** Fake verify per reference: ['ref' => response|closure]; unknown references get a 404. */
    private function fakeVerify(array $byReference): void
    {
        Http::fake(function ($request) use ($byReference) {
            if (! str_contains($request->url(), '/transaction/verify/')) {
                return Http::response(['status' => false, 'message' => 'unexpected call'], 500);
            }
            $reference = rawurldecode(basename($request->url()));
            $answer = $byReference[$reference] ?? null;
            if ($answer === null) {
                return Http::response(['status' => false, 'message' => 'Transaction reference not found'], 404);
            }

            return is_callable($answer) ? $answer($request) : $answer;
        });
    }

    private function webhook(string $reference)
    {
        $body = json_encode(['event' => 'charge.success', 'data' => ['reference' => $reference, 'status' => 'success', 'amount' => self::GROSS_KOBO]]);

        return $this->call('POST', '/paystack/webhook', [], [], [], ['HTTP_X_PAYSTACK_SIGNATURE' => hash_hmac('sha512', $body, 'sk_test_not-a-real-key'), 'CONTENT_TYPE' => 'application/json'], $body);
    }

    private function verifyCalls(): int
    {
        return Http::recorded(fn ($r) => str_contains($r->url(), '/transaction/verify/'))->count();
    }

    private function assertUntouchedSuccess(Transaction $t, string $paidAt): void
    {
        $t->refresh();
        $this->assertSame('success', $t->status);
        $this->assertSame($paidAt, $t->paid_at->toIso8601String(), 'paid_at written once, never rewritten');
        $this->assertSame(1, Payout::where('transaction_id', $t->id)->count(), 'exactly one payout obligation');
    }

    // =====================================================================
    // 1–4. what counts as failure
    // =====================================================================

    public function test_a_definitive_failed_charge_is_recorded_as_failed_with_its_reason(): void
    {
        $t = $this->pending($this->alpha, 'ref-declined');
        $this->fakeVerify([
            'ref-declined' => Http::response($this->verifyAnswer('failed', ['gateway_response' => 'Declined by issuer']), 200),
            'ref-reversed' => Http::response($this->verifyAnswer('reversed'), 200),
        ]);

        $this->get('/payment/callback?reference=ref-declined')->assertRedirect('/s/alpha/payment')->assertSessionHas('error', 'Payment failed!');

        $t->refresh();
        $this->assertSame('failed', $t->status);
        $this->assertNull($t->paid_at);
        $this->assertSame('ref-declined', $t->reference);
        $this->assertSame(self::GROSS, (float) $t->amount, 'the attempted amount is preserved');
        $this->assertSame('failed', $t->failure()['paystack_status']);
        $this->assertSame('Declined by issuer', $t->failure()['gateway_response']);
        $this->assertSame('verify', $t->failure()['source']);
        $this->assertNotNull($t->failure()['observed_at']);
        $this->assertSame(50000.0, $t->receiptBreakdown()['fee_subtotal'], 'receiptBreakdown() untouched');
        $this->assertDatabaseCount('payouts', 0);
        Mail::assertNothingQueued();
        Bus::assertNotDispatched(InitiateSchoolPayout::class);

        // A reversed charge on a pending row is equally definitive.
        $r = $this->pending($this->alpha, 'ref-reversed');
        $this->assertSame(PaymentSettlementService::FAILED_RECORDED, app(PaymentSettlementService::class)->settleByReference('ref-reversed')['outcome']);
        $this->assertSame('reversed', $r->refresh()->failure()['paystack_status']);
    }

    /** @dataProvider ambiguousAnswers */
    public function test_an_ambiguous_provider_answer_leaves_the_attempt_pending(string $case, callable $response): void
    {
        $t = $this->pending($this->alpha, 'ref-ambiguous');
        $this->fakeVerify(['ref-ambiguous' => $response]);

        $outcome = app(PaymentSettlementService::class)->settleByReference('ref-ambiguous')['outcome'];

        $this->assertContains($outcome, [PaymentSettlementService::NOT_SUCCESSFUL, PaymentSettlementService::VERIFICATION_FAILED], $case);
        $this->assertSame('pending', $t->refresh()->status, "{$case} is not proof of failure");
        $this->assertNull($t->failure());
        $this->assertDatabaseCount('payouts', 0);
    }

    public static function ambiguousAnswers(): array
    {
        $answer = fn (string $status) => fn () => Http::response(['status' => true, 'data' => ['status' => $status, 'amount' => 5125000, 'currency' => 'NGN']], 200);

        return [
            'paystack still pending' => ['pending', $answer('pending')],
            'paystack ongoing' => ['ongoing', $answer('ongoing')],
            'paystack queued' => ['queued', $answer('queued')],
            'paystack processing' => ['processing', $answer('processing')],
            'abandoned within the window' => ['abandoned', $answer('abandoned')],
            'a status we have never seen' => ['brand_new_status', $answer('brand_new_status')],
            'network timeout' => ['timeout', fn () => throw new \Illuminate\Http\Client\ConnectionException('cURL error 28: timed out')],
            'paystack 5xx' => ['5xx', fn () => Http::response('<html>502</html>', 502)],
            'unreadable body' => ['garbage', fn () => Http::response('not json', 200)],
            'reference not found (within window)' => ['404', fn () => Http::response(['status' => false, 'message' => 'Transaction reference not found'], 404)],
        ];
    }

    public function test_browser_abandonment_alone_never_marks_an_attempt_failed(): void
    {
        // The parent closed the tab: no callback, no webhook, no verification at all.
        Http::preventStrayRequests();
        $t = $this->pending($this->alpha, 'ref-closed-tab', hoursAgo: 3);

        $this->artisan('payments:expire-pending')->expectsOutputToContain('No payments have been pending for more than 24 hours.')->assertExitCode(0);

        $this->assertSame('pending', $t->refresh()->status);
    }

    // =====================================================================
    // 5–8. the expiry pass: verify first, never age alone
    // =====================================================================

    public function test_old_pending_attempts_are_verified_and_resolved_by_paystacks_answer(): void
    {
        $abandoned = $this->pending($this->alpha, 'ref-abandoned', 30);
        $declined = $this->pending($this->alpha, 'ref-declined', 30);
        $neverReached = $this->pending($this->alpha, 'ref-never', 30);   // 404 at Paystack
        $stillOpen = $this->pending($this->alpha, 'ref-open', 30);
        $lateSuccess = $this->pending($this->alpha, 'ref-late', 30);
        $unreachable = $this->pending($this->alpha, 'ref-down', 30);
        $recent = $this->pending($this->alpha, 'ref-recent', 23);
        $this->fakeVerify([
            'ref-abandoned' => Http::response($this->verifyAnswer('abandoned'), 200),
            'ref-declined' => Http::response($this->verifyAnswer('failed', ['gateway_response' => 'Insufficient Funds']), 200),
            'ref-open' => Http::response($this->verifyAnswer('pending'), 200),
            'ref-late' => Http::response($this->verifyAnswer('success', ['reference' => 'ref-late']), 200),
            'ref-down' => fn () => Http::response('', 503),
        ]);

        $this->artisan('payments:expire-pending')
            ->expectsOutputToContain('6 payment(s) pending for more than 24 hours')
            ->expectsOutputToContain('ref-late: Paystack reports success — settled now')
            ->expectsOutputToContain('processed: 6 · settled: 1 · failed: 3 · still pending: 1 · unverified: 1 · skipped: 0 · errors: 0')
            ->assertExitCode(0);

        $this->assertSame('failed', $abandoned->refresh()->status);
        $this->assertSame('abandoned', $abandoned->failure()['paystack_status']);
        $this->assertSame('expiry', $abandoned->failure()['source']);
        $this->assertSame('failed', $declined->refresh()->status);
        $this->assertSame('Insufficient Funds', $declined->failure()['gateway_response']);
        $this->assertSame('failed', $neverReached->refresh()->status);
        $this->assertSame('not_found', $neverReached->failure()['paystack_status']);
        $this->assertSame('pending', $stillOpen->refresh()->status, 'Paystack still calls it open');
        $this->assertSame('pending', $unreachable->refresh()->status, 'no answer is not a failure');
        $this->assertSame('pending', $recent->refresh()->status, 'inside the window: not even asked');
        Http::assertNotSent(fn ($r) => str_contains($r->url(), 'ref-recent'));

        // The late success is a real settlement: paid_at, receipt, payout — once.
        $lateSuccess->refresh();
        $this->assertSame('success', $lateSuccess->status);
        $this->assertNotNull($lateSuccess->paid_at);
        Mail::assertQueued(PaymentReceiptMail::class, 1);
        $this->assertSame(1, Payout::where('transaction_id', $lateSuccess->id)->where('status', Payout::PENDING)->count());
        Bus::assertDispatched(InitiateSchoolPayout::class, 1);
        $this->assertSame(0, Payout::whereIn('transaction_id', [$abandoned->id, $declined->id, $neverReached->id])->count(), 'a failed attempt never creates a payout');
    }

    public function test_the_window_is_configurable_and_a_dry_run_contacts_nobody(): void
    {
        config(['fees.pending_payment_expiry_hours' => 2]);
        Http::preventStrayRequests();
        $old = $this->pending($this->alpha, 'ref-3h', 3);
        $this->pending($this->alpha, 'ref-1h', 1);

        $this->artisan('payments:expire-pending --dry-run')
            ->expectsOutputToContain('1 payment(s) pending for more than 2 hours (dry run: nothing will be verified or changed)')
            ->expectsOutputToContain('ref-3h — school #'.$this->alpha->id.' — NGN 51,250.00')
            ->assertExitCode(0);

        $this->assertSame('pending', $old->refresh()->status);
    }

    public function test_successful_and_paid_transactions_are_never_selected_or_expired(): void
    {
        $paid = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-paid', 'paid_at' => now()->subDays(40)]);
        Transaction::whereKey($paid->id)->update(['created_at' => now()->subDays(40)]);
        Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => $paid->id, 'reference' => 'PO-paid', 'amount' => 50000, 'currency' => 'NGN', 'status' => Payout::SUCCESS]);
        // A malformed row: pending status but paid_at set — treated as settled, never touched.
        $oddity = $this->pending($this->alpha, 'ref-odd', 40);
        Transaction::whereKey($oddity->id)->update(['paid_at' => now()->subDays(40)]);
        $paidAt = $paid->fresh()->paid_at->toIso8601String();
        Http::preventStrayRequests();

        $this->artisan('payments:expire-pending')->expectsOutputToContain('No payments have been pending')->assertExitCode(0);

        $this->assertUntouchedSuccess($paid, $paidAt);
        $this->assertSame('pending', $oddity->refresh()->status);
        $this->assertNotNull($oddity->paid_at);
        // Even when handed a settled transaction directly, the reconciliation refuses.
        $this->assertSame(PaymentSettlementService::ALREADY_SETTLED, app(PaymentSettlementService::class)->reconcilePendingAttempt($paid->fresh())['outcome']);
        $this->assertUntouchedSuccess($paid, $paidAt);
    }

    // =====================================================================
    // 9–11, 14–15. races: success always wins
    // =====================================================================

    public function test_a_success_webhook_arriving_while_expiry_runs_wins(): void
    {
        // The expiry pass asks Paystack; while it waits, the success webhook settles
        // the row. Paystack's answer to the expiry pass (abandoned, from a moment
        // earlier) must not downgrade the settled payment.
        $t = $this->pending($this->alpha, 'ref-race', 30);
        // Deterministic form of the race: the webhook settles first, then the expiry
        // pass — which read the row as pending before that — receives a stale
        // "abandoned" answer and tries to apply it.
        Http::fake(['*transaction/verify/ref-race' => Http::sequence()
            ->push($this->verifyAnswer('success', ['reference' => 'ref-race']), 200)
            ->push($this->verifyAnswer('abandoned'), 200)]);

        $this->webhook('ref-race')->assertOk()->assertJson(['status' => 'settled']);
        $paidAt = $t->refresh()->paid_at->toIso8601String();

        $this->assertSame(PaymentSettlementService::ALREADY_SETTLED, app(PaymentSettlementService::class)->reconcilePendingAttempt($t)['outcome']);
        $this->assertUntouchedSuccess($t, $paidAt);
        $this->assertNull($t->failure(), 'no failure was ever written to a settled row');

        // And the stale "abandoned" answer applied at the lowest level still loses.
        $service = app(PaymentSettlementService::class);
        $method = new \ReflectionMethod($service, 'recordFailure');
        $this->assertSame(PaymentSettlementService::ALREADY_SETTLED, $method->invoke($service, $t->fresh(), 'abandoned', 'stale', null, 'expiry')['outcome']);
        $this->assertUntouchedSuccess($t, $paidAt);
        $this->assertNull($t->failure());
    }

    public function test_a_callback_racing_expiry_cannot_downgrade_a_success_and_expiry_cannot_downgrade_a_callback_success(): void
    {
        $t = $this->pending($this->alpha, 'ref-cb', 30);
        // Expiry sees "abandoned" first, then the parent's browser returns with success
        // (the parent completed the checkout after all).
        Http::fake(['*transaction/verify/ref-cb' => Http::sequence()
            ->push($this->verifyAnswer('abandoned'), 200)
            ->push($this->verifyAnswer('success', ['reference' => 'ref-cb']), 200)
            ->push($this->verifyAnswer('failed'), 200)]);

        $this->artisan('payments:expire-pending')->assertExitCode(0);
        $this->assertSame('failed', $t->refresh()->status);

        // Provider success discovered after the local failure: the safe recovery path
        // is simply the normal settlement — failed -> success, once, with payout.
        $this->get('/payment/callback?reference=ref-cb')->assertSessionHas('success');
        $t->refresh();
        $this->assertSame('success', $t->status);
        $paidAt = $t->paid_at->toIso8601String();
        $this->assertSame(1, Payout::where('transaction_id', $t->id)->count());
        Mail::assertQueued(PaymentReceiptMail::class, 1);
        $this->assertSame('abandoned', $t->failure()['paystack_status'], 'the earlier failure stays in the history; the status is what counts');

        // A later stale "failed" answer (third response) changes nothing.
        $this->get('/payment/callback?reference=ref-cb')->assertSessionHas('success'); // fast path: already settled, no verify call
        $this->assertSame(PaymentSettlementService::ALREADY_SETTLED, app(PaymentSettlementService::class)->reconcilePendingAttempt($t)['outcome']);
        $this->assertUntouchedSuccess($t, $paidAt);
    }

    public function test_duplicate_success_webhooks_and_callbacks_after_a_failure_are_idempotent(): void
    {
        $t = $this->pending($this->alpha, 'ref-dup', 30);
        Http::fake(['*transaction/verify/ref-dup' => Http::sequence()
            ->push($this->verifyAnswer('failed'), 200)
            ->push($this->verifyAnswer('success', ['reference' => 'ref-dup']), 200)
            ->push($this->verifyAnswer('success', ['reference' => 'ref-dup']), 200)]);

        $this->get('/payment/callback?reference=ref-dup')->assertSessionHas('error');
        $this->assertSame('failed', $t->refresh()->status);

        $this->webhook('ref-dup')->assertOk()->assertJson(['status' => 'settled']);
        $paidAt = $t->refresh()->paid_at->toIso8601String();
        $this->webhook('ref-dup')->assertOk()->assertJson(['status' => 'already_settled']);
        $this->webhook('ref-dup')->assertOk()->assertJson(['status' => 'already_settled']);
        $this->get('/payment/callback?reference=ref-dup')->assertSessionHas('success');

        $this->assertUntouchedSuccess($t, $paidAt);
        Mail::assertQueued(PaymentReceiptMail::class, 1);
        Bus::assertDispatched(InitiateSchoolPayout::class, 1);
        $this->assertSame(2, $this->verifyCalls(), 'settled rows are answered from the fast path');
    }

    public function test_two_expiry_workers_processing_the_same_attempt_write_the_failure_once(): void
    {
        $t = $this->pending($this->alpha, 'ref-two', 30);
        $this->fakeVerify(['ref-two' => Http::response($this->verifyAnswer('abandoned'), 200)]);
        $service = app(PaymentSettlementService::class);

        $first = $service->reconcilePendingAttempt($t)['outcome'];
        $second = $service->reconcilePendingAttempt(Transaction::find($t->id))['outcome'];
        $third = $this->artisan('payments:expire-pending')->assertExitCode(0);

        $this->assertSame(PaymentSettlementService::FAILED_RECORDED, $first);
        $this->assertSame(PaymentSettlementService::ALREADY_FAILED, $second);
        $t->refresh();
        $this->assertSame('failed', $t->status);
        $this->assertSame(1, $this->verifyCalls(), 'an already-failed row is not re-verified');
        $observed = $t->failure()['observed_at']->toIso8601String();
        // A concurrent worker that had already read the row as pending applies the
        // same answer under the lock and finds it failed: no second write.
        $method = new \ReflectionMethod($service, 'recordFailure');
        $this->assertSame(PaymentSettlementService::ALREADY_FAILED, $method->invoke($service, Transaction::find($t->id), 'abandoned', 'again', null, 'expiry')['outcome']);
        $this->assertSame($observed, $t->refresh()->failure()['observed_at']->toIso8601String());
    }

    public function test_a_mismatch_under_review_is_never_turned_into_a_failure(): void
    {
        $t = $this->pending($this->alpha, 'ref-mismatch', 30);
        Http::fake(['*transaction/verify/ref-mismatch' => Http::sequence()
            ->push($this->verifyAnswer('success', ['amount' => self::GROSS_KOBO - 100, 'reference' => 'ref-mismatch']), 200)
            ->push($this->verifyAnswer('abandoned'), 200)]);

        $this->webhook('ref-mismatch')->assertOk()->assertJson(['status' => 'amount_mismatch']);
        $this->assertSame('mismatch', $t->refresh()->status);

        $this->artisan('payments:expire-pending')->expectsOutputToContain('No payments have been pending')->assertExitCode(0);
        $this->assertSame(PaymentSettlementService::NOT_SUCCESSFUL, app(PaymentSettlementService::class)->reconcilePendingAttempt($t)['outcome']);
        $this->assertSame('mismatch', $t->refresh()->status, 'a human decides mismatches; expiry never does');
    }

    // =====================================================================
    // 12–13, 19–20. payouts, tenants, repeat safety, no money movement
    // =====================================================================

    public function test_expiry_is_tenant_blind_but_tenant_safe_and_touches_nothing_else(): void
    {
        $alphaOld = $this->pending($this->alpha, 'ref-alpha-old', 30);
        $betaOld = $this->pending($this->beta, 'ref-beta-old', 30);
        $betaPaid = $this->makeSuccessfulTransaction($this->beta, ['reference' => 'ref-beta-paid']);
        Payout::create(['school_id' => $this->beta->id, 'transaction_id' => $betaPaid->id, 'reference' => 'PO-beta', 'amount' => 50000, 'currency' => 'NGN', 'status' => Payout::PROCESSING, 'transfer_code' => 'TRF_B']);
        $this->fakeVerify(['ref-alpha-old' => Http::response($this->verifyAnswer('abandoned'), 200), 'ref-beta-old' => Http::response($this->verifyAnswer('abandoned'), 200)]);

        $this->artisan('payments:expire-pending')->expectsOutputToContain('processed: 2 · settled: 0 · failed: 2')->assertExitCode(0);

        // Each row changed under its own school id; nothing crossed, nothing else moved.
        $this->assertSame($this->alpha->id, $alphaOld->refresh()->school_id);
        $this->assertSame($this->beta->id, $betaOld->refresh()->school_id);
        $this->assertSame(['failed', 'failed', 'success'], [$alphaOld->status, $betaOld->status, $betaPaid->refresh()->status]);
        $this->assertSame(Payout::PROCESSING, Payout::where('reference', 'PO-beta')->value('status'));
        $this->assertSame(1, Payout::count());
        Http::assertNotSent(fn ($r) => $r->method() !== 'GET');
        Bus::assertNotDispatched(InitiateSchoolPayout::class);
        Mail::assertNothingQueued();

        // Running it again: nothing to do, nobody contacted, same state.
        Http::fake();
        $this->artisan('payments:expire-pending')->expectsOutputToContain('No payments have been pending')->assertExitCode(0);
        Http::assertNothingSent();
        $this->assertSame(['failed', 'failed'], [$alphaOld->refresh()->status, $betaOld->refresh()->status]);

        // Tenant boundaries in the admin: each school sees only its own failed attempt.
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions?status=failed')->assertOk()->assertSee('ref-alpha-old')->assertDontSee('ref-beta-old');
        $this->actingAsSchoolAdmin($this->beta)->get('/admin/beta/transactions?status=failed')->assertOk()->assertSee('ref-beta-old')->assertDontSee('ref-alpha-old');
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/transactions/{$betaOld->id}")->assertNotFound();
    }

    public function test_a_public_callback_cannot_reach_another_schools_row_and_the_limit_batches_the_backlog(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->pending($i % 2 ? $this->alpha : $this->beta, "ref-bulk-{$i}", 30 + $i);
        }
        $this->fakeVerify(array_fill_keys(['ref-bulk-1', 'ref-bulk-2', 'ref-bulk-3', 'ref-bulk-4', 'ref-bulk-5'], Http::response($this->verifyAnswer('abandoned'), 200)));

        $this->artisan('payments:expire-pending --limit=2')->expectsOutputToContain('processed: 2')->assertExitCode(0);
        $this->assertSame(2, $this->verifyCalls());
        // Oldest first (by created_at), so a backlog drains from the back.
        $this->assertEqualsCanonicalizing(['ref-bulk-5', 'ref-bulk-4'], Transaction::where('status', 'failed')->pluck('reference')->all());
        $this->artisan('payments:expire-pending --limit=10')->expectsOutputToContain('processed: 3')->assertExitCode(0);
        $this->assertSame(5, Transaction::where('status', 'failed')->count());

        // The callback resolves strictly by our reference; a reference of another
        // school's row settles that row only and metadata cannot redirect it.
        $this->assertSame(PaymentSettlementService::NOT_FOUND, app(PaymentSettlementService::class)->settleByReference('no-such-ref')['outcome']);
    }

    // =====================================================================
    // 16–18. admin representation without service-fee / gross exposure
    // =====================================================================

    public function test_admin_pages_and_csv_represent_a_failed_attempt_without_exposing_fees_or_gross(): void
    {
        $student = $this->makeStudent($this->alpha, 'A/1', 'Ada Okonkwo');
        $t = $this->pending($this->alpha, 'ref-shown', 30);
        Transaction::whereKey($t->id)->update(['student_id' => $student->id, 'student_name' => 'Ada Okonkwo']);
        $this->fakeVerify(['ref-shown' => Http::response($this->verifyAnswer('abandoned', ['gateway_response' => 'The transaction was not completed']), 200)]);
        $this->artisan('payments:expire-pending')->assertExitCode(0);
        $this->assertSame('failed', $t->refresh()->status);

        $list = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions?status=failed')->assertOk();
        $list->assertSee('ref-shown')->assertSee('50,000.00')->assertDontSee('51,250')->assertDontSee('1,250.00')->assertDontSee('The transaction was not completed');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions')->assertOk()->assertDontSee('ref-shown'); // default view is collections only

        $detail = $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/transactions/{$t->id}")->assertOk();
        $detail->assertSee('Payment not completed')->assertSee('never completed it')->assertSee($t->failure()['observed_at']->format('d M Y'))
            ->assertDontSee('51,250')->assertDontSee('1,250.00')->assertDontSee('Service fee')->assertDontSee('The transaction was not completed')->assertDontSee('gateway');

        $dashboard = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/dashboard')->assertOk();
        $dashboard->assertSee('1 payment')->assertSee('not completed')->assertDontSee('awaiting confirmation')->assertDontSee('51,250');

        $history = $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$student->id}")->assertOk();
        $history->assertDontSee('ref-shown');
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$student->id}?attempts=1")->assertOk()->assertSee('ref-shown')->assertDontSee('51,250');

        $csv = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions/export?status=failed')->assertOk()->streamedContent();
        $this->assertStringContainsString('ref-shown', $csv);
        $this->assertStringContainsString(',failed,', $csv);
        $this->assertStringContainsString(',50000.00,', $csv);
        $this->assertStringNotContainsString('51250', $csv);
        $this->assertStringNotContainsString('1250.00', $csv);
        $this->assertStringNotContainsString('Service Fee', $csv);
        $this->assertStringNotContainsString('Total Charged', $csv);
        $this->assertStringNotContainsString('not completed', $csv, 'the gateway text is not exported');
    }
}
