<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\School;
use App\Models\Student;
use App\Models\Subcategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Only ACTIVE students can be paid for. Graduated students and students who left
 * keep their record and their payment history, but the public payment page
 * neither lists them nor accepts their id — enforced on the server, not the page.
 */
class PaymentStudentEligibilityTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    private AcademicTerm $term;

    private Subcategory $fee;

    private Student $active;

    private Student $graduated;

    private Student $left;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_secret', 'fees.markup_percent' => 2.5]);

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');
        $this->term = $this->makeSessionWithTerms($this->alpha, '2026/2027')->terms()->where('number', 1)->firstOrFail();
        $this->fee = $this->makeFee($this->alpha, 'School Fees', 'Tuition', 50000, $this->term->id);

        // Same surname so one search term finds all three when eligible.
        $this->active = $this->makeStudent($this->alpha, 'A/001', 'Ada Okonkwo', 'JSS 1');
        $this->graduated = $this->makeStudent($this->alpha, 'A/002', 'Grace Okonkwo', 'SS 3', ['status' => Student::STATUS_GRADUATED]);
        $this->left = $this->makeStudent($this->alpha, 'A/003', 'Lola Okonkwo', 'JSS 2', ['status' => Student::STATUS_LEFT]);

        Http::fake(['*/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']])]);
    }

    private function pay(int $studentId, string $slug = 'alpha')
    {
        return $this->post("/s/{$slug}/payment/initialize", [
            'email' => 'parent@example.test', 'name' => 'Parent',
            'category_id' => $this->fee->category_id, 'subcategory_id' => $this->fee->id, 'quantity' => 1,
            'student_id' => $studentId,
            'academic_session_id' => $this->term->academic_session_id, 'academic_term_id' => $this->term->id,
        ]);
    }

    public function test_only_the_active_student_appears_in_the_public_search(): void
    {
        $names = array_column($this->getJson('/s/alpha/payment/student-search?q=okonkwo')->assertOk()->json('students'), 'full_name');
        $this->assertSame(['Ada Okonkwo'], $names);

        // Searching a non-active student by name or admission number returns nothing.
        $this->getJson('/s/alpha/payment/student-search?q=grace')->assertOk()->assertExactJson(['students' => []]);
        $this->getJson('/s/alpha/payment/student-search?q=A/002')->assertOk()->assertExactJson(['students' => []]);
        $this->getJson('/s/alpha/payment/student-search?q=lola')->assertOk()->assertExactJson(['students' => []]);
        $this->getJson('/s/alpha/payment/student-search?q=A/003')->assertOk()->assertExactJson(['students' => []]);
    }

    public function test_a_graduated_student_id_cannot_initialize_a_payment(): void
    {
        $this->pay($this->graduated->id)->assertNotFound();
        $this->assertDatabaseCount('transactions', 0);
        Http::assertNothingSent();
        $this->assertDatabaseHas('students', ['id' => $this->graduated->id, 'status' => 'graduated']);
    }

    public function test_a_student_who_left_cannot_be_paid_for_but_an_active_one_can(): void
    {
        $this->pay($this->left->id)->assertNotFound();
        $this->assertDatabaseCount('transactions', 0);

        $this->pay($this->active->id)->assertRedirect('https://checkout.paystack.com/abc');
        $this->assertDatabaseHas('transactions', ['school_id' => $this->alpha->id, 'student_id' => $this->active->id, 'student_name' => 'Ada Okonkwo']);
    }

    public function test_a_previously_selected_inactive_student_is_not_reselected_after_a_failed_submit(): void
    {
        // The id survives in old input; the page must not re-offer it once the student is no longer active.
        $this->withSession(['_old_input' => ['student_id' => $this->graduated->id]])
            ->get('/s/alpha/payment')->assertOk()->assertDontSee('Grace Okonkwo');
        $this->withSession(['_old_input' => ['student_id' => $this->active->id]])
            ->get('/s/alpha/payment')->assertOk()->assertSee('Ada Okonkwo');
    }

    public function test_historical_transactions_of_inactive_students_stay_visible_to_the_school_admin(): void
    {
        $paid = $this->makeSuccessfulTransaction($this->alpha, [
            'reference' => 'ref-grad', 'student_id' => $this->graduated->id, 'student_name' => 'Grace Okonkwo',
            'student_admission_number' => 'A/002', 'student_class' => 'SS 3',
        ]);

        // Transactions list, transaction detail, student page + history, receipt.
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions')->assertOk()->assertSee('ref-grad')->assertSee('Grace Okonkwo');
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/transactions/{$paid->id}")->assertOk()->assertSee('Grace Okonkwo')->assertSee('SS 3');
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$this->graduated->id}")->assertOk()
            ->assertSee('Graduated')->assertSee('1 payment')->assertSee("/admin/alpha/transactions/{$paid->id}");
        $this->actingAsSchoolAdmin($this->alpha)->get("/payment/receipt/{$paid->id}")->assertOk()->assertSee('Grace Okonkwo');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students?status=graduated')->assertOk()->assertSee('Grace Okonkwo');

        // The export still includes it.
        $csv = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions/export')->streamedContent();
        $this->assertStringContainsString('ref-grad', $csv);
    }

    public function test_tenant_isolation_is_unchanged(): void
    {
        $betaTerm = $this->makeSessionWithTerms($this->beta, '2026/2027')->terms()->firstOrFail();
        $betaFee = $this->makeFee($this->beta, 'School Fees', 'Beta Tuition', 1, $betaTerm->id);
        $betaActive = $this->makeStudent($this->beta, 'B/001', 'Beta Okonkwo', 'SS 1');

        // Alpha's active student is invisible and unusable at beta, and vice versa.
        $this->getJson('/s/beta/payment/student-search?q=okonkwo')->assertOk()->assertJsonCount(1, 'students')->assertJsonPath('students.0.full_name', 'Beta Okonkwo');
        $this->getJson('/s/alpha/payment/student-search?q=beta')->assertOk()->assertExactJson(['students' => []]);
        $this->pay($betaActive->id)->assertNotFound();
        $this->post('/s/beta/payment/initialize', [
            'email' => 'parent@example.test', 'category_id' => $betaFee->category_id, 'subcategory_id' => $betaFee->id, 'quantity' => 1,
            'student_id' => $this->active->id, 'academic_session_id' => $betaTerm->academic_session_id, 'academic_term_id' => $betaTerm->id,
        ])->assertNotFound();
        $this->assertDatabaseCount('transactions', 0);

        // Admin side: alpha cannot see beta's student, nor a non-active alpha student through beta's prefix.
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$betaActive->id}")->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/beta/students/{$this->graduated->id}")->assertNotFound();
    }
}
