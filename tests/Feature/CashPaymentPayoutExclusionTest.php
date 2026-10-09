<?php

namespace Tests\Feature;

use App\Jobs\InitiateSchoolPayout;
use App\Models\Payout;
use App\Models\Transaction;
use App\Services\PaymentSettlementService;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSchools;
use Tests\Concerns\RecordsCashPayments;
use Tests\TestCase;

/**
 * Cash the school recorded is never a FEYRA collection: it never becomes a payout
 * obligation (not through the payout service, not through the scheduled sweep),
 * never reaches Paystack, and shares the one-successful-school-fee-per-term rule
 * with online payments in both directions.
 */
class CashPaymentPayoutExclusionTest extends TestCase
{
    use InteractsWithSchools, RecordsCashPayments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        $this->fakePaystack();
        $this->setUpCashSchool();
    }

    public function test_the_payout_service_never_creates_an_obligation_for_cash(): void
    {
        $cash = $this->recordedCash();

        $this->assertNull(app(PayoutService::class)->recordObligationFor($cash));
        // Even from a partially loaded model, the source is read from the database.
        $this->assertNull(app(PayoutService::class)->recordObligationFor(Transaction::select(['id', 'status', 'school_id', 'amount', 'meta_data'])->find($cash->id)));
        $this->assertSame(0, Payout::count());
    }

    public function test_the_scheduled_payout_sweep_skips_cash(): void
    {
        $this->recordedCash();
        $online = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-online']);

        Artisan::call('payouts:run', ['--dispatch' => true]);

        // The online payment gets its payout; the cash payment never does.
        $this->assertSame([$online->id], Payout::pluck('transaction_id')->all());
        Queue::assertPushed(InitiateSchoolPayout::class, 1);
    }

    public function test_the_sweep_dry_run_does_not_list_cash(): void
    {
        $cash = $this->recordedCash();

        Artisan::call('payouts:run', ['--dry-run' => true]);

        $this->assertStringNotContainsString($cash->reference, Artisan::output());
        $this->assertSame(0, Payout::count());
    }

    public function test_a_webhook_or_callback_naming_a_cash_reference_never_calls_paystack(): void
    {
        $cash = $this->recordedCash();
        Http::fake(); // record what would be sent
        Http::preventStrayRequests(false);

        $result = app(PaymentSettlementService::class)->settleByReference($cash->reference);

        $this->assertSame(PaymentSettlementService::ALREADY_SETTLED, $result['outcome']);
        Http::assertNothingSent();
        $this->assertSame(0, Payout::count());
        $this->assertSame('success', $cash->fresh()->status);
    }

    public function test_a_voided_cash_reference_is_never_settled(): void
    {
        $cash = $this->recordedCash();
        app(\App\Services\ManualPaymentService::class)->void($this->alpha, $cash, 'Recorded in error');
        Http::fake();
        Http::preventStrayRequests(false);

        $result = app(PaymentSettlementService::class)->settleByReference($cash->reference);

        $this->assertSame(PaymentSettlementService::NOT_FOUND, $result['outcome']);
        Http::assertNothingSent();
        $this->assertSame('voided', $cash->fresh()->status);
    }

    public function test_cash_after_an_online_payment_is_refused(): void
    {
        $online = $this->startOnlineCheckout();
        $this->assertSame(PaymentSettlementService::SETTLED, $this->settleOnline($online)['outcome']);

        $this->actingAsSchoolAdmin($this->alpha)->get($this->cashUrl())
            ->assertSee('School fees for First Term, 2026/2027 have already been paid for Ada Obi.');
        $this->recordCash()->assertSessionHasErrors('payment');

        $this->assertSame(0, Transaction::manual()->count());
    }

    public function test_cash_after_cash_is_refused(): void
    {
        $this->recordedCash();

        $this->recordCash(['receipt_number' => 'RC-2'])->assertSessionHasErrors('payment');
        $this->assertSame(1, Transaction::manual()->count());
    }

    public function test_an_online_checkout_after_cash_is_refused(): void
    {
        $this->recordedCash();

        $this->post('/pay/alpha/initialize', [
            'email' => 'parent@example.test',
            'category_id' => $this->schoolFee->category_id,
            'subcategory_id' => $this->schoolFee->id,
            'quantity' => 1,
            'student_id' => $this->ada->id,
            'academic_session_id' => $this->firstTerm->academic_session_id,
            'academic_term_id' => $this->firstTerm->id,
        ])->assertSessionHasErrors('subcategory_id');

        $this->assertSame(0, Transaction::online()->count());
        // And the payment page already knows the term is paid.
        $paid = $this->postJson('/pay/alpha/student-search', ['name' => 'Ada Obi', 'admission_number' => 'ALP/001'])->json('student.paid_fees');
        $this->assertSame([['fee_id' => $this->schoolFee->id, 'term_id' => $this->firstTerm->id]], $paid);
    }

    public function test_a_pending_checkout_is_warned_about_and_held_for_refund_if_it_completes_after_cash(): void
    {
        $pending = $this->startOnlineCheckout();

        $this->actingAsSchoolAdmin($this->alpha)->get($this->cashUrl())
            ->assertSee('data-pending-online', false)
            ->assertSee('An online payment is in progress');
        $this->actingAsSchoolAdmin($this->alpha)->post($this->cashUrl().'/review', $this->cashDetails())
            ->assertSee('data-pending-online', false);

        $cash = $this->recordedCash();
        $result = $this->settleOnline($pending);

        $this->assertSame(PaymentSettlementService::DUPLICATE_OBLIGATION, $result['outcome']);
        $this->assertSame('mismatch', $pending->fresh()->status);
        $this->assertSame([$cash->id], Transaction::successful()->pluck('id')->all());
        // The held charge never becomes a payout.
        $this->assertSame(0, Payout::count());
        Queue::assertNothingPushed();
    }

    public function test_the_database_refuses_a_second_success_for_one_obligation_across_sources(): void
    {
        $cash = $this->recordedCash();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->makeSuccessfulTransaction($this->alpha, [
            'student_id' => $this->ada->id,
            'academic_term_id' => $this->firstTerm->id,
        ])->forceFill(['settled_obligation_key' => $cash->settled_obligation_key])->save();
    }

    public function test_a_race_with_a_settled_online_payment_surfaces_as_already_paid(): void
    {
        // Simulate the online payment settling between the cash checks and the insert:
        // its row already holds the obligation key, but is hidden from the application
        // check (as in SchoolFeePaidOnceTest), so only the unique index can stop us.
        $online = $this->startOnlineCheckout();
        $this->assertSame(PaymentSettlementService::SETTLED, $this->settleOnline($online)['outcome']);
        Transaction::whereKey($online->id)->update(['status' => 'pending']);

        $this->recordCash()->assertSessionHasErrors(['payment' => 'School fees for First Term, 2026/2027 have just been paid for Ada Obi. Nothing was recorded.']);

        $this->assertSame(0, Transaction::manual()->count());
        $this->assertSame(0, \App\Models\SchoolAuditEvent::where('action', 'payment.cash_recorded')->count());
    }

    public function test_other_terms_and_additional_fees_are_unaffected_by_cash(): void
    {
        $this->recordedCash();
        $uniform = $this->makeFee($this->alpha, 'Uniform', 'Uniform', 3000, null);

        $online = $this->startOnlineCheckout($uniform);
        $this->assertSame(PaymentSettlementService::SETTLED, $this->settleOnline($online)['outcome']);
        $this->assertSame(1, Payout::count());
    }
}
