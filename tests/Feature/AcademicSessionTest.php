<?php

namespace Tests\Feature;

use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Category;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\Subcategory;
use App\Services\AcademicPeriodService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Academic years and terms without a Sessions page: a fee names its academic year
 * and term and the session/term rows behind them are found or created on first
 * use; the current term is chosen on the Fees page. The data model (one session
 * per year, three terms, current-term pointer) is unchanged.
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

    private function schoolFee(array $overrides = []): array
    {
        $level = $this->alpha->classLevels()->firstOrCreate(['name' => 'JSS1'], ['position' => 1]);

        return array_merge([
            'is_tuition' => '1', 'price' => 50000, 'academic_year' => '2026/2027', 'term' => 1,
            'class_level_ids' => [$level->id],
        ], $overrides);
    }

    public function test_creating_a_fee_creates_its_academic_year_with_three_terms_and_sets_the_current_term(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/subcategories', $this->schoolFee(['term' => 2]))
            ->assertRedirect('/admin/alpha/subcategories')
            ->assertSessionHasNoErrors();

        $session = AcademicSession::where('school_id', $this->alpha->id)->where('name', '2026/2027')->sole();
        $this->assertSame(['First Term', 'Second Term', 'Third Term'], $session->terms()->pluck('name')->all());
        $this->assertSame([1, 2, 3], $session->terms()->pluck('number')->all());
        $this->assertTrue($session->terms->every(fn ($t) => (int) $t->school_id === (int) $this->alpha->id));

        // The fee is tied to the term the admin chose…
        $second = $session->terms()->where('number', 2)->value('id');
        $this->assertSame($second, Subcategory::sole()->academic_term_id);
        // …and, as the school had none, that term became current.
        $this->assertSame($second, $this->alpha->fresh()->current_academic_term_id);
    }

    public function test_later_fees_reuse_the_year_and_never_move_the_current_term(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/subcategories', $this->schoolFee());
        $current = $this->alpha->fresh()->current_academic_term_id;

        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/subcategories', $this->schoolFee(['term' => 3]))->assertSessionHasNoErrors();
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/subcategories', $this->schoolFee(['academic_year' => '2027/2028']))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'currently opens on First Term, 2026/2027'));

        $this->assertSame(['2026/2027', '2027/2028'], AcademicSession::where('school_id', $this->alpha->id)->orderBy('name')->pluck('name')->all());
        $this->assertSame(6, AcademicTerm::where('school_id', $this->alpha->id)->count());
        $this->assertSame($current, $this->alpha->fresh()->current_academic_term_id);
    }

    #[DataProvider('badAcademicYears')]
    public function test_academic_year_must_be_two_consecutive_years(string $year): void
    {
        $this->actingAsSchoolAdmin($this->alpha)
            ->from('/admin/alpha/subcategories/create')
            ->post('/admin/alpha/subcategories', $this->schoolFee(['academic_year' => $year]))
            ->assertSessionHasErrors('academic_year');

        $this->assertDatabaseCount('academic_sessions', 0);
        $this->assertDatabaseCount('subcategories', 0);
    }

    public static function badAcademicYears(): array
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

    public function test_term_must_be_one_of_the_three(): void
    {
        foreach ([0, 4, 'Fourth'] as $term) {
            $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/subcategories/create')
                ->post('/admin/alpha/subcategories', $this->schoolFee(['term' => $term]))
                ->assertSessionHasErrors('term');
        }
        $this->assertDatabaseCount('academic_sessions', 0);
    }

    public function test_a_year_is_unique_per_school_but_not_globally(): void
    {
        $periods = app(AcademicPeriodService::class);
        $beta = $periods->termFor($this->beta, '2026/2027', 1);
        $alpha = $periods->termFor($this->alpha, '2026/2027', 1);

        $this->assertNotSame($beta->academic_session_id, $alpha->academic_session_id);
        $this->assertSame($alpha->id, $periods->termFor($this->alpha, ' 2026/2027 ', 1)->id);
        $this->assertSame(1, AcademicSession::where('school_id', $this->alpha->id)->count());
    }

    public function test_a_failed_fee_save_leaves_no_new_year_behind(): void
    {
        // A main fee needs a class: the save fails after the year was resolved, and
        // the year is rolled back with it.
        $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/subcategories/create')
            ->post('/admin/alpha/subcategories', $this->schoolFee(['class_level_ids' => []]))
            ->assertSessionHasErrors('class_level_ids');

        $this->assertDatabaseCount('academic_sessions', 0);
        $this->assertNull($this->alpha->fresh()->current_academic_term_id);
    }

    public function test_the_sessions_page_is_gone_and_redirects_to_fees(): void
    {
        $this->makeSessionWithTerms($this->alpha, '2024/2025');

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/sessions')->assertRedirect('/admin/alpha/subcategories');
        $this->actingAsSchoolAdmin($this->alpha)->post('/admin/alpha/sessions', ['name' => '2030/2031'])->assertStatus(405);
        $this->assertFalse(AcademicSession::where('name', '2030/2031')->exists());

        $this->flushSession();
        $this->get('/admin/alpha/sessions')->assertRedirect('/admin/login');
    }

    public function test_admin_sets_the_current_term_from_the_fees_page(): void
    {
        $this->makeSessionWithTerms($this->alpha, '2026/2027');

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/subcategories')->assertOk()
            ->assertSee('Current term')->assertSee('First Term, 2026/2027')
            ->assertSee('action="http://localhost/admin/alpha/current-term"', false);

        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/current-term', ['current_academic_year' => '2026/2027', 'current_term' => 2])
            ->assertRedirect('/admin/alpha/subcategories')
            ->assertSessionHas('success', 'Second Term, 2026/2027 is now the current term. The payment page opens on it.');

        $second = AcademicTerm::where('school_id', $this->alpha->id)->where('number', 2)->value('id');
        $this->assertSame($second, $this->alpha->fresh()->current_academic_term_id);
        $event = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_TERM_CHANGED)->sole();
        $this->assertSame($second, $event->changes['current_academic_term_id']['to']);

        // A year that does not exist yet is created on the way.
        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/alpha/current-term', ['current_academic_year' => '2027/2028', 'current_term' => 1])
            ->assertSessionHasNoErrors();
        $this->assertSame('First Term, 2027/2028', $this->alpha->fresh()->currentTerm->label);

        // Invalid input changes nothing.
        $before = $this->alpha->fresh()->current_academic_term_id;
        $this->actingAsSchoolAdmin($this->alpha)->from('/admin/alpha/subcategories')
            ->put('/admin/alpha/current-term', ['current_academic_year' => '2027/2029', 'current_term' => 9])
            ->assertSessionHasErrors(['current_academic_year', 'current_term']);
        $this->assertSame($before, $this->alpha->fresh()->current_academic_term_id);
    }

    public function test_admin_can_set_the_current_term_by_id(): void
    {
        $session = $this->makeSessionWithTerms($this->alpha);
        $second = $session->terms()->where('number', 2)->firstOrFail();

        $this->actingAsSchoolAdmin($this->alpha)
            ->post("/admin/alpha/terms/{$second->id}/current")
            ->assertRedirect('/admin/alpha/subcategories');

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
        $this->actingAsSchoolAdmin($this->alpha)
            ->put('/admin/beta/current-term', ['current_academic_year' => '2026/2027', 'current_term' => 2])
            ->assertNotFound();

        $this->assertSame($before, $this->alpha->fresh()->current_academic_term_id);
        $this->assertNotSame($betaTerm->id, $this->alpha->fresh()->current_academic_term_id);
        $this->assertNotSame($betaTerm->id, $this->beta->fresh()->current_academic_term_id);
    }

    public function test_a_fee_can_be_tied_to_own_term_but_not_to_another_schools_term(): void
    {
        $alphaTerm = $this->makeSessionWithTerms($this->alpha)->terms()->first();
        $betaTerm = $this->makeSessionWithTerms($this->beta)->terms()->first();
        $category = Category::create(['school_id' => $this->alpha->id, 'name' => 'Levies']);

        $level = ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'JSS1', 'position' => 1]);

        // Legacy forms posted a term id; it is still honoured for school fees, and still owner-checked.
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/subcategories', [
                'is_tuition' => '1', 'name' => 'Development levy', 'price' => 50000,
                'academic_term_id' => $alphaTerm->id, 'class_level_ids' => [$level->id],
            ])
            ->assertRedirect('/admin/alpha/subcategories');
        $this->assertDatabaseHas('subcategories', ['name' => 'Development levy', 'academic_term_id' => $alphaTerm->id, 'school_id' => $this->alpha->id]);

        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/subcategories', [
                'is_tuition' => '1', 'name' => 'Smuggled', 'price' => 1,
                'academic_term_id' => $betaTerm->id, 'class_level_ids' => [$level->id],
            ])
            ->assertNotFound();
        $this->assertDatabaseMissing('subcategories', ['name' => 'Smuggled']);

        // An additional fee is never tied to a term: a posted term id, even another school's, is not saved.
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/subcategories', [
                'is_tuition' => '0', 'category_id' => $category->id, 'name' => 'Sports levy', 'price' => 1000,
                'academic_term_id' => $betaTerm->id,
            ])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subcategories', ['name' => 'Sports levy', 'academic_term_id' => null]);

        // An additional fee may be payable in any term.
        $this->actingAsSchoolAdmin($this->alpha)
            ->post('/admin/alpha/subcategories', ['is_tuition' => '0', 'category_id' => $category->id, 'name' => 'Uniform', 'price' => 3000, 'academic_year' => '2026/2027', 'term' => ''])
            ->assertSessionHasNoErrors();
        $this->assertDatabaseHas('subcategories', ['name' => 'Uniform', 'academic_term_id' => null]);
    }

    public function test_the_current_year_follows_the_current_term_or_else_the_calendar(): void
    {
        $periods = app(AcademicPeriodService::class);

        // No term yet: a Nigerian school year starts in September.
        $this->assertSame('2026/2027', $periods->currentYear($this->alpha, Carbon::create(2026, 9, 1)));
        $this->assertSame('2025/2026', $periods->currentYear($this->alpha, Carbon::create(2026, 8, 31)));
        $this->assertSame('2027/2028', AcademicPeriodService::nextYear('2026/2027'));

        $this->makeSessionWithTerms($this->alpha, '2030/2031');
        $this->assertSame('2030/2031', $periods->currentYear($this->alpha->fresh()));
        $this->assertSame(['2031/2032', '2030/2031', '2029/2030'], $periods->yearOptions($this->alpha->fresh()));
    }

    public function test_term_labels_are_not_hard_coded_in_the_controller(): void
    {
        $this->assertSame(['First Term', 'Second Term', 'Third Term'], array_values(AcademicTerm::NAMES));
    }
}
