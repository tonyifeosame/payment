<?php

namespace Tests\Feature;

use App\Jobs\InitiateSchoolPayout;
use App\Models\Payout;
use App\Models\School;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Batch 3 — two places where a provider call was classified wrongly.
 *
 * L2  Checkout initialization retried ANY failed response three times, always
 *     with the same reference. A definitive 4xx was therefore sent three times,
 *     and in the case it was likeliest to matter — a first attempt that actually
 *     succeeded but whose response was lost — the re-sends came back "Duplicate
 *     Transaction Reference" and the parent was told initialization had failed
 *     for a checkout that existed.
 *
 * L11 Recipient creation uses the retrying client, which re-throws when its
 *     attempts run out. That exception escaped initiateTransfer() entirely and
 *     came out of the job AFTER the payout had been claimed, leaving it in
 *     `initiating` until H1's hourly lookup released it.
 *
 * The classification in both cases is the same question: did this failure leave
 * anything behind at the provider? For a 4xx on initialize, no. For recipient
 * creation, also no — it creates a payout destination, it never moves money.
 */
class ProviderRetryClassificationTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const INITIALIZE = '*/transaction/initialize';

    private School $school;

    private \App\Models\Subcategory $fee;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_secret']);

        $this->school = $this->makeSchool('Alpha School', 'alpha');
        $this->fee = $this->makeFee($this->school, 'School Fees', 'Primary', 50000);
    }

    private function submit(): TestResponse
    {
        return $this->post('/pay/alpha/initialize', [
            'email' => 'payer@example.test',
            'name' => 'Alpha Payer',
            'category_id' => $this->fee->category_id,
            'subcategory_id' => $this->fee->id,
            'quantity' => 1,
        ]);
    }

    // ============================================================ L2 retries

    public function test_a_server_error_is_retried(): void
    {
        Http::fake([self::INITIALIZE => Http::response('bad gateway', 502)]);

        $this->submit()->assertRedirect()->assertSessionHas('error', 'Unable to initialize payment.');

        Http::assertSentCount(3);
    }

    public function test_a_connection_failure_is_retried(): void
    {
        // A thrown ConnectionException never becomes a recorded request/response
        // pair, so the attempts are counted in the fake itself.
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('cURL error 28: Operation timed out');
        });

        $this->submit()->assertRedirect()->assertSessionHas('error', 'Unable to initialize payment.');

        $this->assertSame(3, $attempts);
    }

    public function test_a_duplicate_reference_is_not_retried(): void
    {
        // Paystack's answer when the reference has already been used — the exact
        // shape a lost-response re-send produces. Sending it again cannot help.
        Http::fake([self::INITIALIZE => Http::response([
            'status' => false, 'message' => 'Duplicate Transaction Reference',
        ], 400)]);

        $this->submit()->assertRedirect()->assertSessionHas('error', 'Unable to initialize payment.');

        Http::assertSentCount(1);
    }

    public function test_a_rejected_key_is_not_retried(): void
    {
        Http::fake([self::INITIALIZE => Http::response(['status' => false, 'message' => 'Invalid key'], 401)]);

        $this->submit()->assertRedirect();

        Http::assertSentCount(1);
    }

    public function test_a_rate_limit_is_not_retried(): void
    {
        // Hammering a 429 twice more is the worst possible response to it.
        Http::fake([self::INITIALIZE => Http::response(['status' => false, 'message' => 'Too many requests'], 429)]);

        $this->submit()->assertRedirect();

        Http::assertSentCount(1);
    }

    public function test_a_successful_initialization_is_sent_once_and_keeps_its_reference(): void
    {
        Http::fake([self::INITIALIZE => Http::response([
            'status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/ok'],
        ])]);

        $this->submit()->assertRedirect('https://checkout.paystack.com/ok');

        Http::assertSentCount(1);

        // The reference Paystack was given is the transaction's own durable
        // reference — unchanged by this finding, and still the idempotency key.
        $transaction = Transaction::sole();
        Http::assertSent(fn ($request) => $request['reference'] === $transaction->reference);
    }

    public function test_every_retry_reuses_the_same_reference(): void
    {
        Http::fake([self::INITIALIZE => Http::response('server error', 500)]);

        $this->submit()->assertRedirect();

        $transaction = Transaction::sole();
        $references = collect(Http::recorded())->map(fn ($pair) => $pair[0]['reference'])->unique();

        $this->assertCount(3, Http::recorded());
        $this->assertCount(1, $references, 'a retry must not mint a new reference');
        $this->assertSame($transaction->reference, $references->first());
    }

    public function test_a_failed_initialization_still_leaves_the_transaction_pending(): void
    {
        Http::fake([self::INITIALIZE => Http::response(['status' => false, 'message' => 'Duplicate Transaction Reference'], 400)]);

        $this->submit()->assertRedirect();

        // Unchanged semantics: the row stays pending for the expiry cron (H5) to
        // resolve with Paystack. Nothing here settles or fails it.
        $this->assertSame('pending', Transaction::sole()->status);
    }

    // ================================================== L11 recipient failure

    private function payout(): Payout
    {
        $this->school->forceFill([
            'bank' => 'GTB', 'bank_code' => '058',
            'account_number' => '0123456789', 'account_name' => 'ALPHA SCHOOL LTD',
            'paystack_recipient_code' => null,   // forces recipient creation
        ])->save();

        $transaction = $this->makeSuccessfulTransaction($this->school, ['fee_amount' => 50000]);

        return Payout::create([
            'school_id' => $this->school->id,
            'transaction_id' => $transaction->id,
            'reference' => 'PO-recipient-test',
            'amount' => 50000,
            'status' => Payout::PENDING,
            'attempts' => 0,
        ]);
    }

    public function test_a_recipient_connection_failure_issues_no_transfer_and_does_not_strand_the_payout(): void
    {
        Http::fake(['*/transferrecipient' => fn () => throw new ConnectionException('cURL error 28: Operation timed out')]);

        $payout = $this->payout();

        // The job must not throw: the exception used to escape after the claim.
        (new InitiateSchoolPayout($payout->id))->handle(app(\App\Services\PayoutService::class), app(\App\Services\PaystackService::class));

        $payout->refresh();

        $this->assertSame(Payout::FAILED, $payout->status, 'the payout was left stranded in initiating');
        $this->assertNotSame(Payout::INITIATING, $payout->status);
        $this->assertStringContainsString('payout recipient', (string) $payout->last_error);

        // The decisive assertion: no money instruction was ever issued.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/transfer')
            && ! str_contains($request->url(), '/transferrecipient'));
    }

    public function test_a_recipient_rejection_is_still_treated_as_rejected(): void
    {
        // Paystack answering definitively (not an exception) was already handled;
        // this pins that the new catch did not change it.
        Http::fake(['*/transferrecipient' => Http::response(['status' => false, 'message' => 'Invalid account'], 400)]);

        $payout = $this->payout();

        (new InitiateSchoolPayout($payout->id))->handle(app(\App\Services\PayoutService::class), app(\App\Services\PaystackService::class));

        $this->assertSame(Payout::FAILED, $payout->fresh()->status);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/transfer')
            && ! str_contains($request->url(), '/transferrecipient'));
    }

    public function test_a_failed_recipient_payout_is_recoverable_by_the_existing_tooling(): void
    {
        // One fake for the whole test: Http::fake() APPENDS stubs rather than
        // replacing them, so a second call would leave the throwing stub matching
        // first. The first ensureRecipientForSchool() burns its three retries and
        // fails; by the time the operator retries, Paystack is reachable again.
        $recipientCalls = 0;
        Http::fake([
            '*/transferrecipient' => function () use (&$recipientCalls) {
                $recipientCalls++;
                if ($recipientCalls <= 3) {
                    throw new ConnectionException('cURL error 28: Operation timed out');
                }

                return Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_ok']]);
            },
            '*/transfer' => Http::response(['status' => true, 'data' => [
                'status' => 'success', 'transfer_code' => 'TRF_1',
                'reference' => 'PO-recipient-test', 'amount' => 5000000, 'currency' => 'NGN',
            ]]),
        ]);

        $payout = $this->payout();
        (new InitiateSchoolPayout($payout->id))->handle(app(\App\Services\PayoutService::class), app(\App\Services\PaystackService::class));

        $this->assertSame(Payout::FAILED, $payout->fresh()->status);
        $this->assertSame(3, $recipientCalls, 'the recipient call should have exhausted its retries');

        // B1 is untouched: `failed` is exactly the state payouts:retry resets. The
        // suite runs the queue synchronously, so the retry re-runs the job inside
        // the command and carries it all the way through.
        $this->artisan('payouts:retry', ['reference' => 'PO-recipient-test'])->assertExitCode(0);

        $this->assertNotSame(Payout::FAILED, $payout->fresh()->status, 'payouts:retry could not recover the payout');
        $this->assertSame('RCP_ok', $this->school->fresh()->paystack_recipient_code);
    }

    public function test_a_recipient_that_resolves_still_transfers_normally(): void
    {
        Http::fake([
            '*/transferrecipient' => Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_ok']]),
            '*/transfer' => Http::response(['status' => true, 'data' => ['status' => 'success', 'transfer_code' => 'TRF_1', 'reference' => 'PO-recipient-test', 'amount' => 5000000, 'currency' => 'NGN']]),
        ]);

        $payout = $this->payout();
        (new InitiateSchoolPayout($payout->id))->handle(app(\App\Services\PayoutService::class), app(\App\Services\PaystackService::class));

        $this->assertSame('RCP_ok', $this->school->fresh()->paystack_recipient_code);
        $this->assertNotSame(Payout::FAILED, $payout->fresh()->status);
        Http::assertSent(fn ($request) => str_contains($request->url(), '/transfer')
            && ! str_contains($request->url(), '/transferrecipient'));
    }
}
