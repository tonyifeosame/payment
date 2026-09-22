<?php

namespace Tests\Feature;

use App\Mail\PaymentReceiptMail;
use App\Models\Category;
use App\Models\School;
use App\Models\Subcategory;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Batch 1 of the Low findings: three places where the page said something that
 * was not true.
 *
 *   L3  every receipt claimed to be "official proof of payment", including one
 *       for a payment that is still pending or has failed outright;
 *   L4  a failed receipt email during registration put the raw SMTP exception —
 *       host, port, provider error — on an unauthenticated registrant's screen;
 *   L6  a school with nothing payable rendered the full checkout form with empty
 *       dropdowns and no explanation. M4 widened this: fees without an amount are
 *       now filtered out of the form, so a school whose fees are all drafts hits
 *       it even though its categories are populated.
 */
class ReceiptProofAndEmptyStateTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const PROOF = 'official proof of payment';

    private const ATTEMPT = 'This is a record of a payment attempt, not proof of payment.';

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::preventStrayRequests();
        config(['services.paystack.secret_key' => 'sk_test_fake']);

        $this->school = $this->makeSchool('Alpha School', 'alpha');
    }

    private function transaction(string $status): Transaction
    {
        return $this->makeSuccessfulTransaction($this->school, [
            'status' => $status,
            'paid_at' => $status === 'success' ? now() : null,
        ]);
    }

    // ------------------------------------------------------------------ L3

    public function test_a_successful_receipt_is_still_proof_of_payment(): void
    {
        $transaction = $this->transaction('success');

        $this->actingAsSchoolAdmin($this->school)
            ->get('/payment/receipt/'.$transaction->id)
            ->assertOk()
            ->assertSee(self::PROOF)
            ->assertDontSee(self::ATTEMPT);
    }

    public function test_a_pending_receipt_does_not_claim_to_be_proof_of_payment(): void
    {
        $transaction = $this->transaction('pending');

        $this->actingAsSchoolAdmin($this->school)
            ->get('/payment/receipt/'.$transaction->id)
            ->assertOk()
            ->assertDontSee(self::PROOF)
            ->assertSee(self::ATTEMPT);
    }

    public function test_a_failed_receipt_does_not_claim_to_be_proof_of_payment(): void
    {
        $transaction = $this->transaction('failed');

        $this->actingAsSchoolAdmin($this->school)
            ->get('/payment/receipt/'.$transaction->id)
            ->assertOk()
            ->assertDontSee(self::PROOF)
            ->assertSee(self::ATTEMPT);
    }

    public function test_the_pdf_receipt_follows_the_same_rule(): void
    {
        foreach (['success' => true, 'pending' => false, 'failed' => false] as $status => $isProof) {
            $transaction = $this->transaction($status);

            $html = view('payment.receipt_pdf', [
                'transaction' => $transaction->fresh(),
                'school' => $this->school,
                'logoDataUri' => null,
            ])->render();

            $isProof
                ? $this->assertStringContainsString(self::PROOF, $html, "PDF for {$status} should be proof")
                : $this->assertStringNotContainsString(self::PROOF, $html, "PDF for {$status} must not claim proof");
        }
    }

    public function test_the_email_receipt_follows_the_same_rule(): void
    {
        foreach (['success' => true, 'pending' => false, 'failed' => false] as $status => $isProof) {
            $transaction = $this->transaction($status);

            $html = (new PaymentReceiptMail($transaction->fresh()))->render();

            $isProof
                ? $this->assertStringContainsString(self::PROOF, $html, "email for {$status} should be proof")
                : $this->assertStringNotContainsString(self::PROOF, $html, "email for {$status} must not claim proof");
        }
    }

    // ------------------------------------------------------------------ L4

    public function test_a_failed_links_email_never_shows_the_smtp_error_to_the_registrant(): void
    {
        Http::fake(['*/bank/resolve*' => Http::response([
            'status' => true,
            'data' => ['account_name' => 'NEW SCHOOL LTD', 'account_number' => '0123456789'],
        ], 200)]);

        Mail::shouldReceive('to')->once()->andReturnSelf();
        Mail::shouldReceive('send')->once()->andThrow(
            new \RuntimeException('Connection could not be established with host smtp.internal.example:587 — auth failed for user feyra-mailer')
        );

        $response = $this->post('/registration', [
            'name' => 'New School',
            'email' => 'new@example.test',
            'account_number' => '0123456789',
            'bank' => 'Guaranty Trust Bank',
            'bank_code' => '058',
            'admin_password' => 'password123',
            'admin_password_confirmation' => 'password123',
        ]);

        // Registration still succeeds — the mail is a courtesy, not the account.
        $response->assertRedirect('/admin/new-school/dashboard');
        $this->assertDatabaseHas('schools', ['slug' => 'new-school']);

        $flashed = (string) session('error');
        $this->assertStringNotContainsString('smtp.internal.example', $flashed);
        $this->assertStringNotContainsString('587', $flashed);
        $this->assertStringNotContainsString('feyra-mailer', $flashed);
        $this->assertStringNotContainsString('Connection could not be established', $flashed);
        $this->assertStringContainsString('could not email your links', $flashed);
    }

    // ------------------------------------------------------------------ L6

    public function test_a_school_with_no_fees_at_all_gets_an_explanation_not_an_empty_form(): void
    {
        $this->get('/pay/alpha')
            ->assertOk()
            ->assertSee('No fees are available to pay yet')
            ->assertSee('Alpha School has not published any fees for online payment.', false)
            ->assertDontSee('id="paymentForm"', false);
    }

    public function test_a_school_whose_fees_are_all_unpriced_drafts_gets_the_same_explanation(): void
    {
        // The case M4 introduced: the category exists and is populated, but every
        // fee in it is a draft, so nothing is payable.
        $category = Category::create(['school_id' => $this->school->id, 'name' => 'Uniform']);
        Subcategory::create(['school_id' => $this->school->id, 'category_id' => $category->id, 'name' => 'Blazer', 'price' => null]);
        Subcategory::create(['school_id' => $this->school->id, 'category_id' => $category->id, 'name' => 'Free Tie', 'price' => 0]);

        $this->get('/pay/alpha')
            ->assertOk()
            ->assertSee('No fees are available to pay yet')
            ->assertDontSee('id="paymentForm"', false);
    }

    public function test_one_priced_fee_is_enough_to_show_the_form(): void
    {
        $fee = $this->makeFee($this->school, 'Uniform', 'Shirt', 3000);

        // A draft alongside it must not tip the page into the empty state.
        Subcategory::create([
            'school_id' => $this->school->id, 'category_id' => $fee->category_id,
            'name' => 'Blazer', 'price' => null,
        ]);

        $this->get('/pay/alpha')
            ->assertOk()
            ->assertSee('id="paymentForm"', false)
            ->assertDontSee('No fees are available to pay yet');
    }

    public function test_the_legacy_payment_url_behaves_identically(): void
    {
        $this->get('/s/alpha/payment')
            ->assertOk()
            ->assertSee('No fees are available to pay yet')
            ->assertDontSee('id="paymentForm"', false);

        $this->makeFee($this->school, 'Uniform', 'Shirt', 3000);

        $this->get('/s/alpha/payment')
            ->assertOk()
            ->assertSee('id="paymentForm"', false)
            ->assertDontSee('No fees are available to pay yet');
    }

    public function test_the_empty_state_does_not_load_the_form_script(): void
    {
        // The script caches the form's elements unconditionally, so loading it
        // without the form would throw on every missing node.
        $this->get('/pay/alpha')
            ->assertOk()
            ->assertDontSee("getElementById('subcategory')", false);

        $this->makeFee($this->school, 'Uniform', 'Shirt', 3000);

        $this->get('/pay/alpha')
            ->assertOk()
            ->assertSee("getElementById('subcategory')", false);
    }

    public function test_the_school_contact_details_are_still_reachable_on_the_empty_state(): void
    {
        $this->school->forceFill(['phone' => '08012345678', 'address' => '1 Test Road'])->save();

        $this->get('/pay/alpha')
            ->assertOk()
            ->assertSee('No fees are available to pay yet')
            ->assertSee('08012345678')
            ->assertSee('1 Test Road');
    }
}
