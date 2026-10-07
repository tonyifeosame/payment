<?php

namespace Tests\Feature;

use App\Jobs\InitiateSchoolPayout;
use App\Models\Payout;
use App\Models\School;
use App\Models\Transaction;
use App\Services\PaystackService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * M2: a payout job that created a Paystack recipient from the bank account it had
 * loaded could save that recipient back AFTER the school changed its account,
 * silently sending every later payout to the old account. Recipients are now
 * saved only if the account is still the one they were created from, and a
 * stored recipient is used only for the account it was created for.
 */
class PayoutRecipientRaceTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const BASE = 50000.00;

    private School $school;

    /** @var list<array{url: string, body: array}> */
    private array $calls = [];

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.paystack.secret_key' => 'sk_test_fake']);
        $this->school = $this->makeSchool('Alpha School', 'alpha', [
            'account_number' => '0000000001', 'bank_code' => '058', 'account_name' => 'Old Account',
        ]);
    }

    /** Paystack fake; $duringRecipient runs while the recipient POST is "in flight". */
    private function fakePaystack(?\Closure $duringRecipient = null): void
    {
        Http::fake(function (HttpRequest $r) use ($duringRecipient) {
            $this->calls[] = ['url' => $r->url(), 'body' => $r->data()];

            if (str_contains($r->url(), '/transferrecipient')) {
                if ($duringRecipient) {
                    $duringRecipient();
                }

                return Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_for_'.$r['account_number']]]);
            }
            if (str_ends_with($r->url(), '/transfer')) {
                return Http::response(['status' => true, 'data' => [
                    'status' => 'pending', 'transfer_code' => 'TRF_1', 'reference' => $r['reference'],
                    'amount' => $r['amount'], 'currency' => 'NGN',
                ]]);
            }

            return Http::response(['status' => false], 404);
        });
    }

    private function transfers(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => str_ends_with($c['url'], '/transfer')));
    }

    private function recipientCreations(): array
    {
        return array_values(array_filter($this->calls, fn ($c) => str_contains($c['url'], '/transferrecipient')));
    }

    private function pendingPayout(string $ref = 'PO-race'): Payout
    {
        $t = Transaction::create([
            'school_id' => $this->school->id, 'reference' => 'pay-'.$ref, 'amount' => 51250, 'status' => 'success',
            'email' => 'p@example.test', 'meta_data' => ['quantity' => 1, 'base_amount' => self::BASE, 'markup_amount' => 1250, 'gross_amount' => 51250],
        ]);

        return Payout::create([
            'school_id' => $this->school->id, 'transaction_id' => $t->id, 'reference' => $ref,
            'amount' => self::BASE, 'currency' => 'NGN', 'status' => Payout::PENDING,
        ]);
    }

    /** What SchoolBankDetailsService writes, applied directly by "the other request". */
    private function concurrentBankChange(): void
    {
        DB::table('schools')->where('id', $this->school->id)->update([
            'account_number' => '0000000002', 'bank_code' => '044', 'account_name' => 'New Account',
            'paystack_recipient_code' => null, 'paystack_recipient_account' => null,
        ]);
    }

    public function test_a_bank_change_during_recipient_creation_discards_the_stale_recipient(): void
    {
        $this->fakePaystack(fn () => $this->concurrentBankChange());
        $payout = $this->pendingPayout();

        InitiateSchoolPayout::dispatchSync($payout->id);

        $fresh = $this->school->fresh();
        $this->assertNull($fresh->paystack_recipient_code, 'the old account\'s recipient was saved over the bank change');
        $this->assertSame('0000000002', $fresh->account_number);
        $this->assertSame([], $this->transfers(), 'money was sent to the account the school had just replaced');

        // No transfer exists, so the payout is definitively failed and retryable.
        $this->assertSame(Payout::FAILED, $payout->fresh()->status);
    }

    public function test_after_the_race_the_retry_pays_the_new_account(): void
    {
        $this->fakePaystack(fn () => $this->concurrentBankChange());
        $payout = $this->pendingPayout();
        InitiateSchoolPayout::dispatchSync($payout->id);

        $this->fakePaystack();
        $this->artisan('payouts:retry', ['reference' => 'PO-race'])->assertExitCode(0);

        $creations = $this->recipientCreations();
        $this->assertSame('0000000002', end($creations)['body']['account_number']);
        $transfer = $this->transfers()[0];
        $this->assertSame('RCP_for_0000000002', $transfer['body']['recipient']);
        $this->assertSame('RCP_for_0000000002', $this->school->fresh()->paystack_recipient_code);
    }

    public function test_a_recipient_created_for_another_account_is_never_used(): void
    {
        // A code stamped for the OLD account, while the school row now holds the new one.
        $old = $this->school->recipientAccountKey();
        $this->school->forceFill(['paystack_recipient_code' => 'RCP_stale', 'paystack_recipient_account' => $old])->save();
        DB::table('schools')->where('id', $this->school->id)->update(['account_number' => '0000000003', 'account_name' => 'Newer Account']);

        $this->fakePaystack();
        InitiateSchoolPayout::dispatchSync($this->pendingPayout()->id);

        $transfer = $this->transfers()[0];
        $this->assertSame('RCP_for_0000000003', $transfer['body']['recipient']);
        $this->assertNotSame('RCP_stale', $this->school->fresh()->paystack_recipient_code);
    }

    public function test_a_model_loaded_before_the_change_is_not_trusted(): void
    {
        $staleModel = School::find($this->school->id); // loaded with the old account
        $this->concurrentBankChange();
        $this->fakePaystack();

        $code = app(PaystackService::class)->ensureRecipientForSchool($staleModel);

        $this->assertSame('RCP_for_0000000002', $code);
        $this->assertSame('0000000002', $this->recipientCreations()[0]['body']['account_number']);
    }

    public function test_a_matching_recipient_is_reused_without_creating_another(): void
    {
        $this->fakePaystack();

        $first = app(PaystackService::class)->ensureRecipientForSchool($this->school);
        $second = app(PaystackService::class)->ensureRecipientForSchool($this->school->fresh());

        $this->assertSame('RCP_for_0000000001', $first);
        $this->assertSame($first, $second);
        $this->assertCount(1, $this->recipientCreations());
        $this->assertSame($this->school->fresh()->recipientAccountKey(), $this->school->fresh()->paystack_recipient_account);
    }

    public function test_the_payout_amount_is_unchanged(): void
    {
        $this->fakePaystack();
        InitiateSchoolPayout::dispatchSync($this->pendingPayout()->id);

        $this->assertSame((int) round(self::BASE * 100), (int) $this->transfers()[0]['body']['amount']);
    }

    public function test_the_fingerprint_is_hidden_from_serialisation(): void
    {
        $this->school->forceFill(['paystack_recipient_code' => 'RCP_x', 'paystack_recipient_account' => 'abc'])->save();

        $json = $this->school->fresh()->toArray();
        $this->assertArrayNotHasKey('paystack_recipient_account', $json);
        $this->assertArrayNotHasKey('paystack_recipient_code', $json);
    }
}
