<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;
use App\Models\StudentPromotion;
use App\Models\StudentPromotionEntry;
use App\Services\StudentPromotionService;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Bulk promotion: follows the school's own ladder, applies only to the selected
 * students, in one transaction, once per session, and only within the school.
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

        // Alpha's current session is 2025/2026 (first created → its First Term is current).
        $this->s2025 = $this->makeSessionWithTerms($this->alpha, '2025/2026');
        $this->s2026 = $this->makeSessionWithTerms($this->alpha, '2026/2027');
        $this->alpha->refresh();

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
        $payload = ['to_session_id' => $this->s2026->id, 'students' => [], 'from' => []];
        foreach ($students as $s) {
            $payload['students'][] = $s->id;
            $payload['from'][$s->id] = $s->class_level_id ?? 0;
        }

        return array_merge($payload, $extra);
    }

    public function test_preview_follows_the_ladder_not_the_names(): void
    {
        $page = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/promotion')->assertOk();

        // Defaults to the session after the current one.
        $page->assertSee('2025/2026')->assertSee('2026/2027')
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
            ->assertRedirect('/admin/alpha/students/promotion?to_session_id='.$this->s2026->id)
            ->assertSessionHas('error');
        $this->assertSame($this->l['Middle']->id, $this->st['a']->fresh()->class_level_id);

        // Even with the "reviewed in" class updated to the new one: still once per session.
        $fresh = $this->st['a']->fresh();
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $this->selection([$fresh]))->assertSessionHas('error');
        $this->assertSame($this->l['Middle']->id, $fresh->fresh()->class_level_id);
        $this->assertDatabaseCount('student_promotions', 1);

        // The preview no longer offers the student for this session, but b is still there.
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/promotion?to_session_id='.$this->s2026->id)->assertOk()
            ->assertDontSee('Ada Lower')->assertSee('Bola Lower')->assertSee('1 student already promoted into 2026/2027');

        // The database itself refuses a second entry for the same student and session.
        $this->expectException(\Illuminate\Database\QueryException::class);
        StudentPromotionEntry::create(['student_promotion_id' => StudentPromotion::sole()->id, 'school_id' => $this->alpha->id, 'student_id' => $this->st['a']->id, 'to_academic_session_id' => $this->s2026->id, 'action' => 'promoted']);
    }

    public function test_stale_or_crafted_selections_are_rejected_wholesale(): void
    {
        $betaLevel = ClassLevel::create(['school_id' => $this->beta->id, 'name' => 'B1', 'position' => 1]);
        $betaSession = $this->makeSessionWithTerms($this->beta, '2026/2027');
        $betaStudent = $this->makeStudent($this->beta, 'B/1', 'Beta Kid', 'B1', ['class_level_id' => $betaLevel->id]);

        // Another school's student in the list: the whole batch is refused.
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/students/promotion', $this->selection([$this->st['a'], $betaStudent]))
            ->assertSessionHas('error');
        $this->assertSame($this->l['Lower']->id, $this->st['a']->fresh()->class_level_id);
        $this->assertSame($betaLevel->id, $betaStudent->fresh()->class_level_id);

        // Another school's session.
        $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/students/promotion')
            ->post('/admin/alpha/students/promotion', $this->selection([$this->st['a']], ['to_session_id' => $betaSession->id]))
            ->assertSessionHasErrors('to_session_id');

        // Stale "from" class (roster changed since review).
        $payload = $this->selection([$this->st['a']]);
        $payload['from'][$this->st['a']->id] = $this->l['Upper']->id;
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $payload)->assertSessionHas('error');

        // Inactive student, legacy (unmapped) student, empty selection.
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $this->selection([$this->st['left']]))->assertSessionHas('error');
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $this->selection([$this->st['legacy']]))->assertSessionHas('error');
        $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/students/promotion')
            ->post('/admin/alpha/students/promotion', ['to_session_id' => $this->s2026->id])->assertSessionHasErrors('students');

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
        $betaSession = $this->makeSessionWithTerms($this->beta, '2026/2027');
        $betaStudent = $this->makeStudent($this->beta, 'B/1', 'Beta Kid', 'Basic 2', ['class_level_id' => $b2->id]);

        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/promotion', $this->selection([$this->st['a']]))->assertSessionHas('success');
        $this->assertDatabaseHas('students', ['id' => $this->st['a']->id, 'class_level_id' => $this->l['Upper']->id, 'class_name' => 'Upper']);

        $this->actingAsSchoolAdmin($this->beta)->post('/admin/beta/students/promotion', [
            'to_session_id' => $betaSession->id, 'students' => [$betaStudent->id], 'from' => [$betaStudent->id => $b2->id],
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
}
