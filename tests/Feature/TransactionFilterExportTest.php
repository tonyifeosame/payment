<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Payout;
use App\Models\School;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Phase 1 — transaction list filters and CSV export, all tenant-scoped.
 */
class TransactionFilterExportTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    private Transaction $tuition;

    private Transaction $uniform;

    private Transaction $pending;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');

        $session = $this->makeSessionWithTerms($this->alpha, '2026/2027');
        $first = $session->terms()->where('number', 1)->first();
        $second = $session->terms()->where('number', 2)->first();
        $fees = Category::create(['school_id' => $this->alpha->id, 'name' => 'School Fees']);
        $uniformCat = Category::create(['school_id' => $this->alpha->id, 'name' => 'Uniform']);
        $student = $this->makeStudent($this->alpha, 'A/2026/001', 'Adaeze Okonkwo', 'JSS 1');

        $this->tuition = $this->makeSuccessfulTransaction($this->alpha, [
            'reference' => 'ref-tuition', 'fee_amount' => 50000, 'paid_at' => '2026-09-10 09:00:00',
            'student_id' => $student->id, 'student_name' => 'Adaeze Okonkwo', 'student_admission_number' => 'A/2026/001', 'student_class' => 'JSS 1',
            'academic_session_id' => $session->id, 'academic_term_id' => $first->id, 'session_name' => '2026/2027', 'term_name' => 'First Term',
            'category_id' => $fees->id, 'category_name' => 'School Fees', 'subcategory_name' => 'JSS 1 Tuition',
            'name' => 'Parent Okonkwo', 'email' => 'okonkwo@example.test',
        ]);
        $this->uniform = $this->makeSuccessfulTransaction($this->alpha, [
            'reference' => 'ref-uniform', 'fee_amount' => 3000, 'paid_at' => '2026-09-01 09:00:00',
            'academic_session_id' => $session->id, 'academic_term_id' => $second->id, 'session_name' => '2026/2027', 'term_name' => 'Second Term',
            'category_id' => $uniformCat->id, 'category_name' => 'Uniform', 'subcategory_name' => 'Shirt',
            'name' => 'Parent Bakare', 'email' => 'bakare@example.test',
        ]);
        $this->pending = $this->makeSuccessfulTransaction($this->alpha, [
            'reference' => 'ref-pending', 'status' => 'pending', 'paid_at' => null, 'fee_amount' => 80000,
            'name' => 'Parent Pending', 'email' => 'pending@example.test', 'category_name' => 'School Fees',
        ]);

        $this->makeSuccessfulTransaction($this->beta, [
            'reference' => 'ref-beta', 'fee_amount' => 999999, 'name' => 'Beta Payer', 'email' => 'beta@private.test',
            'student_name' => 'Beta Student', 'student_admission_number' => 'A/2026/001', // same number, other school
        ]);
    }

    private function list(string $query = '')
    {
        return $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions'.($query ? '?'.$query : ''));
    }

    public function test_default_list_shows_only_successful_payments(): void
    {
        $this->list()
            ->assertOk()
            ->assertSee('ref-tuition')
            ->assertSee('ref-uniform')
            ->assertDontSee('ref-pending')
            ->assertDontSee('ref-beta')
            ->assertDontSee('Beta Payer');
    }

    public function test_status_filter_can_show_pending_without_counting_it_as_a_collection(): void
    {
        $this->list('status=pending')
            ->assertOk()
            ->assertSee('ref-pending')
            ->assertDontSee('ref-tuition');

        $this->list('status=all')
            ->assertOk()
            ->assertSee('ref-pending')
            ->assertSee('ref-tuition');

        // An unknown status is ignored rather than widening the query.
        $this->list('status=%27%20OR%201=1')->assertOk()->assertDontSee('ref-beta');
    }

    public function test_search_matches_student_name_admission_number_payer_and_reference(): void
    {
        $this->list('q=Adaeze')->assertOk()->assertSee('ref-tuition')->assertDontSee('ref-uniform');
        $this->list('q=a/2026/001')->assertOk()->assertSee('ref-tuition')->assertDontSee('ref-uniform')->assertDontSee('Beta Student');
        $this->list('q=bakare')->assertOk()->assertSee('ref-uniform')->assertDontSee('ref-tuition');
        $this->list('q=bakare@example.test')->assertOk()->assertSee('ref-uniform')->assertDontSee('ref-tuition');
        $this->list('q=ref-uniform')->assertOk()->assertSee('ref-uniform')->assertDontSee('ref-tuition');
        // Beta's data is unreachable through search.
        $this->list('q=Beta')->assertOk()->assertDontSee('ref-beta')->assertDontSee('beta@private.test');
    }

    public function test_date_category_session_and_term_filters(): void
    {
        $this->list('date_from=2026-09-05')->assertOk()->assertSee('ref-tuition')->assertDontSee('ref-uniform');
        $this->list('date_to=2026-09-05')->assertOk()->assertSee('ref-uniform')->assertDontSee('ref-tuition');
        $this->list('date_from=2026-09-01&date_to=2026-09-01')->assertOk()->assertSee('ref-uniform')->assertDontSee('ref-tuition');
        $this->list('date_from=not-a-date')->assertOk()->assertSee('ref-tuition')->assertSee('ref-uniform');

        $this->list('category_id='.$this->tuition->category_id)->assertOk()->assertSee('ref-tuition')->assertDontSee('ref-uniform');
        $this->list('term_id='.$this->uniform->academic_term_id)->assertOk()->assertSee('ref-uniform')->assertDontSee('ref-tuition');
        $this->list('session_id='.$this->tuition->academic_session_id)->assertOk()->assertSee('ref-tuition')->assertSee('ref-uniform');
    }

    public function test_filters_cannot_reach_another_schools_rows(): void
    {
        $beta = Transaction::where('reference', 'ref-beta')->first();
        $betaTerm = $this->makeSessionWithTerms($this->beta)->terms()->first();
        $beta->update(['academic_term_id' => $betaTerm->id, 'academic_session_id' => $betaTerm->academic_session_id, 'category_id' => Category::create(['school_id' => $this->beta->id, 'name' => 'B'])->id]);

        $this->list('term_id='.$betaTerm->id)->assertOk()->assertDontSee('ref-beta');
        $this->list('session_id='.$betaTerm->academic_session_id)->assertOk()->assertDontSee('ref-beta');
        $this->list('category_id='.$beta->category_id)->assertOk()->assertDontSee('ref-beta');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/beta/transactions')->assertNotFound();
    }

    public function test_export_contains_only_the_schools_rows_with_student_and_fee_columns(): void
    {
        Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => $this->tuition->id, 'reference' => 'PO-tuition', 'amount' => 50000, 'status' => Payout::SUCCESS]);

        $response = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions/export?status=all');

        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        $this->assertStringContainsString('alpha-transactions-', (string) $response->headers->get('Content-Disposition'));

        $csv = $response->streamedContent();
        $rows = array_map('str_getcsv', array_filter(explode("\n", trim(ltrim($csv, "\xEF\xBB\xBF")))));

        $this->assertSame([
            'Reference', 'Paystack Reference', 'Date Paid', 'Status',
            'Student', 'Admission Number', 'Class', 'Session', 'Term',
            'Category', 'Fee Type', 'Quantity',
            'Payer Name', 'Payer Email', 'Payment Method',
            'Fee Amount (NGN)',
            'Payout Status', 'Payout Reference',
        ], $rows[0]);

        // The platform service fee and the gross charge are never exported to the school.
        $this->assertStringNotContainsString('Service Fee', $csv);
        $this->assertStringNotContainsString('Total Charged', $csv);
        $this->assertStringNotContainsString('1250.00', $csv);
        $this->assertStringNotContainsString('51250.00', $csv);

        $byRef = collect(array_slice($rows, 1))->keyBy(0);
        $this->assertEqualsCanonicalizing(['ref-tuition', 'ref-uniform', 'ref-pending'], $byRef->keys()->all());
        $this->assertArrayNotHasKey('ref-beta', $byRef->all());

        $tuition = $byRef['ref-tuition'];
        $this->assertSame('success', $tuition[3]);
        $this->assertSame('Adaeze Okonkwo', $tuition[4]);
        $this->assertSame('A/2026/001', $tuition[5]);
        $this->assertSame('JSS 1', $tuition[6]);
        $this->assertSame('2026/2027', $tuition[7]);
        $this->assertSame('First Term', $tuition[8]);
        $this->assertSame('School Fees', $tuition[9]);
        $this->assertSame('JSS 1 Tuition', $tuition[10]);
        $this->assertSame('Parent Okonkwo', $tuition[12]);
        $this->assertSame('50000.00', $tuition[15]);
        $this->assertSame('success', $tuition[16]);
        $this->assertSame('PO-tuition', $tuition[17]);
        $this->assertCount(18, $tuition);

        $this->assertStringNotContainsString('beta@private.test', $csv);
    }

    public function test_export_honours_the_same_filters_as_the_list(): void
    {
        $csv = $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions/export?q=Adaeze')->streamedContent();

        $this->assertStringContainsString('ref-tuition', $csv);
        $this->assertStringNotContainsString('ref-uniform', $csv);
        $this->assertStringNotContainsString('ref-pending', $csv); // default status filter = success
    }

    public function test_export_is_protected_like_the_list(): void
    {
        $this->get('/admin/alpha/transactions/export')->assertRedirect('/admin/login');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/beta/transactions/export')->assertNotFound();
    }
}
