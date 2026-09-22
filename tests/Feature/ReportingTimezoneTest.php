<?php

namespace Tests\Feature;

use App\Mail\PaymentReceiptMail;
use App\Models\Payout;
use App\Models\School;
use App\Models\Student;
use App\Models\Transaction;
use App\Support\BusinessTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * M3 — every reporting surface quotes the same clock.
 *
 * Storage stays UTC. What changed is the edge: before this, only the dashboard
 * converted to the reporting zone, so a payment settled at 23:20 UTC was
 * "16 Sep, 00:20" on the dashboard and "15 Sep 23:20" on the transaction list,
 * the CSV, the payout ledger and the parent's receipt — the same money on two
 * different days.
 *
 * The fixture below is that exact window: 2026-09-15 23:20 UTC is
 * 2026-09-16 00:20 in Lagos (WAT, UTC+1, no DST). Every assertion here says
 * "16 Sep", and the date filters agree with what the page prints — which is the
 * half that would have been easy to miss, since a display-only fix would have
 * left a row shown as 16 Sep outside a date_from=2026-09-16 filter.
 */
class ReportingTimezoneTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    /** 2026-09-15 23:20 UTC === 2026-09-16 00:20 Africa/Lagos. */
    private const PAID_AT_UTC = '2026-09-15 23:20:00';

    private School $school;

    private Transaction $transaction;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('UTC', config('app.timezone'), 'these tests assume UTC storage');
        config(['fees.reporting_timezone' => 'Africa/Lagos']);

        $this->school = $this->makeSchool('Alpha School', 'alpha');
        $this->student = $this->makeStudent($this->school, 'A/2026/001', 'Adaeze Okonkwo');
        $this->transaction = $this->makeSuccessfulTransaction($this->school, [
            'reference' => 'ref-late-night',
            'fee_amount' => 50000,
            'paid_at' => Carbon::parse(self::PAID_AT_UTC),
            'created_at' => Carbon::parse(self::PAID_AT_UTC),
            'student_id' => $this->student->id,
            'student_name' => 'Adaeze Okonkwo',
            'student_admission_number' => 'A/2026/001',
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): static
    {
        return $this->actingAsSchoolAdmin($this->school);
    }

    // ------------------------------------------------- every surface agrees

    public function test_the_transaction_list_dates_the_payment_on_the_schools_clock(): void
    {
        $this->admin()->get('/admin/alpha/transactions')
            ->assertOk()
            ->assertSee('16 Sep 2026')
            ->assertSee('00:20')
            ->assertDontSee('15 Sep 2026');
    }

    public function test_the_transaction_detail_dates_the_payment_on_the_schools_clock(): void
    {
        $this->admin()->get('/admin/alpha/transactions/'.$this->transaction->id)
            ->assertOk()
            ->assertSee('16 Sep 2026 at 00:20')
            ->assertDontSee('15 Sep 2026 at 23:20');
    }

    public function test_the_student_history_dates_the_payment_on_the_schools_clock(): void
    {
        $this->admin()->get('/admin/alpha/students/'.$this->student->id)
            ->assertOk()
            ->assertSee('16 Sep 2026')
            ->assertDontSee('15 Sep 2026');
    }

    public function test_the_dashboard_dates_the_payment_on_the_schools_clock(): void
    {
        Carbon::setTestNow('2026-09-16 00:30:00'); // 01:30 Lagos, same business day

        $this->admin()->get('/admin/alpha/dashboard')
            ->assertOk()
            ->assertSee('16 Sep, 00:20')
            ->assertDontSee('15 Sep, 23:20');
    }

    public function test_the_receipt_dates_the_payment_on_the_schools_clock_and_names_the_zone(): void
    {
        $this->admin()->get('/payment/receipt/'.$this->transaction->id)
            ->assertOk()
            ->assertSee('16 Sep 2026, 12:20 AM WAT')
            ->assertDontSee('15 Sep 2026, 11:20 PM');
    }

    public function test_the_pdf_receipt_dates_the_payment_on_the_schools_clock(): void
    {
        $html = view('payment.receipt_pdf', [
            'transaction' => $this->transaction->fresh(),
            'school' => $this->school,
            'logoDataUri' => null,
        ])->render();

        $this->assertStringContainsString('16 Sep 2026, 12:20 AM WAT', $html);
        $this->assertStringNotContainsString('15 Sep 2026, 11:20 PM', $html);
    }

    public function test_the_email_receipt_dates_the_payment_on_the_schools_clock(): void
    {
        $html = (new PaymentReceiptMail($this->transaction->fresh()))->render();

        $this->assertStringContainsString('16 Sep 2026, 12:20 AM WAT', $html);
        $this->assertStringNotContainsString('15 Sep 2026, 11:20 PM', $html);
    }

    public function test_the_csv_export_dates_the_payment_on_the_schools_clock_and_labels_the_column(): void
    {
        $response = $this->admin()->get('/admin/alpha/transactions/export')->assertOk();

        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")))));

        $this->assertSame('Date Paid (WAT)', $rows[0][2], 'the export does not say which clock it quotes');
        $this->assertSame('2026-09-16 00:20:00', $rows[1][2]);
    }

    public function test_the_payout_ledger_dates_rows_on_the_schools_clock(): void
    {
        $payout = $this->payout();

        $this->admin()->get('/admin/alpha/payouts')
            ->assertOk()
            ->assertSee('16 Sep 2026')
            ->assertSee('00:25');

        $this->admin()->get('/admin/alpha/payouts/'.$payout->id)
            ->assertOk()
            ->assertSee('16 Sep 2026, 00:25')   // created
            ->assertSee('16 Sep 2026, 00:40')   // sent to bank
            ->assertSee('16 Sep 2026, 00:55');  // paid
    }

    // ------------------------------------- display and filters move together

    public function test_a_date_filter_matches_the_day_the_list_prints(): void
    {
        // The page says 16 Sep, so the 16 Sep filter must find it...
        $this->admin()->get('/admin/alpha/transactions?date_from=2026-09-16')
            ->assertOk()->assertSee('ref-late-night');

        $this->admin()->get('/admin/alpha/transactions?date_to=2026-09-16')
            ->assertOk()->assertSee('ref-late-night');

        // ...and the 15th, which is the UTC date, must not.
        $this->admin()->get('/admin/alpha/transactions?date_to=2026-09-15')
            ->assertOk()->assertDontSee('ref-late-night');

        $this->admin()->get('/admin/alpha/transactions?date_from=2026-09-17')
            ->assertOk()->assertDontSee('ref-late-night');
    }

    public function test_the_export_honours_the_same_business_day_boundaries(): void
    {
        $inside = $this->admin()->get('/admin/alpha/transactions/export?date_from=2026-09-16')->assertOk();
        $this->assertStringContainsString('ref-late-night', $inside->streamedContent());

        $outside = $this->admin()->get('/admin/alpha/transactions/export?date_to=2026-09-15')->assertOk();
        $this->assertStringNotContainsString('ref-late-night', $outside->streamedContent());
    }

    public function test_the_payout_filter_matches_the_day_the_ledger_prints(): void
    {
        $this->payout();

        $this->admin()->get('/admin/alpha/payouts?date_from=2026-09-16')
            ->assertOk()->assertSee('PO-late-night');

        $this->admin()->get('/admin/alpha/payouts?date_to=2026-09-15')
            ->assertOk()->assertDontSee('PO-late-night');
    }

    public function test_the_payout_filter_is_echoed_back_as_the_day_that_was_typed(): void
    {
        $this->payout();

        // The bound is stored as 15 Sep 23:00 UTC; the form and the chip must
        // still read 2026-09-16, or the admin sees their filter change itself.
        $this->admin()->get('/admin/alpha/payouts?date_from=2026-09-16')
            ->assertOk()
            ->assertSee('value="2026-09-16"', false)
            ->assertDontSee('value="2026-09-15"', false);
    }

    // ---------------------------------------------------- nothing hardcoded

    public function test_every_surface_follows_the_configured_zone(): void
    {
        config(['fees.reporting_timezone' => 'UTC']);
        $this->payout();

        // With storage and reporting in the same zone, every surface says 15 Sep.
        $this->admin()->get('/admin/alpha/transactions')->assertOk()->assertSee('15 Sep 2026')->assertSee('23:20');
        $this->admin()->get('/payment/receipt/'.$this->transaction->id)->assertOk()->assertSee('15 Sep 2026, 11:20 PM UTC');
        $this->admin()->get('/admin/alpha/payouts')->assertOk()->assertSee('15 Sep 2026');

        $csv = $this->admin()->get('/admin/alpha/transactions/export')->assertOk()->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")))));
        $this->assertSame('Date Paid (UTC)', $rows[0][2]);
        $this->assertSame('2026-09-15 23:20:00', $rows[1][2]);

        // And the filter boundary follows it too.
        $this->admin()->get('/admin/alpha/transactions?date_to=2026-09-15')->assertOk()->assertSee('ref-late-night');
    }

    public function test_a_zone_without_an_abbreviation_falls_back_to_its_identifier(): void
    {
        config(['fees.reporting_timezone' => 'Africa/Lagos']);
        $this->assertSame('WAT', BusinessTime::label());

        // Asia/Kathmandu formats as "+0545"; the identifier reads better than that.
        config(['fees.reporting_timezone' => 'Asia/Kathmandu']);
        $this->assertSame('Asia/Kathmandu', BusinessTime::label(), 'a numeric offset is not a useful column label');
    }

    // ------------------------------------------------------- storage is UTC

    public function test_the_stored_instant_is_untouched(): void
    {
        $raw = (string) \DB::table('transactions')->where('id', $this->transaction->id)->value('paid_at');

        $this->assertStringStartsWith('2026-09-15 23:20:00', $raw, 'M3 must not rewrite stored timestamps');
        $this->assertSame('UTC', BusinessTime::storageZone());
    }

    public function test_the_time_element_carries_the_reporting_offset(): void
    {
        $this->admin()->get('/admin/alpha/transactions')
            ->assertOk()
            ->assertSee('2026-09-16T00:20:00+01:00', false);
    }

    /** A payout for the fixture transaction, minted in the same late-night window. */
    private function payout(): Payout
    {
        $payout = Payout::firstOrCreate(
            ['reference' => 'PO-late-night'],
            [
                'school_id' => $this->school->id,
                'transaction_id' => $this->transaction->id,
                'amount' => 50000,
                'status' => Payout::SUCCESS,
                'attempts' => 1,
                'initiated_at' => Carbon::parse('2026-09-15 23:40:00'),
                'completed_at' => Carbon::parse('2026-09-15 23:55:00'),
            ]
        );

        // created_at/updated_at are not fillable, so they arrive as "now"; pin them
        // into the same late-night window with the timestamp hooks switched off.
        $payout->timestamps = false;
        $payout->forceFill([
            'created_at' => Carbon::parse('2026-09-15 23:25:00'),
            'updated_at' => Carbon::parse('2026-09-15 23:55:00'),
        ])->save();
        $payout->timestamps = true;

        return $payout;
    }
}
