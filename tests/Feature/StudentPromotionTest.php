<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentPromotion;
use App\Models\StudentPromotionEntry;
use App\Services\AcademicPeriodService;
use App\Services\StudentPromotionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Bulk promotion: follows the school's own ladder, applies only to the selected
 * students, in one transaction, once per academic year, and only within the
 * school. The admin names an academic year, never a session.
 */
class StudentPromotionTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    private AcademicSession $s2025;

    private AcademicSession $s2026;

    /** @var array<string, ClassLevel> */
    private array $l;

    /** @var array<string, Student> */
    private array $st;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');

        // Alpha is in the last term of 2025/2026, so it may promote into 2026/2027.
        $this->s2025 = $this->makeSessionWithTerms($this->alpha, '2025/2026');
        $this->s2026 = $this->makeSessionWithTerms($this->alpha, '2026/2027');
        $this->setCurrentTerm($this->alpha, '2025/2026', 3);

        // A ladder that is deliberately NOT in name order, to prove nothing is parsed.
        $this->l = [];
        foreach (['Lower', 'Middle', 'Upper', 'Final'] as $i => $name) {
            $this->l[$name] = ClassLevel::create(['school_id' => $this->alpha->id, 'name' => $name, 'position' => $i + 1, 'is_active' => true]);
        }

        $this->st = [
            'a' => $this->makeStudent($this->alpha, 'A/1', 'Ada Lower', 'Lower', ['class_level_id' => $this->l['Lower']->id]),
            'b' => $this->makeStudent($this->alpha, 'A/2', 'Bola Lower', 'Lower', ['class_level_id' => $this->l['Lower']->id]),
            'c' => $this->makeStudent($this->alpha, 'A/3', 'Chi Middle', 'Middle', ['class_level_id' => $this->l['Middle']->id]),
            'd' => $this->makeStudent($this->alpha, 'A/4', 'Dayo Final', 'Final', ['class_level_id' => $this->l['Final']->id]),
            'legacy' => $this->makeStudent($this->alpha, 'A/5', 'Eze Legacy', 'Unmapped'),
            'left' => $this->makeStudent($this->alpha, 'A/6', 'Femi Left', 'Lower', ['class_level_id' => $this->l['Lower']->id, 'status' => 'left']),
        ];
    }

    private function selection(array $students, array $extra = []): array
    {
        $payload = ['to_year' => '2026/2027', 'students' => [], 'from' => []];
        foreach ($students as $s) {
            $payload['students'][] = $s->id;
            $payload['from'][$s->id] = $s->class_level_id ?? 0;
        }

        return array_merge($payload, $extra);
    }

    public function test_preview_follows_the_ladder_not_the_names(): void
    {
        $page = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/promotion')->assertOk();

        // In the last term it defaults to the year after the current one.
        $page->assertSee('2025/2026')->assertSee('<option value="2026/2027" selected>2026/2027</option>', false)
            ->assertSeeInOrder(['Lower', '2', 'Middle'])
            ->assertSeeInOrder(['Middle', '1', 'Upper'])
            ->assertSeeInOrder(['Final', '1', 'Graduated'])
            ->assertSee('Ada Lower')->assertSee('Chi Middle')->assertSee('Dayo Final')
            ->assertDontSee('Femi Left')          // not active
            ->assertSee('1 active student without a class'); // legacy, cannot be promoted
        $page->assertDontSee('Eze Legacy');
    }

    public function test_promotion_moves_selected_students_and_graduates_the_last_class(): void
    {
        // Review step: nothing changes.
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/students/promotion/review', $this->selection([$this->st['a'], $this->st['c'], $this->st['d']]))
            ->assertOk()->assertSee('3 students')->assertSee('1 student excluded')->assertSee('Confirm promotion');
        $this->assertSame($this->l['Lower']->id, $this->st['a']->fresh()->class_level_id);
        $this->assertDatabaseCount('student_promotions', 0);

        // Apply: b is excluded and untouched.
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/students/promotion', $this->selection([$this->st['a'], $this->st['c'], $this->st['d']]))
            ->assertRedirect('/admin/alpha/students')
            ->assertSessionHas('success', 'Promotion into 2026/2027 applied: 2 students promoted, 1 graduated.');

        $this->assertDatabaseHas('students', ['id' => $this->st['a']->id, 'class_level_id' => $this->l['Middle']->id, 'class_name' => 'Middle', 'status' => 'active']);
        $this->assertDatabaseHas('students', ['id' => $this->st['c']->id, 'class_level_id' => $this->l['Upper']->id, 'class_name' => 'Upper', 'status' => 'active']);
        $this->assertDatabaseHas('students', ['id' => $this->st['d']->id, 'class_level_id' => $this->l['Final']->id, 'class_name' => 'Final', 'status' => 'graduated']);
        $this->assertDatabaseHas('students', ['id' => $this->st['b']->id, 'class_level_id' => $this->l['Lower']->id, 'class_name' => 'Lower', 'status' => 'active']);
        $this->assertDatabaseHas('students', ['id' => $this->st['legacy']->id, 'class_level_id' => null, 'class_name' => 'Unmapped']);
        $this->assertDatabaseHas('students', ['id' => $this->st['left']->id, 'status' => 'left', 'class_level_id' => $this->l['Lower']->id]);

        // Audit trail.
        $run = StudentPromotion::sole();
        $this->assertSame($this->alpha->id, $run->school_id);
        $this->assertSame($this->s2025->id, $run->from_academic_session_id);
        $this->assertSame($this->s2026->id, $run->to_academic_session_id);
        $this->assertSame('school_admin', $run->performed_by);
        $this->assertSame([2, 1, 1], [$run->promoted_count, $run->graduated_count, $run->excluded_count]);
        $this->assertDatabaseHas('student_promotion_entries', ['student_promotion_id' => $run->id, 'student_id' => $this->st['a']->id, 'from_class_level_id' => $this->l['Lower']->id, 'to_class_level_id' => $this->l['Middle']->id, 'from_class_name' => 'Lower', 'to_class_name' => 'Middle', 'action' => 'promoted']);
        $this->assertDatabaseHas('student_promotion_entries', ['student_promotion_id' => $run->id, 'student_id' => $this->st['d']->id, 'from_class_name' => 'Final', 'to_class_level_id' => null, 'to_class_name' => null, 'action' => 'graduated']);
        $this->assertSame(3, $run->entries()->count());

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/promotion')->assertOk()
            ->assertSee('Recent promotions')->assertSee('2 promoted, 1 graduated, 1 excluded');
    }

    public function test_the_same_transition_cannot_be_applied_twice(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $this->selection([$this->st['a']]))->assertRedirect('/admin/alpha/students');
        $this->assertSame($this->l['Middle']->id, $this->st['a']->fresh()->class_level_id);

        // A double click replays the exact same request: rejected, nothing moves.
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $this->selection([$this->st['a']]))
            ->assertRedirect('/admin/alpha/students/promotion?to_year=2026%2F2027')
            ->assertSessionHas('error');
        $this->assertSame($this->l['Middle']->id, $this->st['a']->fresh()->class_level_id);

        // Even with the "reviewed in" class updated to the new one: still once per year.
        $fresh = $this->st['a']->fresh();
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $this->selection([$fresh]))->assertSessionHas('error');
        $this->assertSame($this->l['Middle']->id, $fresh->fresh()->class_level_id);
        $this->assertDatabaseCount('student_promotions', 1);

        // The preview no longer offers the student for this year, but b is still there.
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/promotion?to_year=2026/2027')->assertOk()
            ->assertDontSee('Ada Lower')->assertSee('Bola Lower')->assertSee('1 student already promoted into 2026/2027 or a later year');

        // The database itself refuses a second entry for the same student and session.
        $this->expectException(\Illuminate\Database\QueryException::class);
        StudentPromotionEntry::create(['student_promotion_id' => StudentPromotion::sole()->id, 'school_id' => $this->alpha->id, 'student_id' => $this->st['a']->id, 'to_academic_session_id' => $this->s2026->id, 'action' => 'promoted']);
    }

    public function test_stale_or_crafted_selections_are_rejected_wholesale(): void
    {
        $betaLevel = ClassLevel::create(['school_id' => $this->beta->id, 'name' => 'B1', 'position' => 1]);
        $this->makeSessionWithTerms($this->beta, '2026/2027');
        $betaStudent = $this->makeStudent($this->beta, 'B/1', 'Beta Kid', 'B1', ['class_level_id' => $betaLevel->id]);

        // Another school's student in the list: the whole batch is refused.
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/students/promotion', $this->selection([$this->st['a'], $betaStudent]))
            ->assertSessionHas('error');
        $this->assertSame($this->l['Lower']->id, $this->st['a']->fresh()->class_level_id);
        $this->assertSame($betaLevel->id, $betaStudent->fresh()->class_level_id);

        // A year the school may not promote into now: too far ahead, in the past, or malformed.
        foreach (['2027/2028', '2024/2025', '2026', ''] as $year) {
            $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/students/promotion')
                ->post('/admin/alpha/students/promotion', $this->selection([$this->st['a']], ['to_year' => $year]))
                ->assertSessionHasErrors('to_year');
        }
        $this->assertFalse(AcademicSession::where('school_id', $this->alpha->id)->where('name', '2027/2028')->exists());

        // Stale "from" class (roster changed since review).
        $payload = $this->selection([$this->st['a']]);
        $payload['from'][$this->st['a']->id] = $this->l['Upper']->id;
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $payload)->assertSessionHas('error');

        // Inactive student, legacy (unmapped) student, empty selection.
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $this->selection([$this->st['left']]))->assertSessionHas('error');
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $this->selection([$this->st['legacy']]))->assertSessionHas('error');
        $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/students/promotion')
            ->post('/admin/alpha/students/promotion', ['to_year' => '2026/2027'])->assertSessionHasErrors('students');

        $this->assertDatabaseCount('student_promotions', 0);
        $this->assertDatabaseCount('student_promotion_entries', 0);

        // Beta's admin cannot promote through alpha's URL, and guests are sent to login.
        $this->actingAsSchoolAdmin($this->beta)->post('/admin/alpha/students/promotion', $this->selection([$this->st['a']]))->assertNotFound();
        $this->actingAsSchoolAdmin($this->beta)->get('/admin/alpha/students/promotion')->assertNotFound();
        $this->flushSession();
        $this->post('/admin/alpha/students/promotion', $this->selection([$this->st['a']]))->assertRedirect('/admin/login');
        $this->assertSame($this->l['Lower']->id, $this->st['a']->fresh()->class_level_id);
    }

    public function test_a_failure_midway_leaves_nothing_promoted(): void
    {
        $service = app(StudentPromotionService::class);
        $rows = $service->resolveSelection($this->alpha, $this->s2026, [
            $this->st['a']->id => $this->l['Lower']->id,
            $this->st['c']->id => $this->l['Middle']->id,
        ]);

        // Simulate a concurrent change to the second student between review and apply.
        $this->st['c']->forceFill(['class_level_id' => $this->l['Upper']->id, 'class_name' => 'Upper'])->save();

        try {
            $service->apply($this->alpha, $this->s2026, $rows);
            $this->fail('apply() should have thrown');
        } catch (DomainException $e) {
            $this->assertStringContainsString('Nothing was changed', $e->getMessage());
        }

        // The first student was updated inside the transaction, then rolled back.
        $this->assertDatabaseHas('students', ['id' => $this->st['a']->id, 'class_level_id' => $this->l['Lower']->id, 'class_name' => 'Lower']);
        $this->assertDatabaseCount('student_promotions', 0);
        $this->assertDatabaseCount('student_promotion_entries', 0);
    }

    public function test_deactivated_rung_is_skipped_and_each_school_has_its_own_ladder(): void
    {
        $this->l['Middle']->update(['is_active' => false]);

        // Beta uses different names and a different order: "Basic 2" before "Basic 1".
        $b2 = ClassLevel::create(['school_id' => $this->beta->id, 'name' => 'Basic 2', 'position' => 1]);
        $b1 = ClassLevel::create(['school_id' => $this->beta->id, 'name' => 'Basic 1', 'position' => 2]);
        $this->makeSessionWithTerms($this->beta, '2026/2027'); // beta is in its First Term: promotes into its current year
        $betaStudent = $this->makeStudent($this->beta, 'B/1', 'Beta Kid', 'Basic 2', ['class_level_id' => $b2->id]);

        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $this->selection([$this->st['a']]))->assertSessionHas('success');
        $this->assertDatabaseHas('students', ['id' => $this->st['a']->id, 'class_level_id' => $this->l['Upper']->id, 'class_name' => 'Upper']);

        $this->actingAsSchoolAdmin($this->beta)->post('/admin/beta/students/promotion', [
            'to_year' => '2026/2027', 'students' => [$betaStudent->id], 'from' => [$betaStudent->id => $b2->id],
        ])->assertSessionHas('success');
        $this->assertDatabaseHas('students', ['id' => $betaStudent->id, 'class_level_id' => $b1->id, 'class_name' => 'Basic 1']);
    }

    public function test_promotion_pages_are_read_only_except_the_two_posts(): void
    {
        foreach (['put', 'patch', 'delete'] as $method) {
            $this->actingAsSchoolAdmin($this->alpha)->{$method}('/admin/alpha/students/promotion')->assertStatus(405);
        }
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/promotion/review')->assertStatus(405);
    }

    private function setCurrentTerm(School $school, string $year, int $number): void
    {
        $periods = app(AcademicPeriodService::class);
        $periods->setCurrentTerm($school->refresh(), $periods->termFor($school, $year, $number));
        $school->refresh();
    }

    // ------------------------------------------------- academic year, not session

    public function test_only_the_current_year_or_in_the_last_term_the_next_one_can_be_promoted_into(): void
    {
        $service = app(StudentPromotionService::class);

        // Last term of 2025/2026: the current year (promote at the start of a year)
        // or the next one (promote at the end of a year), defaulting to the next.
        $this->assertSame(['2025/2026', '2026/2027'], $service->allowedTargetYears($this->alpha));
        $this->assertSame('2026/2027', $service->defaultTargetYear($this->alpha));

        // First and second term: only the current year.
        $this->setCurrentTerm($this->alpha, '2026/2027', 1);
        $this->assertSame(['2026/2027'], $service->allowedTargetYears($this->alpha));
        $this->setCurrentTerm($this->alpha, '2026/2027', 2);
        $this->assertSame(['2026/2027'], $service->allowedTargetYears($this->alpha));

        $page = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/promotion')->assertOk();
        $page->assertSee('<option value="2026/2027" selected>2026/2027 (current year)</option>', false)
            ->assertDontSee('2027/2028')
            ->assertDontSee('name="to_session_id"', false);
    }

    public function test_promoting_into_a_year_that_does_not_exist_yet_creates_it_on_confirm_only(): void
    {
        $this->setCurrentTerm($this->alpha, '2026/2027', 3);
        $this->assertFalse(AcademicSession::where('school_id', $this->alpha->id)->where('name', '2027/2028')->exists());

        // Preview and review are read-only: still no 2027/2028.
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/promotion')->assertOk()
            ->assertSee('<option value="2027/2028" selected>2027/2028</option>', false)->assertSee('Ada Lower');
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/students/promotion/review', $this->selection([$this->st['a']], ['to_year' => '2027/2028']))
            ->assertOk()->assertSee('2027/2028');
        $this->assertFalse(AcademicSession::where('school_id', $this->alpha->id)->where('name', '2027/2028')->exists());

        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/students/promotion', $this->selection([$this->st['a']], ['to_year' => '2027/2028']))
            ->assertSessionHas('success', 'Promotion into 2027/2028 applied: 1 student promoted.');

        $year = AcademicSession::where('school_id', $this->alpha->id)->where('name', '2027/2028')->sole();
        $this->assertSame([1, 2, 3], $year->terms()->pluck('number')->all());
        $run = StudentPromotion::sole();
        $this->assertSame($year->id, $run->to_academic_session_id);
        $this->assertSame($this->s2026->id, $run->from_academic_session_id);
        // The current term is the admin's choice and is not moved by a promotion.
        $this->assertSame('2026/2027', $this->alpha->fresh()->currentTerm->session->name);
    }

    public function test_a_year_promoted_into_early_cannot_be_promoted_again_when_it_starts(): void
    {
        // End of 2025/2026: promote into 2026/2027.
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $this->selection([$this->st['a'], $this->st['c']]))
            ->assertSessionHas('success');
        $this->assertSame($this->l['Middle']->id, $this->st['a']->fresh()->class_level_id);

        // The new year starts and the school moves its current term. The page now
        // offers only 2026/2027, which the students already have: nobody moves again.
        $this->setCurrentTerm($this->alpha, '2026/2027', 1);
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/promotion')->assertOk()
            ->assertDontSee('Ada Lower')->assertSee('Bola Lower')->assertSee('2 students already promoted into 2026/2027 or a later year');
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/students/promotion', $this->selection([$this->st['a']->fresh()]))
            ->assertSessionHas('error');
        $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/students/promotion')
            ->post('/admin/alpha/students/promotion', $this->selection([$this->st['a']->fresh()], ['to_year' => '2027/2028']))
            ->assertSessionHasErrors('to_year');

        $this->assertSame($this->l['Middle']->id, $this->st['a']->fresh()->class_level_id);
        $this->assertDatabaseCount('student_promotions', 1);
    }

    public function test_a_student_promoted_into_a_later_year_is_not_offered_for_an_earlier_one(): void
    {
        // Legacy data: Ada was promoted into 2027/2028 under the old session picker.
        $later = $this->makeSessionWithTerms($this->alpha, '2027/2028');
        $run = StudentPromotion::create(['school_id' => $this->alpha->id, 'to_academic_session_id' => $later->id, 'performed_by' => 'school_admin']);
        StudentPromotionEntry::create(['student_promotion_id' => $run->id, 'school_id' => $this->alpha->id, 'student_id' => $this->st['a']->id, 'to_academic_session_id' => $later->id, 'action' => 'promoted']);

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/promotion')->assertOk()
            ->assertDontSee('Ada Lower')->assertSee('Bola Lower');
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $this->selection([$this->st['a']]))
            ->assertSessionHas('error');
        $this->assertSame($this->l['Lower']->id, $this->st['a']->fresh()->class_level_id);
    }

    public function test_a_realistic_ladder_moves_every_class_up_and_graduates_the_final_class(): void
    {
        $school = $this->makeSchool('Gamma College', 'gamma');
        $this->makeSessionWithTerms($school, '2025/2026');
        $this->setCurrentTerm($school, '2025/2026', 3);

        $names = ['JSS1', 'JSS2', 'JSS3', 'SS1', 'SS2', 'SS3'];
        $levels = [];
        foreach ($names as $i => $name) {
            $levels[$name] = ClassLevel::create(['school_id' => $school->id, 'name' => $name, 'position' => $i + 1]);
        }
        $students = [];
        foreach ($names as $i => $name) {
            $students[$name] = $this->makeStudent($school, 'G/'.$i, 'Pupil '.$name, $name, ['class_level_id' => $levels[$name]->id]);
        }

        // A school-fees payment already made by the SS3 student stays exactly as it was.
        $paid = $this->makeSuccessfulTransaction($school, ['student_id' => $students['SS3']->id, 'reference' => 'gamma-ss3']);

        $payload = ['to_year' => '2026/2027', 'students' => [], 'from' => []];
        foreach ($students as $student) {
            $payload['students'][] = $student->id;
            $payload['from'][$student->id] = $student->class_level_id;
        }

        $this->actingAsSchoolAdmin($school)->get('/admin/gamma/students/promotion')->assertOk()
            ->assertSeeInOrder(['JSS1', 'JSS2'])->assertSeeInOrder(['JSS3', 'SS1'])->assertSeeInOrder(['SS3', 'Graduated']);

        $this->actingAsSchoolAdmin($school)->post('/admin/gamma/students/promotion', $payload)
            ->assertSessionHas('success', 'Promotion into 2026/2027 applied: 5 students promoted, 1 graduated.');

        foreach (['JSS1' => 'JSS2', 'JSS2' => 'JSS3', 'JSS3' => 'SS1', 'SS1' => 'SS2', 'SS2' => 'SS3'] as $from => $to) {
            $this->assertDatabaseHas('students', ['id' => $students[$from]->id, 'class_level_id' => $levels[$to]->id, 'class_name' => $to, 'status' => 'active']);
        }
        // The final class has nowhere to go: graduated, kept on record in SS3.
        $this->assertDatabaseHas('students', ['id' => $students['SS3']->id, 'class_level_id' => $levels['SS3']->id, 'status' => Student::STATUS_GRADUATED]);
        $this->assertDatabaseHas('transactions', ['id' => $paid->id, 'student_id' => $students['SS3']->id, 'status' => 'success', 'reference' => 'gamma-ss3']);
    }
}
