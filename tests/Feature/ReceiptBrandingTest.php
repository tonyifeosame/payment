<?php

namespace Tests\Feature;

use App\Mail\PaymentReceiptMail;
use App\Models\School;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Phase 1 — branded receipts with student context, on screen, as PDF and by
 * email, under the same authorization model as before.
 */
class ReceiptBrandingTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private School $alpha;

    private Transaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha', [
            'phone' => '0801 234 5678',
            'address' => '1 Alpha Road, Lagos',
            'receipt_footer' => 'Bursary hours: 8am-4pm. Fees are not refundable.',
        ]);

        $this->transaction = $this->makeSuccessfulTransaction($this->alpha, [
            'reference' => 'alpha-ref-1',
            'fee_amount' => 50000,
            'service_fee' => 1250,
            'student_name' => 'Adaeze Okonkwo',
            'student_admission_number' => 'A/2026/001',
            'student_class' => 'JSS 1',
            'session_name' => '2026/2027',
            'term_name' => 'First Term',
            'subcategory_name' => 'JSS 1 Tuition',
            'name' => 'Parent Okonkwo',
            'email' => 'okonkwo@example.test',
        ]);
    }

    private function expectBrandedContent($response): void
    {
        $response
            ->assertSee('Alpha School')
            ->assertSee('0801 234 5678')
            ->assertSee('1 Alpha Road, Lagos')
            ->assertSee('Adaeze Okonkwo')
            ->assertSee('A/2026/001')
            ->assertSee('JSS 1')
            ->assertSee('2026/2027')
            ->assertSee('First Term')
            ->assertSee('JSS 1 Tuition')
            ->assertSee('alpha-ref-1')
            ->assertSee('50,000.00')
            ->assertSee('1,250.00')
            ->assertSee('51,250.00')
            ->assertSee('Bursary hours: 8am-4pm. Fees are not refundable.');
    }

    public function test_receipt_page_shows_branding_and_student_context(): void
    {
        $this->expectBrandedContent(
            $this->get(URL::signedRoute('payment.receipt', ['transaction' => $this->transaction->id]))->assertOk()
        );
    }

    public function test_receipt_page_shows_the_logo_when_the_school_has_one(): void
    {
        $this->giveLogo($this->alpha);

        $this->get(URL::signedRoute('payment.receipt', ['transaction' => $this->transaction->id]))
            ->assertOk()
            ->assertSee('/s/alpha/logo', false);
    }

    public function test_pdf_download_is_a_real_pdf_with_the_same_content(): void
    {
        $this->giveLogo($this->alpha);

        $response = $this->get(URL::signedRoute('payment.receipt.download', ['transaction' => $this->transaction->id]));

        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('receipt-alpha-ref-1.pdf', (string) $response->headers->get('Content-Disposition'));

        $pdf = $response->getContent();
        $this->assertStringStartsWith('%PDF', $pdf);
        $this->assertGreaterThan(2000, strlen($pdf));
        // Dompdf embeds the image as an XObject; its presence proves the logo path was read.
        $this->assertStringContainsString('/Subtype /Image', $pdf);

        // And the rendered HTML the PDF is built from carries the same content as the page.
        $html = view('payment.receipt_pdf', [
            'transaction' => $this->transaction->fresh()->load('school'),
            'school' => $this->alpha->fresh(),
            'logoDataUri' => $this->alpha->fresh()->logoDataUri(),
        ])->render();
        foreach (['Alpha School', 'Adaeze Okonkwo', 'A/2026/001', 'JSS 1', '2026/2027', 'First Term', 'alpha-ref-1', '50,000.00', '1,250.00', '51,250.00', 'Fees are not refundable', 'data:image/png;base64,'] as $needle) {
            $this->assertStringContainsString($needle, $html);
        }
    }

    public function test_receipt_authorization_is_unchanged(): void
    {
        $id = $this->transaction->id;

        // Unsigned, anonymous: 404 on both, for the page and the PDF.
        $this->get("/payment/receipt/{$id}")->assertNotFound();
        $this->get("/payment/receipt/{$id}/download")->assertNotFound();

        // Another school's admin: 404.
        $beta = $this->makeSchool('Beta School', 'beta');
        $this->actingAsSchoolAdmin($beta)->get("/payment/receipt/{$id}")->assertNotFound();
        $this->actingAsSchoolAdmin($beta)->get("/payment/receipt/{$id}/download")->assertNotFound();
        $this->flushSession();

        // Tampered signature: 404.
        $signed = URL::signedRoute('payment.receipt.download', ['transaction' => $id]);
        $this->get(preg_replace('/signature=\w{6}/', 'signature=000000', $signed))->assertNotFound();

        // Owning admin, paying session, valid signature: OK.
        $this->actingAsSchoolAdmin($this->alpha)->get("/payment/receipt/{$id}/download")->assertOk();
        $this->flushSession();
        $this->withSession(['last_transaction_id' => $id])->get("/payment/receipt/{$id}/download")->assertOk();
        $this->flushSession();
        $this->get($signed)->assertOk();
    }

    public function test_receipt_email_includes_student_context_and_still_links_signed(): void
    {
        $rendered = (new PaymentReceiptMail($this->transaction->fresh()))->render();

        foreach (['Alpha School', 'Adaeze Okonkwo', 'A/2026/001', 'JSS 1', '2026/2027', 'First Term', 'alpha-ref-1', '51,250.00', 'Fees are not refundable', '0801 234 5678'] as $needle) {
            $this->assertStringContainsString($needle, $rendered);
        }

        $this->assertMatchesRegularExpression('#/payment/receipt/'.$this->transaction->id.'\?[^"]*signature=#', $rendered);
    }

    public function test_receipt_without_student_context_still_renders(): void
    {
        $legacy = $this->makeSuccessfulTransaction($this->alpha, ['reference' => 'legacy-1']);

        $this->get(URL::signedRoute('payment.receipt', ['transaction' => $legacy->id]))
            ->assertOk()
            ->assertSee('legacy-1')
            ->assertDontSee('Admission No.');

        $this->get(URL::signedRoute('payment.receipt.download', ['transaction' => $legacy->id]))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringNotContainsString('Admission Number', (new PaymentReceiptMail($legacy))->render());
    }
}
