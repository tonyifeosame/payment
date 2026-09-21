<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\School;
use App\Services\PayoutService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Phase 1 — the read-only payout ledger a school sees.
 */
class PayoutLedgerTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');
    }

    public function test_ledger_lists_only_own_payouts_with_status_labels_and_references(): void
    {
        $paid = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-paid', 'fee_amount' => 50000, 'student_name' => 'Adaeze Okonkwo']);
        $failed = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-failed', 'fee_amount' => 7000]);
        $review = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-review', 'fee_amount' => 1000]);
        $betaTx = $this->makeSuccessfulTransaction($this->beta, ['reference' => 'ref-beta', 'fee_amount' => 900000]);

        Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => $paid->id, 'reference' => 'PO-paid', 'amount' => 50000, 'status' => Payout::SUCCESS, 'transfer_code' => 'TRF_abc', 'completed_at' => now()]);
        Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => $failed->id, 'reference' => 'PO-failed', 'amount' => 7000, 'status' => Payout::FAILED, 'last_error' => 'Recipient not available', 'attempts' => 2]);
        Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => $review->id, 'reference' => 'PO-review', 'amount' => 0, 'status' => Payout::NEEDS_REVIEW, 'last_error' => 'No base_amount recorded']);
        Payout::create(['school_id' => $this->beta->id, 'transaction_id' => $betaTx->id, 'reference' => 'PO-beta', 'amount' => 900000, 'status' => Payout::SUCCESS]);

        $page = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/payouts')->assertOk();

        $page->assertSee('PO-paid')->assertSee('Paid')->assertSee('ref-paid')->assertSee('Adaeze Okonkwo');
        $page->assertSee('PO-failed')->assertSee('Failed');
        $page->assertSee('PO-review')->assertSee('Needs review');
        $page->assertSee('50,000.00');

        // Provider identifiers, raw errors and attempt counters are internal and never rendered.
        $page->assertDontSee('TRF_abc')->assertDontSee('Recipient not available')->assertDontSee('No base_amount recorded')->assertDontSee('2 attempts');

        $page->assertDontSee('PO-beta')->assertDontSee('ref-beta')->assertDontSee('900,000.00');
    }

    public function test_ledger_status_filter_and_protection(): void
    {
        $t = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-1']);
        $u = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-2']);
        Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => $t->id, 'reference' => 'PO-1', 'amount' => 1, 'status' => Payout::SUCCESS]);
        Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => $u->id, 'reference' => 'PO-2', 'amount' => 1, 'status' => Payout::PENDING]);

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/payouts?status=pending')->assertOk()->assertSee('PO-2')->assertDontSee('PO-1');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/payouts?status=bogus')->assertOk()->assertSee('PO-1')->assertSee('PO-2');

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/beta/payouts')->assertNotFound();
        $this->flushSession();
        $this->get('/admin/alpha/payouts')->assertRedirect('/admin/login');
    }

    public function test_ledger_amount_is_the_school_share_not_the_gross_charge(): void
    {
        // Recorded through the real service, exactly as settlement does it.
        $t = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-share', 'fee_amount' => 50000, 'service_fee' => 1250]);
        $payout = app(PayoutService::class)->recordObligationFor($t);

        $this->assertEquals(50000.00, (float) $payout->amount);
        $this->assertEquals(51250.00, (float) $t->amount);

        $this->actingAsSchoolAdmin($this->alpha)
            ->get('/admin/alpha/payouts')
            ->assertOk()
            ->assertSee('50,000.00')
            ->assertDontSee('51,250.00');
    }

    public function test_ledger_has_no_write_routes(): void
    {
        $t = $this->makeSuccessfulTransaction($this->alpha);
        $payout = Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => $t->id, 'reference' => 'PO-x', 'amount' => 1, 'status' => Payout::PENDING]);

        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->actingAsSchoolAdmin($this->alpha)->{$method}('/admin/alpha/payouts')->assertStatus(405);
            // The detail page is GET-only: every write method is rejected.
            $this->actingAsSchoolAdmin($this->alpha)->{$method}("/admin/alpha/payouts/{$payout->id}")->assertStatus(405);
        }

        $this->assertSame(Payout::PENDING, $payout->fresh()->status);
    }
}
