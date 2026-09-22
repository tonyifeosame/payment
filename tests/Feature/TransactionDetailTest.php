<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Payout;
use App\Models\School;
use App\Models\Student;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Admin transaction detail page and the student payment history: what they show,
 * that every figure comes from receiptBreakdown()/the payout row, and that neither
 * can reach another school's records.
 */
class TransactionDetailTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    private Student $student;

    private Transaction $paid;

    private Transaction $pending;

    private Transaction $betaTx;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');

        $session = $this->makeSessionWithTerms($this->alpha, '2026/2027');
        $first = $session->terms()->where('number', 1)->first();
        $fees = Category::create(['school_id' => $this->alpha->id, 'name' => 'School Fees']);
        $this->student = $this->makeStudent($this->alpha, 'A/2026/001', 'Adaeze Okonkwo', 'JSS 1');

        $this->paid = $this->makeSuccessfulTransaction($this->alpha, [
            'reference' => 'ref-paid', 'paystack_reference' => 'PSK-paid-123', 'fee_amount' => 50000, 'service_fee' => 1250,
            'paid_at' => '2026-09-10 09:00:00', 'payment_method' => 'card',
            'student_id' => $this->student->id, 'student_name' => 'Adaeze Okonkwo', 'student_admission_number' => 'A/2026/001', 'student_class' => 'JSS 1',
            'academic_session_id' => $session->id, 'academic_term_id' => $first->id, 'session_name' => '2026/2027', 'term_name' => 'First Term',
            'category_id' => $fees->id, 'category_name' => 'School Fees', 'subcategory_name' => 'JSS 1 Tuition',
            'name' => 'Parent Okonkwo', 'email' => 'okonkwo@example.test',
            'meta_data' => ['quantity' => 2, 'base_amount' => 50000, 'markup_amount' => 1250],
        ]);
        $this->pending = $this->makeSuccessfulTransaction($this->alpha, [
            'reference' => 'ref-pending', 'status' => 'pending', 'paid_at' => null, 'fee_amount' => 80000,
            'student_id' => $this->student->id, 'student_name' => 'Adaeze Okonkwo',
            'name' => 'Parent Pending', 'email' => 'pending@example.test', 'subcategory_name' => 'Pending Fee',
        ]);
        $this->betaTx = $this->makeSuccessfulTransaction($this->beta, [
            'reference' => 'ref-beta', 'fee_amount' => 999999, 'name' => 'Beta Payer', 'email' => 'beta@private.test',
        ]);
    }

    private function show(Transaction $t, string $slug = 'alpha')
    {
        return $this->actingAsSchoolAdmin($this->alpha)->get("/admin/{$slug}/transactions/{$t->id}");
    }

    // ---------------------------------------------------------------- detail page

    public function test_successful_transaction_detail_shows_trusted_amounts_and_records(): void
    {
        $b = $this->paid->receiptBreakdown();
        $page = $this->show($this->paid)->assertOk();

        // Figures are exactly what receiptBreakdown() reports, nowhere recomputed —
        // and only the school's share: the platform service fee and the gross total
        // the parent was charged are never shown to the school admin.
        $page->assertSee('₦'.number_format($b['fee_subtotal'], 2))
            ->assertSee('2 × ₦'.number_format($b['unit_price'], 2))
            ->assertDontSee('₦'.number_format($b['total'], 2))
            ->assertDontSee('₦'.number_format($b['service_fee'], 2))
            ->assertDontSee('ervice fee');
        $this->assertSame(51250.0, $b['total']);
        $this->assertSame(1250.0, $b['service_fee']);

        $page->assertSee('ref-paid')->assertSee('PSK-paid-123')->assertSee('10 Sep 2026')
            ->assertSee('JSS 1 Tuition')->assertSee('School Fees')
            ->assertSee('Adaeze Okonkwo')->assertSee('A/2026/001')->assertSee('First Term, 2026/2027')
            ->assertSee('Parent Okonkwo')->assertSee('okonkwo@example.test')
            ->assertSee('Payment confirmed')
            ->assertSee("/admin/alpha/students/{$this->student->id}")
            ->assertSee('/payment/receipt/'.$this->paid->id)
            ->assertSee('/payment/receipt/'.$this->paid->id.'/download?signature=');
    }

    public function test_pending_transaction_detail_has_no_receipt_and_no_payout(): void
    {
        $this->show($this->pending)->assertOk()
            ->assertSee('ref-pending')->assertSee('Pending Fee')
            ->assertSee('Awaiting confirmation')
            ->assertSee('Issued once the payment is confirmed')
            ->assertSee('A payout is only created once the payment is confirmed')
            ->assertDontSee('/payment/receipt/'.$this->pending->id)
            ->assertDontSee('Payment confirmed');
    }

    public function test_detail_page_has_no_state_changing_controls(): void
    {
        $html = $this->show($this->paid)->assertOk()->getContent();

        // The only forms on an admin page are the sidebar logout and the shared
        // confirm dialog (method="dialog"); nothing posts to a transaction.
        preg_match_all('/<form[^>]*>/', $html, $forms);
        $this->assertNotEmpty($forms[0]);
        foreach ($forms[0] as $form) {
            $this->assertTrue(
                str_contains($form, '/admin/logout') || str_contains($form, 'method="dialog"'),
                'Unexpected form on the transaction detail page: '.$form
            );
        }
        $this->assertStringNotContainsStringIgnoringCase('mark as paid', $html);
        $this->assertStringNotContainsStringIgnoringCase('retry', $html);
    }

    public function test_payout_states_are_explained_without_internal_detail(): void
    {
        $payout = Payout::create([
            'school_id' => $this->alpha->id, 'transaction_id' => $this->paid->id, 'reference' => 'PO-paid', 'amount' => 50000,
            'status' => Payout::SUCCESS, 'transfer_code' => 'TRF_secret', 'transfer_id' => '987654',
            'initiated_at' => '2026-09-10 09:05:00', 'completed_at' => '2026-09-10 09:20:00',
            'response' => ['reason' => 'internal provider payload'],
        ]);

        $page = $this->show($this->paid)->assertOk();
        $page->assertSee('Paid out')->assertSee('PO-paid')->assertSee('₦50,000.00')
            ->assertSee('Payout queued')->assertSee('Payout sent to bank')->assertSee('Payout paid')
            // Stored 09:05/09:20 UTC, shown on the school's clock (M3): WAT is UTC+1.
            ->assertSee('10 Sep 2026, 10:05')->assertSee('10 Sep 2026, 10:20')
            ->assertDontSee('TRF_secret')->assertDontSee('987654')->assertDontSee('internal provider payload');

        $payout->forceFill(['status' => Payout::FAILED, 'last_error' => 'Paystack reported transfer status: failed (code 4xx)', 'completed_at' => '2026-09-10 09:30:00'])->save();
        $this->show($this->paid)->assertOk()
            ->assertSee('Needs attention')->assertSee('Payout failed')->assertSee('contact support')
            ->assertDontSee('Paystack reported')->assertDontSee('code 4xx');

        $payout->forceFill(['status' => Payout::PROCESSING, 'last_error' => null])->save();
        $this->show($this->paid)->assertOk()->assertSee('Being processed')->assertSee('Processing at bank');

        $payout->forceFill(['status' => Payout::PENDING, 'initiated_at' => null])->save();
        $this->show($this->paid)->assertOk()->assertSee('Awaiting payout')->assertDontSee('Payout sent to bank');

        $payout->forceFill(['status' => Payout::NEEDS_REVIEW, 'amount' => 0, 'last_error' => 'No base_amount recorded'])->save();
        $this->show($this->paid)->assertOk()->assertSee('Under review')->assertSee('Payout under review')
            ->assertDontSee('No base_amount recorded')->assertDontSee('₦0.00');
    }

    public function test_successful_payment_without_payout_row_says_so(): void
    {
        $this->show($this->paid)->assertOk()->assertSee('No payout recorded yet');
    }

    // ------------------------------------------------------------ tenant isolation

    public function test_transaction_from_another_school_is_not_found(): void
    {
        // Alpha's admin, alpha's URL, beta's id: scoped binding 404s.
        $this->show($this->betaTx)->assertNotFound();
        // Alpha's admin on beta's URL: EnsureSchoolAdmin 404s.
        $this->show($this->betaTx, 'beta')->assertNotFound();
        $this->show($this->paid, 'beta')->assertNotFound();
        // Nonexistent and non-numeric ids.
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions/999999')->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions/ref-paid')->assertNotFound();
        // Guests are sent to login; the export route is untouched by the new one.
        $this->flushSession();
        $this->get("/admin/alpha/transactions/{$this->paid->id}")->assertRedirect('/admin/login');
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions/export?status=all')->assertOk()
            ->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    }

    public function test_detail_page_is_read_only(): void
    {
        foreach (['post', 'put', 'patch', 'delete'] as $method) {
            $this->actingAsSchoolAdmin($this->alpha)->{$method}("/admin/alpha/transactions/{$this->paid->id}")->assertStatus(405);
        }
    }

    public function test_transaction_list_links_to_the_detail_page(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/transactions?status=all')->assertOk()
            ->assertSee("/admin/alpha/transactions/{$this->paid->id}")
            ->assertSee("/admin/alpha/transactions/{$this->pending->id}")
            ->assertSee('/payment/receipt/'.$this->paid->id)
            ->assertDontSee('/payment/receipt/'.$this->pending->id)
            ->assertDontSee('ref-beta');
    }

    // --------------------------------------------------------- student history

    public function test_student_history_shows_own_payments_and_hides_failed_attempts_by_default(): void
    {
        $other = $this->makeStudent($this->alpha, 'A/2026/002', 'Other Student');
        $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-other-student', 'student_id' => $other->id, 'student_name' => 'Other Student']);
        $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'ref-failed', 'status' => 'failed', 'paid_at' => null, 'student_id' => $this->student->id]);
        $this->makeSuccessfulTransaction($this->beta, ['reference' => 'ref-beta-same-student-id', 'student_id' => $this->student->id, 'student_name' => 'Adaeze Okonkwo']);

        $page = $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$this->student->id}")->assertOk();
        $page->assertSee('JSS 1 Tuition')->assertSee('Pending Fee')->assertSee('₦50,000.00')
            ->assertSee("/admin/alpha/transactions/{$this->paid->id}")
            ->assertSee('/payment/receipt/'.$this->paid->id)
            ->assertSee('2 payments')
            ->assertSee('Show 1 unsuccessful attempt')
            ->assertDontSee('ref-other-student')->assertDontSee('Other Student')
            ->assertDontSee('ref-failed')->assertDontSee('Failed')
            ->assertDontSee('ref-beta-same-student-id');

        $all = $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$this->student->id}?attempts=1")->assertOk();
        $all->assertSee('Failed')->assertSee('3 records')->assertSee('Hide 1 unsuccessful attempt')
            ->assertDontSee('ref-beta-same-student-id')->assertDontSee('ref-other-student');
    }

    public function test_student_with_no_payments_sees_empty_state(): void
    {
        $fresh = $this->makeStudent($this->alpha, 'A/2026/003', 'Fresh Student');

        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$fresh->id}")->assertOk()
            ->assertSee('No payments yet')->assertSee('0 payments')->assertDontSee('unsuccessful');
    }

    public function test_student_history_is_paginated(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->makeSuccessfulTransaction($this->alpha, ['reference' => "ref-bulk-{$i}", 'student_id' => $this->student->id]);
        }

        $page = $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$this->student->id}")->assertOk();
        $page->assertSee('27 payments')->assertSee('?page=2');
        $this->assertSame(20, substr_count($page->getContent(), 'sr-only"> transaction '));

        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$this->student->id}?page=2")->assertOk()->assertSee('ref-paid');
    }

    public function test_student_history_cannot_cross_tenants(): void
    {
        $betaStudent = $this->makeStudent($this->beta, 'B/2026/001', 'Beta Student');
        $this->makeSuccessfulTransaction($this->beta, ['reference' => 'ref-beta-student', 'student_id' => $betaStudent->id, 'student_name' => 'Beta Student']);

        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$betaStudent->id}")->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/beta/students/{$betaStudent->id}")->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/students/{$betaStudent->id}?attempts=1")->assertNotFound();
    }
}
