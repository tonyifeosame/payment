<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\School;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * The redesigned dashboard: what each tile/section renders, from the unchanged
 * SchoolDashboardService figures — and what must never appear on it.
 */
class DashboardPageTest extends TestCase
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

    private function page(string $query = '')
    {
        return $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/dashboard'.($query ? '?'.$query : ''));
    }

    public function test_dashboard_loads_with_header_and_setup_states_and_is_protected(): void
    {
        $page = $this->page()->assertOk();
        $page->assertSee('Overview')->assertSee('Dashboard')->assertSee("Alpha School's payment overview", false)
            ->assertSee('Finish setting up')->assertSee('Create the session and its terms')->assertSee('Add the fees parents can pay')->assertSee('Add your first student')
            ->assertSee('/admin/alpha/sessions')->assertSee('/admin/alpha/subcategories')->assertSee('/admin/alpha/students')
            ->assertSee('No payments yet')->assertSee('Nothing collected yet')
            ->assertSee('/admin/alpha/students/create')->assertSee('/admin/alpha/subcategories/create')->assertSee('/admin/alpha/share')
            ->assertSee('Create academic session');
        $this->assertSame(1, substr_count($page->getContent(), '<h1'));

        // Setup card disappears once the school is set up.
        $this->makeSessionWithTerms($this->alpha, '2026/2027');
        $this->makeFee($this->alpha, 'Tuition', 'Tuition', 1000);
        $this->makeStudent($this->alpha, 'A/1', 'Ada');
        $this->page()->assertOk()->assertDontSee('Finish setting up')->assertSee('First Term, 2026/2027');

        $this->flushSession();
        $this->get('/admin/alpha/dashboard')->assertRedirect('/admin/login');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/beta/dashboard')->assertNotFound();
    }

    public function test_summary_tiles_show_the_schools_fee_amounts_for_each_period(): void
    {
        $session = $this->makeSessionWithTerms($this->alpha, '2026/2027');
        $first = $session->terms()->where('number', 1)->first();
        $this->alpha->refresh();

        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 50000, 'service_fee' => 1250, 'paid_at' => now(), 'academic_term_id' => $first->id, 'category_name' => 'Tuition', 'subcategory_name' => 'First Term Tuition', 'student_name' => 'Ada Okonkwo', 'reference' => 'ref-today']);
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 3000, 'service_fee' => 75, 'paid_at' => now()->subDays(9), 'academic_term_id' => $first->id, 'category_name' => 'Uniform', 'reference' => 'ref-old']);
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 99999, 'status' => 'pending', 'paid_at' => null, 'reference' => 'ref-pending']);
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 88888, 'status' => 'failed', 'paid_at' => null, 'reference' => 'ref-failed']);
        $this->makeSuccessfulTransaction($this->beta, ['fee_amount' => 1000000, 'paid_at' => now(), 'reference' => 'ref-beta', 'student_name' => 'Beta Kid']);

        $page = $this->page()->assertOk();

        // Today / this week: only today's payment. Term & all time: both successful ones.
        $page->assertSeeInOrder(['Today', '₦50,000.00', '1 payment', 'Since midnight'])
            ->assertSeeInOrder(['This week', '₦50,000.00', '1 payment', 'Since Monday'])
            ->assertSeeInOrder(['First Term', '₦53,000.00', '2 payments', '2026/2027'])
            ->assertSeeInOrder(['All time', '₦53,000.00', '2 payments']);

        // Recent payments (successful only), category breakdown, pending/failed kept secondary.
        // The status badge must read the transaction's own status, not a leaked loop variable.
        $this->assertSame(2, substr_count($page->getContent(), '</span>Success</span>'));
        $page->assertSee('Ada Okonkwo')->assertSee('First Term Tuition')->assertSee("/admin/alpha/transactions/")
            ->assertSee('Collections by category')->assertSeeInOrder(['Tuition', '1', '₦50,000.00'])->assertSeeInOrder(['Uniform', '1', '₦3,000.00'])
            ->assertSee('1 pending payment')->assertSee('status=pending')->assertSee('1 payment not completed')->assertSee('status=failed')
            ->assertDontSee('ref-pending')->assertDontSee('ref-failed')
            ->assertDontSee('₦99,999.00')->assertDontSee('₦88,888.00');

        // Tenant isolation: nothing of beta's.
        $page->assertDontSee('Beta Kid')->assertDontSee('ref-beta')->assertDontSee('1,000,000');
    }

    public function test_service_fee_and_gross_charge_are_never_rendered(): void
    {
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 50000, 'service_fee' => 1250, 'paid_at' => now(), 'category_name' => 'Tuition']);
        $t = $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 20000, 'service_fee' => 500, 'paid_at' => now()]);
        Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => $t->id, 'reference' => 'PO-1', 'amount' => 20000, 'status' => Payout::FAILED, 'last_error' => 'Paystack reported transfer status: failed', 'transfer_code' => 'TRF_x', 'attempts' => 4]);

        $html = $this->page()->assertOk()->getContent();

        // Gross charge for the two payments would be 51,250 and 20,500; the fee is 1,250 / 500.
        foreach (['51,250', '20,500', '71,750', '1,250.00', '500.00', 'service fee', 'Service Fee', 'Service fee', 'charged', 'Total Charged', 'platform fee', 'Platform fee', 'gross', 'TRF_x', 'Paystack reported', 'last_error', 'attempts'] as $needle) {
            $this->assertStringNotContainsString($needle, $html, "Dashboard leaked: {$needle}");
        }
        $this->assertStringContainsString('₦70,000.00', $html);
    }

    public function test_payout_overview_uses_ledger_groupings_and_links_to_the_payouts_page(): void
    {
        $mk = fn (string $ref, string $status, float $amount) => Payout::create([
            'school_id' => $this->alpha->id, 'transaction_id' => $this->makeSuccessfulTransaction($this->alpha, ['reference' => $ref])->id,
            'reference' => $ref, 'amount' => $amount, 'status' => $status,
        ]);
        $mk('PO-pending', Payout::PENDING, 1000);
        $mk('PO-init', Payout::INITIATING, 2000);
        $mk('PO-proc', Payout::PROCESSING, 3000);
        $mk('PO-paid', Payout::SUCCESS, 4000);
        $mk('PO-failed', Payout::FAILED, 5000);
        $mk('PO-review', Payout::NEEDS_REVIEW, 0);
        $mk('PO-rev', Payout::REVERSED, 7000);
        Payout::create(['school_id' => $this->beta->id, 'transaction_id' => $this->makeSuccessfulTransaction($this->beta)->id, 'reference' => 'PO-beta', 'amount' => 900000, 'status' => Payout::SUCCESS]);

        $page = $this->page()->assertOk();
        $page->assertSee('View payouts')->assertSee('/admin/alpha/payouts')
            ->assertSeeInOrder(['Pending', '1 payout', '₦1,000.00'])
            ->assertSeeInOrder(['Processing', '2 payouts', '₦5,000.00'])
            ->assertSeeInOrder(['Paid', '1 payout', '₦4,000.00'])
            ->assertSeeInOrder(['Needs attention', '2 payouts', '₦5,000.00'])
            ->assertSeeInOrder(['Reversed', '1 payout', '₦7,000.00'])
            ->assertSee('2 payouts need attention')
            ->assertSee('status=in_progress')->assertSee('status=attention')
            ->assertDontSee('PO-beta')->assertDontSee('900,000');
    }

    public function test_today_and_week_follow_the_reporting_timezone(): void
    {
        // 23:30 UTC on Tuesday 15 Sep is 00:30 Wednesday 16 Sep in Lagos; the week started Monday 14 Sep 00:00 Lagos.
        Carbon::setTestNow('2026-09-15 23:30:00');
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 111, 'paid_at' => Carbon::parse('2026-09-15 23:10:00')]); // today (Lagos 16 Sep 00:10)
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 222, 'paid_at' => Carbon::parse('2026-09-15 22:50:00')]); // yesterday in Lagos, this week
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 333, 'paid_at' => Carbon::parse('2026-09-13 22:50:00')]); // Sunday 13 Sep 23:50 Lagos: last week

        $page = $this->page()->assertOk();
        $page->assertSeeInOrder(['Today', '₦111.00', '1 payment'])
            ->assertSeeInOrder(['This week', '₦333.00', '2 payments'])
            ->assertSeeInOrder(['All time', '₦666.00', '3 payments'])
            ->assertSee('16 Sep, 00:10')->assertDontSee('15 Sep, 23:10')
            ->assertSee('Africa/Lagos');
    }

    public function test_term_selector_keeps_existing_query_behaviour(): void
    {
        $session = $this->makeSessionWithTerms($this->alpha, '2026/2027');
        $second = $session->terms()->where('number', 2)->first();
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 80000, 'academic_term_id' => $second->id, 'category_name' => 'Tuition']);

        $this->page('term='.$second->id)->assertOk()
            ->assertSeeInOrder(['Second Term', '₦80,000.00', '1 payment', '2026/2027'])
            ->assertSee('Second Term, 2026/2027');

        // Existing controller behaviour, preserved: another school's term id is ignored and
        // the page shows all-time figures with no term context (it does not fall back to
        // the current term). The selector still lists only the school's own terms.
        $betaTerm = $this->makeSessionWithTerms($this->beta, '2026/2027')->terms()->first();
        $this->page('term='.$betaTerm->id)->assertOk()->assertSee('First Term, 2026/2027')
            ->assertSeeInOrder(['Current term', 'No term selected'])->assertSee('All time');
    }
}
