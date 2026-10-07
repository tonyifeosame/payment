<?php

namespace Tests\Feature;

use App\Mail\PaymentReceiptMail;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * L8: the receipt email is a Markdown mail. A value with a blank line in it
 * ended the surrounding raw-HTML block, and what followed was parsed as
 * Markdown — so a payer name could plant a link in a FEYRA-branded receipt sent
 * to any address the payer typed. Values are now single lines, link/image syntax
 * is encoded, and the payer name refuses control characters at checkout.
 */
class ReceiptEmailInjectionTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private function receiptFor(array $overrides, array $schoolOverrides = []): string
    {
        $school = $this->makeSchool('Alpha School', 'alpha', $schoolOverrides);
        $t = Transaction::create(array_merge([
            'school_id' => $school->id, 'reference' => 'ref-1', 'amount' => 102.5, 'status' => 'success', 'paid_at' => now(),
            'email' => 'payer@example.test', 'name' => 'Ada Parent', 'student_name' => 'Tobi Ade', 'student_class' => 'JSS 1',
            'student_admission_number' => 'A/001', 'category_name' => 'School fees', 'subcategory_name' => 'Tuition',
            'meta_data' => ['quantity' => 1, 'base_amount' => 100, 'markup_amount' => 2.5, 'gross_amount' => 102.5],
        ], $overrides));

        return (new PaymentReceiptMail($t))->render();
    }

    private function assertNoInjectedMarkup(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('#<a[^>]+attacker\.example#i', $html, 'an attacker link was rendered');
        $this->assertDoesNotMatchRegularExpression('#<img[^>]+attacker\.example#i', $html, 'an attacker image was rendered');
        $this->assertStringNotContainsString('<h1>Owned', $html);
        $this->assertStringNotContainsString('<strong>Owned', $html);
        $this->assertStringNotContainsString('<script', $html);
    }

    public function test_a_blank_line_in_a_payer_value_cannot_break_out_into_markdown(): void
    {
        $html = $this->receiptFor([
            'name' => "Ada\n\n[Pay again here](https://attacker.example/phish)",
            'student_name' => "Tobi\n\n# Owned\n\n**Owned**",
            'student_class' => "JSS 1\r\n\r\n![x](https://attacker.example/t.png)",
            'student_admission_number' => "A/001\n\n[ref]: https://attacker.example",
            'category_name' => "Fees\n\n<script>alert(1)</script>",
            'subcategory_name' => "Tuition\n\n[x]: https://attacker.example",
        ]);

        $this->assertNoInjectedMarkup($html);
        // The text is still shown, on one line.
        $this->assertStringContainsString('Pay again here', $html);
        $this->assertStringContainsString('Tobi # Owned **Owned**', $html);
    }

    public function test_link_and_image_syntax_on_one_line_is_not_rendered(): void
    {
        $html = $this->receiptFor([
            'name' => '[Click here](https://attacker.example/phish)',
            'student_name' => '![img](https://attacker.example/t.png) <img src="https://attacker.example/x">',
        ]);

        $this->assertNoInjectedMarkup($html);
        $this->assertStringContainsString('[Click here](https://attacker.example/phish)', $html);
    }

    public function test_school_supplied_text_cannot_inject_either(): void
    {
        $html = $this->receiptFor([], [
            'address' => "1 Road\n\n[Support](https://attacker.example)",
            'receipt_footer' => "Thanks!\n\n\n[Click](https://attacker.example)\n# Owned",
        ]);

        $this->assertNoInjectedMarkup($html);
        $this->assertStringContainsString('Thanks!<br>[Click](https://attacker.example)<br># Owned', $html);
    }

    public function test_an_ordinary_receipt_is_unchanged(): void
    {
        $html = $this->receiptFor([
            'name' => "Ngozi O'Brien-Adeyemi", 'student_name' => 'Tobi Adeyemi', 'student_admission_number' => 'FGC/2024/031',
        ], ['receipt_footer' => "Thank you for paying.\nBursary: 0801 234 5678"]);

        $this->assertStringContainsString("Ngozi O'Brien-Adeyemi", $html);
        $this->assertStringContainsString('FGC/2024/031', $html);
        $this->assertStringContainsString('Thank you for paying.<br>Bursary: 0801 234 5678', $html);
        $this->assertStringContainsString('₦102.50', $html);
        $this->assertStringContainsString('View / Download Receipt', $html);
    }

    public function test_checkout_refuses_a_payer_name_with_control_characters(): void
    {
        config(['services.paystack.secret_key' => 'sk_test_fake']);
        Http::fake(['*/transaction/initialize' => Http::response(['status' => true, 'data' => ['authorization_url' => 'https://checkout.paystack.com/x']])]);
        $school = $this->makeSchool('Alpha School', 'alpha');
        $fee = $this->makeFee($school, 'School fees', 'Tuition', 1000);
        $payload = ['email' => 'p@example.test', 'category_id' => $fee->category_id, 'subcategory_id' => $fee->id, 'quantity' => 1];

        $this->post('/pay/alpha/initialize', $payload + ['name' => "Ada\n\n[x](https://attacker.example)"])->assertSessionHasErrors('name');
        $this->assertSame(0, Transaction::count());

        $this->post('/pay/alpha/initialize', $payload + ['name' => 'Ada Parent'])->assertRedirect('https://checkout.paystack.com/x');
    }
}
