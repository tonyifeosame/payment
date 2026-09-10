<?php

namespace Tests\Feature;

use App\Mail\PaymentReceiptMail;
use App\Models\Category;
use App\Models\School;
use App\Models\Subcategory;
use App\Models\Transaction;
use App\Services\PaymentSettlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regression tests for E9 — the receipt email could not render at all.
 *
 * PaymentReceiptMail's template is built from Markdown mail components, but the
 * mailable rendered it with ->view(), which never registers the `mail` view
 * namespace. Every receipt threw "No hint path defined for [mail]".
 *
 * These tests deliberately DO NOT use Mail::fake(): a fake intercepts the mailable
 * before it is ever rendered, which is exactly why the original bug survived a
 * green test suite. Everything here exercises the real renderer and the real
 * queue worker.
 */
class ReceiptMailRenderingTest extends TestCase
{
    use RefreshDatabase;

    private Transaction $transaction;

    /** Base 50,000 + 2.5% markup = 51,250 actually charged. */
    private const BASE = 50000.00;

    private const GROSS = 51250.00;

    private const GROSS_KOBO = 5125000;

    protected function setUp(): void
    {
        parent::setUp();

        // A correctly configured deployment supplies these; .env.example ships
        // MAIL_FROM_ADDRESS empty, which Symfony rejects (reported separately).
        config([
            'mail.default' => 'array',
            'mail.from.address' => 'noreply@school.test',
            'mail.from.name' => 'School Fees Portal',
            'services.paystack.secret_key' => 'sk_test_secret',
        ]);

        $school = School::create([
            'name' => 'Greenfield Academy', 'slug' => 'greenfield',
            'email' => 'admin@greenfield.test', 'admin_password' => Hash::make('password123'),
        ]);
        $category = Category::create(['name' => 'School Fees', 'school_id' => $school->id]);
        $subcategory = Subcategory::create([
            'category_id' => $category->id, 'name' => 'Primary', 'price' => self::BASE, 'school_id' => $school->id,
        ]);

        $this->transaction = Transaction::create([
            'school_id' => $school->id,
            'category_id' => $category->id,
            'subcategory_id' => $subcategory->id,
            'reference' => 'greenfield-ref-001',
            'amount' => self::GROSS,
            'status' => 'pending',
            'email' => 'parent@example.test',
            'name' => 'Ada Parent',
            'category_name' => 'School Fees',
            'subcategory_name' => 'Primary',
            'meta_data' => [
                'quantity' => 1,
                'base_amount' => self::BASE,
                'markup_amount' => 1250,
                'gross_amount' => self::GROSS,
            ],
        ]);
    }

    private function fakeSuccessfulVerify(): void
    {
        Http::fake([
            '*transaction/verify*' => Http::response([
                'status' => true,
                'data' => [
                    'status' => 'success', 'channel' => 'card',
                    'reference' => 'greenfield-ref-001',
                    'amount' => self::GROSS_KOBO, 'currency' => 'NGN',
                ],
            ], 200),
            // Settlement also queues a payout transfer; stub it so this suite stays
            // focused on the receipt and makes no outbound calls.
            '*/transfer' => Http::response([
                'status' => true,
                'data' => ['transfer_code' => 'TRF_R', 'id' => 77, 'status' => 'pending'],
            ], 200),
        ]);
    }

    /** Drain every queued job, not just the first. */
    private function drainQueue(): void
    {
        Artisan::call('queue:work', ['--stop-when-empty' => true, '--tries' => 1]);
    }

    /** Receipt jobs currently waiting on the queue. */
    private function queuedReceiptJobs(): int
    {
        return DB::table('jobs')->get()
            ->filter(fn ($j) => str_contains($j->payload, 'PaymentReceiptMail'))
            ->count();
    }

    private function sentMessages(): array
    {
        return app('mailer')->getSymfonyTransport()->messages()->all();
    }

    // =====================================================================
    // Rendering
    // =====================================================================

    public function test_receipt_mail_renders_without_throwing(): void
    {
        $html = (new PaymentReceiptMail($this->transaction))->render();

        $this->assertNotEmpty($html);
        $this->assertStringNotContainsString('No hint path defined', $html);
    }

    public function test_markdown_is_actually_converted_to_html(): void
    {
        $html = (new PaymentReceiptMail($this->transaction))->render();
        $text = strip_tags($html);

        // Converted...
        $this->assertStringContainsString('<h1', $html, 'the "# Official Payment Receipt" heading was not converted');
        $this->assertStringContainsString('<table', $html, 'the transaction table was not converted');

        // ...and therefore no raw Markdown left in the body.
        $this->assertStringNotContainsString('# Official', $text);
        $this->assertStringNotContainsString('|---|', $text);
        $this->assertStringNotContainsString('**Total Amount:**', $text);
    }

