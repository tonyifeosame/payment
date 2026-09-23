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
        $this->alphaGeneralFee = $this->makeFee($this->alpha, 'Uniform', 'Shirt', 3000, null, allowsQuantity: true);
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
            'student_id' => $this->alphaStudent->id,
            'academic_session_id' => $this->alphaFirstTerm->academic_session_id,
            'academic_term_id' => $this->alphaFirstTerm->id,
        ], $overrides));
    }

    public function test_payment_page_shows_student_and_term_fields_and_branding(): void
    {
        $this->alpha->forceFill(['phone' => '0801 234 5678', 'address' => '1 Alpha Road'])->save();

        $this->get('/s/alpha/payment')
            ->assertOk()
            ->assertSee('name="student_id"', false)
            ->assertSee('student_query', false)
            ->assertSee('student-search', false)
            ->assertDontSee('name="admission_number"', false)
            ->assertSee('academic_term_id', false)
            ->assertSee('Alpha School')
            ->assertSee('0801 234 5678')
            ->assertSee('1 Alpha Road')
            // Sessions reach the page as JSON for the term dropdown.
            ->assertSee('"name":"2026\/2027"', false)
            ->assertDontSee('Beta Tuition')
            ->assertDontSee('B/001')
            // The roster is not embedded in the page; it is only reachable via search.
            ->assertDontSee('Adaeze Okonkwo')
            ->assertDontSee('A/2026/001');
    }

    public function test_payment_page_reselects_the_students_own_school_choice_after_a_failed_submit(): void
    {
        // A failed submit round-trips old('student_id'); the page re-hydrates it from
        // the database, within this school only.
        $this->from('/s/alpha/payment')
            ->pay(['academic_term_id' => $this->alphaSecondTerm->id]) // term fee in the wrong term -> validation error
            ->assertRedirect('/s/alpha/payment');

        $this->get('/s/alpha/payment')
            ->assertOk()
            ->assertSee('name="student_id" value="'.$this->alphaStudent->id.'"', false)
            ->assertSee('Adaeze Okonkwo');

        // A foreign id in old input is simply not echoed back.
        $this->from('/s/alpha/payment')
            ->pay(['student_id' => $this->betaStudent->id, 'academic_term_id' => $this->alphaSecondTerm->id]);

        $this->get('/s/alpha/payment')
            ->assertOk()
            ->assertDontSee('Beta Student')
            ->assertSee('name="student_id" value=""', false);
    }

    public function test_a_paystack_initialization_failure_keeps_the_form_filled_in(): void
    {
        // setUp faked a successful initialize; start a fresh HTTP factory so the
        // failure stub below is the one that answers.
        $this->app->forgetInstance(\Illuminate\Http\Client\Factory::class);
        Http::clearResolvedInstances();
        Http::fake(['*/transaction/initialize' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

        // Strings, as a real browser form would send them.
        $this->from('/s/alpha/payment')
            ->pay([
                'name' => 'Parent Okonkwo',
                'email' => 'parent@example.test',
                'student_id' => (string) $this->alphaStudent->id,
                'academic_session_id' => (string) $this->alphaFirstTerm->academic_session_id,
                'academic_term_id' => (string) $this->alphaFirstTerm->id,
                'category_id' => (string) $this->alphaTermFee->category_id,
                'subcategory_id' => (string) $this->alphaTermFee->id,
                'quantity' => '1',
            ])
            ->assertRedirect('/s/alpha/payment')
            ->assertSessionHas('error', 'Unable to initialize payment.')
            ->assertSessionHasInput('student_id', (string) $this->alphaStudent->id)
            ->assertSessionHasInput('academic_session_id', (string) $this->alphaFirstTerm->academic_session_id)
            ->assertSessionHasInput('academic_term_id', (string) $this->alphaFirstTerm->id)
            ->assertSessionHasInput('category_id', (string) $this->alphaTermFee->category_id)
            ->assertSessionHasInput('subcategory_id', (string) $this->alphaTermFee->id)
            ->assertSessionHasInput('email', 'parent@example.test')
            ->assertSessionHasInput('name', 'Parent Okonkwo')
            ->assertSessionHasInput('quantity', '1');
        $this->assertArrayNotHasKey('_token', session('_old_input'));

        // The pending transaction exists (unsettled), and the page comes back with
        // the student re-selected, from the database, plus the parent's fields.
        $this->assertSame('pending', Transaction::firstOrFail()->status);

        $this->get('/s/alpha/payment')
            ->assertOk()
            ->assertSee('Unable to initialize payment.')
            ->assertSee('name="student_id" value="'.$this->alphaStudent->id.'"', false)
            ->assertSee('Adaeze Okonkwo')
            ->assertSee('value="parent@example.test"', false)
            ->assertSee('value="Parent Okonkwo"', false)
            ->assertSee('const oldTermId = "'.$this->alphaFirstTerm->id.'"', false)
            ->assertSee('const oldSubcategoryId = "'.$this->alphaTermFee->id.'"', false)
            ->assertDontSee('sk_test_secret');
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
        ])->assertRedirect();

        $t = Transaction::firstOrFail();
        $this->assertEquals(51250.00, (float) $t->amount);
        $this->assertSame($this->alpha->id, (int) $t->school_id);
        $this->assertSame($this->alphaStudent->id, (int) $t->student_id);
    }

    public function test_browser_supplied_student_details_cannot_override_the_database_record(): void
    {
        $this->pay([
            'student_name' => 'Somebody Else',
            'full_name' => 'Somebody Else',
            'admission_number' => 'FAKE/999',
            'student_admission_number' => 'FAKE/999',
            'class_name' => 'SS 3',
            'student_class' => 'SS 3',
        ])->assertRedirect('https://checkout.paystack.com/abc123');

        $t = Transaction::firstOrFail();
        $this->assertSame($this->alphaStudent->id, (int) $t->student_id);
        $this->assertSame('Adaeze Okonkwo', $t->student_name);
        $this->assertSame('A/2026/001', $t->student_admission_number);
        $this->assertSame('JSS 1', $t->student_class);
    }

    public function test_a_single_charge_fee_refuses_a_quantity_instead_of_rewriting_it(): void
    {
        // L1: previously the quantity was silently rewritten to 1 because the
        // category was named "School Fees". Now the fee's own setting decides, and
        // a quantity the fee does not allow is refused, never quietly changed.
        $this->from('/s/alpha/payment')
            ->pay(['quantity' => 5])
            ->assertRedirect('/s/alpha/payment')
            ->assertSessionHasErrors('quantity');

        $this->assertDatabaseCount('transactions', 0);
        Http::assertNothingSent();
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

    public function test_a_nonexistent_student_id_cannot_initialize_a_payment(): void
    {
        $this->pay(['student_id' => 999999])->assertNotFound();
        $this->pay(['student_id' => 'abc'])->assertSessionHasErrors('student_id');

        $this->assertDatabaseCount('transactions', 0);
        Http::assertNothingSent();
    }

    public function test_a_student_is_required_once_the_school_has_a_roster(): void
    {
        $this->from('/s/alpha/payment')
            ->pay(['student_id' => ''])
            ->assertRedirect('/s/alpha/payment')
            ->assertSessionHasErrors('student_id');

        $this->from('/s/alpha/payment')
            ->post('/s/alpha/payment/initialize', [
                'email' => 'parent@example.test',
                'category_id' => $this->alphaTermFee->category_id,
                'subcategory_id' => $this->alphaTermFee->id,
                'quantity' => 1,
                'academic_term_id' => $this->alphaFirstTerm->id,
            ])
            ->assertSessionHasErrors('student_id');

        $this->assertDatabaseCount('transactions', 0);
        Http::assertNothingSent();
    }

    public function test_another_schools_student_id_cannot_be_used(): void
    {
        // Beta's student is real, but not at alpha: fail closed, reveal nothing.
        $this->pay(['student_id' => $this->betaStudent->id])->assertNotFound();

        // And vice versa, against beta's own valid fee/term.
        $this->post('/s/beta/payment/initialize', [
            'email' => 'parent@example.test',
            'category_id' => $this->betaFee->category_id,
            'subcategory_id' => $this->betaFee->id,
            'quantity' => 1,
            'student_id' => $this->alphaStudent->id,
            'academic_session_id' => $this->betaTerm->academic_session_id,
            'academic_term_id' => $this->betaTerm->id,
        ])->assertNotFound();

        $this->assertDatabaseCount('transactions', 0);
        Http::assertNothingSent();
    }

    public function test_a_deleted_student_cannot_be_paid_for(): void
    {
        $id = $this->alphaStudent->id;
        $this->alphaStudent->delete();

        $this->pay(['student_id' => $id])->assertNotFound();
        $this->assertDatabaseCount('transactions', 0);
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
        $this->get('/s/gamma/payment')->assertOk()->assertDontSee('name="student_id"', false);
    }

    // ------------------------------------------------------------ student search

    public function test_student_search_is_tenant_scoped_and_returns_only_what_the_picker_needs(): void
    {
        $this->getJson('/s/alpha/payment/student-search?q=ada')
            ->assertOk()
            ->assertExactJson(['students' => [[
                'id' => $this->alphaStudent->id,
                'full_name' => 'Adaeze Okonkwo',
                'class_name' => 'JSS 1',
                'admission_number_masked' => '*/****/001',
            ]]]);

        // Guardian details never leave the server.
        $this->alphaStudent->forceFill(['guardian_name' => 'Mrs Okonkwo', 'guardian_phone' => '08010000000', 'guardian_email' => 'g@example.test'])->save();
        $this->getJson('/s/alpha/payment/student-search?q=ada')
            ->assertOk()
            ->assertDontSee('Mrs Okonkwo')
            ->assertDontSee('08010000000')
            ->assertDontSee('g@example.test')
            ->assertJsonMissingPath('students.0.guardian_name')
            ->assertJsonMissingPath('students.0.guardian_phone')
            ->assertJsonMissingPath('students.0.guardian_email')
            ->assertJsonMissingPath('students.0.school_id');
    }

    public function test_public_search_masks_the_admission_number_but_the_server_still_stores_the_real_one(): void
    {
        // Searching BY admission number still works, but the response never echoes
        // the full number back — only enough of the tail to tell students apart.
        $response = $this->getJson('/s/alpha/payment/student-search?q=a/2026/001')
            ->assertOk()
            ->assertJsonCount(1, 'students')
            ->assertJsonPath('students.0.class_name', 'JSS 1')
            ->assertJsonPath('students.0.admission_number_masked', '*/****/001')
            ->assertJsonMissingPath('students.0.admission_number');
        $this->assertStringNotContainsString('A/2026/001', $response->getContent());
        $this->assertStringNotContainsString('A\\/2026\\/001', $response->getContent());

        // The page's re-hydration after a failed submit is masked the same way.
        $this->from('/s/alpha/payment')
            ->pay(['academic_term_id' => $this->alphaSecondTerm->id])
            ->assertRedirect('/s/alpha/payment');
        $this->get('/s/alpha/payment')
            ->assertOk()
            ->assertSee('*\/****\/001', false) // inside the @json() re-hydration payload
            ->assertDontSee('A/2026/001')
            ->assertDontSee('A\/2026\/001', false);

        // The transaction (and therefore the receipt) still carries the real number,
        // because the server resolves the student from the id, not from the page.
        $this->pay()->assertRedirect('https://checkout.paystack.com/abc123');
        $this->assertSame('A/2026/001', Transaction::firstOrFail()->student_admission_number);
    }

    public function test_masking_keeps_the_tail_and_hides_at_least_half_of_short_numbers(): void
    {
        $cases = [
            'A/2026/001' => '*/****/001',
            'DA/2026/021' => '**/****/021',
            '2024-0157' => '****-*157',
            'ABCDEFG' => '****EFG',
            '123456' => '***456',
            '12345' => '***45',
            '1234' => '**34',
            '12' => '*2',
            '1' => '1',
        ];

        foreach ($cases as $number => $masked) {
            $student = new Student(['admission_number' => $number]);
            $this->assertSame($masked, $student->maskedAdmissionNumber(), "masking {$number}");
        }
    }

    public function test_another_schools_student_never_appears_in_search_results(): void
    {
        // Beta's student is a perfect match for "beta" — at beta.
        $this->getJson('/s/beta/payment/student-search?q=beta')->assertOk()->assertJsonCount(1, 'students');

        // At alpha, the same query finds nothing, by name or by admission number.
        $this->getJson('/s/alpha/payment/student-search?q=beta')->assertOk()->assertExactJson(['students' => []]);
        $this->getJson('/s/alpha/payment/student-search?q=B/001')->assertOk()->assertExactJson(['students' => []]);

        // And alpha's student is invisible at beta.
        $this->getJson('/s/beta/payment/student-search?q=Adaeze')->assertOk()->assertExactJson(['students' => []]);
        $this->getJson('/s/beta/payment/student-search?q=A/2026')->assertOk()->assertExactJson(['students' => []]);

        // A school that does not exist is a 404, not an empty list.
        $this->getJson('/s/nope/payment/student-search?q=Adaeze')->assertNotFound();
    }

    public function test_selecting_a_student_yields_the_correct_admission_number_and_class(): void
    {
        $this->makeStudent($this->alpha, 'A/2026/002', 'Adaeze Okafor', 'SS 2');

        $r = $this->getJson('/s/alpha/payment/student-search?q=Adaeze Oka')->assertOk()->assertJsonCount(1, 'students');
        $picked = $r->json('students.0');

        $this->assertSame('Adaeze Okafor', $picked['full_name']);
        $this->assertSame('*/****/002', $picked['admission_number_masked']);
        $this->assertArrayNotHasKey('admission_number', $picked);
        $this->assertSame('SS 2', $picked['class_name']);

        // What the page auto-fills is exactly what the server stores on submit.
        $this->pay(['student_id' => $picked['id']])->assertRedirect();
        $t = Transaction::firstOrFail();
        $this->assertSame('A/2026/002', $t->student_admission_number);
        $this->assertSame('SS 2', $t->student_class);
        $this->assertSame('Adaeze Okafor', $t->student_name);
    }

    public function test_students_with_similar_or_identical_names_can_be_told_apart(): void
    {
        $twinA = $this->makeStudent($this->alpha, 'A/2026/010', 'Chidi Eze', 'JSS 2');
        $twinB = $this->makeStudent($this->alpha, 'A/2026/011', 'Chidi Eze', 'SS 1');
        $this->makeStudent($this->alpha, 'A/2026/012', 'Chidinma Eze', 'JSS 1');

        $students = $this->getJson('/s/alpha/payment/student-search?q=chidi')
            ->assertOk()
            ->assertJsonCount(3, 'students')
            ->json('students');

        // Every suggestion carries a class and admission number, so two "Chidi Eze"
        // rows are distinguishable, and each maps to its own id.
        $identical = array_values(array_filter($students, fn ($s) => $s['full_name'] === 'Chidi Eze'));
        $this->assertCount(2, $identical);
        $this->assertNotSame($identical[0]['id'], $identical[1]['id']);
        $this->assertEqualsCanonicalizing(['JSS 2', 'SS 1'], array_column($identical, 'class_name'));
        $this->assertEqualsCanonicalizing(['*/****/010', '*/****/011'], array_column($identical, 'admission_number_masked'));

        // Picking the second twin stores the second twin.
        $this->pay(['student_id' => $twinB->id])->assertRedirect();
        $t = Transaction::firstOrFail();
        $this->assertSame($twinB->id, (int) $t->student_id);
        $this->assertSame('SS 1', $t->student_class);
        $this->assertSame('A/2026/011', $t->student_admission_number);
        $this->assertNotSame($twinA->id, (int) $t->student_id);
    }

    public function test_student_search_matches_admission_numbers_and_ranks_name_prefixes_first(): void
    {
        $this->makeStudent($this->alpha, 'A/2026/020', 'Ngozi Adaeze', 'JSS 3');

        // Secondary: admission number, case-insensitive.
        $this->getJson('/s/alpha/payment/student-search?q=a/2026/020')
            ->assertOk()->assertJsonCount(1, 'students')->assertJsonPath('students.0.full_name', 'Ngozi Adaeze');

        // Primary: name, case-insensitive, and "Adaeze …" outranks "… Adaeze".
        $names = array_column($this->getJson('/s/alpha/payment/student-search?q=ADAEZE')->assertOk()->json('students'), 'full_name');
        $this->assertSame(['Adaeze Okonkwo', 'Ngozi Adaeze'], $names);
    }

    public function test_student_search_needs_a_query_and_caps_its_results(): void
    {
        $this->getJson('/s/alpha/payment/student-search')->assertUnprocessable();
        $this->getJson('/s/alpha/payment/student-search?q=a')->assertUnprocessable();
        $this->getJson('/s/alpha/payment/student-search?q='.str_repeat('a', 101))->assertUnprocessable();

        for ($i = 0; $i < 15; $i++) {
            $this->makeStudent($this->alpha, 'A/2026/1'.str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'Common Name '.$i, 'JSS 1');
        }
        $this->getJson('/s/alpha/payment/student-search?q=common')
            ->assertOk()
            ->assertJsonCount(\App\Http\Controllers\PaymentController::STUDENT_SEARCH_LIMIT, 'students');
    }

    public function test_student_search_is_rate_limited(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/s/alpha/payment/student-search?q=zz'.$i)->assertOk();
        }

        $this->getJson('/s/alpha/payment/student-search?q=Adaeze')->assertStatus(429);
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
