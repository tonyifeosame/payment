<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\School;
use App\Models\Student;
use App\Models\Subcategory;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Phase 1 — the public payment page identifies the student and the period, and
 * every identifier the browser sends is verified against the bound school.
 */
class PaymentStudentContextTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    private AcademicTerm $alphaFirstTerm;

    private AcademicTerm $alphaSecondTerm;

    private Subcategory $alphaTermFee;

    private Subcategory $alphaGeneralFee;

    private Student $alphaStudent;

    private AcademicTerm $betaTerm;

    private Subcategory $betaFee;

    private Student $betaStudent;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_secret', 'fees.markup_percent' => 2.5]);

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');

        $alphaSession = $this->makeSessionWithTerms($this->alpha, '2026/2027');
        $this->alphaFirstTerm = $alphaSession->terms()->where('number', 1)->firstOrFail();
        $this->alphaSecondTerm = $alphaSession->terms()->where('number', 2)->firstOrFail();
        $this->alphaTermFee = $this->makeFee($this->alpha, 'School Fees', 'JSS 1 Tuition', 50000, $this->alphaFirstTerm->id);
        $this->alphaGeneralFee = $this->makeFee($this->alpha, 'Uniform', 'Shirt', 3000, null);
        $this->alphaStudent = $this->makeStudent($this->alpha, 'A/2026/001', 'Adaeze Okonkwo', 'JSS 1');

        $this->betaTerm = $this->makeSessionWithTerms($this->beta, '2026/2027')->terms()->firstOrFail();
        $this->betaFee = $this->makeFee($this->beta, 'School Fees', 'Beta Tuition', 1, $this->betaTerm->id);
        $this->betaStudent = $this->makeStudent($this->beta, 'B/001', 'Beta Student', 'SS 1');

        Http::fake([
            '*/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123'],
            ]),
        ]);
    }

    private function pay(array $overrides = [], string $slug = 'alpha')
    {
        return $this->post("/s/{$slug}/payment/initialize", array_merge([
            'email' => 'parent@example.test',
            'name' => 'Parent Okonkwo',
            'category_id' => $this->alphaTermFee->category_id,
            'subcategory_id' => $this->alphaTermFee->id,
            'quantity' => 1,
            'admission_number' => 'a/2026/001',
            'academic_session_id' => $this->alphaFirstTerm->academic_session_id,
            'academic_term_id' => $this->alphaFirstTerm->id,
        ], $overrides));
    }

    public function test_payment_page_shows_student_and_term_fields_and_branding(): void
    {
        $this->alpha->forceFill(['phone' => '0801 234 5678', 'address' => '1 Alpha Road'])->save();

        $this->get('/s/alpha/payment')
            ->assertOk()
            ->assertSee('admission_number', false)
            ->assertSee('academic_term_id', false)
            ->assertSee('Alpha School')
            ->assertSee('0801 234 5678')
            ->assertSee('1 Alpha Road')
            // Sessions reach the page as JSON for the term dropdown.
            ->assertSee('"name":"2026\/2027"', false)
            ->assertDontSee('Beta Tuition')
            ->assertDontSee('B/001');
    }

    public function test_a_valid_payment_is_linked_to_the_student_session_term_and_fee(): void
    {
        $this->pay()->assertRedirect('https://checkout.paystack.com/abc123');

        $t = Transaction::firstOrFail();

        $this->assertSame($this->alpha->id, (int) $t->school_id);
        $this->assertSame($this->alphaStudent->id, (int) $t->student_id);
        $this->assertSame($this->alphaTermFee->id, (int) $t->subcategory_id);
        $this->assertSame($this->alphaTermFee->category_id, (int) $t->category_id);
        $this->assertSame($this->alphaFirstTerm->id, (int) $t->academic_term_id);
        $this->assertSame($this->alphaFirstTerm->academic_session_id, (int) $t->academic_session_id);

        // Snapshots for the receipt, independent of later edits/deletes.
        $this->assertSame('Adaeze Okonkwo', $t->student_name);
        $this->assertSame('A/2026/001', $t->student_admission_number);
        $this->assertSame('JSS 1', $t->student_class);
        $this->assertSame('2026/2027', $t->session_name);
        $this->assertSame('First Term', $t->term_name);

        // Money comes from the stored price and configured markup only.
        $this->assertEquals(50000.00, (float) $t->fee_amount);
        $this->assertEquals(1250.00, (float) $t->service_fee);
        $this->assertEquals(51250.00, (float) $t->amount);
        $this->assertSame('pending', $t->status);

        // Paystack was asked for exactly the server-side amount in kobo.
        Http::assertSent(fn ($request) => $request['amount'] === 5125000 && $request['reference'] === $t->reference);
    }

    public function test_browser_supplied_totals_and_ids_are_ignored(): void
    {
        $this->pay([
            'client_total' => '1',
            'total' => '1',
            'amount' => '1',
            'school_id' => $this->beta->id,
            'student_id' => $this->betaStudent->id,
        ])->assertRedirect();

        $t = Transaction::firstOrFail();
        $this->assertEquals(51250.00, (float) $t->amount);
        $this->assertSame($this->alpha->id, (int) $t->school_id);
        $this->assertSame($this->alphaStudent->id, (int) $t->student_id);
    }

    public function test_school_fees_quantity_is_forced_to_one(): void
    {
        $this->pay(['quantity' => 5])->assertRedirect();

        $this->assertEquals(51250.00, (float) Transaction::firstOrFail()->amount);
    }

    public function test_a_general_fee_is_payable_in_any_term(): void
    {
        $this->pay([
            'category_id' => $this->alphaGeneralFee->category_id,
            'subcategory_id' => $this->alphaGeneralFee->id,
            'academic_term_id' => $this->alphaSecondTerm->id,
            'quantity' => 2,
        ])->assertRedirect('https://checkout.paystack.com/abc123');

        $t = Transaction::firstOrFail();
        $this->assertSame($this->alphaSecondTerm->id, (int) $t->academic_term_id);
        $this->assertEquals(6000.00, (float) $t->fee_amount);
        $this->assertEquals(6150.00, (float) $t->amount);
    }

    public function test_a_term_fee_cannot_be_paid_for_a_different_term(): void
    {
        $this->from('/s/alpha/payment')
            ->pay(['academic_term_id' => $this->alphaSecondTerm->id])
            ->assertRedirect('/s/alpha/payment')
            ->assertSessionHasErrors('subcategory_id');

        $this->assertDatabaseCount('transactions', 0);
        Http::assertNothingSent();
    }

    public function test_term_is_required_once_the_school_has_sessions(): void
    {
        $this->from('/s/alpha/payment')
            ->pay(['academic_term_id' => '', 'academic_session_id' => ''])
            ->assertSessionHasErrors('academic_term_id');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_term_from_the_wrong_session_is_rejected(): void
    {
        $other = $this->makeSessionWithTerms($this->alpha, '2027/2028');

        $this->from('/s/alpha/payment')
            ->pay(['academic_session_id' => $other->id, 'academic_term_id' => $this->alphaFirstTerm->id])
            ->assertSessionHasErrors('academic_term_id');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_an_unknown_admission_number_is_rejected(): void
    {
        $this->from('/s/alpha/payment')
            ->pay(['admission_number' => 'NOPE/999'])
            ->assertSessionHasErrors('admission_number');

        $this->assertDatabaseCount('transactions', 0);
        Http::assertNothingSent();
    }

    public function test_admission_number_is_required_once_the_school_has_students(): void
    {
        $this->from('/s/alpha/payment')
            ->pay(['admission_number' => ''])
            ->assertSessionHasErrors('admission_number');

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_another_schools_student_cannot_be_selected(): void
    {
        // Beta's admission number does not exist at alpha, even though it is real.
        $this->from('/s/alpha/payment')
            ->pay(['admission_number' => 'B/001'])
            ->assertSessionHasErrors('admission_number');

        $this->assertDatabaseCount('transactions', 0);
        $this->assertSame(0, Transaction::where('student_id', $this->betaStudent->id)->count());
    }

    public function test_another_schools_fee_cannot_be_selected(): void
    {
        $this->pay([
            'category_id' => $this->betaFee->category_id,
            'subcategory_id' => $this->betaFee->id,
        ])->assertNotFound();

        // Own category with another school's fee id, and vice versa.
        $this->pay(['subcategory_id' => $this->betaFee->id])->assertNotFound();
        $this->pay(['category_id' => $this->betaFee->category_id])->assertNotFound();

        $this->assertDatabaseCount('transactions', 0);
        Http::assertNothingSent();
    }

    public function test_another_schools_term_cannot_be_selected(): void
    {
        $this->pay([
            'academic_session_id' => $this->betaTerm->academic_session_id,
            'academic_term_id' => $this->betaTerm->id,
        ])->assertNotFound();

        $this->assertDatabaseCount('transactions', 0);
    }

    public function test_a_school_without_students_or_sessions_still_accepts_payments(): void
    {
        // The pre-Phase-1 flow: no roster, no sessions. Gamma keeps working.
        $gamma = $this->makeSchool('Gamma School', 'gamma');
        $fee = $this->makeFee($gamma, 'School Fees', 'Tuition', 20000);

        $this->post('/s/gamma/payment/initialize', [
            'email' => 'parent@example.test',
            'name' => 'Parent',
            'category_id' => $fee->category_id,
            'subcategory_id' => $fee->id,
            'quantity' => 1,
        ])->assertRedirect('https://checkout.paystack.com/abc123');

        $t = Transaction::where('school_id', $gamma->id)->firstOrFail();
        $this->assertNull($t->student_id);
        $this->assertNull($t->academic_term_id);
        $this->assertEquals(20500.00, (float) $t->amount);

        // And its page does not demand a student or term.
        $this->get('/s/gamma/payment')->assertOk()->assertDontSee('name="admission_number"', false);
    }

    public function test_student_lookup_is_scoped_to_the_school_and_reveals_only_name_and_class(): void
    {
        $this->getJson('/s/alpha/payment/student-lookup?admission_number=a/2026/001')
            ->assertOk()
            ->assertExactJson(['found' => true, 'full_name' => 'Adaeze Okonkwo', 'class_name' => 'JSS 1']);

        // Beta's number is unknown at alpha…
        $this->getJson('/s/alpha/payment/student-lookup?admission_number=B/001')
            ->assertNotFound()
            ->assertExactJson(['found' => false]);

        // …and alpha's is unknown at beta.
        $this->getJson('/s/beta/payment/student-lookup?admission_number=A/2026/001')
            ->assertNotFound();

        $this->getJson('/s/alpha/payment/student-lookup')->assertUnprocessable();
    }

    public function test_student_lookup_is_rate_limited(): void
    {
        for ($i = 0; $i < 30; $i++) {
            $this->getJson('/s/alpha/payment/student-lookup?admission_number=x'.$i)->assertNotFound();
        }

        $this->getJson('/s/alpha/payment/student-lookup?admission_number=A/2026/001')->assertStatus(429);
    }

    public function test_settlement_keeps_the_student_link_and_records_the_school_share_payout(): void
    {
        $this->pay()->assertRedirect();
        $t = Transaction::firstOrFail();

        Http::fake([
            '*/transaction/verify/*' => Http::response([
                'status' => true,
                'data' => ['status' => 'success', 'amount' => 5125000, 'currency' => 'NGN', 'channel' => 'card', 'reference' => $t->reference],
            ]),
            '*/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_test']]),
            '*/transfer' => Http::response(['status' => true, 'data' => ['status' => 'pending', 'transfer_code' => 'TRF_1', 'reference' => 'x']]),
        ]);

        $this->get('/payment/callback?reference='.$t->reference)->assertRedirect('/s/alpha/payment');

        $t->refresh();
        $this->assertSame('success', $t->status);
        $this->assertNotNull($t->paid_at);
        $this->assertSame($this->alphaStudent->id, (int) $t->student_id);
        $this->assertSame('A/2026/001', $t->student_admission_number);

        // The payout owes the school its share only, never the service fee.
        $this->assertEquals(50000.00, (float) $t->payout->amount);
    }
}
