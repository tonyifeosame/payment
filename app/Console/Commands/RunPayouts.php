<?php

namespace App\Console\Commands;

use App\Jobs\InitiateSchoolPayout;
use App\Models\Payout;
use App\Models\Transaction;
use App\Services\PayoutService;
use App\Services\PaystackService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Reconciliation / backfill for school payouts.
 *
 * Payouts are now created immediately when a payment settles (Model A, see
 * PaymentSettlementService). This command is no longer the payout mechanism — it
 * exists to catch settled payments that never got an obligation: those that
 * completed before immediate payouts existed, or where scheduling failed.
 *
 * It deliberately does NOT transfer money itself. It records missing obligations
 * and hands each one to InitiateSchoolPayout, so historical payments go through the
 * exact same idempotent state machine as new ones. A transaction that already has a
 * payout is skipped, so it can never create a duplicate transfer.
 *
 * H1: it also reconciles payouts left in `initiating` for longer than
 * PayoutService::STALE_INITIATING_MINUTES — the transfer was requested and the
 * answer was lost — by asking Paystack what became of our reference
 * (PayoutService::reconcileStaleInitiating, the same path as `payouts:lookup
 * --stale`). That is a read-only GET per stale payout; the answer is applied
 * through the state machine, and a payout Paystack has never seen is released to
 * `failed` for an operator's `payouts:retry`. It never re-sends.
 *
 * Every transaction and every stale payout is handled independently: one school's
 * failure is logged against that school and processing continues for all the
 * others (E4b).
 */
class RunPayouts extends Command
{
    protected $signature = 'payouts:run
        {--date= : Only reconcile transactions settled on this date}
        {--since= : Only reconcile transactions settled on or after this date}
        {--limit=500 : Maximum transactions to reconcile in one run}
        {--dispatch : Actually queue the transfers (otherwise obligations are only recorded)}
        {--dry-run : Report what would happen and change nothing}';

    protected $description = 'Reconcile settled payments that have no payout obligation, queue their transfers, and resolve stale initiating payouts';

    public function handle(PayoutService $payouts, PaystackService $paystack): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $dispatch = (bool) $this->option('dispatch');
        $limit = max((int) $this->option('limit'), 1);

        $query = Transaction::query()
            ->where('status', 'success')
            ->whereNotNull('school_id')
            // The guard against double-paying: anything already owed is left alone.
            ->whereDoesntHave('payout')
            ->orderBy('id')
            ->limit($limit);

