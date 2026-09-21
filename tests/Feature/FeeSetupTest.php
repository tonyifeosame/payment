<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\Category;
use App\Models\School;
use App\Models\Subcategory;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * The redesigned Categories + Fee types area: rendering, CRUD, validation, the
 * (unchanged) deletion semantics, tenant isolation, and that the public payment
 * page still receives exactly the same fee structure.
 */
class FeeSetupTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    private AcademicTerm $first;

    private Category $tuition;

    private Subcategory $termFee;

    private Subcategory $generalFee;

    private Category $betaCategory;

    private Subcategory $betaFee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');
        $session = $this->makeSessionWithTerms($this->alpha, '2026/2027');
        $this->first = $session->terms()->where('number', 1)->firstOrFail();

        $this->termFee = $this->makeFee($this->alpha, 'Tuition', 'First Term Tuition', 50000, $this->first->id);
        $this->tuition = $this->termFee->category;
        $this->generalFee = $this->makeFee($this->alpha, 'Uniform', 'Shirt', 3000, null);
        Category::create(['school_id' => $this->alpha->id, 'name' => 'Empty Category']);

        $this->betaFee = $this->makeFee($this->beta, 'Beta Category', 'Beta Fee', 999, null);
        $this->betaCategory = $this->betaFee->category;
    }

    private function alpha(): static
    {
        return $this->actingAsSchoolAdmin($this->alpha);
    }

    // ------------------------------------------------------------- categories

    public function test_categories_list_renders_with_fee_type_counts(): void
    {
        $page = $this->alpha()->get('/admin/alpha/categories')->assertOk();

        $page->assertSee('Fee setup')->assertSee('Organize the fees your school collects.')->assertSeeText('3 categories')
            ->assertSeeInOrder(['Tuition', '1 fee type'])
            ->assertSeeInOrder(['Uniform', '1 fee type'])
            ->assertSeeInOrder(['Empty Category', '0 fee types', 'Parents cannot pay into an empty category'])
            ->assertSee("/admin/alpha/categories/{$this->tuition->id}/edit")
            ->assertSee('data-confirm-title="Delete “Tuition”?"', false)
            ->assertSee('This also deletes its 1 fee type', false)
            ->assertDontSee('Beta Category')
            ->assertDontSee('window.confirm');
        // Exactly one H1.
        $this->assertSame(1, substr_count($page->getContent(), '<h1'));
    }

    public function test_category_create_edit_and_validation(): void
    {
        $this->alpha()->post('/admin/alpha/categories', ['name' => 'Books'])->assertRedirect('/admin/alpha/categories')->assertSessionHas('success');
        $this->assertDatabaseHas('categories', ['school_id' => $this->alpha->id, 'name' => 'Books']);

        $this->alpha()->from('/admin/alpha/categories')->post('/admin/alpha/categories', ['name' => ''])
            ->assertRedirect('/admin/alpha/categories')->assertSessionHasErrors('name');
        $this->alpha()->from('/admin/alpha/categories')->post('/admin/alpha/categories', ['name' => str_repeat('x', 256)])->assertSessionHasErrors('name');

        // The error renders inline, associated with the field, and old input is kept.
        $this->alpha()->withSession(['errors' => $this->errorBag('name', 'The name field is required.'), '_old_input' => ['name' => '']])
            ->get('/admin/alpha/categories')->assertOk()->assertSee('aria-describedby="name-help name-error"', false)->assertSee('The name field is required.');

        $this->alpha()->get("/admin/alpha/categories/{$this->tuition->id}/edit")->assertOk()->assertSee('Edit category')->assertSee('value="Tuition"', false)->assertSee('Save changes');
        $this->alpha()->put("/admin/alpha/categories/{$this->tuition->id}", ['name' => 'School Fees'])->assertRedirect('/admin/alpha/categories')->assertSessionHas('success');
        $this->assertDatabaseHas('categories', ['id' => $this->tuition->id, 'name' => 'School Fees']);
        // Fee types stay attached; transaction snapshots are untouched by a rename.
        $this->assertDatabaseHas('subcategories', ['id' => $this->termFee->id, 'category_id' => $this->tuition->id]);

        $this->alpha()->from("/admin/alpha/categories/{$this->tuition->id}/edit")->put("/admin/alpha/categories/{$this->tuition->id}", ['name' => ''])
            ->assertRedirect("/admin/alpha/categories/{$this->tuition->id}/edit")->assertSessionHasErrors('name');
    }

    public function test_deleting_a_category_cascades_to_its_fee_types_and_keeps_payment_history(): void
    {
        $paid = $this->makeSuccessfulTransaction($this->alpha, [
            'reference' => 'ref-tuition', 'category_id' => $this->tuition->id, 'subcategory_id' => $this->termFee->id,
            'category_name' => 'Tuition', 'subcategory_name' => 'First Term Tuition',
        ]);

        $this->alpha()->delete("/admin/alpha/categories/{$this->tuition->id}")->assertRedirect('/admin/alpha/categories')->assertSessionHas('success');

        // Existing behaviour, unchanged: the DB cascades to the fee types…
        $this->assertDatabaseMissing('categories', ['id' => $this->tuition->id]);
        $this->assertDatabaseMissing('subcategories', ['id' => $this->termFee->id]);
        // …and the transaction survives with its snapshot names, ids set to null.
        $this->assertDatabaseHas('transactions', ['id' => $paid->id, 'category_id' => null, 'subcategory_id' => null, 'category_name' => 'Tuition', 'subcategory_name' => 'First Term Tuition']);
        $this->alpha()->get('/admin/alpha/transactions')->assertOk()->assertSee('ref-tuition')->assertSee('First Term Tuition');
        // Other categories and other schools are untouched.
        $this->assertDatabaseHas('subcategories', ['id' => $this->generalFee->id]);
        $this->assertDatabaseHas('subcategories', ['id' => $this->betaFee->id]);
    }

    public function test_category_tenant_isolation_and_guest_protection(): void
    {
        $id = $this->betaCategory->id;
        $this->alpha()->get('/admin/alpha/categories')->assertOk()->assertDontSee('Beta Category');
        $this->alpha()->get("/admin/alpha/categories/{$id}/edit")->assertNotFound();
        $this->alpha()->put("/admin/alpha/categories/{$id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->alpha()->delete("/admin/alpha/categories/{$id}")->assertNotFound();
        $this->alpha()->get('/admin/beta/categories')->assertNotFound();
        $this->alpha()->delete("/admin/beta/categories/{$id}")->assertNotFound();
        $this->alpha()->get('/admin/alpha/categories/999999/edit')->assertNotFound();
        $this->assertDatabaseHas('categories', ['id' => $id, 'name' => 'Beta Category']);

        $this->flushSession();
        $this->get('/admin/alpha/categories')->assertRedirect('/admin/login');
        $this->post('/admin/alpha/categories', ['name' => 'X'])->assertRedirect('/admin/login');
        $this->delete("/admin/alpha/categories/{$this->tuition->id}")->assertRedirect('/admin/login');
        $this->assertDatabaseHas('categories', ['id' => $this->tuition->id]);
    }

    // -------------------------------------------------------------- fee types

    public function test_fee_types_list_renders_with_category_amount_session_and_term(): void
    {
        $page = $this->alpha()->get('/admin/alpha/subcategories')->assertOk();

        $page->assertSee('Fee setup')->assertSee('Set the fees students can pay for each academic term.')->assertSeeText('2 fee types')
            ->assertSee('First Term Tuition')->assertSee('₦50,000.00')->assertSee('2026/2027')->assertSee('First Term')
            ->assertSee('Shirt')->assertSee('₦3,000.00')->assertSee('General')->assertSee('Payable in any term')
            ->assertSee("/admin/alpha/subcategories/{$this->termFee->id}/edit")
            ->assertSee('data-confirm-title="Delete “Shirt”?"', false)
            ->assertDontSee('Beta Fee')->assertDontSee('window.confirm');
        // Ordered by category name: Tuition rows before Uniform rows.
        $page->assertSeeInOrder(['First Term Tuition', 'Shirt']);
        $this->assertSame(1, substr_count($page->getContent(), '<h1'));

        // A fee without an amount is shown as such rather than as ₦0.00.
        Subcategory::create(['school_id' => $this->alpha->id, 'category_id' => $this->tuition->id, 'name' => 'Unpriced', 'price' => null]);
        $this->alpha()->get('/admin/alpha/subcategories')->assertOk()->assertSeeInOrder(['Unpriced', 'Not set']);
    }

    public function test_fee_type_create_edit_validation_and_relationships(): void
    {
        $page = $this->alpha()->get('/admin/alpha/subcategories/create')->assertOk();
        $page->assertSee('New fee type')->assertSee('Create fee type')
            ->assertSee('<optgroup label="2026/2027">', false)->assertSee('Second Term, 2026/2027')->assertSee('General — payable in any term')
            ->assertSee('Tuition')->assertSee('Uniform')->assertDontSee('Beta Category');

        $this->alpha()->post('/admin/alpha/subcategories', ['category_id' => $this->tuition->id, 'name' => 'Second Term Tuition', 'price' => '52000.50', 'academic_term_id' => $this->first->id])
            ->assertRedirect('/admin/alpha/subcategories')->assertSessionHas('success');
        $this->assertDatabaseHas('subcategories', ['school_id' => $this->alpha->id, 'category_id' => $this->tuition->id, 'name' => 'Second Term Tuition', 'price' => 52000.50, 'academic_term_id' => $this->first->id]);

        // Validation: required fields, negative amount, old input preserved with inline errors.
        $this->alpha()->from('/admin/alpha/subcategories/create')
            ->post('/admin/alpha/subcategories', ['category_id' => '', 'name' => '', 'price' => '-5'])
            ->assertRedirect('/admin/alpha/subcategories/create')->assertSessionHasErrors(['category_id', 'name', 'price']);
        $this->alpha()->withSession(['errors' => $this->errorBag('price', 'The price field must be at least 0.'), '_old_input' => ['name' => 'Kept Name', 'price' => '-5']])
            ->get('/admin/alpha/subcategories/create')->assertOk()
            ->assertSee('value="Kept Name"', false)->assertSee('aria-describedby="price-help price-error"', false)->assertSee('must be at least 0');

        // Edit: pre-filled, term shown, saves.
        $edit = $this->alpha()->get("/admin/alpha/subcategories/{$this->termFee->id}/edit")->assertOk();
        $edit->assertSee('Edit fee type')->assertSee('value="First Term Tuition"', false)->assertSee('value="50000', false)
            ->assertSee('<option value="'.$this->first->id.'" selected', false)->assertSee('Save changes')->assertSee('editing an existing fee');
        $this->alpha()->put("/admin/alpha/subcategories/{$this->termFee->id}", ['category_id' => $this->generalFee->category_id, 'name' => 'Moved Fee', 'price' => '100', 'academic_term_id' => ''])
            ->assertRedirect('/admin/alpha/subcategories')->assertSessionHas('success');
        $this->assertDatabaseHas('subcategories', ['id' => $this->termFee->id, 'category_id' => $this->generalFee->category_id, 'name' => 'Moved Fee', 'price' => 100, 'academic_term_id' => null]);
    }

    public function test_fee_type_delete_keeps_payment_history(): void
    {
        $paid = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-shirt', 'category_id' => $this->generalFee->category_id, 'subcategory_id' => $this->generalFee->id, 'subcategory_name' => 'Shirt']);

        $this->alpha()->delete("/admin/alpha/subcategories/{$this->generalFee->id}")->assertRedirect('/admin/alpha/subcategories')->assertSessionHas('success');
        $this->assertDatabaseMissing('subcategories', ['id' => $this->generalFee->id]);
        $this->assertDatabaseHas('categories', ['id' => $this->generalFee->category_id]); // category stays
        $this->assertDatabaseHas('transactions', ['id' => $paid->id, 'subcategory_id' => null, 'subcategory_name' => 'Shirt']);
    }

    public function test_fee_type_tenant_isolation_and_guest_protection(): void
    {
        $id = $this->betaFee->id;
        $this->alpha()->get('/admin/alpha/subcategories')->assertOk()->assertDontSee('Beta Fee');
        $this->alpha()->get("/admin/alpha/subcategories/{$id}/edit")->assertNotFound();
        $this->alpha()->put("/admin/alpha/subcategories/{$id}", ['category_id' => $this->tuition->id, 'name' => 'Hijacked'])->assertNotFound();
        $this->alpha()->delete("/admin/alpha/subcategories/{$id}")->assertNotFound();
        $this->alpha()->get('/admin/beta/subcategories')->assertNotFound();
        $this->alpha()->get('/admin/beta/subcategories/create')->assertNotFound();
        // Another school's category or term ids fail closed on create and update.
        $this->alpha()->post('/admin/alpha/subcategories', ['category_id' => $this->betaCategory->id, 'name' => 'Smuggled', 'price' => 1])->assertNotFound();
        $betaTerm = $this->makeSessionWithTerms($this->beta, '2026/2027')->terms()->firstOrFail();
        $this->alpha()->post('/admin/alpha/subcategories', ['category_id' => $this->tuition->id, 'name' => 'Smuggled', 'price' => 1, 'academic_term_id' => $betaTerm->id])->assertNotFound();
        $this->alpha()->put("/admin/alpha/subcategories/{$this->termFee->id}", ['category_id' => $this->betaCategory->id, 'name' => 'Smuggled', 'price' => 1])->assertNotFound();
        $this->assertDatabaseMissing('subcategories', ['name' => 'Smuggled']);
        $this->assertDatabaseHas('subcategories', ['id' => $id, 'name' => 'Beta Fee']);
        $this->assertDatabaseHas('subcategories', ['id' => $this->termFee->id, 'name' => 'First Term Tuition']);

        $this->flushSession();
        $this->get('/admin/alpha/subcategories')->assertRedirect('/admin/login');
        $this->get('/admin/alpha/subcategories/create')->assertRedirect('/admin/login');
        $this->delete("/admin/alpha/subcategories/{$this->termFee->id}")->assertRedirect('/admin/login');
        $this->assertDatabaseHas('subcategories', ['id' => $this->termFee->id]);
    }

    public function test_empty_states(): void
    {
        $fresh = $this->makeSchool('Gamma School', 'gamma');
        $this->actingAsSchoolAdmin($fresh)->get('/admin/gamma/categories')->assertOk()->assertSee('No fee categories yet')->assertSee('Categories organize your fee types');
        $this->actingAsSchoolAdmin($fresh)->get('/admin/gamma/subcategories')->assertOk()->assertSee('No fee types yet')->assertSee('Add a category first');

        Category::create(['school_id' => $fresh->id, 'name' => 'Only Category']);
        $this->actingAsSchoolAdmin($fresh)->get('/admin/gamma/subcategories')->assertOk()->assertSee('No fee types yet')->assertSee('Add fee type')->assertDontSee('Add a category first');
    }

    // ---------------------------------------------------------- compatibility

    public function test_public_payment_page_still_receives_the_same_fee_structure(): void
    {
        $page = $this->get('/s/alpha/payment')->assertOk();

        // The same ids, names, prices and term ids reach the browser as before.
        $page->assertSee('"id":'.$this->tuition->id, false)->assertSee('"name":"Tuition"', false)
            ->assertSee('"id":'.$this->termFee->id, false)->assertSee('"name":"First Term Tuition"', false)->assertSee('"price":50000', false)->assertSee('"term_id":'.$this->first->id, false)
            ->assertSee('"name":"Shirt"', false)->assertSee('"price":3000', false)->assertSee('"term_id":null', false)
            ->assertDontSee('Beta Fee');
    }

    public function test_payment_initialization_still_uses_the_server_side_fee_amount(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_secret', 'fees.markup_percent' => 2.5]);
        Http::fake(['*/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/abc']])]);
        $student = $this->makeStudent($this->alpha, 'A/001', 'Ada');

        $this->post('/s/alpha/payment/initialize', [
            'email' => 'parent@example.test', 'name' => 'Parent',
            'category_id' => $this->tuition->id, 'subcategory_id' => $this->termFee->id, 'quantity' => 1,
            'student_id' => $student->id, 'academic_session_id' => $this->first->academic_session_id, 'academic_term_id' => $this->first->id,
            'price' => '1', 'amount' => '1', // browser-supplied figures are ignored
        ])->assertRedirect('https://checkout.paystack.com/abc');

        $t = Transaction::sole();
        $this->assertSame($this->termFee->id, $t->subcategory_id);
        $this->assertSame('First Term Tuition', $t->subcategory_name);
        $this->assertEquals(50000.00, (float) $t->fee_amount);
        $this->assertEquals(51250.00, (float) $t->amount);
    }

    private function errorBag(string $field, string $message): \Illuminate\Support\ViewErrorBag
    {
        $bag = new \Illuminate\Support\ViewErrorBag;
        $bag->put('default', new \Illuminate\Support\MessageBag([$field => [$message]]));

        return $bag;
    }
}
