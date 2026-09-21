<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesPayoutsForRecovery;
use App\Jobs\InitiateSchoolPayout;
use App\Models\PayoutRecoveryEvent;
use App\Services\PayoutService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Operator override (B1): release a payout parked in `needs_review` with an
 * explicit amount.
 *
 *   php artisan payouts:release PO-… --amount=50000 [--note="why"]
 *
 * A reviewed payout has no amount the system trusts, so the operator must state
 * one: a positive naira figure with at most two decimals, never more than the
 * parent was charged. PayoutService::releaseForTransfer() applies the
 * needs_review -> pending transition under the row lock, keeps the original
 * review reason on the row and writes the audit event (with the amount) in the
 * same transaction. The transfer is still InitiateSchoolPayout's job and goes
 * through every normal check (recipient, reference idempotency, amount and
 * currency matching on the way back). This command never calls Paystack.
 */
class ReleasePayout extends Command
{
    use ResolvesPayoutsForRecovery;

    protected $signature = 'payouts:release
        {reference : The payout reference (PO-…) parked in needs_review}
        {--amount= : The amount in naira to pay the school, e.g. 50000 or 50000.00 (required)}
        {--note= : Why this amount is right (recorded on the audit event)}';

    protected $description = 'Release a needs_review payout with an operator-supplied amount and queue its transfer (operator override)';

    public function handle(PayoutService $payouts): int
    {
        $raw = $this->option('amount');

        if ($raw === null || trim((string) $raw) === '') {
            $this->error('--amount is required: state the naira amount to release, e.g. --amount=50000.');

            return self::INVALID;
        }

        $raw = trim((string) $raw);
        if (! preg_match('/^\d+(\.\d{1,2})?$/', $raw) || (float) $raw <= 0) {
            $this->error("Invalid --amount \"{$raw}\": use a positive naira amount with at most two decimals, e.g. 50000 or 50000.50.");

            return self::INVALID;
        }
        $amount = round((float) $raw, 2);

        $payout = $this->payoutByReference((string) $this->argument('reference'));
        if (! $payout) {
            return self::FAILURE;
        }

        $this->line($this->describe($payout));
        if ($payout->last_error) {
            $this->line('Parked because: '.$payout->last_error);
        }

        try {
            $result = $payouts->releaseForTransfer($payout, $amount, PayoutRecoveryEvent::SOURCE_ARTISAN, $this->option('note') ?: null);
        } catch (\Throwable $e) {
            report($e);
            Log::error('payouts:release failed', ['payout_id' => $payout->id, 'reference' => $payout->reference, 'error' => $e->getMessage()]);
            $this->error("Failed to release {$payout->reference}: ".$e->getMessage());

            return self::FAILURE;
        }

        if (! $result['ok']) {
            $this->error("Cannot release {$payout->reference}: {$result['message']}.");

            return self::FAILURE;
        }

        $this->info(sprintf('Payout %s released from %s to %s with amount NGN %s.', $payout->reference, $result['previous'], $result['status'], number_format($amount, 2)));

        try {
            // The release has already committed; the job's afterCommit guard covers
            // any outer transaction.
            InitiateSchoolPayout::dispatch($payout->id);
            $this->info("Payout {$payout->reference} dispatched for transfer.");
        } catch (\Throwable $e) {
            report($e);
            $this->error("Payout {$payout->reference} was released but could not be queued: ".$e->getMessage().' Run `payouts:retry '.$payout->reference.'` to queue it.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
