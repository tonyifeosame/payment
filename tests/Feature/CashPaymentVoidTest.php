<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\SchoolAuditEvent;
use App\Models\Transaction;
use App\Services\ManualPaymentService;
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithSchools;
use Tests\Concerns\RecordsCashPayments;
use Tests\TestCase;

/**
 * Voiding a cash payment recorded in error: audited, reason required, the row kept
 * with every original value, the school fee released so it can be paid again —
 * and never any money movement, payout or second successful payment.
 */
class CashPaymentVoidTest extends TestCase
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

    private function voidUrl(Transaction $t, string $slug = 'alpha'): string
    {
        return "/admin/{$slug}/transactions/{$t->id}/void";
    }

    private function void(Transaction $t, array $overrides = [])
    {
        return $this->actingAsSchoolAdmin($this->alpha)->post($this->voidUrl($t), array_merge([
            'reason' => 'Recorded against the wrong student',
            'confirm_void' => '1',
        ], $overrides));
    }

    public function test_the_void_form_is_offered_for_a_cash_payment(): void
    {
        $cash = $this->recordedCash();

        $this->actingAsSchoolAdmin($this->alpha)->get($this->voidUrl($cash))
            ->assertOk()
            ->assertSee('Void cash payment')->assertSee('Reason for voiding')
            ->assertSee('Ada Obi')->assertSee('₦80,000.00')->assertSee('Mrs Bursar');
    }

    public function test_voiding_keeps_the_record_and_releases_the_school_fee(): void
    {
        $cash = $this->recordedCash();
        $original = $cash->only(['reference', 'amount', 'fee_amount', 'subcategory_id', 'student_id', 'academic_term_id', 'received_by', 'manual_receipt_number', 'notes', 'obligation_key', 'paid_at']);

        $this->void($cash)
            ->assertRedirect('/admin/alpha/students/'.$this->ada->id)
            ->assertSessionHas('success');

        $cash->refresh();
        $this->assertSame(Transaction::STATUS_VOIDED, $cash->status);
        $this->assertNotNull($cash->voided_at);
        $this->assertSame('Recorded against the wrong student', $cash->void_reason);
        $this->assertNull($cash->settled_obligation_key);
        $this->assertNull($cash->active_receipt_key);
        // Every original value is preserved.
        $this->assertEquals($original, $cash->only(array_keys($original)));
        $this->assertSame(Transaction::SOURCE_MANUAL, $cash->source);

        // The term is no longer paid.
        $this->assertFalse(Transaction::paidObligation($this->ada->id, $this->firstTerm->id)->exists());
        $this->assertSame(0, Payout::count());
        Queue::assertNothingPushed();
    }

    public function test_voiding_is_audited(): void
    {
        $cash = $this->recordedCash();
        $this->void($cash);

        $event = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_CASH_PAYMENT_VOIDED)->sole();
        $this->assertSame($this->alpha->id, $event->school_id);
        $this->assertSame($cash->id, $event->subject_id);
        $this->assertSame('transaction', $event->subject_type);
        $this->assertNotNull($event->actor_session);
        $this->assertSame(['from' => 'success', 'to' => 'voided'], $event->changes['status']);
        $this->assertSame('Recorded against the wrong student', $event->changes['reason']['to']);
        // The recording event is still there: the audit trail is preserved.
        $this->assertSame(1, SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_CASH_PAYMENT_RECORDED)->count());
    }

    public function test_a_reason_and_confirmation_are_required(): void
    {
        $cash = $this->recordedCash();

        $this->void($cash, ['reason' => ''])->assertSessionHasErrors('reason');
        $this->void($cash, ['reason' => 'no'])->assertSessionHasErrors('reason');
        $this->void($cash, ['confirm_void' => null])->assertSessionHasErrors('confirm_void');

        $this->assertSame('success', $cash->fresh()->status);
        $this->assertSame(0, SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_CASH_PAYMENT_VOIDED)->count());
    }

    public function test_a_voided_payment_cannot_be_voided_again(): void
    {
        $cash = $this->recordedCash();
        $this->void($cash);

        $this->void($cash, ['reason' => 'Second attempt here'])->assertSessionHasErrors('reason');
        $this->actingAsSchoolAdmin($this->alpha)->get($this->voidUrl($cash))
            ->assertRedirect('/admin/alpha/transactions/'.$cash->id);

        $this->assertSame('Recorded against the wrong student', $cash->fresh()->void_reason);
        $this->assertSame(1, SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_CASH_PAYMENT_VOIDED)->count());
    }

    public function test_a_paystack_payment_can_never_be_voided(): void
    {
        $online = $this->makeSuccessfulTransaction($this->alpha, ['student_id' => $this->ada->id]);

        $this->actingAsSchoolAdmin($this->alpha)->get($this->voidUrl($online))->assertNotFound();
        $this->void($online)->assertNotFound();

        $this->expectException(ValidationException::class);
        try {
            app(ManualPaymentService::class)->void($this->alpha, $online, 'Trying the service directly');
        } finally {
            $this->assertSame('success', $online->fresh()->status);
        }
    }

    public function test_anonymous_users_cannot_void(): void
    {
        $cash = $this->recordedCash();
        $this->flushSession();

        $this->get($this->voidUrl($cash))->assertRedirect('/admin/login');
        $this->post($this->voidUrl($cash), ['reason' => 'Anonymous attempt', 'confirm_void' => '1'])->assertRedirect('/admin/login');
        $this->assertSame('success', $cash->fresh()->status);
    }

    public function test_another_schools_cash_payment_cannot_be_voided(): void
    {
        $cash = $this->recordedCash();

        $this->actingAsSchoolAdmin($this->beta)->get($this->voidUrl($cash, 'beta'))->assertNotFound();
        $this->actingAsSchoolAdmin($this->beta)->post($this->voidUrl($cash, 'beta'), ['reason' => 'Not mine at all', 'confirm_void' => '1'])->assertNotFound();
        $this->actingAsSchoolAdmin($this->beta)->post($this->voidUrl($cash, 'alpha'), ['reason' => 'Not mine at all', 'confirm_void' => '1'])->assertNotFound();

        $this->assertSame('success', $cash->fresh()->status);
    }

    public function test_after_a_void_cash_can_be_recorded_again_and_the_receipt_number_reused(): void
    {
        $cash = $this->recordedCash(['receipt_number' => 'RC-7']);
        $this->void($cash);

        $again = $this->recordedCash(['receipt_number' => 'RC-7']);

        $this->assertNotSame($cash->id, $again->id);
        $this->assertSame('success', $again->status);
        $this->assertSame(1, Transaction::successful()->count());
    }

    public function test_after_a_void_the_parent_can_pay_online(): void
    {
        $cash = $this->recordedCash();
        $this->void($cash);

        $online = $this->startOnlineCheckout();
        $this->assertSame(PaymentSettlementService::SETTLED, $this->settleOnline($online)['outcome']);

        $this->assertSame('success', $online->fresh()->status);
        $this->assertSame(1, Transaction::successful()->count());
        $this->assertSame(1, Payout::count()); // the online payment's own payout only
    }

    public function test_a_pending_online_attempt_settles_as_the_single_success_after_a_void(): void
    {
        $pending = $this->startOnlineCheckout();
        $cash = $this->recordedCash();
        $this->void($cash);

        $this->assertSame(PaymentSettlementService::SETTLED, $this->settleOnline($pending)['outcome']);
        $this->assertSame('success', $pending->fresh()->status);
        $this->assertSame([$pending->id], Transaction::successful()->pluck('id')->all());
    }

    public function test_an_online_payment_held_for_refund_stays_held_after_a_void(): void
    {
        $pending = $this->startOnlineCheckout();
        $cash = $this->recordedCash();
        $this->assertSame(PaymentSettlementService::DUPLICATE_OBLIGATION, $this->settleOnline($pending)['outcome']);
        $this->assertSame('mismatch', $pending->fresh()->status);

        $this->actingAsSchoolAdmin($this->alpha)->get($this->voidUrl($cash))
            ->assertSee('data-held-duplicate', false)
            ->assertSee($pending->reference);

        $this->void($cash);

        // Not converted: the held payment needs a human; nothing is paid now.
        $this->assertSame('mismatch', $pending->fresh()->status);
        $this->assertSame(0, Transaction::successful()->count());
        // A replayed confirmation does not convert it either.
        $this->settleOnline($pending);
        $this->assertSame('mismatch', $pending->fresh()->status);
        $this->assertSame(0, Payout::count());
    }

    public function test_voiding_a_cash_payment_for_a_past_term_warns_it_cannot_be_re_recorded(): void
    {
        $cash = $this->recordedCash();
        $this->alpha->forceFill(['current_academic_term_id' => $this->secondTerm->id])->save();

        $this->actingAsSchoolAdmin($this->alpha)->get($this->voidUrl($cash))
            ->assertSee('data-term-not-current', false);
        $this->void($cash)->assertSessionHas('success');
        $this->assertSame('voided', $cash->fresh()->status);
    }

    public function test_a_voided_payment_never_creates_a_payout(): void
    {
        $cash = $this->recordedCash();
        $this->void($cash);

        Artisan::call('payouts:run', ['--dispatch' => true]);

        $this->assertSame(0, Payout::count());
        Queue::assertNothingPushed();
    }

    public function test_a_voided_payment_is_shown_as_voided_everywhere(): void
    {
        $cash = $this->recordedCash();
        $this->void($cash);
        $admin = fn () => $this->actingAsSchoolAdmin($this->alpha);

        $admin()->get('/admin/alpha/students/'.$this->ada->id)
            ->assertSee('Cash — Voided')
            ->assertSeeInOrder(['Paid online', '₦0.00', 'Paid with cash', '₦0.00']);
        $admin()->get('/admin/alpha/transactions/'.$cash->id)
            ->assertSee('Cash — Voided')->assertSee('Recorded against the wrong student')
            ->assertSee('Cash payment voided')
            ->assertDontSee('/void"', false);
        $admin()->get('/payment/receipt/'.$cash->id)
            ->assertSee('VOIDED — not proof of payment')
            ->assertSee('Recorded against the wrong student')
            ->assertDontSee('official proof');
        // Dashboard totals never included it.
        $admin()->get('/admin/alpha/dashboard')->assertDontSee('data-cash-recorded', false);
    }

    public function test_a_failed_void_audit_leaves_the_payment_untouched(): void
    {
        $cash = $this->recordedCash();
        \Illuminate\Support\Facades\Schema::drop('school_audit_events');

        try {
            app(ManualPaymentService::class)->void($this->alpha, $cash, 'Audit store is down');
            $this->fail('Expected the audit failure to surface.');
        } catch (\Illuminate\Database\QueryException) {
            // expected
        }

        $cash->refresh();
        $this->assertSame('success', $cash->status);
        $this->assertNotNull($cash->settled_obligation_key);
    }
}