    public function test_receipt_content_and_data_are_preserved(): void
    {
        $html = (new PaymentReceiptMail($this->transaction))->render();
        $text = html_entity_decode(strip_tags($html));

        $this->assertStringContainsString('Official Payment Receipt', $text);
        $this->assertStringContainsString('Greenfield Academy', $text);
        $this->assertStringContainsString('Ada Parent', $text);
        $this->assertStringContainsString('parent@example.test', $text);
        $this->assertStringContainsString('greenfield-ref-001', $text);
        $this->assertStringContainsString('School Fees', $text);
        $this->assertStringContainsString('Primary', $text);
    }

    public function test_receipt_renders_for_a_transaction_with_deleted_relations(): void
    {
        // category_id / subcategory_id are ON DELETE SET NULL, so this shape is real.
        $this->transaction->forceFill(['category_id' => null, 'subcategory_id' => null])->save();

        $html = (new PaymentReceiptMail($this->transaction->fresh()))->render();

        $this->assertStringContainsString('Official Payment Receipt', strip_tags($html));
    }

    // =====================================================================
    // Settlement -> queue -> worker, end to end, no Mail::fake()
    // =====================================================================

    public function test_settlement_queues_exactly_one_receipt_job(): void
    {
        config(['queue.default' => 'database']);
        $this->fakeSuccessfulVerify();

        app(PaymentSettlementService::class)->settleByReference('greenfield-ref-001');

        $this->assertSame('success', $this->transaction->refresh()->status);
        $this->assertSame(1, $this->queuedReceiptJobs(), 'expected exactly one receipt job');
    }

    public function test_the_queued_receipt_job_runs_and_sends_the_email(): void
    {
        config(['queue.default' => 'database']);
        $this->fakeSuccessfulVerify();

        app(PaymentSettlementService::class)->settleByReference('greenfield-ref-001');
        $this->drainQueue();

        $this->assertSame(0, DB::table('jobs')->count(), 'the receipt job did not complete');
        $this->assertSame(0, DB::table('failed_jobs')->count(), 'the receipt job failed');

        $messages = $this->sentMessages();
        $this->assertCount(1, $messages);

        $email = $messages[0]->getOriginalMessage();
        $this->assertSame('Your Payment Receipt', $email->getSubject());
        $this->assertSame('parent@example.test', $email->getTo()[0]->getAddress());
        $this->assertStringContainsString('Official Payment Receipt', strip_tags($email->getHtmlBody()));
    }

    public function test_replays_do_not_queue_or_send_a_second_receipt(): void
    {
        config(['queue.default' => 'database']);
        $this->fakeSuccessfulVerify();

        app(PaymentSettlementService::class)->settleByReference('greenfield-ref-001');
        $this->drainQueue();

        // Replay the settlement several times, through the service and the callback.
        app(PaymentSettlementService::class)->settleByReference('greenfield-ref-001');
        $this->get('/payment/callback?reference=greenfield-ref-001');
        app(PaymentSettlementService::class)->settleByReference('greenfield-ref-001');
        $this->drainQueue();

        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertSame(0, DB::table('failed_jobs')->count());
        $this->assertCount(1, $this->sentMessages(), 'a replay produced a duplicate receipt');
    }

    public function test_a_rejected_payment_never_produces_a_receipt(): void
    {
        config(['queue.default' => 'database']);
        Http::fake(['*transaction/verify*' => Http::response([
            'status' => true,
            'data' => ['status' => 'success', 'channel' => 'card',
                       'reference' => 'greenfield-ref-001', 'amount' => 1, 'currency' => 'NGN'],
        ], 200)]);

        app(PaymentSettlementService::class)->settleByReference('greenfield-ref-001');
        $this->drainQueue();

        $this->assertSame('mismatch', $this->transaction->refresh()->status);
        $this->assertSame(0, DB::table('jobs')->count());
        $this->assertCount(0, $this->sentMessages());
    }

    // =====================================================================
    // E10 — the total must be what the payer was actually charged
    // =====================================================================

    public function test_receipt_total_is_the_gross_amount_actually_charged(): void
    {
        $text = html_entity_decode(strip_tags((new PaymentReceiptMail($this->transaction))->render()));
        $normalised = preg_replace('/\s+/', ' ', $text);

        // The payer was charged 51,250.00 (base 50,000 + 2.5% service fee).
        $this->assertStringContainsString('Total Amount Paid: ₦51,250.00', $normalised);
        $this->assertSame(51250.00, $this->transaction->receiptBreakdown()['total']);
    }

    public function test_receipt_discloses_the_service_fee_rather_than_hiding_it(): void
    {
        $text = html_entity_decode(strip_tags((new PaymentReceiptMail($this->transaction))->render()));
        $normalised = preg_replace('/\s+/', ' ', $text);

        $this->assertStringContainsString('Fee Subtotal: ₦50,000.00', $normalised);
        $this->assertStringContainsString('Service Fee: ₦1,250.00', $normalised);
        $this->assertStringContainsString('Total Amount Paid: ₦51,250.00', $normalised);
    }
}
