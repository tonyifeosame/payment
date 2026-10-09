<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\SchoolAuditEvent;
use App\Models\Student;
use App\Models\Transaction;
use App\Services\ManualPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithSchools;
use Tests\Concerns\RecordsCashPayments;
use Tests\TestCase;

/**
 * Recording a cash school-fee payment from the student's profile: details →
 * review → record. The student comes from the URL, the fee and its amount from
 * the school's own configuration, and the term must be the school's current term;
 * nothing about money or identity is taken from the browser.
 */
class CashPaymentTest extends TestCase
{
    use InteractsWithSchools, RecordsCashPayments, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        // Recording cash must never reach Paystack.
        Http::preventStrayRequests();
        Http::fake();

        $this->setUpCashSchool();
    }

    // ------------------------------------------------------------- happy path

    public function test_the_profile_offers_record_cash_payment_for_an_active_student(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/'.$this->ada->id)
            ->assertOk()
            ->assertSeeInOrder(['Edit student', 'Record cash payment'])
            ->assertSee($this->cashUrl(), false);
    }

    public function test_the_details_step_shows_the_student_and_the_configured_school_fee(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->get($this->cashUrl())
            ->assertOk()
            ->assertSee('Ada Obi')->assertSee('ALP/001')->assertSee('JSS 1')
            ->assertSee('JSS 1 First Term Fees')->assertSee('₦80,000.00')
            ->assertSee('2026/2027')->assertSee('First Term')
            ->assertSee('Review payment')
            ->assertDontSee('data-cash-problem', false);
    }

    public function test_the_review_step_summarises_without_recording_anything(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->post($this->cashUrl().'/review', $this->cashDetails())
            ->assertOk()
            ->assertSeeInOrder(['Ada Obi', 'ALP/001', 'JSS 1', 'First Term, 2026/2027', 'JSS 1 First Term Fees', '₦80,000.00', 'Mrs Bursar', 'RC-1001'])
            ->assertSee('confirm_received', false)
            ->assertSee('Record cash payment');

        $this->assertSame(0, Transaction::count());
    }

    public function test_recording_stores_a_cash_payment_with_the_configured_amount_and_no_platform_fee(): void
    {
        $this->recordCash()
            ->assertRedirect('/admin/alpha/students/'.$this->ada->id)
            ->assertSessionHas('success');

        $t = Transaction::sole();
        $this->assertSame(Transaction::SOURCE_MANUAL, $t->source);
        $this->assertSame('cash', $t->payment_method);
        $this->assertSame('success', $t->status);
        $this->assertSame('80000.00', number_format((float) $t->amount, 2, '.', ''));
        $this->assertSame(80000.0, (float) $t->fee_amount);
        $this->assertSame(0.0, (float) $t->service_fee);
        $this->assertNull($t->paystack_reference);
        $this->assertStringStartsWith('CASH-', $t->reference);
        $this->assertNull($t->email);
        $this->assertSame($this->alpha->id, $t->school_id);
        $this->assertSame($this->ada->id, $t->student_id);
        $this->assertSame('Ada Obi', $t->student_name);
        $this->assertSame('ALP/001', $t->student_admission_number);
        $this->assertSame($this->schoolFee->id, $t->subcategory_id);
        $this->assertSame($this->firstTerm->id, $t->academic_term_id);
        $this->assertSame($this->firstTerm->academic_session_id, $t->academic_session_id);
        $this->assertSame('First Term', $t->term_name);
        $this->assertSame('2026/2027', $t->session_name);
        $this->assertSame('Mrs Bursar', $t->received_by);
        $this->assertSame('RC-1001', $t->manual_receipt_number);
        $this->assertSame('Paid at the bursary', $t->notes);
        $this->assertNotNull($t->paid_at);
        $this->assertSame($t->obligation_key, $t->settled_obligation_key);

        $b = $t->receiptBreakdown();
        $this->assertFalse($b['has_service_fee']);
        $this->assertSame(80000.0, $b['total']);

        // FEYRA collected nothing: no Paystack call, no email, no payout, no job.
        Http::assertNothingSent();
        Mail::assertNothingQueued();
        Mail::assertNothingSent();
        Queue::assertNothingPushed();
        $this->assertSame(0, Payout::count());
    }

    public function test_the_payment_shows_on_the_profile_as_paid_with_cash(): void
    {
        $this->recordedCash();

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/'.$this->ada->id)
            ->assertOk()
            ->assertSee('Paid with Cash')
            ->assertSee('Recorded by school')
            ->assertSee('JSS 1 First Term Fees');
    }

    public function test_a_past_payment_date_is_stored_on_that_day(): void
    {
        $date = now('Africa/Lagos')->subDays(3)->format('Y-m-d');
        $t = $this->recordedCash(['paid_on' => $date]);

        $this->assertSame($date, $t->paid_at->copy()->timezone('Africa/Lagos')->format('Y-m-d'));
    }

    public function test_the_audit_trail_records_the_cash_payment(): void
    {
        $t = $this->recordedCash();

        $event = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_CASH_PAYMENT_RECORDED)->sole();
        $this->assertSame($this->alpha->id, $event->school_id);
        $this->assertSame(SchoolAuditEvent::ACTOR_SCHOOL_ADMIN, $event->actor);
        $this->assertNotNull($event->actor_session);
        $this->assertSame('transaction', $event->subject_type);
        $this->assertSame($t->id, $event->subject_id);
        $this->assertSame('80000.00', $event->changes['amount']['to']);
        $this->assertSame('Mrs Bursar', $event->changes['received_by']['to']);
        $this->assertSame('RC-1001', $event->changes['receipt_number']['to']);
        $this->assertSame('First Term, 2026/2027', $event->changes['term']['to']);
        // No student personal data in the audit row.
        $this->assertStringNotContainsString('Ada Obi', json_encode($event->changes));
    }

    public function test_a_failed_audit_write_records_no_payment(): void
    {
        // The audit row is written inside the payment's transaction: if it cannot be
        // written, the payment is rolled back with it.
        \Illuminate\Support\Facades\Schema::drop('school_audit_events');

        $this->withoutExceptionHandling();
        try {
            $this->recordCash();
            $this->fail('Expected the audit failure to surface.');
        } catch (\Illuminate\Database\QueryException) {
            // expected
        }

        $this->assertSame(0, Transaction::count());
    }

    // ---------------------------------------------------- the browser is never trusted

    public function test_amount_fee_student_and_school_in_the_request_are_ignored(): void
    {
        $other = $this->makeStudent($this->alpha, 'ALP/002', 'Bola Ade', 'JSS 1', ['class_level_id' => $this->jss1->id]);
        $uniform = $this->makeFee($this->alpha, 'Uniform', 'Uniform', 500, null);

        $this->recordCash([
            'amount' => '1.00',
            'fee_amount' => '1.00',
            'subcategory_id' => $uniform->id,
            'student_id' => $other->id,
            'school_id' => $this->beta->id,
        ])->assertRedirect('/admin/alpha/students/'.$this->ada->id);

        $t = Transaction::sole();
        $this->assertSame(80000.0, (float) $t->amount);
        $this->assertSame($this->schoolFee->id, $t->subcategory_id);
        $this->assertSame($this->ada->id, $t->student_id);
        $this->assertSame($this->alpha->id, $t->school_id);
    }

    public function test_a_tampered_expected_amount_or_fee_is_refused(): void
    {
        foreach ([['expected_amount' => '1.00'], ['expected_fee_id' => $this->schoolFee->id + 99], ['expected_term_id' => $this->secondTerm->id], ['expected_class_level_id' => 999]] as $tamper) {
            $this->recordCash($tamper)
                ->assertRedirect()
                ->assertSessionHasErrors('payment');
        }

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_fee_price_change_between_review_and_record_is_refused(): void
    {
        $confirmation = $this->cashConfirmation(); // reviewed at ₦80,000
        $this->schoolFee->update(['price' => 85000]);

        $this->actingAsSchoolAdmin($this->alpha)->post($this->cashUrl(), $confirmation)
            ->assertRedirect()
            ->assertSessionHasErrors(['payment' => 'These payment details changed after you reviewed them (the fee, its amount or the student’s class). Nothing was recorded — please review the payment again.']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_class_change_between_review_and_record_is_refused(): void
    {
        $confirmation = $this->cashConfirmation();
        $jss2 = \App\Models\ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'JSS 2', 'position' => 2, 'is_active' => true]);
        $this->mainFee($this->alpha, 'JSS 2 First Term Fees', 90000, $this->firstTerm, $jss2);
        $this->ada->update(['class_level_id' => $jss2->id, 'class_name' => 'JSS 2']);

        $this->actingAsSchoolAdmin($this->alpha)->post($this->cashUrl(), $confirmation)
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, Transaction::count());
    }

    public function test_another_schools_student_is_not_found(): void
    {
        $betaStudent = $this->makeStudent($this->beta, 'BET/001', 'Beta Kid');
        $admin = fn () => $this->actingAsSchoolAdmin($this->alpha);

        $admin()->get($this->cashUrl($betaStudent))->assertNotFound();
        $admin()->post($this->cashUrl($betaStudent).'/review', $this->cashDetails())->assertNotFound();
        $admin()->post($this->cashUrl($betaStudent), $this->cashConfirmation([], $betaStudent))->assertNotFound();
        // Nor through the other school's URL.
        $admin()->get($this->cashUrl($betaStudent, 'beta'))->assertNotFound();

        $this->assertSame(0, Transaction::count());
    }

    public function test_an_academic_year_the_school_does_not_have_is_refused(): void
    {
        $this->makeSessionWithTerms($this->beta, '2030/2031');

        $this->actingAsSchoolAdmin($this->alpha)->post($this->cashUrl(), $this->cashConfirmation(['academic_year' => '2030/2031']))
            ->assertSessionHasErrors(['payment' => 'Choose an academic year and term your school has set up.']);

        $this->assertSame(0, Transaction::count());
        // The cash flow never creates academic years.
        $this->assertFalse($this->alpha->academicSessions()->where('name', '2030/2031')->exists());
    }

    public function test_anonymous_users_are_sent_to_login(): void
    {
        $this->get($this->cashUrl())->assertRedirect('/admin/login');
        $this->post($this->cashUrl().'/review', $this->cashDetails())->assertRedirect('/admin/login');
        $this->post($this->cashUrl(), $this->cashConfirmation())->assertRedirect('/admin/login');

        $this->assertSame(0, Transaction::count());
    }

    // ------------------------------------------------------------- refusals

    public function test_only_active_students(): void
    {
        foreach ([Student::STATUS_GRADUATED, Student::STATUS_LEFT] as $status) {
            $this->ada->update(['status' => $status]);

            $this->actingAsSchoolAdmin($this->alpha)->get($this->cashUrl())
                ->assertOk()->assertSee('data-cash-problem', false)->assertSee('only be recorded for active students')
                ->assertDontSee('Review payment');
            $this->recordCash()->assertSessionHasErrors('payment');
        }

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/'.$this->ada->id)
            ->assertSee('aria-disabled="true"', false);
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_student_without_a_class_is_refused(): void
    {
        $this->ada->update(['class_level_id' => null]);

        $this->actingAsSchoolAdmin($this->alpha)->get($this->cashUrl())->assertSee('Assign Ada Obi to one of your classes');
        $this->recordCash()->assertSessionHasErrors('payment');
        $this->assertSame(0, Transaction::count());
    }

    public function test_no_school_fee_for_the_class_and_term_is_explained(): void
    {
        $this->schoolFee->delete();

        $this->actingAsSchoolAdmin($this->alpha)->get($this->cashUrl())
            ->assertSee('There is no school fee for JSS 1 in First Term, 2026/2027')
            ->assertDontSee('Review payment');
        $this->actingAsSchoolAdmin($this->alpha)->post($this->cashUrl().'/review', $this->cashDetails())
            ->assertSessionHasErrors('payment');
    }

    public function test_an_unpriced_school_fee_is_refused(): void
    {
        $this->schoolFee->update(['price' => null]);

        $this->actingAsSchoolAdmin($this->alpha)->get($this->cashUrl())
            ->assertSee('has no amount set');
        $this->recordCash(['expected_amount' => '0.00'])->assertSessionHasErrors('payment');
        $this->assertSame(0, Transaction::count());
    }

    public function test_an_additional_fee_can_never_be_recorded_as_cash(): void
    {
        // Only an additional fee exists for this class and term: no school fee.
        $this->schoolFee->delete();
        $uniform = $this->makeFee($this->alpha, 'Uniform', 'Uniform', 5000, $this->firstTerm->id);

        $this->recordCash(['expected_fee_id' => $uniform->id, 'expected_amount' => '5000.00'])
            ->assertSessionHasErrors('payment');
        $this->assertSame(0, Transaction::count());
    }

    public function test_a_school_fee_payable_in_any_term_is_used_when_there_is_no_term_fee(): void
    {
        $this->schoolFee->delete();
        $general = $this->mainFee($this->alpha, 'JSS 1 School Fees', 70000, null, $this->jss1);

        $this->recordCash(['expected_fee_id' => $general->id, 'expected_amount' => '70000.00'])
            ->assertRedirect('/admin/alpha/students/'.$this->ada->id);
        $this->assertSame(70000.0, (float) Transaction::sole()->amount);
    }

    public function test_required_details_are_validated(): void
    {
        $admin = fn () => $this->actingAsSchoolAdmin($this->alpha);

        $admin()->post($this->cashUrl().'/review', $this->cashDetails(['received_by' => '']))
            ->assertRedirect()->assertSessionHasErrors('received_by');
        $admin()->post($this->cashUrl().'/review', $this->cashDetails(['received_by' => '   ']))
            ->assertSessionHasErrors('received_by');
        $admin()->post($this->cashUrl().'/review', $this->cashDetails(['paid_on' => '']))
            ->assertSessionHasErrors('paid_on');
        $admin()->post($this->cashUrl().'/review', $this->cashDetails(['paid_on' => 'yesterday']))
            ->assertSessionHasErrors('paid_on');
        $admin()->post($this->cashUrl(), $this->cashConfirmation(['confirm_received' => null]))
            ->assertSessionHasErrors('confirm_received');

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_future_payment_date_is_refused(): void
    {
        $tomorrow = now('Africa/Lagos')->addDay()->format('Y-m-d');

        $this->actingAsSchoolAdmin($this->alpha)->post($this->cashUrl().'/review', $this->cashDetails(['paid_on' => $tomorrow]))
            ->assertSessionHasErrors('paid_on');
        $this->recordCash(['paid_on' => $tomorrow])->assertSessionHasErrors('paid_on');
        $this->assertSame(0, Transaction::count());
    }

    public function test_validation_failure_on_the_record_step_returns_to_the_details_page(): void
    {
        $this->recordCash(['confirm_received' => null])
            ->assertRedirect($this->cashUrl().'?academic_year=2026%2F2027&term=1');
    }

    // ---------------------------------------------------------- the payment window

    public function test_a_term_that_is_not_current_is_refused_at_every_step(): void
    {
        $this->mainFee($this->alpha, 'JSS 1 Second Term Fees', 80000, $this->secondTerm, $this->jss1);
        $admin = fn () => $this->actingAsSchoolAdmin($this->alpha);

        $admin()->get($this->cashUrl().'?academic_year=2026/2027&term=2')
            ->assertOk()->assertSee('Second Term, 2026/2027 is not open for payment')
            ->assertDontSee('Review payment');
        $admin()->post($this->cashUrl().'/review', $this->cashDetails(['term' => 2]))
            ->assertSessionHasErrors('payment');
        $admin()->post($this->cashUrl(), $this->cashConfirmation(['term' => 2], term: $this->secondTerm))
            ->assertSessionHasErrors('payment');

        $this->assertSame(0, Transaction::count());
    }

    public function test_the_window_is_rechecked_when_the_payment_is_saved(): void
    {
        $confirmation = $this->cashConfirmation(); // reviewed while First Term was current
        $this->alpha->forceFill(['current_academic_term_id' => $this->secondTerm->id])->save();

        $this->actingAsSchoolAdmin($this->alpha)->post($this->cashUrl(), $confirmation)
            ->assertSessionHasErrors(['payment' => 'First Term, 2026/2027 is not open for payment. Cash payments can only be recorded for your school’s current term, which is Second Term, 2026/2027.']);

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_school_without_a_current_term_cannot_record_cash(): void
    {
        $this->alpha->forceFill(['current_academic_term_id' => null])->save();

        $this->recordCash()->assertSessionHasErrors('payment');
        $this->assertSame(0, Transaction::count());
    }

    // -------------------------------------------------------------- receipts

    public function test_the_receipt_identifies_a_cash_payment_recorded_by_the_school(): void
    {
        $t = $this->recordedCash();

        $this->actingAsSchoolAdmin($this->alpha)->get('/payment/receipt/'.$t->id)
            ->assertOk()
            ->assertSee('Payment successful')
            ->assertSee('Cash (recorded by the school)')
            ->assertSee('Mrs Bursar')
            ->assertSee('RC-1001')
            ->assertSee('Paid in cash at the school')
            ->assertSee('proof of a cash payment received and recorded by the school')
            ->assertDontSee('Service fee')
            ->assertDontSee('has been emailed');
    }

    public function test_the_receipt_omits_an_empty_receipt_number(): void
    {
        $t = $this->recordedCash(['receipt_number' => '']);
        $this->assertNull($t->manual_receipt_number);

        $this->actingAsSchoolAdmin($this->alpha)->get('/payment/receipt/'.$t->id)
            ->assertOk()->assertDontSee('Cash receipt no.');
    }

    public function test_the_pdf_receipt_renders_for_a_cash_payment(): void
    {
        $t = $this->recordedCash();

        $response = $this->get(URL::signedRoute('payment.receipt.download', ['transaction' => $t->id]));
        $response->assertOk();
        $this->assertSame('application/pdf', $response->headers->get('Content-Type'));

        $html = view('payment.receipt_pdf', ['transaction' => $t, 'school' => $this->alpha, 'logoDataUri' => null])->render();
        $this->assertStringContainsString('Cash (recorded by the school)', $html);
        $this->assertStringContainsString('Mrs Bursar', $html);
        $this->assertStringContainsString('RC-1001', $html);
        $this->assertStringNotContainsString('Service fee', $html);
    }

    public function test_another_schools_admin_cannot_open_the_receipt(): void
    {
        $t = $this->recordedCash();

        $this->actingAsSchoolAdmin($this->beta)->get('/payment/receipt/'.$t->id)->assertNotFound();
        $this->get('/payment/receipt/'.$t->id)->assertNotFound();
    }

    public function test_the_detail_page_shows_cash_details_and_no_payout(): void
    {
        $t = $this->recordedCash();

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions/'.$t->id)
            ->assertOk()
            ->assertSee('Paid with Cash')
            ->assertSee('Cash (recorded by the school)')
            ->assertSee('Mrs Bursar')->assertSee('RC-1001')->assertSee('Paid at the bursary')
            ->assertSee('Cash payment recorded')
            ->assertSee('Not applicable')
            ->assertSee('Void cash payment')
            ->assertDontSee('Paystack reference')
            ->assertDontSee('Payout reference');
    }

    // -------------------------------------------------------------- receipt numbers

    public function test_a_receipt_number_backs_one_live_cash_payment_per_school(): void
    {
        $this->recordedCash(['receipt_number' => 'rc-1001']);
        $bola = $this->makeStudent($this->alpha, 'ALP/002', 'Bola Ade', 'JSS 1', ['class_level_id' => $this->jss1->id]);

        // Same number, any spelling, refused.
        $this->recordCash(['receipt_number' => 'RC-1001'], $bola)->assertSessionHasErrors('receipt_number');
        $this->assertSame(1, Transaction::count());

        // The key is scoped to the school, so another school's number never collides.
        $this->assertSame($this->alpha->id.':RC-1001', Transaction::sole()->active_receipt_key);
    }

    public function test_service_quote_never_creates_terms_or_trusts_ids(): void
    {
        $quote = app(ManualPaymentService::class)->quote($this->alpha->fresh(), $this->ada->fresh(), $this->firstTerm);

        $this->assertNull($quote['problem']);
        $this->assertSame($this->schoolFee->id, $quote['fee']->id);
        $this->assertSame('80000.00', $quote['amount']);
    }

    public function test_the_service_refuses_a_term_of_another_school(): void
    {
        $betaTerm = $this->makeSessionWithTerms($this->beta, '2026/2027')->terms()->first();

        $this->expectException(\Symfony\Component\HttpKernel\Exception\NotFoundHttpException::class);
        app(ManualPaymentService::class)->quote($this->alpha->fresh(), $this->ada->fresh(), $betaTerm);
    }

    public function test_the_record_step_rejects_a_paid_term_as_a_validation_error(): void
    {
        $this->recordedCash();

        $this->expectException(ValidationException::class);
        app(ManualPaymentService::class)->record($this->alpha, $this->ada->fresh(), $this->firstTerm, [
            'fee_id' => $this->schoolFee->id, 'amount' => '80000.00', 'term_id' => $this->firstTerm->id, 'class_level_id' => $this->jss1->id,
        ], ['paid_on' => now('Africa/Lagos')->format('Y-m-d'), 'receipt_number' => null, 'received_by' => 'X', 'notes' => null]);
    }
}
