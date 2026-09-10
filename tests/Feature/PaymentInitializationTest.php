<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\School;
use App\Models\Subcategory;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Regression tests for the checkout hand-off to Paystack.
 *
 * PaymentController::initializeSchool ends with a deliberate
 * `back()->with('error', 'Unable to initialize payment.')`, but that line was
 * unreachable: the request used `->retry(3, 200)`, and retry() re-throws once the
 * attempts are exhausted. Every Paystack-side failure — an expired key, a rate
 * limit, an outage — therefore reached the payer as a 500 instead of the message
 * the method is written to return, and nothing in the suite noticed.
 *
 * These tests pin the payer-visible behaviour for each way the call can fail.
 */
class PaymentInitializationTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    private Category $category;

    private Subcategory $subcategory;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_secret']);

        $this->school = School::create([
            'name' => 'Alpha School',
            'slug' => 'alpha',
            'email' => 'alpha@example.test',
            'admin_password' => Hash::make('secret-password'),
        ]);

        $this->category = Category::create([
            'school_id' => $this->school->id,
            'name' => 'School Fees',
        ]);

        $this->subcategory = Subcategory::create([
            'school_id' => $this->school->id,
            'category_id' => $this->category->id,
            'name' => 'Primary',
            'price' => 50000,
        ]);
    }

    private function submitPayment()
    {
        return $this->post('/s/alpha/payment/initialize', [
            'email' => 'payer@example.test',
            'name' => 'Alpha Payer',
            'category_id' => $this->category->id,
            'subcategory_id' => $this->subcategory->id,
            'quantity' => 1,
        ]);
    }

    public function test_a_successful_initialization_redirects_to_paystack(): void
    {
        Http::fake([
            '*/transaction/initialize' => Http::response([
                'status' => true,
                'data' => ['authorization_url' => 'https://checkout.paystack.com/abc123'],
            ]),
        ]);

        $this->submitPayment()->assertRedirect('https://checkout.paystack.com/abc123');

        // The charge is the fee plus the configured markup, recorded before we hand off.
        $transaction = Transaction::firstOrFail();
        $this->assertSame('pending', $transaction->status);
        $this->assertEqualsWithDelta(51250.00, (float) $transaction->amount, 0.001);
    }

    public function test_a_rejected_paystack_response_is_shown_to_the_payer_not_thrown(): void
    {
        // An expired or wrong secret key looks exactly like this.
        Http::fake([
            '*/transaction/initialize' => Http::response(['status' => false], 401),
        ]);

        $this->submitPayment()
            ->assertRedirect()
            ->assertSessionHas('error', 'Unable to initialize payment.');
    }

    public function test_a_paystack_server_error_is_shown_to_the_payer_not_thrown(): void
    {
        Http::fake([
            '*/transaction/initialize' => Http::response('gateway down', 500),
        ]);

        $this->submitPayment()
            ->assertRedirect()
            ->assertSessionHas('error', 'Unable to initialize payment.');
    }

    public function test_a_network_failure_is_shown_to_the_payer_not_thrown(): void
    {
        // retry()'s throw flag only suppresses a failed response; a DNS or TCP
        // failure raises ConnectionException, which needs the try/catch.
        Http::fake(fn () => throw new ConnectionException('Connection timed out'));

        $this->submitPayment()
            ->assertRedirect()
            ->assertSessionHas('error', 'Unable to initialize payment.');
    }

    public function test_a_failed_initialization_leaves_the_transaction_pending_and_unsettled(): void
    {
        Http::fake([
            '*/transaction/initialize' => Http::response(['status' => false], 401),
        ]);

        $this->submitPayment();

        // The row is deliberately kept: it is the reference we would reconcile
        // against if the payer was in fact charged. It must never look settled.
        $transaction = Transaction::firstOrFail();
        $this->assertSame('pending', $transaction->status);
        $this->assertSame(0, $transaction->payout()->count());
    }
}
