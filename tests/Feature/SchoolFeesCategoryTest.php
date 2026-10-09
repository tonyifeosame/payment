<?php

namespace Tests\Feature;

use App\Http\Controllers\SubcategoryController;
use App\Models\AcademicSession;
use App\Models\AcademicTerm;
use App\Models\Category;
use App\Models\ClassLevel;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\Student;
use App\Models\Subcategory;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * "School Fees" is a built-in category, and the fee form is: type of fee (School
 * fees or additional) → academic year → term → amount → classes. The main-fee
 * rules and paid-once behaviour are unchanged; this covers the new inputs.
 */
class SchoolFeesCategoryTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    private ClassLevel $jss1;

    private ClassLevel $jss2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');
        $this->jss1 = ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'JSS1', 'position' => 1]);
        $this->jss2 = ClassLevel::create(['school_id' => $this->alpha->id, 'name' => 'JSS2', 'position' => 2]);
    }

    private function admin(): static
    {
        return $this->actingAsSchoolAdmin($this->alpha);
    }

    private function schoolFees(array $overrides = []): array
    {
        return array_merge([
            'is_tuition' => '1', 'academic_year' => '2026/2027', 'term' => 1, 'price' => 80000,
            'class_level_ids' => [$this->jss1->id],
        ], $overrides);
    }

    // ------------------------------------------------------- built-in category

    public function test_every_school_gets_one_built_in_school_fees_category(): void
    {
        $this->admin()->get('/admin/alpha/categories')->assertOk()
            ->assertSee('School Fees')->assertSee('Built in · for school fees')->assertSee('Cannot be renamed or deleted');
        $this->admin()->get('/admin/alpha/subcategories')->assertOk();
        $this->admin()->get('/admin/alpha/subcategories/create')->assertOk();

        $builtIn = Category::where('school_id', $this->alpha->id)->where('system_key', Category::SYSTEM_SCHOOL_FEES)->sole();
        $this->assertSame('School Fees', $builtIn->name);
        $this->assertTrue($builtIn->isSystem());
        // Per school, never shared.
        $this->assertSame(0, Category::where('school_id', $this->beta->id)->count());
        $this->assertNotSame($builtIn->id, Category::schoolFeesFor($this->beta)->id);
        $this->assertSame($builtIn->id, Category::schoolFeesFor($this->alpha)->id);
    }

    public function test_a_school_fees_category_the_school_typed_itself_is_adopted_not_duplicated(): void
    {
        $older = Category::create(['school_id' => $this->alpha->id, 'name' => 'school  fee']);
        $newer = Category::create(['school_id' => $this->alpha->id, 'name' => 'SCHOOL FEES']);
        $fee = Subcategory::create(['school_id' => $this->alpha->id, 'category_id' => $older->id, 'name' => 'Old tuition', 'price' => 1000]);

        $builtIn = Category::schoolFeesFor($this->alpha);

        $this->assertSame($older->id, $builtIn->id);
        $this->assertSame(['School Fees', Category::SYSTEM_SCHOOL_FEES], [$builtIn->name, $builtIn->system_key]);
        $this->assertSame($older->id, $fee->fresh()->category_id);
        // The other variant is left alone: nothing is merged or deleted.
        $this->assertDatabaseHas('categories', ['id' => $newer->id, 'name' => 'SCHOOL FEES', 'system_key' => null]);
    }

    public function test_the_migration_backfill_adopts_the_oldest_variant_per_school_and_keeps_history(): void
    {
        $migration = require base_path('database/migrations/2026_10_08_000000_add_system_key_to_categories_table.php');
        $migration->down();

        $alphaOld = DB::table('categories')->insertGetId(['school_id' => $this->alpha->id, 'name' => 'School-Fees', 'created_at' => now(), 'updated_at' => now()]);
        $alphaNew = DB::table('categories')->insertGetId(['school_id' => $this->alpha->id, 'name' => 'school fee', 'created_at' => now(), 'updated_at' => now()]);
        $betaOnly = DB::table('categories')->insertGetId(['school_id' => $this->beta->id, 'name' => 'Uniform', 'created_at' => now(), 'updated_at' => now()]);
        $paid = $this->makeSuccessfulTransaction($this->alpha, ['category_id' => $alphaOld, 'category_name' => 'School-Fees']);

        $migration->up();

        $this->assertDatabaseHas('categories', ['id' => $alphaOld, 'name' => 'School Fees', 'system_key' => 'school_fees']);
        $this->assertDatabaseHas('categories', ['id' => $alphaNew, 'name' => 'school fee', 'system_key' => null]);
        $this->assertDatabaseHas('categories', ['id' => $betaOnly, 'name' => 'Uniform', 'system_key' => null]);
        // Payment history keeps its category id and the name it was paid under.
        $this->assertDatabaseHas('transactions', ['id' => $paid->id, 'category_id' => $alphaOld, 'category_name' => 'School-Fees']);

        // The unique index allows one built-in category per school.
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('categories')->where('id', $alphaNew)->update(['system_key' => 'school_fees']);
    }

    #[DataProvider('schoolFeesSpellings')]
    public function test_school_fees_spellings_cannot_be_created_as_categories(string $name): void
    {
        Category::schoolFeesFor($this->alpha);

        $this->admin()->from('/admin/alpha/categories')->post('/admin/alpha/categories', ['name' => $name])
            ->assertRedirect('/admin/alpha/categories')
            ->assertSessionHasErrors(['name' => '“School Fees” is built in. Choose “School fees” when you add a fee, or give this category another name.']);

        $this->assertSame(1, Category::where('school_id', $this->alpha->id)->count());
    }

    public static function schoolFeesSpellings(): array
    {
        return [
            'exact' => ['School Fees'], 'lower' => ['school fees'], 'singular' => ['School fee'],
            'upper' => ['SCHOOL FEES'], 'hyphen' => ['School-Fees'], 'joined' => ['SchoolFees'], 'padded' => ['  school   fees '],
        ];
    }

    public function test_near_duplicate_categories_are_refused_on_create_and_rename(): void
    {
        $uniform = Category::create(['school_id' => $this->alpha->id, 'name' => 'Uniform']);
        $books = Category::create(['school_id' => $this->alpha->id, 'name' => 'Books']);
        Category::create(['school_id' => $this->beta->id, 'name' => 'Transport']);

        foreach (['uniforms', 'UNIFORM', 'Uniform.'] as $name) {
            $this->admin()->from('/admin/alpha/categories')->post('/admin/alpha/categories', ['name' => $name])
                ->assertSessionHasErrors(['name' => 'You already have a category called “Uniform”.']);
        }
        $this->admin()->from("/admin/alpha/categories/{$books->id}/edit")->put("/admin/alpha/categories/{$books->id}", ['name' => 'uniforms'])
            ->assertSessionHasErrors('name');
        $this->admin()->from("/admin/alpha/categories/{$books->id}/edit")->put("/admin/alpha/categories/{$books->id}", ['name' => 'school fees'])
            ->assertSessionHasErrors('name');
        $this->assertSame('Books', $books->fresh()->name);

        // Renaming a category to a variant of its own name is fine, and another
        // school's names never clash.
        $this->admin()->put("/admin/alpha/categories/{$uniform->id}", ['name' => 'Uniforms'])->assertSessionHasNoErrors();
        $this->assertSame('Uniforms', $uniform->fresh()->name);
        $this->admin()->post('/admin/alpha/categories', ['name' => 'Transport'])->assertSessionHasNoErrors();
    }

    public function test_the_built_in_category_cannot_be_renamed_or_deleted(): void
    {
        $builtIn = Category::schoolFeesFor($this->alpha);
        $fee = $this->makeFee($this->alpha, 'School Fees', 'First Term School Fees', 50000);

        $this->admin()->get("/admin/alpha/categories/{$builtIn->id}/edit")->assertRedirect('/admin/alpha/categories')->assertSessionHas('error');
        $this->admin()->put("/admin/alpha/categories/{$builtIn->id}", ['name' => 'Tuition'])->assertRedirect('/admin/alpha/categories')->assertSessionHas('error');
        $this->admin()->delete("/admin/alpha/categories/{$builtIn->id}")->assertRedirect('/admin/alpha/categories')->assertSessionHas('error');

        $this->assertDatabaseHas('categories', ['id' => $builtIn->id, 'name' => 'School Fees', 'system_key' => 'school_fees']);
        $this->assertDatabaseHas('subcategories', ['id' => $fee->id, 'category_id' => $builtIn->id]);
        $this->assertSame(0, SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_CATEGORY_DELETED)->count());
    }

    // ------------------------------------------------------------- the fee form

    public function test_the_fee_form_defaults_to_school_fees_with_academic_year_and_term(): void
    {
        Category::create(['school_id' => $this->alpha->id, 'name' => 'Uniform']);
        Category::create(['school_id' => $this->beta->id, 'name' => 'Beta Only']);

        $page = $this->admin()->get('/admin/alpha/subcategories/create')->assertOk();
        $page->assertSee('Add a fee')->assertSee('Type of fee')
            ->assertSee('name="is_tuition" value="1" class="mt-0.5 h-5 w-5 border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" data-fee-kind checked', false)
            ->assertSee('Academic year')->assertSee('name="academic_year"', false)->assertSee('name="term"', false)
            ->assertSee('First Term')->assertSee('Second Term')->assertSee('Third Term')
            // Year and term are school fees' fields; an additional fee has neither.
            ->assertDontSee('>Any term</option>', false)
            ->assertSee('name="academic_year" id="academic_year" data-tuition-field', false)->assertSee('name="term" id="term" data-tuition-field', false)
            ->assertDontSee('Saving will remove this fee’s term')
            ->assertSee('Uniform')->assertDontSee('Beta Only')
            // No internal ids or session management on the form.
            ->assertDontSee('name="academic_term_id"', false)->assertDontSee('/admin/alpha/sessions', false)
            ->assertSee('Applies to classes')->assertSee('JSS1');
        // School Fees is the type of fee, not one of the categories to pick.
        $this->assertStringNotContainsString('>School Fees</option>', $page->getContent());
    }

    public function test_creating_school_fees_files_them_under_the_built_in_category_and_names_them_by_term(): void
    {
        $this->admin()->post('/admin/alpha/subcategories', $this->schoolFees(['category_id' => '', 'name' => '']))
            ->assertRedirect('/admin/alpha/subcategories')
            ->assertSessionHasNoErrors();

        $fee = Subcategory::sole();
        $term = AcademicTerm::where('school_id', $this->alpha->id)->where('number', 1)->sole();
        $this->assertSame(Category::schoolFeesFor($this->alpha)->id, $fee->category_id);
        $this->assertSame('First Term School Fees', $fee->name);
        $this->assertTrue($fee->is_tuition);
        $this->assertSame($term->id, $fee->academic_term_id);
        $this->assertSame([$this->jss1->id], $fee->classLevels->pluck('id')->all());

        $event = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_FEE_CREATED)->where('subject_id', $fee->id)->sole();
        $this->assertTrue($event->changes['is_tuition']['to']);

        $this->admin()->get('/admin/alpha/subcategories')->assertOk()
            ->assertSeeInOrder(['First Term School Fees', 'School fees · main class fee', 'School Fees', 'JSS1', '₦80,000.00', '2026/2027', 'First Term']);
    }

    public function test_school_fees_ignore_a_posted_category_and_cannot_be_moved_into_another_one(): void
    {
        $uniform = Category::create(['school_id' => $this->alpha->id, 'name' => 'Uniform']);
        $betaCategory = Category::create(['school_id' => $this->beta->id, 'name' => 'Beta']);

        $this->admin()->post('/admin/alpha/subcategories', $this->schoolFees(['category_id' => $uniform->id, 'name' => 'JSS1 fees']))->assertSessionHasNoErrors();
        $this->admin()->post('/admin/alpha/subcategories', $this->schoolFees(['category_id' => $betaCategory->id, 'name' => 'JSS2 fees', 'class_level_ids' => [$this->jss2->id]]))->assertSessionHasNoErrors();

        $builtIn = Category::schoolFeesFor($this->alpha);
        $this->assertSame([$builtIn->id], Subcategory::pluck('category_id')->unique()->values()->all());
    }

    public function test_existing_school_fees_keep_their_category_unless_the_admin_moves_them(): void
    {
        $tuition = Category::create(['school_id' => $this->alpha->id, 'name' => 'Tuition']);
        $term = $this->makeSessionWithTerms($this->alpha, '2026/2027')->terms()->where('number', 1)->sole();
        $fee = Subcategory::create([
            'school_id' => $this->alpha->id, 'category_id' => $tuition->id, 'name' => 'JSS1 Tuition',
            'price' => 70000, 'academic_term_id' => $term->id, 'is_tuition' => true,
        ]);
        $fee->classLevels()->sync([$this->jss1->id => ['school_id' => $this->alpha->id]]);
        $paid = $this->makeSuccessfulTransaction($this->alpha, ['category_id' => $tuition->id, 'subcategory_id' => $fee->id, 'category_name' => 'Tuition']);

        // Opening Fees creates the built-in category but moves nothing into it.
        $this->admin()->get('/admin/alpha/subcategories')->assertOk();
        $this->assertSame($tuition->id, $fee->fresh()->category_id);

        // The edit form offers the move; keeping is the default.
        $this->admin()->get("/admin/alpha/subcategories/{$fee->id}/edit")->assertOk()
            ->assertSee('Keep it in')->assertSee('Move it to')
            ->assertSee('name="school_fees_category" value="keep" class="h-5 w-5 border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" checked', false);

        // Saving — with "keep", or from a form without the choice — leaves the category alone,
        // even when the hidden additional-fee category field is submitted too.
        $this->admin()->put("/admin/alpha/subcategories/{$fee->id}", $this->schoolFees(['name' => 'JSS1 Tuition', 'price' => 75000, 'school_fees_category' => 'keep']))
            ->assertSessionHasNoErrors();
        $this->admin()->put("/admin/alpha/subcategories/{$fee->id}", $this->schoolFees(['name' => 'JSS1 Tuition', 'category_id' => Category::schoolFeesFor($this->alpha)->id]))
            ->assertSessionHasNoErrors();
        $this->assertSame($tuition->id, $fee->fresh()->category_id);
        $this->assertSame(0, SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_FEE_UPDATED)->get()
            ->filter(fn ($e) => isset($e->changes['category_id']))->count());

        // Only an explicit move puts it in School Fees, and that change is audited.
        $this->admin()->put("/admin/alpha/subcategories/{$fee->id}", $this->schoolFees(['name' => 'JSS1 Tuition', 'school_fees_category' => 'move']))
            ->assertSessionHasNoErrors();
        $builtIn = Category::schoolFeesFor($this->alpha);
        $this->assertSame($builtIn->id, $fee->fresh()->category_id);
        $moved = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_FEE_UPDATED)->get()->last(fn ($e) => isset($e->changes['category_id']));
        $this->assertEquals(['from' => $tuition->id, 'to' => $builtIn->id], $moved->changes['category_id']);

        // Past payments keep the category they were made under.
        $this->assertDatabaseHas('transactions', ['id' => $paid->id, 'category_id' => $tuition->id, 'category_name' => 'Tuition']);
        $this->assertDatabaseHas('categories', ['id' => $tuition->id]);
    }

    public function test_an_existing_additional_fee_turned_into_school_fees_also_keeps_its_category(): void
    {
        $levies = Category::create(['school_id' => $this->alpha->id, 'name' => 'Levies']);
        $fee = Subcategory::create(['school_id' => $this->alpha->id, 'category_id' => $levies->id, 'name' => 'Termly levy', 'price' => 5000]);

        $this->admin()->put("/admin/alpha/subcategories/{$fee->id}", $this->schoolFees(['name' => 'Termly levy']))->assertSessionHasNoErrors();

        $this->assertTrue($fee->fresh()->is_tuition);
        $this->assertSame($levies->id, $fee->fresh()->category_id);
    }

    public function test_school_fees_need_a_term_and_a_class_and_one_per_class_per_term(): void
    {
        // Term required ("Any term" is for additional fees only).
        $this->admin()->from('/admin/alpha/subcategories/create')->post('/admin/alpha/subcategories', $this->schoolFees(['term' => '']))
            ->assertSessionHasErrors(['term' => 'Choose the term these school fees are for.']);
        // At least one class.
        $this->admin()->from('/admin/alpha/subcategories/create')->post('/admin/alpha/subcategories', $this->schoolFees(['class_level_ids' => []]))
            ->assertSessionHasErrors('class_level_ids');
        $this->assertSame(0, Subcategory::count());

        // One main fee per class per term.
        $this->admin()->post('/admin/alpha/subcategories', $this->schoolFees())->assertSessionHasNoErrors();
        $this->admin()->from('/admin/alpha/subcategories/create')
            ->post('/admin/alpha/subcategories', $this->schoolFees(['name' => 'Boarding fees', 'class_level_ids' => [$this->jss1->id, $this->jss2->id]]))
            ->assertSessionHasErrors('class_level_ids');
        // Another term, or another class, is fine.
        $this->admin()->post('/admin/alpha/subcategories', $this->schoolFees(['term' => 2]))->assertSessionHasNoErrors();
        $this->admin()->post('/admin/alpha/subcategories', $this->schoolFees(['class_level_ids' => [$this->jss2->id]]))->assertSessionHasNoErrors();
        $this->assertSame(3, Subcategory::where('is_tuition', true)->count());
    }

    public function test_additional_fees_take_a_category_and_may_be_payable_in_any_term(): void
    {
        $uniform = Category::create(['school_id' => $this->alpha->id, 'name' => 'Uniform']);

        // Name and category are required for an additional fee — reported together.
        $this->admin()->from('/admin/alpha/subcategories/create')
            ->post('/admin/alpha/subcategories', ['is_tuition' => '0', 'price' => 3000, 'academic_year' => '2026/2027', 'term' => ''])
            ->assertSessionHasErrors(['category_id', 'name']);

        $this->admin()->post('/admin/alpha/subcategories', [
            'is_tuition' => '0', 'category_id' => $uniform->id, 'name' => 'Shirt', 'price' => 3000,
            'academic_year' => '2026/2027', 'term' => '', 'allows_quantity' => '1',
        ])->assertSessionHasNoErrors();

        $shirt = Subcategory::where('name', 'Shirt')->sole();
        $this->assertSame($uniform->id, $shirt->category_id);
        $this->assertNull($shirt->academic_term_id);
        $this->assertFalse($shirt->is_tuition);
        $this->assertTrue($shirt->allows_quantity);
        $this->assertTrue($shirt->isPayableForStudent(null), 'an unassigned ordinary fee stays payable by everyone');
        // "Any term" creates no academic year.
        $this->assertDatabaseCount('academic_sessions', 0);
    }

    public function test_an_additional_fee_never_saves_a_posted_academic_year_or_term(): void
    {
        $uniform = Category::create(['school_id' => $this->alpha->id, 'name' => 'Uniform']);
        $betaTerm = $this->makeSessionWithTerms($this->beta, '2026/2027')->terms()->where('number', 1)->sole();

        // Year, term and a legacy term id — even malformed or another school's — are
        // excluded for an additional fee, not validated, saved or used to create a year.
        $this->admin()->post('/admin/alpha/subcategories', [
            'is_tuition' => '0', 'category_id' => $uniform->id, 'name' => 'Shirt', 'price' => 3000,
            'academic_year' => '2026/2027', 'term' => 2, 'academic_term_id' => $betaTerm->id,
        ])->assertRedirect('/admin/alpha/subcategories')->assertSessionHasNoErrors();
        $this->admin()->post('/admin/alpha/subcategories', [
            'is_tuition' => '0', 'category_id' => $uniform->id, 'name' => 'Tie', 'price' => 500,
            'academic_year' => 'not a year', 'term' => 9,
        ])->assertSessionHasNoErrors();

        $this->assertSame([null, null], Subcategory::orderBy('id')->pluck('academic_term_id')->all());
        $this->assertSame(0, AcademicTerm::where('school_id', $this->alpha->id)->count());
        $this->assertTrue(Subcategory::where('name', 'Shirt')->sole()->isPayableForTerm($betaTerm));
    }

    public function test_saving_an_additional_fee_that_has_a_term_makes_it_payable_in_any_term(): void
    {
        $levies = Category::create(['school_id' => $this->alpha->id, 'name' => 'Levies']);
        $session = $this->makeSessionWithTerms($this->alpha, '2026/2027');
        $first = $session->terms()->where('number', 1)->sole();
        $second = $session->terms()->where('number', 2)->sole();
        $fee = Subcategory::create([
            'school_id' => $this->alpha->id, 'category_id' => $levies->id, 'name' => 'Sports levy',
            'price' => 2000, 'academic_term_id' => $first->id,
        ]);
        $paid = $this->makeSuccessfulTransaction($this->alpha, [
            'category_id' => $levies->id, 'subcategory_id' => $fee->id, 'subcategory_name' => 'Sports levy',
            'academic_session_id' => $session->id, 'academic_term_id' => $first->id,
            'session_name' => '2026/2027', 'term_name' => 'First Term',
        ]);
        $paidBefore = $paid->fresh()->getAttributes();

        // Untouched, an existing term-limited additional fee behaves as it did.
        $this->assertFalse($fee->isPayableForTerm($second));

        // The edit form warns that saving lifts the term.
        $this->admin()->get("/admin/alpha/subcategories/{$fee->id}/edit")->assertOk()
            ->assertSee('Saving will remove this fee’s term')->assertSee('currently limited to First Term, 2026/2027');

        // Saved as it would be from the form — no checkbox, and a stale year/term is ignored.
        $this->admin()->put("/admin/alpha/subcategories/{$fee->id}", [
            'is_tuition' => '0', 'category_id' => $levies->id, 'name' => 'Sports levy', 'price' => 2000,
            'academic_year' => '2026/2027', 'term' => 1,
        ])->assertRedirect('/admin/alpha/subcategories')->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'It is now payable in any term.'));

        $fee->refresh();
        $this->assertNull($fee->academic_term_id);
        $this->assertTrue($fee->isPayableForTerm($second));

        $event = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_FEE_UPDATED)->where('subject_id', $fee->id)->sole();
        $this->assertEquals(['from' => $first->id, 'to' => null], $event->changes['academic_term_id']);

        // The payment made under the term, and its receipt details, are untouched.
        $this->assertSame($paidBefore, $paid->fresh()->getAttributes());
    }

    public function test_switching_school_fees_without_payment_records_to_an_additional_fee_removes_the_term_with_a_warning_and_an_audit_record(): void
    {
        $levies = Category::create(['school_id' => $this->alpha->id, 'name' => 'Levies']);
        $this->admin()->post('/admin/alpha/subcategories', $this->schoolFees(['term' => 2]))->assertSessionHasNoErrors();
        $fee = Subcategory::sole();
        $termId = $fee->academic_term_id;

        // No payment records, so the type is open. The warning is on the form, shown by
        // the script only once "Additional fee" is chosen.
        $this->admin()->get("/admin/alpha/subcategories/{$fee->id}/edit")->assertOk()
            ->assertDontSee('id="is_tuition-locked"', false)->assertDontSee('data-fee-kind  disabled', false)
            ->assertSee('data-additional-only role="status"', false)
            ->assertSee('Saving will remove this fee’s term')->assertSee('currently limited to Second Term, 2026/2027');

        // Without JavaScript the year and term are still posted; they are ignored.
        $this->admin()->put("/admin/alpha/subcategories/{$fee->id}", [
            'is_tuition' => '0', 'category_id' => $levies->id, 'name' => 'Second Term School Fees', 'price' => 80000,
            'academic_year' => '2026/2027', 'term' => 2, 'class_level_ids' => [$this->jss1->id],
        ])->assertRedirect('/admin/alpha/subcategories')->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($message) => str_contains($message, 'It is now payable in any term.'));

        $fee->refresh();
        $this->assertFalse($fee->is_tuition);
        $this->assertNull($fee->academic_term_id);

        $event = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_FEE_UPDATED)->where('subject_id', $fee->id)->sole();
        $this->assertEquals(['from' => true, 'to' => false], $event->changes['is_tuition']);
        $this->assertEquals(['from' => $termId, 'to' => null], $event->changes['academic_term_id']);
    }

    // --------------------------------------------------------- fee-type lock

    /** Every kind of payment record locks a fee's type, not only a successful payment. */
    public static function paymentRecords(): array
    {
        return [
            'successful' => ['success', 'paystack'],
            'pending' => ['pending', 'paystack'],
            'failed' => ['failed', 'paystack'],
            'held for review' => ['mismatch', 'paystack'],
            'cash' => ['success', 'manual'],
            'voided cash' => ['voided', 'manual'],
        ];
    }

    /** A payment row for the fee, student and term, with the given status and source. */
    private function paymentRecord(Subcategory $fee, Student $student, AcademicTerm $term, string $status, string $source): Transaction
    {
        return $this->makeSuccessfulTransaction($this->alpha, [
            'status' => $status, 'source' => $source, 'paid_at' => $status === 'success' ? now() : null,
            'student_id' => $student->id, 'subcategory_id' => $fee->id, 'category_id' => $fee->category_id,
            'academic_session_id' => $term->academic_session_id, 'academic_term_id' => $term->id,
        ]);
    }

    #[DataProvider('paymentRecords')]
    public function test_school_fees_with_payment_records_cannot_become_an_additional_fee(string $status, string $source): void
    {
        $levies = Category::create(['school_id' => $this->alpha->id, 'name' => 'Levies']);
        $this->admin()->post('/admin/alpha/subcategories', $this->schoolFees())->assertSessionHasNoErrors();
        $fee = Subcategory::sole();
        $student = $this->makeStudent($this->alpha, 'A/1', 'Ada', 'JSS1', ['class_level_id' => $this->jss1->id]);
        $record = $this->paymentRecord($fee, $student, $fee->academicTerm, $status, $source);
        $recordBefore = $record->fresh()->getAttributes();
        $feeBefore = $fee->fresh()->getAttributes();

        // The form explains the lock and offers only the fee's own type.
        $this->admin()->get("/admin/alpha/subcategories/{$fee->id}/edit")->assertOk()
            ->assertSee('id="is_tuition-locked"', false)
            ->assertSee('The type of this fee can’t be changed')->assertSee('create a new fee instead')
            ->assertSee('value="1" class="mt-0.5 h-5 w-5 border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" data-fee-kind checked', false)
            ->assertSee('value="0" class="mt-0.5 h-5 w-5 border-brand-ash text-brand-violet focus:ring-4 focus:ring-brand-violet/30" data-fee-kind  disabled', false);

        // A posted switch, however it was built, is refused and nothing changes.
        $this->admin()->from("/admin/alpha/subcategories/{$fee->id}/edit")->put("/admin/alpha/subcategories/{$fee->id}", [
            'is_tuition' => '0', 'category_id' => $levies->id, 'name' => 'Levy', 'price' => 5000,
        ])->assertRedirect("/admin/alpha/subcategories/{$fee->id}/edit")
            ->assertSessionHasErrors(['is_tuition' => SubcategoryController::MESSAGE_TYPE_LOCKED]);

        $this->assertSame($feeBefore, $fee->fresh()->getAttributes());
        $this->assertSame($recordBefore, $record->fresh()->getAttributes());
        $this->assertSame(0, SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_FEE_UPDATED)->count());
    }

    #[DataProvider('paymentRecords')]
    public function test_an_additional_fee_with_payment_records_cannot_become_school_fees(string $status, string $source): void
    {
        $levies = Category::create(['school_id' => $this->alpha->id, 'name' => 'Levies']);
        $term = $this->makeSessionWithTerms($this->alpha, '2026/2027')->terms()->where('number', 1)->sole();
        $fee = Subcategory::create(['school_id' => $this->alpha->id, 'category_id' => $levies->id, 'name' => 'Levy', 'price' => 5000]);
        $student = $this->makeStudent($this->alpha, 'A/1', 'Ada', 'JSS1', ['class_level_id' => $this->jss1->id]);
        $record = $this->paymentRecord($fee, $student, $term, $status, $source);
        $recordBefore = $record->fresh()->getAttributes();

        $this->admin()->get("/admin/alpha/subcategories/{$fee->id}/edit")->assertOk()
            ->assertSee('id="is_tuition-locked"', false)
            ->assertSee('To charge it as school fees, create a new fee instead.');

        $this->admin()->from("/admin/alpha/subcategories/{$fee->id}/edit")
            ->put("/admin/alpha/subcategories/{$fee->id}", $this->schoolFees(['name' => 'Levy', 'academic_year' => '2030/2031']))
            ->assertSessionHasErrors(['is_tuition' => SubcategoryController::MESSAGE_TYPE_LOCKED]);

        $this->assertFalse($fee->fresh()->is_tuition);
        $this->assertSame($recordBefore, $record->fresh()->getAttributes());
        // The refused save created nothing for the year it named.
        $this->assertSame(0, AcademicSession::where('school_id', $this->alpha->id)->where('name', '2030/2031')->count());
        // The levy payment was never school fees, and still is not.
        $this->assertFalse(Transaction::paidObligation($student->id, $term->id)->exists());
    }

    public function test_a_locked_fee_keeps_every_other_detail_editable_and_its_paid_once_record(): void
    {
        $this->admin()->post('/admin/alpha/subcategories', $this->schoolFees())->assertSessionHasNoErrors();
        $fee = Subcategory::sole();
        $student = $this->makeStudent($this->alpha, 'A/1', 'Ada', 'JSS1', ['class_level_id' => $this->jss1->id]);
        $key = Transaction::obligationKey($student->id, $fee->academicTerm->academic_session_id, $fee->academic_term_id);
        $paid = $this->paymentRecord($fee, $student, $fee->academicTerm, 'success', 'paystack');
        $paid->forceFill(['obligation_key' => $key, 'settled_obligation_key' => $key])->save();
        $paidBefore = $paid->fresh()->getAttributes();

        // Same type: name, amount and classes all save as usual.
        $this->admin()->put("/admin/alpha/subcategories/{$fee->id}", $this->schoolFees([
            'name' => 'JSS fees', 'price' => 90000, 'class_level_ids' => [$this->jss1->id, $this->jss2->id],
        ]))->assertRedirect('/admin/alpha/subcategories')->assertSessionHasNoErrors();

        $fee->refresh();
        $this->assertTrue($fee->is_tuition);
        $this->assertSame('JSS fees', $fee->name);
        $this->assertEquals(90000, (float) $fee->price);
        $this->assertEqualsCanonicalizing([$this->jss1->id, $this->jss2->id], $fee->classLevels->pluck('id')->all());

        // The payment is untouched and still counts: this term's school fees stay paid,
        // and checkout refuses a second payment.
        $this->assertSame($paidBefore, $paid->fresh()->getAttributes());
        $this->assertTrue(Transaction::paidObligation($student->id, $fee->academic_term_id)->exists());
    }

    public function test_switching_an_additional_fee_to_school_fees_requires_a_term_again(): void
    {
        $levies = Category::create(['school_id' => $this->alpha->id, 'name' => 'Levies']);
        $fee = Subcategory::create(['school_id' => $this->alpha->id, 'category_id' => $levies->id, 'name' => 'Levy', 'price' => 5000]);

        // An additional fee has no term to warn about.
        $this->admin()->get("/admin/alpha/subcategories/{$fee->id}/edit")->assertOk()
            ->assertDontSee('Saving will remove this fee’s term');

        $this->admin()->from("/admin/alpha/subcategories/{$fee->id}/edit")
            ->put("/admin/alpha/subcategories/{$fee->id}", $this->schoolFees(['name' => 'Levy', 'term' => '']))
            ->assertSessionHasErrors(['term' => 'Choose the term these school fees are for.']);
        $this->assertFalse($fee->fresh()->is_tuition);

        $this->admin()->put("/admin/alpha/subcategories/{$fee->id}", $this->schoolFees(['name' => 'Levy', 'term' => 3]))->assertSessionHasNoErrors();
        $this->assertSame(3, $fee->fresh()->academicTerm->number);
    }

    public function test_a_typed_new_category_is_created_once_and_variants_reuse_it(): void
    {
        $this->admin()->post('/admin/alpha/subcategories', ['is_tuition' => '0', 'new_category' => 'Textbooks', 'name' => 'Maths', 'price' => 2000])->assertSessionHasNoErrors();
        $this->admin()->post('/admin/alpha/subcategories', ['is_tuition' => '0', 'new_category' => 'textbook', 'name' => 'English', 'price' => 2000])->assertSessionHasNoErrors();
        $this->admin()->post('/admin/alpha/subcategories', ['is_tuition' => '0', 'new_category' => 'school fees', 'name' => 'Levy', 'price' => 500])->assertSessionHasNoErrors();

        $textbooks = Category::where('school_id', $this->alpha->id)->where('name', 'Textbooks')->sole();
        $this->assertSame([$textbooks->id, $textbooks->id], Subcategory::whereIn('name', ['Maths', 'English'])->pluck('category_id')->all());
        // A typed spelling of School Fees is the built-in category, not a new one.
        $this->assertSame(Category::schoolFeesFor($this->alpha)->id, Subcategory::where('name', 'Levy')->value('category_id'));
        $this->assertFalse(Subcategory::where('name', 'Levy')->value('is_tuition'));
        $this->assertSame(2, Category::where('school_id', $this->alpha->id)->count());
    }

    public function test_editing_a_fee_shows_its_year_and_term_and_keeps_paid_once_history(): void
    {
        $this->admin()->post('/admin/alpha/subcategories', $this->schoolFees(['term' => 2]))->assertSessionHasNoErrors();
        $fee = Subcategory::sole();
        $student = $this->makeStudent($this->alpha, 'A/1', 'Ada', 'JSS1', ['class_level_id' => $this->jss1->id]);
        $paid = $this->makeSuccessfulTransaction($this->alpha, [
            'student_id' => $student->id, 'subcategory_id' => $fee->id, 'category_id' => $fee->category_id,
            'academic_session_id' => $fee->academicTerm->academic_session_id, 'academic_term_id' => $fee->academic_term_id,
        ]);

        $this->admin()->get("/admin/alpha/subcategories/{$fee->id}/edit")->assertOk()
            ->assertSee('Edit fee')
            ->assertSee('<option value="2026/2027" selected>2026/2027</option>', false)
            ->assertSee('<option value="2" selected>Second Term</option>', false)
            ->assertSee('value="Second Term School Fees"', false);

        $this->admin()->put("/admin/alpha/subcategories/{$fee->id}", $this->schoolFees(['term' => 2, 'name' => 'Second Term School Fees', 'price' => 85000]))
            ->assertRedirect('/admin/alpha/subcategories')->assertSessionHasNoErrors();

        $this->assertEquals(85000, (float) $fee->fresh()->price);
        $this->assertTrue(Transaction::paidObligation($student->id, $fee->academic_term_id)->exists(), 'the paid-once record is untouched');
        $this->assertDatabaseHas('transactions', ['id' => $paid->id, 'subcategory_id' => $fee->id, 'status' => 'success']);
    }

    public function test_a_foreign_class_or_term_still_fails_closed_with_the_new_inputs(): void
    {
        $betaLevel = ClassLevel::create(['school_id' => $this->beta->id, 'name' => 'JSS1', 'position' => 1]);

        $this->admin()->post('/admin/alpha/subcategories', $this->schoolFees(['class_level_ids' => [$betaLevel->id]]))->assertNotFound();
        $this->assertSame(0, Subcategory::count());
        // Nothing was created for the year either: ids are resolved before the transaction.
        $this->assertDatabaseCount('academic_sessions', 0);

        // Beta cannot write into alpha's fees at all.
        $this->actingAsSchoolAdmin($this->beta)->post('/admin/alpha/subcategories', $this->schoolFees())->assertNotFound();
        $this->assertSame(0, Subcategory::count());
    }
}
