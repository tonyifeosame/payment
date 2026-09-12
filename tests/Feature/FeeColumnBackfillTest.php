<?php

namespace Tests\Feature;

use App\Models\Transaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithSchools;
use Tests\TestCase;

/**
 * Phase 1 — the migration that promotes the fee split out of meta_data must agree
 * with Transaction::receiptBreakdown for every historical shape of metadata.
 */
class FeeColumnBackfillTest extends TestCase
{
    use InteractsWithSchools, RefreshDatabase;

    private function runBackfill(): void
    {
        $migration = require base_path('database/migrations/2026_09_12_000200_add_student_and_period_context_to_fees_and_transactions.php');
        $method = new \ReflectionMethod($migration, 'backfillFeeColumns');
        $method->invoke($migration);
    }

    public function test_backfill_matches_receipt_breakdown_for_every_metadata_shape(): void
    {
        $school = $this->makeSchool('Alpha School', 'alpha');

        $rows = [
            'base + markup' => [51250, ['base_amount' => 50000, 'markup_amount' => 1250]],
            'no markup' => [50000, ['base_amount' => 50000, 'markup_amount' => 0]],
            'markup missing' => [51250, ['base_amount' => 50000]],
            'inconsistent parts' => [51250, ['base_amount' => 50000, 'markup_amount' => 999]],
            'base above charge' => [40000, ['base_amount' => 50000, 'markup_amount' => 0]],
            'double encoded' => [51250, json_encode(['base_amount' => 50000, 'markup_amount' => 1250])],
            'no metadata' => [51250, null],
        ];

        $ids = [];
        foreach ($rows as $label => [$amount, $meta]) {
            $ids[$label] = DB::table('transactions')->insertGetId([
                'school_id' => $school->id,
                'reference' => 'ref-'.md5($label),
                'amount' => $amount,
                'status' => 'success',
                'email' => 'x@example.test',
                'meta_data' => is_array($meta) ? json_encode($meta) : ($meta === null ? null : json_encode($meta)),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->runBackfill();

        foreach ($ids as $label => $id) {
            $t = Transaction::findOrFail($id);
            $breakdown = $t->receiptBreakdown();

            if (! $breakdown['has_breakdown']) {
                $this->assertNull($t->fee_amount, "$label: unknown split stays NULL");
                $this->assertNull($t->service_fee, "$label: unknown split stays NULL");

                continue;
            }

            $this->assertEquals($breakdown['fee_subtotal'], (float) $t->fee_amount, "$label: fee_amount");
            $this->assertEquals($breakdown['service_fee'], (float) $t->service_fee, "$label: service_fee");
            $this->assertEquals((float) $t->amount, (float) $t->fee_amount + (float) $t->service_fee, "$label: parts reconcile to the charge");
        }
    }

    public function test_backfill_is_idempotent_and_leaves_populated_rows_alone(): void
    {
        $school = $this->makeSchool('Alpha School', 'alpha');
        $t = $this->makeSuccessfulTransaction($school, ['fee_amount' => 123, 'service_fee' => 4, 'amount' => 127]);

        $this->runBackfill();
        $this->runBackfill();

        $this->assertEquals(123.0, (float) $t->fresh()->fee_amount);
        $this->assertEquals(4.0, (float) $t->fresh()->service_fee);
    }
}
