<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use App\Services\PaymentSettlementService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * H5: resolve checkouts that have stayed `pending` past the configured window.
 *
 *   php artisan payments:expire-pending [--limit=200] [--dry-run]
 *
 * Nothing is marked failed on age alone. Every candidate is verified with
 * Paystack through PaymentSettlementService::reconcilePendingAttempt() — the same
 * verification the callback and webhook use — and the answer decides:
 *
 *   success                 -> SETTLED, exactly as a late webhook would (receipt
 *                              queued, payout obligation recorded); a payment is
 *                              never lost because the browser never came back
 *   failed / reversed       -> failed (definitive for this attempt)
 *   abandoned / unknown ref -> failed (the checkout never completed / never reached
 *                              Paystack, and the window has passed)
 *   pending / ongoing / …   -> left pending, checked again next run
 *   Paystack unreachable    -> left pending, checked again next run
 *
 * Candidates: status = pending, paid_at IS NULL, created before now minus
 * config('fees.pending_payment_expiry_hours'), oldest first, at most --limit per
 * run so historical rows drain gradually (one GET each). A `success` row is never
 * selected and, under the row lock, never changed; each transaction is handled
 * independently; repeated and overlapping runs converge on the same state.
 * No transfer, no charge, no receipt invalidation.
 */
class ExpirePendingPayments extends Command
{
    protected $signature = 'payments:expire-pending
        {--limit=200 : Maximum pending transactions to verify in one run}
        {--dry-run : Report the candidates and change nothing (Paystack is not contacted)}';

    protected $description = 'Verify checkouts pending longer than the configured window and record their real outcome (never on age alone)';

    public function handle(PaymentSettlementService $settlement): int
    {
        $hours = max((int) config('fees.pending_payment_expiry_hours', 24), 1);
        $limit = max((int) $this->option('limit'), 1);
        $dryRun = (bool) $this->option('dry-run');

        $candidates = Transaction::query()
            ->where('status', 'pending')
            ->whereNull('paid_at')
            ->where('created_at', '<=', now()->subHours($hours))
            ->orderBy('created_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        if ($candidates->isEmpty()) {
            $this->info("No payments have been pending for more than {$hours} hours.");

            return self::SUCCESS;
        }

        $this->info("{$candidates->count()} payment(s) pending for more than {$hours} hours".($dryRun ? ' (dry run: nothing will be verified or changed)' : ':'));

        if ($dryRun) {
            foreach ($candidates as $t) {
                $this->line("  {$t->reference} — school #{$t->school_id} — NGN ".number_format((float) $t->amount, 2).' — started '.$t->created_at?->toDateTimeString());
            }

            return self::SUCCESS;
        }

        $counts = ['processed' => 0, 'settled' => 0, 'failed' => 0, 'pending' => 0, 'unverified' => 0, 'skipped' => 0, 'errors' => 0];

        foreach ($candidates as $transaction) {
            $counts['processed']++;

            try {
                $result = $settlement->reconcilePendingAttempt($transaction);
            } catch (\Throwable $e) {
                report($e);
                Log::error('Pending payment expiry failed for a transaction', ['transaction_id' => $transaction->id, 'reference' => $transaction->reference, 'error' => $e->getMessage()]);
                $this->error("  FAILED {$transaction->reference}: ".$e->getMessage());
                $counts['errors']++;

                continue;
            }

            switch ($result['outcome']) {
                case PaymentSettlementService::SETTLED:
                    $counts['settled']++;
                    $this->info("  {$transaction->reference}: Paystack reports success — settled now (receipt and payout handled as usual)");
                    break;
                case PaymentSettlementService::ALREADY_SETTLED:
                    $counts['skipped']++;
                    $this->line("  {$transaction->reference}: already settled elsewhere; untouched");
                    break;
                case PaymentSettlementService::FAILED_RECORDED:
                    $counts['failed']++;
                    $this->line("  {$transaction->reference}: not completed — ".($result['message'] ?? 'recorded as failed'));
                    break;
                case PaymentSettlementService::ALREADY_FAILED:
                    $counts['skipped']++;
                    $this->line("  {$transaction->reference}: already recorded as failed; untouched");
                    break;
                case PaymentSettlementService::AMOUNT_MISMATCH:
                case PaymentSettlementService::CURRENCY_MISMATCH:
                case PaymentSettlementService::SETTLEMENT_CONFLICT:
                    $counts['skipped']++;
                    $this->warn("  {$transaction->reference}: {$result['outcome']} — needs a human (see logs)");
                    break;
                case PaymentSettlementService::VERIFICATION_FAILED:
                    $counts['unverified']++;
                    $this->warn("  {$transaction->reference}: Paystack could not be asked (".($result['message'] ?? 'no answer').'); left pending');
                    break;
                default: // NOT_SUCCESSFUL: Paystack still calls it open
                    $counts['pending']++;
                    $this->line("  {$transaction->reference}: ".($result['message'] ?? 'still open at Paystack').'; left pending');
            }
        }

        $this->newLine();
        $this->info(sprintf(
            'processed: %d · settled: %d · failed: %d · still pending: %d · unverified: %d · skipped: %d · errors: %d',
            $counts['processed'], $counts['settled'], $counts['failed'], $counts['pending'], $counts['unverified'], $counts['skipped'], $counts['errors']
        ));
        Log::info('Pending payment expiry run', $counts);

        return $counts['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
