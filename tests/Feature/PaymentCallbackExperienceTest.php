<?php

namespace Tests\Feature;

use App\Http\Controllers\PaymentController;
use App\Models\School;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Services\PaymentSettlementService;
use App\Services\PaystackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * What the payer sees on returning from Paystack (/payment/callback): an outcome-
 * specific message, the receipt only for the browser entitled to it, a finished
 * success state, and a public page when there is no payment to return to.
 */
class PaymentCallbackExperienceTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $school;

    private Subcategory $fee;

    private int $sessionId;

    private int $termId;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();

        $this->school = $this->makeSchool('Alpha School', 'alpha');
        $session = $this->makeSessionWithTerms($this->school);
        $this->sessionId = $session->id;
        $this->termId = $session->terms()->first()->id;
        $this->fee = $this->makeFee($this->school, 'School Fees', 'Tuition', 50000, $this->termId);
    }

    // ---------------------------------------------------------------- helpers

    private function pendingTransaction(string $reference): Transaction
    {
        return $this->makeSuccessfulTransaction($this->school, [
            'reference' => $reference, 'status' => 'pending', 'paid_at' => null,
            'amount' => 51250, 'fee_amount' => 50000, 'service_fee' => 1250,
        ]);
    }

    /** Paystack's verify answer for every reference in this test. */
    private function paystackReports(string $status, int $amountKobo = 5125000): void
    {
        $this->mock(PaystackService::class, fn ($m) => $m->shouldReceive('verifyTransaction')->andReturnUsing(fn ($ref) => [
            'ok' => true, 'status' => $status, 'amount' => $amountKobo, 'currency' => 'NGN',
            'channel' => 'card', 'reference' => $ref, 'gateway_response' => 'test', 'raw' => [],
        ]));
    }

    /** Start a checkout through the real endpoint (Paystack initialise faked). */
    private function startCheckout(): Transaction
    {
        Http::fake(['*/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.test/abc']])]);

        $this->post('/pay/alpha/initialize', [
            'email' => 'parent@example.test',
            'category_id' => $this->fee->category_id,
            'subcategory_id' => $this->fee->id,
            'quantity' => 1,
            'academic_session_id' => $this->sessionId,
            'academic_term_id' => $this->termId,
        ])->assertRedirect('https://checkout.paystack.test/abc');

        return Transaction::latest('id')->firstOrFail();
    }

    private function receiptActionsIn(string $html): bool
    {
        return str_contains($html, '>View receipt<') && str_contains($html, '>Download PDF<');
    }

    // ------------------------------------------------ webhook-first and replay

    public function test_webhook_first_success_grants_the_receipt_only_to_the_browser_that_started_checkout(): void
    {
        $this->paystackReports('success');
        $transaction = $this->startCheckout();

        // The webhook settles it before the payer is redirected back.
        $this->assertSame(PaymentSettlementService::SETTLED, app(PaymentSettlementService::class)->settleByReference($transaction->reference)['outcome']);

        $this->get('/payment/callback?reference='.$transaction->reference)
            ->assertRedirect('/s/alpha/payment')
            ->assertSessionHas('success', PaymentController::MESSAGE_RECEIPT_AVAILABLE)
            ->assertSessionHas('receipt_available', true)
            ->assertSessionHas('last_transaction_id', $transaction->id);

        $page = $this->get('/s/alpha/payment')->assertOk()->getContent();
        $this->assertTrue($this->receiptActionsIn($page));
        $this->get(route('payment.receipt', $transaction->id))->assertOk();
        $this->get(route('payment.receipt.download', $transaction->id))->assertOk();
    }

    public function test_a_stranger_replaying_the_callback_gets_the_confirmation_but_no_receipt(): void
    {
        $this->paystackReports('success');
        $transaction = $this->startCheckout();
        app(PaymentSettlementService::class)->settleByReference($transaction->reference);

        // Another browser, with the callback URL but not the checkout.
        $this->flushSession();

        $this->get('/payment/callback?reference='.$transaction->reference)
            ->assertRedirect('/s/alpha/payment')
            ->assertSessionHas('success', PaymentController::MESSAGE_RECEIPT_EMAILED)
            ->assertSessionMissing('receipt_available')
            ->assertSessionMissing('last_transaction_id');

        $this->assertFalse($this->receiptActionsIn($this->get('/s/alpha/payment')->getContent()));
        $this->get(route('payment.receipt', $transaction->id))->assertNotFound();
        $this->get(route('payment.receipt.download', $transaction->id))->assertNotFound();
    }

    public function test_an_older_receipt_in_the_session_is_never_offered_for_a_new_payment(): void
    {
        // Payment A: settled by this browser's callback, so it holds A's receipt.
        $this->paystackReports('success');
        $a = $this->pendingTransaction('ref-a');
        $this->get('/payment/callback?reference=ref-a')->assertSessionHas('last_transaction_id', $a->id);

        // Payment B was started elsewhere and is already settled: this browser gets
        // the confirmation for B, and must not be shown A's receipt as B's.
        $b = $this->pendingTransaction('ref-b');
        app(PaymentSettlementService::class)->settleByReference('ref-b');

        $this->get('/payment/callback?reference=ref-b')->assertSessionHas('success', PaymentController::MESSAGE_RECEIPT_EMAILED);
        $page = $this->get('/s/alpha/payment')->getContent();

        $this->assertFalse($this->receiptActionsIn($page));
        $this->assertStringNotContainsString(route('payment.receipt', $a->id), $page);
    }

    // -------------------------------------------------------- outcome messages

    public function test_a_payment_still_processing_is_told_not_to_pay_again(): void
    {
        foreach (['pending', 'ongoing', 'processing', 'queued'] as $status) {
            $this->flushSession();
            $this->paystackReports($status);
            $this->pendingTransaction('ref-'.$status);

            $this->get('/payment/callback?reference=ref-'.$status)
                ->assertRedirect('/s/alpha/payment')
                ->assertSessionHas('error', PaymentController::MESSAGE_PENDING)
                ->assertSessionHas('payment_outcome', 'pending');

            $this->get('/s/alpha/payment')->assertSee('Payment still processing')->assertSee("Please don't pay again.");
        }
    }

    public function test_an_abandoned_checkout_is_reported_as_not_completed(): void
    {
        $this->paystackReports('abandoned');
        $this->pendingTransaction('ref-abandoned');

        $this->get('/payment/callback?reference=ref-abandoned')
            ->assertSessionHas('error', PaymentController::MESSAGE_CANCELLED)
            ->assertSessionHas('payment_outcome', 'cancelled');

        $this->get('/s/alpha/payment')->assertSee('Payment not completed')->assertSee('Payment was not completed. You can try again.');
    }

    public function test_a_declined_or_reversed_charge_is_reported_as_unsuccessful(): void
    {
        foreach (['failed', 'reversed'] as $status) {
            $this->flushSession();
            $this->paystackReports($status);
            $this->pendingTransaction('ref-'.$status);

            $this->get('/payment/callback?reference=ref-'.$status)
                ->assertSessionHas('error', PaymentController::MESSAGE_DECLINED)
                ->assertSessionHas('payment_outcome', 'declined');

            $this->get('/s/alpha/payment')->assertSee('Payment unsuccessful')->assertSee('Payment did not go through. You can try again.');
        }
    }

    public function test_a_settlement_conflict_has_its_own_message(): void
    {
        $transaction = $this->pendingTransaction('ref-conflict');
        $this->mock(PaymentSettlementService::class, fn ($m) => $m->shouldReceive('settleByReference')->andReturn([
            'outcome' => PaymentSettlementService::SETTLEMENT_CONFLICT, 'transaction' => $transaction,
            'message' => 'Settlement could not be recorded.', 'paystack_status' => null,
        ]));

        $this->get('/payment/callback?reference=ref-conflict')
            ->assertSessionHas('error', PaymentController::MESSAGE_CONFLICT)
            ->assertSessionHas('payment_outcome', 'conflict');

        $this->get('/s/alpha/payment')->assertSee('Payment not yet recorded')->assertSee("Please don't pay again. Contact support.");
    }

    public function test_the_checkout_start_error_still_keeps_the_filled_in_details_note(): void
    {
        Http::fake(['*/transaction/initialize' => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

        $this->from('/pay/alpha')->post('/pay/alpha/initialize', [
            'email' => 'parent@example.test', 'category_id' => $this->fee->category_id, 'subcategory_id' => $this->fee->id,
            'quantity' => 1, 'academic_session_id' => $this->sessionId, 'academic_term_id' => $this->termId,
        ])->assertRedirect('/pay/alpha')->assertSessionMissing('payment_outcome');

        $this->get('/pay/alpha')->assertSee('Payment not completed')->assertSee('Unable to initialize payment.')
            ->assertSee('Your details are still filled in below', false);
    }

    // ------------------------------------------------------ payment not found

    public function test_a_missing_or_unknown_reference_gets_the_same_public_not_found_page(): void
    {
        $missing = $this->get('/payment/callback')->assertNotFound();
        $unknown = $this->get('/payment/callback?reference=does-not-exist')->assertNotFound();

        foreach ([$missing, $unknown] as $response) {
            $response->assertSee('We couldn’t find this payment')
                ->assertSee('please don’t pay again', false)
                ->assertSee('your school can confirm your payment status')
                ->assertDontSee('does-not-exist')
                ->assertDontSee('Sign in to your school', false);
            $this->assertStringNotContainsString('/admin/login"', $response->getContent().' ', 'no admin sign-in redirect');
            $this->assertNull($response->headers->get('Location'));
        }

        $normalise = fn ($r) => preg_replace('/name="csrf-token" content="[^"]*"/', '', $r->getContent());
        $this->assertSame($normalise($missing), $normalise($unknown), 'identical whichever it was');
    }

    // ------------------------------------------------------------ success state

    public function test_the_success_state_shows_receipt_actions_and_no_payment_form(): void
    {
        $this->paystackReports('success');
        $transaction = $this->pendingTransaction('ref-ok');

        $this->get('/payment/callback?reference=ref-ok')->assertRedirect('/s/alpha/payment');
        $page = $this->get('/s/alpha/payment')->assertOk();
        $html = $page->getContent();

        $this->assertTrue($this->receiptActionsIn($html));
        $page->assertSee('href="'.route('payment.receipt', $transaction->id).'"', false)
            ->assertSee('href="'.route('payment.receipt.download', $transaction->id).'"', false)
            ->assertSee('<a href="'.url('/s/alpha/payment').'" class="btn-outline w-full sm:w-auto">Make another payment</a>', false);

        // A finished confirmation: no form, no sticky Pay bar, no form script.
        foreach (['id="paymentForm"', 'id="submitBtn"', 'id="submitTotal"', 'const maxQuantity'] as $absent) {
            $this->assertStringNotContainsString($absent, $html, $absent);
        }
    }

    public function test_make_another_payment_returns_to_the_payment_form(): void
    {
        $this->paystackReports('success');
        $this->pendingTransaction('ref-again');
        $this->get('/payment/callback?reference=ref-again');
        $this->get('/s/alpha/payment')->assertDontSee('id="paymentForm"', false);

        // "Make another payment" is a plain link back to this page: the confirmation
        // has been shown, so the form is back.
        $this->get('/s/alpha/payment')
            ->assertSee('id="paymentForm"', false)
            ->assertSee('id="submitBtn"', false)
            ->assertDontSee('Payment successful');
    }

    public function test_the_success_wording_is_not_repeated(): void
    {
        $this->paystackReports('success');
        $this->pendingTransaction('ref-once');
        $this->get('/payment/callback?reference=ref-once');

        $html = $this->get('/s/alpha/payment')->getContent();
        preg_match('/<body\b.*<\/body>/s', $html, $body);

        $this->assertSame(1, substr_count($body[0], 'Payment successful'), 'the heading only');
        $this->assertStringNotContainsString('Payment successful!', $html);
        $this->assertStringContainsString(PaymentController::MESSAGE_RECEIPT_AVAILABLE, $html);
    }

    // --------------------------------------------------------------- security

    public function test_no_transaction_id_or_reference_reaches_a_url_or_message(): void
    {
        $this->paystackReports('success');
        $transaction = $this->startCheckout();

        $response = $this->get('/payment/callback?reference='.$transaction->reference)->assertRedirect('/s/alpha/payment');

        $this->assertSame(url('/s/alpha/payment'), $response->headers->get('Location'), 'no query string on the redirect');
        foreach ([session('success'), session('error')] as $message) {
            $this->assertStringNotContainsString((string) $transaction->id, (string) $message);
            $this->assertStringNotContainsString($transaction->reference, (string) $message);
        }
    }

    public function test_receipt_access_policy_is_unchanged(): void
    {
        $this->paystackReports('success');
        $transaction = $this->pendingTransaction('ref-policy');
        $this->get('/payment/callback?reference=ref-policy');

        // The paying browser: allowed. Anyone else, unsigned: 404. Another school's
        // admin: 404. The owning school's admin: allowed.
        $this->get(route('payment.receipt', $transaction->id))->assertOk();
        $this->flushSession();
        $this->get(route('payment.receipt', $transaction->id))->assertNotFound();

        $other = $this->makeSchool('Beta School', 'beta');
        $this->actingAsSchoolAdmin($other)->get(route('payment.receipt', $transaction->id))->assertNotFound();
        $this->flushSession();
        $this->actingAsSchoolAdmin($this->school)->get(route('payment.receipt', $transaction->id))->assertOk();
    }
}
