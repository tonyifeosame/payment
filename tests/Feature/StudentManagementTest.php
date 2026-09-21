<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Phase 1 — student identity: creation, validation, per-school uniqueness and
 * tenant isolation of the roster.
 */
class StudentManagementTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    private Student $betaStudent;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');
        $this->betaStudent = $this->makeStudent($this->beta, 'B/001', 'Beta Only Student', 'SS 3');
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Adaeze Okonkwo',
            'admission_number' => 'a/2026/001',
            'class_name' => 'JSS 1',
        ], $overrides);
    }

    public function test_admin_can_create_a_student_for_their_own_school(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/students', $this->payload())
            ->assertRedirect('/admin/alpha/students');

        // Normalised on the way in, and attached to the acting school — not to any
        // school_id in the request.
        $this->assertDatabaseHas('students', [
            'school_id' => $this->alpha->id,
            'admission_number' => 'A/2026/001',
            'full_name' => 'Adaeze Okonkwo',
            'class_name' => 'JSS 1',
        ]);
    }

    public function test_a_school_id_in_the_request_is_ignored(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/students', $this->payload(['school_id' => $this->beta->id]));

        $this->assertDatabaseHas('students', ['admission_number' => 'A/2026/001', 'school_id' => $this->alpha->id]);
        $this->assertDatabaseMissing('students', ['admission_number' => 'A/2026/001', 'school_id' => $this->beta->id]);
    }

    public function test_required_fields_are_validated(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/students/create')
            ->post('/admin/alpha/students', ['full_name' => '', 'admission_number' => '', 'class_name' => ''])
            ->assertRedirect('/admin/alpha/students/create')
            ->assertSessionHasErrors(['full_name', 'admission_number', 'class_name']);

        $this->assertDatabaseCount('students', 1); // only the seeded beta student
    }

    public function test_admission_number_is_unique_within_a_school(): void
    {
        $this->makeStudent($this->alpha, 'A/2026/001', 'First');

        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/students/create')
            ->post('/admin/alpha/students', $this->payload(['admission_number' => ' a/2026/001 ', 'full_name' => 'Second']))
            ->assertSessionHasErrors('admission_number');

        $this->assertDatabaseMissing('students', ['full_name' => 'Second']);
    }

    public function test_the_same_admission_number_may_exist_at_two_different_schools(): void
    {
        // Beta already has B/001. Alpha may use it too.
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/students', $this->payload(['admission_number' => 'B/001']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Student::where('admission_number', 'B/001')->count());
    }

    public function test_the_database_itself_rejects_a_duplicate_within_a_school(): void
    {
        $this->makeStudent($this->alpha, 'A/2026/001');

        $this->expectException(\Illuminate\Database\QueryException::class);

        Student::create([
            'school_id' => $this->alpha->id,
            'full_name' => 'Duplicate',
            'admission_number' => 'A/2026/001',
            'class_name' => 'JSS 2',
        ]);
    }

    public function test_student_session_must_belong_to_the_same_school(): void
    {
        $betaSession = $this->makeSessionWithTerms($this->beta);

        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/students/create')
            ->post('/admin/alpha/students', $this->payload(['academic_session_id' => $betaSession->id]))
            ->assertSessionHasErrors('academic_session_id');
    }

    public function test_roster_lists_and_searches_only_own_students(): void
    {
        $this->makeStudent($this->alpha, 'A/2026/001', 'Alpha Student One', 'JSS 1');
        $this->makeStudent($this->alpha, 'A/2026/002', 'Alpha Student Two', 'JSS 2');

        $this->actingAsSchoolAdmin($this->alpha)
            ->get('/admin/alpha/students')
            ->assertOk()
            ->assertSee('Alpha Student One')
            ->assertSee('Alpha Student Two')
            ->assertDontSee('Beta Only Student');

        $this->actingAsSchoolAdmin($this->alpha)
            ->get('/admin/alpha/students?q=two')
            ->assertOk()
            ->assertSee('Alpha Student Two')
            ->assertDontSee('Alpha Student One');

        // Searching for beta's admission number from alpha finds nothing.
        $this->actingAsSchoolAdmin($this->alpha)
            ->get('/admin/alpha/students?q=B/001')
            ->assertOk()
            ->assertDontSee('Beta Only Student');

        $this->actingAsSchoolAdmin($this->alpha)
            ->get('/admin/alpha/students?class=JSS+2')
            ->assertOk()
            ->assertSee('Alpha Student Two')
            ->assertDontSee('Alpha Student One');
    }

    public function test_admin_can_edit_their_own_student(): void
    {
        $student = $this->makeStudent($this->alpha, 'A/2026/001', 'Old Name');

        $this->actingAsSchoolAdmin($this->alpha)
            ->get("/admin/alpha/students/{$student->id}/edit")
            ->assertOk()
            ->assertSee('Old Name');

        $this->actingAsSchoolAdmin($this->alpha)
            ->put("/admin/alpha/students/{$student->id}", $this->payload(['full_name' => 'New Name', 'class_name' => 'JSS 3']))
            ->assertRedirect('/admin/alpha/students');

        $this->assertDatabaseHas('students', ['id' => $student->id, 'full_name' => 'New Name', 'class_name' => 'JSS 3']);
    }

    public function test_editing_keeps_the_students_own_admission_number_valid(): void
    {
        $student = $this->makeStudent($this->alpha, 'A/2026/001');

        // Re-submitting the same admission number must not trip the unique rule.
        $this->actingAsSchoolAdmin($this->alpha)
            ->put("/admin/alpha/students/{$student->id}", $this->payload(['admission_number' => 'A/2026/001']))
            ->assertSessionHasNoErrors();
    }

    public function test_school_a_cannot_view_or_edit_school_b_student(): void
    {
        $id = $this->betaStudent->id;

        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$id}")->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$id}/edit")->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->put("/admin/alpha/students/{$id}", $this->payload(['full_name' => 'Hijacked']))->assertNotFound();

        // Via beta's own prefix, the middleware rejects the mismatched school.
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/beta/students/{$id}")->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/beta/students')->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->put("/admin/beta/students/{$id}", $this->payload(['full_name' => 'Hijacked']))->assertNotFound();

        $this->assertDatabaseHas('students', ['id' => $id, 'full_name' => 'Beta Only Student']);
    }

    public function test_anonymous_user_is_sent_to_login(): void
    {
        $this->get('/admin/alpha/students')->assertRedirect('/admin/login');
        $this->post('/admin/alpha/students', $this->payload())->assertRedirect('/admin/login');
        $this->assertDatabaseCount('students', 1);
    }
}
