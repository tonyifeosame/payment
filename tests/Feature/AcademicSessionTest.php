<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Phase 1 — academic sessions and terms: creation, validation, the current-term
 * pointer, and that a fee can only be tied to the school's own term.
 */
class AcademicSessionTest extends TestCase
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

    public function test_creating_a_session_creates_its_three_terms_and_sets_the_current_term(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/sessions', ['name' => '2026/2027'])
            ->assertRedirect('/admin/alpha/sessions');

        $session = AcademicSession::where('school_id', $this->alpha->id)->where('name', '2026/2027')->firstOrFail();

        $this->assertSame(
            ['First Term', 'Second Term', 'Third Term'],
            $session->terms()->pluck('name')->all()
        );
        $this->assertSame([1, 2, 3], $session->terms()->pluck('number')->all());
        $this->assertTrue($session->terms->every(fn ($t) => (int) $t->school_id === (int) $this->alpha->id));

        // The first session's First Term becomes current automatically.
        $this->assertSame($session->terms()->where('number', 1)->value('id'), $this->alpha->fresh()->current_academic_term_id);
    }

    public function test_a_second_session_does_not_steal_the_current_term(): void
    {
        $first = $this->makeSessionWithTerms($this->alpha, '2025/2026');
        $current = $this->alpha->fresh()->current_academic_term_id;

        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/sessions', ['name' => '2026/2027']);

        $this->assertSame($current, $this->alpha->fresh()->current_academic_term_id);
        $this->assertSame($first->terms()->where('number', 1)->value('id'), $current);
    }

    #[DataProvider('badSessionNames')]
    public function test_session_name_must_be_two_consecutive_years(string $name): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/sessions')
            ->post('/admin/alpha/sessions', ['name' => $name])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('academic_sessions', 0);
    }

    public static function badSessionNames(): array
    {
        return [
            'not years' => ['First Term'],
            'same year' => ['2026/2026'],
            'gap' => ['2026/2028'],
            'backwards' => ['2027/2026'],
            'single year' => ['2026'],
            'empty' => [''],
        ];
    }

    public function test_session_name_is_unique_per_school_but_not_globally(): void
    {
        $this->makeSessionWithTerms($this->beta, '2026/2027');

        // Alpha may also have 2026/2027…
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/sessions', ['name' => '2026/2027'])
            ->assertSessionHasNoErrors();

        // …but not twice.
        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/sessions')
            ->post('/admin/alpha/sessions', ['name' => '2026/2027'])
            ->assertSessionHasErrors('name');

        $this->assertSame(1, AcademicSession::where('school_id', $this->alpha->id)->count());
    }

    public function test_end_date_cannot_precede_start_date(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/sessions')
            ->post('/admin/alpha/sessions', ['name' => '2026/2027', 'starts_on' => '2026-09-14', 'ends_on' => '2026-09-01'])
            ->assertSessionHasErrors('ends_on');
    }

    public function test_admin_can_set_the_current_term(): void
    {
        $session = $this->makeSessionWithTerms($this->alpha);
        $second = $session->terms()->where('number', 2)->firstOrFail();

        $this->actingAsSchoolAdmin($this->alpha)
            ->post("/admin/alpha/terms/{$second->id}/current")
            ->assertRedirect('/admin/alpha/sessions');

        $this->assertSame($second->id, $this->alpha->fresh()->current_academic_term_id);
    }

    public function test_school_a_cannot_select_school_b_term_as_current(): void
    {
        $this->makeSessionWithTerms($this->alpha);
        $betaTerm = $this->makeSessionWithTerms($this->beta)->terms()->where('number', 2)->firstOrFail();
        $before = $this->alpha->fresh()->current_academic_term_id;

        $this->actingAsSchoolAdmin($this->alpha)
            ->post("/admin/alpha/terms/{$betaTerm->id}/current")
            ->assertNotFound();

        $this->actingAsSchoolAdmin($this->alpha)
            ->post("/admin/beta/terms/{$betaTerm->id}/current")
            ->assertNotFound();

        $this->assertSame($before, $this->alpha->fresh()->current_academic_term_id);
        $this->assertNotSame($betaTerm->id, $this->alpha->fresh()->current_academic_term_id);
    }

    public function test_sessions_page_lists_only_own_sessions(): void
    {
        $this->makeSessionWithTerms($this->alpha, '2024/2025');
        $this->makeSessionWithTerms($this->beta, '2031/2032');

        $this->actingAsSchoolAdmin($this->alpha)
            ->get('/admin/alpha/sessions')
            ->assertOk()
            ->assertSee('2024/2025')
            ->assertDontSee('2031/2032');

        $this->flushSession();
        $this->get('/admin/alpha/sessions')->assertRedirect('/admin/login');
    }

    public function test_a_fee_can_be_tied_to_own_term_but_not_to_another_schools_term(): void
    {
        $alphaTerm = $this->makeSessionWithTerms($this->alpha)->terms()->first();
        $betaTerm = $this->makeSessionWithTerms($this->beta)->terms()->first();
        $category = \App\Models\Category::create(['school_id' => $this->alpha->id, 'name' => 'School Fees']);

        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/subcategories', [
                'category_id' => $category->id, 'name' => 'Tuition', 'price' => 50000,
                'academic_term_id' => $alphaTerm->id,
            ])
            ->assertRedirect('/admin/alpha/subcategories');
        $this->assertDatabaseHas('subcategories', ['name' => 'Tuition', 'academic_term_id' => $alphaTerm->id, 'school_id' => $this->alpha->id]);

        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/subcategories', [
                'category_id' => $category->id, 'name' => 'Smuggled', 'price' => 1,
                'academic_term_id' => $betaTerm->id,
            ])
            ->assertNotFound();
        $this->assertDatabaseMissing('subcategories', ['name' => 'Smuggled']);

        // Term is optional: a general fee has none.
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/subcategories', ['category_id' => $category->id, 'name' => 'Uniform', 'price' => 3000])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subcategories', ['name' => 'Uniform', 'academic_term_id' => null]);
    }

    public function test_term_labels_are_not_hard_coded_in_the_controller(): void
    {
        $this->assertSame(['First Term', 'Second Term', 'Third Term'], array_values(AcademicTerm::NAMES));
    }
}
