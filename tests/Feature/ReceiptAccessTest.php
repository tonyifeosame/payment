<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Regression tests for F3 — receipt IDOR.
 *
 * /payment/receipt/{transaction} used to be public with sequential ids, so anyone
 * could enumerate every payer's name, email, amount and reference.
 */
class ReceiptAccessTest extends TestCase
{
    use RefreshDatabase;

    private School $alpha;

    private School $beta;

    private Transaction $alphaTransaction;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = $this->makeSchool('Alpha School', 'alpha');
        $this->beta = $this->makeSchool('Beta School', 'beta');

        $this->alphaTransaction = Transaction::create([
            'school_id' => $this->alpha->id,
            'reference' => 'alpha-ref-1',
            'amount' => 51250,
            'status' => 'success',
            'email' => 'payer@private.test',
            'name' => 'PrivatePayerName',
            'category_name' => 'School Fees',
            'subcategory_name' => 'Primary',
            'meta_data' => ['quantity' => 1, 'base_amount' => 50000],
        ]);
    }

    private function makeSchool(string $name, string $slug): School
    {
        return School::create([
            'name' => $name,
            'slug' => $slug,
            'email' => $slug.'@example.test',
            'admin_password' => Hash::make('password123'),
            'account_number' => '0123456789',
            'bank' => 'GTB',
            'bank_code' => '058',
            'account_name' => 'Acct '.$name,
        ]);
    }

    // ---------------------------------------------------------------
    // (c) Anonymous users cannot enumerate receipts
    // ---------------------------------------------------------------

    public function test_anonymous_user_cannot_view_an_arbitrary_receipt(): void
    {
        $this->get("/payment/receipt/{$this->alphaTransaction->id}")->assertNotFound();
    }

    public function test_anonymous_user_cannot_download_an_arbitrary_receipt(): void
    {
        $this->get("/payment/receipt/{$this->alphaTransaction->id}/download")->assertNotFound();
    }

    public function test_receipt_enumeration_leaks_nothing(): void
    {
        for ($id = 1; $id <= 5; $id++) {
            $response = $this->get("/payment/receipt/{$id}");
            $response->assertNotFound();
            $response->assertDontSee('payer@private.test');
            $response->assertDontSee('PrivatePayerName');
        }
    }

    public function test_missing_and_existing_ids_are_indistinguishable(): void
    {
        // Both 404: the endpoint must not confirm which transaction ids exist.
        $existing = $this->get("/payment/receipt/{$this->alphaTransaction->id}")->status();
        $missing = $this->get('/payment/receipt/999999')->status();

        $this->assertSame(404, $existing);
        $this->assertSame($existing, $missing);
    }

    public function test_admin_of_another_school_cannot_view_the_receipt(): void
    {
        $this->withSession(['school_admin_id' => $this->beta->id])
            ->get("/payment/receipt/{$this->alphaTransaction->id}")
            ->assertNotFound();
    }

    public function test_tampered_signature_is_rejected(): void
    {
        $signed = URL::signedRoute('payment.receipt', ['transaction' => $this->alphaTransaction->id]);

        $this->get($signed.'x')->assertNotFound();
        $this->get(str_replace('signature=', 'signature=deadbeef', $signed))->assertNotFound();
    }

    public function test_a_signature_for_one_receipt_does_not_open_another(): void
    {
        $other = Transaction::create([
            'school_id' => $this->beta->id,
            'reference' => 'beta-ref-1',
            'amount' => 1000,
            'status' => 'success',
            'email' => 'other@private.test',
            'name' => 'OtherPayer',
            'meta_data' => ['base_amount' => 1000],
        ]);

        $signed = URL::signedRoute('payment.receipt', ['transaction' => $this->alphaTransaction->id]);
        $swapped = str_replace(
            "/payment/receipt/{$this->alphaTransaction->id}?",
            "/payment/receipt/{$other->id}?",
            $signed
        );

        $this->get($swapped)->assertNotFound();
    }

    // ---------------------------------------------------------------
    // (d) Legitimate receipt access still works
    // ---------------------------------------------------------------

    public function test_signed_url_grants_access(): void
    {
        $signed = URL::signedRoute('payment.receipt', ['transaction' => $this->alphaTransaction->id]);

        $this->get($signed)
            ->assertOk()
            ->assertSee('PrivatePayerName')
            ->assertSee('alpha-ref-1');
    }

    public function test_signed_download_url_grants_access_and_returns_an_attachment(): void
    {
        $signed = URL::signedRoute('payment.receipt.download', ['transaction' => $this->alphaTransaction->id]);

        $response = $this->get($signed);

        $response->assertOk();
        // Phase 1: the download is a branded PDF named by the payment reference.
        $response->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('receipt-alpha-ref-1.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    public function test_paying_session_can_view_its_own_receipt(): void
    {
        // What the payer gets immediately after the Paystack callback.
        $this->withSession(['last_transaction_id' => $this->alphaTransaction->id])
            ->get("/payment/receipt/{$this->alphaTransaction->id}")
            ->assertOk()
            ->assertSee('PrivatePayerName');
    }

    public function test_paying_session_cannot_view_a_different_receipt(): void
    {
        $other = Transaction::create([
            'school_id' => $this->beta->id,
            'reference' => 'beta-ref-2',
            'amount' => 1000,
            'status' => 'success',
            'email' => 'other@private.test',
            'name' => 'OtherPayer',
            'meta_data' => ['base_amount' => 1000],
        ]);

        $this->withSession(['last_transaction_id' => $this->alphaTransaction->id])
            ->get("/payment/receipt/{$other->id}")
            ->assertNotFound();
    }

    public function test_owning_school_admin_can_view_the_receipt(): void
    {
        $this->withSession(['school_admin_id' => $this->alpha->id])
            ->get("/payment/receipt/{$this->alphaTransaction->id}")
            ->assertOk()
            ->assertSee('PrivatePayerName');
    }

    public function test_receipt_page_offers_a_working_signed_download_link(): void
    {
        // An authorized viewer must be able to click "Download" and still be authorized.
        $html = $this->withSession(['last_transaction_id' => $this->alphaTransaction->id])
            ->get("/payment/receipt/{$this->alphaTransaction->id}")
            ->getContent();

        $this->assertMatchesRegularExpression('/receipt\/\d+\/download\?[^"]*signature=/', $html);

        preg_match('/href="([^"]*\/download\?[^"]*signature=[^"]*)"/', $html, $m);
        $downloadUrl = html_entity_decode($m[1]);

        // Fresh session — proves the link itself carries the authorization.
        $this->flushSession();
        $this->get($downloadUrl)->assertOk();
    }
}
