<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\ClassLevel;
use App\Models\Payout;
use App\Models\School;
use App\Models\Student;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Services\PaymentSettlementService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * ONE main (tuition) school fee per student, academic session and term — whichever
 * main fee it is, so a student moved to another class mid-term (whose class has a
 * different main fee) cannot pay school fees for that term twice. Class-level
 * assignment still decides WHICH main fee is due.
 *
 * Only a SUCCESSFUL payment counts as paid. Checkout refuses a second attempt at a
 * paid obligation; settlement refuses to record a second success for one (two
 * checkouts started before either was paid), backed by a unique key; every other
 * fee, term and student is unaffected.
 */
class SchoolFeePaidOnceTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    private AcademicTerm $firstTerm;

    private AcademicTerm $secondTerm;

    private ClassLevel $jss1;

    private Subcategory $schoolFees;

    private Subcategory $secondTermFees;

    private Subcategory $uniform;

    private Subcategory $textbooks;

    private Student $anthony;

    /** Paystack's answer to /transaction/verify, by our reference (default: success). */
    private array $paystackStatus = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_secret', 'fees.markup_percent' => 2.5]);
        Mail::fake();
        // The payout obligation row is written at settlement either way; the transfer
        // job itself is not what these tests are about, so it is queued, not run.
        Queue::fake();
        // Nothing in this suite may reach the real Paystack API.
        Http::preventStrayRequests();

        $this->alpha = $this->makeSchool('Demo Academy', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');

        $session = $this->makeSessionWithTerms($this->alpha, '2026/2027');
        $this->firstTerm = $session->terms()->where('number', 1)->firstOrFail();
        $this->secondTerm = $session->terms()->where('number', 2)->firstOrFail();

        $this->jss1 = ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'JSS1', 'position' => 1, 'is_active' => true]);

        $this->schoolFees = $this->mainFee($this->alpha, 'Secondary - First Term', 80000, $this->firstTerm, $this->jss1);
        $this->secondTermFees = $this->mainFee($this->alpha, 'Secondary - Second Term', 80000, $this->secondTerm, $this->jss1);
        $this->uniform = $this->makeFee($this->alpha, 'Uniform', 'Uniform', 3000, null);
        $this->textbooks = $this->makeFee($this->alpha, 'Books', 'Textbooks', 5000, null);

        $this->anthony = $this->makeStudent($this->alpha, 'DA/2025/2026', 'Anthony Ifeosame', 'JSS1', ['class_level_id' => $this->jss1->id]);

        Http::fake([
            '*/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123']]),
            '*/transaction/verify/*' => function (HttpRequest $request) {
                $reference = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));
                $t = Transaction::where('reference', $reference)->firstOrFail();

                return Http::response(['status' => true, 'data' => [
                    'status' => $this->paystackStatus[$reference] ?? 'success',
                    'channel' => 'card',
                    'reference' => 'PSK-'.$reference,
                    'amount' => (int) round(((float) $t->amount) * 100),
                    'currency' => 'NGN',
                ]]);
            },
        ]);
    }

    private function mainFee(School $school, string $name, float $price, AcademicTerm $term, ClassLevel $level): Subcategory
    {
        $fee = $this->makeFee($school, 'School Fees', $name, $price, $term->id);
        $fee->classLevels()->sync([$level->id => ['school_id' => $school->id]]);
        $fee->forceFill(['is_tuition' => true])->save();

        return $fee;
    }

    private function checkout(Subcategory $fee, ?Student $student = null, ?AcademicTerm $term = null, string $slug = 'alpha')
    {
        $student ??= $this->anthony;
        $term ??= $this->firstTerm;

        // The parent no longer chooses the term: checkout pays the school's current
        // term, so "paying for $term" means the admin has made it the current term.
        School::where('slug', $slug)->where('id', $term->school_id)
            ->update(['current_academic_term_id' => $term->id]);

        return $this->post("/pay/{$slug}/initialize", [
            'email' => 'parent@example.test',
            'category_id' => $fee->category_id,
            'subcategory_id' => $fee->id,
            'quantity' => 1,
            'student_id' => $student->id,
            'student_name' => $student->full_name,
            'student_admission_number' => $student->admission_number,
        ]);
    }

    /** Start a checkout and return the pending row it wrote. */
    private function startCheckout(Subcategory $fee, ?Student $student = null, ?AcademicTerm $term = null): Transaction
    {
        $before = Transaction::max('id') ?? 0;
        $this->checkout($fee, $student, $term)->assertRedirect('https://checkout.paystack.com/abc123');

        return Transaction::where('id', '>', $before)->sole();
    }

    private function settle(Transaction $t): array
    {
        return app(PaymentSettlementService::class)->settleByReference($t->reference);
    }

    /** Pay a fee all the way through: checkout, then Paystack confirms it. */
    private function payInFull(Subcategory $fee, ?Student $student = null, ?AcademicTerm $term = null): Transaction
    {
        $t = $this->startCheckout($fee, $student, $term);
        $this->assertSame(PaymentSettlementService::SETTLED, $this->settle($t)['outcome']);

        return $t->refresh();
    }

    private function lookupPaidFees(): array
    {
        return $this->postJson('/pay/alpha/student-search', ['name' => 'Anthony Ifeosame', 'admission_number' => 'DA/2025/2026'])
            ->assertOk()->json('student.paid_fees');
    }

    // ------------------------------------------------------------------ 1 + 2

    public function test_the_first_school_fee_payment_succeeds_with_unchanged_economics(): void
    {
        $t = $this->payInFull($this->schoolFees);

        $this->assertSame('success', $t->status);
        $this->assertEquals(80000, (float) $t->fee_amount);
        $this->assertEquals(2000, (float) $t->service_fee);
        $this->assertEquals(82000, (float) $t->amount);
        // student:session:term — not the fee.
        $key = "{$this->anthony->id}:{$this->firstTerm->academic_session_id}:{$this->firstTerm->id}";
        $this->assertSame($key, $t->obligation_key);
        $this->assertSame($key, $t->settled_obligation_key);
        $this->assertSame(1, Payout::count());
    }

    public function test_a_second_attempt_at_a_paid_school_fee_is_refused_server_side(): void
    {
        $this->payInFull($this->schoolFees);

        $this->checkout($this->schoolFees)
            ->assertSessionHasErrors(['subcategory_id' => 'School fees have already been paid for this student for First Term, 2026/2027.']);

        $this->assertSame(1, Transaction::count(), 'no second attempt is even started');
    }

    // -------------------------------------------------------------- 3, 4, 5

    public function test_a_failed_payment_does_not_block_a_retry(): void
    {
        $failed = $this->startCheckout($this->schoolFees);
        $this->paystackStatus[$failed->reference] = 'failed';
        $this->assertSame(PaymentSettlementService::FAILED_RECORDED, $this->settle($failed)['outcome']);
        $this->assertSame('failed', $failed->refresh()->status);
        $this->assertSame([], $this->lookupPaidFees());

        $retry = $this->payInFull($this->schoolFees);
        $this->assertSame('success', $retry->status);
    }

    public function test_a_cancelled_or_abandoned_payment_does_not_block_a_retry(): void
    {
        $abandoned = $this->startCheckout($this->schoolFees);
        $this->paystackStatus[$abandoned->reference] = 'abandoned';

        // The payer closed Paystack's checkout: still pending, then expired as failed.
        $this->assertSame(PaymentSettlementService::NOT_SUCCESSFUL, $this->settle($abandoned)['outcome']);
        $this->assertSame(PaymentSettlementService::FAILED_RECORDED, app(PaymentSettlementService::class)->reconcilePendingAttempt($abandoned)['outcome']);

        $this->assertSame('success', $this->payInFull($this->schoolFees)->status);
    }

    public function test_a_pending_payment_does_not_block_another_attempt(): void
    {
        $pending = $this->startCheckout($this->schoolFees);
        $this->assertSame('pending', $pending->status);

        // The parent tries again before the first attempt resolves: allowed.
        $second = $this->startCheckout($this->schoolFees);
        $this->assertSame(PaymentSettlementService::SETTLED, $this->settle($second)['outcome']);

        // Now it is paid, and only now is a new attempt refused.
        $this->checkout($this->schoolFees)->assertSessionHasErrors('subcategory_id');
    }

    // ------------------------------------------------------------- 6, 7, 8

    public function test_other_fees_remain_payable_after_school_fees_are_paid(): void
    {
        $this->payInFull($this->schoolFees);

        $uniform = $this->payInFull($this->uniform);
        $books = $this->payInFull($this->textbooks);
        // An ordinary fee is not "once per term": a second uniform is fine.
        $uniformAgain = $this->payInFull($this->uniform);

        foreach ([$uniform, $books, $uniformAgain] as $t) {
            $this->assertSame('success', $t->status);
            $this->assertNull($t->obligation_key);
            $this->assertNull($t->settled_obligation_key);
        }
        $this->assertEquals(3075, (float) $uniform->amount);
        $this->assertEquals(5125, (float) $books->amount);
    }

    public function test_the_second_term_is_payable_after_the_first_term_is_paid(): void
    {
        $this->payInFull($this->schoolFees);

        $second = $this->payInFull($this->secondTermFees, term: $this->secondTerm);

        $this->assertSame('success', $second->status);
        $this->assertEqualsCanonicalizing(
            [['fee_id' => $this->schoolFees->id, 'term_id' => $this->firstTerm->id], ['fee_id' => $this->secondTermFees->id, 'term_id' => $this->secondTerm->id]],
            $this->lookupPaidFees(),
        );
    }

    public function test_a_new_admin_assigned_school_fee_obligation_can_be_paid(): void
    {
        $this->payInFull($this->schoolFees);
        $thirdTerm = $this->firstTerm->session->terms()->where('number', 3)->firstOrFail();

        // The admin opens the next obligation through the fee screens.
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/subcategories', [
            'category_id' => $this->schoolFees->category_id,
            'name' => 'Secondary - Third Term',
            'price' => 80000,
            'academic_term_id' => $thirdTerm->id,
            'is_tuition' => '1',
            'class_level_ids' => [$this->jss1->id],
        ])->assertRedirect('/admin/alpha/subcategories');
        $newFee = Subcategory::where('name', 'Secondary - Third Term')->sole();

        $this->assertSame('success', $this->payInFull($newFee, term: $thirdTerm)->status);
    }

    public function test_a_student_moved_to_another_class_mid_term_cannot_pay_school_fees_again(): void
    {
        // JSS1 pays First Term.
        $this->payInFull($this->schoolFees);

        // Moved to JSS2 in the same First Term, whose class has its own main fee.
        $jss2 = ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'JSS2', 'position' => 2, 'is_active' => true]);
        $jss2Fees = $this->mainFee($this->alpha, 'JSS2 - First Term', 85000, $this->firstTerm, $jss2);
        $jss2SecondTerm = $this->mainFee($this->alpha, 'JSS2 - Second Term', 85000, $this->secondTerm, $jss2);
        $this->anthony->update(['class_level_id' => $jss2->id, 'class_name' => 'JSS2']);

        // The JSS2 fee is the one that applies now, but First Term is already paid.
        $this->assertContains($jss2Fees->id, $this->postJson('/pay/alpha/student-search', ['name' => 'Anthony Ifeosame', 'admission_number' => 'DA/2025/2026'])->json('student.fee_ids'));
        $this->checkout($jss2Fees)
            ->assertSessionHasErrors(['subcategory_id' => 'School fees have already been paid for this student for First Term, 2026/2027.']);
        $this->assertSame(1, Transaction::count(), 'no second school-fee attempt is started');

        // Uniform remains payable, and JSS2's Second Term fee is due and payable.
        $this->assertSame('success', $this->payInFull($this->uniform)->status);
        $this->assertSame('success', $this->payInFull($jss2SecondTerm, term: $this->secondTerm)->status);
        $this->assertSame(1, Transaction::successful()->where('academic_term_id', $this->firstTerm->id)->whereNotNull('settled_obligation_key')->count());
    }

    public function test_two_different_main_fees_paid_concurrently_in_one_term_cannot_both_succeed(): void
    {
        // Both checkouts start before either is paid: JSS1's fee, then (after a class
        // change) JSS2's. Paystack confirms both; only one school fee is recorded.
        $jss1Attempt = $this->startCheckout($this->schoolFees);

        $jss2 = ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'JSS2', 'position' => 2, 'is_active' => true]);
        $jss2Fees = $this->mainFee($this->alpha, 'JSS2 - First Term', 85000, $this->firstTerm, $jss2);
        $this->anthony->update(['class_level_id' => $jss2->id, 'class_name' => 'JSS2']);
        $jss2Attempt = $this->startCheckout($jss2Fees);

        $this->assertSame(PaymentSettlementService::SETTLED, $this->settle($jss2Attempt)['outcome']);
        $this->assertSame(PaymentSettlementService::DUPLICATE_OBLIGATION, $this->settle($jss1Attempt)['outcome']);

        $this->assertSame('success', $jss2Attempt->refresh()->status);
        $this->assertSame('mismatch', $jss1Attempt->refresh()->status);
        $this->assertSame(1, Payout::count());
    }

    // ------------------------------------------------------------------- 9

    public function test_cross_school_manipulation_is_rejected(): void
    {
        $this->payInFull($this->schoolFees);
        $betaStudent = $this->makeStudent($this->beta, 'B/001', 'Beta Student', 'JSS1');

        // Beta's student with alpha's paid fee, at alpha: not alpha's student.
        $this->checkout($this->schoolFees, $betaStudent)->assertNotFound();
        // Alpha's student and fee posted to beta's checkout: not beta's.
        $this->checkout($this->schoolFees, slug: 'beta')->assertNotFound();
        // The paid fee with another term id: not payable in that term, so no bypass.
        $this->checkout($this->schoolFees, term: $this->secondTerm)->assertSessionHasErrors('subcategory_id');

        $this->assertSame(1, Transaction::count());
    }

    public function test_a_payment_made_before_the_rule_existed_still_counts_and_is_left_untouched(): void
    {
        $legacy = $this->makeSuccessfulTransaction($this->alpha, [
            'student_id' => $this->anthony->id,
            'subcategory_id' => $this->schoolFees->id,
            'category_id' => $this->schoolFees->category_id,
            'academic_term_id' => $this->firstTerm->id,
            'academic_session_id' => $this->firstTerm->academic_session_id,
            'fee_amount' => 80000,
        ]);
        $before = (array) DB::table('transactions')->where('id', $legacy->id)->first();

        $this->checkout($this->schoolFees)->assertSessionHasErrors('subcategory_id');
        $this->assertSame([['fee_id' => $this->schoolFees->id, 'term_id' => $this->firstTerm->id]], $this->lookupPaidFees());

        $this->assertSame($before, (array) DB::table('transactions')->where('id', $legacy->id)->first());
        $this->assertNull($legacy->refresh()->obligation_key);
    }

    // ------------------------------------------------------------------ 10

    public function test_the_payment_page_learns_which_school_fees_are_paid(): void
    {
        $this->assertSame([], $this->lookupPaidFees());

        $this->payInFull($this->schoolFees);
        $this->assertSame([['fee_id' => $this->schoolFees->id, 'term_id' => $this->firstTerm->id]], $this->lookupPaidFees());

        $this->get('/pay/alpha')->assertOk()
            ->assertSee('id="paidFee"', false)
            ->assertSee('School fees paid');
    }

    // ------------------------------------------------------------------ 11

    public function test_two_checkouts_paid_concurrently_cannot_both_succeed(): void
    {
        // Two tabs: both start before either is paid (allowed: nothing is paid yet).
        $first = $this->startCheckout($this->schoolFees);
        $second = $this->startCheckout($this->schoolFees);

        // Paystack confirms both charges.
        $this->assertSame(PaymentSettlementService::SETTLED, $this->settle($first)['outcome']);
        $this->assertSame(PaymentSettlementService::DUPLICATE_OBLIGATION, $this->settle($second)['outcome']);

        $this->assertSame('success', $first->refresh()->status);
        $this->assertSame('mismatch', $second->refresh()->status);
        $this->assertNull($second->settled_obligation_key);
        $this->assertSame(PaymentSettlementService::DUPLICATE_OBLIGATION, $second->decodedMetaData()['verification_error']['kind']);

        // One paid obligation, one payout, one receipt; a replay changes nothing.
        $this->assertSame(1, Transaction::successful()->where('subcategory_id', $this->schoolFees->id)->count());
        $this->assertSame(1, Payout::count());
        $this->assertSame(PaymentSettlementService::ALREADY_SETTLED, $this->settle($first)['outcome']);
        $this->assertSame('mismatch', $second->refresh()->status);
    }

    public function test_a_pending_attempt_from_before_the_rule_cannot_become_a_second_success(): void
    {
        // An attempt still open when the rule shipped: no obligation_key on the row.
        $old = $this->startCheckout($this->schoolFees);
        Transaction::whereKey($old->id)->update(['obligation_key' => null]);
        $this->payInFull($this->schoolFees);

        $this->assertSame(PaymentSettlementService::DUPLICATE_OBLIGATION, $this->settle($old->refresh())['outcome']);
        $this->assertSame('mismatch', $old->refresh()->status);
        $this->assertSame(1, Transaction::successful()->count());
    }

    public function test_the_payer_of_the_duplicate_is_told_to_contact_the_school(): void
    {
        $first = $this->startCheckout($this->schoolFees);
        $second = $this->startCheckout($this->schoolFees);
        $this->settle($first);

        $this->get('/payment/callback?reference='.$second->reference)
            ->assertRedirect('/s/alpha/payment')
            ->assertSessionHas('error', \App\Http\Controllers\PaymentController::MESSAGE_DUPLICATE);
        $this->assertSame('mismatch', $second->refresh()->status);
    }

    public function test_the_database_refuses_a_second_success_for_one_obligation(): void
    {
        $paid = $this->payInFull($this->schoolFees);

        // Even a write that skipped every application check cannot store it twice.
        $this->expectException(QueryException::class);
        Transaction::create([
            'school_id' => $this->alpha->id, 'reference' => 'raw-dup', 'amount' => 82000, 'status' => 'success', 'email' => 'x@example.test',
        ])->forceFill(['settled_obligation_key' => $paid->settled_obligation_key])->save();
    }

    public function test_a_race_past_the_check_ends_as_a_duplicate_not_a_second_success(): void
    {
        $first = $this->startCheckout($this->schoolFees);
        $second = $this->startCheckout($this->schoolFees);

        // Simulate the other settlement committing between our check and our write:
        // its row holds the key the moment this one tries to claim it.
        $this->settle($first);
        Transaction::whereKey($first->id)->update(['status' => 'pending']); // hide it from the check…
        $result = $this->settle($second); //                                  …so only the unique key stops it

        $this->assertNotSame(PaymentSettlementService::SETTLED, $result['outcome']);
        $this->assertNotSame('success', $second->refresh()->status);
        $this->assertSame(0, Transaction::where('id', $second->id)->whereNotNull('settled_obligation_key')->count());
    }
}
