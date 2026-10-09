<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\Student;
use App\Models\Subcategory;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Class level -> fee assignment -> student -> applicable fee.
 *
 * A school assigns a fee to one or more of its class levels (fee_assignments) and
 * may mark it as the class's main fee (is_tuition). The payment page offers a
 * verified student only the fees that apply to their class and selects the class
 * fee for the parent; PaymentCheckoutService re-derives the same rule on submit,
 * so no request can pair a student with another class's (or school's) fee.
 */
class ClassLevelFeeAssignmentTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    private AcademicTerm $firstTerm;

    private AcademicTerm $secondTerm;

    private ClassLevel $primary1;

    private ClassLevel $jss1;

    private ClassLevel $jss2;

    private Subcategory $primaryTuition;

    private Subcategory $secondaryTuition;

    private Subcategory $jss2Tuition;

    private Subcategory $shirt;

    private Student $anthony;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_secret', 'fees.markup_percent' => 2.5]);

        $this->alpha = $this->makeSchool('Demo Academy', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');

        $session = $this->makeSessionWithTerms($this->alpha, '2026/2027');
        $this->firstTerm = $session->terms()->where('number', 1)->firstOrFail();
        $this->secondTerm = $session->terms()->where('number', 2)->firstOrFail();

        $this->primary1 = $this->makeLevel($this->alpha, 'Primary 1', 1);
        $this->jss1 = $this->makeLevel($this->alpha, 'JSS1', 2);
        $this->jss2 = $this->makeLevel($this->alpha, 'JSS2', 3);

        $this->primaryTuition = $this->makeFee($this->alpha, 'School Fees', 'Primary - First Term', 50000, $this->firstTerm->id);
        $this->secondaryTuition = $this->makeFee($this->alpha, 'School Fees', 'Secondary - First Term', 80000, $this->firstTerm->id);
        $this->jss2Tuition = $this->makeFee($this->alpha, 'School Fees', 'JSS2 - First Term', 85000, $this->firstTerm->id);
        $this->shirt = $this->makeFee($this->alpha, 'Uniform', 'Shirt', 3000, null, allowsQuantity: true);

        $this->assign($this->primaryTuition, [$this->primary1], tuition: true);
        $this->assign($this->secondaryTuition, [$this->jss1], tuition: true);
        $this->assign($this->jss2Tuition, [$this->jss2], tuition: true);

        $this->anthony = $this->makeStudent($this->alpha, 'DA/2025/2026', 'Anthony Ifeosame', 'JSS1', ['class_level_id' => $this->jss1->id]);

        Http::fake([
            '*/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123'],
            ]),
        ]);
    }

    private function makeLevel(School $school, string $name, int $position): ClassLevel
    {
        return ClassLevel::create(['school_id' => $school->id, 'name' => $name, 'position' => $position, 'is_active' => true]);
    }

    /** @param  array<int, ClassLevel>  $levels */
    private function assign(Subcategory $fee, array $levels, bool $tuition = false): void
    {
        $fee->classLevels()->sync(collect($levels)->mapWithKeys(fn ($l) => [$l->id => ['school_id' => $fee->school_id]])->all());
        $fee->forceFill(['is_tuition' => $tuition])->save();
    }

    private function lookup(string $name, string $admission, string $slug = 'alpha')
    {
        return $this->postJson("/pay/{$slug}/student-search", ['name' => $name, 'admission_number' => $admission]);
    }

    private function pay(Subcategory $fee, ?Student $student = null, array $overrides = [], string $slug = 'alpha')
    {
        $student ??= $this->anthony;

        return $this->post("/pay/{$slug}/initialize", array_merge([
            'email' => 'parent@example.test',
            'category_id' => $fee->category_id,
            'subcategory_id' => $fee->id,
            'quantity' => 1,
            'student_id' => $student->id,
            'student_name' => $student->full_name,
            'student_admission_number' => $student->admission_number,
        ], $overrides));
    }

    // ------------------------------------------------------------ regression

    public function test_anthony_ifeosame_in_jss1_gets_secondary_first_term_and_never_primary(): void
    {
        $response = $this->lookup('Anthony Ifeosame', 'DA/2025/2026')->assertOk();

        $this->assertSame('JSS1', $response->json('student.class_name'));
        $feeIds = $response->json('student.fee_ids');
        $this->assertContains($this->secondaryTuition->id, $feeIds);
        $this->assertNotContains($this->primaryTuition->id, $feeIds, 'Primary ₦50,000 must not be offered to a JSS1 student');
        $this->assertNotContains($this->jss2Tuition->id, $feeIds);

        // Exactly one main fee applies, so the page can select it for the parent.
        $tuitionIds = Subcategory::whereIn('id', $feeIds)->where('is_tuition', true)->pluck('id')->all();
        $this->assertSame([$this->secondaryTuition->id], $tuitionIds);

        // And the checkout charges ₦80,000 + 2.5% = ₦82,000 for it.
        $this->pay($this->secondaryTuition)->assertRedirect('https://checkout.paystack.com/abc123');
        $t = Transaction::sole();
        $this->assertSame('Secondary - First Term', $t->subcategory_name);
        $this->assertEquals(80000, (float) $t->fee_amount);
        $this->assertEquals(2000, (float) $t->service_fee);
        $this->assertEquals(82000, (float) $t->amount);
        $this->assertSame('JSS1', $t->student_class);

        // Submitting the Primary fee for him is refused, however the request is built.
        $this->pay($this->primaryTuition)->assertSessionHasErrors('subcategory_id');
        $this->assertSame(1, Transaction::count());
    }

    // ---------------------------------------------------------- class levels

    public function test_class_levels_are_created_by_the_school_and_scoped_to_it(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/classes', ['name' => 'SS1'])
            ->assertRedirect('/admin/alpha/students/classes');
        $this->actingAsSchoolAdmin($this->beta)->post('/admin/beta/students/classes', ['name' => 'SS1'])
            ->assertRedirect('/admin/beta/students/classes');

        $this->assertSame(1, ClassLevel::forSchool($this->alpha)->where('name', 'SS1')->count());
        $this->assertSame(1, ClassLevel::forSchool($this->beta)->where('name', 'SS1')->count());

        // Alpha's fee form lists only alpha's classes.
        $form = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/subcategories/create')->assertOk();
        $form->assertSee('Applies to classes')->assertSee('JSS1')->assertSee('SS1');
        $betaLevel = ClassLevel::forSchool($this->beta)->where('name', 'SS1')->sole();
        $form->assertDontSee('value="'.$betaLevel->id.'" ', false);
    }

    // -------------------------------------------------------- fee assignment

    public function test_an_admin_assigns_a_fee_to_class_levels_and_the_list_shows_it(): void
    {
        // Second Term: JSS1 and JSS2 already have their First Term main fees, and a
        // class may have only one main fee per term.
        $fee = $this->makeFee($this->alpha, 'School Fees', 'Senior - Second Term', 90000, $this->secondTerm->id);

        $this->actingAsSchoolAdmin($this->alpha)->put("/admin/alpha/subcategories/{$fee->id}", [
            'category_id' => $fee->category_id,
            'name' => 'Senior - Second Term',
            'price' => 90000,
            'academic_term_id' => $this->secondTerm->id,
            'is_tuition' => '1',
            'class_level_ids' => [$this->jss1->id, $this->jss2->id],
        ])->assertRedirect('/admin/alpha/subcategories');

        $fee->refresh();
        $this->assertTrue($fee->is_tuition);
        $this->assertEqualsCanonicalizing([$this->jss1->id, $this->jss2->id], $fee->classLevels->pluck('id')->all());
        // One fee, several classes: no duplicate fee rows, one assignment row per class.
        $this->assertSame(2, DB::table('fee_assignments')->where('subcategory_id', $fee->id)->where('school_id', $this->alpha->id)->count());

        // Fee | Class level | Term | Session | Amount
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/subcategories')->assertOk()
            ->assertSee('Applies to')
            ->assertSeeInOrder(['Secondary - First Term', 'School fees · main class fee', 'School Fees', 'JSS1', '₦80,000.00', '2026/2027', 'First Term'])
            ->assertSeeInOrder(['Shirt', 'Uniform', 'All classes', '₦3,000.00']);

        // Unticking every class makes it a fee for all classes again.
        $this->actingAsSchoolAdmin($this->alpha)->put("/admin/alpha/subcategories/{$fee->id}", [
            'category_id' => $fee->category_id,
            'name' => 'Senior - First Term',
            'price' => 90000,
            'academic_term_id' => $this->firstTerm->id,
        ])->assertRedirect('/admin/alpha/subcategories');
        $this->assertSame(0, $fee->classLevels()->count());
        $this->assertFalse($fee->refresh()->is_tuition);
    }

    public function test_creating_a_fee_with_an_assignment_is_audited(): void
    {
        $category = $this->secondaryTuition->category;

        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/subcategories', [
            'category_id' => $category->id,
            'name' => 'JSS1 - Second Term',
            'price' => 80000,
            'academic_term_id' => $this->secondTerm->id,
            'is_tuition' => '1',
            'class_level_ids' => [$this->jss1->id],
        ])->assertRedirect('/admin/alpha/subcategories');

        $fee = Subcategory::where('name', 'JSS1 - Second Term')->sole();
        $this->assertSame([$this->jss1->id], $fee->classLevels->pluck('id')->all());

        $event = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_FEE_CREATED)->where('subject_id', $fee->id)->sole();
        $this->assertSame('JSS1', $event->changes['class_levels']['to']);
        $this->assertTrue($event->changes['is_tuition']['to']);

        $this->actingAsSchoolAdmin($this->alpha)->put("/admin/alpha/subcategories/{$fee->id}", [
            'category_id' => $category->id,
            'name' => 'JSS1 - Second Term',
            'price' => 80000,
            'academic_term_id' => $this->secondTerm->id,
            'is_tuition' => '1',
            'class_level_ids' => [$this->jss1->id, $this->jss2->id],
        ]);
        $update = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_FEE_UPDATED)->where('subject_id', $fee->id)->sole();
        $this->assertSame(['from' => 'JSS1', 'to' => 'JSS1, JSS2'], $update->changes['class_levels']);
    }

    public function test_another_schools_class_level_cannot_be_assigned(): void
    {
        $betaLevel = $this->makeLevel($this->beta, 'JSS1', 1);

        $this->actingAsSchoolAdmin($this->alpha)->put("/admin/alpha/subcategories/{$this->shirt->id}", [
            'category_id' => $this->shirt->category_id,
            'name' => 'Shirt',
            'price' => 3000,
            'class_level_ids' => [$this->jss1->id, $betaLevel->id],
        ])->assertNotFound();
        $this->assertSame(0, $this->shirt->classLevels()->count(), 'nothing is assigned when any id is foreign');

        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/subcategories', [
            'category_id' => $this->shirt->category_id,
            'name' => 'Sneaky',
            'price' => 1,
            'class_level_ids' => [$betaLevel->id],
        ])->assertNotFound();
        $this->assertFalse(Subcategory::where('name', 'Sneaky')->exists());
    }

    public function test_a_class_level_with_fees_assigned_cannot_be_deleted(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->delete("/admin/alpha/students/classes/{$this->primary1->id}")
            ->assertSessionHas('error');
        $this->assertTrue(ClassLevel::whereKey($this->primary1->id)->exists());
        // Otherwise Primary - First Term would lose its last class and open to everyone.
        $this->assertSame([$this->primary1->id], $this->primaryTuition->classLevels->pluck('id')->all());
    }

    // ------------------------------------------------------- applicability

    public function test_assignment_respects_the_fee_term(): void
    {
        // Assigned to JSS1, but it is a First Term fee: not payable once the school is
        // in Second Term, even with First Term posted by the form.
        $this->alpha->forceFill(['current_academic_term_id' => $this->secondTerm->id])->save();
        $this->pay($this->secondaryTuition, overrides: ['academic_term_id' => $this->firstTerm->id])
            ->assertSessionHasErrors('subcategory_id');
        $this->assertSame(0, Transaction::count());
    }

    public function test_the_student_lookup_returns_only_fees_for_that_students_class(): void
    {
        $this->makeStudent($this->alpha, 'P/001', 'Chidi Okafor', 'Primary 1', ['class_level_id' => $this->primary1->id]);

        $ids = $this->lookup('Chidi Okafor', 'P/001')->assertOk()->json('student.fee_ids');

        $this->assertEqualsCanonicalizing([$this->primaryTuition->id, $this->shirt->id], $ids);
    }

    public function test_a_fee_assigned_to_another_class_is_rejected_on_submit(): void
    {
        $this->pay($this->jss2Tuition)->assertSessionHasErrors(['subcategory_id' => "The selected fee does not apply to this student's class."]);
        $this->pay($this->primaryTuition)->assertSessionHasErrors('subcategory_id');
        $this->assertSame(0, Transaction::count());
    }

    public function test_another_schools_fee_is_refused_for_this_schools_student(): void
    {
        $betaTerm = $this->makeSessionWithTerms($this->beta, '2026/2027')->terms()->firstOrFail();
        $betaLevel = $this->makeLevel($this->beta, 'JSS1', 1);
        $betaFee = $this->makeFee($this->beta, 'School Fees', 'Beta JSS1', 1, $betaTerm->id);
        $this->assign($betaFee, [$betaLevel], tuition: true);

        // Alpha's student with beta's fee, at alpha: the fee is not alpha's.
        $this->pay($betaFee, overrides: ['academic_term_id' => $betaTerm->id, 'academic_session_id' => $betaTerm->academic_session_id])
            ->assertNotFound();

        // Beta's student with alpha's fee, at alpha: the student is not alpha's.
        $betaStudent = $this->makeStudent($this->beta, 'B/001', 'Beta Student', 'JSS1', ['class_level_id' => $betaLevel->id]);
        $this->pay($this->secondaryTuition, $betaStudent)->assertNotFound();

        // Alpha's student at beta, with beta's fee: the student is not beta's.
        $this->pay($betaFee, $this->anthony, ['academic_term_id' => $betaTerm->id, 'academic_session_id' => $betaTerm->academic_session_id], 'beta')
            ->assertNotFound();

        $this->assertSame(0, Transaction::count());
    }

    public function test_a_student_whose_level_is_another_schools_cannot_pay_an_assigned_fee(): void
    {
        // A corrupt row: alpha's student pointing at a beta class level. Fail closed.
        $betaLevel = $this->makeLevel($this->beta, 'JSS1', 1);
        DB::table('fee_assignments')->insert([
            'school_id' => $this->beta->id, 'subcategory_id' => $this->secondaryTuition->id,
            'class_level_id' => $betaLevel->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->anthony->forceFill(['class_level_id' => $betaLevel->id])->save();

        $this->assertFalse($this->secondaryTuition->fresh()->isPayableForStudent($this->anthony->fresh()));
        $this->assertNotContains($this->secondaryTuition->id, $this->lookup('Anthony Ifeosame', 'DA/2025/2026')->json('student.fee_ids'));
        $this->pay($this->secondaryTuition)->assertSessionHasErrors('subcategory_id');
    }

    // --------------------------------------------------- missing assignments

    public function test_an_unassigned_fee_keeps_the_existing_behaviour_for_every_student(): void
    {
        $legacy = $this->makeStudent($this->alpha, 'L/001', 'Legacy Student', 'Primary 5'); // no class level

        $this->pay($this->shirt, $legacy, ['quantity' => 2])->assertRedirect('https://checkout.paystack.com/abc123');
        $this->pay($this->shirt, $this->anthony)->assertRedirect('https://checkout.paystack.com/abc123');
        $this->assertSame(2, Transaction::count());
    }

    public function test_a_student_not_yet_mapped_to_a_class_cannot_pay_a_class_fee(): void
    {
        $legacy = $this->makeStudent($this->alpha, 'L/001', 'Legacy Student', 'JSS1'); // free text only

        // The free-text class "JSS1" is never read as the JSS1 level.
        $ids = $this->lookup('Legacy Student', 'L/001')->json('student.fee_ids');
        $this->assertSame([$this->shirt->id], $ids);

        $this->pay($this->secondaryTuition, $legacy)->assertSessionHasErrors('subcategory_id');
        $this->assertSame(0, Transaction::count());
    }

    public function test_without_a_roster_class_fees_are_not_offered_or_accepted(): void
    {
        $gamma = $this->makeSchool('Gamma School', 'gamma');
        $level = $this->makeLevel($gamma, 'JSS1', 1);
        $classFee = $this->makeFee($gamma, 'School Fees', 'JSS1 Fees', 70000);
        $openFee = $this->makeFee($gamma, 'School Fees', 'Open Fee', 1000);
        $this->assign($classFee, [$level], tuition: true);
        $this->assertFalse($gamma->requiresStudentOnPayment());

        $page = $this->get('/pay/gamma')->assertOk();
        $page->assertSee('Open Fee')->assertDontSee('JSS1 Fees');

        $this->post('/pay/gamma/initialize', [
            'email' => 'p@example.test', 'category_id' => $classFee->category_id, 'subcategory_id' => $classFee->id, 'quantity' => 1,
        ])->assertSessionHasErrors('subcategory_id');
        $this->post('/pay/gamma/initialize', [
            'email' => 'p@example.test', 'category_id' => $openFee->category_id, 'subcategory_id' => $openFee->id, 'quantity' => 1,
        ])->assertRedirect('https://checkout.paystack.com/abc123');
        $this->assertSame(1, Transaction::count());
    }

    // ------------------------------------------------------- multiple fees

    public function test_additional_fees_stay_payable_alongside_the_class_fee(): void
    {
        $trousers = $this->makeFee($this->alpha, 'Uniform', 'Trousers', 4000);
        $this->assign($trousers, [$this->jss1]); // assigned, but not a main fee

        $ids = $this->lookup('Anthony Ifeosame', 'DA/2025/2026')->json('student.fee_ids');
        $this->assertEqualsCanonicalizing([$this->secondaryTuition->id, $this->shirt->id, $trousers->id], $ids);

        // Only one of them is the class fee; the others are additional, never bundled.
        $this->assertSame([$this->secondaryTuition->id], Subcategory::whereIn('id', $ids)->where('is_tuition', true)->pluck('id')->all());

        $this->pay($trousers)->assertRedirect('https://checkout.paystack.com/abc123');
        $t = Transaction::sole();
        $this->assertEquals(4000, (float) $t->fee_amount);
        $this->assertEquals(100, (float) $t->service_fee);
        $this->assertEquals(4100, (float) $t->amount);
    }

    public function test_a_second_main_fee_for_the_same_class_and_term_is_refused(): void
    {
        $payload = [
            'category_id' => $this->secondaryTuition->category_id, 'name' => 'Secondary Boarding - First Term', 'price' => 120000,
            'academic_term_id' => $this->firstTerm->id, 'is_tuition' => '1', 'class_level_ids' => [$this->jss1->id],
        ];

        // JSS1 already has "Secondary - First Term" as its First Term main fee.
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/subcategories', $payload)
            ->assertSessionHasErrors('class_level_ids');
        $this->assertFalse(Subcategory::where('name', 'Secondary Boarding - First Term')->exists());

        // Editing another fee into the clash is refused the same way, and changes nothing.
        $this->actingAsSchoolAdmin($this->alpha)->put("/admin/alpha/subcategories/{$this->jss2Tuition->id}", array_merge($payload, [
            'name' => 'JSS2 - First Term', 'price' => 85000, 'class_level_ids' => [$this->jss1->id, $this->jss2->id],
        ]))->assertSessionHasErrors('class_level_ids');
        $this->assertEqualsCanonicalizing([$this->jss2->id], $this->jss2Tuition->fresh()->classLevels->pluck('id')->all());

        // The same class may have a main fee for another term, and an ORDINARY fee
        // alongside its main fee in the same term.
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/subcategories', array_merge($payload, [
            'name' => 'Secondary - Second Term', 'academic_term_id' => $this->secondTerm->id,
        ]))->assertSessionHasNoErrors();
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/subcategories', array_merge($payload, [
            'name' => 'Boarding levy', 'is_tuition' => '0',
        ]))->assertSessionHasNoErrors();

        // Re-saving the existing main fee unchanged is not a clash with itself.
        $this->actingAsSchoolAdmin($this->alpha)->put("/admin/alpha/subcategories/{$this->secondaryTuition->id}", array_merge($payload, [
            'name' => 'Secondary - First Term', 'price' => 80000,
        ]))->assertSessionHasNoErrors();
    }

    public function test_a_main_fee_must_be_assigned_to_a_class(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/subcategories', [
            'category_id' => $this->secondaryTuition->category_id, 'name' => 'Floating tuition', 'price' => 70000,
            'academic_term_id' => $this->firstTerm->id, 'is_tuition' => '1',
        ])->assertSessionHasErrors('class_level_ids');

        $this->assertFalse(Subcategory::where('name', 'Floating tuition')->exists());
    }

    public function test_an_unassigned_main_fee_is_never_payable_or_offered(): void
    {
        // A main fee left with no class (legacy data, or written around the form)
        // belongs to no class, so it must never become every class's alternative.
        $this->primaryTuition->classLevels()->detach();
        $this->primaryTuition->forceFill(['is_tuition' => true])->save();

        $ids = $this->lookup('Anthony Ifeosame', 'DA/2025/2026')->json('student.fee_ids');
        $this->assertNotContains($this->primaryTuition->id, $ids);
        $this->assertContains($this->secondaryTuition->id, $ids);

        $this->pay($this->primaryTuition)->assertSessionHasErrors('subcategory_id');
        $this->assertSame(0, Transaction::count());
    }

    public function test_an_ambiguous_legacy_pair_of_main_fees_stays_class_bound(): void
    {
        // Two main fees for one class and term can only exist if written around the
        // admin rule. Both stay bound to their class: the browser's single-candidate
        // rule declines to auto-select, and other classes' fees are still refused.
        $boarding = $this->makeFee($this->alpha, 'School Fees', 'Secondary Boarding - First Term', 120000, $this->firstTerm->id);
        $this->assign($boarding, [$this->jss1], tuition: true);

        $ids = $this->lookup('Anthony Ifeosame', 'DA/2025/2026')->json('student.fee_ids');
        $this->assertCount(2, Subcategory::whereIn('id', $ids)->where('is_tuition', true)->get());
        $this->pay($this->primaryTuition)->assertSessionHasErrors('subcategory_id');
    }

    // -------------------------------------------------- public payment page

    public function test_the_payment_page_carries_the_class_fee_markup_and_flags(): void
    {
        $page = $this->get('/pay/alpha')->assertOk();

        $page->assertSee('id="autoFee"', false)
            ->assertSee('Pay a different fee instead')
            ->assertSee('"is_tuition":true', false)
            ->assertSee('"is_tuition":false', false);

        // Which fee belongs to which class is revealed per verified student by the
        // lookup, never dumped on the page.
        $page->assertDontSee('"class_levels"', false)->assertDontSee('"pivot"', false);
    }

    // ------------------------------------------------------------ economics

    public function test_the_service_fee_is_unchanged_and_computed_on_the_server(): void
    {
        $this->pay($this->secondaryTuition, overrides: ['client_total' => '1', 'total' => '1'])->assertRedirect('https://checkout.paystack.com/abc123');

        $t = Transaction::sole();
        $this->assertEquals(2.5, (float) $t->meta_data['markup_percent']);
        $this->assertEquals(80000, (float) $t->meta_data['base_amount']);
        $this->assertEquals(2000, (float) $t->meta_data['markup_amount']);
        $this->assertEquals(82000, (float) $t->meta_data['gross_amount']);
    }

    public function test_assigning_a_fee_leaves_existing_transactions_untouched(): void
    {
        $fee = $this->makeFee($this->alpha, 'School Fees', 'Old Fee', 50000, $this->firstTerm->id);
        $old = $this->makeSuccessfulTransaction($this->alpha, [
            'subcategory_id' => $fee->id, 'category_id' => $fee->category_id,
            'student_class' => 'Primary 5', 'subcategory_name' => 'Old Fee',
        ]);
        $before = DB::table('transactions')->where('id', $old->id)->first();

        $this->actingAsSchoolAdmin($this->alpha)->put("/admin/alpha/subcategories/{$fee->id}", [
            'category_id' => $fee->category_id,
            'name' => 'Old Fee',
            'price' => 50000,
            'academic_term_id' => $this->firstTerm->id,
            'is_tuition' => '0',
            'class_level_ids' => [$this->jss1->id],
        ])->assertRedirect('/admin/alpha/subcategories');

        $this->assertEquals($before, DB::table('transactions')->where('id', $old->id)->first());
    }
}
