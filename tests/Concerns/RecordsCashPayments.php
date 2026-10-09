<?php

namespace Tests\Concerns;

use App\Models\AcademicTerm;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;
use App\Models\Subcategory;
use App\Models\Transaction;
use Illuminate\Testing\TestResponse;

/**
 * A school ready for cash payments: a 2026/2027 year whose First Term is current,
 * a JSS 1 class with an ₦80,000 First Term school fee, an active JSS 1 student,
 * and a second school (beta) for cross-tenant checks. Use with InteractsWithSchools.
 */
trait RecordsCashPayments
{
    protected School $alpha;

    protected School $beta;

    protected AcademicTerm $firstTerm;

    protected AcademicTerm $secondTerm;

    protected ClassLevel $jss1;

    protected Subcategory $schoolFee;

    protected Student $ada;

    protected function setUpCashSchool(): void
    {
        $this->alpha = $this->makeSchool('Alpha Academy', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');

        $session = $this->makeSessionWithTerms($this->alpha, '2026/2027');
        $this->firstTerm = $session->terms()->where('number', 1)->with('session')->firstOrFail();
        $this->secondTerm = $session->terms()->where('number', 2)->with('session')->firstOrFail();
        $this->alpha->forceFill(['current_academic_term_id' => $this->firstTerm->id])->save();

        $this->jss1 = ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'JSS 1', 'position' => 1, 'is_active' => true]);
        $this->schoolFee = $this->mainFee($this->alpha, 'JSS 1 First Term Fees', 80000, $this->firstTerm, $this->jss1);

        $this->ada = $this->makeStudent($this->alpha, 'ALP/001', 'Ada Obi', 'JSS 1', ['class_level_id' => $this->jss1->id]);
    }

    /**
     * Fake Paystack: initialize hands back a checkout URL; verify reports the charge
     * as successful for its exact amount (or the status set in $paystackStatus).
     */
    protected function fakePaystack(array &$paystackStatus = []): void
    {
        config(['services.paystack.secret_key' => 'sk_test_secret', 'fees.markup_percent' => 2.5]);
        \Illuminate\Support\Facades\Http::preventStrayRequests();
        \Illuminate\Support\Facades\Http::fake([
            '*/transaction/initialize' => \Illuminate\Support\Facades\Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123']]),
            '*/transaction/verify/*' => function (\Illuminate\Http\Client\Request $request) use (&$paystackStatus) {
                $reference = rawurldecode(basename(parse_url($request->url(), PHP_URL_PATH)));
                $t = Transaction::where('reference', $reference)->firstOrFail();

                return \Illuminate\Support\Facades\Http::response(['status' => true, 'data' => [
                    'status' => $paystackStatus[$reference] ?? 'success',
                    'channel' => 'card',
                    'reference' => 'PSK-'.$reference,
                    'amount' => (int) round(((float) $t->amount) * 100),
                    'currency' => 'NGN',
                ]]);
            },
        ]);
    }

    /** A parent starts an online checkout for a fee; returns the pending row. */
    protected function startOnlineCheckout(?Subcategory $fee = null, ?Student $student = null, ?AcademicTerm $term = null): Transaction
    {
        $fee ??= $this->schoolFee;
        $student ??= $this->ada;
        $term ??= $this->firstTerm;
        $before = Transaction::max('id') ?? 0;

        $this->post('/pay/alpha/initialize', [
            'email' => 'parent@example.test',
            'category_id' => $fee->category_id,
            'subcategory_id' => $fee->id,
            'quantity' => 1,
            'student_id' => $student->id,
            'academic_session_id' => $term->academic_session_id,
            'academic_term_id' => $term->id,
        ])->assertRedirect('https://checkout.paystack.com/abc123');

        return Transaction::where('id', '>', $before)->sole();
    }

    /** Paystack confirms a checkout (webhook/callback path). */
    protected function settleOnline(Transaction $t): array
    {
        return app(\App\Services\PaymentSettlementService::class)->settleByReference($t->reference);
    }

    protected function mainFee(School $school, string $name, float $price, ?AcademicTerm $term, ClassLevel $level): Subcategory
    {
        $fee = $this->makeFee($school, 'School Fees', $name, $price, $term?->id);
        $fee->classLevels()->sync([$level->id => ['school_id' => $school->id]]);
        $fee->forceFill(['is_tuition' => true])->save();

        return $fee;
    }

    protected function cashUrl(?Student $student = null, string $slug = 'alpha'): string
    {
        return "/admin/{$slug}/students/".($student ?? $this->ada)->id.'/cash-payment';
    }

    /** The details step's fields (year + term + payment details). */
    protected function cashDetails(array $overrides = []): array
    {
        return array_merge([
            'academic_year' => '2026/2027',
            'term' => 1,
            'paid_on' => now('Africa/Lagos')->format('Y-m-d'),
            'received_by' => 'Mrs Bursar',
            'receipt_number' => 'RC-1001',
            'notes' => 'Paid at the bursary',
        ], $overrides);
    }

    /** The record step's fields: details + confirmation + what the review page showed. */
    protected function cashConfirmation(array $overrides = [], ?Student $student = null, ?Subcategory $fee = null, ?AcademicTerm $term = null): array
    {
        $student ??= $this->ada;
        $fee ??= $this->schoolFee;
        $term ??= $this->firstTerm;

        return array_merge($this->cashDetails(), [
            'confirm_received' => '1',
            'expected_fee_id' => $fee->id,
            'expected_amount' => number_format((float) ($fee->fresh() ?? $fee)->price, 2, '.', ''),
            'expected_term_id' => $term->id,
            'expected_class_level_id' => $student->fresh()->class_level_id,
        ], $overrides);
    }

    protected function recordCash(array $overrides = [], ?Student $student = null): TestResponse
    {
        return $this->actingAsSchoolAdmin($this->alpha)->post($this->cashUrl($student), $this->cashConfirmation($overrides, $student));
    }

    /** Record a cash payment and return the row it wrote. */
    protected function recordedCash(array $overrides = [], ?Student $student = null): Transaction
    {
        $student ??= $this->ada;
        $this->recordCash($overrides, $student)->assertRedirect('/admin/alpha/students/'.$student->id);

        return Transaction::manual()->where('student_id', $student->id)->latest('id')->firstOrFail();
    }
}
