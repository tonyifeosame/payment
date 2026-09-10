<?php

namespace Tests\Feature;

use App\Mail\PaymentReceiptMail;
use App\Models\School;
use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Regression tests for E10 — receipts understated what the payer was charged.
 *
 * transactions.amount is the gross actually taken. meta_data only *explains* it
 * (base_amount + markup_amount); it must never replace it. Both the emailed and
 * the on-screen receipt are asserted here, because the bug existed in both and
 * they must not diverge again.
 */
class ReceiptTotalsTest extends TestCase
{
    use RefreshDatabase;

    private School $school;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'mail.default' => 'array',
            'mail.from.address' => 'no-reply@example.com',
        ]);

        $this->school = School::create([
            'name' => 'Greenfield Academy', 'slug' => 'greenfield',
            'email' => 'admin@greenfield.test', 'admin_password' => Hash::make('password123'),
        ]);
    }

    private function makeTransaction(float $amount, ?array $meta, string $reference = 'ref-001'): Transaction
    {
        return Transaction::create([
            'school_id' => $this->school->id,
            'reference' => $reference,
            'amount' => $amount,
            'status' => 'success',
            'email' => 'parent@example.test',
            'name' => 'Ada Parent',
            'category_name' => 'School Fees',
            'subcategory_name' => 'Primary',
            'meta_data' => $meta,
        ]);
    }

    /** Plain text of the emailed receipt. */
    private function emailText(Transaction $t): string
    {
        return preg_replace('/\s+/', ' ',
            html_entity_decode(strip_tags((new PaymentReceiptMail($t))->render())));
    }

    /** Plain text of the on-screen receipt, fetched through the signed URL. */
    private function webText(Transaction $t): string
    {
        $html = $this->get(URL::signedRoute('payment.receipt', ['transaction' => $t->id]))
            ->assertOk()
            ->getContent();

        return preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($html)));
    }

    // =====================================================================
    // Base amount + service fee
    // =====================================================================

    public function test_base_plus_markup_shows_all_three_figures(): void
    {
        $t = $this->makeTransaction(51250.00, [
            'quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 1250,
        ]);

        foreach (['email' => $this->emailText($t), 'web' => $this->webText($t)] as $channel => $text) {
            $this->assertStringContainsString('50,000.00', $text, "$channel: fee subtotal missing");
            $this->assertStringContainsString('1,250.00', $text, "$channel: service fee hidden");
            $this->assertStringContainsString('51,250.00', $text, "$channel: gross total missing");
        }

        $b = $t->receiptBreakdown();
        $this->assertSame(50000.00, $b['fee_subtotal']);
        $this->assertSame(1250.00, $b['service_fee']);
        $this->assertSame(51250.00, $b['total']);
        $this->assertTrue($b['has_service_fee']);
    }

    public function test_quantity_is_reflected_in_the_unit_price(): void
    {
        $t = $this->makeTransaction(12300.00, [
            'quantity' => 4, 'base_amount' => 12000, 'markup_amount' => 300,
        ]);

        $b = $t->receiptBreakdown();
        $this->assertSame(4, $b['quantity']);
        $this->assertSame(3000.00, $b['unit_price']);   // 12,000 / 4
        $this->assertSame(12300.00, $b['total']);
        $this->assertStringContainsString('12,300.00', $this->emailText($t));
    }

    // =====================================================================
    // No markup
    // =====================================================================

    public function test_no_markup_shows_a_single_total_and_no_service_fee_line(): void
    {
        $t = $this->makeTransaction(50000.00, [
            'quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 0,
        ]);

        $b = $t->receiptBreakdown();
        $this->assertSame(0.0, $b['service_fee']);
        $this->assertFalse($b['has_service_fee']);
        $this->assertSame(50000.00, $b['total']);

        foreach (['email' => $this->emailText($t), 'web' => $this->webText($t)] as $channel => $text) {
            $this->assertStringContainsString('50,000.00', $text, "$channel: total missing");
            $this->assertStringNotContainsString('Service Fee', $text, "$channel: showed a zero service fee");
        }
    }

    // =====================================================================
    // Missing markup metadata — derive it from the real charge
    // =====================================================================

    public function test_missing_markup_metadata_is_derived_from_the_amount(): void
    {
        // base_amount recorded, markup_amount absent.
        $t = $this->makeTransaction(51250.00, ['quantity' => 1, 'base_amount' => 50000]);

        $b = $t->receiptBreakdown();
        $this->assertSame(1250.00, $b['service_fee'], 'service fee was not derived');
        $this->assertSame(51250.00, $b['total']);
        $this->assertTrue($b['has_service_fee']);

        $this->assertStringContainsString('1,250.00', $this->emailText($t));
        $this->assertStringContainsString('51,250.00', $this->webText($t));
    }

    public function test_inconsistent_metadata_never_overrides_the_amount_charged(): void
    {
        // Parts that do not reconcile: the real charge still wins.
        $t = $this->makeTransaction(51250.00, [
            'quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 9999,
        ]);

        $b = $t->receiptBreakdown();
        $this->assertSame(51250.00, $b['total']);
        $this->assertSame(1250.00, $b['service_fee'], 'fee should be re-derived when the parts do not add up');
        $this->assertSame(51250.00, round($b['fee_subtotal'] + $b['service_fee'], 2));
    }

    public function test_base_larger_than_the_amount_charged_is_clamped(): void
    {
        $t = $this->makeTransaction(1000.00, ['quantity' => 1, 'base_amount' => 5000]);

        $b = $t->receiptBreakdown();
        $this->assertSame(1000.00, $b['total']);
        $this->assertSame(0.0, $b['service_fee'], 'must never show a negative service fee');
        $this->assertSame(1000.00, $b['fee_subtotal']);
    }

    // =====================================================================
    // No metadata at all
    // =====================================================================

    public function test_missing_metadata_falls_back_to_the_amount_charged(): void
    {
        $t = $this->makeTransaction(7500.00, null);

        $b = $t->receiptBreakdown();
        $this->assertFalse($b['has_breakdown']);
        $this->assertFalse($b['has_service_fee']);
        $this->assertSame(7500.00, $b['total']);
        $this->assertSame(7500.00, $b['fee_subtotal']);

        foreach (['email' => $this->emailText($t), 'web' => $this->webText($t)] as $channel => $text) {
            $this->assertStringContainsString('7,500.00', $text, "$channel: total missing");
        }
    }

    public function test_legacy_double_encoded_metadata_still_produces_a_correct_total(): void
    {
        DB::table('transactions')->insert([
            'school_id' => $this->school->id,
            'reference' => 'legacy-001',
            'amount' => 51250.00,
            'status' => 'success',
            'email' => 'parent@example.test',
            'name' => 'Ada Parent',
            'meta_data' => json_encode(json_encode(['quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 1250])),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $t = Transaction::where('reference', 'legacy-001')->firstOrFail();
        $b = $t->receiptBreakdown();

        $this->assertSame(51250.00, $b['total']);
        $this->assertSame(50000.00, $b['fee_subtotal']);
        $this->assertSame(1250.00, $b['service_fee']);
        $this->assertStringContainsString('51,250.00', $this->emailText($t));
    }

    // =====================================================================
    // The invariant, across every shape
    // =====================================================================

    public static function transactionShapes(): array
    {
        return [
            'base + markup' => [51250.00, ['quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 1250]],
            'no markup' => [50000.00, ['quantity' => 1, 'base_amount' => 50000, 'markup_amount' => 0]],
            'markup metadata missing' => [51250.00, ['quantity' => 1, 'base_amount' => 50000]],
            'no metadata at all' => [7500.00, null],
            'empty metadata' => [1234.56, []],
            'multi quantity' => [12300.00, ['quantity' => 4, 'base_amount' => 12000, 'markup_amount' => 300]],
            'small amount' => [1.03, ['quantity' => 1, 'base_amount' => 1, 'markup_amount' => 0.03]],
        ];
    }

    /**
     * @dataProvider transactionShapes
     */
    public function test_receipt_total_always_equals_transactions_amount(float $amount, ?array $meta): void
    {
        $t = $this->makeTransaction($amount, $meta);

        $breakdown = $t->receiptBreakdown();

        $this->assertSame(round($amount, 2), $breakdown['total'], 'total drifted from transactions.amount');
        $this->assertSame(
            round($amount, 2),
            round($breakdown['fee_subtotal'] + $breakdown['service_fee'], 2),
            'the displayed parts do not add up to the amount charged'
        );

        $expected = number_format($amount, 2);
        $this->assertStringContainsString($expected, $this->emailText($t));
        $this->assertStringContainsString($expected, $this->webText($t));
    }
}
