<?php

namespace Tests\Feature;

use App\Models\AcademicTerm;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\Subcategory;
use App\Models\Transaction;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * L1 — whether a fee may be bought in multiples is the fee's own setting
 * (`subcategories.allows_quantity`), not something inferred from whether its
 * category's name contains "school fee".
 */
class FeeQuantityTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const CHECKOUT_URL = 'https://checkout.paystack.com/l1';

    private School $alpha;

    private AcademicTerm $term;

    /** A general fee that allows multiple units: ₦3,000 each. */
    private Subcategory $shirt;

    /** A term fee charged once: ₦50,000. */
    private Subcategory $tuition;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_secret', 'fees.markup_percent' => 2.5]);
        Http::fake(['*/transaction/initialize' => Http::response([
            'status' => true, 'data' => ['authorization_url' => self::CHECKOUT_URL],
        ])]);

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->term = $this->makeSessionWithTerms($this->alpha, '2026/2027')->terms()->where('number', 1)->firstOrFail();
        $this->shirt = $this->makeFee($this->alpha, 'Uniform', 'Shirt', 3000, null, allowsQuantity: true);
        $this->tuition = $this->makeFee($this->alpha, 'Tuition', 'First Term Tuition', 50000, $this->term->id);
    }

    private function admin(): static
    {
        return $this->actingAsSchoolAdmin($this->alpha);
    }

    private function pay(Subcategory $fee, int|string $quantity): TestResponse
    {
        return $this->from('/pay/alpha')->post('/pay/alpha/initialize', [
            'email' => 'parent@example.test',
            'name' => 'Ada Parent',
            'category_id' => $fee->category_id,
            'subcategory_id' => $fee->id,
            'quantity' => $quantity,
            'academic_session_id' => $this->term->academic_session_id,
            'academic_term_id' => $this->term->id,
        ]);
    }

    private function assertRefused(TestResponse $response): void
    {
        $response->assertRedirect('/pay/alpha')->assertSessionHasErrors('quantity');
        $this->assertSame(0, Transaction::count());
        Http::assertNothingSent();
    }

    // ------------------------------------------------------------------ admin

    public function test_a_new_fee_defaults_to_a_single_charge(): void
    {
        $this->admin()->get('/admin/alpha/subcategories/create')->assertOk()
            ->assertSee('Allow multiple units')
            ->assertSee('such as uniforms or books')
            ->assertDontSee('aria-describedby="allows_quantity-help" checked', false);

        $this->admin()->post('/admin/alpha/subcategories', [
            'category_id' => $this->shirt->category_id, 'name' => 'Tie', 'price' => 1500,
        ])->assertRedirect('/admin/alpha/subcategories');

        $this->assertFalse(Subcategory::where('name', 'Tie')->sole()->allows_quantity);
    }

    public function test_a_term_fee_defaults_to_a_single_charge(): void
    {
        $this->admin()->post('/admin/alpha/subcategories', [
            'category_id' => $this->tuition->category_id, 'name' => 'Second Term Tuition', 'price' => 50000,
            'academic_term_id' => $this->term->id,
        ])->assertRedirect('/admin/alpha/subcategories');

        $this->assertFalse(Subcategory::where('name', 'Second Term Tuition')->sole()->allows_quantity);
        $this->assertFalse($this->tuition->fresh()->allows_quantity);
    }

    public function test_the_admin_can_enable_multiple_units(): void
    {
        $this->admin()->post('/admin/alpha/subcategories', [
            'category_id' => $this->shirt->category_id, 'name' => 'Textbook', 'price' => 4500, 'allows_quantity' => '1',
        ])->assertRedirect('/admin/alpha/subcategories');
        $this->assertTrue(Subcategory::where('name', 'Textbook')->sole()->allows_quantity);

        $this->admin()->put("/admin/alpha/subcategories/{$this->tuition->id}", [
            'category_id' => $this->tuition->category_id, 'name' => 'First Term Tuition', 'price' => 50000,
            'academic_term_id' => $this->term->id, 'allows_quantity' => '1',
        ])->assertRedirect('/admin/alpha/subcategories');
        $this->assertTrue($this->tuition->fresh()->allows_quantity);

        $this->admin()->get("/admin/alpha/subcategories/{$this->tuition->id}/edit")->assertOk()
            ->assertSee('aria-describedby="allows_quantity-help" checked', false);
    }

    public function test_the_admin_can_disable_multiple_units(): void
    {
        $this->admin()->get("/admin/alpha/subcategories/{$this->shirt->id}/edit")->assertOk()
            ->assertSee('aria-describedby="allows_quantity-help" checked', false);

        // An unticked checkbox is simply absent from the submitted form.
        $this->admin()->put("/admin/alpha/subcategories/{$this->shirt->id}", [
            'category_id' => $this->shirt->category_id, 'name' => 'Shirt', 'price' => 3000,
        ])->assertRedirect('/admin/alpha/subcategories');

        $this->assertFalse($this->shirt->fresh()->allows_quantity);
        $this->assertRefused($this->pay($this->shirt->fresh(), 2));
    }

    public function test_an_invalid_setting_is_refused(): void
    {
        $this->admin()->from("/admin/alpha/subcategories/{$this->shirt->id}/edit")
            ->put("/admin/alpha/subcategories/{$this->shirt->id}", [
                'category_id' => $this->shirt->category_id, 'name' => 'Shirt', 'price' => 3000, 'allows_quantity' => 'sometimes',
            ])->assertSessionHasErrors('allows_quantity');

        $this->assertTrue($this->shirt->fresh()->allows_quantity);
    }

    public function test_the_fee_list_marks_fees_that_allow_multiple_units(): void
    {
        $this->admin()->get('/admin/alpha/subcategories')->assertOk()
            ->assertSeeInOrder(['First Term Tuition', 'Shirt', '₦3,000.00', 'Per unit · multiple allowed']);

        $this->shirt->update(['allows_quantity' => false]);
        $this->admin()->get('/admin/alpha/subcategories')->assertOk()->assertDontSee('Per unit · multiple allowed');
    }

    // --------------------------------------------------------------- checkout

    public function test_a_single_charge_fee_rejects_quantity_two(): void
    {
        $response = $this->pay($this->tuition, 2);

        $this->assertRefused($response);
        $this->assertSame(
            'This fee is a single charge, so the quantity must be 1.',
            session('errors')->first('quantity'),
        );
    }

    public function test_a_single_charge_fee_accepts_quantity_one(): void
    {
        $this->pay($this->tuition, 1)->assertRedirect(self::CHECKOUT_URL);

        $t = Transaction::sole();
        $this->assertEquals(50000.00, (float) $t->fee_amount);
        $this->assertEquals(51250.00, (float) $t->amount);
        $this->assertSame(1, $t->decodedMetaData()['quantity']);
    }

    public function test_a_quantity_enabled_fee_accepts_quantity_two(): void
    {
        $this->pay($this->shirt, 2)->assertRedirect(self::CHECKOUT_URL);

        // price × quantity, plus the unchanged 2.5% service fee on that subtotal.
        $t = Transaction::sole();
        $this->assertEquals(6000.00, (float) $t->fee_amount);
        $this->assertEquals(150.00, (float) $t->service_fee);
        $this->assertEquals(6150.00, (float) $t->amount);
        $this->assertSame(2, $t->decodedMetaData()['quantity']);

        Http::assertSent(fn ($request) => $request['amount'] === 615000
            && $request['metadata']['quantity'] === 2);
    }

    public function test_quantity_zero_is_rejected(): void
    {
        $this->assertRefused($this->pay($this->shirt, 0));
        $this->assertRefused($this->pay($this->tuition, 0));
    }

    public function test_quantity_101_is_rejected(): void
    {
        $this->assertRefused($this->pay($this->shirt, 101));
        $this->assertRefused($this->pay($this->tuition, 101));
    }

    public function test_the_category_name_no_longer_controls_quantity(): void
    {
        // Named "School Fees" but set to allow units: the old rule would have forced 1.
        $levy = $this->makeFee($this->alpha, 'School Fees', 'Sports Kit', 2000, null, allowsQuantity: true);
        $this->pay($levy, 3)->assertRedirect(self::CHECKOUT_URL);
        $this->assertEquals(6000.00, (float) Transaction::sole()->fee_amount);

        // Named "Tuition" and single-charge: the old rule would have allowed up to 100.
        $this->assertSame('Tuition', $this->tuition->category->name);
        $this->pay($this->tuition, 3)->assertRedirect('/pay/alpha')->assertSessionHasErrors('quantity');
        $this->assertSame(1, Transaction::count());
    }

    public function test_renaming_a_category_does_not_change_quantity_behaviour(): void
    {
        $this->admin()->put("/admin/alpha/categories/{$this->shirt->category_id}", ['name' => 'School Fees Extras'])->assertRedirect();
        $this->admin()->put("/admin/alpha/categories/{$this->tuition->category_id}", ['name' => 'Levies'])->assertRedirect();

        $this->assertTrue($this->shirt->fresh()->allows_quantity);
        $this->assertFalse($this->tuition->fresh()->allows_quantity);

        $this->pay($this->shirt, 2)->assertRedirect(self::CHECKOUT_URL);
        $this->pay($this->tuition, 2)->assertRedirect('/pay/alpha')->assertSessionHasErrors('quantity');
        $this->assertSame(1, Transaction::count());
    }

    // ------------------------------------------------------------------ audit

    public function test_the_audit_trail_records_allows_quantity_changes(): void
    {
        $this->admin()->post('/admin/alpha/subcategories', [
            'category_id' => $this->shirt->category_id, 'name' => 'Textbook', 'price' => 4500, 'allows_quantity' => '1',
        ])->assertRedirect();
        $fee = Subcategory::where('name', 'Textbook')->sole();

        $created = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_FEE_CREATED)->sole();
        $this->assertSame(['from' => null, 'to' => true], $created->changes['allows_quantity']);

        // Only the setting moved, so only the setting is recorded.
        $this->admin()->put("/admin/alpha/subcategories/{$fee->id}", [
            'category_id' => $fee->category_id, 'name' => 'Textbook', 'price' => 4500,
        ])->assertRedirect();

        $updated = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_FEE_UPDATED)->sole();
        $this->assertSame('subcategory', $updated->subject_type);
        $this->assertSame($fee->id, $updated->subject_id);
        $this->assertSame(['allows_quantity' => ['from' => true, 'to' => false]], $updated->changes);

        // Re-saving the same value is not a change.
        $this->admin()->put("/admin/alpha/subcategories/{$fee->id}", [
            'category_id' => $fee->category_id, 'name' => 'Textbook', 'price' => 4500,
        ])->assertRedirect();
        $this->assertSame(1, SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_FEE_UPDATED)->count());

        // Existing fields are still audited alongside it.
        $this->admin()->put("/admin/alpha/subcategories/{$fee->id}", [
            'category_id' => $fee->category_id, 'name' => 'Textbook Pack', 'price' => 5000, 'allows_quantity' => '1',
        ])->assertRedirect();
        $latest = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_FEE_UPDATED)->latest('id')->first();
        $this->assertEqualsCanonicalizing(['name', 'price', 'allows_quantity'], array_keys($latest->changes));
        $this->assertSame(['from' => false, 'to' => true], $latest->changes['allows_quantity']);

        $this->admin()->delete("/admin/alpha/subcategories/{$fee->id}")->assertRedirect();
        $deleted = SchoolAuditEvent::where('action', SchoolAuditEvent::ACTION_FEE_DELETED)->sole();
        $this->assertSame(['from' => true, 'to' => null], $deleted->changes['allows_quantity']);
    }

    // -------------------------------------------------------------- public UI

    public function test_the_public_page_receives_each_fees_allows_quantity_state(): void
    {
        $page = $this->get('/pay/alpha')->assertOk();

        $page->assertSee('"id":'.$this->shirt->id.',"name":"Shirt","price":3000,"term_id":null,"allows_quantity":true', false)
            ->assertSee('"id":'.$this->tuition->id.',"name":"First Term Tuition","price":50000,"term_id":'.$this->term->id.',"allows_quantity":false', false)
            ->assertSee('max="100"', false)
            ->assertSee('const maxQuantity = 100;', false)
            ->assertSee("getAttribute('data-allows-quantity') === '1'", false)
            ->assertDontSee('school fee');
    }

    // ------------------------------------------------------------- history

    public function test_existing_transactions_keep_their_quantity_and_receipt(): void
    {
        // A payment made while the fee still allowed multiple units…
        $t = $this->makeSuccessfulTransaction($this->alpha, [
            'reference' => 'ref-three-shirts', 'category_id' => $this->shirt->category_id, 'subcategory_id' => $this->shirt->id,
            'category_name' => 'Uniform', 'subcategory_name' => 'Shirt',
            'fee_amount' => 9000, 'service_fee' => 225, 'amount' => 9225,
            'meta_data' => ['quantity' => 3, 'base_amount' => 9000, 'markup_amount' => 225],
        ]);

        // …is unaffected by the fee later becoming a single charge.
        $this->admin()->put("/admin/alpha/subcategories/{$this->shirt->id}", [
            'category_id' => $this->shirt->category_id, 'name' => 'Shirt', 'price' => 3000,
        ])->assertRedirect();

        $t->refresh();
        $this->assertEquals(9225.00, (float) $t->amount);
        $this->assertSame(3, $t->decodedMetaData()['quantity']);
        $b = $t->receiptBreakdown();
        $this->assertSame(3, $b['quantity']);
        $this->assertSame(3000.00, $b['unit_price']);
        $this->assertSame(9225.00, $b['total']);

        $this->admin()->get("/admin/alpha/transactions/{$t->id}")->assertOk()->assertSee('3 × ₦3,000.00');
    }

    // --------------------------------------------------------------- backfill

    private function runBackfill(): void
    {
        $migration = require base_path('database/migrations/2026_09_26_000000_add_allows_quantity_to_subcategories_table.php');
        (new \ReflectionMethod($migration, 'backfill'))->invoke($migration);
    }

    public function test_the_backfill_keeps_general_fees_multi_unit_and_makes_term_fees_single_charge(): void
    {
        $other = $this->makeSchool('Beta School', 'beta');
        $betaTerm = $this->makeSessionWithTerms($other, '2026/2027')->terms()->firstOrFail();

        $fees = [
            // label => [fee, expected after backfill]
            'general, Uniform' => [$this->shirt, true],
            'term, Tuition (was quantity-enabled by name)' => [$this->tuition, false],
            'general, School Fees' => [$this->makeFee($this->alpha, 'School Fees', 'Registration', 5000), false],
            'term, School Fees' => [$this->makeFee($this->alpha, 'School Fees', 'JSS 1 First Term', 60000, $this->term->id), false],
            'general, upper-case SCHOOL FEES' => [$this->makeFee($this->alpha, 'OLD SCHOOL FEES', 'Levy', 1000), false],
            'general, "Fees" (no "school fee")' => [$this->makeFee($this->alpha, 'Fees', 'Books', 4000), true],
            'general draft, no price' => [Subcategory::create(['school_id' => $this->alpha->id, 'category_id' => $this->shirt->category_id, 'name' => 'Blazer', 'price' => null]), true],
            'other school, general' => [$this->makeFee($other, 'Uniform', 'Beta Shirt', 2500), true],
            'other school, term' => [$this->makeFee($other, 'Tuition', 'Beta Tuition', 40000, $betaTerm->id), false],
        ];

        $paid = $this->makeSuccessfulTransaction($this->alpha, [
            'subcategory_id' => $this->tuition->id, 'category_id' => $this->tuition->category_id,
            'meta_data' => ['quantity' => 3, 'base_amount' => 150000, 'markup_amount' => 3750],
            'fee_amount' => 150000, 'service_fee' => 3750, 'amount' => 153750,
        ]);

        // The state straight after the column is added: every fee single-charge.
        DB::table('subcategories')->update(['allows_quantity' => false]);
        $transactionsBefore = DB::table('transactions')->orderBy('id')->get()->toArray();
        $pricesBefore = DB::table('subcategories')->orderBy('id')->pluck('price', 'id')->all();

        $this->runBackfill();

        foreach ($fees as $label => [$fee, $expected]) {
            $this->assertSame($expected, $fee->fresh()->allows_quantity, $label);
        }

        // Nothing but the new column moves: transactions and prices are untouched.
        $this->assertEquals($transactionsBefore, DB::table('transactions')->orderBy('id')->get()->toArray());
        $this->assertEquals($pricesBefore, DB::table('subcategories')->orderBy('id')->pluck('price', 'id')->all());
        $this->assertSame(3, $paid->fresh()->decodedMetaData()['quantity']);
    }

    public function test_the_backfill_preserves_current_multi_unit_checkout_for_general_fees(): void
    {
        DB::table('subcategories')->update(['allows_quantity' => false]);
        $this->runBackfill();

        $this->pay($this->shirt->fresh(), 2)->assertRedirect(self::CHECKOUT_URL);
        $this->assertEquals(6000.00, (float) Transaction::sole()->fee_amount);

        $this->pay($this->tuition->fresh(), 2)->assertRedirect('/pay/alpha')->assertSessionHasErrors('quantity');
    }

    public function test_the_demo_seeder_keeps_uniforms_multi_unit_and_term_fees_single_charge(): void
    {
        $this->seed(DemoSeeder::class);

        $demo = School::where('slug', DemoSeeder::SCHOOL_SLUG)->sole();
        $settings = Subcategory::where('school_id', $demo->id)->pluck('allows_quantity', 'name')->all();

        $this->assertSame([
            'Primary - First Term' => false,
            'Secondary - First Term' => false,
            'Shirt' => true,
            'Trousers' => true,
        ], collect($settings)->sortKeys()->all());

        // Re-seeding is idempotent and restores the intended setting.
        Subcategory::where('school_id', $demo->id)->update(['allows_quantity' => false]);
        $this->seed(DemoSeeder::class);
        $this->assertTrue(Subcategory::where('school_id', $demo->id)->where('name', 'Shirt')->sole()->allows_quantity);
    }

    public function test_the_column_defaults_to_false_at_the_database_level(): void
    {
        $id = DB::table('subcategories')->insertGetId([
            'school_id' => $this->alpha->id, 'category_id' => $this->shirt->category_id,
            'name' => 'Raw insert', 'price' => 100, 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertFalse(Subcategory::findOrFail($id)->allows_quantity);
    }
}