        if ($date = $this->option('date')) {
            $day = Carbon::parse($date);
            $query->whereBetween('created_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()]);
        }

        if ($since = $this->option('since')) {
            $query->where('created_at', '>=', Carbon::parse($since)->startOfDay());
        }

        $transactions = $query->get();

        // HIGH-3: obligations whose school share could not be separated from the
        // platform fee are parked for a human. Surface them on every run so they
        // cannot sit unnoticed.
        $this->reportPayoutsNeedingReview();

        // Obligations that were committed but never queued are recovered on every
        // run, including runs where nothing else needs reconciling (MEDIUM-3).
        $requeued = $dryRun ? 0 : $this->requeueStrandedObligations($dispatch);

        // H1: payouts whose transfer outcome was lost are looked up on every run
        // (lookup only — see the class comment). A dry run only counts them.
        $staleFailures = $this->reconcileStaleInitiating($payouts, $paystack, $dryRun);

        if ($transactions->isEmpty()) {
            $this->info('No settled payments are missing a payout obligation.');

            if ($requeued > 0) {
                $this->info("Re-queued {$requeued} stranded obligation(s).");
            }

            return $staleFailures > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->info("Found {$transactions->count()} settled payment(s) without a payout obligation.");

        if ($dryRun) {
            $this->table(
                ['Transaction', 'School', 'Charged', 'School share', 'Attributable?'],
                $transactions->map(function (Transaction $t) use ($payouts) {
                    $attributable = $payouts->hasTrustworthyBreakdown($t);

                    return [
                        $t->reference,
                        $t->school_id,
                        number_format((float) $t->amount, 2),
                        $attributable ? number_format($payouts->payoutAmountFor($t), 2) : 'UNKNOWN',
                        $attributable ? 'yes' : 'NO - needs review',
                    ];
                })->all()
            );
            $this->comment('Dry run: nothing was written and nothing was queued.');

            return self::SUCCESS;
        }

        $created = 0;
        $review = 0;
        $queued = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($transactions as $transaction) {
            // E4b: each transaction stands alone. A failure here is recorded and the
            // loop continues, so one school can never block another's payout.
            try {
                $payout = $payouts->recordObligationFor($transaction);

                if (! $payout) {
                    $skipped++;
                    $this->warn("  skipped {$transaction->reference}: nothing payable");

                    continue;
                }

                if ($payout->status === Payout::NEEDS_REVIEW) {
                    $review++;
                    $this->warn("  REVIEW {$transaction->reference}: {$payout->last_error}");

                    continue;
                }

                $created++;

                if ($dispatch && $payout->status === Payout::PENDING) {
                    InitiateSchoolPayout::dispatch($payout->id);
                    $queued++;
                }

                $this->line("  recorded {$transaction->reference} -> payout {$payout->reference} (NGN ".number_format((float) $payout->amount, 2).')');
            } catch (\Throwable $e) {
                $failed++;
                report($e);

                Log::error('Payout reconciliation failed for a transaction', [
                    'transaction_id' => $transaction->id,
                    'reference' => $transaction->reference,
                    'school_id' => $transaction->school_id,
                    'error' => $e->getMessage(),
                ]);

                $this->error("  FAILED {$transaction->reference}: {$e->getMessage()}");
            }
        }

        $queued += $requeued;

        $this->newLine();
        $this->info("Obligations recorded: {$created}, transfers queued: {$queued}, needs review: {$review}, skipped: {$skipped}, failed: {$failed}");

        if ($created > 0 && ! $dispatch) {
            $this->comment('Re-run with --dispatch to queue the transfers for these obligations.');
        }

        return $failed + $staleFailures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * H1: look up every `initiating` payout older than the stale threshold and apply
     * Paystack's answer. Prints a per-payout line and a summary; nothing here can
     * create a transfer. Returns how many payouts raised an exception.
     */
    private function reconcileStaleInitiating(PayoutService $payouts, PaystackService $paystack, bool $dryRun): int
    {
        $minutes = PayoutService::STALE_INITIATING_MINUTES;

        if ($dryRun) {
            $stale = $payouts->staleInitiating()->count();
            if ($stale > 0) {
                $this->newLine();
                $this->warn("{$stale} payout(s) have been initiating for over {$minutes} minutes and would be looked up (dry run: not contacted).");
            }

            return 0;
        }

        $printed = false;
        $counts = $payouts->reconcileStaleInitiating($paystack, \App\Models\PayoutRecoveryEvent::SOURCE_SCHEDULER, function (Payout $payout, $result) use (&$printed, $minutes) {
            if (! $printed) {
                $this->newLine();
                $this->info("Looking up payouts initiating for over {$minutes} minutes:");
                $printed = true;
            }

            if ($result instanceof \Throwable) {
                $this->error("  FAILED {$payout->reference}: ".$result->getMessage());

                return;
            }

            $line = match ($result['outcome']) {
                'resolved' => "  {$payout->reference}: ".($result['message'] ?? 'resolved')." -> {$result['status']}",
                'released' => "  {$payout->reference}: Paystack has no transfer for this reference -> {$result['status']} (retry with payouts:retry once the cause is known)",
                'not_initiating' => "  {$payout->reference}: already {$result['status']} (resolved elsewhere); nothing done",
                default => "  {$payout->reference}: still unknown (".($result['message'] ?? 'no answer').') -> left initiating',
            };
            $result['outcome'] === 'unresolved' || $result['outcome'] === 'released' ? $this->warn($line) : $this->line($line);
        });

        if ($counts['found'] > 0) {
            $byStatus = $counts['by_status'] === [] ? 'none' : implode(', ', array_map(fn ($status, $n) => "{$n} {$status}", array_keys($counts['by_status']), $counts['by_status']));
            $this->info("Stale payouts: found {$counts['found']}, changed {$counts['changed']} ({$byStatus}), released to failed {$counts['released']}, still ambiguous {$counts['unresolved']}, resolved elsewhere {$counts['skipped']}, errors {$counts['failed']}");
        }

        return $counts['failed'];
    }

    /**
     * MEDIUM-3: a payout obligation is committed with the payment, but its job can
     * still fail to reach the queue. Such a payout sits at `pending` with no attempt
     * recorded and would otherwise never be picked up, because the reconciliation
     * query only looks for transactions with no payout at all.
     *
     * Re-dispatching is safe: the atomic pending -> initiating claim means a payout
     * already in flight or finished cannot be sent again.
     */
    private function requeueStrandedObligations(bool $dispatch): int
    {
        $stranded = Payout::where('status', Payout::PENDING)
            ->where('attempts', 0)
            ->orderBy('id')
            ->get();

        if ($stranded->isEmpty()) {
            return 0;
        }

        $this->newLine();
        $this->info("{$stranded->count()} recorded obligation(s) have never been queued.");

        if (! $dispatch) {
            $this->comment('Re-run with --dispatch to queue them.');

            return 0;
        }

        $queued = 0;

        foreach ($stranded as $payout) {
            // E4b: one school's dispatch problem must not stop the others.
            try {
                InitiateSchoolPayout::dispatch($payout->id);
                $queued++;
                $this->line("  queued {$payout->reference}");
            } catch (\Throwable $e) {
                report($e);
                $this->error("  FAILED to queue {$payout->reference}: {$e->getMessage()}");
            }
        }

        return $queued;
    }

    /**
     * List payouts a human must resolve before any money can move.
     */
    private function reportPayoutsNeedingReview(): void
    {
        $review = Payout::with('transaction')
            ->where('status', Payout::NEEDS_REVIEW)
            ->orderBy('id')
            ->get();

        if ($review->isEmpty()) {
            return;
        }

        $this->warn("{$review->count()} payout(s) need manual review before they can be transferred:");
        $this->table(
            ['Payout', 'School', 'Transaction', 'Charged', 'Reason'],
            $review->map(fn (Payout $p) => [
                $p->reference,
                $p->school_id,
                $p->transaction?->reference ?? '-',
                number_format((float) ($p->transaction?->amount ?? 0), 2),
                \Illuminate\Support\Str::limit((string) $p->last_error, 60),
            ])->all()
        );
        $this->newLine();
    }
}
