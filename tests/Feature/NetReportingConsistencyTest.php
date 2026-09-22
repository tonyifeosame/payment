<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Student;
use App\Models\Transaction;
use App\Services\SchoolDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * L9 — every surface that reports "the school's share" must report the same
 * number for the same payment.
 *
 * receiptBreakdown() is the authority, and for a row whose metadata carries no
 * usable `base_amount` it answers "the whole charge": there is nothing to split
 * by, so nothing was the platform's. The transaction list, the CSV export, the
 * dashboard's recent-payments list and the student history rows all read it.
 *
 * The aggregates did not. They summed `fee_amount`, which those rows do not
 * have — they predate the column, and the backfill migration could only
 * populate the ones whose metadata let it. A legacy payment therefore counted
 * as ₦0 in the dashboard tiles and in a student's total, while the rows
 * immediately beneath showed its full amount.
 */
class NetReportingConsistencyTest extends TestCase
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

    /** A row exactly as the pre-column era left it: no fee_amount, no usable meta. */
    private function legacyTransaction(School $school, float $amount, ?Student $student = null): Transaction
    {
        $transaction = $this->makeSuccessfulTransaction($school, [
            'reference' => 'legacy-'.uniqid(),
            'amount' => $amount,
            'student_id' => $student?->id,
            'student_name' => $student?->full_name,
        ]);

        // Straight to the database: the model always writes these, and the point
        // is the shape that existed before it did.
        DB::table('transactions')->where('id', $transaction->id)->update([
            'fee_amount' => null,
            'service_fee' => null,
            'meta_data' => json_encode(['quantity' => 1]),   // no base_amount
        ]);

        return $transaction->fresh();
    }

    private function stats(School $school): array
    {
        return app(SchoolDashboardService::class)->build($school, null);
    }

    /** What the list and the CSV would show for the school's share. */
    private function breakdownTotal(School $school): float
    {
        return round(Transaction::forSchool($school)->successful()->get()
            ->sum(fn (Transaction $t) => $t->receiptBreakdown()['fee_subtotal']), 2);
    }

    // ------------------------------------------------ the regression guard

    public function test_a_normal_transaction_is_unchanged(): void
    {
        // fee_amount 50000, service fee 1250, charged 51250.
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 50000]);

        $stats = $this->stats($this->alpha);

        $this->assertEqualsWithDelta(50000.00, $stats['all_time']['net'], 0.001);
        $this->assertEqualsWithDelta(51250.00, $stats['all_time']['gross'], 0.001);
        $this->assertEqualsWithDelta(50000.00, $this->breakdownTotal($this->alpha), 0.001);
    }

    public function test_a_transaction_with_valid_breakdown_metadata_still_reports_its_base_amount(): void
    {
        $this->makeSuccessfulTransaction($this->alpha, [
            'fee_amount' => 30000,
            'service_fee' => 750,
            'amount' => 30750,
            'meta_data' => ['quantity' => 1, 'base_amount' => 30000, 'markup_amount' => 750],
        ]);

        $stats = $this->stats($this->alpha);

        $this->assertEqualsWithDelta(30000.00, $stats['all_time']['net'], 0.001);
        $this->assertEqualsWithDelta(30000.00, $this->breakdownTotal($this->alpha), 0.001);
        $this->assertEqualsWithDelta(30750.00, $stats['all_time']['gross'], 0.001);
    }

    // --------------------------------------------------------- the L9 case

    public function test_a_legacy_transaction_agrees_across_dashboard_list_and_csv(): void
    {
        $this->legacyTransaction($this->alpha, 40000);

        $stats = $this->stats($this->alpha);

        // Before L9 this was 0.00 while the list and CSV showed 40000.00.
        $this->assertEqualsWithDelta(40000.00, $stats['all_time']['net'], 0.001);
        $this->assertEqualsWithDelta(40000.00, $this->breakdownTotal($this->alpha), 0.001);
        $this->assertEqualsWithDelta(40000.00, $stats['all_time']['gross'], 0.001);

        // And the rendered surfaces agree with each other.
        $this->actingAsSchoolAdmin($this->alpha)
            ->get('/admin/alpha/transactions')->assertOk()->assertSee('40,000.00');

        $csv = $this->actingAsSchoolAdmin($this->alpha)
            ->get('/admin/alpha/transactions/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('40000.00', $csv);
    }

    public function test_the_category_breakdown_agrees_for_a_legacy_transaction(): void
    {
        $this->legacyTransaction($this->alpha, 40000);

        $row = $this->stats($this->alpha)['by_category']->first();

        $this->assertEqualsWithDelta(40000.00, (float) $row->net, 0.001);
        $this->assertEqualsWithDelta(40000.00, (float) $row->gross, 0.001);
    }

    public function test_a_mixed_ledger_totals_the_same_both_ways(): void
    {
        $this->makeSuccessfulTransaction($this->alpha, ['fee_amount' => 50000]);   // net 50000
        $this->legacyTransaction($this->alpha, 40000);                             // net 40000
        $this->makeSuccessfulTransaction($this->alpha, [
            'fee_amount' => 30000, 'service_fee' => 750, 'amount' => 30750,
            'meta_data' => ['quantity' => 1, 'base_amount' => 30000, 'markup_amount' => 750],
        ]);                                                                        // net 30000

        $this->assertEqualsWithDelta(120000.00, $this->stats($this->alpha)['all_time']['net'], 0.001);
        $this->assertEqualsWithDelta(120000.00, $this->breakdownTotal($this->alpha), 0.001);
    }

    public function test_a_students_total_agrees_with_the_payments_listed_under_it(): void
    {
        $student = $this->makeStudent($this->alpha, 'A/2026/001', 'Adaeze Okonkwo');

        $this->makeSuccessfulTransaction($this->alpha, [
            'fee_amount' => 50000, 'student_id' => $student->id, 'student_name' => 'Adaeze Okonkwo',
        ]);
        $this->legacyTransaction($this->alpha, 40000, $student);

        // The page shows this total immediately above the rows it totals, and
        // those rows read receiptBreakdown(). Before L9 it said 50,000.00.
        $this->actingAsSchoolAdmin($this->alpha)
            ->get('/admin/alpha/students/'.$student->id)
            ->assertOk()
            ->assertSee('90,000.00')
            ->assertSee('40,000.00');
    }

    // ---------------------------------------------------- tenant isolation

    public function test_one_schools_legacy_rows_never_reach_another_schools_totals(): void
    {
        $this->legacyTransaction($this->alpha, 40000);
        $this->legacyTransaction($this->beta, 99000);
        $this->makeSuccessfulTransaction($this->beta, ['fee_amount' => 11000]);

        $this->assertEqualsWithDelta(40000.00, $this->stats($this->alpha)['all_time']['net'], 0.001);
        $this->assertEqualsWithDelta(110000.00, $this->stats($this->beta)['all_time']['net'], 0.001);

        // The fallback did not widen the scope of anything.
        $this->assertEqualsWithDelta(40000.00, $this->breakdownTotal($this->alpha), 0.001);
        $this->assertEqualsWithDelta(110000.00, $this->breakdownTotal($this->beta), 0.001);
    }

    public function test_a_students_total_is_scoped_to_its_own_school(): void
    {
        $alphaStudent = $this->makeStudent($this->alpha, 'A/2026/001', 'Adaeze Okonkwo');
        $this->legacyTransaction($this->alpha, 40000, $alphaStudent);
        $this->legacyTransaction($this->beta, 99000);

        $this->actingAsSchoolAdmin($this->alpha)
            ->get('/admin/alpha/students/'.$alphaStudent->id)
            ->assertOk()
            ->assertSee('40,000.00')
            ->assertDontSee('99,000.00');
    }

    // ------------------------------------------------ unsettled rows excluded

    public function test_only_settled_payments_count_towards_net(): void
    {
        $this->legacyTransaction($this->alpha, 40000);

        $pending = $this->makeSuccessfulTransaction($this->alpha, ['amount' => 77000, 'status' => 'pending']);
        DB::table('transactions')->where('id', $pending->id)->update(['fee_amount' => null]);

        // The fallback must not drag an unsettled charge into the total.
        $this->assertEqualsWithDelta(40000.00, $this->stats($this->alpha)['all_time']['net'], 0.001);
        $this->assertSame(1, $this->stats($this->alpha)['all_time']['count']);
    }
}
