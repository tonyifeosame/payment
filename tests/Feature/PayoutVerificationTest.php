<?php

namespace Tests\Feature;

use App\Jobs\InitiateSchoolPayout;
use App\Models\Payout;
use App\Models\School;
use App\Models\SchoolAuditEvent;
use App\Models\Transaction;
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * H3: registration is open, and payouts used to be automatic — anyone could
 * register a "school" with any resolvable bank account and be paid every
 * parent's money within the hour. Now a school must be verified by an operator
 * before any transfer is sent, and every bank-account change holds payouts for
 * a cooling-off period. Payments themselves are never blocked.
 */
class PayoutVerificationTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private const GROSS = 51250.00;

    private const BASE = 50000.00;

    private function fakePaystack(int &$transfers): void
    {
        $transfers = 0;
        Http::fake(function (HttpRequest $r) use (&$transfers) {
            $url = $r->url();
            if (str_contains($url, '/transaction/verify/')) {
                return Http::response(['status' => true, 'data' => [
                    'status' => 'success', 'channel' => 'card', 'reference' => basename($url),
                    'amount' => (int) round(self::GROSS * 100), 'currency' => 'NGN',
                ]]);
            }
            if (str_contains($url, '/bank/resolve')) {
                return Http::response(['status' => true, 'data' => ['account_name' => 'Resolved Name', 'account_number' => '1111111111']]);
            }
            if (str_contains($url, '/transferrecipient')) {
                return Http::response(['status' => true, 'data' => ['recipient_code' => 'RCP_new']]);
            }
            if (str_ends_with($url, '/transfer') && $r->method() === 'POST') {
                $transfers++;

                return Http::response(['status' => true, 'data' => [
                    'status' => 'pending', 'transfer_code' => 'TRF_'.$transfers, 'reference' => $r['reference'],
                    'amount' => $r['amount'], 'currency' => 'NGN',
                ]]);
            }

            return Http::response(['status' => false], 404);
        });
    }

    private function pendingPayment(School $school, string $reference): Transaction
    {
        return Transaction::create([
            'school_id' => $school->id, 'reference' => $reference, 'amount' => self::GROSS,
            'status' => 'pending', 'email' => 'parent@example.test',
            'meta_data' => ['quantity' => 1, 'base_amount' => self::BASE, 'markup_amount' => 1250, 'gross_amount' => self::GROSS],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Mail::fake();
        config(['services.paystack.secret_key' => 'sk_test_fake', 'payouts.bank_change_hold_hours' => 48]);
    }

    public function test_a_newly_registered_school_is_not_approved_for_payouts(): void
    {
        $transfers = 0;
        $this->fakePaystack($transfers);

        $this->post('/registration', [
            'name' => 'New School', 'email' => 'new@example.test', 'account_number' => '1111111111',
            'bank' => 'GTB', 'bank_code' => '058', 'admin_password' => 'a-long-passphrase-1', 'admin_password_confirmation' => 'a-long-passphrase-1',
        ])->assertRedirect();

        $school = School::where('slug', 'new-school')->firstOrFail();
        $this->assertNull($school->payouts_approved_at);
        $this->assertFalse($school->canReceivePayouts());
        $this->assertSame('awaiting verification', $school->payoutBlockReason());
    }

    public function test_approval_cannot_be_mass_assigned_at_registration(): void
    {
        $transfers = 0;
        $this->fakePaystack($transfers);

        $this->post('/registration', [
            'name' => 'Sneaky School', 'email' => 'sneaky@example.test', 'account_number' => '1111111111',
            'bank' => 'GTB', 'bank_code' => '058', 'admin_password' => 'a-long-passphrase-1', 'admin_password_confirmation' => 'a-long-passphrase-1',
            'payouts_approved_at' => now()->toDateTimeString(), 'payout_hold_until' => null,
        ]);

        $this->assertNull(School::where('slug', 'sneaky-school')->firstOrFail()->payouts_approved_at);
    }

    public function test_an_unverified_school_is_paid_into_but_never_transfers_to(): void
    {
        $transfers = 0;
        $this->fakePaystack($transfers);
        $school = $this->makeSchool('Unverified', 'unverified', ['payouts_approved_at' => null, 'paystack_recipient_code' => 'RCP_u']);
        $payment = $this->pendingPayment($school, 'ref-unverified');

        // The parent's payment settles exactly as before (queue is sync in tests,
        // so the payout job runs inline here too).
        $result = app(PaymentSettlementService::class)->settleByReference('ref-unverified');
        $this->assertSame(PaymentSettlementService::SETTLED, $result['outcome']);
        $this->assertSame('success', $payment->fresh()->status);

        $payout = Payout::where('transaction_id', $payment->id)->firstOrFail();
        $this->assertSame(Payout::PENDING, $payout->status, 'a held payout must stay pending, not fail');
        $this->assertSame(0, (int) $payout->attempts, 'a held payout must not be claimed');
        $this->assertEquals(self::BASE, (float) $payout->amount, 'the school share must be unchanged');
        $this->assertSame(0, $transfers, 'money was transferred to an unverified school');

        // The hourly reconciliation does not send it either.
        $this->artisan('payouts:run', ['--dispatch' => true])->assertExitCode(0);
        $this->assertSame(0, $transfers);
        $this->assertSame(Payout::PENDING, $payout->fresh()->status);
    }

    public function test_approving_a_school_releases_its_held_payouts_on_the_next_run(): void
    {
        $transfers = 0;
        $this->fakePaystack($transfers);
        $school = $this->makeSchool('Unverified', 'unverified', ['payouts_approved_at' => null, 'paystack_recipient_code' => 'RCP_u']);
        $payment = $this->pendingPayment($school, 'ref-held');
        app(PaymentSettlementService::class)->settleByReference('ref-held');
        $this->assertSame(0, $transfers);

        $this->artisan('schools:approve-payouts', ['school' => 'unverified', '--note' => 'Called the school office; account letter checked'])
            ->assertExitCode(0);

        $this->assertNotNull($school->fresh()->payouts_approved_at);
        $this->assertTrue($school->fresh()->canReceivePayouts());

        $this->artisan('payouts:run', ['--dispatch' => true])->assertExitCode(0);

        $this->assertSame(1, $transfers, 'the approved school was not paid');
        $payout = Payout::where('transaction_id', $payment->id)->firstOrFail();
        $this->assertSame(Payout::PROCESSING, $payout->status);
        $this->assertEquals(self::BASE, (float) $payout->amount);

        $event = SchoolAuditEvent::where('school_id', $school->id)->where('action', SchoolAuditEvent::ACTION_PAYOUTS_APPROVED)->firstOrFail();
        $this->assertSame(SchoolAuditEvent::ACTOR_ARTISAN, $event->actor);
    }

    public function test_approval_requires_a_note_and_an_existing_school(): void
    {
        $school = $this->makeSchool('Unverified', 'unverified', ['payouts_approved_at' => null]);

        $this->artisan('schools:approve-payouts', ['school' => 'unverified'])->assertExitCode(1);
        $this->artisan('schools:approve-payouts', ['school' => 'no-such-school', '--note' => 'x'])->assertExitCode(1);

        $this->assertNull($school->fresh()->payouts_approved_at);
    }

    public function test_an_approved_school_is_paid_immediately_as_before(): void
    {
        $transfers = 0;
        $this->fakePaystack($transfers);
        $school = $this->makeSchool('Verified', 'verified', ['paystack_recipient_code' => 'RCP_v']);
        $this->pendingPayment($school, 'ref-verified');

        app(PaymentSettlementService::class)->settleByReference('ref-verified');

        $this->assertSame(1, $transfers);
    }

    public function test_a_bank_change_holds_payouts_until_the_hold_passes(): void
    {
        $transfers = 0;
        $this->fakePaystack($transfers);
        $school = $this->makeSchool('Verified', 'verified', ['paystack_recipient_code' => 'RCP_old']);

        $this->actingAsSchoolAdmin($school)->put('/admin/verified/settings/bank', [
            'bank' => 'Access', 'bank_code' => '044', 'account_number' => '1111111111', 'current_password' => 'password123',
        ])->assertRedirect();

        $school->refresh();
        $this->assertNotNull($school->payout_hold_until);
        $this->assertEqualsWithDelta(now()->addHours(48)->timestamp, $school->payout_hold_until->timestamp, 5);
        $this->assertStringStartsWith('bank account changed', (string) $school->payoutBlockReason());
        $this->assertNull($school->paystack_recipient_code);

        $bankEvent = SchoolAuditEvent::where('school_id', $school->id)->where('action', SchoolAuditEvent::ACTION_BANK_CHANGED)->firstOrFail();
        $this->assertArrayHasKey('payout_hold_until', $bankEvent->changes);

        // A payment during the hold settles; its payout waits.
        $payment = $this->pendingPayment($school, 'ref-during-hold');
        app(PaymentSettlementService::class)->settleByReference('ref-during-hold');
        $this->assertSame('success', $payment->fresh()->status);
        $this->assertSame(0, $transfers, 'money moved during the bank-change hold');

        // After the hold, the next run pays the NEW account's recipient.
        $this->travel(49)->hours();
        $this->artisan('payouts:run', ['--dispatch' => true])->assertExitCode(0);

        $this->assertSame(1, $transfers);
        $this->assertSame('RCP_new', $school->fresh()->paystack_recipient_code);
    }

    public function test_an_operator_can_lift_a_bank_change_hold_early(): void
    {
        $school = $this->makeSchool('Verified', 'verified', ['payout_hold_until' => now()->addHours(48)]);
        $this->assertFalse($school->canReceivePayouts());

        $this->artisan('schools:approve-payouts', ['school' => (string) $school->id, '--note' => 'School confirmed the change by phone'])
            ->assertExitCode(0);

        $this->assertNull($school->fresh()->payout_hold_until);
        $this->assertTrue($school->fresh()->canReceivePayouts());
    }

    public function test_keep_hold_approves_without_lifting_the_hold(): void
    {
        $school = $this->makeSchool('Unverified', 'unverified', ['payouts_approved_at' => null, 'payout_hold_until' => now()->addHours(10)]);

        $this->artisan('schools:approve-payouts', ['school' => 'unverified', '--note' => 'verified', '--keep-hold' => true])->assertExitCode(0);

        $school->refresh();
        $this->assertNotNull($school->payouts_approved_at);
        $this->assertNotNull($school->payout_hold_until);
        $this->assertFalse($school->canReceivePayouts());
    }

    public function test_suspending_a_school_stops_new_transfers_but_not_payments(): void
    {
        $transfers = 0;
        $this->fakePaystack($transfers);
        $school = $this->makeSchool('Verified', 'verified', ['paystack_recipient_code' => 'RCP_v']);

        $this->artisan('schools:suspend-payouts', ['school' => 'verified', '--note' => 'Reported as impersonation'])->assertExitCode(0);
        $this->assertNull($school->fresh()->payouts_approved_at);

        $payment = $this->pendingPayment($school, 'ref-suspended');
        app(PaymentSettlementService::class)->settleByReference('ref-suspended');

        $this->assertSame('success', $payment->fresh()->status);
        $this->assertSame(0, $transfers);
        $this->assertTrue(SchoolAuditEvent::where('school_id', $school->id)->where('action', SchoolAuditEvent::ACTION_PAYOUTS_SUSPENDED)->exists());
    }

    public function test_the_job_itself_refuses_a_held_school_even_when_dispatched_directly(): void
    {
        $transfers = 0;
        $this->fakePaystack($transfers);
        $school = $this->makeSchool('Unverified', 'unverified', ['payouts_approved_at' => null, 'paystack_recipient_code' => 'RCP_u']);
        $payment = $this->pendingPayment($school, 'ref-direct');
        $payment->forceFill(['status' => 'success'])->save();
        $payout = Payout::create([
            'school_id' => $school->id, 'transaction_id' => $payment->id, 'reference' => 'PO-direct',
            'amount' => self::BASE, 'currency' => 'NGN', 'status' => Payout::PENDING,
        ]);

        InitiateSchoolPayout::dispatchSync($payout->id);

        $this->assertSame(0, $transfers);
        $this->assertSame(Payout::PENDING, $payout->fresh()->status);
    }

    public function test_the_status_command_lists_held_schools_only_by_default(): void
    {
        $this->makeSchool('Verified', 'verified');
        $this->makeSchool('Waiting', 'waiting', ['payouts_approved_at' => null]);

        $this->artisan('schools:payout-status')
            ->expectsOutputToContain('waiting')
            ->doesntExpectOutputToContain('verified')
            ->assertExitCode(0);
    }

    public function test_the_school_sees_why_its_payouts_are_waiting(): void
    {
        $waiting = $this->makeSchool('Waiting', 'waiting', ['payouts_approved_at' => null]);
        $held = $this->makeSchool('Held', 'held', ['payout_hold_until' => now()->addHours(5)]);
        $fine = $this->makeSchool('Fine', 'fine');

        $this->actingAsSchoolAdmin($waiting)->get('/admin/waiting/payouts')->assertOk()->assertSee('Payouts start once FEYRA has verified your school.');
        $this->actingAsSchoolAdmin($waiting)->get('/admin/waiting/dashboard')->assertOk()->assertSee('data-payout-hold', false);
        $this->actingAsSchoolAdmin($held)->get('/admin/held/payouts')->assertOk()->assertSee('Payouts are paused until');
        $this->actingAsSchoolAdmin($fine)->get('/admin/fine/payouts')->assertOk()->assertDontSee('data-payout-hold', false);
    }

    public function test_the_migration_backfills_existing_schools_as_approved(): void
    {
        $existing = $this->makeSchool('Existing', 'existing', ['payouts_approved_at' => null]);

        $migration = require database_path('migrations/2026_10_07_000000_add_payout_verification_to_schools_table.php');
        $migration->up();

        $this->assertNotNull($existing->fresh()->payouts_approved_at, 'an existing school lost its payouts on upgrade');
    }
}
