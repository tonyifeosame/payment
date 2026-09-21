<?php

namespace Tests\Feature;

use App\Models\Payout;
use App\Models\School;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * The redesigned payouts area: list, summary, filters, pagination, empty states,
 * the read-only detail page, and — above all — what is never rendered.
 */
class PayoutPageTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private School $beta;

    /** @var array<string, Payout> */
    private array $p = [];

    private Payout $betaPayout;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha', ['bank' => 'GTB', 'account_number' => '0123456789', 'account_name' => 'Alpha School Ltd']);
        $this->beta = $this->makeSchool('Beta School', 'beta');
        $student = $this->makeStudent($this->alpha, 'A/2026/001', 'Adaeze Okonkwo', 'JSS 1');

        $make = function (string $key, string $status, array $extra = []) use ($student) {
            // Provider identifiers/payload that must never reach a page (transfer_code is unique per row).
            $secrets = ['transfer_code' => 'TRF_secret_'.$key, 'transfer_id' => '424242'.$key, 'response' => ['reason' => 'internal provider payload', 'recipient' => 'RCP_x']];
            $t = $this->makeSuccessfulTransaction($this->alpha, [
                'reference' => 'ref-'.$key, 'fee_amount' => 50000, 'service_fee' => 1250, 'paid_at' => '2026-09-10 09:00:00',
                'student_id' => $student->id, 'student_name' => 'Adaeze Okonkwo', 'student_admission_number' => 'A/2026/001',
                'subcategory_name' => 'JSS 1 Tuition', 'category_name' => 'School Fees', 'name' => 'Parent '.$key, 'email' => $key.'@example.test',
            ]);
            $this->p[$key] = Payout::create(array_merge([
                'school_id' => $this->alpha->id, 'transaction_id' => $t->id, 'reference' => 'PO-'.$key, 'amount' => 50000, 'status' => $status,
                'last_error' => 'Paystack reported transfer status: '.$status.' (raw)', 'attempts' => 3,
            ], $secrets, $extra));
        };
        $make('pending', Payout::PENDING);
        $make('initiating', Payout::INITIATING, ['initiated_at' => '2026-09-10 09:05:00']);
        $make('processing', Payout::PROCESSING, ['initiated_at' => '2026-09-10 09:05:00']);
        $make('success', Payout::SUCCESS, ['initiated_at' => '2026-09-10 09:05:00', 'completed_at' => '2026-09-10 09:20:00']);
        $make('failed', Payout::FAILED, ['initiated_at' => '2026-09-10 09:05:00', 'completed_at' => '2026-09-10 09:30:00']);
        $make('review', Payout::NEEDS_REVIEW, ['amount' => 0, 'last_error' => 'No base_amount recorded: the school share cannot be separated']);
        $make('reversed', Payout::REVERSED, ['initiated_at' => '2026-09-10 09:05:00', 'completed_at' => '2026-09-11 09:00:00']);

        $betaTx = $this->makeSuccessfulTransaction($this->beta, ['reference' => 'ref-beta', 'name' => 'Beta Payer', 'email' => 'beta@private.test']);
        $this->betaPayout = Payout::create(['school_id' => $this->beta->id, 'transaction_id' => $betaTx->id, 'reference' => 'PO-beta', 'amount' => 900000, 'status' => Payout::SUCCESS, 'last_error' => 'beta secret error']);
    }

    private function list(string $query = '')
    {
        return $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/payouts'.($query ? '?'.$query : ''));
    }

    public function test_list_loads_with_summary_and_every_status_label(): void
    {
        $page = $this->list()->assertOk();

        $page->assertSee('Payouts')->assertSee('Money movement')->assertSeeText('7 payouts');
        foreach (['Pending', 'Initiating', 'Processing', 'Paid', 'Failed', 'Needs review', 'Reversed'] as $label) {
            $page->assertSee($label);
        }
        // Summary figures come straight from the payout rows: 1 pending, 2 processing, 1 paid, 2 attention.
        $page->assertSee('1 payout · Waiting to be sent')->assertSee('2 payouts · Sent to the bank')
            ->assertSee('1 payout · Reached your account')->assertSee('2 payouts · Failed or under review');
        $page->assertSee('PO-success')->assertSee('ref-success')->assertSee('Adaeze Okonkwo')->assertSee('JSS 1 Tuition')
            ->assertSee("/admin/alpha/payouts/{$this->p['success']->id}");
    }

    public function test_nothing_internal_is_rendered_on_the_list(): void
    {
        $html = $this->list()->assertOk()->getContent();

        foreach (['TRF_secret', '424242', 'internal provider payload', 'RCP_x', 'Paystack reported', '(raw)', 'No base_amount', '3 attempts', 'last_error'] as $secret) {
            $this->assertStringNotContainsString($secret, $html, "List leaked: {$secret}");
        }
        $this->assertStringNotContainsString('PO-beta', $html);
        $this->assertStringNotContainsString('beta secret error', $html);
    }

    public function test_filters_search_dates_and_groups(): void
    {
        $this->list('status=pending')->assertOk()->assertSee('PO-pending')->assertDontSee('PO-success')->assertSeeText('1 payout');
        $this->list('status=in_progress')->assertOk()->assertSee('PO-pending')->assertSee('PO-initiating')->assertSee('PO-processing')->assertDontSee('PO-success')->assertDontSee('PO-failed');
        $this->list('status=attention')->assertOk()->assertSee('PO-failed')->assertSee('PO-review')->assertDontSee('PO-success')->assertDontSee('PO-reversed');
        $this->list('status=bogus')->assertOk()->assertSeeText('7 payouts');

        $this->list('q=PO-reversed')->assertOk()->assertSee('PO-reversed')->assertDontSee('PO-success');
        $this->list('q=ref-failed')->assertOk()->assertSee('PO-failed')->assertDontSee('PO-success');
        $this->list('q=Parent+processing')->assertOk()->assertSee('PO-processing')->assertDontSee('PO-success');
        $this->list('q=Adaeze')->assertOk()->assertSeeText('7 payouts');
        $this->list('q=Beta+Payer')->assertOk()->assertSeeText('0 payouts')->assertDontSee('PO-beta');

        $today = now()->format('Y-m-d');
        $this->list("date_from={$today}")->assertOk()->assertSeeText('7 payouts');
        $this->list('date_to=2000-01-01')->assertOk()->assertSeeText('0 payouts')->assertSee('No payouts match these filters');
        $this->list('date_from=not-a-date')->assertOk()->assertSeeText('7 payouts');
    }

    public function test_empty_states(): void
    {
        $this->list('q=zzz')->assertOk()->assertSee('No payouts match these filters');

        Payout::where('school_id', $this->alpha->id)->whereIn('status', [Payout::FAILED, Payout::NEEDS_REVIEW])->delete();
        $this->list('status=attention')->assertOk()->assertSee('Nothing needs your attention');

        Payout::where('school_id', $this->alpha->id)->where('status', Payout::PENDING)->delete();
        $this->list('status=pending')->assertOk()->assertSee('No pending payouts');

        Payout::where('school_id', $this->alpha->id)->delete();
        $this->list()->assertOk()->assertSee('No payouts yet')->assertSeeText('0 payouts');
    }

    public function test_list_is_paginated_with_query_string_preserved(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $t = $this->makeSuccessfulTransaction($this->alpha, ['reference' => "ref-bulk-{$i}"]);
            Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => $t->id, 'reference' => "PO-bulk-{$i}", 'amount' => 1, 'status' => Payout::SUCCESS]);
        }

        $page = $this->list('status=success')->assertOk();
        $page->assertSeeText('26 payouts')->assertSee('Page 1 of 2')->assertSee('status=success&page=2');
        $this->assertSame(20, substr_count($page->getContent(), 'sr-only"> payout '));
        $this->list('status=success&page=2')->assertOk()->assertSee('PO-success');
    }

    public function test_detail_page_explains_each_state_without_internal_detail(): void
    {
        $show = fn (string $key) => $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/payouts/{$this->p[$key]->id}")->assertOk();

        $page = $show('success');
        $page->assertSee('Paid out')->assertSee('PO-success')->assertSee('₦50,000.00')
            ->assertDontSee('₦51,250.00')->assertDontSee('₦1,250.00')->assertDontSee('ervice fee')
            ->assertSee('ref-success')->assertSee('Adaeze Okonkwo')->assertSee('A/2026/001')->assertSee('JSS 1 Tuition')->assertSee('Parent success')
            ->assertSee('10 Sep 2026, 09:05')->assertSee('10 Sep 2026, 09:20')
            ->assertSee('Payment confirmed')->assertSee('Payout queued')->assertSee('Payout sent to bank')->assertSee('Payout paid')
            ->assertSee('GTB')->assertSee('····6789')->assertSee('Alpha School Ltd')->assertDontSee('0123456789')
            ->assertSee('/payment/receipt/'.$this->p['success']->transaction_id)
            ->assertSee("/admin/alpha/transactions/{$this->p['success']->transaction_id}");

        $show('pending')->assertSee('Awaiting payout')->assertDontSee('Payout sent to bank');
        $show('initiating')->assertSee('Being processed')->assertSee('Initiating')->assertSee('Payout sent to bank');
        $show('processing')->assertSee('Being processed')->assertSee('Processing at bank');
        $show('failed')->assertSee('Needs attention')->assertSee('Payout failed')->assertSee('What you can do')->assertSee('contact support')->assertSee('PO-failed');
        $show('review')->assertSee('Needs review')->assertSee('Payout under review')->assertSee('Being determined')->assertDontSee('₦0.00');
        $show('reversed')->assertSee('Reversed')->assertSee('Payout reversed')->assertSee('11 Sep 2026, 09:00');

        foreach (['success', 'failed', 'review', 'reversed'] as $key) {
            $html = $show($key)->getContent();
            foreach (['TRF_secret', '424242', 'internal provider payload', 'RCP_x', 'Paystack reported', '(raw)', 'No base_amount', 'attempts', 'last_error'] as $secret) {
                $this->assertStringNotContainsString($secret, $html, "Detail ({$key}) leaked: {$secret}");
            }
            // Read-only: the only forms are logout and the shared dialog.
            preg_match_all('/<form[^>]*>/', $html, $forms);
            foreach ($forms[0] as $form) {
                $this->assertTrue(str_contains($form, '/admin/logout') || str_contains($form, 'method="dialog"'), 'Unexpected form: '.$form);
            }
            foreach (['Retry', 'Mark as paid', 'Send again'] as $action) {
                $this->assertStringNotContainsString($action, $html);
            }
        }
    }

    public function test_detail_page_is_tenant_scoped(): void
    {
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/payouts/{$this->betaPayout->id}")->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/beta/payouts/{$this->betaPayout->id}")->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/beta/payouts/{$this->p['success']->id}")->assertNotFound();
        $this->actingAsSchoolAdmin($this->beta)->get("/admin/alpha/payouts/{$this->p['success']->id}")->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/payouts/999999')->assertNotFound();
        $this->actingAsSchoolAdmin($this->alpha)->get('/admin/alpha/payouts/PO-success')->assertNotFound();

        $this->flushSession();
        $this->get("/admin/alpha/payouts/{$this->p['success']->id}")->assertRedirect('/admin/login');
        $this->get('/admin/alpha/payouts')->assertRedirect('/admin/login');

        // Nothing above changed anything.
        $this->assertSame(Payout::SUCCESS, $this->betaPayout->fresh()->status);
        $this->assertDatabaseCount('payouts', 8);
    }

    public function test_a_payout_without_a_transaction_still_renders(): void
    {
        $orphan = Payout::create(['school_id' => $this->alpha->id, 'transaction_id' => null, 'reference' => 'PO-orphan', 'amount' => 12.5, 'status' => Payout::SUCCESS, 'payout_date' => '2025-10-01']);

        $this->list('q=PO-orphan')->assertOk()->assertSee('PO-orphan')->assertSee('No payment attached');
        $this->actingAsSchoolAdmin($this->alpha)->get("/admin/alpha/payouts/{$orphan->id}")->assertOk()
            ->assertSee('PO-orphan')->assertSee('₦12.50')->assertSee('not linked to a single payment')->assertDontSee('What happened');
    }
}
