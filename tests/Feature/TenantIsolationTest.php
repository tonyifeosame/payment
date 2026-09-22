<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\School;
use App\Models\Subcategory;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression tests for F1 — cross-tenant access.
 *
 * A school admin must only ever reach their own school's categories,
 * subcategories and transactions, at the server level.
 */
class TenantIsolationTest extends TestCase
{
    use RefreshDatabase;

    private School $alpha;

    private School $beta;

    private Category $betaCategory;

    private Subcategory $betaSubcategory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');

        $this->betaCategory = Category::create([
            'name' => 'BetaOnlyCategory',
            'school_id' => $this->beta->id,
        ]);

        $this->betaSubcategory = Subcategory::create([
            'category_id' => $this->betaCategory->id,
            'name' => 'BetaOnlySubcategory',
            'price' => 80000,
            'school_id' => $this->beta->id,
        ]);

        Transaction::create([
            'school_id' => $this->beta->id,
            'reference' => 'beta-txn-1',
            'amount' => 82000,
            'status' => 'success',
            'email' => 'betaparent@private.test',
            'name' => 'BetaPayerName',
            'meta_data' => ['base_amount' => 80000],
        ]);
    }

    private function makeSchool(string $name, string $slug): School
    {
        return School::create([
            'name' => $name,
            'slug' => $slug,
            'email' => $slug.'@example.test',
            'admin_password' => Hash::make('password123'),
            'account_number' => '0123456789',
            'bank' => 'GTB',
            'bank_code' => '058',
            'account_name' => 'Acct '.$name,
        ]);
    }

    private function actingAsAlpha(): self
    {
        $this->withSession(\App\Support\SchoolSession::payloadFor($this->alpha));

        return $this;
    }

    // ---------------------------------------------------------------
    // (a) School A cannot read School B transactions
    // ---------------------------------------------------------------

    public function test_school_a_cannot_open_school_b_transactions_page(): void
    {
        $this->actingAsAlpha()
            ->get('/admin/beta/transactions')
            ->assertNotFound();
    }

    public function test_school_a_own_transaction_list_never_contains_school_b_data(): void
    {
        Transaction::create([
            'school_id' => $this->alpha->id,
            'reference' => 'alpha-txn-1',
            'amount' => 10000,
            'status' => 'success',
            'email' => 'alphaparent@example.test',
            'name' => 'AlphaPayerName',
            'meta_data' => ['base_amount' => 10000],
        ]);

        $response = $this->actingAsAlpha()->get('/admin/alpha/transactions');

        $response->assertOk();
        $response->assertSee('AlphaPayerName');
        $response->assertDontSee('BetaPayerName');
        $response->assertDontSee('betaparent@private.test');
    }

    public function test_school_a_cannot_reach_school_b_transactions_through_search(): void
    {
        // The search form used to post to a global, un-scoped route.
        // NB: the query itself is echoed back into the search input, so assert on
        // fields that only appear when a matching row is actually rendered.
        $response = $this->actingAsAlpha()->get('/admin/alpha/transactions?q=BetaPayerName');

        $response->assertOk();
        $response->assertDontSee('betaparent@private.test');
        $response->assertDontSee('beta-txn-1');
        $response->assertSee('No transactions');
    }

    // ---------------------------------------------------------------
    // (b) School A cannot modify or delete School B categories
    // ---------------------------------------------------------------

    public function test_school_a_cannot_view_school_b_category_edit_page(): void
    {
        $this->actingAsAlpha()
            ->get("/admin/alpha/categories/{$this->betaCategory->id}/edit")
            ->assertNotFound();
    }

    public function test_school_a_cannot_update_school_b_category(): void
    {
        $this->actingAsAlpha()
            ->put("/admin/alpha/categories/{$this->betaCategory->id}", ['name' => 'Hijacked'])
            ->assertNotFound();

        $this->assertDatabaseHas('categories', [
            'id' => $this->betaCategory->id,
            'name' => 'BetaOnlyCategory',
        ]);
    }

    public function test_school_a_cannot_delete_school_b_category(): void
    {
        $this->actingAsAlpha()
            ->delete("/admin/alpha/categories/{$this->betaCategory->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('categories', ['id' => $this->betaCategory->id]);
    }

    public function test_school_a_cannot_delete_school_b_category_via_school_b_prefix(): void
    {
        // Swapping the school segment must not help: the middleware rejects it.
        $this->actingAsAlpha()
            ->delete("/admin/beta/categories/{$this->betaCategory->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('categories', ['id' => $this->betaCategory->id]);
    }

    public function test_school_a_cannot_update_or_delete_school_b_subcategory(): void
    {
        $this->actingAsAlpha()
            ->put("/admin/alpha/subcategories/{$this->betaSubcategory->id}", [
                'category_id' => $this->betaCategory->id,
                'name' => 'Hijacked',
                'price' => 1,
            ])
            ->assertNotFound();

        $this->actingAsAlpha()
            ->delete("/admin/alpha/subcategories/{$this->betaSubcategory->id}")
            ->assertNotFound();

        $this->assertDatabaseHas('subcategories', [
            'id' => $this->betaSubcategory->id,
            'name' => 'BetaOnlySubcategory',
        ]);
    }

    public function test_school_a_cannot_attach_a_subcategory_to_school_b_category(): void
    {
        // category_id passes `exists:categories,id` but belongs to another school.
        $this->actingAsAlpha()
            ->post('/admin/alpha/subcategories', [
                'category_id' => $this->betaCategory->id,
                'name' => 'Smuggled',
                'price' => 500,
            ])
            ->assertNotFound();

        $this->assertDatabaseMissing('subcategories', ['name' => 'Smuggled']);
    }

    // ---------------------------------------------------------------
    // Positive cases: a school retains full control of its own records
    // ---------------------------------------------------------------

    public function test_school_can_manage_its_own_categories(): void
    {
        $this->actingAsAlpha()
            ->post('/admin/alpha/categories', ['name' => 'AlphaFees'])
            ->assertRedirect('/admin/alpha/categories');

        $own = Category::where('school_id', $this->alpha->id)->firstOrFail();
        $this->assertSame('AlphaFees', $own->name);

        $this->actingAsAlpha()->get('/admin/alpha/categories')->assertOk()->assertSee('AlphaFees');
        $this->actingAsAlpha()->get("/admin/alpha/categories/{$own->id}/edit")->assertOk();

        $this->actingAsAlpha()
            ->put("/admin/alpha/categories/{$own->id}", ['name' => 'AlphaFeesRenamed'])
            ->assertRedirect('/admin/alpha/categories');
        $this->assertDatabaseHas('categories', ['id' => $own->id, 'name' => 'AlphaFeesRenamed']);

        $this->actingAsAlpha()->delete("/admin/alpha/categories/{$own->id}")->assertRedirect('/admin/alpha/categories');
        $this->assertDatabaseMissing('categories', ['id' => $own->id]);
    }

    public function test_school_categories_page_shows_only_its_own_categories(): void
    {
        Category::create(['name' => 'AlphaOnlyCategory', 'school_id' => $this->alpha->id]);

        $this->actingAsAlpha()
            ->get('/admin/alpha/categories')
            ->assertOk()
            ->assertSee('AlphaOnlyCategory')
            ->assertDontSee('BetaOnlyCategory');
    }

    // ---------------------------------------------------------------
    // The removed un-scoped routes must stay gone
    // ---------------------------------------------------------------

    public static function removedGlobalRoutes(): array
    {
        return [
            'categories index' => ['GET', '/categories'],
            'categories store' => ['POST', '/categories'],
            'categories destroy' => ['DELETE', '/categories/1'],
            'subcategories index' => ['GET', '/subcategories'],
            'subcategories destroy' => ['DELETE', '/subcategories/1'],
            'transactions index' => ['GET', '/transactions'],
            'transactions store' => ['POST', '/transactions'],
            'global initialize' => ['POST', '/payment/initialize'],
        ];
    }

    /**
     * @dataProvider removedGlobalRoutes
     */
    public function test_removed_global_routes_no_longer_exist(string $method, string $uri): void
    {
        $this->actingAsAlpha()
            ->call($method, $uri)
            ->assertNotFound();
    }

    // ---------------------------------------------------------------
    // Anonymous access to management pages
    // ---------------------------------------------------------------

    public function test_anonymous_user_is_sent_to_login_for_management_pages(): void
    {
        $this->get('/admin/alpha/categories')->assertRedirect(route('admin.login'));
        $this->get('/admin/alpha/transactions')->assertRedirect(route('admin.login'));
    }

    public function test_session_referencing_a_deleted_school_is_rejected(): void
    {
        $this->withSession(['school_admin_id' => 999999])
            ->get('/admin/alpha/categories')
            ->assertRedirect(route('admin.login'));
    }

    // ---------------------------------------------------------------
    // The public parent payment flow must keep working
    // ---------------------------------------------------------------

    public function test_public_school_payment_page_is_reachable_without_login(): void
    {
        $category = Category::create(['name' => 'AlphaPublicFee', 'school_id' => $this->alpha->id]);
        // The category only reaches the form when the school has something payable
        // in it (L6); an empty category renders the empty state instead.
        Subcategory::create([
            'category_id' => $category->id, 'school_id' => $this->alpha->id,
            'name' => 'AlphaPublicSubcategory', 'price' => 50000,
        ]);

        $this->get('/s/alpha/payment')
            ->assertOk()
            ->assertSee('AlphaPublicFee');
    }

    public function test_public_payment_page_shows_only_that_schools_fees(): void
    {
        $category = Category::create(['name' => 'AlphaPublicFee', 'school_id' => $this->alpha->id]);
        Subcategory::create([
            'category_id' => $category->id, 'school_id' => $this->alpha->id,
            'name' => 'AlphaPublicSubcategory', 'price' => 50000,
        ]);

        // Asserted positively as well as negatively: without a payable fee of its
        // own, alpha's page would render the empty state (L6) and beta's category
        // would be absent because NOTHING rendered — which would prove nothing
        // about tenant scoping.
        $this->get('/s/alpha/payment')
            ->assertOk()
            ->assertSee('AlphaPublicFee')
            ->assertSee('AlphaPublicSubcategory')
            ->assertDontSee('BetaOnlyCategory')
            ->assertDontSee('BetaOnlySubcategory');
    }

    public function test_public_payment_initialize_route_still_accepts_parents(): void
    {
        // Reaches validation rather than auth: the endpoint is still public.
        $this->post('/s/alpha/payment/initialize', [])
            ->assertSessionHasErrors(['email', 'category_id', 'subcategory_id', 'quantity']);
    }

    public function test_parent_cannot_pay_school_a_using_school_b_fees(): void
    {
        $this->post('/s/alpha/payment/initialize', [
            'email' => 'parent@example.test',
            'name' => 'Parent',
            'category_id' => $this->betaCategory->id,
            'subcategory_id' => $this->betaSubcategory->id,
            'quantity' => 1,
        ])->assertNotFound();

        $this->assertDatabaseCount('transactions', 1); // only the seeded Beta transaction
    }
}
