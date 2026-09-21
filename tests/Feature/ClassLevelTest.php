<?php

namespace Tests\Feature;

use App\Models\ClassLevel;
use App\Models\School;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * The school-defined class ladder: create, rename, reorder, (de)activate, map
 * legacy free-text classes — every action tenant-scoped.
 */
class ClassLevelTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');
    }

    private function ladder(School $school, array $names): array
    {
        $levels = [];
        foreach ($names as $i => $name) {
            $levels[$name] = ClassLevel::create(['school_id' => $school->id, 'name' => $name, 'position' => $i + 1, 'is_active' => true]);
        }

        return $levels;
    }

    private function order(School $school): array
    {
        return $school->classLevels()->pluck('name')->all();
    }

    public function test_classes_are_created_in_order_and_listed_with_next_class(): void
    {
        foreach (['Basic 1', 'Basic 2', 'Basic 3'] as $name) {
            $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/classes', ['name' => $name])
                ->assertRedirect('/admin/alpha/students/classes');
        }

        $this->assertSame(['Basic 1', 'Basic 2', 'Basic 3'], $this->order($this->alpha));
        $this->assertSame([1, 2, 3], $this->alpha->classLevels()->pluck('position')->all());

        $page = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/classes')->assertOk();
        $page->assertSeeInOrder(['Basic 1', 'next: Basic 2', 'Basic 2', 'next: Basic 3', 'Basic 3', 'next: Graduated']);
    }

    public function test_duplicate_and_blank_names_are_rejected_per_school(): void
    {
        $this->ladder($this->alpha, ['JSS 1']);
        $this->ladder($this->beta, ['SS 1']);

        $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/students/classes')
            ->post('/admin/alpha/students/classes', ['name' => 'JSS 1'])->assertSessionHasErrors('name');
        $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/students/classes')
            ->post('/admin/alpha/students/classes', ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/students/classes')
            ->post('/admin/alpha/students/classes', ['name' => str_repeat('x', 101)])->assertSessionHasErrors('name');

        // Beta's name is free for alpha.
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/classes', ['name' => 'SS 1'])->assertSessionHasNoErrors();
        $this->assertSame(1, ClassLevel::where('school_id', $this->alpha->id)->where('name', 'SS 1')->count());
    }

    public function test_move_up_and_down_renumbers_the_ladder(): void
    {
        $l = $this->ladder($this->alpha, ['A', 'B', 'C', 'D']);

        $this->actingAsSchoolAdmin($this->alpha)->post("/admin/alpha/students/classes/{$l['C']->id}/move", ['direction' => 'up'])->assertRedirect();
        $this->assertSame(['A', 'C', 'B', 'D'], $this->order($this->alpha));

        $this->actingAsSchoolAdmin($this->alpha)->post("/admin/alpha/students/classes/{$l['A']->id}/move", ['direction' => 'down'])->assertRedirect();
        $this->assertSame(['C', 'A', 'B', 'D'], $this->order($this->alpha));

        // Edges are no-ops, and positions are always 1..n.
        $this->actingAsSchoolAdmin($this->alpha)->post("/admin/alpha/students/classes/{$l['C']->id}/move", ['direction' => 'up'])->assertRedirect();
        $this->actingAsSchoolAdmin($this->alpha)->post("/admin/alpha/students/classes/{$l['D']->id}/move", ['direction' => 'down'])->assertRedirect();
        $this->assertSame(['C', 'A', 'B', 'D'], $this->order($this->alpha));
        $this->assertSame([1, 2, 3, 4], $this->alpha->classLevels()->pluck('position')->all());

        $this->actingAsSchoolAdmin($this->alpha)->post("/admin/alpha/students/classes/{$l['A']->id}/move", ['direction' => 'sideways'])->assertSessionHasErrors('direction');
    }

    public function test_rename_updates_students_display_class_and_deactivation_skips_the_rung(): void
    {
        $l = $this->ladder($this->alpha, ['JSS 1', 'JSS 2', 'JSS 3']);
        $student = $this->makeStudent($this->alpha, 'A/1', 'Ada', 'JSS 2', ['class_level_id' => $l['JSS 2']->id]);

        $this->actingAsSchoolAdmin($this->alpha)
            ->put("/admin/alpha/students/classes/{$l['JSS 2']->id}", ['name' => 'Junior 2', 'is_active' => '1'])
            ->assertRedirect('/admin/alpha/students/classes');

        $this->assertDatabaseHas('students', ['id' => $student->id, 'class_level_id' => $l['JSS 2']->id, 'class_name' => 'Junior 2']);

        // Deactivate JSS 2: JSS 1 now leads straight to JSS 3.
        $this->actingAsSchoolAdmin($this->alpha)->put("/admin/alpha/students/classes/{$l['JSS 2']->id}", ['name' => 'Junior 2']);
        $ladder = $this->alpha->classLevels()->get();
        $this->assertSame('JSS 3', $ladder->firstWhere('name', 'JSS 1')->nextIn($ladder)->name);
        $this->assertNull($ladder->firstWhere('name', 'JSS 3')->nextIn($ladder));

        // A student's existing (now inactive) level still appears in their edit form; not in create.
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$student->id}/edit")->assertOk()->assertSee('Junior 2 (inactive)');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/create')->assertOk()->assertDontSee('Junior 2');
    }

    public function test_delete_only_when_unused(): void
    {
        $l = $this->ladder($this->alpha, ['A', 'B', 'C']);
        $this->makeStudent($this->alpha, 'A/1', 'Ada', 'B', ['class_level_id' => $l['B']->id]);

        $this->actingAsSchoolAdmin($this->alpha)->delete("/admin/alpha/students/classes/{$l['B']->id}")->assertRedirect()->assertSessionHas('error');
        $this->assertDatabaseHas('class_levels', ['id' => $l['B']->id]);

        $this->actingAsSchoolAdmin($this->alpha)->delete("/admin/alpha/students/classes/{$l['C']->id}")->assertRedirect()->assertSessionHas('success');
        $this->assertDatabaseMissing('class_levels', ['id' => $l['C']->id]);
        $this->assertSame([1, 2], $this->alpha->classLevels()->pluck('position')->all());
    }

    public function test_legacy_free_text_classes_are_mapped_only_by_explicit_action(): void
    {
        $l = $this->ladder($this->alpha, ['JSS 1', 'JSS 2']);
        $a = $this->makeStudent($this->alpha, 'A/1', 'Ada', 'JSS 1');       // free text, exactly "JSS 1"
        $b = $this->makeStudent($this->alpha, 'A/2', 'Bola', 'Jss 1');      // different string: not touched
        $c = $this->makeStudent($this->alpha, 'A/3', 'Chi', 'Grade 7');     // custom name
        $beta = $this->makeStudent($this->beta, 'B/1', 'Beta', 'JSS 1');    // other school, same string

        // Nothing is mapped by itself.
        $this->assertNull($a->fresh()->class_level_id);
        $page = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/classes')->assertOk();
        $page->assertSee('Students with an unassigned class')->assertSee('“JSS 1”')->assertSee('“Jss 1”')->assertSee('“Grade 7”');

        // Map "JSS 1" to the existing level: exact string, this school only.
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/classes/assign', ['class_name' => 'JSS 1', 'class_level_id' => $l['JSS 1']->id])
            ->assertRedirect()->assertSessionHas('success');
        $this->assertSame($l['JSS 1']->id, $a->fresh()->class_level_id);
        $this->assertNull($b->fresh()->class_level_id);
        $this->assertNull($c->fresh()->class_level_id);
        $this->assertNull($beta->fresh()->class_level_id);

        // Create a new level from a custom name.
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students/classes/assign', ['class_name' => 'Grade 7', 'class_level_id' => 'new'])->assertSessionHas('success');
        $new = ClassLevel::where('school_id', $this->alpha->id)->where('name', 'Grade 7')->first();
        $this->assertNotNull($new);
        $this->assertSame(3, $new->position);
        $this->assertSame($new->id, $c->fresh()->class_level_id);

        // Cannot map to another school's level.
        $betaLevel = ClassLevel::create(['school_id' => $this->beta->id, 'name' => 'X', 'position' => 1]);
        $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/students/classes')
            ->post('/admin/alpha/students/classes/assign', ['class_name' => 'Jss 1', 'class_level_id' => $betaLevel->id])
            ->assertSessionHasErrors('class_level_id');
        $this->assertNull($b->fresh()->class_level_id);
    }

    public function test_class_levels_are_tenant_isolated(): void
    {
        $beta = ClassLevel::create(['school_id' => $this->beta->id, 'name' => 'Beta Class', 'position' => 1]);
        $this->ladder($this->alpha, ['Alpha Class']);

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/classes')->assertOk()->assertSee('Alpha Class')->assertDontSee('Beta Class');
        $this->actingAsSchoolAdmin($this->alpha)->put("/admin/alpha/students/classes/{$beta->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->post("/admin/alpha/students/classes/{$beta->id}/move", ['direction' => 'up'])->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->delete("/admin/alpha/students/classes/{$beta->id}")->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/beta/students/classes')->assertNotFound();
        $this->assertDatabaseHas('class_levels', ['id' => $beta->id, 'name' => 'Beta Class']);

        $this->flushSession();
        $this->get('/admin/alpha/students/classes')->assertRedirect('/admin/login');
        $this->post('/admin/alpha/students/classes', ['name' => 'X'])->assertRedirect('/admin/login');
    }

    public function test_students_use_the_ladder_once_it_exists(): void
    {
        // Without a ladder the legacy free-text class still works.
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/students', ['full_name' => 'Free Text', 'admission_number' => 'A/0', 'class_name' => 'Any Class', 'status' => 'active'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('students', ['admission_number' => 'A/0', 'class_name' => 'Any Class', 'class_level_id' => null]);

        $l = $this->ladder($this->alpha, ['JSS 1', 'JSS 2']);
        $betaLevel = ClassLevel::create(['school_id' => $this->beta->id, 'name' => 'Beta 1', 'position' => 1]);

        // Now a level is required and the display name comes from it, not the request.
        $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/students/create')
            ->post('/admin/alpha/students', ['full_name' => 'No Level', 'admission_number' => 'A/1', 'class_name' => 'JSS 1'])
            ->assertSessionHasErrors('class_level_id');

        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/students', ['full_name' => 'Ada', 'admission_number' => 'A/1', 'class_level_id' => $l['JSS 1']->id, 'class_name' => 'Spoofed'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('students', ['admission_number' => 'A/1', 'class_level_id' => $l['JSS 1']->id, 'class_name' => 'JSS 1', 'status' => 'active']);

        // Another school's level is rejected.
        $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/students/create')
            ->post('/admin/alpha/students', ['full_name' => 'Cross', 'admission_number' => 'A/2', 'class_level_id' => $betaLevel->id])
            ->assertSessionHasErrors('class_level_id');
        $this->assertDatabaseMissing('students', ['admission_number' => 'A/2']);

        // Editing moves the student and rewrites the display class.
        $ada = Student::where('admission_number', 'A/1')->first();
        $this->actingAsSchoolAdmin($this->alpha)
            ->put("/admin/alpha/students/{$ada->id}", ['full_name' => 'Ada', 'admission_number' => 'A/1', 'class_level_id' => $l['JSS 2']->id, 'status' => 'active'])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('students', ['id' => $ada->id, 'class_level_id' => $l['JSS 2']->id, 'class_name' => 'JSS 2']);

        // Roster filter by level id, and by a legacy name for the unmapped student.
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students?class='.$l['JSS 2']->id)->assertOk()->assertSee('Ada')->assertDontSee('Free Text');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students?class=Any+Class')->assertOk()->assertSee('Free Text')->assertDontSee('Ada');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students?class='.$betaLevel->id)->assertOk()->assertDontSee('Ada')->assertDontSee('Free Text');

        // Status filter: graduated students are hidden by default and shown on request.
        $ada->forceFill(['status' => 'graduated'])->save();
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students')->assertOk()->assertDontSee('Ada')->assertSee('Free Text');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students?status=graduated')->assertOk()->assertSee('Ada')->assertDontSee('Free Text');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students?status=all')->assertOk()->assertSee('Ada')->assertSee('Free Text');
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$ada->id}")->assertOk()->assertSee('Graduated')->assertSee('JSS 2');
    }
}
