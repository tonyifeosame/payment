<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\School;
use App\Models\Subcategory;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * M4 — a fee with no amount set is a draft, and a draft is not payable.
 *
 * Schools are meant to be able to create a fee type before deciding the figure;
 * the admin list shows those as "Not set" and FeeSetupTest locks that in. What
 * must not happen is a parent being offered one: the price cast to 0.0, the page
 * rendered "₦0", a zero-value pending row was written and Paystack was asked to
 * charge nothing — so the only thing refusing the payment was the provider.
 *
 * Two protections, tested separately because they answer different questions:
 * the public page never offers such a fee, and the checkout service refuses it
 * even when the id is posted directly.
 */
class UnpricedFeeCheckoutTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const CANONICAL = '/pay/alpha/initialize';

    private const LEGACY = '/s/alpha/payment/initialize';

    private const CHECKOUT_URL = 'https://checkout.paystack.com/abc';

    private School $school;

    private Subcategory $priced;

    private Subcategory $unpriced;

    private Subcategory $zeroPriced;

    private Category $draftsOnly;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_secret']);

        $this->school = $this->makeSchool('Alpha School', 'alpha');

        // One category holding a real fee alongside two drafts.
        $this->priced = $this->makeFee($this->school, 'Uniform', 'Shirt', 3000);
        $category = $this->priced->category;
        $this->unpriced = Subcategory::create([
            'school_id' => $this->school->id, 'category_id' => $category->id,
            'name' => 'Blazer (amount pending)', 'price' => null,
        ]);
        $this->zeroPriced = Subcategory::create([
            'school_id' => $this->school->id, 'category_id' => $category->id,
            'name' => 'Free Tie', 'price' => 0,
        ]);

        // A second category whose every fee is still a draft.
        $this->draftsOnly = Category::create(['school_id' => $this->school->id, 'name' => 'Excursions']);
        Subcategory::create([
            'school_id' => $this->school->id, 'category_id' => $this->draftsOnly->id,
            'name' => 'Zoo Trip (amount pending)', 'price' => null,
        ]);

        Http::fake(['*/transaction/initialize' => Http::response([
            'status' => true, 'data' => ['authorization_url' => self::CHECKOUT_URL],
        ])]);
    }

    private function submit(Subcategory $fee, string $uri = self::CANONICAL, int $quantity = 1): TestResponse
    {
        return $this->post($uri, [
            'email' => 'parent@example.test',
            'name' => 'Ada Parent',
            'category_id' => $fee->category_id,
            'subcategory_id' => $fee->id,
            'quantity' => $quantity,
        ]);
    }

    /** The fee payload the page actually hands the browser. */
    private function feesOnPage(string $uri = '/pay/alpha'): array
    {
        $response = $this->get($uri)->assertOk();

        return collect($response->viewData('categoriesForJs'))
            ->flatMap(fn ($c) => collect($c['subcategories'])->pluck('name'))
            ->all();
    }

    // ------------------------------------------------ the public page (B)

    public function test_the_public_page_offers_priced_fees_only(): void
    {
        $fees = $this->feesOnPage();

        $this->assertSame(['Shirt'], $fees, 'a fee with no amount set was offered to a parent');
    }

    public function test_the_draft_fee_names_never_reach_the_browser(): void
    {
        $this->get('/pay/alpha')
            ->assertOk()
            ->assertSee('Shirt')
            ->assertDontSee('Blazer (amount pending)')
            ->assertDontSee('Free Tie')
            ->assertDontSee('Zoo Trip (amount pending)');
    }

    public function test_the_legacy_payment_page_filters_identically(): void
    {
        $this->assertSame(['Shirt'], $this->feesOnPage('/s/alpha/payment'));
    }

    public function test_a_category_whose_fees_are_all_drafts_is_left_with_an_empty_fee_list(): void
    {
        $response = $this->get('/pay/alpha')->assertOk();

        // The category itself still renders — the page's own empty state ("No fees
        // in this category for the selected term.") is what the parent sees on
        // choosing it, rather than a "₦0" option.
        $response->assertSee('Excursions');

        $excursions = collect($response->viewData('categoriesForJs'))
            ->firstWhere('name', 'Excursions');

        $this->assertNotNull($excursions, 'the category disappeared rather than emptying');
        $this->assertSame([], $excursions['subcategories']->all());
    }

    // -------------------------------------------- the checkout service (A)

    public function test_posting_an_unpriced_fee_is_refused_before_any_row_or_paystack_call(): void
    {
        $this->from('/pay/alpha')
            ->submit($this->unpriced)
            ->assertRedirect('/pay/alpha')
            ->assertSessionHasErrors(['subcategory_id' => 'This fee does not have an amount set yet. Please contact the school.']);

        $this->assertSame(0, Transaction::count(), 'a zero-value pending transaction was created');
        Http::assertNothingSent();
    }

    public function test_posting_a_zero_priced_fee_is_refused_before_any_row_or_paystack_call(): void
    {
        $this->from('/pay/alpha')
            ->submit($this->zeroPriced)
            ->assertRedirect('/pay/alpha')
            ->assertSessionHasErrors('subcategory_id');

        $this->assertSame(0, Transaction::count());
        Http::assertNothingSent();
    }

    public function test_a_negative_price_written_around_the_admin_form_is_refused_too(): void
    {
        // The controller validates min:0, but the column is a plain signed decimal,
        // so a legacy or hand-edited row can hold a negative amount.
        DB::table('subcategories')->where('id', $this->priced->id)->update(['price' => -5000]);

        $this->from('/pay/alpha')
            ->submit($this->priced->fresh())
            ->assertRedirect('/pay/alpha')
            ->assertSessionHasErrors('subcategory_id');

        $this->assertSame(0, Transaction::count());
        Http::assertNothingSent();
    }

    public function test_the_legacy_initialize_route_refuses_a_draft_fee_identically(): void
    {
        $this->from('/s/alpha/payment')
            ->submit($this->unpriced, self::LEGACY)
            ->assertRedirect('/s/alpha/payment')
            ->assertSessionHasErrors('subcategory_id');

        $this->assertSame(0, Transaction::count());
        Http::assertNothingSent();
    }

    public function test_a_quantity_multiplier_cannot_rescue_a_draft_fee(): void
    {
        $this->from('/pay/alpha')
            ->submit($this->unpriced, self::CANONICAL, 100)
            ->assertRedirect('/pay/alpha')
            ->assertSessionHasErrors('subcategory_id');

        $this->assertSame(0, Transaction::count());
        Http::assertNothingSent();
    }

    // --------------------------------------------------------- regression

    public function test_a_normally_priced_fee_still_checks_out(): void
    {
        $this->submit($this->priced)->assertRedirect(self::CHECKOUT_URL);

        $transaction = Transaction::sole();
        $this->assertSame('Shirt', $transaction->subcategory_name);
        $this->assertEqualsWithDelta(3000.00, (float) $transaction->fee_amount, 0.001);
        $this->assertGreaterThan(0, (float) $transaction->amount);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/transaction/initialize')
            && $request['amount'] > 0);
    }

    public function test_a_priced_fee_at_the_quantity_ceiling_still_checks_out(): void
    {
        $this->submit($this->priced, self::CANONICAL, 100)->assertRedirect(self::CHECKOUT_URL);

        $transaction = Transaction::sole();
        $this->assertEqualsWithDelta(300000.00, (float) $transaction->fee_amount, 0.001);
    }

    public function test_an_unpriced_fee_remains_a_valid_admin_draft(): void
    {
        // The complement of FeeSetupTest: hidden from parents, still listed for the
        // school with its "Not set" label, and still editable into a real fee.
        $this->actingAsSchoolAdmin($this->school)
            ->get('/admin/alpha/subcategories')
            ->assertOk()
            ->assertSeeInOrder(['Blazer (amount pending)', 'Not set']);

        $this->actingAsSchoolAdmin($this->school)
            ->put('/admin/alpha/subcategories/'.$this->unpriced->id, [
                'name' => 'Blazer',
                'category_id' => $this->unpriced->category_id,
                'price' => 12000,
            ])->assertRedirect();

        $this->assertEqualsWithDelta(12000.00, (float) $this->unpriced->fresh()->price, 0.001);

        // Priced, so it is now offered and payable.
        $this->assertContains('Blazer', $this->feesOnPage());
        $this->submit($this->unpriced->fresh())->assertRedirect(self::CHECKOUT_URL);
    }
}
