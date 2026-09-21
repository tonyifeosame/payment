<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ResolvesPayoutsForRecovery;
use App\Models\Payout;
use App\Models\PayoutRecoveryEvent;
use App\Services\PayoutService;
use App\Services\PaystackService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Operator recovery (B1): resolve payouts whose transfer outcome is unknown.
 *
 *   php artisan payouts:lookup PO-…   one payout
 *   php artisan payouts:lookup --stale every `initiating` payout older than
 *                                     PayoutService::STALE_INITIATING_MINUTES
 *
 * An `initiating` payout means we asked Paystack to move money and never learned
 * the answer. The only safe move is to ask — PayoutService::reconcileInitiating(),
 * the same path the queued job takes — never to send again:
 *   - Paystack knows the transfer  -> its status is applied (processing/success/
 *                                     failed/reversed, amount- and currency-checked);
 *   - Paystack has no such transfer -> the claim is released to `failed`, which
 *                                     payouts:retry may then queue again;
 *   - Paystack cannot say          -> the payout stays `initiating`.
 * Any other state is reported and left alone. Every lookup writes an audit event.
 */
class LookupPayouts extends Command
{
    use ResolvesPayoutsForRecovery;

    protected $signature = 'payouts:lookup
        {reference? : The payout reference (PO-…) to look up}
        {--stale : Look up every initiating payout older than the stale threshold}';

    protected $description = 'Ask Paystack what became of an initiating payout and apply the answer — never re-sends (operator recovery)';

    public function handle(PayoutService $payouts, PaystackService $paystack): int
    {
        $reference = (string) $this->argument('reference');
        $stale = (bool) $this->option('stale');

        if ($stale === ($reference !== '')) {
            $this->error('Give exactly one of: a payout reference, or --stale.');

            return self::INVALID;
        }

        if (! $stale) {
            $payout = $this->payoutByReference($reference);
            if (! $payout) {
                return self::FAILURE;
            }

            // A single reference is only a success when Paystack gave a definitive
            // answer; "still unknown" and "not initiating" are errors for the operator.
            return $this->lookupOne($payout, $payouts, $paystack) === 'resolved' ? self::SUCCESS : self::FAILURE;
        }

        $minutes = PayoutService::STALE_INITIATING_MINUTES;
        $candidates = $payouts->staleInitiating()->with(['school', 'transaction'])->get();

        if ($candidates->isEmpty()) {
            $this->info("No initiating payouts older than {$minutes} minutes.");
            $this->summary(0, 0, 0, 0, 'resolved');

            return self::SUCCESS;
        }

        $this->info("{$candidates->count()} initiating payout(s) older than {$minutes} minutes.");

        $counts = ['processed' => 0, 'resolved' => 0, 'unchanged' => 0, 'failed' => 0];

        foreach ($candidates as $payout) {
            $counts['processed']++;
            $counts[$this->lookupOne($payout, $payouts, $paystack)]++;
        }

        $this->summary($counts['processed'], $counts['resolved'], $counts['unchanged'], $counts['failed'], 'resolved');

        return $counts['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * Look one payout up in isolation; a failure here never stops the batch.
     *
     * @return 'resolved'|'unchanged'|'failed'
     */
    private function lookupOne(Payout $payout, PayoutService $payouts, PaystackService $paystack): string
    {
        $this->line($this->describe($payout));

        try {
            if ($payout->status !== Payout::INITIATING) {
                $this->warn("Cannot look up {$payout->reference}: ".$this->notInitiating($payout).'.');

                return 'unchanged';
            }

            $result = $payouts->reconcileInitiating($payout, $paystack);

            $changed = $result['status'] !== $result['previous'];
            $payouts->recordRecoveryEvent(
                $payout,
                PayoutRecoveryEvent::ACTION_LOOKUP,
                $result['previous'],
                $changed ? $result['status'] : null,
                PayoutRecoveryEvent::SOURCE_ARTISAN,
                null,
                $result['message'],
                $result['outcome'].($changed ? ': '.$result['previous'].' -> '.$result['status'] : ': unchanged')
            );

            switch ($result['outcome']) {
                case 'resolved':
                    $this->info(sprintf('Payout %s: %s; now %s.', $payout->reference, $result['message'], $result['status']));

                    return $changed ? 'resolved' : 'unchanged';

                case 'released':
                    $this->info("Payout {$payout->reference}: {$result['message']}; released to {$result['status']} — run `payouts:retry {$payout->reference}` to send it again.");

                    return 'resolved';

                case 'not_initiating':
                    $this->warn("Payout {$payout->reference} changed underneath us and is now {$result['status']}; nothing done.");

                    return 'unchanged';

                default:
                    $this->warn("Payout {$payout->reference} is still unresolved ({$result['message']}); left initiating.");

                    return 'unchanged';
            }
        } catch (\Throwable $e) {
            report($e);
            Log::error('payouts:lookup failed for a payout', ['payout_id' => $payout->id, 'reference' => $payout->reference, 'error' => $e->getMessage()]);
            $this->error("Failed to look up {$payout->reference}: ".$e->getMessage());

            return 'failed';
        }
    }

    private function notInitiating(Payout $payout): string
    {
        return match ($payout->status) {
            Payout::PENDING => 'payout is pending and has not been sent yet',
            Payout::PROCESSING => "payout is processing — Paystack's transfer webhook will finish it",
            Payout::SUCCESS => 'payout is already paid',
            Payout::FAILED => 'payout is failed (use payouts:retry)',
            Payout::REVERSED => 'payout was reversed and is final',
            Payout::NEEDS_REVIEW => 'payout needs review (use payouts:release)',
            default => 'payout is '.$payout->status,
        };
    }
}
