<?php

namespace Tests\Feature;

use App\Models\Transaction;
use App\Services\SchoolDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InteractsWithSchools;
use Tests\Concerns\RecordsCashPayments;
use Tests\TestCase;

/**
 * Cash recorded by the school is a PAID school fee but never a FEYRA collection.
 * Online collection, revenue and service-fee figures stay exactly what they were;
 * cash appears beside them, labelled, and in every place that answers "is this fee
 * paid?" — the student's history, the Payments list and the CSV export.
 */
class CashReportingSeparationTest extends TestCase
{
    use InteractsWithSchools, RecordsCashPayments, RefreshDatabase;

    private Transaction $online;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
        $this->setUpCashSchool();

        // One online payment: ₦50,000 school share + ₦1,250 service fee.
        $bola = $this->makeStudent($this->alpha, 'ALP/002', 'Bola Ade', 'JSS 1', ['class_level_id' => $this->jss1->id]);
        $this->online = $this->makeSuccessfulTransaction($this->alpha, [
            'reference' => 'ref-online', 'student_id' => $bola->id, 'student_name' => 'Bola Ade',
            'academic_term_id' => $this->firstTerm->id, 'category_name' => 'Uniform', 'payment_method' => 'card',
        ]);
    }

    private function stats(): array
    {
        return app(SchoolDashboardService::class)->build($this->alpha->fresh(), $this->firstTerm);
    }

    public function test_dashboard_collection_totals_are_unchanged_by_cash(): void
    {
        $before = $this->stats();
        $this->recordedCash();
        $after = $this->stats();

        foreach (['today', 'week', 'term_totals', 'all_time'] as $bucket) {
            $this->assertSame($before[$bucket], $after[$bucket], "$bucket collections must not include cash");
            $this->assertSame(['gross' => 51250.0, 'net' => 50000.0, 'count' => 1], $after[$bucket]);
            $this->assertSame(['gross' => 80000.0, 'net' => 80000.0, 'count' => 1], $after['cash'][$bucket]);
        }
        $this->assertEquals($before['by_category'], $after['by_category']);
        $this->assertSame(['Uniform'], $after['by_category']->pluck('category')->all());
    }

    public function test_the_dashboard_shows_cash_separately_and_labelled(): void
    {
        $this->recordedCash();

        $page = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/dashboard')->assertOk();
        $page->assertSeeInOrder(['All time', '₦50,000.00', '1 payment', '+', '₦80,000.00', 'cash recorded by school'])
            ->assertSee('Cash recorded by your school is shown separately and is not included')
            ->assertSee('online payments only; cash recorded by your school is not included')
            // The recent-activity list includes it, labelled.
            ->assertSee('Paid with Cash')
            ->assertSee('Paid with Card');
        $this->assertStringNotContainsString('₦130,000.00', $page->getContent());
    }

    public function test_no_cash_line_without_cash(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/dashboard')
            ->assertOk()->assertDontSee('data-cash-recorded', false);
    }

    public function test_online_revenue_and_service_fee_queries_see_no_cash(): void
    {
        $this->recordedCash();

        $this->assertSame(1250.0, (float) Transaction::forSchool($this->alpha)->successful()->online()->sum('service_fee'));
        $this->assertSame(51250.0, (float) Transaction::forSchool($this->alpha)->successful()->online()->sum('amount'));
        // Cash carries no service fee at all.
        $this->assertSame(0.0, (float) Transaction::manual()->sum('service_fee'));
        // And is still a paid school fee.
        $this->assertTrue(Transaction::paidObligation($this->ada->id, $this->firstTerm->id)->exists());
    }

    public function test_the_students_page_splits_online_and_cash(): void
    {
        $this->recordedCash();
        $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-ada-online', 'student_id' => $this->ada->id, 'fee_amount' => 3000, 'service_fee' => 75, 'payment_method' => 'bank_transfer']);

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/students/'.$this->ada->id)
            ->assertOk()
            ->assertSeeInOrder(['Total fees paid', '₦83,000.00', 'Paid online', '₦3,000.00', 'Paid with cash', '₦80,000.00'])
            ->assertSee('Paid with Cash')
            ->assertSee('Paid with Bank Transfer');
    }

    public function test_the_payments_list_labels_cash_and_filters_by_method(): void
    {
        $cash = $this->recordedCash();
        $admin = fn () => $this->actingAsSchoolAdmin($this->alpha);

        $admin()->get('/admin/alpha/transactions')->assertOk()
            ->assertSee('Paid with Cash')->assertSee('Paid with Card')
            ->assertSee($cash->reference)->assertSee('ref-online');

        $admin()->get('/admin/alpha/transactions?source=cash')->assertOk()
            ->assertSee($cash->reference)->assertDontSee('ref-online')
            ->assertSee('Method: Cash recorded by school');
        $admin()->get('/admin/alpha/transactions?source=online')->assertOk()
            ->assertSee('ref-online')->assertDontSee($cash->reference);
    }

    public function test_the_payments_csv_has_source_and_receipt_number(): void
    {
        $cash = $this->recordedCash(['receipt_number' => '=HYPERLINK("x")']);

        $csv = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions/export')->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")))));
        $header = $rows[0];
        $this->assertSame(['Source', 'Receipt No.'], array_slice($header, -2));

        $byRef = collect(array_slice($rows, 1))->keyBy(0);
        $cashRow = array_combine($header, $byRef[$cash->reference]);
        $onlineRow = array_combine($header, $byRef['ref-online']);

        $this->assertSame('Cash', $cashRow['Source']);
        $this->assertSame('cash', $cashRow['Payment Method']);
        $this->assertSame('', $cashRow['Paystack Reference']);
        $this->assertSame('80000.00', $cashRow['Fee Amount (NGN)']);
        $this->assertSame('', $cashRow['Payout Status']);
        // Receipt numbers are typed by the school: neutralised like every other cell.
        $this->assertSame("'=HYPERLINK(\"x\")", $cashRow['Receipt No.']);

        $this->assertSame('Paystack', $onlineRow['Source']);
        $this->assertSame('', $onlineRow['Receipt No.']);
    }

    public function test_voided_cash_leaves_every_total_and_the_default_list(): void
    {
        $cash = $this->recordedCash();
        app(\App\Services\ManualPaymentService::class)->void($this->alpha, $cash, 'Recorded in error');

        $stats = $this->stats();
        $this->assertSame(0, $stats['cash']['all_time']['count']);
        $this->assertSame(['gross' => 51250.0, 'net' => 50000.0, 'count' => 1], $stats['all_time']);

        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions')->assertDontSee($cash->reference);
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions?status=voided')->assertSee($cash->reference)->assertSee('Cash — Voided');

        $csv = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions/export')->streamedContent();
        $this->assertStringNotContainsString($cash->reference, $csv);
    }

    public function test_another_schools_cash_never_reaches_this_schools_reports(): void
    {
        $this->recordedCash();
        $betaStats = app(SchoolDashboardService::class)->build($this->beta, null);

        $this->assertSame(0, $betaStats['cash']['all_time']['count']);
        $this->assertSame(0, $betaStats['all_time']['count']);
        $this->actingAsSchoolAdmin($this->beta)->get('/admin/beta/transactions?status=all')->assertDontSee('CASH-');
    }
}
