<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\School;
use App\Services\SchoolDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Phase 1 — the school dashboard: every figure is a sum over the acting school's
 * own successful transactions, and only successful ones.
 */
class DashboardTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-09-16 10:00:00'); // a Wednesday

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_dashboard_requires_login_and_the_right_school(): void
    {
        $this->get('/s/alpha/dashboard')->assertRedirect('/admin/login');
        $this->actingAsSchoolAdmin($this->alpha)->get('/s/beta/dashboard')->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->get('/s/alpha/dashboard')->assertOk()->assertSee('Dashboard');
    }

    public function test_login_lands_on_the_dashboard(): void
    {
        $this->post('/admin/login', ['name' => 'alpha school', 'password' => 'password123'])
            ->assertRedirect('/s/alpha/dashboard');
    }

    public function test_totals_are_tenant_scoped_and_only_count_successful_payments(): void
    {
        // Alpha: two successes today, one last week, one pending, one failed.
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 50000, 'paid_at' => now()]);
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 3000, 'paid_at' => now()->subHours(2)]);
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 20000, 'paid_at' => now()->subDays(9)]);
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 99999, 'status' => 'pending', 'paid_at' => null]);
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 99999, 'status' => 'failed', 'paid_at' => null]);
        // Beta: a big success today that must never leak into alpha's numbers.
        $this->makeSuccessfulTransaction($this->beta, ['fee_amount' => 1000000, 'paid_at' => now()]);

        $stats = app(SchoolDashboardService::class)->build($this->alpha, null);

        $this->assertEquals(53000.00, $stats['today']['net']);
        $this->assertEquals(54325.00, $stats['today']['gross']); // 53000 * 1.025
        $this->assertSame(2, $stats['today']['count']);

        $this->assertEquals(53000.00, $stats['week']['net']); // the 9-day-old one is outside this week
        $this->assertEquals(73000.00, $stats['all_time']['net']);
        $this->assertSame(3, $stats['all_time']['count']);

        $this->assertSame(3, $stats['status_counts']['success']);
        $this->assertSame(1, $stats['status_counts']['pending']);
        $this->assertSame(1, $stats['status_counts']['failed']);

        $this->assertCount(3, $stats['recent']);
        $this->assertTrue($stats['recent']->every(fn ($t) => (int) $t->school_id === $this->alpha->id && $t->status === 'success'));
    }

    public function test_term_context_totals_and_category_grouping(): void
    {
        $session = $this->makeSessionWithTerms($this->alpha);
        $first = $session->terms()->where('number', 1)->first();
        $second = $session->terms()->where('number', 2)->first();

        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 50000, 'academic_term_id' => $first->id, 'category_name' => 'School Fees']);
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 3000, 'academic_term_id' => $first->id, 'category_name' => 'Uniform']);
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 4000, 'academic_term_id' => $first->id, 'category_name' => 'Uniform']);
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 80000, 'academic_term_id' => $second->id, 'category_name' => 'School Fees']);
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 1, 'status' => 'pending', 'academic_term_id' => $first->id, 'category_name' => 'School Fees']);

        $stats = app(SchoolDashboardService::class)->build($this->alpha, $first);

        $this->assertEquals(57000.00, $stats['term_totals']['net']);
        $this->assertSame(3, $stats['term_totals']['count']);

        $byCategory = $stats['by_category']->keyBy('category');
        $this->assertEquals(50000.00, (float) $byCategory['School Fees']->net);
        $this->assertEquals(7000.00, (float) $byCategory['Uniform']->net);
        $this->assertSame(2, (int) $byCategory['Uniform']->count);

        // The page honours ?term= for its own terms only.
        $this->actingAsSchoolAdmin($this->alpha)
            ->get('/s/alpha/dashboard?term='.$second->id)
            ->assertOk()
            ->assertSee('Second Term, 2026/2027');

        $betaTerm = $this->makeSessionWithTerms($this->beta)->terms()->first();
        $page = $this->actingAsSchoolAdmin($this->alpha)->get('/s/alpha/dashboard?term='.$betaTerm->id)->assertOk();
        // Falls back to the school's own current term rather than using beta's.
        $page->assertSee('First Term, 2026/2027');
    }

    public function test_payout_summary_reflects_the_ledger_states(): void
    {
        $t1 = $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 50000]);
        $t2 = $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 20000]);
        $t3 = $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 7000]);
        $t4 = $this->makeSuccessfulTransaction($this->beta, ['fee_amount' => 500000]);

        Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => $t1->id, 'reference' => 'PO-1', 'amount' => 50000, 'status' => Payout::SUCCESS]);
        Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => $t2->id, 'reference' => 'PO-2', 'amount' => 20000, 'status' => Payout::PROCESSING]);
        Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => $t3->id, 'reference' => 'PO-3', 'amount' => 7000, 'status' => Payout::FAILED, 'last_error' => 'Recipient not available']);
        Payout::create(['school_id' => $this->beta->id, 'transaction_id' => $t4->id, 'reference' => 'PO-4', 'amount' => 500000, 'status' => Payout::SUCCESS]);

        $summary = app(SchoolDashboardService::class)->payoutSummary($this->alpha);

        $this->assertEquals(50000.00, $summary['paid']['amount']);
        $this->assertEquals(20000.00, $summary['in_progress']['amount']);
        $this->assertEquals(7000.00, $summary['attention']['amount']);
        $this->assertCount(3, $summary['recent']);

        $this->actingAsSchoolAdmin($this->alpha)
            ->get('/s/alpha/dashboard')
            ->assertOk()
            ->assertSee('50,000.00')
            ->assertDontSee('500,000.00');
    }
}
