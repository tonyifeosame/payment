<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesPayoutsForRecovery;
use App\Jobs\InitiateSchoolPayout;
use App\Models\Payout;
use App\Models\PayoutRecoveryEvent;
use App\Services\PayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Operator recovery (B1): put a definitively failed payout back in the queue.
 *
 *   php artisan payouts:retry PO-…        one payout
 *   php artisan payouts:retry --all-failed every failed payout, each on its own
 *
 * Only `failed` is reset. The state change is PayoutService::retryFailed() — a
 * row-locked, transition-checked pending reset that writes its audit event in the
 * same transaction — and the transfer itself is still InitiateSchoolPayout's job,
 * dispatched only after that transaction has committed. This command never calls
 * Paystack. A payout that is already `pending` (say, a previous retry whose
 * dispatch was lost) is not changed but is queued again: the job's atomic
 * pending -> initiating claim makes a second dispatch harmless.
 */
class RetryPayouts extends Command
{
    use ResolvesPayoutsForRecovery;

    protected $signature = 'payouts:retry
        {reference? : The payout reference (PO-…) to retry}
        {--all-failed : Retry every payout currently in the failed state}
        {--note= : Why the operator is retrying (recorded on the audit event)}';

    protected $description = 'Reset a failed payout to pending and queue its transfer again (operator recovery)';

    public function handle(PayoutService $payouts): int
    {
        $reference = (string) $this->argument('reference');
        $all = (bool) $this->option('all-failed');

        if ($all === ($reference !== '')) {
            $this->error('Give exactly one of: a payout reference, or --all-failed.');

            return self::INVALID;
        }

        if (! $all) {
            $payout = $this->payoutByReference($reference);
            if (! $payout) {
                return self::FAILURE;
            }

            // A single reference that could not be reset is an error for the operator;
            // a batch only fails on exceptions (see below).
            return in_array($this->retryOne($payout, $payouts), ['reset', 'dispatched'], true) ? self::SUCCESS : self::FAILURE;
        }

        $failed = Payout::with(['school', 'transaction'])->where('status', Payout::FAILED)->orderBy('id')->get();

        if ($failed->isEmpty()) {
            $this->info('No failed payouts.');
            $this->summary(0, 0, 0, 0, 'reset');

            return self::SUCCESS;
        }

        $this->info("{$failed->count()} failed payout(s).");

        $counts = ['processed' => 0, 'changed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($failed as $payout) {
            $counts['processed']++;
            $outcome = $this->retryOne($payout, $payouts);
            $counts[match ($outcome) {
                'reset' => 'changed', 'failed' => 'failed', default => 'skipped'
            }]++;
        }

        $this->summary($counts['processed'], $counts['changed'], $counts['skipped'], $counts['failed'], 'reset');

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Retry one payout in isolation; a failure here never stops the batch.
     *
     * @return 'reset'|'dispatched'|'skipped'|'failed'
     */
    private function retryOne(Payout $payout, PayoutService $payouts): string
    {
        $this->line($this->describe($payout));

        try {
            // Already queued once and never claimed: nothing to reset, but a dispatch
            // is safe and is the only way to recover a lost one.
            if ($payout->status === Payout::PENDING) {
                $payouts->recordRecoveryEvent($payout, PayoutRecoveryEvent::ACTION_RETRY, $payout->status, null, PayoutRecoveryEvent::SOURCE_ARTISAN, null, $this->option('note') ?: null, 'dispatched (already pending)');
                $this->dispatch($payout);
                $this->comment("Payout {$payout->reference} is already pending; queued again.");

                return 'dispatched';
            }

            $result = $payouts->retryFailed($payout, PayoutRecoveryEvent::SOURCE_ARTISAN, $this->option('note') ?: null);

            if (! $result['ok']) {
                $this->warn("Cannot retry {$payout->reference}: {$result['message']}.");

                return 'skipped';
            }

            $this->info("Payout {$payout->reference} reset from {$result['previous']} to {$result['status']}.");

            $this->dispatch($payout);
            $this->info("Payout {$payout->reference} dispatched for retry.");

            return 'reset';
        } catch (\Throwable $e) {
            report($e);
            Log::error('payouts:retry failed for a payout', ['payout_id' => $payout->id, 'reference' => $payout->reference, 'error' => $e->getMessage()]);
            $this->error("Failed to retry {$payout->reference}: ".$e->getMessage());

            return 'failed';
        }
    }

    private function dispatch(Payout $payout): void
    {
        // The reset has already committed (PayoutService ran its own transaction),
        // and the job's own afterCommit guard covers any outer transaction.
        InitiateSchoolPayout::dispatch($payout->id);
    }
}
